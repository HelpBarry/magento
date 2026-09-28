<?php

namespace Bluebarry\Bluebarry\Test\Unit\Controller\Session;

use Bluebarry\Bluebarry\Controller\Session\Update;
use Bluebarry\Bluebarry\Test\Unit\SessionDouble;
use Magento\Framework\App\Action\Context;
use Magento\Framework\App\Request\Http;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;

class UpdateTest extends TestCase
{
    private Http&Stub $request;
    private SessionDouble $session;
    private array $responseData = [];
    private Update $controller;

    protected function setUp(): void
    {
        $this->request = $this->createStub(Http::class);
        $context = $this->createStub(Context::class);
        $context->method('getRequest')->willReturn($this->request);

        $this->session = new SessionDouble();

        $json = $this->createStub(Json::class);
        $json->method('setData')->willReturnCallback(function ($data) use ($json) {
            $this->responseData = $data;
            return $json;
        });
        $jsonFactory = $this->createStub(JsonFactory::class);
        $jsonFactory->method('create')->willReturn($json);

        $this->controller = new Update($context, $this->session, $jsonFactory);
    }

    public function testPostStoresQuizSessionFromBbSessionIdField(): void
    {
        $this->request->method('isPost')->willReturn(true);
        $this->request->method('getPostValue')->willReturn([
            'form_key' => 'fk',
            'bb_session_id' => 'session',
            'advisor_id' => 'advisor',
            'user_id' => 'user',
        ]);

        $this->controller->execute();

        $this->assertSame([[
            'bluebarry' => ['session_id' => 'session', 'advisor_id' => 'advisor', 'user_id' => 'user'],
        ]], $this->session->writes);
        $this->assertTrue($this->responseData['success']);
    }

    /** The legacy field name trips WAF rule 943120 and must not be what the storefront relies on. */
    public function testLegacySessionIdFieldIsNotUsed(): void
    {
        $this->request->method('isPost')->willReturn(true);
        $this->request->method('getPostValue')->willReturn(['session_id' => 'session', 'advisor_id' => 'a', 'user_id' => 'u']);

        $this->controller->execute();

        $this->assertSame([[
            'bluebarry' => ['session_id' => null, 'advisor_id' => 'a', 'user_id' => 'u'],
        ]], $this->session->writes);
    }

    public function testGetReturnsStoredSessionWithoutWriting(): void
    {
        $stored = ['bluebarry' => ['session_id' => 's', 'advisor_id' => 'a', 'user_id' => 'u']];
        $this->request->method('isPost')->willReturn(false);
        $this->session->stored = $stored;

        $this->controller->execute();

        $this->assertSame([], $this->session->writes);
        $this->assertSame(['success' => true, 'session' => $stored], $this->responseData);
    }
}
