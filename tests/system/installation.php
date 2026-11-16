<?php

if (PHP_SAPI !== 'cli' || ! in_array('--rehearse-empty-install', $argv, true)) {
    exit(1);
}
[$app, $directory, $settings] = require __DIR__.'/bootstrap.php';
use App\Services\Operations\OperationalState;
use App\Services\Operations\PortalBackup;
use Illuminate\Console\OutputStyle;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Console\Question\Question;
use Symfony\Component\Console\Tester\CommandTester;

if (realpath(base_path()) !== realpath($directory.'/portal-next') || ! str_contains(file_get_contents(base_path('.env')), 'SENTETİK KURULUM')) {
    throw new RuntimeException('Only the private source copy with synthetic environment is allowed.');
}
set_exception_handler(function (Throwable $error): void {
    fwrite(STDERR, 'Isolated installation rehearsal failed: '.get_class($error).' '.$error->getMessage()."\n");
    exit(1);
});
$passed = [];
$check = function (bool $value, string $name) use (&$passed): void {
    if (! $value) {
        throw new RuntimeException($name);
    }
    $passed[] = $name;
};
$server = require __DIR__.'/server.php';
$server->exec('CREATE DATABASE `'.$settings['database'].'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
$check(Artisan::call('portal:assert-empty') === 0, 'empty_namespace');
$check(Artisan::call('migrate', ['--force' => true]) === 0, 'fresh_migrations');
$check(DB::table('people')->count() === 0 && DB::table('users')->count() === 0 && DB::table('module_records')->count() === 0, 'zero_sample_business_data');
$check(Artisan::call('portal:assert-empty') === 1, 'repeat_install_refuses_nonempty_namespace');
$check(Artisan::call('portal:install-admin', ['username' => 'synthetic1', '--no-interaction' => true]) === 1, 'password_not_accepted_in_noninteractive_command');
$command = Artisan::all()['portal:install-admin'];
if (PHP_OS_FAMILY === 'Windows') {
    // Symfony's Windows hiddeninput.exe reads the real console, ignoring CommandTester streams.
    // Substitute only the private rehearsal's question transport; native terminal entry remains
    // a separate operator acceptance item, and production commands have no test password option.
    $app->bind(OutputStyle::class, function ($app, array $parameters) {
        return new class($parameters['input'], $parameters['output']) extends OutputStyle
        {
            private $testInput;

            public function __construct($input, $output)
            {
                $this->testInput = $input;
                parent::__construct($input, $output);
            }

            public function askQuestion(Question $question): mixed
            {
                if (! $question->isHidden() || $question->isHiddenFallback()) {
                    throw new RuntimeException('Administrator prompts must require hidden input.');
                }

                return rtrim((string) fgets($this->testInput->getStream()), "\r\n");
            }
        };
    });
}
$tester = new CommandTester($command);
$tester->setInputs([$settings['password'], $settings['password']]);
$check($tester->execute(['username' => 'synthetic1', '--name' => 'SENTETİK YÖNETİCİ'], ['interactive' => true]) === 0, 'interactive_initial_admin');
$check(! str_contains($tester->getDisplay(), $settings['password']), 'password_not_in_console_output');
$check(DB::table('users')->count() === 1 && DB::table('people')->count() === 0, 'exactly_one_admin_zero_people');
$tester->setInputs([$settings['password'], $settings['password']]);
$check($tester->execute(['username' => 'synthetic2'], ['interactive' => true]) === 1 && DB::table('users')->count() === 1, 'initial_admin_cannot_repeat');
$hash = DB::table('users')->value('password');
$check($hash !== $settings['password'] && password_verify($settings['password'], $hash), 'admin_password_hash');
$check(Artisan::call('portal:doctor') === 0, 'fresh_environment_doctor');
$check(Artisan::call('portal:acceptance', ['--output' => $directory.'/technical-acceptance.json']) === 0, 'read_only_technical_acceptance');
$disk = storage_path('app/private');
if (! is_dir($disk)) {
    mkdir($disk, 0700, true);
}
file_put_contents($disk.'/synthetic-document.bin', 'SENTETİK DOSYA İÇERİĞİ');
$backups = $directory.'/backups';
$restore = $directory.'/restore';
mkdir($backups, 0700);
mkdir($restore, 0700);
$check(Artisan::call('portal:backup', ['directory' => $backups, '--mysqldump' => '/not-a-real-dump']) === 1, 'backup_requires_maintenance');
Artisan::call('down');
$check(Artisan::call('portal:clean-private-documents') === 1, 'file_writer_stops_during_backup_maintenance');
$check(Artisan::call('portal:backup', ['directory' => $backups, '--mysqldump' => '/not-a-real-dump']) === 1, 'dump_failure_does_not_produce_backup');
$check(glob($backups.'/*.zip') === [] && app(OperationalState::class)->read()['backup']['success'] === false, 'failed_backup_visible');
$dump = getenv('CTE_TEST_MYSQLDUMP') ?: 'mysqldump';
$mysql = getenv('CTE_TEST_MYSQL') ?: 'mysql';
$check(Artisan::call('portal:backup', ['directory' => $backups, '--mysqldump' => $dump]) === 0, 'maintenance_consistent_backup');
$archive = glob($backups.'/*.zip')[0];
$check(Artisan::call('portal:verify-backup', ['archive' => $archive]) === 0, 'archive_checksum');
$check(app(OperationalState::class)->read()['backup']['success'] === true, 'successful_backup_visible');
$target = 'cte_restore_'.bin2hex(random_bytes(6));
$result = app(PortalBackup::class)->rehearse($archive, $target, $restore, $mysql);
$check($result['status'] === 'passed', 'restore_table_content_hashes');
$check(hash_file('sha256', base_path('.env')) === hash_file('sha256', $restore.'/configuration.env'), 'restored_app_key_and_configuration_preserved');
$check(file_get_contents($restore.'/files/private/synthetic-document.bin') === 'SENTETİK DOSYA İÇERİĞİ', 'private_document_restored');
$check(DB::table('users')->value('password') === $hash && DB::table('users')->count() === 1 && DB::table('people')->count() === 0, 'restore_source_unchanged');
mkdir($directory.'/restore-second', 0700);
try {
    app(PortalBackup::class)->rehearse($archive, $target, $directory.'/restore-second', $mysql);
    $check(false, 'existing_restore_target_rejected');
} catch (Throwable) {
    $passed[] = 'existing_restore_target_rejected';
}
Artisan::call('up');
$check(Artisan::call('portal:clean-private-documents') === 0, 'cleanup_job_recovers_after_maintenance');
$check(app(OperationalState::class)->read()['job']['success'] === true, 'cleanup_success_visible');
$server->exec('DROP DATABASE `'.$target.'`');
$report = ['status' => 'passed', 'checks' => $passed, 'checkCount' => count($passed), 'restore' => $result, 'realDatabaseTouched' => false, 'windowsExecution' => PHP_OS_FAMILY === 'Windows' ? 'passed' : 'pending', 'nativeWindowsPasswordEntry' => 'operator_acceptance_required', 'institutionAcceptance' => 'pending'];
file_put_contents($directory.'/installation-report.json', json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
echo json_encode(['status' => 'passed', 'checks' => count($passed)])."\n";
