<?php

namespace App\Http\Controllers;

use App\Services\Administration\AccountRequests;
use App\Services\Administration\BackupPlans;
use App\Services\Administration\BoardDefinitions;
use App\Services\Administration\DatabaseTools;
use App\Services\Administration\FieldDefinitions;
use App\Services\Administration\LegacySqlConversion;
use App\Services\Administration\ManagedDatabase;
use App\Services\Administration\ManagedFiles;
use App\Services\Administration\ManagementAccess;
use App\Services\Administration\ManagementReports;
use App\Services\Administration\ManagementSettings;
use App\Services\Administration\RecoveryAccess;
use App\Services\Administration\SqlExports;
use App\Services\Audit\AuditLog;
use App\Services\Imports\ImportConflict;
use App\Services\Imports\OrganizationImporter;
use App\Services\Imports\OrganizationSnapshot;
use App\Services\Modules\DefinitionConflict;
use App\Services\People\PersonConflict;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

final class ManagementController extends Controller
{
    private const TITLES = ['overview' => 'Genel Bakış', 'appearance' => 'Giriş & Footer Metinleri', 'boards' => 'Kurul Başlıkları', 'logins' => 'Kullanıcı Girişleri', 'audit' => 'İşlem Kayıtları', 'requests' => 'Başvuru ve Şifre Talepleri', 'fields' => 'Sayfa Alanları', 'database' => 'Veritabanı & Sistem', 'recovery' => 'Kurtarma & Dosya Yöneticisi'];

    public function index(Request $r)
    {
        $actor = app(ManagementAccess::class)->actor($r->user());
        $mode = $r->route('management_mode');
        $data = match ($mode) {
            'overview' => app(ManagementReports::class)->overview($actor),'appearance' => app(ManagementSettings::class)->appearance(),'boards' => app(BoardDefinitions::class)->read(),
            'logins' => app(ManagementReports::class)->logins($actor, $r->all()),'audit' => app(ManagementReports::class)->audit($actor, $r->all()),'requests' => app(AccountRequests::class)->read($actor, $r->all()),
            'fields' => app(FieldDefinitions::class)->state(),'database' => app(ManagedDatabase::class)->read($actor, $r->all()),'recovery' => app(ManagedFiles::class)->scope($r->query('scope', 'documents'))->read($actor, $r->query('path', '')),default => abort(404)
        };
        if ($r->wantsJson()) {
            return response()->json($data)->header('Cache-Control', 'private, no-store');
        }

        return $this->noStore(Inertia::render('AdminWorkspace', $data + ['mode' => $mode, 'title' => self::TITLES[$mode], 'applicationName' => config('app.name'), 'baseUrl' => $r->url()])->toResponse($r));
    }

    public function mutate(Request $r)
    {
        $actor = app(ManagementAccess::class)->actor($r->user());
        $mode = $r->route('management_mode');
        $result = [];
        try {
            switch ($mode) {
                case 'appearance': app(ManagementSettings::class)->saveAppearance($actor, $r->all());
                    break;
                case 'boards': app(BoardDefinitions::class)->save($actor, $r->all());
                    break;
                case 'fields': app(FieldDefinitions::class)->save($actor, $r->all());
                    break;
                case 'requests':
                    $d = $r->validate(['id' => ['required', 'integer', 'min:1']]);
                    app(AccountRequests::class)->decide($actor, $d['id'], $r->all());
                    $fresh = $actor->fresh();
                    if ((int) $fresh->session_version !== (int) $actor->session_version) {
                        $result['redirect'] = route('login');
                    } break;
                case 'recovery': $result = app(ManagedFiles::class)->scope($r->input('scope', 'documents'))->mutate($actor, $r->all());
                    break;
                case 'database':
                    $action = $r->validate(['action' => ['required', 'in:backup,verify,archive,restore,plan,plans,targets,plan-run,repair,conversion-preview,conversion,sql,structure,reset,diagnose,schema-repair,sql-preview,sql-conversion,recovery-token,converter-update']])['action'];
                    if ($action === 'sql') {
                        $result = app(DatabaseTools::class)->execute($actor, $r->all());
                    } elseif ($action === 'structure') {
                        $result = app(DatabaseTools::class)->structure($actor, $r->all());
                    } elseif ($action === 'reset') {
                        $result = app(DatabaseTools::class)->reset($actor, $r->all());
                    } elseif ($action === 'diagnose') {
                        $result = app(DatabaseTools::class)->diagnose();
                    } elseif ($action === 'schema-repair') {
                        $result = app(DatabaseTools::class)->repair($actor, $r->all());
                    } elseif ($action === 'recovery-token') {
                        $result = app(RecoveryAccess::class)->issue($actor);
                    } elseif ($action === 'converter-update') {
                        $result = app(SqlExports::class)->update($actor);
                    } elseif ($action === 'sql-preview') {
                        $d = $r->validate(['sql' => ['required', 'string', 'max:20971520']]);
                        $result = app(LegacySqlConversion::class)->preview($d['sql']);
                    } elseif ($action === 'sql-conversion') {
                        $result = app(LegacySqlConversion::class)->apply($actor, $r->all());
                    } elseif ($action === 'backup') {
                        $result = app(ManagedDatabase::class)->create($actor);
                    } elseif ($action === 'targets') {
                        app(BackupPlans::class)->saveTargets($actor, $r->all());
                        $result = ['saved' => true];
                    } elseif ($action === 'plans') {
                        app(BackupPlans::class)->save($actor, $r->all());
                    } elseif ($action === 'plan-run') {
                        $id = $r->validate(['id' => ['required', 'string', 'max:40']])['id'];
                        $result = app(BackupPlans::class)->run($actor, $id);
                    } elseif ($action === 'plan') {
                        app(ManagedDatabase::class)->plan($actor, $r->all());
                    } elseif ($action === 'repair') {
                        $r->validate(['confirmed' => ['accepted']]);
                        $result = app(ManagedDatabase::class)->repair($actor);
                    } elseif ($action === 'restore') {
                        $id = $r->validate(['id' => ['required', 'integer', 'min:1']])['id'];
                        $result = app(ManagedDatabase::class)->restore($actor, $id, $r->all());
                    } elseif (in_array($action, ['verify', 'archive'])) {
                        $id = $r->validate(['id' => ['required', 'integer', 'min:1']])['id'];
                        if ($action === 'verify') {
                            $result = app(ManagedDatabase::class)->verify($actor, $id);
                        } else {
                            app(ManagedDatabase::class)->archive($actor, $id);
                        }
                    } else {
                        $d = $r->validate(['snapshot' => ['required', 'array'], 'confirmed' => [$action === 'conversion' ? 'accepted' : 'nullable']]);
                        $snapshot = OrganizationSnapshot::fromArray($d['snapshot']);
                        $result = ['source' => $snapshot->source, 'counts' => array_map('count', $snapshot->tables), 'checksum' => hash('sha256', json_encode($d['snapshot'], JSON_THROW_ON_ERROR))];
                        if ($action === 'conversion') {
                            $r->validate(['checksum' => ['required', 'string', 'size:64']]);
                            abort_unless(hash_equals($result['checksum'], $r->input('checksum')), 409, 'Önizleme değişti.');
                            app(ManagedDatabase::class)->create($actor, 'before-conversion');
                            $result += DB::transaction(function () use ($actor, $snapshot) {
                                $actor = app(ManagementAccess::class)->actor($actor, true);
                                $result = app(OrganizationImporter::class)->apply($snapshot);
                                app(AuditLog::class)->record($actor, 'administration_conversion', 0, 'imported', $result);

                                return $result;
                            });
                        }
                    }
                    break;
                default: abort(404);
            }
        } catch (DefinitionConflict|PersonConflict|ImportConflict $e) {
            return response()->json(['message' => $e->getMessage()], 409)->header('Cache-Control', 'private, no-store');
        } catch (QueryException) {
            return response()->json(['message' => 'Veritabanı işlemi tamamlanamadı. Ön yedek ve girdiler korundu.'], 422)->header('Cache-Control', 'private, no-store');
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422)->header('Cache-Control', 'private, no-store');
        }
        if (isset($result['redirect'])) {
            Auth::logout();
            $r->session()->invalidate();
            $r->session()->regenerateToken();
        }

        return response()->json($result + ['saved' => true])->header('Cache-Control', 'private, no-store');
    }

    public function sqlDownload(Request $r)
    {
        app(ManagementAccess::class)->actor($r->user());
        $data = $r->validate(['kind' => ['required', 'in:schema,legacy-template,data'], 'table' => ['nullable', 'string', 'max:100']]);
        if ($data['kind'] === 'data') {
            $path = app(SqlExports::class)->dataFile($r->user(), $data['table'] ?? null);

            return response()->download($path, 'cte-data.sql', ['Content-Type' => 'application/sql; charset=utf-8', 'Cache-Control' => 'private, no-store'])->deleteFileAfterSend(true);
        }
        $sql = app(SqlExports::class)->download($r->user(), $data['kind'], $data['table'] ?? null);

        return response($sql)->header('Content-Type', 'application/sql; charset=utf-8')->header('Content-Disposition', 'attachment; filename="cte-'.$data['kind'].'.sql"')->header('Cache-Control', 'private, no-store');
    }

    public function download(Request $r, int $id)
    {
        $backup = app(ManagedDatabase::class)->backup($r->user(), $id);

        return response()->download($backup['path'], $backup['row']->name, ['Cache-Control' => 'private, no-store']);
    }

    public function file(Request $r)
    {
        app(ManagementAccess::class)->actor($r->user());
        $service = app(ManagedFiles::class)->scope($r->query('scope', 'documents'));
        $path = $service->path($r->query('path', ''));
        if (is_dir($path)) {
            $zip = $service->zip($r->user(), $r->query('path', ''));

            return response()->download($zip, (basename($path) ?: 'dosyalar').'.zip', ['Cache-Control' => 'private, no-store'])->deleteFileAfterSend(true);
        } abort_unless(is_file($path), 404);

        return response()->download($path, basename($path), ['Cache-Control' => 'private, no-store']);
    }

    public function upload(Request $r)
    {
        $d = $r->validate(['scope' => ['nullable', 'in:documents,application'], 'path' => ['nullable', 'string', 'max:300'], 'file' => ['required_without:files', 'file', 'max:20480'], 'files' => ['required_without:file', 'array', 'max:1000'], 'files.*' => ['file', 'max:20480'], 'paths' => ['nullable', 'array'], 'paths.*' => ['string', 'max:300']]);
        $service = app(ManagedFiles::class)->scope($d['scope'] ?? 'documents');
        if ($r->hasFile('files')) {
            $service->uploadMany($r->user(), $d['path'] ?? '', $r->file('files'), $d['paths'] ?? []);
        } else {
            $service->upload($r->user(), $d['path'] ?? '', $r->file('file'));
        }

        return back()->with('notice', 'Dosyalar yüklendi.');
    }
}
