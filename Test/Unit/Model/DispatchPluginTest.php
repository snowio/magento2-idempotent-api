<?php

namespace SnowIO\IdempotentAPI\Test\Unit\Model;

use Magento\Framework\App\FrontControllerInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\Webapi\ErrorProcessor;
use Magento\Framework\Webapi\Request;
use Magento\Webapi\Controller\Rest;
use PHPUnit_Framework_MockObject_MockObject;
use PHPUnit_Framework_TestCase;
use SnowIO\IdempotentAPI\Model\DispatchPlugin;
use SnowIO\IdempotentAPI\Model\WebApiMessageGroupRepository;
use SnowIO\Lock\Api\LockService;
use Magento\Framework\Webapi\Response;
use \Magento\Framework\App\RequestInterface;
use Zend\Http\Header\Date;
use Zend\Http\Header\IfUnmodifiedSince;

class DispatchPluginTest extends PHPUnit_Framework_TestCase
{
    /** @var  WebApiMessageGroupRepository | PHPUnit_Framework_MockObject_MockObject */
    private $mockResourceTimestampRespository;

    /** @var  LockService | PHPUnit_Framework_MockObject_MockObject */
    private $mockMagento2LockService;

    /** @var  ResourceConnection | PHPUnit_Framework_MockObject_MockObject */
    private $mockResourceConnection;

    /** @var  ErrorProcessor | PHPUnit_Framework_MockObject_MockObject */
    private $mockErrorProcessor;

    /** @var Response | PHPUnit_Framework_MockObject_MockObject */
    private $mockResponse;

    /** @var  FrontControllerInterface | \PHPUnit_Framework_MockObject_MockObject */
    private $mockFrontController;

    /** @var  RequestInterface | PHPUnit_Framework_MockObject_MockObject */
    private $mockRequest;

    /** @var  \Closure */
    private $proceedClosure;

    /** @var  AdapterInterface | PHPUnit_Framework_MockObject_MockObject */
    private $mockConnection;


    public function __construct($name = null, array $data = array(), $dataName = '')
    {
        parent::__construct($name, $data, $dataName);
    }


    public function setUp()
    {
        $this->mockResourceTimestampRespository = $this->getMockBuilder(WebApiMessageGroupRepository::class)
            ->disableOriginalConstructor()->setMethods([
                'getLastModificationTime',
                'updateModificationTime'
            ])->getMock();
        $this->mockMagento2LockService = $this->getMockBuilder(LockService::class)
            ->disableOriginalConstructor()->setMethods(['acquireLock', 'releaseLock'])->getMock();
        $this->mockConnection = $this->getMockForAbstractClass(AdapterInterface::class);
        $this->mockResourceConnection = $this->getMockBuilder(ResourceConnection::class)
            ->disableOriginalConstructor()->setMethods(['getConnection'])->getMock();
        $this->mockResourceConnection->method('getConnection')->willReturn($this->mockConnection);
        $this->mockErrorProcessor = $this->getMockBuilder(ErrorProcessor::class)
            ->disableOriginalConstructor()->getMock();
        $this->mockResponse = $this->getMockBuilder(Response::class)
            ->disableOriginalConstructor()->getMock();
        $this->mockRequest = $this->getMockBuilder(Request::class)
            ->disableOriginalConstructor()->getMock();
        $this->mockFrontController = $this->getMockBuilder(Rest::class)
            ->disableOriginalConstructor()->getMock();
        $this->proceedClosure = function () {
            return new Response();
        };
    }

    public function testWithNoResourceQuery()
    {
        $date = new \DateTime();
        $ifUnModifiedDate = new \DateTime();
        $ifUnModifiedDate->setTimestamp(strtotime('+1 day', \time()));
        $request = $this->getMockRequest($date, $ifUnModifiedDate);
        $response = new Response();

        $this->mockConnection->expects($this->once())->method('beginTransaction');
        $this->mockConnection->expects($this->once())->method('commit');
        $this->mockMagento2LockService->expects($this->once())->method('acquireLock')->willReturn(true);
        $this->mockMagento2LockService->expects($this->once())->method('releaseLock')->willReturn(true);

        $plugin = $this->getPlugin($request, $response);
        $response = $plugin->aroundDispatch($this->mockFrontController, $this->proceedClosure, $this->mockRequest);
        $this->assertEquals(200, $response->getStatusCode());
    }

    public function testNewResourceRequest()
    {
        //A resource request that has no entry in the repository should expect a 200 and a new entry into the repository

        $date = new \DateTime();
        $ifUnModifiedDate = new \DateTime();
        $ifUnModifiedDate->setTimestamp(strtotime('+1 day', \time()));
        $request = $this->getMockRequest($date, $ifUnModifiedDate);
        $response = new Response();

        //As its a new resource request getLastModificationTime will null
        $this->mockResourceTimestampRespository->method('getLastModificationTime')->willReturn(null);
        $this->mockMagento2LockService->expects($this->once())->method('acquireLock')->willReturn(true);
        $this->mockMagento2LockService->expects($this->once())->method('releaseLock')->willReturn(true);

        $this->mockConnection->expects($this->once())->method('beginTransaction');
        $this->mockConnection->expects($this->once())->method('commit');

        $plugin = $this->getPlugin($request, $response);
        $response = $plugin->aroundDispatch($this->mockFrontController, $this->proceedClosure, $this->mockRequest);
        $this->assertEquals(200, $response->getStatusCode());
    }


    public function testWithLateResourceRequest()
    {
        //scenario is that the ifUnmodifiedDate is older than the lastModified Timestamp located in the
        //webapi resource table
        $date = new \DateTime();
        $date->setTimestamp(strtotime('-1 day', \time()));
        $ifUnModifiedDate = new \DateTime();
        $ifUnModifiedDate->setTimestamp(strtotime('-1 day', \time()));
        $this->mockResourceTimestampRespository->method('getLastModificationTime')
            ->willReturn(\time());
        $response = new Response();
        $request = $this->getMockRequest($date, $ifUnModifiedDate);

        //it will still acquire and release the lock
        $this->mockMagento2LockService->expects($this->once())->method('acquireLock')->willReturn(true);
        $this->mockMagento2LockService->expects($this->once())->method('releaseLock')->willReturn(true);

        //it will NEVER begin, rollBack or commit a transaction
        $this->mockConnection->expects($this->never())->method('beginTransaction');
        $this->mockConnection->expects($this->never())->method('commit');
        $this->mockConnection->expects($this->never())->method('rollBack');

        //assert the updateModificationTime
        $plugin = $this->getPlugin($request, $response);
        $response = $plugin->aroundDispatch($this->mockFrontController, $this->proceedClosure, $this->mockRequest);
        $this->assertEquals(412, $response->getStatusCode());
    }


    public function testFailedAcquire()
    {
        $date = new \DateTime();
        $date->setTimestamp(strtotime('+1 day', \time()));
        $ifUnModifiedDate = new \DateTime();
        $ifUnModifiedDate->setTimestamp(strtotime('+1 day', \time()));
        $this->mockResourceTimestampRespository->method('getLastModificationTime')
            ->willReturn(gmdate('D, d M Y H:i:s T'));
        $response = new Response();
        $request = $this->getMockRequest($date, $ifUnModifiedDate);

        //simulate race condition (as one resource has already acquired the lock )
        $this->mockMagento2LockService->expects($this->once())->method('acquireLock')->willReturn(false);

        //it will NEVER begin, rollBack or commit a transaction and also release the lock
        $this->mockConnection->expects($this->never())->method('beginTransaction');
        $this->mockConnection->expects($this->never())->method('commit');
        $this->mockConnection->expects($this->never())->method('rollBack');
        $this->mockMagento2LockService->expects($this->never())->method('releaseLock');

        //assert the updateModificationTime
        $plugin = $this->getPlugin($request, $response);
        $response = $plugin->aroundDispatch($this->mockFrontController, $this->proceedClosure, $this->mockRequest);
        $this->assertEquals(409, $response->getStatusCode());
    }

    private function getPlugin(Request $request, Response $response) : DispatchPlugin
    {
        return new DispatchPlugin(
            $request,
            $response,
            $this->mockMagento2LockService,
            $this->mockResourceConnection,
            $this->mockResourceTimestampRespository,
            $this->mockErrorProcessor
        );
    }

    /**
     * @return Request | PHPUnit_Framework_MockObject_MockObject
     */
    private function getMockRequest(\DateTime $inputDate, \DateTime $inputIfUnmodifiedSinceDate)
    {
        $date = new Date();
        $date->setDate($inputDate);
        $ifModifiedSince = new IfUnmodifiedSince();
        $ifModifiedSince->setDate($inputIfUnmodifiedSinceDate);
        $request = $this->getMockBuilder(Request::class)->disableOriginalConstructor()
            ->setMethods(['getHeader', 'getParam'])->getMock();
        $request->method('getHeader')->will($this->returnValueMap([
            [
                'Date',
                false,
                $date
            ],
            [
                'If-Unmodified-Since',
                false,
                $ifModifiedSince
            ]
        ]));
        $request->method('getParam')->will($this->returnValueMap([[
            'resource',
             null,
            'testReturnResource'
        ]]));
        return $request;
    }
}
