<?php
namespace SnowIO\IdempotentAPI\Model;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\App\ResponseInterface;
use Magento\Framework\Phrase;
use Magento\Framework\Webapi\ErrorProcessor;
use Magento\Framework\Webapi\Request;
use Magento\Framework\Webapi\Response;
use Magento\Framework\Webapi\Rest\Response as RestResponse;
use Magento\Webapi\Controller\Rest;
use Magento\Webapi\Controller\Soap;
use SnowIO\Lock\Api\LockService;
use Magento\Framework\Webapi\Exception as WebapiException;

class DispatchPlugin
{
    private $request;
    private $response;
    private $lockService;
    private $resourceConnection;
    private $modificationTimeRepo;
    private $errorProcessor;

    public function __construct(
        Request $request,
        Response $response,
        LockService $lockService,
        ResourceConnection $resourceConnection,
        ResourceModificationTimeRepository $resourceTimestampRepository,
        ErrorProcessor $errorProcessor
    ) {
        $this->request = $request;
        $this->response = $response;
        $this->lockService = $lockService;
        $this->resourceConnection = $resourceConnection;
        $this->modificationTimeRepo = $resourceTimestampRepository;
        $this->errorProcessor = $errorProcessor;
    }

    public function aroundDispatch(
        $frontController,
        \Closure $proceed,
        \Magento\Framework\App\RequestInterface $request
    ) {
        if (!$frontController instanceof Rest && !$frontController instanceof Soap) {
            return $proceed($request);
        }

        $resourceId = $this->request->getParam('resource');
        $lastModificationTimeExpectation = $this->request->getHeader('If-Unmodified-Since');
        $newModificationTime = $this->request->getHeader('Date');

        if (!$resourceId && !$lastModificationTimeExpectation && !$newModificationTime) {
            return $proceed($request);
        }

        if (!isset($resourceId)) {
            $resourceId = $this->request->getPathInfo();
        }

        if (!$this->lockService->acquireLock("idempotent_api.$resourceId", $timeout = 0)) {
            $this->response->setStatusCode(409);
            return $this->response;
        }

        try {
            if (isset($lastModificationTimeExpectation) && $lastModificationTime = $this->modificationTimeRepo->getLastModificationTime($resourceId)) {
                $timestampForCondition = $this->convertDateToTimestamp($lastModificationTimeExpectation);
                if (!$this->isUnmodifiedSince($lastModificationTime, $timestampForCondition)) {
                    $this->response->setStatusCode(412);
                    return $this->response;
                }
            }

            if (isset($newModificationTime)) {
                $updateTimestamp = $this->convertDateToTimestamp($newModificationTime);
            } else {
                $updateTimestamp = \time();
            }

            $connection = $this->resourceConnection->getConnection();
            $connection->beginTransaction();

            try {
                /** @var ResponseInterface $response */
                $response = $proceed($request);

                if ($response instanceof RestResponse && $response->isException()) {
                    throw end($response->getException());
                } elseif ($response instanceof Response && $response->getHttpResponseCode() >= 400) {
                    throw new WebapiException(
                        new Phrase($response->getBody()),
                        $response->getHttpResponseCode(),
                        $response->getHttpResponseCode(),
                        [],
                        '',
                        null,
                        null
                    );
                }
                $this->modificationTimeRepo->updateModificationTime($resourceId, $updateTimestamp, $lastModificationTime);
                $connection->commit();
                return $response;
            } catch (\Throwable $e) {
                $connection->rollBack();
                throw $e;
            }
        } catch (\Exception $e) {
            $e = $this->errorProcessor->maskException($e);
            $this->response->setStatusCode($e->getHttpCode());
            return $this->response;
        } catch (\Throwable $e) {
            $this->response->setStatusCode(500);
            return $this->response;
        } finally {
            $this->lockService->releaseLock("idempotent_api.$resourceId");
        }
    }

    private function convertDateToTimestamp(string $date) : int
    {
        return strtotime($date);
    }

    private function isUnmodifiedSince(int $modificationTime, int $expectedTime)
    {
        return $modificationTime < $expectedTime;
    }
}