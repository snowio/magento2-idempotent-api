<?php
namespace SnowIO\IdempotentAPI\Model;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Webapi\ErrorProcessor;
use Magento\Framework\Webapi\Request;
use Magento\Framework\Webapi\Response;
use Magento\Framework\Webapi\Rest\Response as RestResponse;
use Magento\Webapi\Controller\Rest;
use Magento\Webapi\Controller\Soap;
use SnowIO\Lock\Api\LockService;

class DispatchPlugin
{
    private \Magento\Framework\Webapi\Request $request;
    private \Magento\Framework\Webapi\Response $response;
    private \SnowIO\Lock\Api\LockService $lockService;
    private \Magento\Framework\App\ResourceConnection $resourceConnection;
    private \SnowIO\IdempotentAPI\Model\ResourceModificationTimeRepository $modificationTimeRepo;
    private \Magento\Framework\Webapi\ErrorProcessor $errorProcessor;

    public function __construct(
        \Magento\Framework\App\RequestInterface $request,
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

        $messageGroupId = $this->request->getHeader('X-Message-Group-ID', $default = false);
        $messageTimestamp = $this->request->getHeader('X-Message-Timestamp', $default = false);

        if ($messageGroupId === false) {
            return $proceed($request);
        }

        if (!$this->lockService->acquireLock("idempotent_api.$messageGroupId", $timeout = 0)) {
            $this->response->setStatusCode(409);
            return $this->response;
        }

        try {
            if ($messageTimestamp !== false &&
                $lastModificationTime = $this->modificationTimeRepo->getLastModificationTime($messageGroupId)
            ) {
                if (!$this->isUnmodifiedSince($lastModificationTime, $messageTimestamp)) {
                    $this->response->setStatusCode(412);
                    return $this->response;
                }
                $updateTimestamp = $messageTimestamp;
            } else {
                $lastModificationTime = null;
                $updateTimestamp = \time();
            }

            $connection = $this->resourceConnection->getConnection();
            $connection->beginTransaction();

            try {
                $response = $proceed($request);

                if (($response instanceof RestResponse && $response->isException())
                    || ($response instanceof Response && $response->getHttpResponseCode() >= 400)
                ) {
                    $connection->rollBack();
                    return $response;
                }

                $this->modificationTimeRepo->updateModificationTime(
                    $messageGroupId,
                    $updateTimestamp,
                    $lastModificationTime
                );
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
            $this->lockService->releaseLock("idempotent_api.$messageGroupId");
        }
    }

    private function isUnmodifiedSince(int $modificationTime, int $expectedTime)
    {
        return $modificationTime <= $expectedTime;
    }
}
