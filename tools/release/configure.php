<?php

use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Output\ConsoleOutput;
use Symfony\Component\Console\Question\Question;

// Standalone bootstrap: no Laravel boot, migrations, DB access or password arguments.
if (PHP_SAPI !== 'cli') {
    exit(1);
}
$root = dirname(__DIR__, 2);
if (is_file($root.'/.env')) {
    fwrite(STDERR, "Var olan .env korunur; kurulum yeniden yapılandırılmaz.\n");
    exit(1);
}
function line(string $label): string
{
    fwrite(STDOUT, $label.': ');

    return trim((string) fgets(STDIN));
}
$database = 'cte';
$prefix = '';
fwrite(STDOUT, "Veritabanı: cte; tablo öneki kullanılmaz.\n");
$url = line('Uygulama URL (http/https)');
$host = line('DB host');
$port = line('DB port');
$user = line('DB kullanıcı');
if (! filter_var($url, FILTER_VALIDATE_URL) || ! in_array(parse_url($url, PHP_URL_SCHEME), ['http', 'https'], true) || parse_url($url, PHP_URL_QUERY) || parse_url($url, PHP_URL_FRAGMENT) || parse_url($url, PHP_URL_USER) || ! preg_match('/^\d{2,5}$/D', $port) || (int) $port > 65535 || $host === '' || $user === '') {
    fwrite(STDERR, "Kurulum girdisi geçersiz; .env yazılmadı.\n");
    exit(1);
}
// Symfony question helper provides native hidden password entry on Windows and Unix.
require $root.'/vendor/autoload.php';
$helper = new QuestionHelper;
$question = new Question('DB parola (gizli): ');
$question->setHidden(true)->setHiddenFallback(false);
$password = $helper->ask(new ArgvInput, new ConsoleOutput, $question);
$quote = fn ($value) => '"'.str_replace(['\\', '"', "\r", "\n", '$'], ['\\\\', '\\"', '', '', '\\$'], (string) $value).'"';
$env = file_get_contents($root.'/.env.example');
$values = ['APP_ENV' => 'production', 'APP_DEBUG' => 'false', 'APP_KEY' => 'base64:'.base64_encode(random_bytes(32)), 'APP_URL' => rtrim($url, '/'), 'DB_DATABASE' => $database, 'DB_TABLE_PREFIX' => $prefix, 'DB_HOST' => $host, 'DB_PORT' => $port, 'DB_USERNAME' => $user, 'DB_PASSWORD' => $password, 'DB_SOCKET' => '', 'SESSION_SECURE_COOKIE' => parse_url($url, PHP_URL_SCHEME) === 'https' ? 'true' : 'false', 'CTE_PYTHON_BINARY' => str_replace('\\', '/', $root.'/runtime/python/python.exe')];
foreach ($values as $name => $value) {
    $env = preg_replace_callback('/^'.preg_quote($name, '/').'=.*$/m', fn () => $name.'='.$quote($value), $env, 1, $count);
    if ($count === 0) {
        $env .= "\n".$name.'='.$quote($value)."\n";
    }
}
$handle = fopen($root.'/.env', 'x');
if ($handle === false) {
    exit(1);
}
fwrite($handle, $env);
fclose($handle);
chmod($root.'/.env', 0600);
echo "Yeni .env ve APP_KEY oluşturuldu. Veritabanına bağlanılmadı.\n";
