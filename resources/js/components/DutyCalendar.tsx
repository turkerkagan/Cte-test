import { todayInIstanbul } from '../lib/format';
import { useEffect, useState } from 'react';
type RecordRow = {
    id: number;
    revision: number;
    values: Record<string, string | number | null>;
    display: Record<string, string>;
    isRead: boolean;
};
export default function DutyCalendar({
    baseUrl,
    onEdit,
    initialMonth,
}: {
    baseUrl: string;
    onEdit?: (row: RecordRow) => void;
    initialMonth?: string;
}) {
    const [month, setMonth] = useState(
        initialMonth?.slice(0, 7) ??
            new Date().getFullYear() + '-' + String(new Date().getMonth() + 1).padStart(2, '0'),
    );
    const [rows, setRows] = useState<RecordRow[]>([]);
    const [error, setError] = useState('');
    const [busy, setBusy] = useState(false);
    const [year, index] = month.split('-').map(Number);
    const days = new Date(year, index, 0).getDate();
    const offset = (new Date(year, index - 1, 1).getDay() + 6) % 7;
    useEffect(() => {
        const abort = new AbortController();
        setBusy(true);
        setError('');
        fetch(
            baseUrl +
                '/takvim?' +
                new URLSearchParams({ from: month + '-01', to: month + '-' + String(days).padStart(2, '0') }),
            { credentials: 'same-origin', headers: { Accept: 'application/json' }, signal: abort.signal },
        )
            .then(async (r) => {
                const d = await r.json();
                if (!r.ok) throw new Error(d.message ?? 'Takvim alınamadı.');
                return d.rows as RecordRow[];
            })
            .then(setRows)
            .catch((e) => {
                if (e.name !== 'AbortError') setError(e.message);
            })
            .finally(() => {
                if (!abort.signal.aborted) setBusy(false);
            });
        return () => abort.abort();
    }, [month, baseUrl]);
    return (
        <section className="duty-calendar">
            <header className="duty-calendar__header">
                <h2>
                    <i className="fas fa-calendar-days" /> Nöbet Takvimi
                </h2>
                <label>
                    Ay{' '}
                    <input
                        aria-label="Nöbet takvimi ayı"
                        type="month"
                        required
                        value={month}
                        onChange={(e) => {
                            if (e.target.value) setMonth(e.target.value);
                        }}
                    />
                </label>
            </header>
            {error && (
                <p className="form-error" role="alert">
                    {error}
                </p>
            )}
            {busy && <p role="status">Takvim yükleniyor…</p>}
            <div className="duty-calendar__grid" aria-busy={busy}>
                {['Pazartesi', 'Salı', 'Çarşamba', 'Perşembe', 'Cuma', 'Cumartesi', 'Pazar'].map((day) => (
                    <div className="duty-calendar__cell duty-calendar__weekday" key={day}>
                        {day}
                    </div>
                ))}
                {Array.from({ length: offset }, (_, i) => (
                    <div className="duty-calendar__cell" key={'empty' + i} />
                ))}
                {Array.from({ length: days }, (_, i) => {
                    const date = month + '-' + String(i + 1).padStart(2, '0');
                    return (
                        <div
                            className={
                                'duty-calendar__cell' + (date === todayInIstanbul() ? ' is-today' : '')
                            }
                            key={date}
                        >
                            <strong>{i + 1}</strong>
                            {rows
                                .filter((r) => String(r.values.date ?? '').slice(0, 10) === date)
                                .map((r) => (
                                    <button key={r.id} onClick={() => onEdit?.(r)} disabled={!onEdit}>
                                        {r.display.user_id || r.display.user_name || 'Personel atanmamış'}
                                        {r.values.deputy && <small> · {r.values.deputy}</small>}
                                    </button>
                                ))}
                        </div>
                    );
                })}
            </div>
        </section>
    );
}
