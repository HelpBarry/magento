<?php

namespace Bluebarry\Bluebarry\Test\Unit\Controller;

use Bluebarry\Bluebarry\Controller\Command\Index;
use PHPUnit\Framework\TestCase;

/**
 * bluebarry's commands are signed like DataApi's WooCommerceStoreClient.Sign: HMAC-SHA256 of
 * "{timestamp}.{body}" with the website's API key, lowercase hex.
 */
class CommandTest extends TestCase
{
    private const BODY = '{"command":"settings.refresh","payload":null}';

    public function testOnlyARecentRequestSignedWithTheWebsitesKeyIsTaken(): void
    {
        $now = 1790000000;
        $sign = fn (string $key, int $at) => hash_hmac('sha256', $at . '.' . self::BODY, $key);

        $this->assertTrue(Index::isSigned('key', (string) $now, $sign('key', $now), self::BODY, $now));
        $this->assertTrue(Index::isSigned('key', (string) ($now - 299), strtoupper($sign('key', $now - 299)), self::BODY, $now));
        $this->assertFalse(Index::isSigned('key', (string) $now, $sign('another key', $now), self::BODY, $now));
        $this->assertFalse(Index::isSigned('key', (string) $now, $sign('key', $now), self::BODY . ' ', $now));
        $this->assertFalse(Index::isSigned('key', (string) ($now - 301), $sign('key', $now - 301), self::BODY, $now));
        $this->assertFalse(Index::isSigned('key', '', '', self::BODY, $now));
        $this->assertFalse(Index::isSigned('key', 'soon', $sign('key', $now), self::BODY, $now));
    }
}
