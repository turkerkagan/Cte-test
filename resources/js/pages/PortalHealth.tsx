import { formatDateTime } from '../lib/format';
import { Head, Link } from '@inertiajs/react';
import AppShell from '../components/AppShell';
import DataTable from '../components/DataTable';
import type { Column } from '../lib/table';
type RouteHealth = {
    route: string;
    requests: number;
    errors: number;
    slow: number;
    max_ms: number;
    max_queries: number;
    max_query_ms: number;
};
const columns: Column<RouteHealth>[] = [
    { key: 'route', label: 'Route', value: (r) => r.route },
    { key: 'requests', label: 'İstek', value: (r) => r.requests },
    { key: 'errors', label: 'Sunucu hatası', value: (r) => r.errors },
    { key: 'slow', label: 'Yavaş istek', value: (r) => r.slow },
    { key: 'max_ms', label: 'En uzun ms', value: (r) => r.max_ms },
    { key: 'max_queries', label: 'En çok sorgu', value: (r) => r.max_queries },
    { key: 'max_query_ms', label: 'En uzun sorgu toplamı ms', value: (r) => r.max_query_ms },
];
type Result = { at: string; success: boolean; last_success_at?: string | null } | null;
type BackupPlanHealth = {
    id: string;
    name: string;
    enabled: boolean;
    status: string;
    attempts: number;
    retry_at: string | null;
    last_local_success: string | null;
    last_target_success: string | null;
};
type Health = {
    version: string;
    php: string;
    database: boolean;
    installed: boolean;
    maintenance: boolean;
    debugDisabled: boolean;
    assets: boolean;
    backup: Result;
    job: Result;
    scheduler?: Result;
    schedulerHealthy?: boolean;
    day: string | null;
    measurementEnabled: boolean;
    institutionAcceptance: string;
    routes: RouteHealth[];
    backupPlans?: BackupPlanHealth[];
    cleanup?: { pending: number; oldest: string | null } | null;
    pendingMigrations?: string[] | null;
};
const dateLabel = (at?: string | null) => formatDateTime(at, 'Henüz yok');
const planColumns: Column<BackupPlanHealth>[] = [
    { key: 'name', label: 'Plan', value: (plan) => plan.name },
    { key: 'enabled', label: 'Etkin', value: (plan) => plan.enabled },
    { key: 'status', label: 'Hedef teslimatı', value: (plan) => plan.status },
    {
        key: 'last_local_success',
        label: 'Son yerel başarı',
        value: (plan) => dateLabel(plan.last_local_success),
    },
    {
        key: 'last_target_success',
        label: 'Son hedef başarısı',
        value: (plan) => dateLabel(plan.last_target_success),
    },
    { key: 'attempts', label: 'Ardışık hata', value: (plan) => plan.attempts },
    { key: 'retry_at', label: 'Sonraki deneme', value: (plan) => dateLabel(plan.retry_at) },
];
export default function PortalHealth({
    applicationName,
    navigation,
    health,
}: {
    applicationName: string;
    navigation?: { label: string; url: string }[];
    logoutUrl: string;
    health: Health;
}) {
    const result = (value: Result) =>
        value
            ? `${value.success ? 'Başarılı' : 'Başarısız'} · ${formatDateTime(value.at)}`
            : 'Henüz çalıştırılmadı';
    const failedPlans =
        health.backupPlans?.filter((plan) => plan.enabled && plan.status === 'Başarısız').length ?? 0;
    return (
        <AppShell
            applicationName={applicationName}
            navigation={navigation}
            pageTitle="Sistem sağlığı"
            toolbar={null}
            search={null}
        >
            <Head title="Sistem sağlığı" />
            {failedPlans > 0 && (
                <p className="form-error" role="alert">
                    {failedPlans} etkin yedek planında hedef teslimatı başarısız. Sonraki deneme ve son hedef
                    başarısını aşağıdan kontrol edin.
                </p>
            )}
            <section className="health-overview" aria-label="Genel bakış">
                {[
                    ['Veritabanı', health.database ? 'Bağlantı başarılı' : 'Kontrol gerekli'],
                    ['Arayüz dosyaları', health.assets ? 'Hazır' : 'Eksik'],
                    ['Bakım modu', health.maintenance ? 'Açık' : 'Kapalı'],
                    ['Son yerel yedek denemesi', result(health.backup)],
                ].map(([label, value]) => (
                    <article key={label}>
                        <h2>{label}</h2>
                        <strong>{value}</strong>
                    </article>
                ))}
            </section>
            <section className="editor-panel">
                <h2>Hızlı İşlemler</h2>
                <div className="health-quick-links">
                    {navigation
                        ?.filter((item) => item.url === '#management')
                        .flatMap(
                            (item) =>
                                (item as typeof item & { children?: { label: string; url: string }[] })
                                    .children ?? [],
                        )
                        .map((item) => (
                            <Link className="button" key={item.url} href={item.url}>
                                {item.label}
                            </Link>
                        ))}
                </div>
            </section>
            <section className="editor-panel health-panel">
                <h2>Çalışma durumu</h2>
                <dl>
                    <dt>Sürüm</dt>
                    <dd>{health.version}</dd>
                    <dt>PHP</dt>
                    <dd>{health.php}</dd>
                    <dt>Zamanlayıcı</dt>
                    <dd>
                        {health.schedulerHealthy ? 'Çalışıyor' : 'Son 3 dakikada tetiklenmedi'}
                        {health.scheduler?.at && ` · ${health.scheduler.at}`}
                    </dd>
                    <dt>Veritabanı</dt>
                    <dd>{health.database ? 'Bağlantı başarılı' : 'Bağlantı kontrol edilmeli'}</dd>
                    <dt>İlk yönetici kurulumu</dt>
                    <dd>{health.installed ? 'Tamamlandı' : 'Bekliyor'}</dd>
                    <dt>Yerel arayüz dosyaları</dt>
                    <dd>{health.assets ? 'Hazır' : 'Eksik'}</dd>
                    <dt>Hata ayıklama</dt>
                    <dd>{health.debugDisabled ? 'Kapalı' : 'Açık; kurumda kapatılmalı'}</dd>
                    <dt>Bakım modu</dt>
                    <dd>{health.maintenance ? 'Açık' : 'Kapalı'}</dd>
                    <dt>Son yerel yedek denemesi</dt>
                    <dd>{result(health.backup)}</dd>
                    <dt>Son başarılı yerel yedek</dt>
                    <dd>{dateLabel(health.backup?.last_success_at)}</dd>
                    <dt>Son dosya temizleme görevi</dt>
                    <dd>{result(health.job)}</dd>
                    <dt>Bekleyen dosya silme işi</dt>
                    <dd>{health.cleanup ? health.cleanup.pending : 'Kuyruk tablosu kontrol edilmeli'}</dd>
                    <dt>En eski bekleyen iş</dt>
                    <dd>{dateLabel(health.cleanup?.oldest)}</dd>
                    <dt>Veritabanı güncellemeleri</dt>
                    <dd>
                        {health.pendingMigrations === null || health.pendingMigrations === undefined
                            ? 'Kontrol edilemedi'
                            : health.pendingMigrations.length
                              ? `${health.pendingMigrations.length} güncelleme bekliyor`
                              : 'Güncel'}
                    </dd>
                </dl>
                <p>{health.institutionAcceptance}</p>
            </section>
            {(health.backupPlans?.length ?? 0) > 0 && (
                <section className="editor-panel health-panel">
                    <h2>Yedek hedeflerinin güvenilirliği</h2>
                    <p>
                        Yerel yedeğin oluşması ile hedefe doğrulanmış teslimat ayrı izlenir. Başarısız
                        teslimatlarda aynı yerel kopya yeniden denenir.
                    </p>
                    <DataTable
                        rows={health.backupPlans ?? []}
                        columns={planColumns}
                        rowKey={(plan) => plan.id}
                        caption="Yedek planı teslimat durumu"
                    />
                </section>
            )}
            <section className="editor-panel health-panel">
                <h2>İstek ve sorgu özeti</h2>
                <p>
                    {health.measurementEnabled
                        ? `Gün: ${health.day ?? 'henüz ölçüm yok'}. Yavaş istek sınırı 500 ms.`
                        : 'İstek ölçümü kapalı.'}{' '}
                    İsimler, kayıt değerleri ve sorgu parametreleri tutulmaz.
                </p>
                <DataTable
                    rows={health.routes}
                    columns={columns}
                    rowKey={(row) => row.route}
                    caption="En uzun istek süresine göre ilk 30 route"
                />
            </section>
        </AppShell>
    );
}
