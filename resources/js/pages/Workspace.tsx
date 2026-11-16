import { formatDateTime, todayInIstanbul } from '../lib/format';
import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';
import AppShell from '../components/AppShell';
import DataTable from '../components/DataTable';
import PortalDialog from '../components/PortalDialog';
import { portalUrl } from '../lib/portalUrl';
import { useWorkspaceData, workspaceRequest } from '../lib/workspace';
import type { Column } from '../lib/table';

type Handover = {
    hours: number;
    since: string;
    generatedAt: string;
    tasks: { label: string; count: number; url: string }[];
    activity: { resource_type: string; action: string; count: number; last_at: string }[];
};
type PermissionRow = {
    id: number;
    name: string;
    code: string;
    is_active: boolean;
    read_only: boolean;
    personal_reason: string | null;
    effective: {
        giris: boolean;
        yazma: boolean;
        sources: { type: string; permission: string; page_name?: string }[];
    };
};
type Permissions = {
    users: { id: number; full_name: string; username: string }[];
    selected: {
        id: number;
        name: string;
        active: boolean;
        passwordChangeRequired: boolean;
        department: string | null;
        jobTitle: string | null;
    } | null;
    pages: PermissionRow[];
};
type QualityRow = { id: number; full_name: string | null; tc_identity: string | null };
type Quality = {
    issues: Record<string, { label: string; count: number }>;
    issue: string;
    rows: QualityRow[];
    page: number;
    pages: number;
    generatedAt: string;
};
type OriginField = {
    key: string;
    label: string;
    value: string | number | boolean | null;
    source: string;
    lastVerifiedChange: string | null;
    eventId: number | null;
};
type Origin = {
    person: { id: number; name: string };
    fields: OriginField[];
    imports: {
        source_name: string;
        source_table: string;
        source_id: number;
        import_version: number;
        updated_at: string;
    }[];
    note: string;
};
type DutyRow = { date: string; user_id: number; name: string };
type Workload = { id: number; name: string; new: number; total: number; weekends: number };
type Scenario = {
    strategy: string;
    label: string;
    rows: DutyRow[];
    skipped: string[];
    preserved: number;
    spread: number;
    workload: Workload[];
};
type Scenarios = {
    token: string;
    input: { from: string; to: string; exclude: number[] };
    candidates: { id: number; name: string }[];
    scenarios: Scenario[];
};
const time = (value: string | null) => formatDateTime(value, 'Kayıt yok');
const sourceNames: Record<string, string> = {
    system_admin: 'Sistem yöneticisi',
    homepage: 'Ana sayfa okuma kuralı',
    kisi: 'Kişisel kural',
    birim: 'Birim kuralı',
    unvan: 'Unvan kuralı',
    herkes: 'Herkes',
    misafir: 'Misafir',
};

function LoadState({ loading, error, retry }: { loading: boolean; error: string; retry: () => void }) {
    return (
        <>
            {loading && <p role="status">Yükleniyor…</p>}
            {error && (
                <div role="alert">
                    <p className="form-error">{error}</p>
                    <button type="button" className="button" onClick={retry}>
                        Tekrar dene
                    </button>
                </div>
            )}
        </>
    );
}
function PermissionPanel({ baseUrl }: { baseUrl: string }) {
    const [query, setQuery] = useState(''),
        [search, setSearch] = useState(''),
        [user, setUser] = useState('');
    const state = useWorkspaceData<Permissions>(
        `${baseUrl}/yetkiler?q=${encodeURIComponent(search)}${user ? '&user=' + user : ''}`,
    );
    const columns: Column<PermissionRow>[] = [
        { key: 'name', label: 'Sayfa', value: (row) => row.name },
        { key: 'is_active', label: 'Etkin', value: (row) => row.is_active },
        { key: 'read', label: 'Okuma', value: (row) => row.effective.giris },
        { key: 'write', label: 'Yazma', value: (row) => row.effective.yazma },
        {
            key: 'sources',
            label: 'Erişimin nedeni',
            value: (row) =>
                row.effective.sources
                    .map(
                        (source) =>
                            `${sourceNames[source.type] ?? source.type}${source.page_name ? ' · ' + source.page_name : ''}`,
                    )
                    .join(', ') || 'Uygun erişim kuralı yok',
        },
        { key: 'personal_reason', label: 'Kişisel kural gerekçesi', value: (row) => row.personal_reason },
    ];
    return (
        <section className="editor-panel">
            <h2>Yetki simülatörü</h2>
            <p>
                Seçilen hesabın mevcut birim, unvan ve kişisel kurallarıyla erişimini açıklar. Hesap veya
                oturum değiştirilmez.
            </p>
            <form
                onSubmit={(event) => {
                    event.preventDefault();
                    setSearch(query);
                    setUser('');
                }}
            >
                <label>
                    Personel ara
                    <input value={query} maxLength={100} onChange={(event) => setQuery(event.target.value)} />
                </label>
                <button className="button">Ara</button>
            </form>
            <label>
                Personel
                <select value={user} onChange={(event) => setUser(event.target.value)}>
                    <option value="">Seçin</option>
                    {state.data?.users.map((item) => (
                        <option key={item.id} value={item.id}>
                            {item.full_name || item.username} · {item.username}
                        </option>
                    ))}
                </select>
            </label>
            <LoadState {...state} retry={state.reload} />
            {state.data?.selected && (
                <>
                    <p>
                        {state.data.selected.name} · {state.data.selected.department ?? 'Birimsiz'} ·{' '}
                        {state.data.selected.jobTitle ?? 'Unvansız'}
                        {!state.data.selected.active && ' · Hesap pasif'}
                        {state.data.selected.passwordChangeRequired && ' · Parola değişikliği gerekiyor'}
                    </p>
                    <DataTable
                        rows={state.data.pages}
                        columns={columns}
                        rowKey={(row) => row.id}
                        caption="Hesabın etkin erişim kuralları"
                    />
                </>
            )}
        </section>
    );
}
function OriginPanel({ url }: { url: string }) {
    const state = useWorkspaceData<Origin>(url);
    const [query, setQuery] = useState('');
    const columns: Column<OriginField>[] = [
        { key: 'label', label: 'Alan', value: (row) => row.label },
        { key: 'value', label: 'Güncel değer', value: (row) => row.value },
        { key: 'source', label: 'Kaynak alan', value: (row) => row.source },
        { key: 'last', label: 'Kayıtlı son değişiklik', value: (row) => time(row.lastVerifiedChange) },
    ];
    return (
        <>
            <LoadState {...state} retry={state.reload} />
            {state.data && (
                <section className="editor-panel">
                    <h2>{state.data.person.name}</h2>
                    <p>{state.data.note}</p>
                    <label>
                        Alan ara
                        <input
                            type="search"
                            value={query}
                            onChange={(event) => setQuery(event.target.value)}
                        />
                    </label>
                    <DataTable
                        rows={state.data.fields.filter((field) =>
                            (field.label + field.source)
                                .toLocaleLowerCase('tr')
                                .includes(query.toLocaleLowerCase('tr')),
                        )}
                        columns={columns}
                        rowKey={(row) => row.key}
                        caption="Alanların kökeni ve kayıtlı değişiklikleri"
                    />
                    <h3>İçe aktarma kaynağı</h3>
                    {state.data.imports.length ? (
                        state.data.imports.map((item) => (
                            <p key={item.source_name + item.source_id}>
                                {item.source_name} · {item.source_table} · Kaynak kayıt {item.source_id} ·
                                Aktarım sürümü {item.import_version} · {time(item.updated_at)}
                            </p>
                        ))
                    ) : (
                        <p>Bu kişi için içe aktarma eşlemesi bulunmuyor. Elle girildiği varsayılmaz.</p>
                    )}
                    <Link
                        className="button"
                        href={`${portalUrl('/portal')}?scope=all&q=${encodeURIComponent(state.data.person.name ?? '')}`}
                    >
                        Ana kayıt listesinde aç
                    </Link>
                </section>
            )}
        </>
    );
}
function QualityPanel({ baseUrl }: { baseUrl: string }) {
    const [issue, setIssue] = useState('duplicate_identity'),
        [page, setPage] = useState(1),
        [person, setPerson] = useState<number | null>(null);
    const state = useWorkspaceData<Quality>(`${baseUrl}/veri-kalitesi?issue=${issue}&page=${page}`);
    return (
        <section className="editor-panel">
            <h2>Veri kalitesi iş listesi</h2>
            <p>
                Mükerrer veya eksik bilgiler inceleme işaretidir; kayıtlar otomatik birleştirilmez veya
                silinmez. Satırdan kayıt kökenini inceleyebilirsiniz.
            </p>
            {state.data && (
                <>
                    <div className="row-actions">
                        {Object.entries(state.data.issues).map(([key, item]) => (
                            <button
                                type="button"
                                className="button"
                                aria-pressed={issue === key}
                                key={key}
                                onClick={() => {
                                    setIssue(key);
                                    setPage(1);
                                }}
                            >
                                {item.label} · {item.count}
                            </button>
                        ))}
                    </div>
                    <DataTable
                        rows={state.data.rows}
                        columns={[
                            {
                                key: 'full_name',
                                label: 'Adı Soyadı',
                                kind: 'name',
                                value: (row) => row.full_name,
                            },
                            {
                                key: 'tc_identity',
                                label: 'TC Kimlik',
                                kind: 'tc',
                                value: (row) => row.tc_identity,
                            },
                        ]}
                        onRowClick={(row) => setPerson(row.id)}
                        rowLabel={(row) => `${row.full_name ?? 'Kişi'} kayıt kökenini aç`}
                        rowKey={(row) => row.id}
                        caption={state.data.issues[issue].label}
                    />
                    <div className="row-actions">
                        <button className="button" disabled={page <= 1} onClick={() => setPage(page - 1)}>
                            Önceki
                        </button>
                        <span>
                            {state.data.page} / {state.data.pages}
                        </span>
                        <button
                            className="button"
                            disabled={page >= state.data.pages}
                            onClick={() => setPage(page + 1)}
                        >
                            Sonraki
                        </button>
                    </div>
                </>
            )}
            <LoadState {...state} retry={state.reload} />
            {person !== null && (
                <PortalDialog protectChanges={false} title="Kayıt kökeni" onClose={() => setPerson(null)}>
                    <OriginPanel url={`${baseUrl}/veri-kokeni/${person}`} />
                </PortalDialog>
            )}
        </section>
    );
}
function DutyPanel({ baseUrl, kinds }: { baseUrl: string; kinds: { id: string; label: string }[] }) {
    const [kind, setKind] = useState(kinds[0]?.id ?? ''),
        [from, setFrom] = useState(todayInIstanbul());
    const [to, setTo] = useState(from),
        [exclude, setExclude] = useState<number[]>([]),
        [candidates, setCandidates] = useState<Scenarios['candidates']>([]);
    const [result, setResult] = useState<Scenarios | null>(null),
        [selected, setSelected] = useState('balanced'),
        [confirmed, setConfirmed] = useState(false);
    const [busy, setBusy] = useState(false),
        [error, setError] = useState(''),
        [notice, setNotice] = useState('');
    function clear() {
        setResult(null);
        setConfirmed(false);
        setNotice('');
    }
    async function run(action: 'preview' | 'apply') {
        if (busy || (action === 'apply' && (!result || !confirmed))) return;
        setBusy(true);
        setError('');
        setNotice('');
        try {
            if (action === 'preview') {
                const value = await workspaceRequest<Scenarios>(`${baseUrl}/nobet-senaryolari/${kind}`, {
                    action,
                    from,
                    to,
                    exclude,
                });
                setResult(value);
                setCandidates(value.candidates);
                setConfirmed(false);
            } else {
                const value = await workspaceRequest<{ created: number }>(
                    `${baseUrl}/nobet-senaryolari/${kind}`,
                    { action, ...result!.input, token: result!.token, strategy: selected, confirmed },
                );
                setNotice(`${value.created} yeni nöbet kaydı oluşturuldu.`);
                setResult(null);
            }
        } catch (cause) {
            setError(cause instanceof Error ? cause.message : 'İşlem yapılamadı.');
            if (action === 'apply') clear();
        } finally {
            setBusy(false);
        }
    }
    return (
        <section className="editor-panel">
            <h2>Nöbet senaryolarını karşılaştır</h2>
            <p>
                İki yöntem aynı adayları ve mazeretleri kullanır. Mevcut nöbetler korunur. Önizleme kayıt
                oluşturmaz; seçilen senaryo açık onaydan sonra uygulanır.
            </p>
            <form
                onSubmit={(event) => {
                    event.preventDefault();
                    void run('preview');
                }}
            >
                <fieldset className="user-fields" disabled={busy}>
                    <label>
                        Nöbet türü
                        <select
                            value={kind}
                            onChange={(event) => {
                                setKind(event.target.value);
                                setCandidates([]);
                                setExclude([]);
                                clear();
                            }}
                        >
                            {kinds.map((item) => (
                                <option value={item.id} key={item.id}>
                                    {item.label}
                                </option>
                            ))}
                        </select>
                    </label>
                    <label>
                        Başlangıç
                        <input
                            type="date"
                            required
                            value={from}
                            onChange={(event) => {
                                setFrom(event.target.value);
                                clear();
                            }}
                        />
                    </label>
                    <label>
                        Bitiş
                        <input
                            type="date"
                            required
                            value={to}
                            min={from}
                            onChange={(event) => {
                                setTo(event.target.value);
                                clear();
                            }}
                        />
                    </label>
                    {candidates.length > 0 && (
                        <details>
                            <summary>Bu senaryoda hariç tutulacak personel</summary>
                            {candidates.map((person) => (
                                <label key={person.id}>
                                    <input
                                        type="checkbox"
                                        checked={exclude.includes(person.id)}
                                        onChange={(event) => {
                                            setExclude(
                                                event.target.checked
                                                    ? [...exclude, person.id]
                                                    : exclude.filter((id) => id !== person.id),
                                            );
                                            clear();
                                        }}
                                    />
                                    {person.name}
                                </label>
                            ))}
                        </details>
                    )}
                    <button className="button button--primary" disabled={!kind}>
                        Senaryoları hesapla
                    </button>
                </fieldset>
            </form>
            {busy && <p role="status">İşlem sürüyor…</p>}
            {error && (
                <p className="form-error" role="alert">
                    {error}
                </p>
            )}
            {notice && <p role="status">{notice}</p>}
            {result && (
                <>
                    <div className="user-fields">
                        {result.scenarios.map((scenario) => (
                            <section key={scenario.strategy} className="editor-panel">
                                <h3>
                                    <label>
                                        <input
                                            type="radio"
                                            name="duty-strategy"
                                            value={scenario.strategy}
                                            checked={selected === scenario.strategy}
                                            onChange={() => {
                                                setSelected(scenario.strategy);
                                                setConfirmed(false);
                                            }}
                                        />
                                        {scenario.label}
                                    </label>
                                </h3>
                                <p>
                                    {scenario.rows.length} yeni atama · {scenario.preserved} mevcut gün
                                    korunur · {scenario.skipped.length} uygun personel bulunamayan gün ·
                                    Toplam yük farkı: {scenario.spread}
                                </p>
                                <DataTable
                                    rows={scenario.workload}
                                    columns={[
                                        {
                                            key: 'name',
                                            label: 'Adı Soyadı',
                                            kind: 'name',
                                            value: (row) => row.name,
                                        },
                                        { key: 'new', label: 'Yeni görev', value: (row) => row.new },
                                        {
                                            key: 'total',
                                            label: 'Önceki 90 gün + plan',
                                            value: (row) => row.total,
                                        },
                                        {
                                            key: 'weekends',
                                            label: 'Hafta sonu',
                                            value: (row) => row.weekends,
                                        },
                                    ]}
                                    rowKey={(row) => row.id}
                                    caption={scenario.label}
                                />
                                {scenario.skipped.length > 0 && (
                                    <p>Atanmayan günler: {scenario.skipped.join(', ')}</p>
                                )}
                                <details>
                                    <summary>Önerilen günlük atamalar</summary>
                                    <DataTable
                                        rows={scenario.rows}
                                        columns={[
                                            { key: 'date', label: 'Tarih', value: (row) => row.date },
                                            {
                                                key: 'name',
                                                label: 'Adı Soyadı',
                                                kind: 'name',
                                                value: (row) => row.name,
                                            },
                                        ]}
                                        rowKey={(row) => row.date}
                                        caption="Önerilen atamalar"
                                    />
                                </details>
                            </section>
                        ))}
                    </div>
                    <label>
                        <input
                            type="checkbox"
                            disabled={busy}
                            checked={confirmed}
                            onChange={(event) => setConfirmed(event.target.checked)}
                        />
                        Seçtiğim senaryonun yeni atamalarını kontrol ettim ve uygulanmasını onaylıyorum.
                    </label>
                    <button
                        className="button button--primary"
                        disabled={busy || !confirmed}
                        onClick={() => void run('apply')}
                    >
                        Seçilen senaryoyu uygula
                    </button>
                </>
            )}
        </section>
    );
}

type RehearsalResult = {
    status: string;
    at: string;
    tables?: number;
    rows?: number;
    files?: number;
    durationMs?: number;
    temporaryResourcesCleaned?: boolean;
};
function RehearsalPanel({ baseUrl }: { baseUrl: string }) {
    const state = useWorkspaceData<{
        supported: boolean;
        backups: { id: number; name: string; created_at: string; bytes: number }[];
        last: RehearsalResult | null;
    }>(baseUrl + '/yedek-provalari');
    const [backup, setBackup] = useState(''),
        [confirmed, setConfirmed] = useState(false),
        [busy, setBusy] = useState(false),
        [error, setError] = useState(''),
        [result, setResult] = useState<RehearsalResult | null>(null);
    async function run() {
        if (busy || !confirmed || !backup) return;
        setBusy(true);
        setError('');
        setResult(null);
        try {
            setResult(
                await workspaceRequest<RehearsalResult>(baseUrl + '/yedek-provalari', {
                    backup: Number(backup),
                    confirmed,
                }),
            );
            state.reload();
        } catch (cause) {
            setError(cause instanceof Error ? cause.message : 'Prova tamamlanamadı.');
        } finally {
            setBusy(false);
            setConfirmed(false);
        }
    }
    return (
        <section className="editor-panel">
            <h2>İzole geri yükleme provası</h2>
            <p>
                Seçilen panel yedeği yeni ve ayrı bir geçici veritabanında açılır. Satır içerikleri ve özel
                dosyalar karşılaştırılır; prova kaynakları sonunda temizlenir. Çalışan sistemin kayıtları geri
                yüklenmez.
            </p>
            <LoadState {...state} retry={state.reload} />
            {state.data && !state.data.supported && <p>Bu prova MariaDB/MySQL ortamında kullanılabilir.</p>}
            {state.data?.supported && (
                <form
                    onSubmit={(event) => {
                        event.preventDefault();
                        void run();
                    }}
                >
                    <fieldset disabled={busy}>
                        <label>
                            Panel yedeği
                            <select
                                required
                                value={backup}
                                onChange={(event) => {
                                    setBackup(event.target.value);
                                    setConfirmed(false);
                                }}
                            >
                                <option value="">Seçin</option>
                                {state.data.backups.map((item) => (
                                    <option value={item.id} key={item.id}>
                                        {item.name} · {time(item.created_at)}
                                    </option>
                                ))}
                            </select>
                        </label>
                        <label>
                            <input
                                type="checkbox"
                                checked={confirmed}
                                onChange={(event) => setConfirmed(event.target.checked)}
                            />
                            Bu yedeğin geçici, ayrı bir veritabanında açılmasını ve prova kaynaklarının
                            temizlenmesini onaylıyorum.
                        </label>
                        <button className="button button--primary" disabled={!backup || !confirmed}>
                            Provayı başlat
                        </button>
                    </fieldset>
                </form>
            )}
            {busy && <p role="status">Prova sürüyor. Geçici veritabanı oluşturma ve silme izni gerekir.</p>}
            {error && (
                <p className="form-error" role="alert">
                    {error}
                </p>
            )}
            {(result ?? state.data?.last) && (
                <section>
                    <h3>Son prova</h3>
                    <p>
                        {(result ?? state.data?.last)?.status === 'passed' ? 'Doğrulandı' : 'Başarısız'} ·{' '}
                        {time((result ?? state.data?.last)?.at ?? null)}
                    </p>
                    {(result ?? state.data?.last)?.status === 'passed' && (
                        <p>
                            {(result ?? state.data?.last)?.tables} tablo ·{' '}
                            {(result ?? state.data?.last)?.rows} satır · {(result ?? state.data?.last)?.files}{' '}
                            dosya · {(result ?? state.data?.last)?.durationMs} ms · Geçici kaynaklar
                            temizlendi.
                        </p>
                    )}
                </section>
            )}
        </section>
    );
}

export default function Workspace({
    applicationName,
    navigation,
    baseUrl,
    handover,
    canManage,
    dutyKinds,
}: {
    applicationName: string;
    navigation?: { label: string; url: string }[];
    baseUrl: string;
    handover: Handover;
    canManage: boolean;
    dutyKinds: { id: string; label: string }[];
}) {
    const [tab, setTab] = useState('handover');
    const tabs = [
        { id: 'handover', label: 'Devir özeti' },
        ...(canManage
            ? [
                  { id: 'permissions', label: 'Yetki simülatörü' },
                  { id: 'quality', label: 'Veri kalitesi ve kökeni' },
                  { id: 'rehearsal', label: 'Yedek provası' },
              ]
            : []),
        ...(dutyKinds.length ? [{ id: 'duties', label: 'Nöbet senaryoları' }] : []),
    ];
    return (
        <AppShell
            applicationName={applicationName}
            navigation={navigation}
            pageTitle="Çalışma merkezi"
            search={null}
            toolbar={
                <div className="row-actions">
                    {tabs.map((item) => (
                        <button
                            type="button"
                            className="button"
                            aria-pressed={tab === item.id}
                            key={item.id}
                            onClick={() => setTab(item.id)}
                        >
                            {item.label}
                        </button>
                    ))}
                </div>
            }
        >
            <Head title="Çalışma merkezi" />
            {tab === 'handover' && (
                <section className="editor-panel">
                    <h2>Vardiya devir özeti</h2>
                    <p>
                        Son {handover.hours} saatteki kendi işlemleriniz ve erişiminiz kapsamındaki yaklaşan
                        işler. Hazırlanma: {time(handover.generatedAt)}.
                    </p>
                    <label>
                        Zaman aralığı
                        <select
                            value={handover.hours}
                            onChange={(event) =>
                                router.get(baseUrl, { hours: event.target.value }, { preserveState: true })
                            }
                        >
                            {[8, 12, 24, 48, 168].map((hours) => (
                                <option key={hours} value={hours}>
                                    {hours} saat
                                </option>
                            ))}
                        </select>
                    </label>
                    <div className="health-overview">
                        {handover.tasks.map((task) => (
                            <article key={task.label}>
                                <h3>{task.label}</h3>
                                <strong>{task.count}</strong>
                                <p>
                                    <Link href={task.url}>İlgili ekranı aç</Link>
                                </p>
                            </article>
                        ))}
                    </div>
                    <DataTable
                        rows={handover.activity}
                        columns={[
                            { key: 'resource_type', label: 'Kayıt alanı', value: (row) => row.resource_type },
                            { key: 'action', label: 'İşlem', value: (row) => row.action },
                            { key: 'count', label: 'Adet', value: (row) => row.count },
                            { key: 'last_at', label: 'Son işlem', value: (row) => time(row.last_at) },
                        ]}
                        rowKey={(row) => row.resource_type + row.action}
                        caption="Kendi işlem özetiniz"
                    />
                </section>
            )}
            {tab === 'permissions' && canManage && <PermissionPanel baseUrl={baseUrl} />}
            {tab === 'quality' && canManage && <QualityPanel baseUrl={baseUrl} />}
            {tab === 'rehearsal' && canManage && <RehearsalPanel baseUrl={baseUrl} />}
            {tab === 'duties' && dutyKinds.length > 0 && <DutyPanel baseUrl={baseUrl} kinds={dutyKinds} />}
        </AppShell>
    );
}
