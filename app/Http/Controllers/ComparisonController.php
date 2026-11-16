<?php

namespace App\Http\Controllers;

use App\Services\Institution\CountReport;
use App\Services\Institution\PersonBatchActions;
use App\Services\Institution\PersonBatchImport;
use App\Services\People\PersonAccess;
use App\Services\Workflows\CaseList;
use App\Services\Workflows\CaseRecords;
use App\Services\Workflows\WorkflowAccess;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

final class ComparisonController extends Controller
{
    public function index(Request $r)
    {
        $kind = $r->route('comparison');
        app(WorkflowAccess::class)->authorize($r->user(), $kind);
        $cached = $r->session()->get('comparison.'.$kind);
        $data = $kind === 'sayim' ? app(CountReport::class)->read($cached['parsed'] ?? null) : ($cached['parsed'] ?? null);

        if ($kind === 'uyap_karsilastirma' && isset($cached['source']['records'])) {
            $data = $this->compareSource($cached['source']);
        }

        if ($r->wantsJson()) {
            return response()->json(['result' => $data, 'snapshot' => $cached ? hash('sha256', json_encode($cached['source'] ?? [], JSON_THROW_ON_ERROR)) : null, 'filename' => $cached['filename'] ?? null, 'canImport' => app(PersonAccess::class)->canWrite($r->user())])->header('Cache-Control', 'private, no-store');
        }

        return $this->noStore(Inertia::render('Comparison', ['applicationName' => config('app.name'), 'baseUrl' => route(WorkflowAccess::PAGES[$kind][1]), 'logoutUrl' => route('logout'), 'kind' => $kind, 'title' => WorkflowAccess::PAGES[$kind][0], 'result' => $data, 'snapshot' => $cached ? hash('sha256', json_encode($cached['source'] ?? [], JSON_THROW_ON_ERROR)) : null, 'filename' => $cached['filename'] ?? null, 'canImport' => app(PersonAccess::class)->canWrite($r->user()), 'statuses' => DB::table('person_statuses')->where('is_active', true)->where('included_in_active_list', true)->orderBy('position')->get(['id', 'label']), 'error' => $r->session()->get('comparison-error.'.$kind)])->toResponse($r));
    }

    public function upload(Request $r)
    {
        $kind = $r->route('comparison');
        app(WorkflowAccess::class)->authorize($r->user(), $kind);
        if ($kind === 'uyap_karsilastirma' && $r->has('snapshot')) {
            $r->validate(['snapshot' => ['required', 'string', 'size:64']]);
            $cached = $r->session()->get('comparison.'.$kind);
            abort_unless($cached && hash_equals(hash('sha256', json_encode($cached['source'] ?? [], JSON_THROW_ON_ERROR)), $r->input('snapshot')), 409, 'XML karşılaştırması başka bir pencerede değişti. Dosyayı tekrar karşılaştırın.');
        }
        if ($kind === 'uyap_karsilastirma' && $r->input('action') === 'search') {
            $r->validate(['text' => ['required', 'string', 'max:200000']]);

            return response()->json(['people' => app(PersonBatchActions::class)->search($r->user(), $r->input('text'))]);
        }
        if ($kind === 'uyap_karsilastirma' && in_array($r->input('action'), ['status-preview', 'status-apply', 'delete-preview', 'delete-apply'], true)) {
            $cached = $r->session()->get('comparison.'.$kind);

            return response()->json(app(PersonBatchActions::class)->mutate($r->user(), $r->all(), $r->boolean('xml_only') ? ($cached['source'] ?? abort(422)) : null));
        }
        if ($kind === 'uyap_karsilastirma' && $r->input('action') === 'add') {
            $cached = $r->session()->get('comparison.'.$kind);
            abort_unless($cached && isset($cached['source']['records']), 422, 'Önce XML dosyasını yükleyin.');

            return response()->json(app(PersonBatchImport::class)->import($r->user(), $cached['source']['records'], $r->input('selected', [])));
        }
        if ($kind === 'uyap_karsilastirma' && in_array($r->input('action'), ['text-preview', 'text-add'], true)) {
            app(PersonAccess::class)->authorize($r->user(), 'yazma');
            $r->validate(['text' => ['required', 'string', 'max:200000']]);
            $parsed = app(PersonBatchImport::class)->text($r->input('text'));
            if ($r->input('action') === 'text-preview') {
                return response()->json($parsed);
            }

            return response()->json(app(PersonBatchImport::class)->import($r->user(), $parsed['records'], $r->input('selected', [])));
        }
        if ($r->input('action') === 'reset') {
            $r->session()->forget(['comparison.'.$kind, 'comparison-error.'.$kind]);

            return response()->json(['saved' => true]);
        }
        try {
            $r->validate(['file' => ['required', 'file', 'max:20480']]);
            $file = $r->file('file');
            $extension = strtolower($file->getClientOriginalExtension());
            if (! in_array($extension, $kind === 'sayim' ? ['xls', 'xlsx'] : ['xml'], true)) {
                throw new \RuntimeException('Desteklenen bir dosya seçin.');
            }if ($kind === 'sayim') {
                require_once app_path('Services/Institution/Parsers/CountParser.php');
                $parsed = \App\Services\Institution\Parsers\execute_uyap_parser($file->getRealPath());
                if (! ($parsed['success'] ?? false)) {
                    throw new \RuntimeException($parsed['error'] ?? 'Dosya okunamadı.');
                }if (count($parsed['rows']) > 5000) {
                    throw new \RuntimeException('Karşılaştırma en fazla 5000 satır olabilir.');
                }
            } else {
                require_once app_path('Services/Institution/Parsers/UyapParser.php');
                $parsed = \App\Services\Institution\Parsers\uyap_xml_parse($file->getRealPath());
                $source = $parsed;
                $parsed = $this->compareSource($source);
            }
            if (strlen(json_encode($parsed, JSON_THROW_ON_ERROR)) > 5242880) {
                throw new \RuntimeException('Karşılaştırma en fazla 5 MB olabilir.');
            }$r->session()->put('comparison.'.$kind, ['source' => $source ?? null, 'parsed' => $parsed, 'filename' => mb_substr(basename($file->getClientOriginalName()), 0, 180)]);
            $r->session()->forget('comparison-error.'.$kind);
        } catch (\Throwable $e) {
            $r->session()->forget('comparison.'.$kind);
            $message = $e instanceof ValidationException ? 'Geçerli, en fazla 20 MB dosya seçin.' : ($e instanceof \RuntimeException ? $e->getMessage() : 'Dosya okunamadı.');
            $r->session()->put('comparison-error.'.$kind, $message);

            return response()->json(['message' => $message], 422);
        }

        return response()->json(['saved' => true, 'result' => $parsed, 'snapshot' => hash('sha256', json_encode($source ?? [], JSON_THROW_ON_ERROR)), 'filename' => $r->session()->get('comparison.'.$kind.'.filename')])->header('Cache-Control', 'private, no-store');
    }

    private function compareSource(array $source): array
    {
        $source += ['invalid' => [], 'page_count' => 0];
        require_once app_path('Services/Institution/Parsers/UyapParser.php');
        $case = app(CaseList::class);
        $options = app(CaseRecords::class)->options();
        $people = [];
        foreach ($case->query()->orderBy('p.id')->select($case->columns())->cursor() as $row) {
            $people[] = ['id' => $row->person_id, 'huk_tc' => $row->tc_identity, 'huk_adi' => $row->full_name, 'huk_blok' => $row->block, 'huk_kogus' => $row->room, 'huk_ranza' => $row->bed, 'huk_calis' => collect($options['huk_calis'])->firstWhere('id', (int) $row->huk_calis)['label'] ?? null, 'huk_karar' => collect($options['huk_karar'])->firstWhere('id', (int) $row->huk_karar)['label'] ?? null, 'huk_durum' => $row->status];
            if (count($people) > 5000) {
                throw new \RuntimeException('Karşılaştırma en fazla 5000 kişi olabilir.');
            }
        }

        return \App\Services\Institution\Parsers\uyap_compare_with_database($people, $source);
    }
}
