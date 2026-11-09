<?php

namespace App\Http\Controllers;

use App\Models\InmateFile;
use App\Models\Module;
use App\Models\UnitRecord;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class UnitRecordController extends Controller
{
    public const MODULES = [
        'sorumlu-basmemurluk' => [
            'permission' => 'intake',
            'task' => 'CTE-050',
            'title' => 'Sorumlu Başmemurluk',
            'description' => 'İlk görüş profil/geçmişi, sosyal-sağlık-risk alanları, DS işlemleri, filtre ve çıktı.',
            'sensitive' => false,
            'types' => ['İlk görüş', 'Sosyal profil', 'Sağlık beyanı', 'Risk notu', 'DS işlemi'],
            'fields' => ['Yaş', 'Meslek', 'Genel sağlık', 'Engellilik', 'Zarar verme durumu', 'Önceki infaz durumu', 'Aile suç durumu', 'Aile detayı', 'Bilgilendirme durumu', 'Tanıdık durumu', 'Tanıdık detayı', 'Kendine zarar riski', 'Risk notu', 'DS işlem durumu'],
        ],
        'degerlendirme' => [
            'permission' => 'evaluation',
            'task' => 'CTE-051',
            'title' => 'Değerlendirme',
            'description' => 'Personel, oda/yatak, temizlik, tasarruf, bakım, sağlık kuralı ve davranış geçmişi.',
            'sensitive' => false,
            'types' => ['Oda-yatak', 'Temizlik', 'Tasarruf', 'Bakım', 'Davranış'],
            'fields' => ['Personel uyumu', 'Oda/yatak uyumu', 'Temizlik ve düzen', 'Tasarruf', 'Kişisel bakım', 'Sağlık kurallarına uyum', 'Görevli tutumu', 'Tutum ve davranış'],
        ],
        'revir' => [
            'permission' => 'health',
            'task' => 'CTE-052',
            'title' => 'Revir',
            'description' => 'Sağlık profili; kronik/bulaşıcı/portör/UYAP; tarihsel şikâyet, tanı, tedavi, sevk ve not.',
            'sensitive' => true,
            'types' => ['Şikâyet', 'Tanı', 'Tedavi', 'Sevk', 'Portör'],
            'fields' => ['Statü', 'Genel sağlık', 'Kronik rahatsızlık', 'Bulaşıcı hastalık', 'Portör durumu', 'Portör tarihi 1', 'Portör tarihi 2', 'UYAP sağlık kaydı', 'Çalışma durumu', 'Ceza tehir', 'Şikâyet', 'Tanı', 'Tedavi', 'Sevk yeri'],
        ],
        'psikososyal' => [
            'permission' => 'psychosocial',
            'task' => 'CTE-053',
            'title' => 'Psikososyal',
            'description' => 'Tarihsel görüşmeler, SAMBA, öfke, SOMGP ve hassas erişim.',
            'sensitive' => true,
            'types' => ['Görüşme', 'SAMBA', 'Öfke', 'SOMGP', 'Takip'],
            'fields' => ['Görüşme özeti', 'SAMBA', 'Öfke kontrolü', 'SOMGP', 'Çalışma yeri', 'Genel sağlık', 'Takip sonucu'],
        ],
        'infaz' => [
            'permission' => 'execution',
            'task' => 'CTE-054',
            'title' => 'İnfaz',
            'description' => 'UYAP, geliş, DS/tahliye, ceza, mahkeme, kurul, ilave DS, olaylar, filtre ve sevk özeti.',
            'sensitive' => false,
            'types' => ['UYAP', 'Geliş', 'DS/Tahliye', 'Kurul', 'Olay'],
            'fields' => ['UYAP No', 'Cezaevine giriş tarihi', 'Doğum Tarihi', 'Yaş', 'DS Tarihi', 'Tahliye Tarihi', 'İnfaz durumu', 'Geliş Nedeni', 'Cinsel suç yazı tarihi', 'Kamu mahkemesi', 'Cinsel suç', 'Ceza yıl', 'Ceza ay', 'Ceza gün', 'Özel kurul', 'Özel kurul durumu', 'Özel kurul tarihi', 'İlave DS', 'İnfaz yılı', 'Satır rengi', 'Kurul notu', 'Ceza bilgisi'],
        ],
        'izin' => [
            'permission' => 'leave',
            'task' => 'CTE-055',
            'title' => 'İzin',
            'description' => 'Çoklu izin geçmişi, tür, süre, çıkış/dönüş tarih-saat, çakışma ve günlük rapor.',
            'sensitive' => false,
            'types' => ['Evci', 'Mazeret', 'Ödül', 'Yol', 'Dönüş'],
            'fields' => ['İzin türü', 'Gün sayısı', 'Çıkış', 'Çıkış Saati', 'Dönüş', 'Dönüş Saati'],
        ],
        'teblig-bakanlik-sevk' => [
            'permission' => 'notification',
            'task' => 'CTE-056',
            'title' => 'Tebliğ ve Bakanlık Sevk',
            'description' => 'Tarihsel kayıt, belge metadata, responsive/yazdırılabilir formlar.',
            'sensitive' => false,
            'types' => ['Tebliğ', 'Bakanlık sevk', 'Belge', 'Form'],
            'fields' => ['Belge no', 'Dosya/UYAP No', 'Müddetname tarihi', 'Tebliğ konusu', 'Tebliğ tarihi', 'Sevk yeri', 'Önceki kurumlar', 'Disiplin cezası', 'Kurumda yakını', 'Kronik rahatsızlık', 'Devam eden yargılama', 'Form notu'],
        ],
        'ziyaret' => [
            'permission' => 'visit',
            'task' => 'CTE-057',
            'title' => 'Ziyaret',
            'description' => 'Sınırsız ziyaretçi ve ziyaret geçmişi; eski 1/2/3 alanlarını satırlara dönüştürme.',
            'sensitive' => false,
            'types' => ['Ziyaretçi', 'Açık ziyaret', 'Kapalı ziyaret', 'Görüş'],
            'fields' => ['Ziyaretçi adı', 'Yakınlık', 'Telefon', 'Ziyaret tarihi', 'Ziyaret saati', 'Ziyaret türü', 'Ziyaret notu'],
        ],
        'manevi-rehberlik' => [
            'permission' => 'spiritual',
            'task' => 'CTE-058',
            'title' => 'Manevi Rehberlik',
            'description' => 'Profil, görüşme, talep, sınırsız faaliyet, kurs, belge ve ödül geçmişi.',
            'sensitive' => false,
            'types' => ['Görüşme', 'Talep', 'Faaliyet', 'Kurs', 'Ödül'],
            'fields' => ['Statü', 'Görüşme', 'Dini bilgi', 'Öğrenim', 'Kuran bilgisi', 'Hafızlık', 'Talep', 'Faaliyet 1', 'Faaliyet 2', 'Faaliyet 3', 'Kurs', 'Belge', 'Ödül', 'Açıklama'],
        ],
        'egitim' => [
            'permission' => 'education',
            'task' => 'CTE-059',
            'title' => 'Eğitim',
            'description' => 'Eğitim profili, okul/fakülte/bölüm/sınıf/öğrenci, şifreli eğitim hesap alanları.',
            'sensitive' => true,
            'types' => ['Profil', 'Okul', 'Fakülte', 'Bölüm', 'Sınav'],
            'fields' => ['Statü', 'Mezuniyet', 'Öğrenim', 'Okul', 'Öğretim', 'Sınıf', 'Okul şifresi', 'Domain şifresi', 'Fakülte', 'Bölüm', 'Öğrenci No', 'İletişim'],
        ],
        'kurs-hijyen-isg' => [
            'permission' => 'education',
            'task' => 'CTE-060',
            'title' => 'Kurs / Hijyen / İSG',
            'description' => 'Kurs kataloğu-katılım geçmişi, eğitmen, tarihler; hijyen ve iş güvenliği eğitim geçmişi.',
            'sensitive' => false,
            'types' => ['Kurs', 'Hijyen', 'İSG', 'Katılım', 'Eğitmen'],
            'fields' => ['Eğitim statüsü', 'Kurs adı', 'Öğretmen/Eğitmen', 'Süre', 'Başlangıç tarihi', 'Bitiş tarihi', 'Hijyen statüsü', 'Hijyen eğitimi', 'İş güvenliği eğitimi', 'Katılım durumu', 'Belge notu'],
        ],
        'egitim-istatistikleri' => [
            'permission' => 'education',
            'task' => 'CTE-061',
            'title' => 'Eğitim İstatistikleri',
            'description' => 'Arşivde kalmış işlevleri yerel grafik kütüphanesiyle geri kur.',
            'sensitive' => false,
            'types' => ['Aylık sayı', 'Kurs dağılımı', 'Başarı', 'Devam'],
            'fields' => ['Gösterge', 'Değer', 'Dönem', 'Açıklama'],
        ],
        'mesem' => [
            'permission' => 'mesem',
            'task' => 'CTE-062',
            'title' => 'MESEM',
            'description' => 'Profil/olay, görüşme, belge, sınıf, dal, mezuniyet, SGK, dilekçe, işe giriş ve istatistik.',
            'sensitive' => true,
            'types' => ['Profil', 'Olay', 'Görüşme', 'Belge', 'SGK', 'İşe giriş'],
            'fields' => ['Statü', 'Görüşme', 'Durum', 'Sınıf', 'Okul No', 'Dal', 'Mevcut Belge', 'Belge Türü', 'Mezuniyet', 'Ayrıldığı Okul', 'SGK', 'Dilekçe', 'Öğrenim Belgesi', 'İşe Giriş Tarihi'],
        ],
    ];

    public function index(Request $request, string $module): View
    {
        $config = $this->module($module);
        $this->authorizeView($config);

        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:255'],
            'record_type' => ['nullable', 'string', 'max:100'],
            'status_code' => ['nullable', 'string', 'max:100'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'is_closed' => ['nullable', 'in:0,1'],
            'field' => ['nullable', Rule::in(isset($config['field_definitions']) ? array_column(array_filter($config['field_definitions'], fn ($field) => $field['filterable']), 'key') : $config['fields'])],
            'field_value' => ['nullable', 'string', 'max:255'],
        ]);

        $records = UnitRecord::with(['inmate.institution', 'creator'])
            ->module($module)
            ->search($filters['q'] ?? null)
            ->when(isset($filters['record_type']), fn ($query) => $query->where('record_type', $filters['record_type']))
            ->when(isset($filters['status_code']), fn ($query) => $query->where('status_code', $filters['status_code']))
            ->when(isset($filters['from']), fn ($query) => $query->whereDate('occurred_at', '>=', $filters['from']))
            ->when(isset($filters['to']), fn ($query) => $query->whereDate('occurred_at', '<=', $filters['to']))
            ->when(isset($filters['is_closed']), fn ($query) => $query->where('is_closed', (bool) $filters['is_closed']))
            ->when(isset($filters['field'], $filters['field_value']), fn ($query) => $query->where('data->'.$filters['field'], 'like', '%'.$filters['field_value'].'%'))
            ->latest('occurred_at')
            ->latest()
            ->paginate(20)
            ->withQueryString();

        $stats = [
            'total' => UnitRecord::module($module)->count(),
            'open' => UnitRecord::module($module)->where('is_closed', false)->count(),
            'closed' => UnitRecord::module($module)->where('is_closed', true)->count(),
        ];

        return view('unit-records.index', [
            'module' => $module,
            'config' => $config,
            'modules' => app(\App\Services\UnitRegistry::class)->visible(),
            'records' => $records,
            'stats' => $stats,
        ]);
    }

    public function create(string $module, InmateFile $inmate): View
    {
        $config = $this->module($module);
        $this->authorizeUpdate($config);

        return view('unit-records.create', [
            'module' => $module,
            'config' => $config,
            'inmate' => $inmate,
            'record' => null,
        ]);
    }

    public function store(Request $request, string $module, InmateFile $inmate): RedirectResponse
    {
        $config = $this->module($module);
        $this->authorizeUpdate($config);
        $validated = $this->validated($request, $config);

        UnitRecord::create($this->recordData($validated, $module, $config, $inmate));

        return redirect()->route('unit-records.index', $module)->with('status', $config['title'].' kaydı oluşturuldu.');
    }

    public function edit(string $module, UnitRecord $record): View
    {
        $config = $this->module($module);
        $this->authorizeUpdate($config);
        abort_unless($record->module_code === $module, 404);

        return view('unit-records.create', [
            'module' => $module,
            'config' => $config,
            'inmate' => $record->inmate,
            'record' => $record,
        ]);
    }

    public function update(Request $request, string $module, UnitRecord $record): RedirectResponse
    {
        $config = $this->module($module);
        $this->authorizeUpdate($config);
        abort_unless($record->module_code === $module, 404);
        $validated = $this->validated($request, $config);
        $validated['is_closed'] = $validated['is_closed'] ?? $record->is_closed;

        $record->update($this->recordData($validated, $module, $config, $record->inmate, updating: true));

        return redirect()->route('unit-records.index', $module)->with('status', $config['title'].' kaydı güncellendi.');
    }

    public function close(string $module, UnitRecord $record): RedirectResponse
    {
        $config = $this->module($module);
        $this->authorizeUpdate($config);
        abort_unless($record->module_code === $module, 404);

        DB::transaction(function () use ($record): void {
            $record = UnitRecord::whereKey($record->id)->lockForUpdate()->firstOrFail();
            if (! $record->is_closed) {
                $record->update(['is_closed' => true, 'updated_by' => auth()->id()]);
            }
        });

        return redirect()->route('unit-records.index', $module)->with('status', $config['title'].' kaydı kapatıldı.');
    }

    private function validated(Request $request, array $config): array
    {
        $dynamicRules = [];
        if (isset($config['field_definitions'])) {
            $dynamicRules['data'] = ['nullable', 'array:'.implode(',', $config['fields'])];
            foreach ($config['field_definitions'] as $field) {
                $rules = [$field['required'] ? 'required' : 'nullable'];
                $rules = array_merge($rules, match ($field['type']) {
                    'date' => ['date_format:Y-m-d'],
                    'number' => ['numeric'],
                    'select' => ['string', Rule::in($field['options'])],
                    default => ['string', 'max:1000'],
                });
                $dynamicRules['data.'.$field['key']] = $rules;
            }
        }

        return $request->validate([
            'record_type' => ['required', 'string', 'max:100', Rule::in($config['types'])],
            'title' => ['required', 'string', 'max:255'],
            'occurred_at' => ['nullable', 'date'],
            'status_code' => ['nullable', 'string', 'max:100'],
            'is_closed' => ['nullable', 'boolean'],
            'notes' => ['nullable', 'string', 'max:3000'],
            'data' => ['nullable', 'array'],
            'data.*' => isset($config['field_definitions']) ? ['nullable'] : ['nullable', 'string', 'max:1000'],
            ...$dynamicRules,
            ...($config['permission'] === 'leave' ? [
                'data.Çıkış' => ['nullable', 'date_format:Y-m-d'],
                'data.Dönüş' => ['nullable', 'date_format:Y-m-d'],
                'data.Çıkış Saati' => ['nullable', 'date_format:H:i'],
                'data.Dönüş Saati' => ['nullable', 'date_format:H:i'],
            ] : []),
        ]);
    }

    private function recordData(array $validated, string $module, array $config, InmateFile $inmate, bool $updating = false): array
    {
        $occurredAt = $validated['occurred_at'] ?? now()->toDateString();
        $endedAt = null;
        if ($module === 'izin') {
            $occurredAt = $validated['data']['Çıkış'] ?? $occurredAt;
            $endedAt = $validated['data']['Dönüş'] ?? null;
        }

        return [
            'inmate_file_id' => $inmate->id,
            'module_code' => $module,
            'record_type' => $validated['record_type'],
            'title' => $validated['title'],
            'occurred_at' => $occurredAt,
            'ended_at' => $endedAt,
            'status_code' => $validated['status_code'] ?? null,
            'is_sensitive' => (bool) $config['sensitive'],
            'is_closed' => (bool) ($validated['is_closed'] ?? false),
            'data' => Arr::where($validated['data'] ?? [], fn ($value) => $value !== null && $value !== ''),
            'notes' => $validated['notes'] ?? null,
            ...($updating ? [] : ['created_by' => auth()->id()]),
            'updated_by' => auth()->id(),
        ];
    }

    private function module(string $module): array
    {
        return app(\App\Services\UnitRegistry::class)->find($module);
    }

    private function authorizeView(array $config): void
    {
        abort_unless(auth()->user()?->hasPermission($config['permission'].'.view'), 403);
    }

    private function authorizeUpdate(array $config): void
    {
        abort_unless(auth()->user()?->hasPermission($config['permission'].'.update'), 403);
    }
}
