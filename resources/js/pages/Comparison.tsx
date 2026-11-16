import { portalFetch } from '../lib/http';
import { Head, router } from '@inertiajs/react';
import { useState } from 'react';
import AppShell from '../components/AppShell';
import PersonBatchPanel from '../components/PersonBatchPanel';
import CountComparisonTable from '../components/CountComparisonTable';
import HomeBulkOperations from '../components/HomeBulkOperations';
import ComparisonRecords from '../components/ComparisonRecords';
import { writeJson } from '../lib/workflow';

type Count = { registered: number; away: number; ready: number };
type CountRow = Count & { room: string; sourceRow?: number; key?: string };
type Result = {
    rows?: {
        key: string;
        status: string;
        system: CountRow[];
        file: CountRow[];
        difference: Partial<Count>;
    }[];
    systemTotals?: Count;
    fileTotals?: Count | null;
    summary?: Record<string, number>;
    [key: string]: unknown;
};

type Props = {
    applicationName: string;
    navigation?: { label: string; url: string }[];
    baseUrl: string;
    logoutUrl: string;
    kind: string;
    title: string;
    canImport: boolean;
    statuses: { id: number; label: string }[];
    result: Result | null;
    filename: string | null;
    snapshot?: string | null;
    error: string | null;
};

export default function Comparison(p: Props) {
    const [activeTab, setActiveTab] = useState<'summary' | 'uyap' | 'compare'>('summary');
    const [selected, setSelected] = useState<string[]>([]);
    const [text, setText] = useState('');
    const [textResult, setTextResult] = useState<{
        records: Record<string, unknown>[];
        review: Record<string, unknown>[];
    } | null>(null);
    const [countNotes, setCountNotes] = useState<Record<string, string>>({});
    const [file, setFile] = useState<File | null>(null);
    const [error, setError] = useState(p.error ?? '');
    const [busy, setBusy] = useState(false);

    async function upload() {
        if (!file) return;
        setBusy(true);
        setError('');
        try {
            const data = new FormData();
            data.set('file', file);

            const r = await portalFetch(p.baseUrl, {
                method: 'POST',
                credentials: 'same-origin',
                headers: { Accept: 'application/json' },
                body: data,
            });
            if (!r.headers.get('content-type')?.includes('application/json'))
                throw new Error('Oturumu kontrol edin.');
            const result = await r.json();
            if (!r.ok) {
                setError(result.message ?? 'Dosya okunamadı.');
                router.reload();
                return;
            }
            setActiveTab('compare');
            router.visit(p.baseUrl);
        } catch (e) {
            setError(e instanceof Error ? e.message : 'Dosya okunamadı.');
        } finally {
            setBusy(false);
        }
    }

    async function reset() {
        const r = await writeJson(p.baseUrl, { action: 'reset' }, 'POST');
        if (r.ok) router.visit(p.baseUrl);
        else setError(r.data.message ?? 'Sıfırlanamadı.');
    }

    async function add(action = 'add') {
        setBusy(true);
        setError('');
        try {
            const r = await writeJson(p.baseUrl, { action, selected, text }, 'POST');
            if (!r.ok) {
                setError(r.data.message ?? 'Kayıtlar eklenemedi.');
            } else {
                const data = r.data as unknown as { created: number; skipped: number };
                setError(`${data.created} kişi eklendi, ${data.skipped} mevcut TC atlandı.`);
                setSelected([]);
            }
        } catch (e) {
            setError(e instanceof Error ? e.message : 'İşlem yapılamadı.');
        } finally {
            setBusy(false);
        }
    }

    async function previewText() {
        setBusy(true);
        try {
            const r = await writeJson(p.baseUrl, { action: 'text-preview', text }, 'POST');
            if (r.ok) {
                setTextResult(r.data as unknown as typeof textResult);
                setSelected([]);
            } else {
                setError(r.data.message ?? 'Metin okunamadı.');
            }
        } finally {
            setBusy(false);
        }
    }

    if (p.kind !== 'sayim')
        return (
            <AppShell
                search={null}
                applicationName={p.applicationName}
                navigation={p.navigation}
                pageTitle="Toplu İşlemler"
                toolbar={
                    p.filename ? (
                        <button type="button" disabled={busy} className="button" onClick={() => void reset()}>
                            Dosyayı Kaldır
                        </button>
                    ) : null
                }
            >
                <Head title="Toplu İşlemler" />
                {p.error && (
                    <p role="alert" className="form-error">
                        {p.error}
                    </p>
                )}
                <HomeBulkOperations
                    baseUrl={p.baseUrl}
                    canWrite={p.canImport}
                    statuses={p.statuses}
                    onActivity={setBusy}
                    initialResult={p.result}
                    initialSnapshot={p.snapshot}
                    initialFilename={p.filename}
                />
            </AppShell>
        );

    // Sayım bloklarını gruplandırma (A, B, C Blok)
    const systemRows = p.result?.rows?.flatMap((r) => r.system) ?? [];
    const blocksMap: Record<string, CountRow[]> = {};
    systemRows.forEach((row) => {
        const blockCode = row.room.split('·')[0]?.trim() || 'A';
        if (!blocksMap[blockCode]) blocksMap[blockCode] = [];
        blocksMap[blockCode].push(row);
    });

    const diffCount =
        p.result?.rows?.filter(
            (r) => r.status === 'Fark var' || r.status === 'Yalnız sistemde' || r.status === 'Yalnız dosyada',
        ).length ?? 0;

    return (
        <AppShell
            applicationName={p.applicationName}
            navigation={p.navigation}
            pageTitle={p.title}
            toolbar={
                <div style={{ display: 'flex', alignItems: 'center', gap: '8px', marginLeft: 'auto' }}>
                    <button type="button" className="button" onClick={() => window.print()}>
                        <i className="fas fa-print" aria-hidden="true" /> Yazdır (A4)
                    </button>
                    {p.filename && (
                        <button disabled={busy} className="button" onClick={() => void reset()}>
                            <i className="fas fa-trash" aria-hidden="true" /> Dosyayı Kaldır
                        </button>
                    )}
                </div>
            }
            search={
                <div
                    style={{
                        display: 'flex',
                        alignItems: 'center',
                        justifyContent: 'space-between',
                        width: '100%',
                        flexWrap: 'wrap',
                        gap: '10px',
                    }}
                >
                    {/* 3 SEKMELİ SAYIM NAVİGASYONU */}
                    <div className="tabs-selector" style={{ display: 'flex', gap: '6px' }}>
                        <button
                            type="button"
                            className={`button ${activeTab === 'summary' ? 'button--primary' : ''}`}
                            onClick={() => setActiveTab('summary')}
                        >
                            <i className="fas fa-chart-pie" aria-hidden="true" /> Sayım Özet
                        </button>
                        <button
                            type="button"
                            className={`button ${activeTab === 'uyap' ? 'button--primary' : ''}`}
                            onClick={() => setActiveTab('uyap')}
                        >
                            <i className="fas fa-file-excel" aria-hidden="true" /> UYAP Sayım
                        </button>
                        <button
                            type="button"
                            className={`button ${activeTab === 'compare' ? 'button--primary' : ''}`}
                            onClick={() => setActiveTab('compare')}
                        >
                            <i className="fas fa-code-compare" aria-hidden="true" /> Sayım Kıyasla
                            {diffCount > 0 && (
                                <span
                                    style={{
                                        marginLeft: '6px',
                                        padding: '2px 7px',
                                        borderRadius: '999px',
                                        background: '#ef4444',
                                        color: '#fff',
                                        fontSize: '11px',
                                        fontWeight: 800,
                                    }}
                                >
                                    {diffCount}
                                </span>
                            )}
                        </button>
                    </div>
                </div>
            }
        >
            <Head title={p.title} />

            <div className="cte-sayim-page-stage" style={{ padding: '8px 12px' }}>
                {error && (
                    <p className="form-error" role="alert" style={{ marginBottom: '12px' }}>
                        {error}
                    </p>
                )}

                {/* ==============================================================
             SEKME 1: SAYIM ÖZET PANELİ (CANLI VERİTABANI VE BLOK KARTLARI)
             ============================================================== */}
                {activeTab === 'summary' && p.kind === 'sayim' && (
                    <div id="pane-summary">
                        {/* ÜST İSTATİSTİK BAR */}
                        <div
                            style={{
                                display: 'flex',
                                alignItems: 'center',
                                justifyContent: 'space-between',
                                padding: '12px 16px',
                                marginBottom: '16px',
                                borderRadius: '10px',
                                background: 'var(--cte-surface, #fff)',
                                border: '1px solid var(--cte-border, #d8e2e2)',
                                boxShadow: '0 2px 8px rgba(0,0,0,0.04)',
                                flexWrap: 'wrap',
                                gap: '12px',
                            }}
                        >
                            <div style={{ display: 'flex', gap: '24px', flexWrap: 'wrap' }}>
                                <div>
                                    <span
                                        style={{
                                            fontSize: '11px',
                                            color: 'var(--cte-muted, #687983)',
                                            textTransform: 'uppercase',
                                        }}
                                    >
                                        Toplam Hükümlü
                                    </span>
                                    <div
                                        style={{
                                            fontSize: '20px',
                                            fontWeight: 800,
                                            color: 'var(--cte-primary, #245461)',
                                        }}
                                    >
                                        {p.result?.systemTotals?.registered ?? 0}
                                    </div>
                                </div>
                                <div>
                                    <span
                                        style={{
                                            fontSize: '11px',
                                            color: 'var(--cte-muted, #687983)',
                                            textTransform: 'uppercase',
                                        }}
                                    >
                                        Özel İzinli
                                    </span>
                                    <div
                                        style={{
                                            fontSize: '20px',
                                            fontWeight: 800,
                                            color: 'var(--cte-danger, #a85f67)',
                                        }}
                                    >
                                        {p.result?.systemTotals?.away ?? 0}
                                    </div>
                                </div>
                                <div>
                                    <span
                                        style={{
                                            fontSize: '11px',
                                            color: 'var(--cte-muted, #687983)',
                                            textTransform: 'uppercase',
                                        }}
                                    >
                                        Hazır Mevcut
                                    </span>
                                    <div
                                        style={{
                                            fontSize: '20px',
                                            fontWeight: 800,
                                            color: 'var(--cte-sage, #3f756f)',
                                        }}
                                    >
                                        {p.result?.systemTotals?.ready ?? 0}
                                    </div>
                                </div>
                            </div>
                            <div style={{ fontSize: '12px', color: 'var(--cte-muted, #687983)' }}>
                                <i className="far fa-calendar-days" aria-hidden="true" />{' '}
                                {new Date().toLocaleDateString('tr-TR')}{' '}
                                {new Date().toLocaleTimeString('tr-TR', {
                                    hour: '2-digit',
                                    minute: '2-digit',
                                })}
                            </div>
                        </div>

                        {/* 3 BLOK KART IZGARASI */}
                        <div
                            style={{
                                display: 'grid',
                                gridTemplateColumns: 'repeat(auto-fit, minmax(320px, 1fr))',
                                gap: '14px',
                            }}
                        >
                            {Object.entries(blocksMap).map(([blockName, rows]) => {
                                const bTotal = rows.reduce((a, b) => a + b.registered, 0);
                                const bAway = rows.reduce((a, b) => a + b.away, 0);
                                const bReady = rows.reduce((a, b) => a + b.ready, 0);

                                return (
                                    <div
                                        key={blockName}
                                        style={{
                                            border: '1px solid var(--cte-border, #d8e2e2)',
                                            borderRadius: '10px',
                                            background: 'var(--cte-surface, #fff)',
                                            overflow: 'hidden',
                                            boxShadow: '0 2px 8px rgba(0,0,0,0.03)',
                                        }}
                                    >
                                        <div
                                            style={{
                                                padding: '10px 14px',
                                                background:
                                                    'linear-gradient(135deg, var(--cte-primary-deep, #17394b), var(--cte-primary, #245461))',
                                                color: '#fff',
                                                display: 'flex',
                                                justifyContent: 'space-between',
                                                alignItems: 'center',
                                            }}
                                        >
                                            <h2 style={{ margin: 0, fontSize: '14px', fontWeight: 800 }}>
                                                {blockName} BLOK
                                            </h2>
                                            <span style={{ fontSize: '12px', opacity: 0.9 }}>
                                                {rows.length} Koğuş
                                            </span>
                                        </div>

                                        <table className="portal-table">
                                            <thead>
                                                <tr
                                                    style={{
                                                        background: 'var(--cte-surface-soft, #f2f7f7)',
                                                        borderBottom: '1px solid var(--cte-border, #d8e2e2)',
                                                    }}
                                                >
                                                    <th style={{ textAlign: 'left', padding: '6px 10px' }}>
                                                        Koğuş
                                                    </th>
                                                    <th
                                                        style={{
                                                            textAlign: 'center',
                                                            padding: '6px 8px',
                                                            width: '60px',
                                                        }}
                                                    >
                                                        Kişi
                                                    </th>
                                                    <th
                                                        style={{
                                                            textAlign: 'center',
                                                            padding: '6px 8px',
                                                            width: '60px',
                                                            color: 'var(--cte-danger, #a85f67)',
                                                        }}
                                                    >
                                                        Özel İzin
                                                    </th>
                                                    <th
                                                        style={{
                                                            textAlign: 'center',
                                                            padding: '6px 8px',
                                                            width: '60px',
                                                            color: 'var(--cte-sage, #3f756f)',
                                                        }}
                                                    >
                                                        Hazır
                                                    </th>
                                                    <th>Not</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                {rows.map((r, i) => (
                                                    <tr
                                                        key={r.room + i}
                                                        style={{
                                                            borderBottom:
                                                                '1px solid var(--cte-border, #d8e2e2)',
                                                            background:
                                                                i % 2 === 0
                                                                    ? 'var(--cte-row-odd, #fbfdfd)'
                                                                    : 'var(--cte-row-even, #f1f6f5)',
                                                        }}
                                                    >
                                                        <td style={{ padding: '6px 10px', fontWeight: 600 }}>
                                                            {r.room.split('·')[1]?.trim() || r.room}
                                                        </td>
                                                        <td
                                                            style={{
                                                                textAlign: 'center',
                                                                padding: '6px 8px',
                                                                fontWeight: 700,
                                                            }}
                                                        >
                                                            {r.registered}
                                                        </td>
                                                        <td
                                                            style={{
                                                                textAlign: 'center',
                                                                padding: '6px 8px',
                                                                color:
                                                                    r.away > 0
                                                                        ? 'var(--cte-danger, #a85f67)'
                                                                        : undefined,
                                                                fontWeight: 700,
                                                            }}
                                                        >
                                                            {r.away}
                                                        </td>
                                                        <td
                                                            style={{
                                                                textAlign: 'center',
                                                                padding: '6px 8px',
                                                                color: 'var(--cte-sage, #3f756f)',
                                                                fontWeight: 800,
                                                            }}
                                                        >
                                                            {r.ready}
                                                        </td>
                                                        <td>
                                                            <input
                                                                aria-label={r.room + ' sayım notu'}
                                                                value={countNotes[r.room] ?? ''}
                                                                onChange={(e) =>
                                                                    setCountNotes({
                                                                        ...countNotes,
                                                                        [r.room]: e.target.value,
                                                                    })
                                                                }
                                                                title="Bu görünümün yazdırma notu"
                                                            />
                                                        </td>
                                                    </tr>
                                                ))}
                                            </tbody>
                                            <tfoot>
                                                <tr
                                                    style={{
                                                        background: 'var(--cte-surface-soft, #f2f7f7)',
                                                        borderTop: '2px solid var(--cte-border, #d8e2e2)',
                                                        fontWeight: 800,
                                                    }}
                                                >
                                                    <td style={{ padding: '8px 10px' }}>TOPLAM</td>
                                                    <td style={{ textAlign: 'center', padding: '8px 8px' }}>
                                                        {bTotal}
                                                    </td>
                                                    <td
                                                        style={{
                                                            textAlign: 'center',
                                                            padding: '8px 8px',
                                                            color: 'var(--cte-danger, #a85f67)',
                                                        }}
                                                    >
                                                        {bAway}
                                                    </td>
                                                    <td
                                                        style={{
                                                            textAlign: 'center',
                                                            padding: '8px 8px',
                                                            color: 'var(--cte-sage, #3f756f)',
                                                        }}
                                                    >
                                                        {bReady}
                                                    </td>
                                                    <td />
                                                </tr>
                                            </tfoot>
                                        </table>
                                    </div>
                                );
                            })}
                        </div>
                    </div>
                )}

                {/* ==============================================================
             SEKME 2: UYAP SAYIM EXCEL YÜKLE
             ============================================================== */}
                {activeTab === 'uyap' && (
                    <div
                        id="pane-uyap"
                        style={{
                            maxWidth: '680px',
                            margin: '0 auto',
                            padding: '20px',
                            background: 'var(--cte-surface, #fff)',
                            borderRadius: '12px',
                            border: '1px solid var(--cte-border, #d8e2e2)',
                        }}
                    >
                        <h2 style={{ fontSize: '16px', margin: '0 0 12px' }}>
                            <i
                                className="fas fa-file-excel"
                                style={{ color: '#107c41', marginRight: '8px' }}
                                aria-hidden="true"
                            />
                            UYAP Sayım Excel Dosyası Yükle
                        </h2>
                        <p
                            style={{
                                color: 'var(--cte-muted, #687983)',
                                fontSize: '13px',
                                marginBottom: '16px',
                            }}
                        >
                            UYAP Ceza İnfaz Kurumu ekranından indirilen güncel Sayım Çizelgesi (XLS veya XLSX)
                            dosyasını seçerek sisteme yükleyin.
                        </p>

                        <form
                            onSubmit={(e) => {
                                e.preventDefault();
                                void upload();
                            }}
                            style={{ display: 'grid', gap: '14px' }}
                        >
                            <input
                                id="comparison-file"
                                required
                                type="file"
                                accept=".xls,.xlsx"
                                onChange={(e) => setFile(e.target.files?.[0] ?? null)}
                                style={{
                                    padding: '12px',
                                    border: '2px dashed var(--cte-border, #d8e2e2)',
                                    borderRadius: '8px',
                                    background: 'var(--cte-surface-soft, #f2f7f7)',
                                }}
                            />
                            <button
                                className="button button--primary"
                                type="submit"
                                disabled={busy || !file}
                                style={{ justifySelf: 'start', padding: '9px 20px' }}
                            >
                                {busy ? 'Yükleniyor…' : 'Dosyayı Yükle ve Kıyasla'}
                            </button>
                        </form>

                        {p.filename && (
                            <div
                                style={{
                                    marginTop: '20px',
                                    padding: '12px',
                                    borderRadius: '8px',
                                    background: '#dcfce7',
                                    color: '#15803d',
                                    fontSize: '13px',
                                }}
                            >
                                <i className="fas fa-circle-check" style={{ marginRight: '6px' }} />
                                Yüklü aktif dosya: <strong>{p.filename}</strong>
                            </div>
                        )}
                    </div>
                )}

                {/* ==============================================================
             SEKME 3: SAYIM KIYASLAMA VE FARK TABLOSU
             ============================================================== */}
                {(activeTab === 'compare' || p.kind !== 'sayim') && (
                    <div id="pane-compare">
                        {p.kind !== 'sayim' && (
                            <PersonBatchPanel
                                baseUrl={p.baseUrl}
                                canWrite={p.canImport}
                                statuses={p.statuses}
                                xmlOnly={p.result?.application_only as Record<string, unknown>[] | undefined}
                            />
                        )}

                        {p.kind !== 'sayim' && p.canImport && (
                            <details className="editor-panel no-print">
                                <summary>Metinden kişi ekleme</summary>
                                <p>Her satır: TC ve adı soyadı veya yalnız adı soyadı.</p>
                                <textarea
                                    aria-label="Toplu kişi metni"
                                    maxLength={200000}
                                    value={text}
                                    onChange={(e) => {
                                        setText(e.target.value);
                                        setTextResult(null);
                                        setSelected([]);
                                    }}
                                />
                                <button className="button" disabled={busy} onClick={() => void previewText()}>
                                    Metni incele
                                </button>
                                {textResult && (
                                    <>
                                        <ComparisonRecords
                                            title="Metin kayıtları"
                                            rows={textResult.records}
                                            selectable
                                            selected={selected}
                                            onSelect={(tc, checked) =>
                                                setSelected(
                                                    checked
                                                        ? [...selected, tc]
                                                        : selected.filter((x) => x !== tc),
                                                )
                                            }
                                        />
                                        <ComparisonRecords
                                            title="İnceleme gereken satırlar"
                                            rows={textResult.review}
                                            selected={[]}
                                            onSelect={() => {}}
                                        />
                                        <button
                                            className="button"
                                            disabled={busy || !selected.length}
                                            onClick={() => void add('text-add')}
                                        >
                                            Seçilenleri eklemeyi onayla
                                        </button>
                                    </>
                                )}
                            </details>
                        )}

                        {p.kind !== 'sayim' && p.canImport && !textResult && selected.length > 0 && (
                            <button
                                className="button button--primary no-print"
                                disabled={busy}
                                onClick={() => void add()}
                            >
                                XML'den seçilen {selected.length} kişiyi eklemeyi onayla
                            </button>
                        )}

                        {p.kind === 'sayim' && p.result && (
                            <>
                                <div
                                    style={{
                                        display: 'flex',
                                        gap: '16px',
                                        marginBottom: '14px',
                                        flexWrap: 'wrap',
                                    }}
                                >
                                    <div
                                        style={{
                                            padding: '8px 14px',
                                            borderRadius: '8px',
                                            background: 'var(--cte-surface, #fff)',
                                            border: '1px solid var(--cte-border, #d8e2e2)',
                                            fontSize: '13px',
                                        }}
                                    >
                                        <strong>Sistem: </strong>
                                        Kayıtlı: {p.result.systemTotals?.registered} | İzinli:{' '}
                                        {p.result.systemTotals?.away} | Hazır: {p.result.systemTotals?.ready}
                                    </div>
                                    {p.result.fileTotals && (
                                        <div
                                            style={{
                                                padding: '8px 14px',
                                                borderRadius: '8px',
                                                background: 'var(--cte-surface, #fff)',
                                                border: '1px solid var(--cte-border, #d8e2e2)',
                                                fontSize: '13px',
                                            }}
                                        >
                                            <strong>UYAP Dosyası: </strong>
                                            Kayıtlı: {p.result.fileTotals.registered} | İzinli:{' '}
                                            {p.result.fileTotals.away} | Hazır: {p.result.fileTotals.ready}
                                        </div>
                                    )}
                                </div>

                                <CountComparisonTable rows={p.result.rows ?? []} />
                            </>
                        )}

                        {p.kind !== 'sayim' && p.result && (
                            <>
                                <dl className="security-summary">
                                    {Object.entries(p.result.summary ?? {}).map(([key, count]) => (
                                        <div key={key}>
                                            <dt>{key.replaceAll('_', ' ')}</dt>
                                            <dd>{count}</dd>
                                        </div>
                                    ))}
                                </dl>
                                {Object.entries(p.result)
                                    .filter(([, value]) => Array.isArray(value))
                                    .map(([key, value]) => (
                                        <ComparisonRecords
                                            key={key}
                                            title={key}
                                            rows={value as Record<string, unknown>[]}
                                            selectable={p.canImport && key === 'uyap_only'}
                                            selected={selected}
                                            onSelect={(tc, checked) =>
                                                setSelected(
                                                    checked
                                                        ? [...selected, tc]
                                                        : selected.filter((x) => x !== tc),
                                                )
                                            }
                                        />
                                    ))}
                            </>
                        )}
                    </div>
                )}
            </div>
        </AppShell>
    );
}
