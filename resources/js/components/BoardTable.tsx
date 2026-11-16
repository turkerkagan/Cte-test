import RecordHoverCard from './RecordHoverCard';
import { identityHeadings } from '../lib/table';
import { isRowControl } from '../lib/rowActivation';

export const boardColumns = [
    ['discipline', 'DİSİPLİN KURULU'],
    ['administration', 'İDARE VE GÖZLEM KURULU'],
    ['education', 'EĞİTİM KURULU'],
    ['workshop', 'İŞYURDU YÖNETİM KURULU'],
    ['archive', 'ARŞİV AYIKLAMA VE İMHA KOMİSYONU'],
    ['letters', 'MEKTUP OKUMA KOMİSYONU'],
    ['packages', 'KOLİ AÇMA KOMİSYONU'],
    ['books', 'KİTAP SAYIM KOMİSYONU'],
    ['general_acceptance', 'MUAYENE KABUL KOMİSYONU (GENEL BÜTÇE)'],
    ['workshop_acceptance', 'MUAYENE KABUL KOMİSYONU (İŞYURDU MÜDÜRLÜĞÜ)'],
] as const;
type Row = { id: number; values: Record<string, string | number | null>; display?: Record<string, string> };
export default function BoardTable({
    rows,
    columns = boardColumns,
    editing = false,
    busy = false,
    onChange,
    onRowClick,
}: {
    rows: Row[];
    columns?: readonly (readonly [string, string])[];
    editing?: boolean;
    busy?: boolean;
    onChange?: (id: number, key: string, value: string) => void;
    onRowClick?: (row: Row) => void;
}) {
    return (
        <div className="table-scroll">
            <table className="portal-table board-table">
                <thead>
                    <tr>
                        <th>{identityHeadings.sequence}</th>
                        {columns.map(([key, label]) => (
                            <th key={key}>{label}</th>
                        ))}
                    </tr>
                </thead>
                <tbody>
                    {rows.map((row) => (
                        <tr
                            key={row.id}
                            tabIndex={onRowClick && !busy ? 0 : undefined}
                            className={onRowClick ? 'data-table__record-row' : undefined}
                            onClick={(event) => {
                                if (!busy && !isRowControl(event.target)) onRowClick?.(row);
                            }}
                            onKeyDown={(event) => {
                                if (
                                    !busy &&
                                    onRowClick &&
                                    event.target === event.currentTarget &&
                                    ['Enter', ' '].includes(event.key)
                                ) {
                                    event.preventDefault();
                                    onRowClick(row);
                                }
                            }}
                        >
                            <td>{row.values.position}</td>
                            {columns.map(([key, label]) => (
                                <td key={key}>
                                    <div className="board-cell">
                                        {(['top', 'bottom'] as const).map((part, i) => {
                                            const field = key + '_' + part;
                                            return editing ? (
                                                <input
                                                    key={field}
                                                    disabled={busy}
                                                    aria-label={`${row.values.position}. sıra ${label} ${i === 0 ? 'İsim' : 'Ünvan'}`}
                                                    placeholder={i === 0 ? 'İsim' : 'Ünvan'}
                                                    value={row.values[field] ?? ''}
                                                    onChange={(e) =>
                                                        onChange?.(row.id, field, e.target.value)
                                                    }
                                                />
                                            ) : i === 0 ? (
                                                <strong key={field}>
                                                    <RecordHoverCard
                                                        name={String(
                                                            row.display?.[field] ?? row.values[field] ?? '',
                                                        )}
                                                        onActivate={
                                                            onRowClick ? () => onRowClick(row) : undefined
                                                        }
                                                        disabled={busy}
                                                    >
                                                        <div>
                                                            <strong>
                                                                {row.display?.[field] ?? row.values[field]}
                                                            </strong>
                                                            <p>{label}</p>
                                                            <p>
                                                                {row.display?.[key + '_bottom'] ??
                                                                    row.values[key + '_bottom']}
                                                            </p>
                                                        </div>
                                                    </RecordHoverCard>
                                                </strong>
                                            ) : (
                                                <span key={field}>
                                                    {row.display?.[field] ?? row.values[field] ?? ''}
                                                </span>
                                            );
                                        })}
                                    </div>
                                </td>
                            ))}
                        </tr>
                    ))}
                </tbody>
            </table>
        </div>
    );
}
