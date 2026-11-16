import { portalFetch } from '../lib/http';
import { Head, router } from '@inertiajs/react';
import { useRef, useState } from 'react';
import AppShell from '../components/AppShell';
import DataTable from '../components/DataTable';
import { writeJson } from '../lib/workflow';
type Summary = {
    filename: string;
    label: string;
    status: string;
    total_rows?: number;
    matched_rows?: number;
    tc_col?: string;
    name_col?: string;
    error?: string;
};
type Props = {
    applicationName: string;
    navigation?: { label: string; url: string }[];
    title: string;
    baseUrl: string;
    logoutUrl: string;
    result: {
        total_records: number;
        total_columns: number;
        columns: string[];
        preview_rows: unknown[][];
        summaries: Summary[];
    } | null;
};
export default function ExcelMerge(p: Props) {
    const [files, setFiles] = useState<File[]>([]);
    const [busy, setBusy] = useState(false);
    const [dragging, setDragging] = useState(false);
    const [error, setError] = useState('');
    const [summaries, setSummaries] = useState<Summary[]>(p.result?.summaries ?? []);
    const input = useRef<HTMLInputElement>(null);
    function add(incoming: File[]) {
        setError('');
        if (incoming.some((f) => !/^.*\.(?:xls|xlsx|html|htm)$/i.test(f.name) || f.size > 25 * 1024 * 1024)) {
            setError('XLS, XLSX, HTML dosyası seçin. Dosya başına en fazla 25 MB yüklenebilir.');
            return;
        }
        setFiles((old) => {
            const unique = [...old];
            for (const f of incoming)
                if (
                    !unique.some(
                        (x) => x.name === f.name && x.size === f.size && x.lastModified === f.lastModified,
                    )
                )
                    unique.push(f);
            return unique;
        });
    }
    async function merge() {
        setBusy(true);
        setError('');
        try {
            const form = new FormData();
            files.forEach((f) => form.append('files[]', f));
            const r = await portalFetch(p.baseUrl, {
                method: 'POST',
                credentials: 'same-origin',
                headers: { Accept: 'application/json' },
                body: form,
            });
            if (!r.headers.get('content-type')?.includes('application/json'))
                throw new Error('Oturumu kontrol edin.');
            const data = await r.json();
            if (!r.ok) {
                setError(
                    Object.values(data.errors ?? {})
                        .flat()
                        .join(' ') ||
                        data.message ||
                        'Birleştirilemedi.',
                );
                setSummaries(data.summaries ?? []);
            } else router.visit(p.baseUrl);
        } catch (e) {
            setError(e instanceof Error ? e.message : 'Birleştirilemedi.');
        } finally {
            setBusy(false);
        }
    }
    async function reset() {
        setBusy(true);
        setError('');
        try {
            const r = await writeJson(p.baseUrl, { action: 'reset' }, 'POST');
            if (!r.ok) throw new Error(r.data.message ?? 'Sonuç kaldırılamadı.');
            router.visit(p.baseUrl);
        } catch (e) {
            setError(e instanceof Error ? e.message : 'Sonuç kaldırılamadı.');
        } finally {
            setBusy(false);
        }
    }
    return (
        <AppShell
            search={null}
            applicationName={p.applicationName}
            navigation={p.navigation}
            pageTitle={p.title}
            toolbar={
                p.result && (
                    <>
                        <a className="button" href={p.baseUrl + '/indir'}>
                            Birleşik XLSX indir
                        </a>
                        <button className="button" disabled={busy} onClick={() => void reset()}>
                            Sonucu kaldır
                        </button>
                    </>
                )
            }
        >
            <Head title={p.title} />
            <div className="excel-merge-page">
                <h1>Excel Dosyalarını Birleştir</h1>
                <p>
                    TC öncelikli, tekil ad eşleştirmesiyle birleştirme. Farklı TC taşıyan aynı adlar ayrı
                    tutulur. Dosyaların sütunları kaynak başlığıyla korunur.
                </p>
                <form
                    onSubmit={(e) => {
                        e.preventDefault();
                        void merge();
                    }}
                >
                    <div
                        className={'excel-upload-zone' + (dragging ? ' is-dragging' : '')}
                        onDragOver={(e) => {
                            e.preventDefault();
                            if (!busy) setDragging(true);
                        }}
                        onDragLeave={() => setDragging(false)}
                        onDrop={(e) => {
                            e.preventDefault();
                            setDragging(false);
                            if (!busy) add(Array.from(e.dataTransfer.files));
                        }}
                    >
                        <i className="fas fa-file-excel" aria-hidden="true" />
                        <h2>Dosyaları buraya sürükleyin</h2>
                        <label>
                            veya dosya seçin
                            <input
                                ref={input}
                                disabled={busy}
                                multiple
                                type="file"
                                accept=".xls,.xlsx,.html,.htm"
                                onChange={(e) => {
                                    add(Array.from(e.target.files ?? []));
                                    e.target.value = '';
                                }}
                            />
                        </label>
                        <p>2–20 dosya · dosya başına en fazla 25 MB</p>
                    </div>
                    <ul className="excel-file-cards">
                        {files.map((f, i) => (
                            <li key={`${f.name}-${f.lastModified}`}>
                                <i className="fas fa-file-excel" aria-hidden="true" />
                                <span>
                                    {f.name}
                                    <small>{(f.size / 1024 / 1024).toFixed(2)} MB</small>
                                </span>
                                <button
                                    className="button"
                                    type="button"
                                    disabled={busy}
                                    onClick={() => setFiles(files.filter((_, index) => index !== i))}
                                    aria-label={f.name + ' dosyasını listeden kaldır'}
                                >
                                    <i className="fas fa-xmark" aria-hidden="true" />
                                </button>
                            </li>
                        ))}
                    </ul>
                    {files.length > 20 && <p className="form-error">En fazla 20 dosya seçebilirsiniz.</p>}
                    <div className="row-actions">
                        <button
                            className="button button--primary"
                            disabled={busy || files.length < 2 || files.length > 20}
                        >
                            {busy ? 'Birleştiriliyor…' : 'Dosyaları Birleştir'}
                        </button>
                        <button
                            className="button"
                            type="button"
                            disabled={busy || !files.length}
                            onClick={() => setFiles([])}
                        >
                            Dosya Listesini Temizle
                        </button>
                    </div>
                    {busy && (
                        <p role="status">
                            <i className="fas fa-spinner fa-spin" aria-hidden="true" /> Dosyalar işleniyor.
                            Lütfen bekleyin…
                        </p>
                    )}
                </form>
                {error && (
                    <p className="form-error" role="alert">
                        {error}
                    </p>
                )}
                {summaries.length > 0 && (
                    <DataTable
                        rows={summaries.map((x, id) => ({ ...x, id }))}
                        columns={[
                            { key: 'file', label: 'Dosya', value: (x) => x.filename },
                            { key: 'status', label: 'Sonuç', value: (x) => x.error ?? x.status },
                            { key: 'rows', label: 'Okunan Satır', value: (x) => x.total_rows },
                            { key: 'matches', label: 'Eşleşen Satır', value: (x) => x.matched_rows },
                            { key: 'tc_column', label: 'TC Sütunu', value: (x) => x.tc_col },
                            { key: 'name', label: 'Ad Sütunu', value: (x) => x.name_col },
                        ]}
                        rowKey={(x) => x.id}
                        caption="Dosya sonuçları"
                    />
                )}
                {p.result && (
                    <>
                        <h2>
                            {p.result.total_records} kayıt · {p.result.total_columns} sütun
                        </h2>
                        <p>Sonuç bir saat boyunca bu oturumdan indirilebilir.</p>
                        <DataTable
                            rows={p.result.preview_rows.map((values, id) => ({ values, id }))}
                            columns={p.result.columns.map((label, i) => ({
                                key: String(i),
                                label,
                                value: (x: { values: unknown[] }) => String(x.values[i] ?? ''),
                            }))}
                            rowKey={(x) => x.id}
                            caption="İlk 20 kayıt önizlemesi"
                        />
                    </>
                )}
            </div>
        </AppShell>
    );
}
