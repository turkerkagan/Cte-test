import { Head, Link } from '@inertiajs/react';
import DataTable from '../components/DataTable';
import BoardTable from '../components/BoardTable';
import type { Column } from '../lib/table';
import type { Field, State } from '../lib/workflow';
type Props = {
    title: string;
    applicationName: string;
    baseUrl: string;
    workflow?: string;
    fields: (Field & { id: string })[];
    rows: string[][];
    queryState: State;
};
export default function WorkflowPrint({
    title,
    applicationName,
    baseUrl,
    workflow,
    fields,
    rows,
    queryState,
}: Props) {
    if (workflow === 'kurul')
        return (
            <main className="module-print board-print">
                <Head title={title} />
                <div className="row-actions module-print__actions">
                    <Link className="button" href={baseUrl}>
                        Listeye dön
                    </Link>
                    <button className="button" onClick={() => window.print()}>
                        Yazdır / PDF kaydet
                    </button>
                </div>
                <h1>{title}</h1>
                <BoardTable
                    columns={fields
                        .filter((f) => f.id.endsWith('_top'))
                        .map((f) => [f.id.slice(0, -4), f.label.replace(/\s*\(?İsim\)?$/u, '')])}
                    rows={rows.map((values, id) => ({
                        id,
                        values: Object.fromEntries(fields.map((field, i) => [field.id, values[i]])),
                    }))}
                />
            </main>
        );
    const identity = fields.filter((f) => f.kind);
    const other = fields.filter((f) => !f.kind);
    const groups = ['izin_rapor', 'infaz_sevk_raporu', 'custom_rapor', 'izin'].includes(workflow ?? '')
        ? [fields]
        : Array.from({ length: Math.max(1, Math.ceil(other.length / 4)) }, (_, i) => [
              ...identity,
              ...other.slice(i * 4, i * 4 + 4),
          ]);
    const descriptions = (queryState.filters ?? []).map(
        (f) =>
            `${fields.find((field) => field.id === f.field)?.label ?? f.field}: ${({ eq: '=', contains: 'içerir', gte: '≥', lte: '≤', empty: 'boş', in: 'seçilenler', before: 'önce', after: 'sonra', year: 'yıl', month: 'ay' } as Record<string, string>)[f.op]} ${Array.isArray(f.value) ? f.value.join(', ') : (f.value ?? '')}`,
    );
    return (
        <main
            className={
                'module-print workflow-print' +
                (workflow === 'izin_rapor' || workflow === 'izin' ? ' leave-print' : '')
            }
        >
            <Head title={title} />
            <div className="row-actions module-print__actions">
                <Link className="button" href={baseUrl}>
                    Listeye dön
                </Link>
                <button className="button" onClick={() => window.print()}>
                    Yazdır / PDF kaydet
                </button>
            </div>
            {groups.map((group, index) => {
                const columns: Column<{ id: number; values: string[] }>[] = [
                    {
                        key: 'sequence',
                        label:
                            workflow === 'izin_rapor'
                                ? '#'
                                : ['infaz_sevk_raporu', 'custom_rapor'].includes(workflow ?? '')
                                  ? 'Sıra'
                                  : 'ID',
                        kind: 'sequence',
                        value: () => null,
                    },
                    ...group.map((f) => ({
                        key: f.id,
                        label: f.label,
                        kind: f.kind,
                        value: (r: { values: string[] }) =>
                            r.values[fields.findIndex((field) => field.id === f.id)],
                    })),
                ];
                return (
                    <section className="print-section" key={index}>
                        <h1>{title}</h1>
                        <p>
                            {applicationName} · {rows.length} kayıt · Sütun grubu {index + 1} /{' '}
                            {groups.length}
                        </p>
                        <p>
                            {queryState.q && `Arama: ${queryState.q} · `}
                            {descriptions.join(' · ')}
                            {queryState.date && `Tarih: ${queryState.date}`}
                            {queryState.type &&
                                `${queryState.type === 'il_ici' ? 'İl içi' : 'İl dışı'} · Kalan gün ≥ ${queryState.days} ${queryState.type === 'il_disi' ? `· Yaş < ${queryState.age}` : ''} · Hariç: ${queryState.excluded?.join(', ') || 'Yok'}`}
                        </p>
                        <DataTable
                            rows={rows.map((values, id) => ({ id, values }))}
                            columns={columns}
                            rowKey={(r) => r.id}
                            caption={`${title} · ${index + 1}`}
                        />
                    </section>
                );
            })}
        </main>
    );
}
