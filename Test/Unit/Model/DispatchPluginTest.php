<?php

namespace SnowIO\IdempotentAPI\Test\Unit\Model;

use Magento\Framework\App\FrontControllerInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Webapi\ErrorProcessor;
use Magento\Framework\Webapi\Request;
use PHPUnit_Framework_MockObject_MockObject;
use PHPUnit_Framework_TestCase;
use SnowIO\IdempotentAPI\Model\DispatchPlugin;
use SnowIO\IdempotentAPI\Model\ResourceModificationTimeRepository;
use SnowIO\Lock\Api\LockService;
use Magento\Framework\Webapi\Response;
use \Magento\Framework\App\RequestInterface;
use Zend\Http\Header\Date;
use Zend\Http\Header\HeaderInterface;
use Zend\Http\Header\IfUnmodifiedSince;


class DispatchPluginTest extends PHPUnit_Framework_TestCase
{
    /** @var  ResourceModificationTimeRepository | PHPUnit_Framework_MockObject_MockObject */
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


    public function __construct($name = null, array $data = array(), $dataName = '')
    {
        parent::__construct($name, $data, $dataName);
    }


    public function setUp()
    {
        $this->mockResourceTimestampRespository = $this->getMockBuilder(ResourceModificationTimeRepository::class)
            ->disableOriginalConstructor()->setMethods(['save', 'get'])->getMock();
        $this->mockMagento2LockService = $this->getMockBuilder(LockService::class)
            ->disableOriginalConstructor()->setMethods(['acquire', 'release'])->getMock();
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
        $this->proceedClosure = function () {
            return new Response();
        };
    }

    public function testWithNoResourceQuery()
    {
        /** @var Request $request */
        $request = $this->getMockRequest(new \DateTime(), new \DateTime());
        $request->method('getHeader')->willReturn(null);

        $response = new Response();

        $plugin = $this->getPlugin($request, $response);
        $response = $plugin->aroundDispatch($this->mockFrontController, $this->proceedClosure, $this->mockRequest);
        $this->assertEquals(200, $response->getStatusCode());
    }


    public function testWithOldAPIRequest()
    {
        $time = microtime(true);
        //Scenario: The timestamp the previous for the resource is more recent than the timestamp in the request
        $this->mockResourceTimestampRespository->method('get')
            ->willReturn(['timestamp' => $time, 'identifier' => 'SnowIO-TestResource']);

        /** @var Request $request */
        $request = $this->getMockRequest(new \DateTime(), new \DateTime());

        $request->method('getHeader')->will($this->returnValueMap(['SnowIO-Resource-Identifier', 'Resource'],
            ['SnowIO-Resource-Timestamp', -$time]));

        /** @var Response $response */
        $response = new Response();

        $plugin = $this->getPlugin($request, $response);
        $response = $plugin->aroundDispatch($this->mockFrontController, $this->proceedClosure, $this->mockRequest);
        $this->assertEquals(412, $response->getStatusCode());
    }


    public function testWithReadyAcquiredResource()
    {
        //Scenario: The lock has already been acquired by another webapi request on the needed resource
        $this->mockMagento2LockService->method('acquire')->willReturn(false);

        //Scenario: The timestamp the previous for the resource is more recent than the timestamp in the request
        $this->mockResourceTimestampRespository->method('get')
            ->willReturn(['timestamp' => microtime(true), 'identifier' => 'SnowIO-TestResource']);

        /** @var Request $request */
        $request = $this->getMockRequest(\time(), \time());

        $request->method('getHeader')->will($this->returnValueMap(['SnowIO-Resource-Identifier', 'Resource'],
            ['SnowIO-Resource-Timestamp', microtime(true)]));

        /** @var Response $response */
        $response = new Response();

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
    private function getMockRequest($inputDate, $inputIfUnmodifiedSinceDate)
    {
        $date = new Date();
        $date->setDate($inputDate);
        $ifModifiedSince = new IfUnmodifiedSince();
        $ifModifiedSince->setDate($inputIfUnmodifiedSinceDate);
        $request = $this->getMockBuilder(Request::class)->disableOriginalConstructor()
            ->setMethods(['getHeader'])->getMock();
        $request->method('getHeader')->will($this->returnValueMap([
            'Date',
            new Date()
        ], [
            'If-Unmodified-Since',
            new IfUnmodifiedSince()
        ]));
        return $request;
    }
}
