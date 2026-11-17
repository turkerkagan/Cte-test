<?php
// Standalone PHP 8.0 environment check. No writes, DB connections or subprocesses.
declare(strict_types=1);

header('Cache-Control: no-store, max-age=0');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');

$required = ['ctype', 'curl', 'dom', 'fileinfo', 'filter', 'hash', 'mbstring', 'openssl', 'pcre', 'pdo', 'pdo_mysql', 'session', 'tokenizer', 'xml', 'zip'];
$extensions = [];
foreach ($required as $name) {
    $extensions[$name] = extension_loaded($name);
}
$modules = function_exists('apache_get_modules') ? apache_get_modules() : null;
$apache = [];
foreach (['mod_rewrite', 'mod_actions', 'mod_cgi', 'mod_cgid', 'mod_fcgid', 'mod_proxy_fcgi'] as $name) {
    $apache[$name] = $modules === null ? null : in_array($name, $modules, true);
}
$report = [
    'php_version' => PHP_VERSION,
    'php_82_or_newer' => PHP_VERSION_ID >= 80200,
    'operating_system_family' => PHP_OS_FAMILY,
    'process_bits' => PHP_INT_SIZE * 8,
    'php_interface' => PHP_SAPI,
    'extensions' => $extensions,
    'apache_modules_loaded' => $apache,
    'current_folder_writable' => is_writable(__DIR__),
    'memory_limit' => ini_get('memory_limit'),
    'upload_max_filesize' => ini_get('upload_max_filesize'),
    'post_max_size' => ini_get('post_max_size'),
    'cgi_execution_permission' => 'Bu kontrol ile doğrulanamaz; Apache yapılandırması ayrıca kontrol edilmelidir.',
];
$json = json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
if (($_GET['format'] ?? '') === 'json') {
    header('Content-Type: application/json; charset=utf-8');
    echo $json;
    exit;
}
header('Content-Type: text/html; charset=utf-8');
$escape = static fn ($value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
?>
<!doctype html>
<html lang="tr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>CTE ortam kontrolü</title>
    <style>
        body{font:16px/1.55 system-ui,sans-serif;background:#f4f6f8;color:#17212b;margin:0;padding:28px}
        main{max-width:850px;margin:auto;background:white;padding:28px;border:1px solid #dbe2e8;border-radius:12px}
        h1{margin-top:0}textarea{box-sizing:border-box;width:100%;min-height:650px;padding:16px;font:14px/1.5 monospace;border:1px solid #b9c6d2;border-radius:6px}
        .result{padding:12px 16px;background:#eef3f8;border-left:4px solid #39688e}code{background:#eef3f8;padding:2px 5px}
    </style>
</head>
<body><main>
    <h1>CTE ortam kontrolü</h1>
    <p class="result">PHP: <strong><?= $escape(PHP_VERSION) ?></strong> — <?= PHP_VERSION_ID >= 80200 ? 'Uygulamanın PHP sürümü gereksinimi karşılanıyor.' : 'Mevcut Laravel uygulaması için ayrı PHP 8.2 veya üzeri gerekiyor.' ?></p>
    <p>Bu dosya yalnız ortam bilgilerini okur. Dosya değiştirmez, veritabanına bağlanmaz ve program çalıştırmaz.</p>
    <p>Aşağıdaki sonucu kopyalayabilir veya ekran görüntüsünü paylaşabilirsin. <code>true</code> yüklü, <code>false</code> yüklü değil, <code>null</code> okunamadı demektir.</p>
    <p>CGI/FastCGI modülünün yüklü olması, klasörden ayrı PHP çalıştırma izni bulunduğunu kanıtlamaz.</p>
    <textarea readonly aria-label="Ortam kontrol sonucu"><?= $escape($json) ?></textarea>
    <p>Sonucu aldıktan sonra bu kontrol dosyasını kaldırabilirsin.</p>
</main></body></html>
