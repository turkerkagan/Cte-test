<?php

use Illuminate\Http\Request;

if (PHP_SAPI !== 'cli-server') {
    exit(1);
}
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
// Serve only built assets; never expose arbitrary source or a second PHP entrypoint.
if (str_starts_with($path, '/build/') || str_starts_with($path, '/legacy/')) {
    $public = realpath(dirname(__DIR__, 2).'/public');
    $file = realpath($public.$path);
    if ($file && str_starts_with($file, $public.DIRECTORY_SEPARATOR) && is_file($file) && pathinfo($file, PATHINFO_EXTENSION) !== 'php') {
        return false;
    }
}
[$app] = require __DIR__.'/runtime.php';
$app->handleRequest(Request::capture());
