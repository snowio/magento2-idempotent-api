<?php

namespace SnowIO\IdempotentAPI\Test\Unit\Model;

use Magento\Framework\App\FrontControllerInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\TestFramework\Unit\Helper\ObjectManager;
use Magento\Framework\Webapi\ErrorProcessor;
use Magento\Framework\Webapi\Request;
use PHPUnit_Framework_MockObject_MockObject;
use PHPUnit_Framework_TestCase;
use SnowIO\IdempotentAPI\Model\DispatchPlugin;
use SnowIO\IdempotentAPI\Model\ResourceTimestampRepository;
use SnowIO\Lock\Api\LockService;
use Magento\Framework\Webapi\Response;
use \Magento\Framework\App\RequestInterface;
use Zend\Http\Headers;


class DispatchPluginTest extends PHPUnit_Framework_TestCase
{
    /** @var  ResourceTimestampRepository | PHPUnit_Framework_MockObject_MockObject */
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

    /** @var  RequestInterface | PHPUnit_Framework_TestCase */
    private $mockRequest;

    /** @var  \Closure */
    private $proceedClosure;

    /** @var \Magento\Framework\TestFramework\Unit\Helper\ObjectManager */
    private $objectManager;


    public function __construct($name = null, array $data = array(), $dataName = '')
    {
        parent::__construct($name, $data, $dataName);
    }


    public function setUp()
    {
        $this->objectManager  = new ObjectManager($this);
        $this->mockResourceTimestampRespository = $this->getMockBuilder(ResourceTimestampRepository::class)
            ->disableOriginalConstructor()->setMethods(['save', 'get'])->getMock();
        $this->mockMagento2LockService = $this->getMockBuilder(LockService::class)
            ->disableOriginalConstructor()->setMethods(['acquire', 'release']);
        $this->mockResourceConnection = $this->getMockBuilder(ResourceConnection::class)
            ->disableOriginalConstructor()->getMock();
        $this->mockErrorProcessor = $this->getMockBuilder(ErrorProcessor::class)
            ->disableOriginalConstructor()->getMock();
        $this->mockResponse = $this->getMockBuilder(Response::class)
            ->disableOriginalConstructor()->getMock();
        $this->mockRequest = $this->getMockBuilder(Request::class)
            ->disableOriginalConstructor()->getMock();
        $this->mockFrontController = $this->getMockBuilder(FrontControllerInterface::class)
            ->disableOriginalConstructor()->getMock();

    }

    public function testWithNoId()
    {
        /** @var Request $request */
        $request = $this->objectManager->getObject(Request::class);

        $headers = (new Headers())
            ->addHeaderLine("ACME-Resource-Identifier", "NonResource");
        $request->setHeaders($headers);

        /** @var Response $response */
        $response = $this->objectManager->getObject(Response::class);

        $plugin = $this->getPlugin($request, $response);
        $response = $plugin->aroundDispatch($this->mockFrontController, $this->proceedClosure, $this->mockRequest);
        $this->assertEquals(400, $response->getStatusCode());
    }

    public function testWithNoTimestamp()
    {
        /** @var Request $request */
        $request = $this->objectManager->getObject(Request::class);

        $headers = (new Headers())
            ->addHeaderLine("SnowIO-Resource-Identifier", "SnowIO-TestResource");
        $request->setHeaders($headers);

        /** @var Response $response */
        $response = $this->objectManager->getObject(Response::class);

        $plugin = $this->getPlugin($request, $response);
        $response = $plugin->aroundDispatch($this->mockFrontController, $this->proceedClosure, $this->mockRequest);
        $this->assertEquals(400, $response->getStatusCode());
    }

    public function testWithOldAPIRequest()
    {
        //Scenario: The timestamp the previous for the resource is more recent than the timestamp in the request
        $this->mockResourceTimestampRespository->method('get')
            ->willReturn(['timestamp' => microtime(true), 'identifier' => 'SnowIO-TestResource']);

        /** @var Request $request */
        $request = $this->objectManager->getObject(Request::class);

        $headers = (new Headers())
            ->addHeaderLine("SnowIO-Resource-Identifier", "SnowIO-TestResource")
            ->addHeaderLine("SnowIO-Resource-Timestamp", microtime(true) - 1000);
        $request->setHeaders($headers);

        /** @var Response $response */
        $response = $this->objectManager->getObject(Response::class);

        $plugin = $this->getPlugin($request, $response);
        $response = $plugin->aroundDispatch($this->mockFrontController, $this->proceedClosure, $this->mockRequest);
        $this->assertEquals(412, $response->getStatusCode());
    }


    public function testWithReadyAcquiredResource()
    {
        //Scenario: The lock has already been acquired by another webapi request on the needed resource
        $this->mockMagento2LockService->method('acquire')->willReturn(false);

        /** @var Request $request */
        $request = $this->objectManager->getObject(Request::class);

        $headers = (new Headers())
            ->addHeaderLine("SnowIO-Resource-Identifier", "SnowIO-TestResource")
            ->addHeaderLine("SnowIO-Resource-Timestamp", microtime(true));
        $request->setHeaders($headers);

        /** @var Response $response */
        $response = $this->objectManager->getObject(Response::class);

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

}
