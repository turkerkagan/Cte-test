<?php

use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

if (PHP_SAPI !== 'cli-server') {
    http_response_code(404);
    exit;
}
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$public = realpath(dirname(__DIR__, 2).'/public');
$asset = realpath($public.$path);
if ($asset && is_file($asset) && (str_starts_with($asset, $public.'/build/') || str_starts_with($asset, $public.'/vendor/') || str_starts_with($asset, $public.'/legacy/')) && ! preg_match('/\.(?:php|phtml|phar)$/i', $asset)) {
    return false;
}
$started = hrtime(true);
[$app] = require __DIR__.'/bootstrap.php';
$queryCount = 0;
$queryMs = 0;
DB::listen(function ($event) use (&$queryCount, &$queryMs) {
    $queryCount++;
    $queryMs += $event->time;
});
$kernel = $app->make(Kernel::class);
$request = Request::capture();
$response = $kernel->handle($request);
$response->headers->set('X-System-Queries', (string) $queryCount);
$response->headers->set('X-System-Query-Ms', (string) $queryMs);
$response->headers->set('X-System-Server-Ms', (string) ((hrtime(true) - $started) / 1000000));
if (str_contains($response->headers->get('Content-Type', ''), 'text/html')) {
    $probe = <<<'JS'
<script>
(() => {
 const page = JSON.parse(document.getElementById('app').getAttribute('data-page'));
 const ready = () => {
  if (page.component === 'StaffTree') return document.querySelectorAll('.react-flow__node').length === page.props.nodes.length;
  if (['People','ModuleRecords'].includes(page.component)) return !!document.querySelector('.data-table') && document.querySelectorAll('.data-table tbody tr').length === page.props.rows.length;
  return !!document.querySelector('#app h1, #app h2, #app .portal-shell');
 };
 const observer = new MutationObserver(() => {
  if (!ready()) return;
  observer.disconnect();
  requestAnimationFrame(() => requestAnimationFrame(() => {
   const nav = performance.getEntriesByType('navigation')[0];
   document.documentElement.setAttribute('data-system-timing', JSON.stringify({usable_ms:performance.now(),ttfb_ms:nav.responseStart,html_ms:nav.responseEnd,rows:document.querySelectorAll('tbody tr').length,nodes:document.querySelectorAll('.react-flow__node').length}));
  }));
 });
 observer.observe(document.getElementById('app'),{childList:true,subtree:true});
})();
</script>
JS;
    $response->setContent(str_replace('</body>', $probe.'</body>', $response->getContent()));
}
$response->send();
$kernel->terminate($request, $response);
