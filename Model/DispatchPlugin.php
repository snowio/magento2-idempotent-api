<?php
namespace SnowIO\IdempotentAPI\Model;

use Braintree\Exception;
use Magento\Framework\App\FrontControllerInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Webapi\ErrorProcessor;
use Magento\Framework\Webapi\Request;
use Magento\Framework\Webapi\Response;
use Magento\Webapi\Controller\Rest;
use Magento\Webapi\Controller\Soap;
use SnowIO\Lock\Api\LockService;

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
        FrontControllerInterface $frontController,
        \Closure $proceed,
        \Magento\Framework\App\RequestInterface $request
    ) {
        if (!$frontController instanceof Rest && !$frontController instanceof Soap) {
            return $proceed($request);
        }

        $resourceId = $this->request->getParam('resource');
        $lastModificationTimeExpectation = $this->request->getHeader('If-Unmodified-Since')->getFieldValue();
        $newModificationTime = $this->request->getHeader('Date')->getFieldValue();

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
                $result = $proceed($request);
                $this->modificationTimeRepo->updateModificationTime($resourceId, $updateTimestamp, $lastModificationTime);
                $connection->commit();
                return $result;
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

    private function convertDateToTimestamp(string $date) : string
    {
        return (string) strtotime($date);
    }

    private function isUnmodifiedSince(string $modificationTime, string $expectedTime)
    {
        return strtotime($modificationTime) < strtotime($expectedTime);
    }
}