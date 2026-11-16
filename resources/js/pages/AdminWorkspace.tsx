import {
    adminResult,
    isRecord,
    type AdminProps as Props,
    type AdminRow,
    type AdminResult,
    type DefinitionItem,
    type QueryState,
} from '../lib/adminTypes';
import { Head, Link, router } from '@inertiajs/react';
import { useRef, useState, type ReactNode } from 'react';
import DatabaseTools from '../components/DatabaseTools';
import SqlConversion from '../components/SqlConversion';
import AdministrationUploads from '../components/AdministrationUploads';
import AdministrationFields from '../components/AdministrationFields';
import BackupPlanEditor from '../components/BackupPlanEditor';
import AppShell from '../components/AppShell';
import DataTable from '../components/DataTable';
import PortalDialog from '../components/PortalDialog';
import { ActiveFilters, QuickSearch } from '../components/ListToolbar';
import { writeJson } from '../lib/workflow';
import { portalUrl } from '../lib/portalUrl';
import { notify } from '../components/Toast';

const labels: Record<string, string> = {
    page_visit: 'Sayfa görüntüleme',
    opened: 'Açıldı',
    administration_backup: 'Yedekleme',
    administration_file: 'Dosya yönetimi',
    administration_system: 'Sistem bakımı',
    administration_conversion: 'Kurum aktarımı',
    administration_settings: 'Panel ayarları',
    account_requests: 'Başvuru ve şifre talepleri',
    user: 'Kullanıcılar',
    users: 'Kullanıcılar',
    person: 'Ana kayıtlar',
    person_home: 'Ana kayıtlar',
    person_note: 'Kişi notları',
    person_placement: 'Koğuş yerleşimi',
    person_photo: 'Fotoğraflar',
    staff_node: 'Personel kadrosu',
    page_access: 'Erişim yetkileri',
    page_catalog: 'Sayfalar',
    job_titles: 'Unvanlar',
    housing_blocks: 'Bloklar',
    housing_rooms: 'Koğuşlar',
    housing_beds: 'Ranzalar',
    menu_configuration: 'Menü düzenlemesi',
    modules: 'Özel sayfalar',
    module_records: 'Özel sayfa kayıtları',
    option_lists: 'Seçenek listeleri',
    case_option_lists: 'İş akışı seçenekleri',
    meal_files: 'Yemek listeleri',
    password_changed: 'Şifre değiştirildi',
    password_reset: 'Şifre sıfırlandı',
    sessions_terminated: 'Oturumlar kapatıldı',
    permission_changed: 'Yetki değiştirildi',
    profile_updated: 'Profil güncellendi',
    restored: 'Geri yüklendi',
    repaired: 'Onarıldı',
    imported: 'Aktarıldı',
    uploaded: 'Yüklendi',
    copy: 'Kopyalandı',
    move: 'Taşındı',
    delete: 'Çöp kutusuna taşındı',
    extract: 'Arşiv açıldı',
    renamed: 'Adı değiştirildi',
    published: 'Yayımlandı',
    draft_created: 'Taslak oluşturuldu',
    draft_updated: 'Taslak güncellendi',
    new: 'Yeni',
    read: 'Okundu',
    completed: 'Tamamlandı',
    rejected: 'Reddedildi',
    application: 'Üyelik başvurusu',
    password: 'Şifre talebi',
    login: 'Giriş',
    logout: 'Çıkış',
    failed: 'Başarısız giriş',
    created: 'Eklendi',
    updated: 'Güncellendi',
    archived: 'Pasife alındı',
    active_users: 'Aktif kullanıcı',
    departments: 'Birim / Politika',
    pages: 'Yönetilen modül',
    password_requests: 'Bekleyen şifre talebi',
    applications: 'Bekleyen başvuru',
    logins_today: 'Bugünkü giriş',
    actions_today: 'Bugünkü işlem',
    message: 'Durum mesajı',
    checksum: 'Doğrulama kodu',
    token: 'Kurtarma anahtarı',
    expires_at: 'Geçerlilik süresi',
    verified: 'Bütünlük doğrulaması',
    schemaMatches: 'Şema uyumu',
    affected: 'Etkilenen kayıt',
    tables: 'Tablo sayısı',
    rows: 'Kayıt sayısı',
    files: 'Dosya sayısı',
    created_at: 'Tarih',
    username: 'Kullanıcı',
    full_name: 'Adı Soyadı',
    department: 'Birim',
    resource: 'Modül',
    action: 'İşlem',
    ip: 'IP Adresi',
    status: 'Durum',
};
const tr = (value?: string) => (value ? (labels[value] ?? value) : '—');
const formatVal = (val: unknown): string =>
    val === null || val === undefined || val === ''
        ? '—'
        : typeof val === 'boolean'
          ? val
              ? 'Evet'
              : 'Hayır'
          : Array.isArray(val)
            ? val.map((v) => (typeof v === 'object' ? JSON.stringify(v) : String(v))).join(', ') || '—'
            : typeof val === 'object'
              ? Object.entries(val)
                    .map(([k, v]) => `${tr(k)}: ${v}`)
                    .join(' · ') || '—'
              : tr(String(val));
export default function AdminWorkspace(p: Props) {
    return <Workspace key={`${p.mode}:${p.revision ?? ''}:${p.path ?? ''}`} {...p} />;
}
function Workspace(p: Props) {
    const [values, setValues] = useState(p.values ?? {}),
        [selected, setSelected] = useState<AdminRow | null>(null),
        [busy, setBusy] = useState(false),
        [error, setError] = useState(''),
        [result, setResult] = useState<AdminResult | null>(null),
        [cleanup, setCleanup] = useState<string[]>([]);
    const lock = useRef(false);
    const [password, setPassword] = useState(''),
        [confirmation, setConfirmation] = useState(''),
        [resolution, setResolution] = useState('');
    const [items, setItems] = useState<DefinitionItem[]>(p.values?.items ?? []),
        [path, setPath] = useState(''),
        [text, setText] = useState(p.text ?? ''),
        [target, setTarget] = useState(''),
        [snapshot, setSnapshot] = useState('');
    async function submit(data: Record<string, unknown>, url = p.baseUrl, reload = true) {
        if (lock.current) return false;
        lock.current = true;
        setBusy(true);
        setError('');
        try {
            const r = await writeJson(
                url,
                p.mode === 'recovery' ? { ...data, scope: p.scope } : data,
                'POST',
            );
            if (!r.ok) {
                setError(
                    Object.values(r.data.errors ?? {})
                        .flat()
                        .join(' ') ||
                        r.data.message ||
                        'İşlem tamamlanamadı.',
                );
                return false;
            }
            setResult(adminResult(r.data));
            if (r.data.redirect) {
                window.location.assign(r.data.redirect);
                return true;
            }
            notify('İşlem tamamlandı.');
            if (reload) {
                setSelected(null);
                router.reload();
            }
            return true;
        } catch (e) {
            setError(e instanceof Error ? e.message : 'İşlem tamamlanamadı. Girdiler korunuyor.');
            return false;
        } finally {
            lock.current = false;
            setBusy(false);
        }
    }
    const visit = (patch: QueryState) =>
        router.get(p.baseUrl, { ...p.queryState, ...patch }, { preserveScroll: true });
    const chips = Object.entries(p.queryState ?? {})
        .filter(([k, v]) => v !== '' && v != null && !['page', 'tab', 'table', 'per_page'].includes(k))
        .map(([k, v]) => ({
            key: k,
            label: `${k === 'q' ? 'Arama' : k === 'date' ? 'Tarih' : k === 'status' ? 'Durum' : k === 'kind' ? 'Tür' : k}: ${tr(String(v))}`,
            onRemove: () => visit({ [k]: '' }),
        }));
    const editRequest = (r: AdminRow) => {
        setSelected(r);
        setPassword('');
        setConfirmation('');
        setResolution(r.resolution ?? '');
        setError('');
    };
    function table<Row extends AdminRow>(
        rows: Row[],
        cols: [string, string][],
        onClick?: (row: Row) => void,
    ) {
        return (
            <DataTable
                rows={rows.map((row, index) => ({ ...row, _rowKey: index }))}
                columns={cols.map(([key, label]) => ({
                    key,
                    label,
                    value: (r: Row) =>
                        typeof r[key] === 'boolean' ? (r[key] ? 'Evet' : 'Hayır') : String(r[key] ?? '—'),
                }))}
                rowKey={(r: Row & { _rowKey: number }) => r.id ?? r.name ?? r.path ?? r._rowKey}
                onRowClick={onClick}
                rowDisabled={() => busy}
                caption={p.title}
            />
        );
    }
    function renderStructuredData(data: unknown) {
        if (!isRecord(data)) return <p>{formatVal(data)}</p>;
        const entries = Object.entries(data).filter(
            ([k]) => !['rows', '_rowKey', 'id', 'details'].includes(k),
        );
        const nestedDetails = isRecord(data.details) ? Object.entries(data.details) : [];
        return (
            <>
                {(entries.length > 0 || nestedDetails.length > 0) && (
                    <dl className="admin-status">
                        {[...entries, ...nestedDetails].map(([k, v]) => (
                            <div key={k}>
                                <dt>{tr(k)}</dt>
                                <dd>{formatVal(v)}</dd>
                            </div>
                        ))}
                    </dl>
                )}
                {Array.isArray(data.rows) &&
                    data.rows.length > 0 &&
                    table(
                        data.rows.filter(isRecord),
                        Object.keys(data.rows[0] ?? {}).map((k) => [k, k]),
                    )}
                <details className="admin-raw-json">
                    <summary>Teknik veri (JSON)</summary>
                    <pre>{JSON.stringify(data, null, 2)}</pre>
                </details>
            </>
        );
    }
    function pager() {
        const n = p.pagination;
        return (
            n && (
                <div className="row-actions">
                    <button
                        className="button"
                        disabled={busy || n.page === 1}
                        onClick={() => visit({ page: n.page - 1 })}
                    >
                        Önceki
                    </button>
                    <span>
                        {n.page} / {n.pages} · {n.total} kayıt
                    </span>
                    <button
                        className="button"
                        disabled={busy || n.page === n.pages}
                        onClick={() => visit({ page: n.page + 1 })}
                    >
                        Sonraki
                    </button>
                </div>
            )
        );
    }
    const field = (label: string, key: string, type = 'text') => (
        <label className="form-field" key={key}>
            <span>{label}</span>
            <input
                type={type}
                maxLength={key === 'login_description' ? 1000 : 200}
                value={String(values[key] ?? '')}
                onChange={(e) => setValues({ ...values, [key]: e.target.value })}
            />
        </label>
    );
    function move(index: number, delta: number) {
        setItems((current) => {
            const next = [...current];
            [next[index], next[index + delta]] = [next[index + delta], next[index]];
            return next.map((item, position) => ({ ...item, position }));
        });
    }
    const saveAction = ['boards', 'fields'].includes(p.mode) && (
        <button
            disabled={busy}
            className="cte-primary-action"
            onClick={() =>
                void submit({
                    revision: p.revision,
                    ...(p.mode === 'appearance'
                        ? { values }
                        : {
                              items: items.map(({ position, ...item }) =>
                                  p.mode === 'boards' ? item : { ...item, position },
                              ),
                          }),
                })
            }
        >
            Değişiklikleri kaydet
        </button>
    );
    let content: ReactNode = null;
    if (p.mode === 'overview')
        content = (
            <>
                <header className="admin-hero">
                    <div>
                        <h1>
                            <i className="fas fa-chart-line" aria-hidden="true" /> Genel Bakış
                        </h1>
                        <p>Günlük yönetim işleri, bekleyen talepler ve sistem hareketleri.</p>
                    </div>
                    <div className="admin-summary">
                        <span className="admin-pill">
                            <i className="fas fa-calendar-day" aria-hidden="true" />{' '}
                            {p.overviewContext?.date ?? new Intl.DateTimeFormat('tr-TR').format(new Date())}
                        </span>
                        <span className="admin-pill">
                            <i className="fas fa-user-shield" aria-hidden="true" />{' '}
                            {p.overviewContext?.name ?? 'Sistem Yöneticisi'}
                        </span>
                    </div>
                </header>
                <div className="admin-metrics">
                    {Object.entries(p.summary)
                        .filter(([key]) => key !== 'applications')
                        .map(([key, value]) => (
                            <Link
                                href={portalUrl(
                                    key === 'password_requests' || key === 'applications'
                                        ? '/yonetim/talepler'
                                        : key === 'logins_today'
                                          ? '/yonetim/girisler'
                                          : key === 'actions_today'
                                            ? '/yonetim/islem-kayitlari'
                                            : key === 'departments'
                                              ? '/yonetim/birimler'
                                              : key === 'pages'
                                                ? '/yonetim/erisim-politikalari'
                                                : '/yonetim/kullanicilar',
                                )}
                                className="admin-metric"
                                key={key}
                            >
                                <i
                                    className={
                                        'admin-metric-icon fas fa-' +
                                        ({
                                            active_users: 'users',
                                            departments: 'layer-group',
                                            pages: 'sliders',
                                            password_requests: 'key',
                                            logins_today: 'right-to-bracket',
                                            actions_today: 'clipboard-list',
                                        }[key] ?? 'chart-line')
                                    }
                                    aria-hidden="true"
                                />
                                <div>
                                    <strong>{String(value)}</strong>
                                    <span>{tr(key)}</span>
                                </div>
                            </Link>
                        ))}
                </div>
                <div className="admin-overview-columns">
                    <section className="editor-panel">
                        <h2>
                            <i className="fas fa-bolt" aria-hidden="true" /> Hızlı İşlemler
                        </h2>
                        <div className="admin-quick-links">
                            {[
                                ['/yonetim/kullanicilar', 'Kullanıcı ekle'],
                                ['/yonetim/erisim-politikalari', 'Erişim politikaları'],
                                ['/yonetim/moduller', 'Modül yönetimi'],
                                ['/yonetim/veritabani?tab=backups', 'Yedekler'],
                            ].map(([url, label]) => (
                                <Link className="button" href={portalUrl(url)} key={url}>
                                    {label}
                                </Link>
                            ))}
                        </div>
                        <div className={'admin-system-note ' + (p.lastBackup ? 'is-ok' : 'is-warning')}>
                            <i
                                className={
                                    'fas fa-' + (p.lastBackup ? 'circle-check' : 'triangle-exclamation')
                                }
                                aria-hidden="true"
                            />
                            <div>
                                <strong>Son kayıtlı yedek</strong>
                                <small>
                                    {p.lastBackup
                                        ? `${p.lastBackup.name} · ${p.lastBackup.created_at}`
                                        : 'Kayıtlı yedek bulunamadı.'}
                                </small>
                            </div>
                        </div>
                    </section>
                    <section className="editor-panel">
                        <h2>
                            <i className="fas fa-clock-rotate-left" aria-hidden="true" /> Son İşlemler
                            <Link
                                className="button admin-heading-link"
                                href={portalUrl('/yonetim/islem-kayitlari')}
                            >
                                Tümünü gör
                            </Link>
                        </h2>
                        <div className="admin-activity-list">
                            {p.recent.map((row) => (
                                <button
                                    type="button"
                                    className="admin-activity-item"
                                    key={row.id}
                                    onClick={() => setSelected(row)}
                                >
                                    <span>
                                        <strong>
                                            {row.full_name || row.username || 'Sistem'} · {tr(row.action)}
                                        </strong>
                                        <small>{tr(row.resource)}</small>
                                    </span>
                                    <time>{row.created_at}</time>
                                </button>
                            ))}
                            {!p.recent.length && <p className="muted">Henüz işlem kaydı yok.</p>}
                        </div>
                    </section>
                </div>
                <details className="admin-additional-tools">
                    <summary>Diğer yönetim araçları</summary>
                    <div className="admin-quick-links">
                        {[
                            ['/yonetim/talepler', 'Başvurular ve Şifre Talepleri'],
                            ['/yonetim/alanlar', 'Sayfa Alanları'],
                            ['/yonetim/yerlesim', 'Blok – Koğuş – Ranza'],
                            ['/yonetim/durumlar', 'Durum Seçenekleri'],
                            ['/yonetim/is-akisi-secenekleri', 'İş Akışı Seçenekleri'],
                            ['/yonetim/secenek-listeleri', 'Seçenek Listeleri'],
                            ['/yonetim/moduller', 'Özel Sayfa ve Tablolar'],
                            ['/yonetim/menu', 'Menü Düzenlemesi'],
                        ].map(([path, label]) => (
                            <Link key={path} className="button" href={portalUrl(path)}>
                                {label}
                            </Link>
                        ))}
                    </div>
                </details>
            </>
        );
    if (p.mode === 'appearance')
        content = (
            <form
                className="admin-appearance-form"
                onSubmit={(e) => {
                    e.preventDefault();
                    void submit({ revision: p.revision, values });
                }}
            >
                <section className="editor-panel">
                    <h2>
                        <i className="fas fa-right-to-bracket" aria-hidden="true" /> Giriş Ekranı Metinleri
                    </h2>
                    <div className="user-fields">
                        {field('Üst Rozet', 'login_badge')}
                        {field('Başlık 1', 'login_title')}
                        {field('Başlık 2', 'login_accent')}
                    </div>
                    {field('Açıklama', 'login_description')}
                    <label className="form-field">
                        <span>Özellik Listesi (her madde | ile ayrılır)</span>
                        <input
                            value={(values.login_features ?? []).join(' | ')}
                            onChange={(e) =>
                                setValues({
                                    ...values,
                                    login_features: e.target.value
                                        .split('|')
                                        .map((s) => s.trim())
                                        .filter(Boolean),
                                })
                            }
                        />
                    </label>
                </section>
                <section className="editor-panel">
                    <h2>
                        <i className="fas fa-shoe-prints" aria-hidden="true" /> Footer Metinleri (standart)
                    </h2>
                    <div className="user-fields">
                        {field('Sol', 'footer_left')}
                        {field('Orta', 'footer_center')}
                        {field('Sürüm', 'footer_version')}
                        {field('İletişim (e-posta)', 'footer_contact', 'email')}
                    </div>
                </section>
                <button className="button button--primary" disabled={busy}>
                    <i className="fas fa-save" aria-hidden="true" /> Tüm Metinleri Kaydet
                </button>
            </form>
        );
    if (p.mode === 'boards')
        content = (
            <section className="editor-panel">
                <h2>Kurul ve komisyon başlıkları</h2>
                <p>
                    Başlık sırası kurul tablosuna ve çıktısına yansır. Pasife alınan başlığın üye verileri
                    korunur.
                </p>
                <div className="admin-definition-list">
                    {items.map((item, index) => (
                        <fieldset key={item.code} disabled={busy}>
                            <legend>{index + 1}. başlık</legend>
                            <div className="user-fields">
                                <label className="form-field">
                                    <span>Kod</span>
                                    <input value={item.code} readOnly />
                                </label>
                                <label className="form-field">
                                    <span>Başlık</span>
                                    <input
                                        required
                                        maxLength={150}
                                        value={item.label}
                                        onChange={(e) =>
                                            setItems(
                                                items.map((x, i) =>
                                                    i === index ? { ...x, label: e.target.value } : x,
                                                ),
                                            )
                                        }
                                    />
                                </label>
                            </div>
                            <label>
                                <input
                                    type="checkbox"
                                    checked={item.is_active}
                                    onChange={(e) =>
                                        setItems(
                                            items.map((x, i) =>
                                                i === index ? { ...x, is_active: e.target.checked } : x,
                                            ),
                                        )
                                    }
                                />{' '}
                                Etkin
                            </label>
                            <div className="row-actions">
                                <button
                                    className="button"
                                    disabled={index === 0}
                                    onClick={() => move(index, -1)}
                                >
                                    Yukarı
                                </button>
                                <button
                                    className="button"
                                    disabled={index === items.length - 1}
                                    onClick={() => move(index, 1)}
                                >
                                    Aşağı
                                </button>
                            </div>
                        </fieldset>
                    ))}
                </div>
                <button
                    className="button"
                    disabled={busy || items.length >= 40}
                    onClick={() => {
                        setSelected({ code: '', label: '', is_active: true });
                    }}
                >
                    Başlık ekle
                </button>
                <Link className="button" href={portalUrl('/kurum/kurul')}>
                    Kurul tablosunu aç
                </Link>
            </section>
        );
    if (p.mode === 'audit')
        content = (
            <>
                <div className="admin-filters">
                    <label>
                        Tarih{' '}
                        <input
                            type="date"
                            value={p.queryState.date ?? ''}
                            onChange={(e) => visit({ date: e.target.value, page: 1 })}
                        />
                    </label>
                    {(['resource', 'action'] as const).map((k) => (
                        <label key={k}>
                            {k === 'resource' ? 'Modül' : 'İşlem'}{' '}
                            <select
                                value={p.queryState[k] ?? ''}
                                onChange={(e) => visit({ [k]: e.target.value, page: 1 })}
                            >
                                <option value="">Tümü</option>
                                {p[k === 'resource' ? 'resources' : 'actions'].map((v: string) => (
                                    <option key={v} value={v}>
                                        {tr(v)}
                                    </option>
                                ))}
                            </select>
                        </label>
                    ))}
                </div>
                {table(
                    p.rows.map((r) => ({ ...r, resource: tr(r.resource), action: tr(r.action) })),
                    [
                        ['created_at', 'Tarih'],
                        ['username', 'Kullanıcı'],
                        ['full_name', 'Adı Soyadı'],
                        ['department', 'Birim'],
                        ['resource', 'Modül'],
                        ['action', 'İşlem'],
                        ['ip', 'IP'],
                    ],
                    setSelected,
                )}
                {pager()}
            </>
        );
    if (p.mode === 'logins')
        content = (
            <>
                <div className="admin-filters">
                    <label>
                        Tarih{' '}
                        <input
                            type="date"
                            value={p.queryState.date ?? ''}
                            onChange={(e) => visit({ date: e.target.value, page: 1 })}
                        />
                    </label>
                </div>
                <section className="editor-panel">
                    <h2>Kullanıcı giriş özeti</h2>
                    {!p.sessionMeasurement && (
                        <p>
                            Oturumlar dosya sürücüsünde tutulduğu için çevrimiçi durumu ölçülmüyor. Giriş
                            sayıları ve son giriş zamanı gerçek kayıtlardan alınır.
                        </p>
                    )}
                    {table(
                        p.rows.map((r) => ({
                            ...r,
                            online: r.online == null ? 'Ölçülmüyor' : r.online ? 'Çevrimiçi' : 'Çevrimdışı',
                        })),
                        [
                            ['username', 'Kullanıcı'],
                            ['full_name', 'Adı Soyadı'],
                            ['department', 'Birim'],
                            ['last_login_at', 'Son Giriş'],
                            ['last_activity_at', 'Son Aktivite'],
                            ['today_count', 'Seçili gün giriş sayısı'],
                            ['online', 'Durum'],
                        ],
                        setSelected,
                    )}
                </section>
                <section className="editor-panel">
                    <h2>Giriş / çıkış kayıtları</h2>
                    {p.queryState.user_id && (
                        <button className="button" onClick={() => visit({ user_id: '', page: 1 })}>
                            Tüm kullanıcıları göster
                        </button>
                    )}
                    {table(
                        p.events.map((r) => ({ ...r, outcome: tr(r.outcome) })),
                        [
                            ['created_at', 'Tarih'],
                            ['username', 'Kullanıcı'],
                            ['full_name', 'Adı Soyadı'],
                            ['outcome', 'İşlem'],
                            ['ip_address', 'IP'],
                        ],
                    )}
                    {pager()}
                </section>
            </>
        );
    if (p.mode === 'requests')
        content = (
            <>
                <div className="admin-filters">
                    {(['kind', 'status'] as const).map((k) => (
                        <label key={k}>
                            {k === 'kind' ? 'Talep türü' : 'Durum'}{' '}
                            <select
                                value={p.queryState[k] ?? ''}
                                onChange={(e) => visit({ [k]: e.target.value })}
                            >
                                <option value="">Tümü</option>
                                {(k === 'kind'
                                    ? ['application', 'password']
                                    : ['new', 'read', 'completed', 'rejected']
                                ).map((v) => (
                                    <option value={v} key={v}>
                                        {tr(v)}
                                    </option>
                                ))}
                            </select>
                        </label>
                    ))}
                    <span>
                        Bekleyen başvuru: {p.pending.application ?? 0} · Şifre talebi:{' '}
                        {p.pending.password ?? 0}
                    </span>
                </div>
                {table(
                    p.requests.map((r) => ({ ...r, kindLabel: tr(r.kind), statusLabel: tr(r.status) })),
                    [
                        ['created_at', 'Tarih'],
                        ['kindLabel', 'Talep'],
                        ['username', 'Kullanıcı'],
                        ['full_name', 'Adı Soyadı'],
                        ['department', 'Birim'],
                        ['statusLabel', 'Durum'],
                    ],
                    editRequest,
                )}
                {pager()}
            </>
        );
    if (p.mode === 'fields')
        content = (
            <AdministrationFields
                defaults={p.defaults}
                groups={p.groups}
                sources={p.sources}
                optionLists={p.optionLists}
                items={items}
                setItems={setItems}
                busy={busy}
            />
        );
    if (p.mode === 'database') {
        const tab = p.queryState.tab ?? 'tables';
        content = (
            <>
                <section className="admin-database-target" aria-label="Hedef veritabanı">
                    <i className="fas fa-database" aria-hidden="true" />
                    <div>
                        <small>Müdahale Edilecek Hedef Veritabanı</small>
                        <strong>{p.database}</strong>
                    </div>
                    <label>
                        Veritabanını Değiştir:
                        <select
                            value={p.database}
                            disabled={busy}
                            onChange={(e) => visit({ database: e.target.value, table: '', page: 1 })}
                        >
                            {p.databases.map((name) => (
                                <option key={name}>{name}</option>
                            ))}
                        </select>
                    </label>
                </section>
                <nav className="admin-tabs admin-database-tabs" aria-label="Veritabanı sekmeleri">
                    {[
                        ['health', 'Sistem Sağlığı'],
                        ['tables', 'Tablolar & Veri'],
                        ['backups', 'Yedekleme & Planlar'],
                        ['repair', 'Teşhis & Şema Onarımı'],
                        ['conversion', 'Eski SQL Dönüşümü'],
                    ].map(([key, label]) => (
                        <button
                            className={tab === key ? 'button button--primary' : 'button'}
                            key={key}
                            type="button"
                            aria-current={tab === key ? 'page' : undefined}
                            onClick={() => visit({ tab: key, table: '', page: 1 })}
                        >
                            {label}
                        </button>
                    ))}
                </nav>
                {tab === 'health' && (
                    <section className="editor-panel">
                        <h2>Sistem sağlığı</h2>
                        <dl className="admin-status">
                            {[
                                ['PHP', p.health.php],
                                ['Veritabanı', p.health.database ? 'Bağlı' : 'Bağlantı yok'],
                                ['Kurulum', p.health.installed ? 'Tamamlandı' : 'Eksik'],
                                ['Arayüz', p.health.assets ? 'Hazır' : 'Eksik'],
                                ['Bakım modu', p.health.maintenance ? 'Açık' : 'Kapalı'],
                                ['Hata ayrıntıları', p.health.debugDisabled ? 'Kapalı' : 'Açık'],
                            ].map(([label, value]) => (
                                <div key={String(label)}>
                                    <dt>{label}</dt>
                                    <dd>{value}</dd>
                                </div>
                            ))}
                        </dl>
                        <Link className="button" href={portalUrl('/yonetim/saglik')}>
                            Performans ve ayrıntılı sağlık
                        </Link>
                    </section>
                )}
                {tab === 'tables' && (
                    <section className="editor-panel">
                        <h2>Veritabanı tabloları</h2>
                        <DatabaseTools
                            database={p.database}
                            databases={p.databases}
                            showDatabaseSelector={false}
                            busy={busy}
                            submit={submit}
                            visit={visit}
                        />
                        <a className="button" href={portalUrl('/yonetim/sql-indir') + '?kind=data'}>
                            SQL yedeği indir
                        </a>
                        {table(
                            p.tables,
                            [
                                ['name', 'Tablo'],
                                ['columns', 'Sütun'],
                                ['count', 'Kayıt'],
                            ],
                            (r) => visit({ table: r.name ?? '', page: 1 }),
                        )}
                        {p.queryState.table && (
                            <>
                                <h3>{p.queryState.table}</h3>
                                <p>{p.columns.map((c) => `${c.name} (${c.type_name})`).join(' · ')}</p>
                                <p>
                                    Kimlik, içerik ve güvenlik alanları tablo tarayıcısında gösterilmez. Kayıt
                                    düzenleme için ilgili sayfayı kullanın.
                                </p>
                                {table(
                                    p.rows,
                                    Object.keys(p.rows[0] ?? {}).map((key) => [key, key]),
                                    undefined,
                                )}
                                {pager()}
                            </>
                        )}
                        <Link href={portalUrl('/yonetim/moduller')} className="button">
                            Tablo ve sütunları sayfa tasarımcısında yönet
                        </Link>
                    </section>
                )}
                {tab === 'backups' && (
                    <section className="editor-panel">
                        <h2>Yedekler</h2>
                        <p>
                            Panel yedeği şifreli veritabanı görüntüsü ve özel dosyaları içerir. Açılması için
                            bu kurulumun APP_KEY değeri korunmalıdır. Tam uygulama yedeği portal:backup
                            komutuyla alınır.
                        </p>
                        <button
                            className="button button--primary"
                            disabled={busy}
                            onClick={() => void submit({ action: 'backup' })}
                        >
                            Şimdi yedek oluştur
                        </button>
                        {table(
                            p.backups.map((r) => ({
                                ...r,
                                bytes: `${(Number(r.bytes) / 1024 / 1024).toFixed(2)} MB`,
                            })),
                            [
                                ['created_at', 'Tarih'],
                                ['name', 'Yedek'],
                                ['bytes', 'Boyut'],
                            ],
                            (r) => {
                                setSelected(r);
                                setResult(null);
                                setConfirmation('');
                                setError('');
                            },
                        )}
                        <BackupPlanEditor
                            key={p.plans.revision}
                            state={p.plans}
                            busy={busy}
                            submit={submit}
                        />
                    </section>
                )}
                {tab === 'repair' && (
                    <section className="editor-panel">
                        <h2>Şema bakımı ve onarım</h2>
                        {(result?.unused_columns?.length ?? 0) > 0 && (
                            <fieldset>
                                <legend>Boş artık kolonları temizle</legend>
                                {result?.unused_columns?.map((column: string) => (
                                    <label key={column}>
                                        <input
                                            type="checkbox"
                                            checked={cleanup.includes(column)}
                                            onChange={(e) =>
                                                setCleanup(
                                                    e.target.checked
                                                        ? [...cleanup, column]
                                                        : cleanup.filter((c) => c !== column),
                                                )
                                            }
                                        />
                                        {column}
                                    </label>
                                ))}
                                <p>Seçilen kolonlar yalnız boşsa şema eşitleme sırasında kaldırılır.</p>
                            </fieldset>
                        )}
                        <button
                            className="button"
                            disabled={busy}
                            onClick={() => void submit({ action: 'diagnose' }, p.baseUrl, false)}
                        >
                            Şemayı teşhis et
                        </button>
                        <button
                            className="button"
                            disabled={busy}
                            onClick={() => {
                                if (
                                    window.confirm(
                                        'Ön yedek alınıp kolon, motor ve karakter seti eşitlensin mi?',
                                    )
                                )
                                    void submit(
                                        { action: 'schema-repair', confirmed: true, cleanup },
                                        p.baseUrl,
                                        false,
                                    );
                            }}
                        >
                            Yedekle ve şemayı eşitle
                        </button>
                        <button
                            className="button"
                            disabled={busy}
                            onClick={() => void submit({ action: 'converter-update' }, p.baseUrl, false)}
                        >
                            Dönüştürücü şemasını güncelle
                        </button>
                        <p>
                            Önce yedek oluşturulur, ardından eksik Laravel migration işlemleri uygulanır.
                            Mevcut tablolar silinmez.
                        </p>
                        <button
                            className="button"
                            disabled={busy}
                            onClick={() => {
                                if (window.confirm('Ön yedek alınıp eksik şema işlemleri uygulansın mı?'))
                                    void submit({ action: 'repair', confirmed: true }, p.baseUrl, false);
                            }}
                        >
                            Yedekle ve onar
                        </button>
                        <details>
                            <summary>Verileri sıfırla / temiz kurulum</summary>
                            <p>
                                VERİLERİ SIFIRLA yazın. Ön yedek alınır; temiz kurulumda yönetici hesabınız
                                korunur.
                            </p>
                            <input
                                aria-label="Sıfırlama onayı"
                                value={confirmation}
                                onChange={(e) => setConfirmation(e.target.value)}
                            />
                            {[
                                ['records', 'Operasyonel verileri sıfırla'],
                                ['all', 'Temiz kurulum yap'],
                            ].map(([kind, label]) => (
                                <button
                                    className="button button--danger"
                                    key={kind}
                                    disabled={busy || confirmation !== 'VERİLERİ SIFIRLA'}
                                    onClick={() =>
                                        void submit(
                                            { action: 'reset', kind, confirmed: true, confirmation },
                                            p.baseUrl,
                                            false,
                                        )
                                    }
                                >
                                    {label}
                                </button>
                            ))}
                        </details>
                    </section>
                )}
                {tab === 'conversion' && <SqlConversion busy={busy} baseUrl={p.baseUrl} />}
                {tab === 'conversion' && (
                    <section className="editor-panel">
                        <h2>Eski kurum yapısını aktar</h2>
                        <p>
                            Eski sistemin format_version: 1 kurum yapısı JSON dosyasını seçin. Birimler,
                            unvanlar ve kullanıcılar doğrulanır; değişmiş veya eşlemesiz mevcut kayıtların
                            üzerine yazılmaz.
                        </p>
                        <input
                            aria-label="Aktarım JSON dosyası"
                            type="file"
                            accept=".json"
                            onChange={async (e) => {
                                const f = e.target.files?.[0];
                                if (f && f.size <= 20 * 1024 * 1024) {
                                    setSnapshot(await f.text());
                                    setResult(null);
                                } else setError('En fazla 20 MB JSON dosyası seçin.');
                            }}
                        />
                        <textarea
                            aria-label="Aktarım JSON içeriği"
                            rows={8}
                            value={snapshot}
                            onChange={(e) => {
                                setSnapshot(e.target.value);
                                setResult(null);
                            }}
                        />
                        <button
                            className="button"
                            disabled={busy || !snapshot}
                            onClick={() => {
                                try {
                                    void submit(
                                        { action: 'conversion-preview', snapshot: JSON.parse(snapshot) },
                                        p.baseUrl,
                                        false,
                                    );
                                } catch {
                                    setError('Geçerli JSON girin.');
                                }
                            }}
                        >
                            Doğrula ve önizle
                        </button>
                        {result?.checksum && (
                            <button
                                className="button button--primary"
                                disabled={busy}
                                onClick={() => {
                                    if (
                                        window.confirm(
                                            'Ön yedek alınıp doğrulanan kurum yapısı aktarılsın mı?',
                                        )
                                    )
                                        void submit(
                                            {
                                                action: 'conversion',
                                                confirmed: true,
                                                checksum: result.checksum,
                                                snapshot: JSON.parse(snapshot),
                                            },
                                            p.baseUrl,
                                            false,
                                        );
                                }}
                            >
                                Yedekle ve aktar
                            </button>
                        )}
                    </section>
                )}
            </>
        );
    }
    if (p.mode === 'recovery')
        content = (
            <section className="editor-panel">
                <h2>
                    <i className="fas fa-toolbox" aria-hidden="true" /> Acil Kurtarma &amp; Bağımsız Dosya
                    Yöneticisi
                </h2>
                <p>
                    Kurtarma aracı güvenlik ve oturum sınırlarını korumak için bağımsız tam sayfa olarak
                    açılır.
                </p>
                <div className="admin-recovery-launch">
                    <i className="fas fa-shield-halved" aria-hidden="true" />
                    <h3>Acil Kurtarma &amp; Dosya Yöneticisi</h3>
                    <p>
                        Sunucu dosyalarını yönetmek, SQL dosyası içe aktarmak veya veritabanını onarmak için
                        aracı tam sayfa açın.
                    </p>
                    <a
                        className="button button--primary"
                        href={portalUrl('/recovery.php')}
                        target="_blank"
                        rel="noopener noreferrer"
                    >
                        <i className="fas fa-arrow-up-right-from-square" aria-hidden="true" /> Kurtarma
                        aracını aç
                    </a>
                </div>
                <div className="admin-tabs">
                    <Link href={p.baseUrl}>Belgeler</Link>
                    <Link href={p.baseUrl + '?scope=application'}>Uygulama dosyaları</Link>
                    <button
                        className="button"
                        disabled={busy}
                        onClick={() =>
                            void submit({ action: 'recovery-token' }, portalUrl('/yonetim/veritabani'), false)
                        }
                    >
                        Kurtarma anahtarı oluştur
                    </button>
                    <a href={portalUrl('/recovery.php')} target="_blank" rel="noopener noreferrer">
                        Bağımsız kurtarma
                    </a>
                </div>
                <div className="admin-quick-links">
                    <Link className="button" href={portalUrl('/yonetim/veritabani?tab=backups')}>
                        Yedekleme ve geri yükleme
                    </Link>
                    <Link className="button" href={portalUrl('/yonetim/veritabani?tab=repair')}>
                        Şema onarımı
                    </Link>
                    <Link className="button" href={portalUrl('/yonetim/kullanicilar')}>
                        Hesap ve oturum kurtarma
                    </Link>
                </div>
                <p>
                    Belgeler ve kurtarma dosyaları burada yönetilir. Silinen dosyalar çöp kutusuna, düzenleme
                    öncesi kopyaları sürüm klasörüne alınır.
                </p>
                <nav className="admin-breadcrumb">
                    <Link href={p.baseUrl + '?scope=' + p.scope}>Dosya kökü</Link>
                    {String(p.path ?? '')
                        .split('/')
                        .filter(Boolean)
                        .map((name: string, index: number) => (
                            <Link
                                key={index}
                                href={`${p.baseUrl}?scope=${p.scope}&path=${encodeURIComponent(
                                    p.path
                                        .split('/')
                                        .slice(0, index + 1)
                                        .join('/'),
                                )}`}
                            >
                                {name}
                            </Link>
                        ))}
                </nav>
                {p.isFile ? (
                    <>
                        {p.editable !== false ? (
                            <>
                                <textarea
                                    aria-label="Dosya içeriği"
                                    rows={20}
                                    value={text}
                                    onChange={(e) => setText(e.target.value)}
                                    disabled={busy}
                                />
                                <button
                                    className="button button--primary"
                                    disabled={busy}
                                    onClick={() =>
                                        void submit({
                                            action: 'save',
                                            path: p.path,
                                            text,
                                            checksum: p.checksum,
                                        })
                                    }
                                >
                                    Dosyayı kaydet
                                </button>
                            </>
                        ) : (
                            <p>Bu dosya metin düzenleyicisinde açılmaz. Boyut: {p.bytes} bayt.</p>
                        )}
                        <a
                            className="button"
                            href={
                                portalUrl('/yonetim/dosya-indir') +
                                '?scope=' +
                                p.scope +
                                '&path=' +
                                encodeURIComponent(p.path)
                            }
                        >
                            İndir
                        </a>
                        {p.versions.length > 0 && (
                            <>
                                <h3>Önceki dosya sürümleri</h3>
                                {p.versions.map((version) => (
                                    <p key={version.id}>
                                        {version.at}{' '}
                                        <button
                                            className="button"
                                            disabled={busy}
                                            onClick={() => {
                                                if (
                                                    window.confirm(
                                                        'Bu sürüme dönülsün mü? Mevcut dosya da sürüm geçmişine alınır.',
                                                    )
                                                )
                                                    void submit({
                                                        action: 'version',
                                                        path: p.path,
                                                        checksum: p.checksum,
                                                        version_id: version.id,
                                                    });
                                            }}
                                        >
                                            Bu sürüme dön
                                        </button>
                                    </p>
                                ))}
                            </>
                        )}
                        <label className="form-field">
                            <span>Kopyala / taşı hedefi (dosya köküne göre)</span>
                            <input value={target} onChange={(e) => setTarget(e.target.value)} />
                        </label>
                        {p.path.endsWith('.zip') && (
                            <button
                                className="button"
                                disabled={busy || !target}
                                onClick={() => void submit({ action: 'extract', path: p.path, target })}
                            >
                                Arşivi hedef klasöre aç
                            </button>
                        )}
                        {['copy', 'move'].map((action) => (
                            <button
                                className="button"
                                key={action}
                                disabled={busy || !target}
                                onClick={() => void submit({ action, path: p.path, target })}
                            >
                                {action === 'copy' ? 'Kopyala' : 'Taşı'}
                            </button>
                        ))}
                    </>
                ) : (
                    <>
                        {table(
                            p.entries.map((r) => ({ ...r, type: r.directory ? 'Klasör' : 'Dosya' })),
                            [
                                ['name', 'Ad'],
                                ['type', 'Tür'],
                                ['bytes', 'Bayt'],
                                ['modified', 'Son değişiklik'],
                            ],
                            (r) => router.get(p.baseUrl, { path: r.path, scope: p.scope }),
                        )}
                        <div className="admin-filters">
                            <label>
                                Yeni ad <input value={path} onChange={(e) => setPath(e.target.value)} />
                            </label>
                            <button
                                className="button"
                                disabled={busy || !path}
                                onClick={() =>
                                    void submit({
                                        action: 'folder',
                                        path: [p.path, path].filter(Boolean).join('/'),
                                    })
                                }
                            >
                                Klasör oluştur
                            </button>
                            <button
                                className="button"
                                disabled={busy || !path}
                                onClick={() =>
                                    void submit({
                                        action: 'save',
                                        path: [p.path, path].filter(Boolean).join('/'),
                                        text: '',
                                        checksum:
                                            'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855',
                                    })
                                }
                            >
                                Metin dosyası oluştur
                            </button>
                        </div>
                        <AdministrationUploads directory={p.path ?? ''} scope={p.scope} busy={busy} />
                    </>
                )}
                {!p.isFile && (
                    <a
                        className="button"
                        href={
                            portalUrl('/yonetim/dosya-indir') +
                            '?scope=' +
                            p.scope +
                            '&path=' +
                            encodeURIComponent(p.path ?? '')
                        }
                    >
                        Klasörü ZIP indir
                    </a>
                )}
                {p.path && !p.isFile && (
                    <div className="admin-filters">
                        <label>
                            Hedef klasör <input value={target} onChange={(e) => setTarget(e.target.value)} />
                        </label>
                        {['copy', 'move'].map((action) => (
                            <button
                                className="button"
                                key={action}
                                disabled={busy || !target}
                                onClick={() => void submit({ action, path: p.path, target })}
                            >
                                {action === 'copy' ? 'Klasörü kopyala' : 'Klasörü taşı'}
                            </button>
                        ))}
                    </div>
                )}
                {p.path && (
                    <button
                        className="button button--danger"
                        disabled={busy}
                        onClick={() => {
                            if (window.confirm('Bu öğe çöp kutusuna taşınsın mı?'))
                                void submit({ action: 'delete', path: p.path }).then((ok) => {
                                    if (ok) router.get(p.baseUrl, { scope: p.scope });
                                });
                        }}
                    >
                        Çöp kutusuna taşı
                    </button>
                )}
                {!p.isFile && (
                    <>
                        <h3>Çöp kutusu</h3>
                        {(p.trash ?? []).map((r) => (
                            <p key={r.id}>
                                {r.path} · {r.deleted_at}{' '}
                                <button
                                    className="button"
                                    disabled={busy}
                                    onClick={() =>
                                        void submit({ action: 'restore', path: r.path, trash_id: r.id })
                                    }
                                >
                                    Geri al
                                </button>
                            </p>
                        ))}
                    </>
                )}
            </section>
        );
    return (
        <AppShell
            applicationName={p.applicationName}
            navigation={p.navigation}
            pageTitle={p.title}
            managementHeader={p.mode !== 'overview'}
            toolbar={
                ['audit', 'logins', 'requests'].includes(p.mode) ? (
                    <ActiveFilters
                        chips={chips}
                        emptyMessage="Arama, tarih veya seçimlerle kayıtları daraltın."
                    />
                ) : (
                    <>{saveAction}</>
                )
            }
            search={
                ['audit', 'logins', 'requests'].includes(p.mode) ? (
                    <>
                        <QuickSearch
                            label="Kullanıcı adı veya ad soyad ile ara"
                            placeholder="Kullanıcı adı veya ad soyad..."
                            value={String(p.queryState?.q ?? '')}
                            onSearch={(q) => visit({ q, page: 1 })}
                        />
                        <strong>{p.title}</strong>
                    </>
                ) : null
            }
        >
            <Head title={p.title} />
            <div className="admin-workspace" aria-busy={busy}>
                {error && (
                    <p className="form-error" role="alert">
                        {error}
                    </p>
                )}
                {content}
                {result && ['database', 'recovery'].includes(p.mode) && (
                    <section className="editor-panel" role="status">
                        <h3>İşlem sonucu</h3>
                        {result.download && (
                            <a className="button" href={result.download}>
                                Dönüştürücü paketini indir
                            </a>
                        )}
                        {renderStructuredData(result)}
                    </section>
                )}
            </div>
            {selected && (
                <PortalDialog
                    title={
                        p.mode === 'requests'
                            ? 'Talep ayrıntısı'
                            : p.mode === 'boards'
                              ? 'Yeni kurul başlığı'
                              : p.mode === 'fields'
                                ? 'Yeni alan'
                                : p.mode === 'logins'
                                  ? 'Kullanıcı giriş ayrıntısı'
                                  : 'Kayıt ayrıntısı'
                    }
                    onClose={() => setSelected(null)}
                    busy={busy}
                >
                    {error && (
                        <p className="form-error" role="alert">
                            {error}
                        </p>
                    )}
                    {p.mode === 'requests' ? (
                        <>
                            <dl className="admin-status">
                                {[
                                    ['Kullanıcı', selected.username],
                                    ['Adı Soyadı', selected.full_name],
                                    ['Birim', selected.department],
                                    ['Unvan', selected.job_title],
                                    ['E-posta', selected.email],
                                    ['Telefon', selected.phone],
                                    ['Mesaj', selected.message],
                                    ['Durum', tr(selected.status)],
                                ].map(([k, v]) => (
                                    <div key={k}>
                                        <dt>{k}</dt>
                                        <dd>{v || '—'}</dd>
                                    </div>
                                ))}
                            </dl>
                            {
                                <>
                                    <label className="form-field">
                                        <span>Sonuç / yönetici notu</span>
                                        <textarea
                                            value={resolution}
                                            maxLength={500}
                                            onChange={(e) => setResolution(e.target.value)}
                                        />
                                    </label>
                                    <div className="user-fields">
                                        <label className="form-field">
                                            <span>Geçici şifre (en az 6 karakter)</span>
                                            <input
                                                type="password"
                                                autoComplete="new-password"
                                                value={password}
                                                minLength={6}
                                                maxLength={128}
                                                onChange={(e) => setPassword(e.target.value)}
                                            />
                                        </label>
                                        <label className="form-field">
                                            <span>Geçici şifre tekrar</span>
                                            <input
                                                type="password"
                                                autoComplete="new-password"
                                                value={confirmation}
                                                minLength={6}
                                                maxLength={128}
                                                onChange={(e) => setConfirmation(e.target.value)}
                                            />
                                        </label>
                                    </div>
                                    <div className="row-actions">
                                        {[
                                            ['new', 'Yeni işaretle'],
                                            ['read', 'Okundu işaretle'],
                                            ['completed', 'Çözüldü işaretle'],
                                            ['delete', 'Talebi sil'],
                                            ['reject', 'Talebi reddet'],
                                            [
                                                selected.kind === 'application' ? 'approve' : 'password',
                                                selected.kind === 'application'
                                                    ? 'Hesabı oluştur'
                                                    : 'Şifreyi sıfırla',
                                            ],
                                        ].map(([action, label]) => (
                                            <button
                                                className="button"
                                                disabled={
                                                    busy || (action === 'password' && !selected.user_id)
                                                }
                                                key={action}
                                                onClick={() => {
                                                    if (
                                                        action === 'delete' &&
                                                        !window.confirm('Talep kalıcı silinsin mi?')
                                                    )
                                                        return;
                                                    if (
                                                        ['approve', 'password'].includes(action) &&
                                                        !window.confirm(
                                                            'Geçici şifre kullanıcıya güvenli yoldan iletilecek. İşlem uygulansın mı?',
                                                        )
                                                    )
                                                        return;
                                                    void submit({
                                                        id: selected.id,
                                                        revision: selected.revision,
                                                        action,
                                                        resolution,
                                                        confirmed: true,
                                                        ...(['approve', 'password'].includes(action)
                                                            ? {
                                                                  password,
                                                                  password_confirmation: confirmation,
                                                                  user_revision: selected.user_revision,
                                                              }
                                                            : {}),
                                                    });
                                                }}
                                            >
                                                {label}
                                            </button>
                                        ))}
                                    </div>
                                </>
                            }
                        </>
                    ) : ['boards', 'fields'].includes(p.mode) ? (
                        <form
                            onSubmit={(e) => {
                                e.preventDefault();
                                const item = { ...selected };
                                if (p.mode === 'boards' && !item.code) {
                                    const base =
                                        (item.label ?? '')
                                            .toLocaleLowerCase('tr')
                                            .replaceAll('ı', 'i')
                                            .normalize('NFD')
                                            .replace(/[\u0300-\u036f]/g, '')
                                            .replace(/[^a-z0-9]+/g, '_')
                                            .replace(/^_+|_+$/g, '') || 'kurul';
                                    item.code = /^[a-z]/.test(base) ? base : 'kurul_' + base;
                                    let n = 1;
                                    const start = item.code;
                                    while (items.some((x) => x.code === item.code))
                                        item.code = start + '_' + ++n;
                                }
                                if (items.some((x) => (x.code ?? x.key) === (item.code ?? item.key))) {
                                    setError('Bu kod zaten kullanılıyor.');
                                    return;
                                }
                                setItems([
                                    ...items,
                                    { ...item, label: item.label ?? '', position: items.length },
                                ]);
                                setSelected(null);
                            }}
                            className="definition-form"
                        >
                            <label className="form-field">
                                <span>Kod</span>
                                <input
                                    required={p.mode === 'fields'}
                                    pattern={p.mode === 'fields' ? 'custom_[a-z0-9_]+' : '[a-z][a-z0-9_]*'}
                                    maxLength={50}
                                    value={selected.code ?? selected.key}
                                    onChange={(e) =>
                                        setSelected({
                                            ...selected,
                                            [p.mode === 'fields' ? 'key' : 'code']: e.target.value,
                                        })
                                    }
                                />
                            </label>
                            <label className="form-field">
                                <span>Başlık</span>
                                <input
                                    required
                                    maxLength={150}
                                    value={selected.label}
                                    onChange={(e) => setSelected({ ...selected, label: e.target.value })}
                                />
                            </label>
                            {p.mode === 'fields' && (
                                <label className="form-field">
                                    <span>Tür</span>
                                    <select
                                        value={selected.type}
                                        onChange={(e) => setSelected({ ...selected, type: e.target.value })}
                                    >
                                        {[
                                            ['text', 'Metin'],
                                            ['longtext', 'Uzun metin'],
                                            ['integer', 'Sayı'],
                                            ['date', 'Tarih'],
                                            ['time', 'Saat'],
                                        ].map(([key, label]) => (
                                            <option key={key} value={key}>
                                                {label}
                                            </option>
                                        ))}
                                    </select>
                                </label>
                            )}
                            <button className="button button--primary">Taslağa ekle</button>
                        </form>
                    ) : p.mode === 'logins' ? (
                        <>
                            <p>
                                {selected.full_name} · {selected.username}
                            </p>
                            <p>Son giriş: {selected.last_login_at ?? '—'}</p>
                            <div className="row-actions">
                                <button
                                    className="button"
                                    disabled={busy}
                                    onClick={() => {
                                        setSelected(null);
                                        visit({ user_id: selected.id, page: 1 });
                                    }}
                                >
                                    Giriş geçmişini göster
                                </button>
                                <button
                                    className="button button--danger"
                                    disabled={busy || selected.id === p.actorId}
                                    onClick={() => {
                                        if (window.confirm('Bu kullanıcının bütün oturumları kapatılsın mı?'))
                                            void submit(
                                                { revision: selected.revision, confirmed: true },
                                                portalUrl(
                                                    `/yonetim/kullanicilar/${selected.id}/oturumlari-sonlandir`,
                                                ),
                                            );
                                    }}
                                >
                                    Oturumları kapat
                                </button>
                                <Link className="button" href={portalUrl('/yonetim/kullanicilar')}>
                                    Kullanıcılar ve erişim
                                </Link>
                            </div>
                        </>
                    ) : p.mode === 'database' ? (
                        <>
                            <p>
                                <strong>{selected.name}</strong>
                            </p>
                            {renderStructuredData(selected.summary ?? selected)}
                            <div className="row-actions">
                                <a
                                    className="button"
                                    href={portalUrl(`/yonetim/yedekler/${selected.id}/indir`)}
                                >
                                    Yedeği indir
                                </a>
                                <button
                                    className="button"
                                    disabled={busy}
                                    onClick={() =>
                                        void submit({ action: 'verify', id: selected.id }, p.baseUrl, false)
                                    }
                                >
                                    Bütünlüğünü doğrula
                                </button>
                                <button
                                    className="button button--danger"
                                    disabled={busy}
                                    onClick={() => {
                                        if (window.confirm('Bu yedek listeden kaldırılıp arşivlensin mi?'))
                                            void submit({ action: 'archive', id: selected.id });
                                    }}
                                >
                                    Arşivle
                                </button>
                            </div>
                            {result?.verified && (
                                <>
                                    <p role="status">
                                        Yedek doğrulandı. Şema: {result.schemaMatches ? 'Uyumlu' : 'Farklı'}
                                    </p>
                                    {result.schemaMatches && (
                                        <>
                                            <label className="form-field">
                                                <span>
                                                    Bu yedeği geri yüklemek için GERİ YÜKLE yazın. Mevcut
                                                    veriler önceden yedeklenir; tüm oturumlar kapanır.
                                                </span>
                                                <input
                                                    value={confirmation}
                                                    onChange={(e) => setConfirmation(e.target.value)}
                                                />
                                            </label>
                                            <button
                                                className="button button--danger"
                                                disabled={busy || confirmation !== 'GERİ YÜKLE'}
                                                onClick={() =>
                                                    void submit(
                                                        {
                                                            action: 'restore',
                                                            id: selected.id,
                                                            checksum: result.checksum,
                                                            confirmed: true,
                                                            confirmation,
                                                        },
                                                        p.baseUrl,
                                                        false,
                                                    )
                                                }
                                            >
                                                Yedekle ve geri yükle
                                            </button>
                                        </>
                                    )}
                                </>
                            )}
                        </>
                    ) : (
                        renderStructuredData(selected.details ?? selected)
                    )}
                </PortalDialog>
            )}
        </AppShell>
    );
}
