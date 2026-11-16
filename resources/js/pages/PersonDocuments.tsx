import { portalFetch } from '../lib/http';
import { Head, router, usePage } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import PortalDialog from '../components/PortalDialog';
import PhotoGallery from '../components/PhotoGallery';
import IdentityDesigner from '../components/IdentityDesigner';
import AppShell from '../components/AppShell';
import { ActiveFilters, QuickSearch } from '../components/ListToolbar';
import DataTable from '../components/DataTable';
import { writeJson } from '../lib/workflow';
type Person = {
    id: number;
    name: string;
    tc: string | null;
    uyap: string | null;
    age: number | null;
    reason: string | null;
    ds: string | null;
    release: string | null;
    block: string | null;
    room: string | null;
    status: string | null;
    registered: string | null;
    photo: string | null;
    photoRevision: number;
};
type Props = {
    applicationName: string;
    navigation?: { label: string; url: string }[];
    title: string;
    kind: string;
    baseUrl: string;
    logoutUrl: string;
    people: Person[];
    selected: number[];
    q: string;
    page: number;
    total: number;
    today: string;
    canWrite: boolean;
    from?: string;
    to?: string;
    previewReady?: boolean;
    photoFilter?: string;
};
export default function PersonDocuments(p: Props) {
    return <DocumentPage key={p.kind} {...p} />;
}
function DocumentPage(p: Props) {
    const version = usePage().version;
    const [documentPreview, setDocumentPreview] = useState(Boolean(p.previewReady));
    const [selected, setSelected] = useState<number[]>(p.selected);
    const [manual, setManual] = useState<Record<number, Record<string, string>>>({});
    const [error, setError] = useState('');
    const [busy, setBusy] = useState(false);
    const [camera, setCamera] = useState<number | null>(null);
    const video = useRef<HTMLVideoElement>(null);
    const stream = useRef<MediaStream | null>(null);
    const selectionRequest = useRef<AbortController | null>(null);
    useEffect(
        () => () => {
            selectionRequest.current?.abort();
            stream.current?.getTracks().forEach((t) => t.stop());
        },
        [],
    );
    useEffect(() => {
        setSelected(p.selected);
        setDocumentPreview(Boolean(p.previewReady));
    }, [JSON.stringify(p.selected), p.previewReady, p.page, p.q]);
    async function selectAll() {
        if (busy) return;
        if (p.total > 500) {
            setError(
                'Bir belgede en fazla 500 kişi seçilebilir. Önce arama veya tarih filtresiyle listeyi daraltın.',
            );
            return;
        }
        const abort = new AbortController();
        selectionRequest.current = abort;
        setBusy(true);
        setError('');
        try {
            const ids = new Set<number>();
            for (let page = 1; page <= Math.max(1, Math.ceil(p.total / 50)); page++) {
                const query = new URLSearchParams({
                    q: p.q,
                    page: String(page),
                    from: p.from ?? '',
                    to: p.to ?? '',
                });
                const response = await portalFetch(`${p.baseUrl}?${query}`, {
                    credentials: 'same-origin',
                    headers: {
                        Accept: 'application/json',
                        'X-Inertia': 'true',
                        ...(version ? { 'X-Inertia-Version': version } : {}),
                    },
                    signal: abort.signal,
                });
                if (response.status === 409)
                    throw new Error('Arayüz güncellendi. Sayfayı yenileyip yeniden seçin.');
                if (!response.ok) throw new Error('Kişi listesi alınamadı. Yeniden deneyin.');
                const data = await response.json();
                if (
                    data.component !== 'PersonDocuments' ||
                    data.props?.kind !== p.kind ||
                    !Array.isArray(data.props.people) ||
                    data.props.total !== p.total
                )
                    throw new Error('Liste değişti. Sayfayı yenileyip yeniden seçin.');
                for (const person of data.props.people) {
                    if (!Number.isSafeInteger(person.id) || person.id < 1)
                        throw new Error('Kişi listesi alınamadı.');
                    ids.add(person.id);
                }
            }
            if (ids.size !== p.total || ids.size > 500)
                throw new Error('Liste değişti. Sayfayı yenileyip yeniden seçin.');
            setSelected([...ids]);
            setDocumentPreview(false);
        } catch (cause) {
            if (!abort.signal.aborted)
                setError(cause instanceof Error ? cause.message : 'Kişi listesi alınamadı.');
        } finally {
            if (!abort.signal.aborted) setBusy(false);
            if (selectionRequest.current === abort) selectionRequest.current = null;
        }
    }
    function selectPage() {
        if (busy) return;
        const next = Array.from(new Set([...selected, ...p.people.map((x) => x.id)]));
        if (next.length > 500) {
            setError('Bir belgede en fazla 500 kişi seçilebilir. Seçiminizi daraltın.');
            return;
        }
        setError('');
        setSelected(next);
        setDocumentPreview(false);
    }
    function togglePerson(id: number, checked: boolean) {
        if (busy) return;
        if (checked && selected.length >= 500) {
            setError('Bir belgede en fazla 500 kişi seçilebilir.');
            return;
        }
        setError('');
        setSelected(checked ? [...selected, id] : selected.filter((key) => key !== id));
        setDocumentPreview(false);
    }
    const chosen = p.people.filter((x) => selected.includes(x.id));
    function value(id: number, key: string, defaultValue = '') {
        return manual[id]?.[key] ?? defaultValue;
    }
    function update(id: number, key: string, v: string) {
        setManual((old) => ({ ...old, [id]: { ...old[id], [key]: v } }));
    }
    function field(id: number, key: string, type = 'text', fallback = '') {
        return (
            <input
                aria-label={key}
                type={type}
                value={value(id, key, fallback)}
                onChange={(e) => update(id, key, e.target.value)}
            />
        );
    }
    function yesno(id: number, key: string) {
        return (
            <select
                aria-label={key}
                value={value(id, key, 'Hayır')}
                onChange={(e) => update(id, key, e.target.value)}
            >
                <option>Hayır</option>
                <option>Evet</option>
            </select>
        );
    }
    async function photo(person: Person, file: File | Blob | null) {
        setBusy(true);
        setError('');
        try {
            const url = `${p.baseUrl}/kisiler/${person.id}/foto`;
            if (file) {
                const data = new FormData();
                data.set('revision', String(person.photoRevision));
                data.set('photo', file, 'foto.png');
                const r = await portalFetch(url, {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: { Accept: 'application/json' },
                    body: data,
                });
                const result = await r.json();
                if (!r.ok) throw new Error(result.message ?? 'Fotoğraf kaydedilemedi.');
            } else {
                const r = await writeJson(url, { revision: person.photoRevision }, 'DELETE');
                if (!r.ok) throw new Error(r.data.message ?? 'Fotoğraf silinemedi.');
            }
            router.reload();
        } catch (e) {
            setError(e instanceof Error ? e.message : 'Fotoğraf kaydedilemedi.');
        } finally {
            setBusy(false);
        }
    }
    async function startCamera(id: number) {
        stream.current?.getTracks().forEach((t) => t.stop());
        try {
            stream.current = await navigator.mediaDevices.getUserMedia({ video: true });
            setCamera(id);
            setTimeout(() => {
                if (video.current) video.current.srcObject = stream.current;
            }, 0);
        } catch {
            setError('Kamera açılamadı; tarayıcı iznini kontrol edin.');
        }
    }
    function stopCamera() {
        stream.current?.getTracks().forEach((t) => t.stop());
        stream.current = null;
        setCamera(null);
    }
    function capture() {
        if (!video.current || camera === null) return;
        const c = document.createElement('canvas');
        c.width = video.current.videoWidth;
        c.height = video.current.videoHeight;
        c.getContext('2d')?.drawImage(video.current, 0, 0);
        const person = p.people.find((x) => x.id === camera);
        c.toBlob((b) => {
            if (b && person) void photo(person, b);
            stopCamera();
        }, 'image/png');
    }
    function search(q: string) {
        if (!busy)
            router.get(
                p.baseUrl,
                { q, page: 1, ids: selected, from: p.from, to: p.to, photo: p.photoFilter },
                { preserveState: true, preserveScroll: true },
            );
    }
    const chips = [
        ...(p.q ? [{ key: 'q', label: 'Arama: ' + p.q, onRemove: () => search('') }] : []),
        ...(['from', 'to'] as const).flatMap((key) =>
            p[key]
                ? [
                      {
                          key,
                          label: `${key === 'from' ? 'Başlangıç' : 'Bitiş'}: ${p[key]}`,
                          onRemove: () =>
                              router.get(p.baseUrl, {
                                  q: p.q,
                                  ids: selected,
                                  page: 1,
                                  from: p.from,
                                  to: p.to,
                                  [key]: undefined,
                              }),
                      },
                  ]
                : [],
        ),
    ];
    return (
        <AppShell
            listing
            applicationName={p.applicationName}
            navigation={p.navigation}
            pageTitle={p.title}
            toolbar={
                <ActiveFilters
                    chips={chips}
                    onClear={() => {
                        if (!busy)
                            router.get(
                                p.baseUrl,
                                { q: '', page: 1, ids: selected, photo: p.photoFilter },
                                { preserveState: true, preserveScroll: true },
                            );
                    }}
                    actions={
                        !['kimlik', 'fotograf'].includes(p.kind) && (
                            <button
                                className="cte-primary-action"
                                disabled={!documentPreview || !chosen.length || busy}
                                onClick={() => window.print()}
                            >
                                <i className="fas fa-print" aria-hidden="true" /> Seçilenleri yazdır / PDF
                            </button>
                        )
                    }
                />
            }
            search={
                <>
                    <QuickSearch value={p.q} onSearch={search} disabled={busy} />
                    {!['teblig', 'bakanlik_sevk'].includes(p.kind) && (
                        <div className="summary-info">
                            <p>
                                <strong>{p.title}: </strong>
                                {p.total} kişi · {selected.length} seçili
                            </p>
                        </div>
                    )}
                </>
            }
        >
            <Head title={p.title} />
            {error && (
                <p role="alert" className="form-error">
                    {error}
                </p>
            )}
            {!['fotograf', 'kimlik'].includes(p.kind) && (
                <section className="document-select no-print">
                    <header>
                        <h2>
                            {p.kind === 'teblig'
                                ? 'Müddetname Tebliğ - Kişi Seçimi'
                                : 'Bakanlık Sevk Formu - Kişi Seçimi'}
                        </h2>
                        <div className="row-actions">
                            <button className="button" disabled={busy} onClick={() => void selectAll()}>
                                {busy ? 'Liste seçiliyor…' : 'Tüm listeyi seç'}
                            </button>
                            <button className="button" disabled={busy} onClick={selectPage}>
                                Görünenleri seç
                            </button>
                            <button
                                className="button"
                                onClick={() => {
                                    if (busy) return;
                                    setSelected([]);
                                    setDocumentPreview(false);
                                }}
                            >
                                Temizle
                            </button>
                            <button
                                className="button button--primary"
                                disabled={busy || !selected.length}
                                onClick={() => router.get(p.baseUrl, { ids: selected, preview: 1 })}
                            >
                                {p.kind === 'teblig' ? 'Belge Oluştur' : 'Tablo Oluştur'}
                            </button>
                        </div>
                    </header>
                    <p>
                        {p.total} kişi · {selected.length} / 500 seçili · Seçimler sayfalar arasında korunur.
                    </p>
                    <ul className="document-person-list">
                        {p.people.map((person) => (
                            <li key={person.id}>
                                <label>
                                    <input
                                        aria-label={`${person.name} seç`}
                                        disabled={busy}
                                        type="checkbox"
                                        checked={selected.includes(person.id)}
                                        onChange={(e) => togglePerson(person.id, e.target.checked)}
                                    />
                                    <span>
                                        {person.name} · {person.tc}{' '}
                                        {person.block && `· ${person.block} / ${person.room ?? ''}`}
                                    </span>
                                </label>
                            </li>
                        ))}
                    </ul>
                    <div className="row-actions">
                        <button
                            className="button"
                            disabled={busy || p.page <= 1}
                            onClick={() =>
                                router.get(p.baseUrl, {
                                    q: p.q,
                                    page: p.page - 1,
                                    ids: selected,
                                    from: p.from,
                                    to: p.to,
                                })
                            }
                        >
                            Önceki
                        </button>
                        <span>Sayfa {p.page}</span>
                        <button
                            className="button"
                            disabled={busy || p.page * 50 >= p.total}
                            onClick={() =>
                                router.get(p.baseUrl, {
                                    q: p.q,
                                    page: p.page + 1,
                                    ids: selected,
                                    from: p.from,
                                    to: p.to,
                                })
                            }
                        >
                            Sonraki
                        </button>
                    </div>
                </section>
            )}
            {p.kind === 'teblig' && documentPreview && chosen.length > 0 && (
                <section className="document-output">
                    <h2>MÜDDETNAME TEBLİĞ TEBELLÜĞ BELGESİ</h2>
                    <DataTable
                        emptyCellValue=""
                        rows={chosen}
                        columns={[
                            { key: 'sequence', label: 'Sıra', value: (x) => chosen.indexOf(x) + 1 },
                            { key: 'name', label: 'Adı Soyadı', kind: 'name', value: (x) => x.name },
                            { key: 'uyap', label: 'Dosya No', value: (x) => x.uyap },
                            { key: 'tc', label: 'TC Kimlik', value: (x) => x.tc },
                            {
                                key: 'room',
                                label: 'Blok, Koğuş',
                                value: (x) => `${x.block ?? ''} ${x.room ?? ''}`,
                            },
                            {
                                key: 'deadline',
                                label: 'Müddetname Tarihi',
                                value: (x) => value(x.id, 'Müddetname Tarihi', p.today),
                                render: (x) => field(x.id, 'Müddetname Tarihi', 'date', p.today),
                            },
                            {
                                key: 'subject',
                                label: 'Tebliğ Konusu',
                                value: () => 'Müddetnameyi teslim aldım.',
                            },
                            {
                                key: 'date',
                                label: 'Tarih',
                                value: () => p.today.split('-').reverse().join('.'),
                            },
                            { key: 'signature', label: 'İmza', value: () => '' },
                        ]}
                        rowKey={(x) => x.id}
                        caption="Tebliğ belgesi"
                    />
                </section>
            )}
            {p.kind === 'bakanlik_sevk' && documentPreview && chosen.length > 0 && (
                <section className="document-output">
                    <h2>HÜKÜMLÜ/TUTUKLU SEVK BİLGİ FORMU</h2>
                    <DataTable
                        emptyCellValue=""
                        rows={chosen}
                        columns={[
                            { key: 'sequence', label: 'Sıra No', value: (x) => chosen.indexOf(x) + 1 },
                            { key: 'name', label: 'Adı Soyadı', kind: 'name', value: (x) => x.name },
                            { key: 'uyap', label: 'Uyap No', value: (x) => x.uyap },
                            { key: 'tc', label: 'TC Kimlik', value: (x) => x.tc },
                            { key: 'age', label: 'Yaşı', value: (x) => x.age },
                            {
                                key: 'previous',
                                label: 'Daha Önce Kaldığı Kurumlar',
                                value: (x) => value(x.id, 'Daha önce kaldığı kurumlar'),
                                render: (x) => field(x.id, 'Daha önce kaldığı kurumlar'),
                            },
                            { key: 'reason', label: 'Giriş Nedeni', value: (x) => x.reason },
                            { key: 'ds', label: 'DS Tarihi', value: (x) => x.ds ?? 'Yok' },
                            { key: 'release', label: 'Şartlı Tahliye', value: (x) => x.release ?? 'Yok' },
                            ...[
                                'Disiplin cezası',
                                'Kurumda yakını',
                                'Kronik rahatsızlık',
                                'Devam eden yargılama',
                            ].map((key) => ({
                                key,
                                label: (
                                    {
                                        'Disiplin cezası': 'Disiplin Cezası',
                                        'Kurumda yakını': 'Kurumda Yakını',
                                        'Kronik rahatsızlık': 'Kronik Rahatsızlık',
                                        'Devam eden yargılama': 'Devam Eden Yargılama',
                                    } as Record<string, string>
                                )[key],
                                value: (x: Person) => value(x.id, key, 'Hayır'),
                                render: (x: Person) => yesno(x.id, key),
                            })),
                        ]}
                        rowKey={(x) => x.id}
                        caption="Sevk formu"
                    />
                </section>
            )}
            {p.kind === 'kimlik' && <IdentityDesigner {...p} selected={selected} onSelection={setSelected} />}
            {p.kind === 'fotograf' && (
                <>
                    <PhotoGallery
                        people={p.people}
                        total={p.total}
                        photoFilter={p.photoFilter ?? 'all'}
                        onFilter={(photo) => router.get(p.baseUrl, { q: p.q, page: 1, photo })}
                        canWrite={p.canWrite}
                        busy={busy}
                        error={error}
                        onUpload={(person, file) => {
                            const record = p.people.find((x) => x.id === person.id);
                            if (record) void photo(record, file);
                        }}
                        onCamera={(id) => void startCamera(id)}
                        onRemove={(person) => {
                            const record = p.people.find((x) => x.id === person.id);
                            if (record) void photo(record, null);
                        }}
                    />
                    <div className="row-actions no-print">
                        <button
                            className="button"
                            disabled={p.page <= 1}
                            onClick={() =>
                                router.get(p.baseUrl, { q: p.q, page: p.page - 1, photo: p.photoFilter })
                            }
                        >
                            Önceki
                        </button>
                        <span>
                            {p.page} · {p.total} kişi
                        </span>
                        <button
                            className="button"
                            disabled={p.page * 50 >= p.total}
                            onClick={() =>
                                router.get(p.baseUrl, { q: p.q, page: p.page + 1, photo: p.photoFilter })
                            }
                        >
                            Sonraki
                        </button>
                    </div>
                </>
            )}
            {camera !== null && (
                <PortalDialog
                    title="Kamera ile Fotoğraf Çek"
                    className="photo-dialog"
                    onClose={stopCamera}
                    busy={busy}
                >
                    <div className="editor-panel">
                        <video ref={video} autoPlay playsInline muted />
                        <button className="button" onClick={capture}>
                            Çek ve kaydet
                        </button>
                        <button className="button" onClick={stopCamera}>
                            Kapat
                        </button>
                        {error && (
                            <p className="form-error" role="alert">
                                {error}
                            </p>
                        )}
                    </div>
                </PortalDialog>
            )}
        </AppShell>
    );
}
