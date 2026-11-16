import { router } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import PortalDialog from './PortalDialog';
import { notify } from './Toast';
import { writeJson } from '../lib/workflow';

type Row = {
    id: number;
    revision: number;
    values: Record<string, string | number | null>;
    display: Record<string, string>;
};
type Draft = { date: string; room_id: string; result: string; staff: string; note: string };
type State = { from?: string; to?: string; block?: string; q?: string; room?: number; page?: number };
const blank = (date: string): Draft => ({ date, room_id: '', result: 'Olumsuzluk yok', staff: '', note: '' });
export default function PartialSearchBoard(p: {
    rows: Row[];
    rooms: { id: number; name: string; block?: string }[];
    canWrite: boolean;
    baseUrl: string;
    queryState: State;
    defaultDateTime?: string;
    summary?: { valid: number; rooms: number; cancelled: number };
    pagination: { page: number; pages: number; total: number };
}) {
    const [draft, setDraft] = useState(() => blank(p.defaultDateTime ?? ''));
    const [filters, setFilters] = useState(p.queryState);
    const [reasons, setReasons] = useState<Record<number, string>>({});
    const [cancelling, setCancelling] = useState<Row | null>(null);
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState('');
    const [conflict, setConflict] = useState(false);
    const lock = useRef(false);
    const applied = JSON.stringify(p.queryState);
    const latestDateTime = useRef(p.defaultDateTime);
    latestDateTime.current = p.defaultDateTime;
    useEffect(() => setFilters(p.queryState), [applied]);
    function query(state: State) {
        return new URLSearchParams(
            Object.entries(state)
                .filter(([, v]) => v !== undefined && v !== null && v !== '')
                .map(([k, v]) => [k, String(v)]),
        ).toString();
    }
    async function mutate(payload: unknown, id?: number) {
        if (lock.current || (id && conflict)) return;
        lock.current = true;
        setBusy(true);
        setError('');
        try {
            const response = await writeJson(id ? `${p.baseUrl}/${id}` : p.baseUrl, payload, 'POST');
            if (!response.ok) {
                setError(
                    response.status === 409
                        ? 'Bu tutanak başka bir işlemde değişti. Güncel listeyi yükleyip işlemi yeniden açın.'
                        : response.data.message ||
                              Object.values(response.data.errors ?? {})
                                  .flat()
                                  .join(' ') ||
                              'İşlem tamamlanamadı.',
                );
                if (response.status === 409) setConflict(true);
                return;
            }
            await new Promise<void>((resolve) => router.reload({ onFinish: () => resolve() }));
            if (id) {
                setCancelling(null);
                setReasons((current) => ({ ...current, [id]: '' }));
            } else setDraft(blank(latestDateTime.current ?? ''));
            notify(id ? 'Tutanak gerekçesiyle iptal edildi.' : 'Arama tutanağı kaydedildi.');
        } catch (cause) {
            setError(cause instanceof Error ? cause.message : 'İşlem kaydedilemedi. Girdileriniz korunuyor.');
        } finally {
            lock.current = false;
            setBusy(false);
        }
    }
    return (
        <section className="cte-kismi-container">
            <h1>Kısmi Arama Tutanakları</h1>
            <p>Her arama ayrı kaydedilir. İptal edilen tutanaklar gerekçesiyle birlikte raporda korunur.</p>
            {error && !cancelling && (
                <p className="form-error" role="alert">
                    {error}
                </p>
            )}
            {p.canWrite && (
                <section className="cte-kismi-panel">
                    <h2>Yeni arama kaydı</h2>
                    <form
                        onSubmit={(event) => {
                            event.preventDefault();
                            void mutate({ values: { ...draft, room_id: Number(draft.room_id) } });
                        }}
                    >
                        <fieldset className="cte-kismi-fields" disabled={busy}>
                            <label>
                                Tarih ve saat
                                <input
                                    type="datetime-local"
                                    required
                                    value={draft.date}
                                    onInput={(event) =>
                                        setDraft({ ...draft, date: event.currentTarget.value })
                                    }
                                    onChange={(event) => setDraft({ ...draft, date: event.target.value })}
                                />
                            </label>
                            <label>
                                Koğuş
                                <select
                                    required
                                    value={draft.room_id}
                                    onChange={(event) => setDraft({ ...draft, room_id: event.target.value })}
                                >
                                    <option value="">Koğuş seçin</option>
                                    {p.rooms.map((room) => (
                                        <option key={room.id} value={room.id}>
                                            {room.name}
                                        </option>
                                    ))}
                                </select>
                            </label>
                            <label>
                                Sonuç
                                <select
                                    required
                                    value={draft.result}
                                    onChange={(event) => setDraft({ ...draft, result: event.target.value })}
                                >
                                    {[
                                        'Olumsuzluk yok',
                                        'Olumsuzluk tespit edildi',
                                        'İşlem tamamlanamadı',
                                    ].map((result) => (
                                        <option key={result}>{result}</option>
                                    ))}
                                </select>
                            </label>
                            <label className="wide">
                                Aramaya katılan görevliler
                                <input
                                    required
                                    maxLength={500}
                                    value={draft.staff}
                                    onChange={(event) => setDraft({ ...draft, staff: event.target.value })}
                                />
                            </label>
                            <label className="wide">
                                Açıklama / bulgular
                                <textarea
                                    rows={3}
                                    maxLength={10000}
                                    value={draft.note}
                                    onChange={(event) => setDraft({ ...draft, note: event.target.value })}
                                />
                            </label>
                        </fieldset>
                        <div className="cte-kismi-actions">
                            <button disabled={busy} type="submit">
                                {busy ? 'Kaydediliyor…' : 'Tutanağı kaydet'}
                            </button>
                        </div>
                    </form>
                </section>
            )}
            <section className="cte-kismi-panel">
                <h2>Rapor</h2>
                <form
                    onSubmit={(event) => {
                        event.preventDefault();
                        setError('');
                        router.visit(`${p.baseUrl}?${query({ ...filters, page: undefined })}`, {
                            onError: (errors) => setError(Object.values(errors).join(' ')),
                        });
                    }}
                >
                    <fieldset className="cte-kismi-fields" disabled={busy}>
                        <label>
                            Başlangıç
                            <input
                                type="date"
                                value={filters.from ?? ''}
                                onInput={(event) =>
                                    setFilters({ ...filters, from: event.currentTarget.value })
                                }
                                onChange={(event) => setFilters({ ...filters, from: event.target.value })}
                            />
                        </label>
                        <label>
                            Bitiş
                            <input
                                type="date"
                                value={filters.to ?? ''}
                                onInput={(event) => setFilters({ ...filters, to: event.currentTarget.value })}
                                onChange={(event) => setFilters({ ...filters, to: event.target.value })}
                            />
                        </label>
                        <label>
                            Blok
                            <select
                                value={filters.block ?? ''}
                                onChange={(event) => setFilters({ ...filters, block: event.target.value })}
                            >
                                <option value="">Tüm bloklar</option>
                                {[
                                    ...new Set(
                                        p.rooms
                                            .map((room) => room.block)
                                            .filter((block): block is string => !!block),
                                    ),
                                ].map((block) => (
                                    <option key={block}>{block}</option>
                                ))}
                            </select>
                        </label>
                    </fieldset>
                    <div className="cte-kismi-actions">
                        <button disabled={busy} type="submit">
                            Raporu göster
                        </button>
                    </div>
                </form>
            </section>
            <div className="cte-kismi-stats" aria-label="Rapor özeti">
                <span>
                    <strong>{p.summary?.valid ?? 0}</strong> geçerli arama
                </span>
                <span>
                    <strong>{p.summary?.rooms ?? 0}</strong> koğuş
                </span>
                <span>
                    <strong>{p.summary?.cancelled ?? 0}</strong> iptal
                </span>
            </div>
            <section className="cte-kismi-panel" style={{ marginTop: 14 }}>
                <h2>Arama geçmişi</h2>
                <div className="cte-kismi-table-wrap">
                    <table className="portal-table">
                        <caption className="sr-only">Arama geçmişi</caption>
                        <thead>
                            <tr>
                                {[
                                    'Tarih / saat',
                                    'Koğuş',
                                    'Görevliler',
                                    'Sonuç',
                                    'Açıklama',
                                    'Kaydeden',
                                    'Durum',
                                ].map((label) => (
                                    <th key={label} scope="col">
                                        {label}
                                    </th>
                                ))}
                            </tr>
                        </thead>
                        <tbody>
                            {p.rows.map((row) => (
                                <tr
                                    key={row.id}
                                    className={
                                        row.values.cancelled_at ? 'cancelled' : 'data-table__record-row'
                                    }
                                    tabIndex={busy ? undefined : 0}
                                    aria-label={`${row.id} numaralı arama tutanağını aç`}
                                    onClick={() => {
                                        if (!busy) {
                                            setCancelling(row);
                                            setConflict(false);
                                            setError('');
                                        }
                                    }}
                                    onKeyDown={(event) => {
                                        if (
                                            !busy &&
                                            event.target === event.currentTarget &&
                                            ['Enter', ' '].includes(event.key)
                                        ) {
                                            event.preventDefault();
                                            setCancelling(row);
                                            setConflict(false);
                                            setError('');
                                        }
                                    }}
                                >
                                    <td>
                                        {String(row.values.date ?? '').replace('T', ' ') +
                                            (String(row.values.date ?? '').length === 16 ? ':00' : '')}
                                    </td>
                                    <td>{row.display.room_id}</td>
                                    <td>{row.display.staff}</td>
                                    <td>{row.display.result}</td>
                                    <td style={{ whiteSpace: 'pre-wrap' }}>{row.display.note}</td>
                                    <td>{row.display.created_by_name}</td>
                                    <td>
                                        {row.values.cancelled_at
                                            ? `İptal · ${row.display.cancel_reason}`
                                            : 'Geçerli'}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                    {!p.rows.length && <p role="status">Kriterlere uygun arama kaydı yok.</p>}
                </div>
                {p.pagination.pages > 1 && (
                    <nav className="cte-kismi-actions" aria-label="Rapor sayfaları">
                        {p.pagination.page > 1 && (
                            <button
                                type="button"
                                onClick={() =>
                                    router.visit(
                                        `${p.baseUrl}?${query({ ...p.queryState, page: p.pagination.page - 1 })}`,
                                    )
                                }
                            >
                                Önceki
                            </button>
                        )}
                        <span>
                            {p.pagination.page} / {p.pagination.pages}
                        </span>
                        {p.pagination.page < p.pagination.pages && (
                            <button
                                type="button"
                                onClick={() =>
                                    router.visit(
                                        `${p.baseUrl}?${query({ ...p.queryState, page: p.pagination.page + 1 })}`,
                                    )
                                }
                            >
                                Sonraki
                            </button>
                        )}
                    </nav>
                )}
            </section>
            {cancelling && (
                <PortalDialog
                    title="Arama tutanağı"
                    className="announcement-edit-dialog"
                    busy={busy}
                    onClose={() => setCancelling(null)}
                    actions={
                        p.canWrite && !cancelling.values.cancelled_at ? (
                            <>
                                <button
                                    type="button"
                                    className="button"
                                    disabled={busy}
                                    onClick={() => setCancelling(null)}
                                >
                                    Vazgeç
                                </button>
                                <button
                                    type="button"
                                    className="button button--danger"
                                    disabled={busy || conflict || !(reasons[cancelling.id] ?? '').trim()}
                                    onClick={() =>
                                        void mutate(
                                            {
                                                action: 'archive',
                                                revision: cancelling.revision,
                                                reason: reasons[cancelling.id],
                                            },
                                            cancelling.id,
                                        )
                                    }
                                >
                                    Tutanağı iptal et
                                </button>
                            </>
                        ) : undefined
                    }
                >
                    <div className="announcement-dialog-content">
                        {error && (
                            <p className="form-error" role="alert">
                                {error}
                            </p>
                        )}
                        <p>
                            <strong>{cancelling.display.room_id}</strong> ·{' '}
                            {String(cancelling.values.date ?? '').replace('T', ' ')}
                        </p>
                        <p>{cancelling.display.staff}</p>
                        <p>{cancelling.display.result}</p>
                        <p style={{ whiteSpace: 'pre-wrap' }}>{cancelling.display.note}</p>
                        {cancelling.values.cancelled_at ? (
                            <p>İptal gerekçesi: {cancelling.display.cancel_reason}</p>
                        ) : (
                            p.canWrite && (
                                <label>
                                    İptal gerekçesi
                                    <input
                                        aria-label={`${cancelling.id} numaralı tutanak iptal gerekçesi`}
                                        required
                                        maxLength={500}
                                        disabled={busy}
                                        value={reasons[cancelling.id] ?? ''}
                                        onChange={(event) =>
                                            setReasons({ ...reasons, [cancelling.id]: event.target.value })
                                        }
                                    />
                                </label>
                            )
                        )}
                        {conflict && (
                            <button
                                type="button"
                                className="button"
                                disabled={busy}
                                onClick={() => {
                                    setBusy(true);
                                    router.reload({ onFinish: () => setBusy(false) });
                                }}
                            >
                                Güncel listeyi yükle
                            </button>
                        )}
                    </div>
                </PortalDialog>
            )}
        </section>
    );
}
