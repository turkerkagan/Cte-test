import { portalFetch } from '../lib/http';
import BulkPreviewChanges, { type BulkChanges } from './BulkPreviewChanges';
import { useRef, useState } from 'react';
import { router } from '@inertiajs/react';
import DataTable from './DataTable';
import { writeJson } from '../lib/workflow';

type Row = Record<string, unknown>;
type Result = { summary?: Record<string, number>; [key: string]: unknown };
type Props = {
    baseUrl: string;
    canWrite: boolean;
    statuses: { id: number; label: string }[];
    onActivity: (busy: boolean) => void;
    initialResult?: Result | null;
    initialSnapshot?: string | null;
    initialFilename?: string | null;
};
const categories: Record<string, string> = {
    uyap_all: 'Tüm UYAP kayıtları',
    application_all: 'Tüm uygulama kayıtları',
    matched: 'Eşleşen kayıtlar',
    uyap_only: 'Yalnız UYAP’ta bulunanlar',
    application_only: 'Yalnız uygulamada bulunanlar',
    name_mismatch: 'Adı soyadı farklı',
    uyap_duplicates: 'UYAP mükerrer TC',
    application_duplicates: 'Uygulama mükerrer TC',
    invalid_uyap: 'UYAP geçersiz TC',
    invalid_application: 'Uygulama geçersiz TC',
};
const itemKey = (row: Row) => String(row.key ?? row.tc ?? '');
const name = (row: Row) => String(row.full_name ?? row.uygulama_adi ?? row.ad_soyad ?? row.uyap_adi ?? '');
const message = (data: Row) =>
    Object.values(data.errors ?? {})
        .flat()
        .join(' ') || String(data.message ?? 'İşlem yapılamadı.');

/** The legacy modal has two inner pages and one shared, selectable result table. */
export default function HomeBulkOperations({
    baseUrl,
    canWrite,
    statuses,
    onActivity,
    initialResult,
    initialSnapshot,
    initialFilename,
}: Props) {
    const [tab, setTab] = useState<'manual' | 'xml'>('manual');
    const [text, setText] = useState('');
    const [file, setFile] = useState<File | null>(null);
    const [result, setResult] = useState<Result | null>(initialResult ?? null);
    const [snapshot, setSnapshot] = useState<string | null>(initialSnapshot ?? null);
    const [rows, setRows] = useState<Row[]>([]);
    const [review, setReview] = useState<Row[]>([]);
    const [hasResults, setHasResults] = useState(!!initialResult);
    const [source, setSource] = useState<'search' | 'text' | 'xml'>(initialResult ? 'xml' : 'search');
    const [category, setCategory] = useState('uyap_only');
    const [selected, setSelected] = useState<string[]>([]);
    const [status, setStatus] = useState('');
    const [page, setPage] = useState(1);
    const [preview, setPreview] = useState<
        | (BulkChanges & { kind: 'add' | 'delete' | 'status'; token?: string; count: number; people?: Row[] })
        | null
    >(null);
    const [confirmation, setConfirmation] = useState('');
    const [busy, setBusy] = useState(false);
    const lock = useRef(false);
    const [error, setError] = useState('');
    const [notice, setNotice] = useState(initialFilename ? `${initialFilename} karşılaştırması yüklü.` : '');
    const [stale, setStale] = useState(false);
    const visibleRows: Row[] =
        source === 'xml' ? (Array.isArray(result?.[category]) ? (result![category] as Row[]) : []) : rows;
    const tableRows: (Row & { _index: number })[] = visibleRows
        .slice((page - 1) * 100, page * 100)
        .map((r, i) => ({ ...r, _index: (page - 1) * 100 + i }));
    const canAdd = canWrite && (source === 'text' || (source === 'xml' && category === 'uyap_only'));
    const canManage =
        canWrite && (source === 'search' || (source === 'xml' && category === 'application_only'));
    const keyOf = (row: Row) => (canAdd ? itemKey(row) : String(row.id ?? ''));
    const duplicateTcs = new Set(
        Array.isArray(result?.uyap_duplicates)
            ? (result!.uyap_duplicates as Row[]).map((r) => String(r.tc))
            : [],
    );
    const selectable = (row: Row) =>
        !stale &&
        (canAdd
            ? !!itemKey(row) && !(source === 'xml' && duplicateTcs.has(String(row.tc)))
            : canManage && Number(row.id) > 0);
    const clearSelection = () => {
        setSelected([]);
        setPreview(null);
        setConfirmation('');
        setPage(1);
    };

    async function request(work: () => Promise<void>) {
        if (lock.current) return;
        lock.current = true;
        setBusy(true);
        onActivity(true);
        setError('');
        setNotice('');
        try {
            await work();
        } catch (e) {
            setError(e instanceof Error ? e.message : 'İşlem yapılamadı. Girdileriniz korunuyor.');
        } finally {
            lock.current = false;
            setBusy(false);
            onActivity(false);
        }
    }
    async function readText(add: boolean) {
        await request(async () => {
            const r = await writeJson(baseUrl, { action: add ? 'text-preview' : 'search', text }, 'POST');
            if (!r.ok) throw new Error(message(r.data));
            const data = r.data as Row;
            setRows((add ? data.records : data.people) as Row[]);
            setReview((data.review ?? []) as Row[]);
            setHasResults(true);
            setSource(add ? 'text' : 'search');
            setStale(false);
            clearSelection();
        });
    }
    async function compare() {
        if (!file) return;
        await request(async () => {
            const data = new FormData();
            data.set('file', file);

            const response = await portalFetch(baseUrl, {
                method: 'POST',
                credentials: 'same-origin',
                headers: { Accept: 'application/json' },
                body: data,
            });
            if (!response.headers.get('content-type')?.includes('application/json'))
                throw new Error('Oturumu kontrol edin. Seçili dosyanız korunuyor.');
            const dataResult = await response.json();
            if (!response.ok) throw new Error(message(dataResult));
            setHasResults(true);
            setResult(dataResult.result);
            setSnapshot(dataResult.snapshot);
            setSource('xml');
            setCategory('uyap_only');
            setReview([]);
            setStale(false);
            clearSelection();
            setNotice(`${dataResult.filename} karşılaştırıldı.`);
        });
    }
    async function prepare(kind: 'add' | 'delete' | 'status') {
        if (!canWrite || stale || !selected.length) return;
        if (kind === 'add') {
            setPreview({ kind, count: selected.length });
            setConfirmation('');
            return;
        }
        await request(async () => {
            const r = await writeJson(
                baseUrl,
                {
                    action: kind + '-preview',
                    ids: selected.map(Number),
                    status: kind === 'status' ? status : null,
                    xml_only: source === 'xml',
                    ...(source === 'xml' ? { snapshot } : {}),
                },
                'POST',
            );
            if (!r.ok) throw new Error(message(r.data));
            setPreview({ ...r.data, kind } as typeof preview);
            setConfirmation('');
        });
    }
    async function apply() {
        if (!canWrite || stale || !preview) return;
        const current = preview;
        await request(async () => {
            const payload =
                current.kind === 'add'
                    ? { action: source === 'text' ? 'text-add' : 'add', text, selected }
                    : {
                          action: current.kind + '-apply',
                          ids: selected.map(Number),
                          status: current.kind === 'status' ? status : null,
                          token: current.token,
                          confirmation: confirmation || null,
                          xml_only: source === 'xml',
                      };
            const r = await writeJson(
                baseUrl,
                { ...payload, ...(source === 'xml' ? { snapshot } : {}) },
                'POST',
            );
            if (!r.ok) {
                setPreview(null);
                throw new Error(message(r.data));
            }
            clearSelection();
            setStale(true);
            const data = r.data as Row;
            setNotice(
                current.kind === 'add'
                    ? `${data.created} kişi eklendi, ${data.skipped} mevcut kayıt atlandı.`
                    : `${data.count} kayıt işlendi.`,
            );
            router.reload();
        });
    }
    function changeText(next: string) {
        setText(next);
        if (source === 'text') setStale(true);
        setPreview(null);
        setConfirmation('');
    }
    return (
        <section className={'home-bulk-operations' + (hasResults ? ' has-results' : '')}>
            <header>
                <h2>Toplu İşlemler</h2>
                <p>Metin, TC veya UYAP XML ile kayıtları tek sonuç tablosunda yönetin.</p>
            </header>
            <div className="bulk-tabs" role="tablist" aria-label="Toplu işlem yöntemi">
                {(['manual', 'xml'] as const).map((t, i) => (
                    <button
                        id={`home-bulk-tab-${t}`}
                        key={t}
                        type="button"
                        role="tab"
                        aria-selected={tab === t}
                        aria-controls={`home-bulk-panel-${t}`}
                        tabIndex={tab === t ? 0 : -1}
                        disabled={busy}
                        className={tab === t ? 'is-active' : ''}
                        onClick={() => setTab(t)}
                        onKeyDown={(e) => {
                            if (['ArrowLeft', 'ArrowRight', 'Home', 'End'].includes(e.key)) {
                                e.preventDefault();
                                const next =
                                    e.key === 'Home'
                                        ? 'manual'
                                        : e.key === 'End'
                                          ? 'xml'
                                          : i === 0
                                            ? 'xml'
                                            : 'manual';
                                setTab(next);
                                document.getElementById(`home-bulk-tab-${next}`)?.focus();
                            }
                        }}
                    >
                        {t === 'manual' ? 'Metin / TC İşlemleri' : 'UYAP XML'}
                    </button>
                ))}
            </div>
            <section
                id="home-bulk-panel-manual"
                role="tabpanel"
                aria-labelledby="home-bulk-tab-manual"
                hidden={tab !== 'manual'}
            >
                <details>
                    <summary>Nasıl kullanılır?</summary>
                    <p>
                        Her satıra bir ad, TC veya TC AD SOYAD yazın. Eklemeden önce ayrıştırılan kayıtları
                        seçip onaylayın.
                    </p>
                </details>
                <label className="sr-only" htmlFor="home-bulk-input">
                    Toplu kayıt metni
                </label>
                <textarea
                    id="home-bulk-input"
                    disabled={busy}
                    maxLength={200000}
                    rows={6}
                    placeholder="Her satıra bir TC, ad soyad veya ‘11111111111 AD SOYAD’ yazın."
                    value={text}
                    onChange={(e) => changeText(e.target.value)}
                />
                <div className="row-actions">
                    <button
                        type="button"
                        className="button button--primary"
                        disabled={busy || !text.trim()}
                        onClick={() => void readText(false)}
                    >
                        Ara ve Listele
                    </button>
                    {canWrite && (
                        <button
                            type="button"
                            className="button button--success"
                            disabled={busy || !text.trim()}
                            onClick={() => void readText(true)}
                        >
                            Toplu Kayıt Ekle
                        </button>
                    )}
                </div>
            </section>
            <section
                id="home-bulk-panel-xml"
                role="tabpanel"
                aria-labelledby="home-bulk-tab-xml"
                hidden={tab !== 'xml'}
            >
                <details>
                    <summary>Nasıl kullanılır?</summary>
                    <p>
                        UYAP JasperPrint XML dosyasını seçip karşılaştırın. Kategoriye göre kayıtları
                        inceleyin.
                    </p>
                </details>
                <label htmlFor="home-bulk-xml">UYAP XML dosyası</label>
                <div className="row-actions">
                    <input
                        id="home-bulk-xml"
                        type="file"
                        accept=".xml,text/xml,application/xml"
                        disabled={busy}
                        onChange={(e) => {
                            setFile(e.target.files?.[0] ?? null);
                            if (source === 'xml') setStale(true);
                            setPreview(null);
                        }}
                    />
                    <button
                        type="button"
                        className="button button--primary"
                        disabled={busy || !file}
                        onClick={() => void compare()}
                    >
                        XML’i Karşılaştır
                    </button>
                </div>
            </section>
            {busy && <p role="status">İşlem sürüyor…</p>}
            {error && (
                <p role="alert" className="form-error">
                    {error}
                </p>
            )}
            {notice && <p role="status">{notice}</p>}
            <section className="bulk-common-results" hidden={!hasResults}>
                <p className="muted">
                    {stale
                        ? 'Kayıtlar veya girdiler değişti. Aramayı / karşılaştırmayı yenileyin.'
                        : 'Arama veya XML karşılaştırması yaptığınızda sonuçlar burada gösterilir.'}
                </p>
                {source === 'xml' && result && (
                    <div className="bulk-summary">
                        {Object.entries(categories).map(([key, label]) => (
                            <button
                                type="button"
                                key={key}
                                className={'button' + (category === key ? ' button--primary' : '')}
                                disabled={busy}
                                onClick={() => {
                                    setCategory(key);
                                    clearSelection();
                                }}
                            >
                                {label} · {Array.isArray(result[key]) ? (result[key] as Row[]).length : 0}
                            </button>
                        ))}
                    </div>
                )}
                <div className="row-actions">
                    <strong>
                        {source === 'xml'
                            ? categories[category]
                            : source === 'text'
                              ? 'Eklenecek metin kayıtları'
                              : 'Arama sonuçları'}
                    </strong>
                    {source === 'xml' && (
                        <label>
                            Göster:{' '}
                            <select
                                aria-label="Sonuç kategorisi"
                                disabled={busy}
                                value={category}
                                onChange={(e) => {
                                    setCategory(e.target.value);
                                    clearSelection();
                                }}
                            >
                                {Object.entries(categories).map(([k, label]) => (
                                    <option key={k} value={k}>
                                        {label}
                                    </option>
                                ))}
                            </select>
                        </label>
                    )}
                    <span>
                        {visibleRows.length} kayıt · {selected.length} kayıt seçili
                    </span>
                </div>
                {(canAdd || canManage) && visibleRows.length > 0 && (
                    <div className="row-actions">
                        <label>
                            <input
                                type="checkbox"
                                aria-label="Tüm sonuçları seç"
                                disabled={busy || stale}
                                checked={
                                    visibleRows.some(selectable) &&
                                    visibleRows.filter(selectable).every((r) => selected.includes(keyOf(r)))
                                }
                                onChange={(e) => {
                                    setSelected(
                                        e.target.checked ? visibleRows.filter(selectable).map(keyOf) : [],
                                    );
                                    setPreview(null);
                                }}
                            />{' '}
                            Tüm sonuçları seç
                        </label>
                        {canAdd && (
                            <button
                                type="button"
                                className="button button--success"
                                disabled={busy || stale || !selected.length}
                                onClick={() => void prepare('add')}
                            >
                                Seçilenleri Ekle
                            </button>
                        )}
                        {canManage && (
                            <>
                                <button
                                    type="button"
                                    className="button button--danger"
                                    disabled={busy || stale || !selected.length}
                                    onClick={() => void prepare('delete')}
                                >
                                    Seçilenleri Sil
                                </button>
                                <select
                                    aria-label="Toplu hedef durum"
                                    disabled={busy}
                                    value={status}
                                    onChange={(e) => {
                                        setStatus(e.target.value);
                                        setPreview(null);
                                    }}
                                >
                                    <option value="">Durum seçin</option>
                                    {statuses.map((s) => (
                                        <option key={s.id} value={s.id}>
                                            {s.label}
                                        </option>
                                    ))}
                                </select>
                                <button
                                    type="button"
                                    className="button button--primary"
                                    disabled={busy || stale || !selected.length || !status}
                                    onClick={() => void prepare('status')}
                                >
                                    Durumu Uygula
                                </button>
                            </>
                        )}
                    </div>
                )}
                <DataTable
                    sequenceOffset={(page - 1) * 100}
                    rows={tableRows}
                    columns={[
                        {
                            key: 'selection',
                            label: 'Seç',
                            value: (r) => selected.includes(keyOf(r)),
                            render: (r) =>
                                selectable(r) ? (
                                    <input
                                        type="checkbox"
                                        aria-label={`${name(r) || r.tc} seç`}
                                        disabled={busy}
                                        checked={selected.includes(keyOf(r))}
                                        onChange={(e) => {
                                            const key = keyOf(r);
                                            setSelected(
                                                e.target.checked
                                                    ? [...selected, key]
                                                    : selected.filter((s) => s !== key),
                                            );
                                            setPreview(null);
                                        }}
                                    />
                                ) : null,
                        },
                        { key: 'sequence', label: 'Sıra', value: (r) => Number(r._index) + 1 },
                        { key: 'tc', label: 'TC Kimlik', value: (r) => String(r.tc ?? r.tc_identity ?? '') },
                        { key: 'full_name', label: 'Adı Soyadı', kind: 'name', value: name },
                        {
                            key: 'uyap',
                            label: 'UYAP Adı',
                            value: (r) =>
                                String(
                                    r.uyap_adi ??
                                        (source === 'xml' &&
                                        ['application_duplicates', 'invalid_application'].includes(category)
                                            ? null
                                            : r.ad_soyad) ??
                                        '',
                                ),
                        },
                        {
                            key: 'application',
                            label: 'Uygulama Adı',
                            value: (r) =>
                                String(
                                    r.uygulama_adi ??
                                        r.full_name ??
                                        (source === 'xml' &&
                                        ['application_duplicates', 'invalid_application'].includes(category)
                                            ? r.ad_soyad
                                            : null) ??
                                        '',
                                ),
                        },
                        { key: 'work', label: 'Çalışma Yeri', value: (r) => String(r.calisma_yeri ?? '') },
                        {
                            key: 'decision',
                            label: 'Çalışma Kararı',
                            value: (r) => String(r.calisma_karari ?? ''),
                        },
                        {
                            key: 'housing',
                            label: 'Blok / Koğuş / Ranza',
                            value: (r) =>
                                [r.blok ?? r.block, r.kogus ?? r.room, r.ranza ?? r.bed]
                                    .filter((v) => v !== null && v !== undefined && v !== '')
                                    .join(' / '),
                        },
                        {
                            key: 'status',
                            label: 'Durum / Açıklama',
                            value: (r) =>
                                r.adet
                                    ? `Aynı TC ${r.adet} kez kayıtlı${Array.isArray(r.kayit_idleri) ? ' · Kayıtlar: ' + r.kayit_idleri.join(', ') : ''}`
                                    : source === 'xml' && duplicateTcs.has(String(r.tc))
                                      ? 'Mükerrer TC: inceleme gerekir'
                                      : String(
                                            r.neden ??
                                                r.durum ??
                                                r.status ??
                                                (source === 'text' ? 'Yeni kayıt' : ''),
                                        ),
                        },
                    ]}
                    rowKey={(r) => Number(r._index)}
                    caption="Toplu işlem sonuçları"
                />
                {visibleRows.length > 100 && (
                    <div className="row-actions">
                        <button
                            type="button"
                            className="button"
                            disabled={busy || page <= 1}
                            onClick={() => setPage(page - 1)}
                        >
                            Önceki
                        </button>
                        <span>
                            {page} / {Math.ceil(visibleRows.length / 100)}
                        </span>
                        <button
                            type="button"
                            className="button"
                            disabled={busy || page * 100 >= visibleRows.length}
                            onClick={() => setPage(page + 1)}
                        >
                            Sonraki
                        </button>
                    </div>
                )}
            </section>
            {review.length > 0 && (
                <section className="bulk-review">
                    <h3>İnceleme gereken satırlar</h3>
                    {review.map((r, i) => (
                        <p key={i}>
                            Satır {String(r.line)}: {String(r.value)} — {String(r.reason)}
                        </p>
                    ))}
                </section>
            )}
            {preview && (
                <section className="bulk-confirmation" aria-label="Toplu işlem onayı">
                    <h3>
                        {preview.count} kayıt ·{' '}
                        {preview.kind === 'add'
                            ? 'Kayıt ekleme'
                            : preview.kind === 'status'
                              ? 'Durum değişikliği'
                              : 'Kalıcı silme'}
                    </h3>
                    <BulkPreviewChanges key={preview.token} preview={preview} />
                    {!preview.changes &&
                        preview.people?.map((r) => <p key={String(r.id)}>{String(r.name)}</p>)}
                    <p>
                        {preview.kind === 'delete'
                            ? 'Kişi ve bağlı bilgileri kalıcı olarak silinir.'
                            : preview.kind === 'add'
                              ? 'YENİ KAYIT, KARARSIZ, SAĞLIKLI ve yetkili değerlendirme varsayılanlarıyla eklenir. Mevcut TC tekrar eklenmez.'
                              : `Hedef durum: ${statuses.find((s) => String(s.id) === status)?.label}`}
                    </p>
                    {preview.kind === 'delete' ? (
                        <label>
                            Onay için KALICI SİL yazın
                            <input
                                disabled={busy}
                                value={confirmation}
                                onChange={(e) => setConfirmation(e.target.value)}
                            />
                        </label>
                    ) : (
                        <label>
                            <input
                                type="checkbox"
                                disabled={busy}
                                checked={confirmation === 'confirmed'}
                                onChange={(e) => setConfirmation(e.target.checked ? 'confirmed' : '')}
                            />{' '}
                            İşlemi onaylıyorum.
                        </label>
                    )}
                    <div className="row-actions">
                        <button
                            type="button"
                            className="button button--primary"
                            disabled={
                                busy ||
                                stale ||
                                (preview.kind === 'delete'
                                    ? confirmation !== 'KALICI SİL'
                                    : confirmation !== 'confirmed')
                            }
                            onClick={() => void apply()}
                        >
                            İşlemi Onayla
                        </button>
                        <button
                            type="button"
                            className="button"
                            disabled={busy}
                            onClick={() => setPreview(null)}
                        >
                            Vazgeç
                        </button>
                    </div>
                </section>
            )}
        </section>
    );
}
