<?php

namespace App\Services\Workflows;

use App\Models\User;
use App\Services\Access\PageAccess;
use App\Services\Administration\BuiltinMenu;
use Illuminate\Support\Facades\DB;

final class WorkflowAccess
{
    public const UNIT_PAGES = ['ilkgorus', 'revir', 'infaz', 'izin', 'izin_rapor', 'infaz_sevk_raporu', 'ziyaret', 'manevi', 'egitim', 'kurslar', 'hijyen_isg', 'psikososyal', 'mesem'];

    public const PAGES = ['mesajlar' => ['Kişisel mesajlar', 'personal-messages.index'],
        'teblig' => ['Tebliğ belgesi', 'documents.teblig.index'],
        'bakanlik_sevk' => ['Bakanlık sevk formu', 'documents.bakanlik_sevk.index'],
        'kimlik' => ['Kimlik kartları', 'documents.kimlik.index'],
        'fotograf' => ['Fotoğraf galerisi', 'documents.fotograf.index'],
        'koguslar' => ['Koğuş listeleri', 'rooms.index'],
        'kisayol' => ['Toplu durum değişikliği', 'bulk-status.index'],
        'excel_merge' => ['Excel birleştirme', 'excel-merge.index'],
        'sayim' => ['Sayım karşılaştırması', 'count-report.index'],
        'uyap_karsilastirma' => ['UYAP kişi karşılaştırması', 'uyap-comparison.index'],
        'custom_rapor' => ['Özel rapor', 'custom-report.index'],
        'istatistik' => ['Kurum istatistikleri', 'statistics.index'],
        'mesemstat' => ['MESEM istatistikleri', 'mesem-statistics.index'],
        'yemek' => ['Yemek listeleri', 'meals.index'],
        'sohbet' => ['Sohbet', 'chat.index'],
        'telefon' => ['Telefon rehberi', 'institution.telefon.index'],
        'duyurular' => ['Duyurular', 'institution.duyurular.index'],
        'kurul' => ['Kurul ve komisyonlar', 'institution.kurul.index'],
        'mudurnobet' => ['Müdür nöbetleri', 'institution.mudurnobet.index'],
        'vardiyanobet' => ['Vardiya nöbetleri', 'institution.vardiyanobet.index'],
        'infaznobet' => ['İnfaz nöbetleri', 'institution.infaznobet.index'],
        'kismiarama' => ['Kısmi arama', 'institution.kismiarama.index'],
        'egitim' => ['Eğitim', 'services.egitim.index'],
        'kurslar' => ['Kurslar', 'services.kurslar.index'],
        'revir' => ['Revir', 'services.revir.index'],
        'ziyaret' => ['Ziyaret', 'services.ziyaret.index'],
        'manevi' => ['Manevi rehberlik', 'services.manevi.index'],
        'mesem' => ['MESEM', 'services.mesem.index'],
        'hijyen_isg' => ['Hijyen ve İSG', 'services.hijyen_isg.index'],
        'psikososyal' => ['Psikososyal', 'services.psikososyal.index'],
        'calisma' => ['Çalışma', 'services.calisma.index'],
        'personel_kadrosu' => ['Personel organizasyon ağacı', 'staff-tree.index'],
        'ilkgorus' => ['İlk görüş', 'interviews.index'],
        'infaz' => ['İnfaz ve tahliye', 'sentences.index'],
        'izin' => ['İzin kayıtları', 'leaves.index'],
        'izin_rapor' => ['İzin raporu', 'leave-report.index'],
        'infaz_sevk_raporu' => ['Sevk raporu', 'transfer-report.index']];

    public const SOURCE_POLICIES = ['koguslar' => 'kogus', 'kismiarama' => 'kismi_arama', 'uyap_karsilastirma' => 'hukumlu_kayit', 'kisayol' => 'hukumlu_kayit', 'mesemstat' => 'mesem'];

    public const READ_ONLY_PAGES = ['psikososyal', 'calisma', 'custom_rapor'];

    private function permitted(User $actor, string $page, string $permission): bool
    {
        $access = app(PageAccess::class);

        return $access->allows($actor, $page, $permission) || (isset(self::SOURCE_POLICIES[$page]) && $access->allows($actor, self::SOURCE_POLICIES[$page], $permission));
    }

    public function authorize(User $actor, string $page, string $permission = 'giris', bool $lock = false): User
    {
        abort_if($permission === 'yazma' && in_array($page, self::READ_ONLY_PAGES, true), 403);
        abort_unless(isset(self::PAGES[$page]) && in_array($permission, ['giris', 'yazma'], true), 403);
        $query = User::whereKey($actor->id);
        $fresh = ($lock ? $query->lockForUpdate() : $query)->first();
        abort_unless($fresh !== null && $fresh->is_active && ! $fresh->must_change_password, 403);
        abort_if($this->archived($page), 403);
        abort_if($page === 'custom_rapor' && ! $fresh->is_system_admin, 403);
        abort_unless($fresh->is_system_admin || $this->permitted($fresh, $page, $permission), 403);

        return $fresh;
    }

    public function canWrite(User $actor, string $page): bool
    {
        $fresh = User::find($actor->id);

        return ! in_array($page, self::READ_ONLY_PAGES, true) && isset(self::PAGES[$page]) && ! $this->archived($page) && $fresh !== null && $fresh->is_active && ! $fresh->must_change_password && ($fresh->is_system_admin || $this->permitted($fresh, $page, 'yazma'));
    }

    private function archived(string $page): bool
    {
        return DB::table('portal_pages')->whereIn('code', array_unique([$page, self::SOURCE_POLICIES[$page] ?? $page]))->where('is_active', false)->exists();
    }

    public function groupedNavigation(User $actor): array
    {
        $allowed = collect($this->navigation($actor))->keyBy('url');
        $structure = app(BuiltinMenu::class)->structure($actor);
        $build = function (array $items) use (&$build, $allowed): array {
            $result = [];
            foreach ($items as $item) {
                if (isset($item['children'])) {
                    $children = $build($item['children']);
                    if ($children !== []) {
                        $result[] = ['label' => $item['label'], 'url' => '#cte-'.$item['key'], 'children' => $children, 'icon' => $item['icon'] ?? ''];
                    }
                } elseif (isset($item['url'])) {
                    $result[] = ['label' => $item['label'], 'url' => $item['url'], 'icon' => $item['icon'] ?? '', 'target' => $item['target'] ?? '_self'];
                } else {
                    $url = route(self::PAGES[$item['page']][1]);
                    if ($allowed->has($url)) {
                        $result[] = ['label' => $item['label'], 'url' => $url, 'icon' => $item['icon'] ?? '', 'target' => $item['target'] ?? '_self'];
                    }
                }
            }

            return $result;
        };

        return $build($structure);
    }

    public function navigation(User $actor): array
    {
        $fresh = User::find($actor->id);
        if ($fresh === null || ! $fresh->is_active || $fresh->must_change_password) {
            return [];
        }
        $matrix = app(PageAccess::class)->displayMatrix($fresh);

        $archived = DB::table('portal_pages')->where('is_active', false)->pluck('code')->all();

        return collect(self::PAGES)->filter(fn ($entry, $code) => ! in_array($code, $archived, true) && ! in_array(self::SOURCE_POLICIES[$code] ?? $code, $archived, true) && ($code !== 'custom_rapor' || $fresh->is_system_admin) && ($fresh->is_system_admin || (($matrix[$code]['giris'] ?? false) || ($matrix[self::SOURCE_POLICIES[$code] ?? $code]['giris'] ?? false))))->map(fn ($entry) => ['label' => $entry[0],
            'url' => route($entry[1])])->values()->all();
    }
}
