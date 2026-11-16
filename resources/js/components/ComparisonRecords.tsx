import { useEffect, useState } from 'react';
import DataTable from './DataTable';
import ListPagination from './ListPagination';
import type { Column } from '../lib/table';

type Row = Record<string, unknown>;
type Props = {
    title: string;
    rows: Row[];
    selectable?: boolean;
    selected: string[];
    onSelect: (tc: string, checked: boolean) => void;
};
const identityKeys = ['tc', 'tc_identity', 'full_name', 'name', 'sequence'];

export default function ComparisonRecords(p: Props) {
    const [page, setPage] = useState(1);
    useEffect(() => setPage(1), [p.rows]);
    const extraKeys = Array.from(new Set(p.rows.flatMap((row) => Object.keys(row)))).filter(
        (key) => !identityKeys.includes(key),
    );
    const columns: Column<Row>[] = [
        { key: 'sequence', label: 'ID', kind: 'sequence', value: () => null },
        {
            key: 'tc_identity',
            label: 'TC Kimlik',
            kind: 'tc',
            value: (row) => String(row.tc_identity ?? row.tc ?? ''),
        },
        {
            key: 'full_name',
            label: 'Adı Soyadı',
            kind: 'name',
            value: (row) => String(row.full_name ?? row.name ?? ''),
        },
        ...(p.selectable
            ? [
                  {
                      key: 'select',
                      label: 'Ekle',
                      value: (row: Row) => p.selected.includes(String(row.key ?? row.tc)),
                      render: (row: Row) => (
                          <input
                              aria-label={`${row.tc} seç`}
                              type="checkbox"
                              checked={p.selected.includes(String(row.key ?? row.tc))}
                              onChange={(event) =>
                                  p.onSelect(String(row.key ?? row.tc), event.target.checked)
                              }
                          />
                      ),
                  },
              ]
            : []),
        ...extraKeys.map((key) => ({
            key,
            label: key.replaceAll('_', ' '),
            value: (row: Row) =>
                Array.isArray(row[key]) ? (row[key] as unknown[]).join(', ') : String(row[key] ?? ''),
        })),
    ];
    return (
        <section className="editor-panel">
            <h2>
                {p.title.replaceAll('_', ' ')} · {p.rows.length}
            </h2>
            <DataTable
                rows={p.rows
                    .slice((page - 1) * 100, page * 100)
                    .map((row, index) => ({ ...row, _index: (page - 1) * 100 + index }))}
                columns={columns}
                rowKey={(row) => Number(row._index)}
                sequenceOffset={(page - 1) * 100}
                caption={p.title}
            />
            {p.rows.length > 100 && (
                <ListPagination
                    page={page}
                    pages={Math.ceil(p.rows.length / 100)}
                    total={p.rows.length}
                    onPage={setPage}
                />
            )}
        </section>
    );
}
