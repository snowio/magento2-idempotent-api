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

        $messageGroupId = $this->request->getHeader('X-Message-Group-ID', $default = false);
        $messageTimestamp = $this->request->getHeader('X-Message-Timestamp', $default = false);

        if ($messageGroupId === false || $messageTimestamp === false) {
            return $proceed($request);
        }

        if (!$this->lockService->acquireLock("idempotent_api.$messageGroupId", $timeout = 0)) {
            $this->response->setStatusCode(409);
            return $this->response;
        }

        try {
            $lastModification = $this->modificationTimeRepo->getLastModification($messageGroupId);
            $lastModificationTime = $lastModification['timestamp'] ?? null;
            $lastVersion = $lastModification['version'] ?? null;

            if ($lastModificationTime !== null && $messageTimestamp < $lastModificationTime) {
                $this->response->setStatusCode(412);
                return $this->response;
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
                    $messageTimestamp,
                    $lastVersion,
                    $lastModificationTime
                );
                $connection->commit();
                return $response;
            } catch (\Throwable $e) {
                $connection->rollBack();
                throw $e;
            }
        } catch (ConflictException $e) {
            $this->response->setStatusCode(409);
            return $this->response;
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
}
