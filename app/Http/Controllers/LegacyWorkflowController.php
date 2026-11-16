<?php

namespace App\Http\Controllers;

use App\Services\People\PersonAccess;
use App\Services\Workflows\WorkflowAccess;
use Illuminate\Http\Request;

final class LegacyWorkflowController extends Controller
{
    public function __invoke(Request $r)
    {
        $aliases = json_decode(file_get_contents(resource_path('defaults/legacy-route-map.json')), true, 64, JSON_THROW_ON_ERROR);
        if ($r->path() === 'index.php' && ! $r->has('sayfa')) {
            app(PersonAccess::class)->authorize($r->user());

            return redirect()->route('portal');
        }
        $file = $r->route('legacy_file') ?? $r->query('sayfa');
        abort_unless(is_string($file) && isset($aliases[$file]), 404);
        $target = $aliases[$file];
        app(WorkflowAccess::class)->authorize($r->user(), $target['page']);

        return redirect()->route($target['route']);
    }
}
