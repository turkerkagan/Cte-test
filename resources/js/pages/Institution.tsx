import { Head, Link, router } from '@inertiajs/react';
import { useEffect, useState, type ReactNode } from 'react';
import PortalDialog from '../components/PortalDialog';
import PhoneDirectory from '../components/PhoneDirectory';
import AnnouncementCards from '../components/AnnouncementCards';
import AnnouncementBoard from '../components/AnnouncementBoard';
import PartialSearchBoard from '../components/PartialSearchBoard';
import DutyCalendar from '../components/DutyCalendar';
import DutyProgramTable from '../components/DutyProgramTable';
import { notify } from '../components/Toast';
import AppShell from '../components/AppShell';
import { ActiveFilters, QuickSearch } from '../components/ListToolbar';
import DataTable from '../components/DataTable';
import BoardTable from '../components/BoardTable';
import { writeJson } from '../lib/workflow';
import useLiveList from '../lib/useLiveList';
type Value = string | number | null;
type Fields = Record<
    string,
    {
        label: string;
        type: string;
        required?: boolean;
        readonly?: boolean;
        form_visible?: boolean;
        table_visible?: boolean;
        max_length?: number;
        option_list?: string;
        default?: string | null;
    }
>;
type Row = {
    is_active?: boolean;
    id: number;
    revision: number;
    values: Record<string, Value>;
    display: Record<string, string>;
    isRead: boolean;
};
type Props = {
    management?: boolean;
    applicationName: string;
    navigation?: { label: string; url: string }[];
    baseUrl: string;
    logoutUrl: string;
    kind: string;
    title: string;
    fields: Fields;
    options?: Record<string, { id: number; label: string; is_active: boolean }[]>;
    rows: Row[];
    candidates: { id: number; name: string }[];
    rooms: { id: number; name: string; block?: string }[];
    summary?: { valid: number; rooms: number; cancelled: number };
    defaultDateTime?: string;
    unavailable: { id: number; user_id: number; date: string; reason: string }[];
    queryState: {
        state?: 'active' | 'archived';
        q?: string;
        from?: string;
        to?: string;
        room?: number;
        block?: string;
    };
    pagination: { page: number; pages: number; total: number };
    canWrite: boolean;
};
export default function Institution(p: Props) {
    return <InstitutionPage key={p.kind + (p.management ? ':management' : '')} {...p} />;
}
function InstitutionPage(p: Props) {
    const archived = p.kind === 'telefon' && p.queryState.state === 'archived';
    const phoneTabs = p.management && p.kind === 'telefon' && (
        <nav aria-label="Telefon kayıt durumu" className="row-actions">
            {(['active', 'archived'] as const).map((state) => (
                <Link
                    key={state}
                    className={
                        (p.queryState.state ?? 'active') === state ? 'button button--primary' : 'button'
                    }
                    aria-current={(p.queryState.state ?? 'active') === state ? 'page' : undefined}
                    href={`${p.baseUrl}?state=${state}`}
                >
                    {state === 'active' ? 'Aktif kayıtlar' : 'Arşiv'}
                </Link>
            ))}
        </nav>
    );
    const duty = ['mudurnobet', 'vardiyanobet', 'infaznobet'].includes(p.kind);
    const [dutyTab, setDutyTab] = useState(p.kind === 'infaznobet' && p.canWrite ? 'setup' : 'schedule');
    const [calendarVisible, setCalendarVisible] = useState(false);
    const [boardRowId, setBoardRowId] = useState<number | null>(null);
    const [boardEditing, setBoardEditing] = useState(false);
    const [managePhone, setManagePhone] = useState(p.management ?? false);
    const [selected, setSelected] = useState<Row | null>(null);
    const [form, setForm] = useState<Record<string, Value>>({});
    const [open, setOpen] = useState(false);
    const [error, setError] = useState('');
    const [busy, setBusy] = useState(false);
    const [from, setFrom] = useState('');
    const [to, setTo] = useState('');
    const [fill, setFill] = useState(false);
    const [notice, setNotice] = useState('');
    const [reason, setReason] = useState('');
    const [unavailableUser, setUnavailableUser] = useState<number | null>(null);
    const [unavailableDate, setUnavailableDate] = useState('');
    const [unavailableDates, setUnavailableDates] = useState<string[]>([]);
    const [board, setBoard] = useState(p.rows);
    const query = new URLSearchParams(
        Object.entries(p.queryState)
            .filter(([, v]) => v !== null && v !== undefined)
            .map(([k, v]) => [k, String(v)]),
    ).toString();
    useLiveList(
        ['rows', 'pagination', 'summary', 'unavailable', 'canWrite'],
        open || busy || boardEditing || (p.kind === 'telefon' && !managePhone),
    );
    useEffect(() => {
        if (!boardEditing) setBoard(p.rows);
    }, [p.rows, boardEditing]);
    async function mutate(payload: unknown, id?: number) {
        setBusy(true);
        setError('');
        try {
            const r = await writeJson(id ? `${p.baseUrl}/${id}` : p.baseUrl, payload, 'POST');
            if (!r.ok) {
                setError(
                    r.data.message ??
                        Object.values(r.data.errors ?? {})
                            .flat()
                            .join(' '),
                );
                return false;
            }
            const extra = r.data as { unavailable_days?: string[] };
            if (extra.unavailable_days?.length) {
                setNotice(`Uygun personel bulunmayan günler: ${extra.unavailable_days.join(', ')}`);
                router.reload();
            } else {
                notify('İşlem tamamlandı.');
                setOpen(false);
                router.visit(`${p.baseUrl}?${query}`);
            }
            return true;
        } catch (e) {
            setError(e instanceof Error ? e.message : 'Kaydedilemedi.');
            return false;
        } finally {
            setBusy(false);
        }
    }
    function edit(row: Row | null) {
        setSelected(row);
        setForm(
            row?.values ??
                Object.fromEntries(
                    Object.entries(p.fields)
                        .filter(([, f]) => f.default != null)
                        .map(([k, f]) => [k, f.default ?? null]),
                ),
        );
        setOpen(true);
        setError('');
    }
    const editable = Object.entries(p.fields).filter(([, f]) => !f.readonly && (f.form_visible ?? true));
    const recordForm = archived ? null : (
        <form
            className="definition-form"
            onSubmit={(e) => {
                e.preventDefault();
                void mutate(
                    {
                        values: Object.fromEntries(
                            Object.entries(form).filter(
                                ([key]) => !p.fields[key]?.readonly && key in p.fields,
                            ),
                        ),
                        ...(selected ? { revision: selected.revision } : {}),
                    },
                    selected?.id,
                );
            }}
        >
            <fieldset
                className="user-fields"
                disabled={busy || (p.kind === 'kismiarama' && selected !== null)}
            >
                {editable.map(([key, f]) => (
                    <div className="form-field" key={key}>
                        <label htmlFor={`institution-edit-${key}`}>{f.label}</label>
                        {['user', 'room'].includes(f.type) ? (
                            <select
                                id={`institution-edit-${key}`}
                                required={f.required}
                                value={form[key] ?? ''}
                                onChange={(e) =>
                                    setForm({
                                        ...form,
                                        [key]: e.target.value ? Number(e.target.value) : null,
                                    })
                                }
                            >
                                <option value="">Seçin</option>
                                {(f.type === 'user' ? p.candidates : p.rooms).map((v) => (
                                    <option key={v.id} value={v.id}>
                                        {v.name}
                                    </option>
                                ))}
                            </select>
                        ) : f.type === 'boolean' ? (
                            <select
                                id={`institution-edit-${key}`}
                                value={form[key] ?? ''}
                                onChange={(e) =>
                                    setForm({
                                        ...form,
                                        [key]: e.target.value === '' ? null : Number(e.target.value),
                                    })
                                }
                            >
                                <option value="">Belirtilmedi</option>
                                <option value={1}>Evet</option>
                                <option value={0}>Hayır</option>
                            </select>
                        ) : f.type === 'option' ? (
                            <select
                                id={`institution-edit-${key}`}
                                value={form[key] ?? ''}
                                required={f.required}
                                onChange={(e) =>
                                    setForm({
                                        ...form,
                                        [key]: e.target.value ? Number(e.target.value) : null,
                                    })
                                }
                            >
                                <option value="">Seçin</option>
                                {p.options?.[(f.option_list ?? key).replaceAll('-', '_')]
                                    ?.filter((v) => v.is_active || v.id === form[key])
                                    .map((v) => (
                                        <option key={v.id} value={v.id}>
                                            {v.label}
                                        </option>
                                    ))}
                            </select>
                        ) : f.type === 'longtext' ? (
                            <textarea
                                id={`institution-edit-${key}`}
                                required={f.required}
                                maxLength={f.max_length ?? 10000}
                                value={form[key] ?? ''}
                                onChange={(e) => setForm({ ...form, [key]: e.target.value || null })}
                            />
                        ) : key === 'result' ? (
                            <select
                                id={`institution-edit-${key}`}
                                required
                                value={form[key] ?? ''}
                                onChange={(e) => setForm({ ...form, [key]: e.target.value })}
                            >
                                <option value="">Seçin</option>
                                {['Olumsuzluk yok', 'Olumsuzluk tespit edildi', 'İşlem tamamlanamadı'].map(
                                    (v) => (
                                        <option key={v}>{v}</option>
                                    ),
                                )}
                            </select>
                        ) : (
                            <input
                                id={`institution-edit-${key}`}
                                type={
                                    ['integer', 'decimal'].includes(f.type)
                                        ? 'number'
                                        : f.type === 'datetime'
                                          ? 'datetime-local'
                                          : f.type === 'time'
                                            ? 'time'
                                            : f.type === 'date'
                                              ? 'date'
                                              : 'text'
                                }
                                required={f.required}
                                step={f.type === 'decimal' ? 'any' : undefined}
                                maxLength={f.max_length ?? (key === 'staff' ? 500 : 255)}
                                value={form[key] ?? ''}
                                onInput={(e) =>
                                    setForm({
                                        ...form,
                                        [key]:
                                            e.currentTarget.value === ''
                                                ? null
                                                : f.type === 'integer'
                                                  ? Number(e.currentTarget.value)
                                                  : e.currentTarget.value,
                                    })
                                }
                                onChange={(e) =>
                                    setForm({
                                        ...form,
                                        [key]:
                                            e.target.value === ''
                                                ? null
                                                : f.type === 'integer'
                                                  ? Number(e.target.value)
                                                  : e.target.value,
                                    })
                                }
                            />
                        )}
                    </div>
                ))}
            </fieldset>
            {!(p.kind === 'kismiarama' && selected) && (
                <button className="button button--primary" disabled={busy}>
                    Kaydet
                </button>
            )}
        </form>
    );
    function visitQuery(next: Props['queryState']) {
        router.get(p.baseUrl, { ...next, page: 1 }, { preserveState: true, preserveScroll: true });
    }
    const chips = Object.entries(p.queryState)
        .filter(
            ([key, value]) =>
                value !== undefined &&
                value !== null &&
                value !== '' &&
                !(key === 'state' && value === 'active'),
        )
        .map(([key, value]) => ({
            key,
            label: `${({ q: 'Arama', from: 'Başlangıç', to: 'Bitiş', room: 'Koğuş', block: 'Blok', state: 'Durum' } as Record<string, string>)[key] ?? key}: ${key === 'room' ? (p.rooms.find((room) => room.id === value)?.name ?? value) : key === 'state' ? 'Arşiv' : value}`,
            onRemove: () => {
                const next = { ...p.queryState };
                delete next[key as keyof typeof next];
                visitQuery(next);
            },
        }));
    const quickSearch = (
        <>
            <QuickSearch value={p.queryState.q ?? ''} onSearch={(q) => visitQuery({ ...p.queryState, q })} />
            <div className="summary-info">
                <p>
                    <strong>{p.title}: </strong>
                    {p.pagination.total} kayıt
                </p>
            </div>
        </>
    );
    const filterBar = (actions?: ReactNode) => (
        <ActiveFilters chips={chips} onClear={() => visitQuery({})} actions={actions} />
    );
    if (p.kind === 'kismiarama')
        return (
            <AppShell
                listing
                applicationName={p.applicationName}
                navigation={p.navigation}
                pageTitle={p.title}
                toolbar={filterBar()}
                search={quickSearch}
            >
                <Head title={p.title} />
                {phoneTabs}
                <PartialSearchBoard {...p} />
            </AppShell>
        );
    if (p.kind === 'duyurular')
        return (
            <AppShell
                listing
                applicationName={p.applicationName}
                navigation={p.navigation}
                pageTitle={p.title}
                toolbar={filterBar(
                    <Link className="cte-primary-action" href={`${p.baseUrl}/yazdir?${query}`}>
                        Yazdır / PDF
                    </Link>,
                )}
                search={quickSearch}
            >
                <Head title={p.title} />
                {phoneTabs}
                <AnnouncementBoard rows={p.rows} canWrite={p.canWrite} baseUrl={p.baseUrl} />
                <div className="row-actions">
                    <button
                        className="button"
                        disabled={p.pagination.page === 1}
                        onClick={() => router.visit(`${p.baseUrl}?${query}&page=${p.pagination.page - 1}`)}
                    >
                        Önceki
                    </button>
                    <span>
                        {p.pagination.page} / {p.pagination.pages} · {p.pagination.total} kayıt
                    </span>
                    <button
                        className="button"
                        disabled={p.pagination.page === p.pagination.pages}
                        onClick={() => router.visit(`${p.baseUrl}?${query}&page=${p.pagination.page + 1}`)}
                    >
                        Sonraki
                    </button>
                </div>
            </AppShell>
        );
    if (p.kind === 'telefon' && !managePhone)
        return (
            <AppShell
                listing
                applicationName={p.applicationName}
                navigation={p.navigation}
                pageTitle={p.title}
                toolbar={filterBar(
                    p.canWrite && (
                        <button className="cte-primary-action" onClick={() => setManagePhone(true)}>
                            Rehberi yönet
                        </button>
                    ),
                )}
                search={quickSearch}
            >
                <Head title={p.title} />
                {phoneTabs}
                <PhoneDirectory searchQuery={p.queryState.q ?? ''} />
            </AppShell>
        );
    return (
        <AppShell
            listing
            applicationName={p.applicationName}
            navigation={p.navigation}
            pageTitle={p.title}
            toolbar={filterBar(
                <>
                    {managePhone && (
                        <button className="cte-primary-action" onClick={() => setManagePhone(false)}>
                            Rehbere dön
                        </button>
                    )}
                    <Link className="cte-primary-action" href={`${p.baseUrl}/yazdir?${query}`}>
                        Yazdır / PDF
                    </Link>
                    {p.canWrite && !archived && p.kind !== 'kurul' && (
                        <button className="cte-primary-action" onClick={() => edit(null)}>
                            Yeni kayıt
                        </button>
                    )}
                </>,
            )}
            search={quickSearch}
        >
            <Head title={p.title} />
            {phoneTabs}
            {duty && (
                <header className="duty-page-heading">
                    <h1>{p.title}</h1>
                    {p.kind === 'infaznobet' && p.canWrite && (
                        <nav aria-label="İnfaz nöbeti bölümleri">
                            {[
                                ['setup', 'Nöbet Kurulumu & Personel'],
                                ['schedule', 'Nöbet Programı'],
                            ].map(([key, label]) => (
                                <button
                                    key={key}
                                    type="button"
                                    aria-pressed={dutyTab === key}
                                    className={dutyTab === key ? 'is-active' : ''}
                                    onClick={() => setDutyTab(key)}
                                >
                                    {label}
                                </button>
                            ))}
                        </nav>
                    )}
                </header>
            )}
            {['duyurular', 'kismiarama', 'kurul'].includes(p.kind) && (
                <h1 className="institution-page-title">
                    {p.kind === 'kurul' ? `${p.applicationName} KURUM KURUL VE KOMİSYONLARI` : p.title}
                </h1>
            )}
            {p.canWrite && ['duyurular', 'kismiarama'].includes(p.kind) && !open && (
                <section className="editor-panel institution-inline-create">
                    <h2>{p.kind === 'duyurular' ? 'Yeni Duyuru Ekle (Yönetici)' : 'Yeni arama kaydı'}</h2>
                    {recordForm}
                </section>
            )}
            {error && (
                <p className="form-error" role="alert">
                    {error}
                </p>
            )}
            {notice && <p role="status">{notice}</p>}
            {p.canWrite && duty && (p.kind !== 'infaznobet' || dutyTab === 'setup') && (
                <section className="editor-panel">
                    <h2>Eksik günleri ekle</h2>
                    {p.kind === 'vardiyanobet' && (
                        <button
                            className="button"
                            disabled={busy}
                            onClick={() =>
                                void mutate({
                                    action: 'generate',
                                    from: new Date().getFullYear() + '-01-01',
                                    to: new Date().getFullYear() + '-12-31',
                                    fill: true,
                                })
                            }
                        >
                            Bu yılın eksik vardiyalarını ekle ve boş personeli doldur
                        </button>
                    )}
                    <form
                        className="definition-form"
                        onSubmit={(e) => {
                            e.preventDefault();
                            void mutate({ action: 'generate', from, to, fill });
                        }}
                    >
                        <div className="user-fields">
                            {(['from', 'to'] as const).map((k) => (
                                <div className="form-field" key={k}>
                                    <label htmlFor={`duty-${k}`}>
                                        {k === 'from' ? 'Başlangıç' : 'Bitiş'}
                                    </label>
                                    <input
                                        id={`duty-${k}`}
                                        required
                                        type="date"
                                        value={k === 'from' ? from : to}
                                        onInput={(e) =>
                                            (k === 'from' ? setFrom : setTo)(e.currentTarget.value)
                                        }
                                        onChange={(e) => (k === 'from' ? setFrom : setTo)(e.target.value)}
                                    />
                                </div>
                            ))}
                            {p.kind === 'vardiyanobet' && (
                                <label className="checkbox-field">
                                    <input
                                        type="checkbox"
                                        checked={fill}
                                        onChange={(e) => setFill(e.target.checked)}
                                    />
                                    Boş atamaları personel sırasıyla doldur
                                </label>
                            )}
                        </div>
                        <button disabled={busy} className="button">
                            Programı oluştur
                        </button>
                    </form>
                </section>
            )}
            {duty && (p.kind !== 'infaznobet' || dutyTab === 'schedule') && (
                <div className="row-actions">
                    <button
                        type="button"
                        className="button"
                        onClick={() => setCalendarVisible(!calendarVisible)}
                    >
                        {calendarVisible ? 'Takvimi gizle' : 'Takvim görünümü'}
                    </button>
                </div>
            )}
            {duty && calendarVisible && (p.kind !== 'infaznobet' || dutyTab === 'schedule') && (
                <DutyCalendar
                    baseUrl={p.baseUrl}
                    initialMonth={p.queryState.from}
                    onEdit={p.canWrite ? edit : undefined}
                />
            )}
            {duty ? (
                (p.kind !== 'infaznobet' || dutyTab === 'schedule') && (
                    <DutyProgramTable
                        error={error}
                        kind={p.kind}
                        rows={p.rows}
                        candidates={p.candidates}
                        canWrite={p.canWrite}
                        busy={busy}
                        onSave={(row, values) => mutate({ revision: row.revision, values }, row.id)}
                    />
                )
            ) : p.kind === 'duyurular' ? (
                <AnnouncementCards
                    rows={p.rows}
                    canWrite={p.canWrite}
                    busy={busy}
                    onEdit={edit}
                    onReceipt={(r) => void mutate({ action: 'receipt', id: r.id, read: !r.isRead })}
                    baseUrl={p.baseUrl}
                />
            ) : p.kind === 'kurul' ? (
                <section className="institution-board">
                    {p.canWrite && (
                        <div className="row-actions">
                            <button
                                className="button"
                                disabled={busy}
                                onClick={() => {
                                    setBoard(p.rows);
                                    setBoardRowId(null);
                                    setBoardEditing(true);
                                }}
                            >
                                Tabloyu düzenle
                            </button>
                        </div>
                    )}
                    <BoardTable
                        columns={Object.entries(p.fields)
                            .filter(([key]) => key.endsWith('_top'))
                            .map(([key, field]) => [
                                key.slice(0, -4),
                                field.label.replace(/\s*\(?İsim\)?$/u, ''),
                            ])}
                        rows={p.rows}
                        busy={busy}
                        onRowClick={
                            p.canWrite
                                ? (row) => {
                                      setBoard(p.rows);
                                      setBoardRowId(row.id);
                                      setBoardEditing(true);
                                  }
                                : undefined
                        }
                    />
                    {boardEditing && (
                        <PortalDialog
                            title={boardRowId === null ? 'Kurul tablosunu düzenle' : 'Kurul satırını düzenle'}
                            className="cte-record-modal"
                            busy={busy}
                            onClose={() => setBoardEditing(false)}
                        >
                            <section className="editor-panel">
                                {error && (
                                    <p className="form-error" role="alert">
                                        {error}
                                    </p>
                                )}
                                <BoardTable
                                    columns={Object.entries(p.fields)
                                        .filter(([key]) => key.endsWith('_top'))
                                        .map(([key, field]) => [
                                            key.slice(0, -4),
                                            field.label.replace(/\s*\(?İsim\)?$/u, ''),
                                        ])}
                                    rows={
                                        boardRowId === null
                                            ? board
                                            : board.filter((row) => row.id === boardRowId)
                                    }
                                    editing
                                    busy={busy}
                                    onChange={(id, key, value) =>
                                        setBoard(
                                            board.map((row) =>
                                                row.id === id
                                                    ? { ...row, values: { ...row.values, [key]: value } }
                                                    : row,
                                            ),
                                        )
                                    }
                                />
                                <button
                                    disabled={busy || board.length !== 12}
                                    className="button button--primary"
                                    onClick={() => {
                                        void mutate({
                                            action: 'board',
                                            records: board.map((row) => ({
                                                id: row.id,
                                                revision: row.revision,
                                                values: Object.fromEntries(
                                                    Object.entries(row.values).filter(
                                                        ([key]) => key in p.fields && !p.fields[key].readonly,
                                                    ),
                                                ),
                                            })),
                                        }).then((saved) => {
                                            if (saved) setBoardEditing(false);
                                        });
                                    }}
                                >
                                    Değişiklikleri kaydet
                                </button>
                            </section>
                        </PortalDialog>
                    )}
                </section>
            ) : (
                <DataTable
                    rows={p.rows}
                    columns={[
                        { key: 'sequence', label: 'ID', kind: 'sequence', value: () => null },
                        ...Object.entries(p.fields)
                            .filter(([, f]) => f.table_visible ?? true)
                            .map(([key, f]) => ({ key, label: f.label, value: (r: Row) => r.display[key] })),
                    ]}
                    onRowClick={p.canWrite ? edit : undefined}
                    rowDisabled={() => busy}
                    rowKey={(r) => r.id}
                    caption={p.title}
                />
            )}
            {open && (
                <PortalDialog
                    onClose={() => setOpen(false)}
                    title={selected ? 'Kayıt ayrıntısı' : 'Yeni kayıt'}
                    busy={busy}
                >
                    <section className="editor-panel">
                        {error && (
                            <p className="form-error" role="alert">
                                {error}
                            </p>
                        )}
                        <h2>{selected ? 'Kayıt ayrıntısı' : 'Yeni kayıt'}</h2>
                        {recordForm}
                        {selected && p.kind === 'kismiarama' && !selected.values.cancelled_at && (
                            <div className="form-field">
                                <label htmlFor="cancel-reason">İptal gerekçesi</label>
                                <input
                                    id="cancel-reason"
                                    required
                                    maxLength={500}
                                    value={reason}
                                    onChange={(e) => setReason(e.target.value)}
                                />
                                <button
                                    className="button button--danger"
                                    disabled={busy || !reason.trim()}
                                    onClick={() =>
                                        void mutate(
                                            { action: 'archive', revision: selected.revision, reason },
                                            selected.id,
                                        )
                                    }
                                >
                                    Tutanağı gerekçe ile iptal et
                                </button>
                            </div>
                        )}
                        {selected && archived && (
                            <button
                                className="button button--primary"
                                disabled={busy}
                                onClick={() =>
                                    void mutate(
                                        { action: 'restore', revision: selected.revision },
                                        selected.id,
                                    )
                                }
                            >
                                Geri al
                            </button>
                        )}
                        {selected && !archived && !['kismiarama', 'kurul'].includes(p.kind) && (
                            <button
                                className="button button--danger"
                                disabled={busy}
                                onClick={() =>
                                    void mutate(
                                        { action: 'archive', revision: selected.revision },
                                        selected.id,
                                    )
                                }
                            >
                                {p.kind === 'telefon' ? 'Arşivle' : 'Kaydı kaldır'}
                            </button>
                        )}
                        <button className="button" onClick={() => setOpen(false)}>
                            Kapat
                        </button>
                    </section>
                </PortalDialog>
            )}
            {p.kind === 'infaznobet' && p.canWrite && dutyTab === 'setup' && (
                <section className="editor-panel">
                    <h2>Nöbete Dahil Personeller</h2>
                    {p.candidates.length ? (
                        <ul className="duty-staff-list">
                            {p.candidates.map((c) => (
                                <li key={c.id}>{c.name}</li>
                            ))}
                        </ul>
                    ) : (
                        <p role="status">Nöbet personeli bulunamadı.</p>
                    )}
                </section>
            )}
            {p.kind === 'infaznobet' && p.canWrite && dutyTab === 'setup' && (
                <section className="editor-panel">
                    <h2>Müsait olmayan günler</h2>
                    <form
                        onSubmit={(e) => {
                            e.preventDefault();
                            void mutate({
                                action: 'unavailable',
                                user_id: unavailableUser,
                                dates: unavailableDates.length ? unavailableDates : [unavailableDate],
                                reason,
                            });
                        }}
                    >
                        <select
                            aria-label="Personel"
                            required
                            value={unavailableUser ?? ''}
                            onChange={(e) =>
                                setUnavailableUser(e.target.value ? Number(e.target.value) : null)
                            }
                        >
                            <option value="">Personel seçin</option>
                            {p.candidates.map((c) => (
                                <option key={c.id} value={c.id}>
                                    {c.name}
                                </option>
                            ))}
                        </select>
                        <input
                            aria-label="Müsait olmayan tarih"
                            type="date"
                            required={!unavailableDates.length}
                            value={unavailableDate}
                            onInput={(e) => setUnavailableDate(e.currentTarget.value)}
                            onChange={(e) => setUnavailableDate(e.target.value)}
                        />
                        <button
                            className="button"
                            type="button"
                            disabled={!unavailableDate}
                            onClick={() => {
                                setUnavailableDates(
                                    [...new Set([...unavailableDates, unavailableDate])].sort(),
                                );
                                setUnavailableDate('');
                            }}
                        >
                            Tarihi listeye ekle
                        </button>
                        {unavailableDates.map((date) => (
                            <button
                                className="button"
                                type="button"
                                key={date}
                                aria-label={date + ' tarihini seçimden kaldır'}
                                onClick={() =>
                                    setUnavailableDates(unavailableDates.filter((d) => d !== date))
                                }
                            >
                                {date} ×
                            </button>
                        ))}
                        <input
                            aria-label="Gerekçe"
                            required
                            maxLength={500}
                            value={reason}
                            onChange={(e) => setReason(e.target.value)}
                        />
                        <button className="button" disabled={busy}>
                            Ekle
                        </button>
                    </form>
                    {p.unavailable.map((u) => (
                        <p key={u.id}>
                            {p.candidates.find((c) => c.id === u.user_id)?.name} · {u.date} · {u.reason}{' '}
                            <button
                                className="button"
                                disabled={busy}
                                onClick={() =>
                                    void mutate({
                                        action: 'unavailable',
                                        user_id: u.user_id,
                                        dates: [u.date],
                                        reason: u.reason,
                                        remove: true,
                                    })
                                }
                            >
                                Kaldır
                            </button>
                        </p>
                    ))}
                </section>
            )}
            {(p.kind !== 'infaznobet' || dutyTab === 'schedule') && (
                <div className="row-actions">
                    <button
                        className="button"
                        disabled={p.pagination.page === 1}
                        onClick={() => router.visit(`${p.baseUrl}?${query}&page=${p.pagination.page - 1}`)}
                    >
                        Önceki
                    </button>
                    <span>
                        {p.pagination.page} / {p.pagination.pages} · {p.pagination.total} kayıt
                    </span>
                    <button
                        className="button"
                        disabled={p.pagination.page === p.pagination.pages}
                        onClick={() => router.visit(`${p.baseUrl}?${query}&page=${p.pagination.page + 1}`)}
                    >
                        Sonraki
                    </button>
                </div>
            )}
        </AppShell>
    );
}
