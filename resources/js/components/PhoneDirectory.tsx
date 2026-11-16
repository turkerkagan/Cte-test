import RecordHoverCard from './RecordHoverCard';
import { useEffect, useState } from 'react';
import { portalUrl } from '../lib/portalUrl';
import { identityHeadings, searchName } from '../lib/table';

type Entry = { id: number; values: Record<string, string | number | null> };
export default function PhoneDirectory({
    compact = false,
    searchQuery,
}: {
    compact?: boolean;
    searchQuery?: string;
}) {
    const [rows, setRows] = useState<Entry[]>([]);
    const [search, setSearch] = useState('');
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState('');
    const [attempt, setAttempt] = useState(0);
    useEffect(() => {
        const abort = new AbortController();
        setLoading(true);
        setError('');
        fetch(portalUrl('/kurum/telefon/rehber'), {
            credentials: 'same-origin',
            headers: { Accept: 'application/json' },
            signal: abort.signal,
        })
            .then(async (r) => {
                if (!r.ok)
                    throw new Error(
                        r.status === 403
                            ? 'Telefon rehberini görüntüleme yetkiniz bulunmuyor.'
                            : 'Telefon rehberi yüklenemedi.',
                    );
                return r.json();
            })
            .then((data) => setRows(data.rows))
            .catch((e) => {
                if (e.name !== 'AbortError') setError(e.message);
            })
            .finally(() => {
                if (!abort.signal.aborted) setLoading(false);
            });
        return () => abort.abort();
    }, [attempt]);
    const filtered = rows.filter((row) =>
        searchName(`${row.values.name ?? ''} ${row.values.phone ?? ''}`, searchQuery ?? search),
    );
    return (
        <div className={`cte-telefon-container${compact ? ' cte-telefon-popup-content' : ''}`}>
            <div className="content telefon-listesi">
                {!compact && <h2>Telefon Rehberi</h2>}
                {searchQuery === undefined && (
                    <div className="search-container">
                        <div className="search-wrapper">
                            <input
                                aria-label="İsim veya telefon numarası ara"
                                type="search"
                                className="search-box"
                                placeholder="İsim veya telefon numarası ara..."
                                value={search}
                                onChange={(e) => setSearch(e.target.value)}
                            />
                            <span className="search-icon" aria-hidden="true">
                                <i className="fas fa-magnifying-glass" />
                            </span>
                        </div>
                    </div>
                )}
                {loading ? (
                    <p role="status">Rehber yükleniyor…</p>
                ) : error ? (
                    <div role="alert">
                        <p className="form-error">{error}</p>
                        <button className="button" onClick={() => setAttempt(attempt + 1)}>
                            Tekrar dene
                        </button>
                    </div>
                ) : (
                    <>
                        <table className="portal-table phone-directory">
                            <thead>
                                <tr>
                                    <th scope="col">{identityHeadings.name}</th>
                                    <th scope="col">Telefon Numarası</th>
                                </tr>
                            </thead>
                            <tbody>
                                {filtered.map((row) => (
                                    <tr key={row.id}>
                                        <td>
                                            <RecordHoverCard name={String(row.values.name ?? '')}>
                                                <div>
                                                    <strong>{row.values.name}</strong>
                                                    <p>{row.values.phone}</p>
                                                </div>
                                            </RecordHoverCard>
                                        </td>
                                        <td className="phone-directory__number">{row.values.phone}</td>
                                    </tr>
                                ))}
                                {!filtered.length && (
                                    <tr>
                                        <td colSpan={2}>
                                            {(searchQuery ?? search)
                                                ? 'Aramaya uygun kayıt bulunamadı.'
                                                : 'Telefon rehberi henüz doldurulmadı.'}
                                        </td>
                                    </tr>
                                )}
                            </tbody>
                        </table>
                        <p className="muted" role="status">
                            {filtered.length} / {rows.length} kayıt
                        </p>
                    </>
                )}
            </div>
        </div>
    );
}
