<?php

namespace App\Services\Workspace;

use App\Models\User;
use App\Services\Access\PageAccess;
use App\Services\Workflows\WorkflowAccess;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

final class HandoverSummary
{
    public function read(User $actor, array $input): array
    {
        $actor = app(WorkspaceAccess::class)->actor($actor);
        $data = Validator::make($input, ['hours' => ['nullable', 'integer', 'between:1,168']])->validate();
        $since = now()->subHours($data['hours'] ?? 24);
        $matrix = app(PageAccess::class)->matrix($actor);
        $can = fn (string $code) => ($matrix[$code]['giris'] ?? false);
        $tasks = [];
        if ($can('mesajlar')) {
            app(WorkflowAccess::class)->authorize($actor, 'mesajlar');
            $tasks[] = ['label' => 'Okunmamış kişisel mesaj', 'count' => DB::table('personal_messages')->where('recipient_id', $actor->id)->where('recipient_deleted', false)->whereNull('read_at')->count(), 'url' => route('personal-messages.index')];
        }
        foreach (['izin' => ['izin_donus_tarihi', 'Önümüzdeki 7 günde izin dönüşü'], 'infaz' => ['infaz_tahliye_tarihi', 'Önümüzdeki 7 günde tahliye tarihi']] as $code => [$field, $label]) {
            if ($can($code)) {
                app(WorkflowAccess::class)->authorize($actor, $code);
                $tasks[] = ['label' => $label, 'count' => DB::table('person_case_data')->whereBetween($field, [now()->toDateString(), now()->addDays(7)->toDateString()])->count(), 'url' => route(WorkflowAccess::PAGES[$code][1])];
            }
        }
        // Only the actor's own metadata is projected. No record values, IPs or other staff's events.
        $activity = DB::table('audit_events')->where('actor_id', $actor->id)->where('created_at', '>=', $since)
            ->where('resource_type', '<>', 'page_visit')->select('resource_type', 'action')
            ->selectRaw('COUNT(*) as count, MAX(created_at) as last_at')->groupBy('resource_type', 'action')
            ->orderByDesc('last_at')->limit(30)->get()->all();

        return ['since' => $since->toIso8601String(), 'generatedAt' => now()->toIso8601String(), 'hours' => (int) ($data['hours'] ?? 24), 'tasks' => $tasks, 'activity' => $activity];
    }
}
