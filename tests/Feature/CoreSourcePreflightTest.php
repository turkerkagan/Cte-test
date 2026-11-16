<?php

namespace Tests\Feature;

use App\Services\Exports\LegacyReadOnlyConnection;
use Tests\TestCase;

class CoreSourcePreflightTest extends TestCase
{
    public function test_source_connection_rejects_socket_dsn_injection_before_connecting(): void
    {
        $this->expectException(\RuntimeException::class);
        app(LegacyReadOnlyConnection::class)->run([
            'database' => 'cte', 'host' => '127.0.0.1', 'port' => 3306,
            'username' => 'unused', 'socket' => '/tmp/socket;dbname=cte_next_development',
        ], fn () => $this->fail('Bağlantı açılmamalı.'));
    }

    public function test_core_command_hides_source_connection_details(): void
    {
        config(['legacy.database' => 'cte_next_development', 'legacy.password' => 'never_print_source_secret']);
        $this->artisan('portal:check-core-source')
            ->expectsOutputToContain('Kaynak ön kontrolü tamamlanamadı')
            ->doesntExpectOutputToContain('never_print_source_secret')->assertFailed();
    }
}
