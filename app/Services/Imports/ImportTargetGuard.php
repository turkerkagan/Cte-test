<?php

namespace App\Services\Imports;

use RuntimeException;

final class ImportTargetGuard
{
    public function assertCommandSafe(string $command, ?string $connection = null): void
    {
        $connection ??= config('database.default');
        if (in_array($command, ['migrate:fresh', 'db:wipe'], true)
            && $this->isPortalDatabase(config('database.connections.'.$connection.'.database'))) {
            throw new RuntimeException(config('database.connections.'.$connection.'.database') === 'cte'
                ? 'Paylaşılan cte veritabanında bütün tabloları silen komutlar kullanılamaz.'
                : 'Portal veritabanında bütün tabloları silen komutlar kullanılamaz.');
        }
    }

    public function assertSafe(?string $connection = null): void
    {
        $connection ??= config('database.default');
        $settings = config('database.connections.'.$connection, []);
        $driver = $settings['driver'] ?? '';
        $database = $settings['database'] ?? '';

        if (isset($settings['url']) && $settings['url'] !== '') {
            throw new RuntimeException('Veritabanı URL yönlendirmesi taşıma hedefinde kullanılamaz.');
        }

        if ($driver === 'sqlite' && $database === ':memory:' && app()->environment('testing')) {
            return;
        }

        // HTTP browser tests need persistence across requests, confined to their owned temp directory.
        $browserDirectory = realpath(getenv('CTE_BROWSER_DIRECTORY') ?: '');
        if ($driver === 'sqlite' && app()->environment('testing')
            && $browserDirectory !== false
            && preg_match('/^cte-browser-[a-zA-Z0-9]+$/D', basename($browserDirectory))
            && realpath(dirname($browserDirectory)) === realpath(sys_get_temp_dir())
            && $database === $browserDirectory.'/database.sqlite'
            && is_file($browserDirectory.'/settings.json')) {
            return;
        }

        if (in_array($driver, ['mysql', 'mariadb'], true) && is_string($database)) {
            if ($this->isPortalDatabase($database) && ($settings['prefix'] ?? '') === '') {
                return;
            }
            // Disposable automated test schemas must never become production alternatives.
            if (app()->environment('testing', 'system-test') && preg_match('/^cte_test_[a-z0-9_]+$/D', $database)) {
                return;
            }
        }

        throw new RuntimeException('Uygulama yalnız cte veya açıkça tanımlanan hosting veritabanını öneksiz tablolarla kullanır.');
    }

    private function isPortalDatabase(mixed $database): bool
    {
        $hosted = config('portal_security.hosted_database');

        return $database === 'cte'
            || (is_string($hosted) && $hosted !== '' && $database === $hosted);
    }
}
