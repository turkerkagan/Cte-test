import { Head, useForm } from '@inertiajs/react';
import { useMemo, useRef, useState, type FormEvent } from 'react';
import ConflictReview from '../components/ConflictReview';
import { workspaceRequest } from '../lib/workspace';
import AppShell from '../components/AppShell';
import DataTable from '../components/DataTable';
import { searchName, sortRows, type Column, type Sort } from '../lib/table';

type Row = {
    id: number;
    code: string;
    name: string;
    is_active: boolean;
    revision: number;
    assigned_count: number;
    permission_count: number;
    permission_snapshot: string;
};
type Props = {
    applicationName: string;
    navigation?: { label: string; url: string }[];
    title: string;
    kind: string;
    rows: Row[];
    baseUrl: string;
    logoutUrl: string;
};
type Selection = { mode: 'new' | 'edit' | 'delete'; row?: Row };

function Editor({
    selection,
    rows,
    baseUrl,
    close,
    onRemove,
    kind,
}: {
    selection: Selection;
    rows: Row[];
    baseUrl: string;
    close: () => void;
    onRemove: () => void;
    kind: string;
}) {
    const { mode, row } = selection;
    const form = useForm({
        code: row?.code ?? '',
        name: row?.name ?? '',
        is_active: row?.is_active ?? true,
        revision: row?.revision ?? 1,
        assigned_count: row?.assigned_count ?? 0,
        permission_count: row?.permission_count ?? 0,
        permission_snapshot: row?.permission_snapshot ?? '',
        target_id: '',
        target_permission_count: 0,
        target_permission_snapshot: '',
    });
    const project = (value: { code: string; name: string; is_active: boolean }) => ({
        code: value.code,
        name: value.name,
        is_active: value.is_active,
    });
    const base = useRef(project(form.data));
    const conflict = mode === 'edit' && !!form.errors.revision;
    const errors = Object.values(form.errors);
    function submit(event: FormEvent) {
        event.preventDefault();
        if (conflict) return;
        const options = { onSuccess: close, preserveScroll: true };
        if (mode === 'delete') form.delete(`${baseUrl}/${row!.id}`, options);
        else if (mode === 'edit') form.put(`${baseUrl}/${row!.id}`, options);
        else form.post(baseUrl, options);
    }
    return (
        <section className="editor-panel organization-editor" aria-labelledby="editor-title">
            <h2 id="editor-title">
                {mode === 'new'
                    ? kind === 'birimler'
                        ? 'Yeni Birim'
                        : 'Yeni Unvan'
                    : mode === 'edit'
                      ? 'Tanımı düzenle'
                      : 'Tanımı sil'}
                {row && `: ${row.name}`}
            </h2>
            <form onSubmit={submit} className="definition-form">
                <fieldset disabled={form.processing || conflict}>
                    {mode === 'delete' ? (
                        <>
                            <p>
                                {row!.assigned_count} personel bu tanıma atanmış. Personel kayıtları korunur.
                                Aktarılan personelin mevcut oturumları kapatılır.
                            </p>
                            <label htmlFor="transfer-target">
                                Aktarım hedefi{row!.assigned_count > 0 ? ' (zorunlu)' : ' (isteğe bağlı)'}
                            </label>
                            <select
                                autoFocus
                                id="transfer-target"
                                value={form.data.target_id}
                                onChange={(event) => {
                                    const target = rows.find(
                                        (item) => String(item.id) === event.target.value,
                                    );
                                    form.setData((data) => ({
                                        ...data,
                                        target_id: event.target.value,
                                        target_permission_count: target?.permission_count ?? 0,
                                        target_permission_snapshot: target?.permission_snapshot ?? '',
                                    }));
                                }}
                                required={row!.assigned_count > 0}
                            >
                                <option value="">Hedef seçin</option>
                                {rows
                                    .filter((item) => item.id !== row!.id && item.is_active)
                                    .map((item) => (
                                        <option key={item.id} value={item.id}>
                                            {item.name}
                                        </option>
                                    ))}
                            </select>
                            <p>
                                {row!.permission_count} sayfa izni bu tanımla birlikte kaldırılır. Bu izinler
                                aktarım hedefine kopyalanmaz.{' '}
                                {form.data.target_id &&
                                    `Seçili hedefin ${form.data.target_permission_count} mevcut sayfa izni korunur; aktarılan personel hedefin izinlerini devralır.`}{' '}
                                Kişisel izinler ve diğer atama izinleri korunur.
                            </p>
                            <p className="muted">
                                Tanım silinir. Atanmış personel seçilen hedefe aktarılır. İşlem hareket
                                kaydına yazılır.
                            </p>
                        </>
                    ) : (
                        <>
                            <div className="organization-definition-fields">
                                <label htmlFor="definition-code">
                                    Kod
                                    <input
                                        id="definition-code"
                                        value={form.data.code}
                                        onChange={(event) => form.setData('code', event.target.value)}
                                        required
                                        maxLength={50}
                                        autoComplete="off"
                                    />
                                </label>
                                <label htmlFor="definition-name">
                                    {kind === 'birimler' ? 'Birim Adı' : 'Unvan Adı'}
                                    <input
                                        id="definition-name"
                                        value={form.data.name}
                                        onChange={(event) => form.setData('name', event.target.value)}
                                        required
                                        maxLength={120}
                                    />
                                </label>
                            </div>
                            <label className="checkbox-field">
                                <input
                                    type="checkbox"
                                    checked={form.data.is_active}
                                    onChange={(event) => form.setData('is_active', event.target.checked)}
                                />
                                Aktif
                            </label>
                        </>
                    )}
                    {errors.length > 0 && (
                        <div role="alert" className="form-error">
                            {errors.map((error, index) => (
                                <p key={index}>{error}</p>
                            ))}
                        </div>
                    )}
                </fieldset>
                {conflict && row && (
                    <ConflictReview
                        base={base.current}
                        draft={project(form.data)}
                        labels={{ code: 'Kod', name: 'Ad', is_active: 'Aktif' }}
                        load={async (signal) => {
                            const data = await workspaceRequest<{ rows: Row[] }>(baseUrl, undefined, signal);
                            const latest = data.rows.find((item) => item.id === row.id);
                            if (!latest) throw new Error('Tanım bulunamadı.');
                            return { values: project(latest), metadata: latest };
                        }}
                        apply={(merged, latest) => {
                            base.current = project(latest);
                            form.setData(
                                (data) => ({ ...data, ...merged, revision: latest.revision }) as typeof data,
                            );
                            form.clearErrors();
                        }}
                    />
                )}
                <div className="row-actions">
                    <button
                        type="submit"
                        className={`button ${mode === 'delete' ? 'button--danger' : 'button--primary'}`}
                        disabled={form.processing || conflict}
                    >
                        {mode === 'delete' ? 'Silme ve aktarımı onayla' : mode === 'new' ? 'Ekle' : 'Kaydet'}
                    </button>
                    {mode !== 'new' && (
                        <button className="button" type="button" onClick={close} disabled={form.processing}>
                            Vazgeç
                        </button>
                    )}
                </div>
            </form>
            {mode === 'edit' && (
                <button
                    type="button"
                    className="button button--danger"
                    disabled={form.processing}
                    onClick={onRemove}
                >
                    Tanımı sil
                </button>
            )}
        </section>
    );
}

export default function Organization({ applicationName, title, rows, baseUrl, navigation, kind }: Props) {
    const [query, setQuery] = useState('');
    const [sort, setSort] = useState<Sort>(null);
    const [selection, setSelection] = useState<Selection | null>(null);
    const columns = useMemo<Column<Row>[]>(
        () => [
            { key: 'code', label: 'Kod', value: (row) => row.code, sortable: true },
            {
                key: 'name',
                label: kind === 'birimler' ? 'Birim' : kind === 'unvanlar' ? 'Unvan' : 'Rol',
                value: (row) => row.name,
                sortable: true,
            },
            { key: 'assigned_count', label: 'Kullanıcı', value: (row) => row.assigned_count, sortable: true },
            ...(kind === 'birimler'
                ? [
                      {
                          key: 'permission_count',
                          label: 'Sayfa',
                          value: (row: Row) => row.permission_count,
                          sortable: true,
                      },
                  ]
                : []),
            {
                key: 'is_active',
                label: 'Durum',
                value: (row) => (row.is_active ? 'Aktif' : 'Arşiv'),
                sortable: true,
            },
        ],
        [kind],
    );
    const visibleRows = useMemo(
        () =>
            sortRows(
                rows.filter((row) => searchName(`${row.name} ${row.code}`, query)),
                columns,
                sort,
            ),
        [rows, query, columns, sort],
    );
    return (
        <AppShell
            applicationName={applicationName}
            navigation={navigation}
            pageTitle={title}
            toolbar={
                <>
                    <div className="active-filters">
                        <span className="active-filters__label">Aktif filtreler</span>
                        {query.trim() ? (
                            <button type="button" className="filter-chip" onClick={() => setQuery('')}>
                                Arama: {query.trim()} ×
                            </button>
                        ) : (
                            <span className="muted">Filtre uygulanmadı</span>
                        )}
                    </div>
                </>
            }
            search={
                <div className="search-field">
                    <label htmlFor="definition-search">Hızlı ad veya kod ara</label>
                    <input
                        id="definition-search"
                        type="search"
                        value={query}
                        onChange={(event) => setQuery(event.target.value)}
                        autoComplete="off"
                    />
                </div>
            }
        >
            <Head title={title} />
            <Editor
                key={`${selection?.mode ?? 'new'}-${selection?.row?.id ?? 'new'}-${rows.length}`}
                kind={kind}
                selection={selection ?? { mode: 'new' }}
                rows={rows}
                baseUrl={baseUrl}
                close={() => setSelection(null)}
                onRemove={() => setSelection({ mode: 'delete', row: selection?.row })}
            />
            <DataTable
                rows={visibleRows}
                columns={columns}
                onRowClick={(row) => setSelection({ mode: 'edit', row })}
                rowLabel={(row) => `${row.name} tanımını aç`}
                rowKey={(row) => row.id}
                caption={title}
                sort={sort}
                onSort={(key) =>
                    setSort((current) => ({
                        key,
                        direction: current?.key === key && current.direction === 'asc' ? 'desc' : 'asc',
                    }))
                }
            />
        </AppShell>
    );
}
