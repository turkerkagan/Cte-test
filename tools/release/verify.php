<?php

if (PHP_SAPI !== 'cli') {
    exit(1);
}
$root = dirname(__DIR__, 2);
$manifestPath = $root.'/release.json';
if (! is_file($manifestPath)) {
    fwrite(STDERR, "Release manifesti bulunamadı.\n");
    exit(1);
}
$manifest = json_decode(file_get_contents($manifestPath), true, 64, JSON_THROW_ON_ERROR);
foreach ($manifest['files'] ?? [] as $relative => $hash) {
    if (str_contains($relative, '..') || str_contains($relative, '\\') || str_starts_with($relative, '/') || ! is_file($root.'/'.$relative) || ! hash_equals($hash, hash_file('sha256', $root.'/'.$relative))) {
        fwrite(STDERR, "Paket bütünlüğü doğrulanamadı.\n");
        exit(1);
    }
}
if (empty($manifest['files']) || isset($manifest['files']['.env']) || ! isset($manifest['files']['public/build/manifest.json'], $manifest['files']['vendor/autoload.php'], $manifest['files']['runtime/python/python.exe'])) {
    exit(1);
}
echo count($manifest['files']).' paket dosyası doğrulandı; sürüm '.$manifest['sourceCommit'].".\n";
