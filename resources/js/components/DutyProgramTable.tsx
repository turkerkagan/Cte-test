import { useState } from 'react';
import { identityHeadings } from '../lib/table';
import { isRowControl } from '../lib/rowActivation';
import PortalDialog from './PortalDialog';
import RecordHoverCard from './RecordHoverCard';

type Value = string | number | null;
type Row = { id: number; revision: number; values: Record<string, Value>; display: Record<string, string> };
type Props = {
    kind: string;
    rows: Row[];
    candidates: { id: number; name: string }[];
    canWrite: boolean;
    busy: boolean;
    error?: string;
    onSave: (row: Row, values: Record<string, Value>) => Promise<boolean>;
};

function dateLabel(value: Value | undefined, weekday = false): string {
    if (!value || !/^\d{4}-\d{2}-\d{2}$/.test(String(value))) return String(value ?? '');
    return new Intl.DateTimeFormat('tr-TR', {
        day: '2-digit',
        month: '2-digit',
        year: 'numeric',
        ...(weekday ? ({ weekday: 'long' } as const) : {}),
    }).format(new Date(String(value) + 'T12:00:00'));
}

export default function DutyProgramTable(p: Props) {
    // Preserve the opened revision when a background refresh replaces the list.
    const [editing, setEditing] = useState<Row | null>(null);
    const [draft, setDraft] = useState<Record<string, Value>>({});
    const [saveError, setSaveError] = useState('');
    const shift = p.kind === 'vardiyanobet';
    const director = p.kind === 'mudurnobet';
    const groups =
        shift && p.rows.length
            ? p.rows.reduce<Record<string, Row[]>>((result, row) => {
                  const key = String(row.values.date ?? '').slice(0, 7);
                  (result[key] ??= []).push(row);
                  return result;
              }, {})
            : { program: p.rows };

    function edit(row: Row) {
        if (p.busy) return;
        setDraft({ ...row.values });
        setEditing(row);
        setSaveError('');
    }
    async function save() {
        if (!editing || !p.canWrite || p.busy) return;
        const values: Record<string, Value> = shift
            ? { user_id: draft.user_id ?? null }
            : {
                  user_id: draft.user_id ?? null,
                  date: draft.date ?? null,
                  ...(director ? { deputy: draft.deputy ?? null } : {}),
              };
        try {
            if (await p.onSave(editing, values)) setEditing(null);
            else setSaveError('Nöbet kaydedilemedi. Girdileriniz korunuyor.');
        } catch {
            setSaveError('Nöbet kaydedilemedi. Bağlantıyı kontrol edip tekrar deneyin.');
        }
    }
    const currentId = Number(draft.user_id || 0);
    const unavailableCurrent =
        currentId !== 0 && !p.candidates.some((candidate) => candidate.id === currentId);

    return (
        <section className="duty-program-table" aria-label="Nöbet programı">
            {!p.rows.length && (
                <p className="duty-empty" role="status">
                    Henüz nöbet programı oluşturulmadı.
                </p>
            )}
            {Object.entries(groups).map(([month, rows]) => (
                <div key={month} className="duty-month">
                    {shift && month !== 'program' && month && (
                        <h2>
                            {new Intl.DateTimeFormat('tr-TR', { month: 'long', year: 'numeric' }).format(
                                new Date(month + '-01T12:00:00'),
                            )}
                        </h2>
                    )}
                    <div className="table-scroll">
                        <table className="portal-table">
                            <thead>
                                <tr>
                                    <th>{identityHeadings.name}</th>
                                    <th>{shift ? 'Gündüz Nöbet Tarihi' : 'Nöbet Tarihi'}</th>
                                    {shift ? (
                                        <>
                                            <th>Gece Nöbet Tarihi</th>
                                            <th>1. Nöbet İzni</th>
                                            <th>2. Nöbet İzni</th>
                                        </>
                                    ) : (
                                        <>
                                            <th>Nöbet Günü</th>
                                            {director && <th>Vekil Nöbetçi</th>}
                                        </>
                                    )}
                                </tr>
                            </thead>
                            <tbody>
                                {rows.map((row) => (
                                    <tr
                                        key={row.id}
                                        className={
                                            row.values.date === new Date().toLocaleDateString('sv')
                                                ? 'is-today'
                                                : ''
                                        }
                                        tabIndex={p.busy ? undefined : 0}
                                        aria-label={`${row.display.user_id || row.display.user_name || 'Atanmamış'} nöbet kaydını aç`}
                                        style={{ cursor: 'pointer' }}
                                        onClick={(event) => {
                                            if (!isRowControl(event.target)) edit(row);
                                        }}
                                        onKeyDown={(event) => {
                                            if (
                                                event.target === event.currentTarget &&
                                                ['Enter', ' '].includes(event.key)
                                            ) {
                                                event.preventDefault();
                                                edit(row);
                                            }
                                        }}
                                    >
                                        <td>
                                            <RecordHoverCard
                                                name={
                                                    row.display.user_id ||
                                                    row.display.user_name ||
                                                    'Seçilmedi'
                                                }
                                                disabled={p.busy}
                                                onActivate={() => edit(row)}
                                            >
                                                <div>
                                                    <strong>
                                                        {row.display.user_id ||
                                                            row.display.user_name ||
                                                            'Seçilmedi'}
                                                    </strong>
                                                    <p>{dateLabel(row.values.date, true)}</p>
                                                    {director && row.display.deputy && (
                                                        <p>Vekil: {row.display.deputy}</p>
                                                    )}
                                                </div>
                                            </RecordHoverCard>
                                        </td>
                                        <td>{dateLabel(row.values.date, shift)}</td>
                                        {shift ? (
                                            <>
                                                <td>{dateLabel(row.values.night, true)}</td>
                                                <td>{dateLabel(row.values.leave1, true)}</td>
                                                <td>{dateLabel(row.values.leave2, true)}</td>
                                            </>
                                        ) : (
                                            <>
                                                <td>
                                                    {row.values.date
                                                        ? new Intl.DateTimeFormat('tr-TR', {
                                                              weekday: 'long',
                                                          }).format(
                                                              new Date(String(row.values.date) + 'T12:00:00'),
                                                          )
                                                        : ''}
                                                </td>
                                                {director && <td>{row.display.deputy}</td>}
                                            </>
                                        )}
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                </div>
            ))}
            {editing && (
                <PortalDialog
                    title={p.canWrite ? 'Nöbet kaydını düzenle' : 'Nöbet kaydı'}
                    busy={p.busy}
                    onClose={() => setEditing(null)}
                >
                    <section className="editor-panel">
                        <form
                            onSubmit={(event) => {
                                event.preventDefault();
                                void save();
                            }}
                        >
                            <fieldset className="user-fields" disabled={p.busy || !p.canWrite}>
                                <label>
                                    Nöbet personeli
                                    <select
                                        required={!shift}
                                        aria-label={`${editing.id}. nöbet personeli`}
                                        value={draft.user_id ?? ''}
                                        onChange={(event) =>
                                            setDraft({
                                                ...draft,
                                                user_id: event.target.value
                                                    ? Number(event.target.value)
                                                    : null,
                                            })
                                        }
                                    >
                                        <option value="">Seçilmedi</option>
                                        {unavailableCurrent && (
                                            <option value={currentId}>
                                                {editing.display.user_id || editing.display.user_name} (mevcut
                                                atama)
                                            </option>
                                        )}
                                        {p.candidates.map((candidate) => (
                                            <option value={candidate.id} key={candidate.id}>
                                                {candidate.name}
                                            </option>
                                        ))}
                                    </select>
                                </label>
                                {!shift && (
                                    <label>
                                        Nöbet tarihi
                                        <input
                                            required
                                            aria-label={`${editing.id}. nöbet tarihi`}
                                            type="date"
                                            value={draft.date ?? ''}
                                            onChange={(event) =>
                                                setDraft({ ...draft, date: event.target.value })
                                            }
                                        />
                                    </label>
                                )}
                                {shift && (
                                    <p>
                                        {dateLabel(editing.values.date, true)} ·{' '}
                                        {dateLabel(editing.values.night, true)}
                                    </p>
                                )}
                                {director && (
                                    <label>
                                        Vekil nöbetçi
                                        <input
                                            aria-label={`${editing.id}. vekil nöbetçi`}
                                            maxLength={255}
                                            value={draft.deputy ?? ''}
                                            onChange={(event) =>
                                                setDraft({ ...draft, deputy: event.target.value || null })
                                            }
                                        />
                                    </label>
                                )}
                            </fieldset>
                            {(p.error || saveError) && (
                                <p className="form-error" role="alert">
                                    {p.error || saveError}
                                </p>
                            )}
                            {p.canWrite && (
                                <button type="submit" className="button button--primary" disabled={p.busy}>
                                    Kaydet
                                </button>
                            )}
                        </form>
                    </section>
                </PortalDialog>
            )}
        </section>
    );
}
