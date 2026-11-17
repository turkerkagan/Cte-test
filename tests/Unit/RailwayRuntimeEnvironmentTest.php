<?php

namespace Tests\Unit;

use Cte\Deployment\RuntimeEnvironment;
use Dotenv\Dotenv;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class RailwayRuntimeEnvironmentTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();
        require_once dirname(__DIR__, 2).'/deploy/railway/runtime-env.php';
        $this->directory = sys_get_temp_dir().'/cte-runtime-'.bin2hex(random_bytes(8));
        mkdir($this->directory, 0700);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory.'/{*,.*}', GLOB_BRACE) ?: [] as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }
        rmdir($this->directory);
        parent::tearDown();
    }

    public function test_secret_characters_round_trip_without_expanding_variables_or_exporting_unrelated_tokens(): void
    {
        $values = ['APP_KEY' => 'base64:synthetic-key', 'DB_PASSWORD' => 'a"b\\c${APP_KEY}#tail',
            'DB_DATABASE' => 'railway', 'RAILWAY_TOKEN' => 'never-export-this-token'];
        RuntimeEnvironment::write($this->directory, $values);
        $actual = Dotenv::createArrayBacked($this->directory)->load();
        $this->assertSame($values['DB_PASSWORD'], $actual['DB_PASSWORD']);
        $this->assertSame($values['APP_KEY'], $actual['APP_KEY']);
        $this->assertArrayNotHasKey('RAILWAY_TOKEN', $actual);
        if (DIRECTORY_SEPARATOR !== '\\') {
            $this->assertSame(0600, fileperms($this->directory.'/.env') & 0777);
        }
    }

    public function test_invalid_input_preserves_the_previous_configuration(): void
    {
        RuntimeEnvironment::write($this->directory, ['APP_KEY' => 'stable-key']);
        $before = file_get_contents($this->directory.'/.env');
        foreach ([[], ['APP_KEY' => 'changed-key', 'DB_PASSWORD' => "invalid\nvalue"]] as $invalid) {
            try {
                RuntimeEnvironment::write($this->directory, $invalid);
                $this->fail('Invalid configuration accepted');
            } catch (RuntimeException $error) {
                $this->assertSame($before, file_get_contents($this->directory.'/.env'));
            }
        }
    }
}
