<?php

namespace App\Console\Commands;

use App\Services\Operations\PortalHealth;
use Illuminate\Console\Command;
use Symfony\Component\Process\Process;
use Throwable;

final class PortalAcceptance extends Command
{
    protected $signature = 'portal:acceptance {--output= : Özel JSON rapor yolu}';

    protected $description = 'Kurulu ortamı salt okunur kontrol eder; kurum/browser kabulünü otomatik onaylamaz';

    public function handle(PortalHealth $health): int
    {
        $snapshot = $health->snapshot();
        $checks = ['php' => version_compare(PHP_VERSION, '8.2.0', '>='), 'database' => $snapshot['database'], 'installed' => $snapshot['installed'], 'local_assets' => $snapshot['assets'], 'debug_disabled' => $snapshot['debugDisabled'], 'storage_writable' => is_writable(storage_path()), 'cache_writable' => is_writable(app()->bootstrapPath('cache'))];
        foreach (['pdo_mysql', 'mbstring', 'openssl', 'dom', 'fileinfo', 'zip'] as $extension) {
            $checks['extension_'.$extension] = extension_loaded($extension);
        }
        try {
            $process = new Process([config('document_tools.python'), '-c', 'import openpyxl,xlrd,et_xmlfile; print("OK")']);
            $process->setTimeout(30);
            $process->run();
            $checks['document_runtime'] = $process->isSuccessful();
        } catch (Throwable) {
            $checks['document_runtime'] = false;
        }
        $result = ['format' => 1, 'at' => now()->toIso8601String(), 'sourceCommit' => $snapshot['version'], 'platform' => PHP_OS_FAMILY, 'php' => PHP_VERSION, 'checks' => $checks, 'status' => in_array(false, $checks, true) ? 'failed' : 'technical_checks_passed', 'institutionAcceptance' => 'pending', 'backupRestoreRehearsal' => 'separate_required', 'browserAcceptance' => 'separate_required'];
        if ($this->option('output')) {
            $path = $this->option('output');
            $parent = realpath(dirname($path));
            $public = realpath(public_path());
            if (! $parent || ($public && str_starts_with(strtolower($parent).DIRECTORY_SEPARATOR, strtolower($public).DIRECTORY_SEPARATOR)) || is_file($path)) {
                $this->error('Rapor yeni ve web kökü dışında bir özel dosya olmalı.');

                return self::FAILURE;
            }
            $handle = fopen($path, 'x');
            if (! $handle) {
                return self::FAILURE;
            }
            fwrite($handle, json_encode($result, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
            fclose($handle);
            chmod($path, 0600);
        }
        $this->line(json_encode($result, JSON_THROW_ON_ERROR));

        return in_array(false, $checks, true) ? self::FAILURE : self::SUCCESS;
    }
}
