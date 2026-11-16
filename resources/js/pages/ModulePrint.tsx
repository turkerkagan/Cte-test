import { Head, Link } from '@inertiajs/react';
import DataTable from '../components/DataTable';
import type { ModuleQuery } from '../lib/moduleQuery';
import type { Column } from '../lib/table';
type Props = {
    applicationName: string;
    title: string;
    definitionVersion: number;
    baseUrl: string;
    fields: { id: number | string; label: string; type: string; kind?: 'tc' | 'name' }[];
    rows: string[][];
    queryState: ModuleQuery;
};
export default function ModulePrint({
    applicationName,
    title,
    definitionVersion,
    baseUrl,
    fields,
    rows,
    queryState,
}: Props) {
    const records = rows.map((values, index) => ({ sequence: index + 1, values }));
    const columns: Column<{ sequence: number; values: string[] }>[] = [
        { key: 'sequence', label: 'ID', kind: 'sequence', value: () => null },
        ...fields.map((field, index) => ({
            key: String(field.id),
            label: field.label,
            kind: field.kind,
            value: (row: { values: string[] }) => row.values[index],
        })),
    ];
    const labels: Record<string, string> = {
        eq: 'Eşittir',
        gte: 'En az',
        lte: 'En çok',
        contains: 'İçerir',
        empty: 'Boş',
    };
    return (
        <main className="module-print">
            <Head title={`${title} · Yazdır`} />
            <div className="row-actions module-print__actions">
                <Link className="button" href={baseUrl}>
                    Listeye dön
                </Link>
                <button className="button" onClick={() => window.print()}>
                    Yazdır / PDF kaydet
                </button>
            </div>
            <h1>{title}</h1>
            <p>
                {applicationName} · Tanım sürümü {definitionVersion} · {rows.length} kayıt
            </p>
            <p>
                {queryState.q ? `Arama: ${queryState.q}` : 'Arama yok'}
                {queryState.filter_field &&
                    ` · ${fields.find((field) => field.id === queryState.filter_field)?.label}: ${labels[queryState.filter_op]} ${queryState.filter_op === 'empty' ? '' : queryState.filter_value}`}
                {(queryState.filters ?? []).map((filter, index) => (
                    <span key={index}>
                        {' '}
                        · {fields.find((field) => field.id === filter.field)?.label}: {labels[filter.op]}{' '}
                        {filter.op === 'empty' ? '' : filter.value}
                    </span>
                ))}
                {queryState.sort_field &&
                    ` · Sıralama: ${fields.find((field) => field.id === queryState.sort_field)?.label} (${queryState.sort_direction === 'asc' ? 'artan' : 'azalan'})`}
            </p>
            <DataTable rows={records} columns={columns} rowKey={(row) => row.sequence} caption={title} />
        </main>
    );
}
