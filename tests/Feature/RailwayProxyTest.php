<?php

namespace Tests\Feature;

use Illuminate\Http\Middleware\TrustProxies;
use Inertia\Testing\AssertableInertia as Assert;
use Symfony\Component\HttpFoundation\Request;
use Tests\TestCase;

class RailwayProxyTest extends TestCase
{
    private array $original = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->original = [$_ENV['CTE_TRUST_PROXY'] ?? null, $_SERVER['CTE_TRUST_PROXY'] ?? null];
    }

    protected function tearDown(): void
    {
        foreach (['env', 'server'] as $index => $source) {
            if ($source === 'env') {
                unset($_ENV['CTE_TRUST_PROXY']);
                if ($this->original[$index] !== null) {
                    $_ENV['CTE_TRUST_PROXY'] = $this->original[$index];
                }
            } else {
                unset($_SERVER['CTE_TRUST_PROXY']);
                if ($this->original[$index] !== null) {
                    $_SERVER['CTE_TRUST_PROXY'] = $this->original[$index];
                }
            }
        }
        TrustProxies::flushState();
        Request::setTrustedProxies([], 0);
        parent::tearDown();
    }

    private function configureProxy(bool $enabled): void
    {
        TrustProxies::flushState();
        Request::setTrustedProxies([], 0);
        $_ENV['CTE_TRUST_PROXY'] = $_SERVER['CTE_TRUST_PROXY'] = $enabled ? 'true' : 'false';
        $this->refreshApplication();
        $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.2']);
        $this->withHeaders([
            'X-Forwarded-Proto' => 'https',
            'X-Forwarded-Host' => 'cte-proxy.up.railway.app',
            'X-Forwarded-Port' => '443',
        ]);
    }

    public function test_railway_proxy_preserves_https_asset_and_form_urls(): void
    {
        $this->configureProxy(true);
        $this->get('http://internal.invalid/giris')
            ->assertOk()
            ->assertSee('https://cte-proxy.up.railway.app/build/assets/', false)
            ->assertInertia(fn (Assert $page) => $page->where('submitUrl', 'https://cte-proxy.up.railway.app/giris'));
    }

    public function test_local_installation_does_not_trust_forwarded_headers_by_default(): void
    {
        $this->configureProxy(false);
        $this->get('http://internal.invalid/giris')
            ->assertOk()
            ->assertDontSee('https://cte-proxy.up.railway.app/build/assets/', false)
            ->assertInertia(fn (Assert $page) => $page->where('submitUrl', 'http://internal.invalid/giris'));
    }
}
