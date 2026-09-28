<?php

namespace Bluebarry\Bluebarry\Test\Unit\Model\Consumer;

use Bluebarry\Bluebarry\Model\Consumer\ConversionProcessor;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\HTTP\Client\Curl;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Item;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class ConversionProcessorTest extends TestCase
{
    private const ADVISOR_ID = '5b3a1c1e-0000-4000-8000-000000000001';
    private const SESSION_ID = '5b3a1c1e-0000-4000-8000-000000000002';
    private const USER_ID = '5b3a1c1e-0000-4000-8000-000000000003';

    private OrderRepositoryInterface&Stub $orderRepository;
    private Curl&Stub $curl;
    private ConversionProcessor $processor;

    /** @var array<int, array{url: string, body: array}> */
    private array $posts = [];

    protected function setUp(): void
    {
        $this->orderRepository = $this->createStub(OrderRepositoryInterface::class);
        $this->curl = $this->createStub(Curl::class);

        $this->curl->method('post')->willReturnCallback(function (string $url, $body) {
            $this->posts[] = ['url' => $url, 'body' => json_decode($body, true), 'raw' => $body];
        });
        $this->curl->method('getStatus')->willReturn(201);
        $this->curl->method('getBody')->willReturn('{"id":"abc"}');

        $this->processor = $this->processor();
    }

    private function processor(?LoggerInterface $logger = null, ?Curl $curl = null): ConversionProcessor
    {
        return new ConversionProcessor(
            $this->orderRepository,
            $this->createStub(ScopeConfigInterface::class),
            $this->createStub(StoreManagerInterface::class),
            $logger ?? $this->createStub(LoggerInterface::class),
            $curl ?? $this->curl
        );
    }

    public function testSendsConversionAndIdentify(): void
    {
        $this->givenOrder(42, 'shopper@example.com', [
            // Values as Magento loads them from the database: decimals come back as strings.
            $this->item('7', '2.0000', '100.0000', '21.0000', '121.0000'),
        ]);

        $this->processor->processConversion($this->message(42));

        $this->assertCount(2, $this->posts);
        [$conversion, $identify] = $this->posts;

        $this->assertSame('https://data.bluebarry.ai/data/conversionevents', $conversion['url']);
        $this->assertSame(self::ADVISOR_ID, $conversion['body']['advisorId']);
        $this->assertSame(self::SESSION_ID, $conversion['body']['sessionId']);
        $this->assertSame(self::USER_ID, $conversion['body']['userId']);
        $this->assertSame('42', $conversion['body']['conversionId']);
        $this->assertSame('EUR', $conversion['body']['currencyIso']);
        $this->assertEqualsWithDelta(200.0, $conversion['body']['orderProductTotal'], 0.001);
        $this->assertEqualsWithDelta(42.0, $conversion['body']['orderTaxTotal'], 0.001);
        $this->assertEqualsWithDelta(242.0, $conversion['body']['orderGrandTotal'], 0.001);

        $this->assertSame('https://data.bluebarry.ai/data/identify', $identify['url']);
        $this->assertSame(
            ['email' => 'shopper@example.com', 'sessionId' => self::SESSION_ID, 'userId' => self::USER_ID],
            $identify['body']
        );
    }

    /**
     * The Bluebarry API accepts decimals as numbers or numeric strings, but string fields such as
     * itemId must be JSON strings: a number there makes the API reject the whole conversion with 400.
     */
    public function testPayloadMatchesApiContractTypes(): void
    {
        // Magento forces PDO::ATTR_STRINGIFY_FETCHES, so loaded ids and decimals are strings.
        $this->givenOrder(42, null, [$this->item('7', '1.0000', '100.0000', '21.0000', '121.0000')]);

        $this->processor->processConversion($this->message(42));

        $item = $this->posts[0]['body']['items'][0];
        $this->assertIsString($item['itemId'], 'itemId must be a JSON string');
        foreach (['quantity', 'value', 'taxPercentage', 'priceExclTax', 'priceInclTax'] as $field) {
            $this->assertTrue(
                is_int($item[$field]) || is_float($item[$field]) || is_numeric($item[$field]),
                "$field must be numeric"
            );
        }
        $this->assertSame(
            [],
            array_diff(
                array_keys($this->posts[0]['body']),
                ['advisorId', 'sessionId', 'userId', 'value', 'orderProductTotal', 'orderTaxTotal',
                 'orderGrandTotal', 'currencyIso', 'conversionId', 'items']
            ),
            'unknown properties are rejected by the API'
        );
    }

    public function testNoIdentifyWithoutCustomerEmail(): void
    {
        $this->givenOrder(42, null, [$this->item('7', '1.0000', '10.0000', '0.0000', '10.0000')]);

        $this->processor->processConversion($this->message(42));

        $this->assertCount(1, $this->posts);
        $this->assertStringEndsWith('/data/conversionevents', $this->posts[0]['url']);
    }

    public function testIgnoresMessageWithIncompleteSession(): void
    {
        $this->givenOrder(42, 'shopper@example.com', []);
        $message = json_encode([
            'order_id' => 42,
            'tenant_id' => 'tenant',
            'session_data' => ['bluebarry' => ['advisor_id' => self::ADVISOR_ID, 'user_id' => self::USER_ID]],
        ]);

        $this->processor->processConversion($message);

        $this->assertSame([], $this->posts);
    }

    public function testRejectsInvalidJsonWithoutThrowing(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('error');

        $this->processor($logger)->processConversion('not json');

        $this->assertSame([], $this->posts);
    }

    public function testApiErrorDoesNotThrow(): void
    {
        $curl = $this->createStub(Curl::class);
        $curl->method('getStatus')->willReturn(503);
        $curl->method('getBody')->willReturn('down');
        $processor = $this->processor(null, $curl);
        $this->givenOrder(42, 'shopper@example.com', [$this->item('7', '1.0000', '10.0000', '0.0000', '10.0000')]);

        $processor->processConversion($this->message(42));

        $this->addToAssertionCount(1);
    }

    private function message(int $orderId): string
    {
        return json_encode([
            'order_id' => $orderId,
            'tenant_id' => 'tenant',
            'session_data' => ['bluebarry' => [
                'session_id' => self::SESSION_ID,
                'advisor_id' => self::ADVISOR_ID,
                'user_id' => self::USER_ID,
            ]],
        ]);
    }

    private function givenOrder(int $id, ?string $email, array $items): void
    {
        $order = $this->createStub(Order::class);
        $order->method('getId')->willReturn($id);
        $order->method('getCustomerEmail')->willReturn($email);
        $order->method('getAllItems')->willReturn($items);
        $this->orderRepository->method('get')->willReturnMap([[$id, $order]]);
    }

    private function item($id, $qty, $price, $taxPercent, $priceInclTax): Item
    {
        $item = $this->createStub(Item::class);
        $item->method('getItemId')->willReturn($id);
        $item->method('getQtyOrdered')->willReturn($qty);
        $item->method('getPrice')->willReturn($price);
        $item->method('getTaxPercent')->willReturn($taxPercent);
        $item->method('getPriceInclTax')->willReturn($priceInclTax);
        return $item;
    }
}
