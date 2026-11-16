<?php

// The shared bootstrap and server helper verify ownership of the disposable DB.
if (PHP_SAPI !== 'cli') {
    exit(1);
}
[$app, $directory, $settings] = require __DIR__.'/bootstrap.php';
require __DIR__.'/server.php';

$pages = [];
foreach (app('router')->getRoutes() as $route) {
    if (! in_array('GET', $route->methods(), true)) {
        continue;
    }
    $action = $route->getActionName();
    if (! preg_match('/Controller(?:@(?:index|create|edit|show|print|options|__invoke))?$/', $action)
        || str_contains($action, 'PersonPhotoController') || str_contains($action, 'LegacyWorkflowController')) {
        continue;
    }
    $uri = $route->uri();
    $variants = [$uri];
    if (str_contains($uri, '{kind}')) {
        $variants = [str_replace('{kind}', 'birimler', $uri), str_replace('{kind}', 'unvanlar', $uri)];
    }
    foreach ($variants as $variant) {
        $path = '/'.ltrim(str_replace(['{person}', '{id}', '{record}', '{code}'], ['1', '1', '1', 'synthetic-performance'], $variant), '/');
        if (str_contains($path, '{')) {
            continue;
        }
        if (str_ends_with($action, '@print')) {
            $path .= '?q='.urlencode(str_contains($path, '/moduller/') ? 'SENTETİK KAYIT 00000' : 'SENTETİK KİŞİ 000');
        }
        $pages[$path] = ['path' => $path, 'name' => $route->getName(), 'action' => $action];
    }
}
file_put_contents($directory.'/frontend-catalog.json', json_encode(array_values($pages), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
echo count($pages)." frontend URLs catalogued.\n";
