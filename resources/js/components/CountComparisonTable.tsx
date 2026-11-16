import { identityHeadings } from '../lib/table';

type Counts = { room: string; registered: number; away: number; ready: number };
type Row = {
    key: string;
    status: string;
    system: Counts[];
    file: Counts[];
    difference: Record<string, number> | never[];
};
export default function CountComparisonTable({ rows }: { rows: Row[] }) {
    const keys = ['registered', 'away', 'ready'] as const;
    return (
        <div className="table-scroll">
            <table className="portal-table count-comparison-table">
                <caption>Sayım karşılaştırması</caption>
                <thead>
                    <tr>
                        <th rowSpan={2}>{identityHeadings.sequence}</th>
                        <th rowSpan={2}>Koğuş / Oda</th>
                        <th colSpan={3}>UYGULAMA (SİSTEM)</th>
                        <th colSpan={3}>UYAP SAYIM (EXCEL)</th>
                        <th colSpan={3}>FARKLAR (Sistem - UYAP)</th>
                        <th rowSpan={2}>Durum</th>
                    </tr>
                    <tr>
                        {[
                            'Kayıtlı',
                            'Özel İzin',
                            'Hazır',
                            'Kayıtlı',
                            'Gayrı Mv.',
                            'Hazır',
                            'Kayıtlı Δ',
                            'İzinli Δ',
                            'Hazır Δ',
                        ].map((label, i) => (
                            <th key={i}>{label}</th>
                        ))}
                    </tr>
                </thead>
                <tbody>
                    {rows.map((row, i) => (
                        <tr key={row.key}>
                            <td>{i + 1}</td>
                            <td>{row.key}</td>
                            {(['system', 'file'] as const).flatMap((side) =>
                                keys.map((key) => (
                                    <td key={side + key}>
                                        {row[side].length
                                            ? row[side].map((count, i) => (
                                                  <div key={i} title={count.room}>
                                                      {count[key]}
                                                  </div>
                                              ))
                                            : '—'}
                                    </td>
                                )),
                            )}
                            {keys.map((key) => (
                                <td key={key}>
                                    {key in row.difference
                                        ? (row.difference as Record<string, number>)[key]
                                        : '—'}
                                </td>
                            ))}
                            <td>
                                <span
                                    style={{
                                        color:
                                            row.status === 'Tam uyum'
                                                ? 'var(--cte-sage)'
                                                : 'var(--cte-danger)',
                                        fontWeight: 700,
                                    }}
                                >
                                    {row.status}
                                </span>
                            </td>
                        </tr>
                    ))}
                </tbody>
            </table>
            {!rows.length && <p role="status">Karşılaştırılacak kayıt bulunamadı.</p>}
        </div>
    );
}
