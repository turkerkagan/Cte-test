<?php

namespace Tests\Feature;

use App\Support\ProductionSecurity;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

class ProductionSecurityTest extends TestCase
{
    public function test_production_security_configuration_passes_when_hardened(): void
    {
        Config::set('app.debug', false);
        Config::set('session.driver', 'file');
        Config::set('cache.default', 'file');
        Config::set('queue.default', 'sync');
        Config::set('session.lifetime', 30);
        Config::set('session.encrypt', true);
        Config::set('session.http_only', true);
        Config::set('session.same_site', 'strict');
        Config::set('logging.channels.stack.level', 'warning');

        $this->assertTrue(ProductionSecurity::passes());
    }

    public function test_production_security_fails_when_debug_is_enabled(): void
    {
        Config::set('app.debug', true);
        Config::set('session.driver', 'file');
        Config::set('cache.default', 'file');
        Config::set('queue.default', 'sync');
        Config::set('session.lifetime', 30);
        Config::set('session.encrypt', true);
        Config::set('session.http_only', true);
        Config::set('session.same_site', 'strict');
        Config::set('logging.channels.stack.level', 'warning');

        $this->assertFalse(ProductionSecurity::passes());
    }

    public function test_safe_error_pages_do_not_expose_debug_details(): void
    {
        $forbidden = view('errors.403')->render();
        $notFound = view('errors.404')->render();
        $serverError = view('errors.500')->render();

        foreach ([$forbidden, $notFound, $serverError] as $html) {
            $this->assertStringNotContainsString('Stack trace', $html);
            $this->assertStringNotContainsString('.env', $html);
            $this->assertStringNotContainsString('DB_PASSWORD', $html);
        }
    }
}
