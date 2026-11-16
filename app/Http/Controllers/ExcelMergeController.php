<?php

namespace App\Http\Controllers;

use App\Services\Workflows\WorkflowAccess;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Symfony\Component\Process\Process;

final class ExcelMergeController extends Controller
{
    public function index(Request $r)
    {
        app(WorkflowAccess::class)->authorize($r->user(), 'excel_merge');
        $result = $r->session()->get('excel-merge.result');
        if ($result && ($result['owner'] !== $r->user()->id || $result['expires'] < time())) {
            $this->clear($r);
            $result = null;
        }

        return $this->noStore(Inertia::render('ExcelMerge', ['applicationName' => config('app.name'), 'title' => 'Excel birleştirme', 'baseUrl' => route('excel-merge.index'), 'logoutUrl' => route('logout'), 'result' => $result ? array_diff_key($result, array_flip(['owner', 'path', 'expires'])) : null])->toResponse($r));
    }

    private function clear(Request $r): void
    {
        $old = $r->session()->get('excel-merge.result');
        if ($old && $old['owner'] === $r->user()->id && preg_match('~^excel-merge/[0-9a-f-]+/output.xlsx$~D', $old['path'])) {
            Storage::disk('local')->deleteDirectory(dirname($old['path']));
        }$r->session()->forget('excel-merge.result');
    }

    public function merge(Request $r)
    {
        app(WorkflowAccess::class)->authorize($r->user(), 'excel_merge');
        if ($r->input('action') === 'reset') {
            $this->clear($r);

            return response()->json(['saved' => true]);
        }$r->validate(['files' => ['required', 'array', 'min:2', 'max:20'], 'files.*' => ['required', 'file', 'max:25600']]);
        $dir = 'excel-merge/'.Str::uuid();
        $disk = Storage::disk('local');
        $configs = [];
        try {
            foreach ($r->file('files') as $i => $file) {
                $ext = strtolower($file->getClientOriginalExtension());
                abort_unless(in_array($ext, ['xls', 'xlsx', 'html', 'htm'], true), 422, 'XLS, XLSX veya HTML dosyası seçin.');
                $path = $file->storeAs($dir, 'input-'.$i.'.'.$ext, 'local');
                abort_if(! $path, 503, 'Dosya kaydedilemedi.');
                $configs[] = ['path' => $disk->path($path), 'filename' => mb_substr(basename($file->getClientOriginalName()), 0, 180), 'label' => 'Dosya '.($i + 1)];
            }$output = $dir.'/output.xlsx';
            $config = $dir.'/config.json';
            abort_unless($disk->put($config, json_encode(['files' => $configs, 'output' => $disk->path($output)], JSON_THROW_ON_ERROR)), 503);
            $process = new Process([config('document_tools.python'), app_path('Services/Institution/Parsers/excel_merge_engine.py'), $disk->path($config)], base_path(), ['PYTHONIOENCODING' => 'utf-8', 'PYTHONUTF8' => '1'], null, 120);
            $process->run();
            $data = json_decode($process->getOutput(), true);
            if (! $process->isSuccessful() || ! ($data['success'] ?? false) || ! $disk->exists($output)) {
                return response()->json(['message' => $data['error'] ?? 'Excel motoru çalıştırılamadı; Python, openpyxl ve xlrd kurulumunu kontrol edin.', 'summaries' => $data['summaries'] ?? []], 422);
            }abort_if($disk->size($output) > 25 * 1024 * 1024, 422, 'Birleşik dosya en fazla 25 MB olabilir.');
            $this->clear($r);
            $r->session()->put('excel-merge.result', ['owner' => $r->user()->id, 'path' => $output, 'expires' => time() + 3600, 'total_records' => $data['total_records'], 'total_columns' => $data['total_columns'], 'columns' => $data['columns'], 'preview_rows' => $data['preview_rows'], 'summaries' => $data['summaries']]);

            return response()->json(['saved' => true]);
        } finally {
            foreach ($configs as $c) {
                $relative = $dir.'/'.basename($c['path']);
                $disk->delete($relative);
            }$disk->delete($dir.'/config.json');
            $saved = $r->session()->get('excel-merge.result');
            if (($saved['path'] ?? null) !== ($dir.'/output.xlsx')) {
                $disk->deleteDirectory($dir);
            }
        }
    }

    public function download(Request $r)
    {
        app(WorkflowAccess::class)->authorize($r->user(), 'excel_merge');
        $data = $r->session()->get('excel-merge.result');
        abort_unless($data && $data['owner'] === $r->user()->id && $data['expires'] >= time(), 404);
        abort_unless(preg_match('~^excel-merge/[0-9a-f-]+/output.xlsx$~D', $data['path']) && Storage::disk('local')->exists($data['path']), 404);

        return response()->download(Storage::disk('local')->path($data['path']), 'birlestirilmis.xlsx', ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }
}
