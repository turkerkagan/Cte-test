import { Head, Link, router, usePage } from '@inertiajs/react';
import { useState } from 'react';
import AppShell from '../components/AppShell';
import { ActiveFilters, QuickSearch } from '../components/ListToolbar';
import DataTable from '../components/DataTable';
import type { Column } from '../lib/table';
import useLiveList from '../lib/useLiveList';
import ListPagination from '../components/ListPagination';
import ModuleFilters from '../components/ModuleFilters';
import ModuleRecordDialog from '../components/ModuleRecordDialog';
import { moduleQueryString, type ModuleQuery, type ModuleFilter } from '../lib/moduleQuery';
type Row = {
    id: number;
    person?: { id: number; tc_identity: string | null; full_name: string | null } | null;
    editUrl?: string;
    values: Record<string, string | number | boolean | null | number[]>;
    option_labels: { field_id: number; option_id: number; label: string }[];
};
type QueryState = ModuleQuery;
type Props = {
    applicationName: string;
    title: string;
    recordMode?: string;
    navigation?: { label: string; url: string }[];
    fields: { id: number; type: string; label: string }[];
    rows: Row[];
    pagination: { page: number; per_page: number; total: number; last_page: number };
    definitionVersion: number;
    baseUrl: string;
    logoutUrl: string;
    canWrite?: boolean;
    createUrl?: string;
    exportUrl?: string;
    printUrl?: string;
    queryState?: QueryState;
    filterOptions?: Record<string, { id: number; label: string }[]>;
};
export default function ModuleRecords({
    applicationName,
    title,
    recordMode = 'standalone',
    navigation,
    fields,
    rows,
    pagination,
    baseUrl,
    canWrite = false,
    createUrl,
    printUrl,
    queryState,
    filterOptions = {},
}: Props) {
    const { errors = {} } = usePage<{ errors: Record<string, string> }>().props;
    const [editorUrl, setEditorUrl] = useState<string | null>(null);
    useLiveList(['rows', 'pagination', 'canWrite'], editorUrl !== null);
    const applied: QueryState = queryState ?? {
        q: '',
        filter_field: null,
        filter_op: 'eq',
        filter_value: '',
        sort_field: null,
        sort_direction: 'asc',
    };
    function visit(page: number, per_page = pagination.per_page, state = applied) {
        const params = new URLSearchParams(moduleQueryString(state));
        params.set('page', String(page));
        params.set('per_page', String(per_page));
        router.visit(`${baseUrl}?${params}`, { preserveState: true, preserveScroll: true });
    }
    function outputUrl(url: string) {
        return url + '?' + moduleQueryString(applied);
    }
    const operatorLabels: Record<string, string> = {
        eq: 'Eşittir',
        gte: 'En az',
        lte: 'En çok',
        contains: 'İçerir',
        empty: 'Boş',
    };
    function sort(key: string) {
        const state = {
            ...applied,
            sort_field: Number(key),
            sort_direction: (applied.sort_field === Number(key) && applied.sort_direction === 'asc'
                ? 'desc'
                : 'asc') as 'asc' | 'desc',
        };
        visit(1, pagination.per_page, state);
    }
    const columns: Column<Row>[] = [
        { key: 'sequence', label: 'ID', kind: 'sequence', value: () => null },
        ...(recordMode !== 'standalone'
            ? [
                  {
                      key: 'tc_identity',
                      label: 'TC Kimlik',
                      kind: 'tc' as const,
                      value: (row: Row) => row.person?.tc_identity,
                  },
                  {
                      key: 'full_name',
                      label: 'Adı Soyadı',
                      kind: 'name' as const,
                      value: (row: Row) => row.person?.full_name,
                  },
              ]
            : []),
        ...fields.map((field) => ({
            key: String(field.id),
            label: field.label,
            sortable: !['select', 'multiselect', 'longtext'].includes(field.type),
            value: (row: Row) => {
                const value = row.values[String(field.id)];
                if (field.type === 'select' || field.type === 'multiselect')
                    return row.option_labels
                        .filter((item) => item.field_id === field.id)
                        .map((item) => item.label)
                        .join(', ');
                return Array.isArray(value) ? value.join(', ') : value;
            },
        })),
    ];
    const activeFilters: ModuleFilter[] = [
        ...(applied.filters ?? []),
        ...(applied.filter_field
            ? [
                  {
                      field: applied.filter_field,
                      op: applied.filter_op as ModuleFilter['op'],
                      value: applied.filter_value,
                  },
              ]
            : []),
    ];
    const chips = [
        ...(applied.q
            ? [
                  {
                      key: 'q',
                      label: 'Arama: ' + applied.q,
                      onRemove: () => visit(1, pagination.per_page, { ...applied, q: '' }),
                  },
              ]
            : []),
        ...(applied.filter_field
            ? [
                  {
                      key: 'field',
                      label: `${fields.find((field) => field.id === applied.filter_field)?.label}: ${operatorLabels[applied.filter_op]} ${applied.filter_value}`,
                      onRemove: () =>
                          visit(1, pagination.per_page, { ...applied, filter_field: null, filter_value: '' }),
                  },
              ]
            : []),
        ...(applied.filters ?? []).map((filter, index) => ({
            key: 'filter-' + index,
            label: `${fields.find((field) => field.id === filter.field)?.label}: ${operatorLabels[filter.op]} ${filter.op === 'empty' ? '' : (filter.value ?? '')}`,
            onRemove: () =>
                visit(1, pagination.per_page, {
                    ...applied,
                    filters: applied.filters?.filter((_, i) => i !== index),
                }),
        })),
    ];
    return (
        <AppShell
            listing
            applicationName={applicationName}
            navigation={navigation}
            pageTitle={title}
            toolbar={
                <ActiveFilters
                    chips={chips}
                    sorted={!!applied.sort_field}
                    onClear={() =>
                        visit(1, pagination.per_page, {
                            q: '',
                            filters: [],
                            filter_field: null,
                            filter_op: 'eq',
                            filter_value: '',
                            sort_field: null,
                            sort_direction: 'asc',
                        })
                    }
                    actions={
                        <>
                            {printUrl && (
                                <Link className="cte-primary-action" href={outputUrl(printUrl)}>
                                    Yazdır / PDF
                                </Link>
                            )}
                            {canWrite && createUrl && (
                                <button
                                    data-portal-command="new-record"
                                    type="button"
                                    className="cte-primary-action"
                                    onClick={() => setEditorUrl(createUrl)}
                                >
                                    Yeni kayıt
                                </button>
                            )}
                        </>
                    }
                />
            }
            search={
                <>
                    <QuickSearch
                        value={applied.q}
                        onSearch={(q) => visit(1, pagination.per_page, { ...applied, q })}
                        label={
                            recordMode === 'standalone'
                                ? 'Modül kayıtlarında ara'
                                : 'Adı Soyadı veya TC ile ara'
                        }
                        placeholder={recordMode === 'standalone' ? 'Hızlı Ara...' : 'Hızlı Ad Soyad Ara...'}
                    />
                    <div className="summary-info">
                        <p>
                            <strong>{title}: </strong>
                            {pagination.total} kayıt
                        </p>
                    </div>
                </>
            }
        >
            <Head title={title} />
            {Object.values(errors).map((error, i) => (
                <p className="form-error" role="alert" key={i}>
                    {error}
                </p>
            ))}
            <ModuleFilters
                fields={fields}
                filters={activeFilters}
                options={filterOptions}
                onApply={(filters) =>
                    visit(1, pagination.per_page, {
                        ...applied,
                        filters,
                        filter_field: null,
                        filter_value: '',
                    })
                }
            />
            <DataTable
                sequenceOffset={(pagination.page - 1) * pagination.per_page}
                rows={rows}
                columns={columns}
                onRowClick={
                    canWrite
                        ? (row) => {
                              if (row.editUrl) setEditorUrl(row.editUrl);
                          }
                        : undefined
                }
                rowDisabled={(row) => canWrite && !row.editUrl}
                rowLabel={(row) => `${row.person?.full_name ?? 'Kayıt'} kaydını aç`}
                rowKey={(row) => row.id}
                caption={title}
                sort={
                    applied.sort_field
                        ? { key: String(applied.sort_field), direction: applied.sort_direction }
                        : null
                }
                onSort={sort}
            />
            <ListPagination
                page={pagination.page}
                pages={pagination.last_page}
                total={pagination.total}
                perPage={pagination.per_page}
                onPage={(page) => visit(page)}
                onPerPage={(count) => visit(1, count)}
            />
            {editorUrl && (
                <ModuleRecordDialog key={editorUrl} url={editorUrl} onClose={() => setEditorUrl(null)} />
            )}
        </AppShell>
    );
}
