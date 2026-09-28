<?php

namespace Bluebarry\Bluebarry\Test\Unit\Model;

use Bluebarry\Bluebarry\Model\Visitor;
use Bluebarry\Bluebarry\Test\Unit\SessionDouble;
use Magento\Cookie\Helper\Cookie as CookieHelper;
use Magento\Framework\Stdlib\CookieManagerInterface;
use PHPUnit\Framework\TestCase;

class VisitorTest extends TestCase
{
    private const TENANT = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';
    private const USER = '11111111-1111-4111-8111-111111111111';
    private const SESSION = '22222222-2222-4222-8222-222222222222';
    private const ADVISOR = '33333333-3333-4333-8333-333333333333';

    public function testReadsTheSdkCookies(): void
    {
        $visitor = $this->visitor(['bb_uid' => strtoupper(self::USER), 'bb_session' => self::SESSION, 'bb_advisor' => self::ADVISOR]);

        $this->assertSame(
            ['user_id' => self::USER, 'session_id' => self::SESSION, 'advisor_id' => self::ADVISOR, 'experiments' => []],
            $visitor->current(self::TENANT)
        );
    }

    public function testVisitorWithoutQuiz(): void
    {
        $current = $this->visitor(['bb_uid' => self::USER])->current(self::TENANT);

        $this->assertSame(self::USER, $current['user_id']);
        $this->assertNull($current['session_id']);
        $this->assertNull($current['advisor_id']);
    }

    public function testNoVisitorOrMalformedIdIsNobody(): void
    {
        $this->assertNull($this->visitor([])->current(self::TENANT));
        $this->assertNull($this->visitor(['bb_uid' => 'not-a-uuid'])->current(self::TENANT));
    }

    public function testCookieRestrictionWithoutConsentLinksNothing(): void
    {
        $this->assertNull($this->visitor(['bb_uid' => self::USER], consentRefused: true)->current(self::TENANT));
    }

    public function testFallsBackToTheQuizSessionFromTheStorefrontScript(): void
    {
        $session = ['bluebarry' => ['user_id' => self::USER, 'session_id' => self::SESSION, 'advisor_id' => self::ADVISOR]];

        $current = $this->visitor([], session: $session)->current(self::TENANT);

        $this->assertSame([self::USER, self::SESSION, self::ADVISOR], [$current['user_id'], $current['session_id'], $current['advisor_id']]);
    }

    public function testExperimentsAreFilteredToThisTenantAndSupportedDomains(): void
    {
        $contexts = [['domain' => 'quiz', 'targetId' => self::SESSION, 'exposureId' => self::ADVISOR]];
        for ($i = 0; $i < 10; $i++) {
            $contexts[] = ['domain' => 'search', 'targetId' => self::SESSION, 'exposureId' => sprintf('%08d-1111-4111-8111-111111111111', $i)];
        }
        $contexts[] = ['domain' => 'popup', 'targetId' => 'bad', 'exposureId' => self::ADVISOR];

        $mine = $this->visitor(['bb_uid' => self::USER, 'bb_experiments' => json_encode(['tenantId' => self::TENANT, 'contexts' => $contexts])]);
        $experiments = $mine->current(self::TENANT)['experiments'];
        $this->assertCount(8, $experiments); // the last eight valid ones; quiz and malformed dropped
        $this->assertSame('00000009-1111-4111-8111-111111111111', end($experiments)['exposureId']);

        $other = $this->visitor(['bb_uid' => self::USER, 'bb_experiments' => json_encode(['tenantId' => self::USER, 'contexts' => $contexts])]);
        $this->assertSame([], $other->current(self::TENANT)['experiments']);
    }

    private function visitor(array $cookies, bool $consentRefused = false, $session = null): Visitor
    {
        $cookieManager = $this->createStub(CookieManagerInterface::class);
        $cookieManager->method('getCookie')->willReturnCallback(fn ($name) => $cookies[$name] ?? null);
        $helper = $this->createStub(CookieHelper::class);
        $helper->method('isUserNotAllowSaveCookie')->willReturn($consentRefused);
        $double = new SessionDouble();
        $double->stored = $session;
        return new Visitor($cookieManager, $helper, $double);
    }
}
