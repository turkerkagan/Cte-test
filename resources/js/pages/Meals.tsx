import { portalFetch } from '../lib/http';
import { Head, router } from '@inertiajs/react';
import { useRef, useState } from 'react';
import AppShell from '../components/AppShell';
import PortalDialog from '../components/PortalDialog';
type FileInfo = { id: number; type: string; name: string; mime: string; revision: number };
type Props = {
    applicationName: string;
    navigation?: { label: string; url: string }[];
    baseUrl: string;
    logoutUrl: string;
    week: string;
    currentWeek: string;
    weeks: string[];
    files: FileInfo[];
    canWrite: boolean;
};
const types = ['weekly', 'diet', 'vegetarian'] as const;
const labels = {
    weekly: 'Haftalık Yemek Listesi',
    diet: 'Diyet Yemeği Listesi',
    vegetarian: 'Vejeteryan Yemeği Listesi',
};

export default function Meals(p: Props) {
    const [preview, setPreview] = useState<FileInfo | null>(null);
    const [error, setError] = useState('');
    const [busy, setBusy] = useState(false);
    const [conflict, setConflict] = useState(false);
    const [selected, setSelected] = useState<Record<string, File | null>>({});
    const saving = useRef(false);
    async function save() {
        if (saving.current || conflict || !p.canWrite || !Object.values(selected).some(Boolean)) return;
        saving.current = true;
        setBusy(true);
        setError('');
        try {
            const data = new FormData();
            data.set('week', p.week);
            for (const type of types) {
                data.set(
                    `revisions[${type}]`,
                    String(p.files.find((file) => file.type === type)?.revision ?? 0),
                );
                if (selected[type]) data.set(type, selected[type]!);
            }

            const response = await portalFetch(p.baseUrl, {
                method: 'POST',
                credentials: 'same-origin',
                headers: { Accept: 'application/json' },
                body: data,
            });
            if (!response.headers.get('content-type')?.includes('application/json'))
                throw new Error('Oturumu kontrol edin. Seçtiğiniz dosyalar korunuyor.');
            const result = await response.json();
            if (!response.ok) {
                setConflict(response.status === 409);
                throw new Error(
                    Object.values(result.errors ?? {})
                        .flat()
                        .join(' ') ||
                        result.message ||
                        'Dosyalar kaydedilemedi.',
                );
            }
            await new Promise<void>((resolve) =>
                router.visit(`${p.baseUrl}?week=${encodeURIComponent(p.week)}`, {
                    onFinish: () => resolve(),
                }),
            );
        } catch (e) {
            setError(
                e instanceof Error ? e.message : 'Dosyalar kaydedilemedi. Seçtiğiniz dosyalar korunuyor.',
            );
        } finally {
            saving.current = false;
            setBusy(false);
        }
    }
    return (
        <AppShell
            applicationName={p.applicationName}
            navigation={p.navigation}
            pageTitle="Yemek listeleri"
            toolbar={
                <button className="button" onClick={() => window.print()}>
                    Yazdır
                </button>
            }
            search={
                <div className="form-field">
                    <label htmlFor="meal-week">Hafta başlangıcı</label>
                    <select
                        disabled={busy}
                        id="meal-week"
                        value={p.week}
                        onChange={(e) => router.visit(`${p.baseUrl}?week=${e.target.value}`)}
                    >
                        {Array.from(new Set([p.currentWeek, ...p.weeks, p.week]))
                            .sort()
                            .reverse()
                            .map((week) => (
                                <option key={week}>{week}</option>
                            ))}
                    </select>
                </div>
            }
        >
            <Head title="Haftalık Yemek Listesi" />
            <div className="cte-yemek-container">
                <div className="main-content">
                    <h1 className="page-title">BUCA AÇIK CEZA İNFAZ KURUMU YEMEK LİSTESİ</h1>
                    <div className="week-info">
                        {p.week.split('-').reverse().join('.')} Haftalık Yemek Listesi
                    </div>
                    {p.canWrite && p.week === p.currentWeek && (
                        <form
                            className="upload-section"
                            onSubmit={(e) => {
                                e.preventDefault();
                                void save();
                            }}
                        >
                            <h2 className="section-title">Haftalık Yemek Listesi Yükle</h2>
                            <div className="upload-grid">
                                {types.map((type) => (
                                    <div className="upload-item" key={type}>
                                        <label className="upload-label">
                                            <span className="label-text">{labels[type]}</span>
                                            <input
                                                className="file-input"
                                                type="file"
                                                accept=".pdf,.jpg,.jpeg,.png"
                                                disabled={busy}
                                                onChange={(e) =>
                                                    setSelected({
                                                        ...selected,
                                                        [type]: e.target.files?.[0] ?? null,
                                                    })
                                                }
                                            />
                                            <span className="file-info">
                                                PDF, JPG, JPEG, PNG · en fazla 15 MB
                                            </span>
                                        </label>
                                        {p.files.find((file) => file.type === type) && (
                                            <div className="current-file">
                                                Mevcut: {p.files.find((file) => file.type === type)!.name}
                                            </div>
                                        )}
                                        {selected[type] && (
                                            <p className="selected-file">Seçilen: {selected[type]!.name}</p>
                                        )}
                                    </div>
                                ))}
                            </div>
                            <button
                                className="btn-submit"
                                disabled={busy || conflict || !Object.values(selected).some(Boolean)}
                            >
                                {busy ? 'Dosyalar yükleniyor…' : 'Dosyaları Yükle'}
                            </button>
                        </form>
                    )}
                    <section className="view-section">
                        <h2 className="section-title">Haftalık Yemek Listeleri</h2>
                        {p.files.length ? (
                            <div className="menu-grid">
                                {types.map((type) => {
                                    const file = p.files.find((item) => item.type === type);
                                    return (
                                        <article className="menu-card" key={type}>
                                            <h3 className="menu-title">{labels[type]}</h3>
                                            {file ? (
                                                <>
                                                    {file.mime === 'application/pdf' ? (
                                                        <iframe
                                                            className="menu-pdf"
                                                            title={file.name}
                                                            src={`${p.baseUrl}/dosyalar/${file.id}`}
                                                        />
                                                    ) : (
                                                        <button
                                                            type="button"
                                                            className="meal-preview-button"
                                                            onClick={() => setPreview(file)}
                                                            aria-label={file.name + ' görüntüle'}
                                                        >
                                                            <img
                                                                className="menu-image"
                                                                alt={file.name}
                                                                src={`${p.baseUrl}/dosyalar/${file.id}`}
                                                            />
                                                        </button>
                                                    )}
                                                    <button
                                                        className="button"
                                                        onClick={() => setPreview(file)}
                                                    >
                                                        Tam Ekran Görüntüle
                                                    </button>
                                                </>
                                            ) : (
                                                <p className="no-file">Henüz yüklenmedi</p>
                                            )}
                                        </article>
                                    );
                                })}
                            </div>
                        ) : (
                            <p className="no-data">Bu hafta için henüz yemek listesi yüklenmemiş.</p>
                        )}
                    </section>
                    {error && (
                        <p role="alert" className="form-error">
                            {error}
                        </p>
                    )}
                    {conflict && (
                        <p className="form-error">
                            Liste başka bir işlemde değişti. Dosya seçimleriniz korunuyor.{' '}
                            <button
                                className="button"
                                onClick={() =>
                                    router.reload({
                                        onSuccess: () => {
                                            setConflict(false);
                                            setError('');
                                        },
                                    })
                                }
                            >
                                Güncel listeyi yükle
                            </button>
                        </p>
                    )}
                </div>
            </div>
            {preview && (
                <PortalDialog
                    title={preview.name}
                    className="meal-preview"
                    onClose={() => setPreview(null)}
                    actions={
                        <a
                            className="button"
                            href={`${p.baseUrl}/dosyalar/${preview.id}`}
                            target="_blank"
                            rel="noreferrer"
                        >
                            Aç / Yazdır
                        </a>
                    }
                >
                    {preview.mime === 'application/pdf' ? (
                        <iframe
                            title={preview.name}
                            src={`${p.baseUrl}/dosyalar/${preview.id}`}
                            style={{ width: '100%', height: '68dvh', border: 0 }}
                        />
                    ) : (
                        <img
                            src={`${p.baseUrl}/dosyalar/${preview.id}`}
                            alt={preview.name}
                            style={{ width: '100%' }}
                        />
                    )}
                </PortalDialog>
            )}
        </AppShell>
    );
}
