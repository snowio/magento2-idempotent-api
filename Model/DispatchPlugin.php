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
    private $resourceTimestampRepository;
    private $errorProcessor;

    public function __construct(
        Request $request,
        Response $response,
        LockService $lockService,
        ResourceConnection $resourceConnection,
        ResourceTimestampRepository $resourceTimestampRepository,
        ErrorProcessor $errorProcessor
    ) {
        $this->request = $request;
        $this->response = $response;
        $this->lockService = $lockService;
        $this->resourceConnection = $resourceConnection;
        $this->resourceTimestampRepository = $resourceTimestampRepository;
        $this->errorProcessor = $errorProcessor;
    }

    public function aroundDispatch(
        FrontControllerInterface $frontController,
        \Closure $proceed,
        \Magento\Framework\App\RequestInterface $request
    ) {
        if ($frontController instanceof Rest || $frontController instanceof Soap) {
            $identifier = $this->request->getHeader('SnowIO-Resource-Identifier')->getFieldValue();
            $timestamp = $this->request->getHeader('SnowIO-Resource-Timestamp')->getFieldValue();

            if (!$identifier || !$timestamp) {
                $this->response->setStatusCode(400);
                return $this->response;
            }

            $resource = $this->resourceTimestampRepository->get($identifier);
            if ($resource['timestamp'] ?? 0 > $timestamp) {
                $this->response->setStatusCode(412);
                return $this->response;
            }

            if (!$this->lockService->acquireLock($identifier, 0)) {
                $this->response->setStatusCode(409);
                return $this->response;
            } else {
                $connection = $this->resourceConnection->getConnection();
                $connection->beginTransaction();
                try {
                    $result = $proceed($request);
                    $this->resourceTimestampRepository->save($identifier, $timestamp);
                    $connection->commit();
                    $this->lockService->releaseLock($identifier);
                    return $result;
                } catch (Exception $e) {
                    $connection->rollBack();
                    $e = $this->errorProcessor->maskException($e);
                    $this->response->setStatusCode($e->getCode());
                    return $this->response;
                }
            }
        } else {
            return $proceed($request);
        }
    }
}