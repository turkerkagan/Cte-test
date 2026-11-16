<?php

if (PHP_SAPI !== 'cli') {
    exit(1);
}
$root = dirname(__DIR__, 2);
$path = $root.'/.env';
if (! is_file($path)) {
    exit(1);
}
$old = file_get_contents($path);
$value = 'CTE_PYTHON_BINARY="'.str_replace('\\', '/', $root.'/runtime/python/python.exe').'"';
$new = preg_replace_callback('/^CTE_PYTHON_BINARY=.*$/m', fn () => $value, $old, 1, $count);
if ($count === 0) {
    $new .= "\n".$value."\n";
}
if (file_put_contents($path, $new, LOCK_EX) === false) {
    exit(1);
}
echo "Belge çalışma zamanı yolu güncellendi; APP_KEY korundu.\n";
