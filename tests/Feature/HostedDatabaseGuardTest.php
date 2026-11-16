<?php

namespace Tests\Feature;

use App\Services\Imports\ImportTargetGuard;
use RuntimeException;
use Tests\TestCase;

class HostedDatabaseGuardTest extends TestCase
{
    public function test_explicit_hosting_database_is_accepted_without_connecting(): void
    {
        config(['portal_security.hosted_database' => 'hosting_cte', 'database.connections.hosted' => [
            'driver' => 'mysql', 'database' => 'hosting_cte', 'prefix' => '', 'host' => 'invalid.invalid',
        ]]);
        app(ImportTargetGuard::class)->assertSafe('hosted');
        $this->addToAssertionCount(1);
    }

    public function test_other_names_prefixes_and_url_overrides_remain_rejected(): void
    {
        config(['portal_security.hosted_database' => 'hosting_cte']);
        foreach ([['database' => 'other_cte'], ['prefix' => 'cte_'], ['url' => 'mysql://invalid.invalid/cte']] as $override) {
            config(['database.connections.hosted' => array_replace([
                'driver' => 'mysql', 'database' => 'hosting_cte', 'prefix' => '', 'url' => null,
            ], $override)]);
            try {
                app(ImportTargetGuard::class)->assertSafe('hosted');
                $this->fail('Unauthorized connection accepted');
            } catch (RuntimeException $error) {
                $this->assertNotEmpty($error->getMessage());
            }
        }
    }

    public function test_destructive_commands_also_protect_the_hosting_database(): void
    {
        config(['portal_security.hosted_database' => 'hosting_cte', 'database.connections.hosted.database' => 'hosting_cte']);
        $guard = app(ImportTargetGuard::class);
        $guard->assertCommandSafe('migrate', 'hosted');
        foreach (['db:wipe', 'migrate:fresh'] as $command) {
            try {
                $guard->assertCommandSafe($command, 'hosted');
                $this->fail('Hosting database wipe accepted');
            } catch (RuntimeException $error) {
                $this->assertStringContainsString('bütün tabloları', $error->getMessage());
            }
        }
    }
}
