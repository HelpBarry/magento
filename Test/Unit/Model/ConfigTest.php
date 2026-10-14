<?php

namespace Bluebarry\Bluebarry\Test\Unit\Model;

use Bluebarry\Bluebarry\Model\Config;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\DeploymentConfig;
use Magento\Framework\Encryption\EncryptorInterface;
use PHPUnit\Framework\TestCase;

class ConfigTest extends TestCase
{
    private const TENANT = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';

    public function testPointsAtProductionWithoutEnvSettings(): void
    {
        $config = $this->config([]);

        $this->assertSame('https://data.bluebarry.ai', $config->getApiUrl());
        $this->assertSame('https://advisor.bluebarry.ai', $config->getAdvisorUrl());
        $this->assertSame('https://cdn.bluebarry.ai/advisor/advisor.js', $config->getSdkUrl(self::TENANT));
    }

    public function testEnvSettingsPointTheStorefrontElsewhere(): void
    {
        $config = $this->config([
            'bluebarry/api_url' => 'https://staging.data.bluebarry.ai/',
            'bluebarry/advisor_url' => ' https://staging.advisor.bluebarry.ai/ ',
            'bluebarry/sdk_url' => 'https://staging.api.bluebarry.ai/sdk/{tenantId}/advisor.js',
        ]);

        $this->assertSame('https://staging.data.bluebarry.ai', $config->getApiUrl());
        $this->assertSame('https://staging.advisor.bluebarry.ai', $config->getAdvisorUrl());
        $this->assertSame(
            'https://staging.api.bluebarry.ai/sdk/' . self::TENANT . '/advisor.js',
            $config->getSdkUrl(self::TENANT)
        );
    }

    public function testTheTenantIsEncodedIntoTheSdkUrl(): void
    {
        $config = $this->config(['bluebarry/sdk_url' => 'https://example.test/sdk/{tenantId}/advisor.js']);

        $this->assertSame('https://example.test/sdk/a%22b%3C/advisor.js', $config->getSdkUrl('a"b<'));
    }

    private function config(array $env): Config
    {
        $deployment = $this->createMock(DeploymentConfig::class);
        $deployment->method('get')->willReturnCallback(fn (string $key) => $env[$key] ?? null);

        return new Config(
            $this->createMock(ScopeConfigInterface::class),
            $deployment,
            $this->createMock(EncryptorInterface::class)
        );
    }
}
