<?php
namespace SnowIO\IdempotentAPI\Model;

use Magento\Framework\App\RequestInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Webapi\ErrorProcessor;
use Magento\Framework\Webapi\Response;
use Magento\Framework\Webapi\Rest\Response as RestResponse;
use Magento\Webapi\Controller\Rest;
use Magento\Webapi\Controller\Soap;
use SnowIO\Lock\Api\LockService;

class DispatchPlugin
{
    private RequestInterface $request;
    private Response $response;
    private LockService $lockService;
    private ResourceConnection $resourceConnection;
    private ResourceModificationTimeRepository $modificationTimeRepo;
    private ErrorProcessor $errorProcessor;

    /**
     * DispatchPlugin constructor.
     * @param RequestInterface $request
     * @param Response $response
     * @param LockService $lockService
     * @param ResourceConnection $resourceConnection
     * @param ResourceModificationTimeRepository $resourceTimestampRepository
     * @param ErrorProcessor $errorProcessor
     */
    public function __construct(
        RequestInterface $request,
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

    /**
     * @param $frontController
     * @param \Closure $proceed
     * @param RequestInterface $request
     * @return Response|mixed
     */
    public function aroundDispatch(
        $frontController,
        \Closure $proceed,
        RequestInterface $request
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

    /**
     * @param int $modificationTime
     * @param int $expectedTime
     * @return bool
     */
    private function isUnmodifiedSince(int $modificationTime, int $expectedTime): bool
    {
        return $modificationTime <= $expectedTime;
    }
}
