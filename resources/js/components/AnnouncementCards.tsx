import { useEffect, useState } from 'react';
import PortalDialog from './PortalDialog';

export type AnnouncementRow = {
    id: number;
    revision: number;
    values: Record<string, string | number | null>;
    display: Record<string, string>;
    isRead: boolean;
    author?: string;
    createdAt?: string;
};
function dateLabel(value?: string) {
    if (!value) return '';
    const match = /^(\d{4})-(\d{2})-(\d{2})[T ](\d{2}):(\d{2})/.exec(value);
    return match ? `${match[3]}.${match[2]}.${match[1]} ${match[4]}:${match[5]}` : value;
}
export default function AnnouncementCards({
    rows,
    canWrite,
    busy,
    onEdit,
    onReceipt,
    onArchive,
    baseUrl,
}: {
    rows: AnnouncementRow[];
    canWrite: boolean;
    busy: boolean;
    onEdit: (row: AnnouncementRow) => void;
    onReceipt: (row: AnnouncementRow) => void;
    onArchive?: (row: AnnouncementRow) => void;
    baseUrl: string;
}) {
    const [readers, setReaders] = useState<number | null>(null);
    return (
        <>
            <div className="duyurular-listesi">
                {rows.map((row) => (
                    <article
                        className={'duyuru-kart ' + (row.isRead ? 'okundu' : 'duyuru-kart-okunmamis')}
                        key={row.id}
                    >
                        <header className="duyuru-kart-header">
                            <span className="duyuru-username">{row.author ?? 'Kurum Duyurusu'}</span>
                            <time className="duyuru-zaman" dateTime={row.createdAt}>
                                {dateLabel(row.createdAt)}
                            </time>
                        </header>
                        <h2 className="duyuru-baslik">{String(row.values.title ?? '')}</h2>
                        <div className="duyuru-icerik">{String(row.values.body ?? '')}</div>
                        <footer className="duyuru-kart-actions">
                            <button
                                type="button"
                                className="duyuru-okundu-btn"
                                disabled={busy}
                                onClick={() => onReceipt(row)}
                            >
                                <i
                                    aria-hidden="true"
                                    className={'fas fa-' + (row.isRead ? 'envelope-open' : 'check')}
                                />{' '}
                                {row.isRead ? 'Okunmadı Olarak İşaretle' : 'Okundu Olarak İşaretle'}
                            </button>
                            {canWrite && (
                                <>
                                    <button
                                        type="button"
                                        className="action-btn duyuru-btn-duzenle"
                                        disabled={busy}
                                        onClick={() => onEdit(row)}
                                    >
                                        <i className="fas fa-edit" aria-hidden="true" /> Düzenle
                                    </button>
                                    {onArchive && (
                                        <button
                                            type="button"
                                            className="action-btn duyuru-btn-sil"
                                            disabled={busy}
                                            onClick={() => onArchive(row)}
                                        >
                                            <i className="fas fa-box-archive" aria-hidden="true" /> Listeden
                                            Kaldır
                                        </button>
                                    )}
                                    <button
                                        type="button"
                                        className="button"
                                        disabled={busy}
                                        onClick={() => setReaders(row.id)}
                                    >
                                        <i className="fas fa-users" aria-hidden="true" /> Okuyanlar
                                    </button>
                                </>
                            )}
                        </footer>
                    </article>
                ))}
                {!rows.length && (
                    <p className="muted" role="status">
                        Henüz duyuru yok.
                    </p>
                )}
            </div>
            {readers !== null && (
                <AnnouncementReaders
                    url={baseUrl + '/' + readers + '/okuyanlar'}
                    onClose={() => setReaders(null)}
                />
            )}
        </>
    );
}
function AnnouncementReaders({ url, onClose }: { url: string; onClose: () => void }) {
    const [names, setNames] = useState<string[]>([]);
    const [error, setError] = useState('');
    const [loading, setLoading] = useState(true);
    const [retry, setRetry] = useState(0);
    useEffect(() => {
        const abort = new AbortController();
        setLoading(true);
        setError('');
        setNames([]);
        fetch(url, {
            credentials: 'same-origin',
            headers: { Accept: 'application/json' },
            signal: abort.signal,
        })
            .then(async (response) => {
                if (!response.ok) throw new Error('Okuyanlar listesi alınamadı.');
                const result = await response.json();
                if (
                    !Array.isArray(result.readers) ||
                    result.readers.some((name: unknown) => typeof name !== 'string')
                )
                    throw new Error('Okuyanlar listesi alınamadı.');
                return result.readers as string[];
            })
            .then(setNames)
            .catch((cause) => {
                if (cause.name !== 'AbortError') setError(cause.message);
            })
            .finally(() => {
                if (!abort.signal.aborted) setLoading(false);
            });
        return () => abort.abort();
    }, [url, retry]);
    return (
        <PortalDialog title="Duyuruyu Okuyanlar" className="announcement-edit-dialog" onClose={onClose}>
            <div className="announcement-dialog-content" aria-busy={loading}>
                {loading ? (
                    <p role="status">Okuyanlar yükleniyor…</p>
                ) : error ? (
                    <>
                        <p className="form-error" role="alert">
                            {error}
                        </p>
                        <button type="button" className="button" onClick={() => setRetry(retry + 1)}>
                            Tekrar dene
                        </button>
                    </>
                ) : names.length ? (
                    <ul>
                        {names.map((name, index) => (
                            <li key={index}>{name}</li>
                        ))}
                    </ul>
                ) : (
                    <p role="status">Henüz okundu bilgisi yok.</p>
                )}
            </div>
        </PortalDialog>
    );
}
