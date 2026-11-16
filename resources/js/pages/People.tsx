import { portalUrl } from '../lib/portalUrl';
import { useCallback, useMemo, useState } from 'react';
import { Head, Link, router, usePage } from '@inertiajs/react';
import AppShell from '../components/AppShell';
import { ActiveFilters, QuickSearch } from '../components/ListToolbar';
import ColumnFilterMenu from '../components/ColumnFilterMenu';
import InmateHoverCard from '../components/InmateHoverCard';
import PrivateNoteModal from '../components/PrivateNoteModal';
import PortalDialog from '../components/PortalDialog';
import HomeRecordDialog, { type HomeOptions } from '../components/HomeRecordDialog';
import DataTable from '../components/DataTable';
import { wrapTableText, type Column } from '../lib/table';
import useLiveList from '../lib/useLiveList';
import useStableRows from '../lib/useStableRows';
type Row = {
    healthWarning?: string | null;
    color?: string | null;
    privateNoteCount?: number;
    photoUrl?: string;
    release?: { ds: string | null; release: string | null };
    work_place?: string | null;
    work_decision?: string | null;
    health?: string | null;
    notes?: string | null;
    risk?: string | null;
    id: number;
    tc_identity: string | null;
    full_name: string | null;
    admitted_on: string | null;
    registered_on: string | null;
    editUrl?: string;
    placementUrl?: string;
    placement?: {
        status: string | null;
        block: string | null;
        room: string | null;
        bed: string | null;
    } | null;
};
type Query = {
    scope: 'active' | 'all';
    q: string;
    tc: string;
    sort_field: string | null;
    sort_direction: 'asc' | 'desc';
    filters?: Record<string, string>;
    selections?: Record<string, string[]>;
    selection_none?: string[];
};
type HousingRow = {
    id: number;
    block: string;
    room: string;
    is_active: boolean;
    capacity: number;
    present: number;
    physical: number;
    reserved: number;
    available: number;
    bedless_reserved: number;
};
type HousingSummary = {
    total: number;
    active: number;
    unassigned: number;
    present: number;
    physical: number;
    reserved: number;
    available: number;
    bedless_reserved: number;
    rooms: HousingRow[];
};
type Props = {
    privateWritable: boolean;
    valuesUrl: string;
    homeOptions: HomeOptions | null;
    homeSummary: {
        today: number;
        specialLeave: number;
        dataChange: number;
        leaves: Record<string, number> | null;
        duty: Record<string, string>;
    };
    homeUrl: string;
    exportUrl: string;
    riskVisible?: boolean;
    housingSummary: HousingSummary;
    applicationName: string;
    navigation?: { label: string; url: string }[];
    rows: Row[];
    queryState: Query;
    pagination: { page: number; per_page: number; total: number; last_page: number };
    canWrite: boolean;
    createUrl: string;
    baseUrl: string;
    logoutUrl: string;
};
const date = (value: string | null) => (value ? value.split('-').reverse().join('-') : '');
const personKey = (row: Row) => row.id;
const personLabel = (row: Row) => `${row.full_name ?? 'Kişi'} kaydını aç`;
function personColor(row: Row) {
    const color = row.color?.toLocaleLowerCase('tr');
    return ['kırmızı', 'kirmizi', 'sarı', 'sari', 'yeşil', 'yesil', 'mavi', 'turuncu', 'siyah'].includes(
        color ?? '',
    )
        ? 'case-color-' + color?.replace('ı', 'i').replace('ş', 's')
        : undefined;
}
export default function People({
    privateWritable,
    valuesUrl,
    homeOptions,
    homeSummary,
    homeUrl,
    exportUrl,
    riskVisible,
    housingSummary,
    applicationName,
    navigation,
    rows: incomingRows,
    queryState,
    pagination,
    canWrite,
    baseUrl,
}: Props) {
    const rows = useStableRows(incomingRows);
    const openRecord = useCallback((row: Row) => setRecordDialog({ id: row.id }), []);
    const [noteModal, setNoteModal] = useState<Row | null>(null);
    const [healthAlert, setHealthAlert] = useState<string | null>(null);
    const [recordDialog, setRecordDialog] = useState<{ id: number | null } | null>(null);
    const [hidden, setHidden] = useState<string[]>(() => {
        try {
            const stored = JSON.parse(localStorage.getItem('cte:index:hidden-columns:v2') ?? '[]');
            return Array.isArray(stored)
                ? stored.filter(
                      (v): v is string =>
                          typeof v === 'string' && !['sequence', 'tc_identity', 'full_name'].includes(v),
                  )
                : [];
        } catch {
            return [];
        }
    });
    useLiveList(
        ['rows', 'pagination', 'homeSummary', 'housingSummary', 'canWrite', 'privateWritable'],
        !!recordDialog || !!noteModal || !!healthAlert,
    );
    const flattened = (items: NonNullable<Props['navigation']>): { label: string; url: string }[] =>
        items.flatMap((item) => [
            item,
            ...flattened((item as typeof item & { children?: typeof items }).children ?? []),
        ]);
    const links = flattened(navigation ?? []);
    const { errors = {} } = usePage<{ errors: Record<string, string> }>().props;
    function visit(page: number, per_page = pagination.per_page, state = queryState) {
        router.get(
            baseUrl,
            {
                page,
                per_page,
                scope: state.scope,
                q: state.q,
                tc: state.tc,
                filters: state.filters ?? {},
                selections: state.selections ?? {},
                selection_none: state.selection_none ?? [],
                ...(state.sort_field
                    ? { sort_field: state.sort_field, sort_direction: state.sort_direction }
                    : {}),
            },
            { preserveState: true, preserveScroll: true },
        );
    }
    function sort(key: string) {
        const state: Query = {
            ...queryState,
            sort_field: key,
            sort_direction:
                queryState.sort_field === key && queryState.sort_direction === 'asc' ? 'desc' : 'asc',
        };
        visit(1, pagination.per_page, state);
    }
    function clear() {
        const state: Query = {
            scope: queryState.scope,
            q: '',
            tc: '',
            sort_field: null,
            sort_direction: 'asc',
            filters: {},
            selections: {},
            selection_none: [],
        };
        visit(1, pagination.per_page, state);
    }
    const columns = useMemo(() => {
        const columns: Column<Row>[] = [
            { key: 'sequence', label: 'ID', kind: 'sequence', value: () => null },
            {
                key: 'tc_identity',
                label: 'TC Kimlik',
                kind: 'tc',
                sortable: true,
                value: (row) => row.tc_identity,
            },
            {
                key: 'full_name',
                label: 'Adı Soyadı',
                kind: 'name',
                sortable: true,
                value: (row) => row.full_name,
                render: (row) => (
                    <InmateHoverCard person={row} onEdit={() => setRecordDialog({ id: row.id })} />
                ),
            },
            ...(
                [
                    { key: 'block', label: 'Blok' },
                    { key: 'room', label: 'Koğuş' },
                    { key: 'bed', label: 'Ranza' },
                ] as const
            ).map((field) => ({
                key: field.key,
                label: field.label,
                sortable: true,
                value: (row: Row) => row.placement?.[field.key],
            })),
            { key: 'work_place', sortable: true, label: 'Çalışma Yeri', value: (row) => row.work_place },
            {
                key: 'work_decision',
                sortable: true,
                label: 'Çalışma Kararı',
                value: (row) => row.work_decision,
            },
            {
                key: 'health',
                sortable: true,
                label: 'Sağlık Durumu',
                value: (row) => row.health,
                render: (row) => (
                    <>
                        {wrapTableText(row.health || '')}
                        {row.healthWarning && (
                            <button
                                className="cte-inline-alert"
                                aria-label="Bulaşıcı hastalık uyarısını göster"
                                title="Bulaşıcı hastalık uyarısı"
                                onClick={() => setHealthAlert(row.healthWarning ?? null)}
                            >
                                <i className="fas fa-triangle-exclamation" />
                            </button>
                        )}
                    </>
                ),
            },

            {
                key: 'registered_on',
                label: 'Kayıt',
                sortable: true,
                value: (row) => row.registered_on,
                render: (row) => date(row.registered_on),
            },
        ];
        columns.push({
            key: 'notes',
            label: 'Notlar',
            sortable: true,
            value: (row) => row.notes,
            render: (row) => (
                <span
                    className="cte-table-note-cell"
                    data-row-click-ignore
                    onDoubleClick={(event) => {
                        if (privateWritable || row.privateNoteCount) {
                            event.stopPropagation();
                            setNoteModal(row);
                        }
                    }}
                >
                    {wrapTableText(row.notes || '')}
                    {!!row.privateNoteCount && (
                        <button
                            className="cte-excel-note-marker"
                            aria-label={row.privateNoteCount + ' özel not var'}
                            title={row.privateNoteCount + ' özel not var'}
                            onClick={() => setNoteModal(row)}
                        />
                    )}
                </span>
            ),
        });
        if (riskVisible)
            columns.push({ key: 'risk', label: 'Risk', sortable: true, value: (row) => row.risk });
        columns.push({
            key: 'status',
            label: 'Durum',
            sortable: true,
            value: (row) => row.placement?.status,
        });
        return columns;
    }, [privateWritable, riskVisible]);
    const visibleColumns = useMemo(
        () => columns.filter((column) => !hidden.includes(column.key)),
        [columns, hidden],
    );
    const columnLabels = Object.fromEntries(columns.map((c) => [c.key, c.label]));
    const chips = [
        queryState.q && ['q', 'Adı Soyadı: ' + queryState.q],
        queryState.tc && ['tc', 'TC Kimlik No: ' + queryState.tc],
        ...Object.entries(queryState.filters ?? {})
            .filter(([, v]) => v)
            .map(([k, v]) => [k, columnLabels[k] + ': ' + v]),
        ...Object.entries(queryState.selections ?? {}).map(([k, v]) => [
            k,
            columnLabels[k] + ': ' + v.map((v) => v || '(Boş)').join(', '),
        ]),
        ...(queryState.selection_none ?? []).map((k) => [k, columnLabels[k] + ': değer seçilmedi']),
    ].filter(Boolean) as string[][];
    function removeFilter(key: string) {
        const state = { ...queryState, filters: { ...queryState.filters } };
        if (key === 'q' || key === 'tc') state[key] = '';
        else delete state.filters[key];
        state.selections = { ...state.selections };
        delete state.selections[key];
        state.selection_none = state.selection_none?.filter((k) => k !== key);
        visit(1, pagination.per_page, state);
    }
    function queryParams(state: Query) {
        const params = new URLSearchParams({
            scope: state.scope,
            q: state.q,
            tc: state.tc,
            ...(state.sort_field
                ? { sort_field: state.sort_field, sort_direction: state.sort_direction }
                : {}),
        });
        Object.entries(state.filters ?? {}).forEach(([k, v]) => params.set('filters[' + k + ']', v));
        Object.entries(state.selections ?? {}).forEach(([k, vs]) =>
            vs.forEach((v, i) => params.set(`selections[${k}][${i}]`, v)),
        );
        state.selection_none?.forEach((v, i) => params.set(`selection_none[${i}]`, v));
        return params;
    }
    function headerAction(column: Column<Row>) {
        if (column.key === 'sequence') return null;
        const params = queryParams(queryState);
        params.set('field', column.key);
        return (
            <ColumnFilterMenu
                label={column.label}
                valuesUrl={valuesUrl + '?' + params}
                selected={queryState.selections?.[column.key]}
                none={queryState.selection_none?.includes(column.key)}
                onSort={(direction) =>
                    visit(1, pagination.per_page, {
                        ...queryState,
                        sort_field: column.key,
                        sort_direction: direction,
                    })
                }
                onApply={(selected) => {
                    const state = {
                        ...queryState,
                        selections: { ...queryState.selections },
                        selection_none: (queryState.selection_none ?? []).filter((k) => k !== column.key),
                    };
                    if (selected === undefined || !selected.length) delete state.selections[column.key];
                    else state.selections[column.key] = selected;
                    if (selected?.length === 0) state.selection_none.push(column.key);
                    visit(1, pagination.per_page, state);
                }}
                onHide={
                    ['tc_identity', 'full_name'].includes(column.key)
                        ? undefined
                        : () => {
                              const next = [...hidden, column.key];
                              setHidden(next);
                              localStorage.setItem('cte:index:hidden-columns:v2', JSON.stringify(next));
                          }
                }
            />
        );
    }
    const exportParams = queryParams(queryState);
    return (
        <AppShell
            applicationName={applicationName}
            navigation={navigation}
            pageTitle="Ana sayfa"
            home
            toolbar={
                <ActiveFilters
                    chips={chips.map(([key, label]) => ({ key, label, onRemove: () => removeFilter(key) }))}
                    onClear={clear}
                    sorted={!!queryState.sort_field}
                    actions={
                        <>
                            <a
                                className="cte-primary-action cte-primary-action--excel"
                                href={exportUrl + '?' + exportParams}
                            >
                                <i className="fas fa-file-excel" aria-hidden="true" />{' '}
                                <span>Excel Dışa Aktar</span>
                            </a>
                            <button
                                data-portal-command="new-record"
                                type="button"
                                className="cte-primary-action"
                                disabled={!canWrite}
                                onClick={() => setRecordDialog({ id: null })}
                            >
                                <i className="fas fa-plus" aria-hidden="true" /> <span>Yeni Kayıt Ekle</span>
                            </button>
                        </>
                    }
                />
            }
            search={
                <>
                    <QuickSearch
                        value={queryState.q}
                        onSearch={(q) => visit(1, pagination.per_page, { ...queryState, q })}
                        label="Adı Soyadı ile hızlı ara"
                    />
                    <div className="summary-info">
                        <p>
                            <strong>Toplam Mevcut: </strong>
                            {housingSummary.active}
                        </p>
                        <p>
                            <strong>Bugün Yapılan Kayıt: </strong>
                            {homeSummary.today}
                        </p>
                        <p>
                            <strong style={{ color: 'var(--cte-text, #24333f)' }}>İzinli Hükümlüler: </strong>
                            <button
                                className="summary-link"
                                style={{
                                    color: 'red',
                                    textDecoration: 'none',
                                    fontWeight: 'bold',
                                    background: 'none',
                                    border: 0,
                                    padding: 0,
                                    cursor: 'pointer',
                                    font: 'inherit',
                                }}
                                onClick={() =>
                                    visit(1, pagination.per_page, {
                                        ...queryState,
                                        filters: { status: 'ÖZEL İZİN' },
                                    })
                                }
                            >
                                {homeSummary.specialLeave}
                            </button>
                            {homeSummary.leaves && (
                                <>
                                    {' | '}
                                    <strong style={{ color: 'var(--cte-text, #24333f)' }}>
                                        <Link
                                            href={portalUrl('/izin-raporu')}
                                            style={{ color: 'inherit', textDecoration: 'none' }}
                                        >
                                            İzin Raporu:{' '}
                                        </Link>
                                    </strong>
                                    <Link
                                        href={portalUrl('/izin-raporu?section=departure')}
                                        title="İzne Ayrılacaklar"
                                        style={{ color: 'red', textDecoration: 'none', fontWeight: 'bold' }}
                                    >
                                        {homeSummary.leaves.departure}
                                    </Link>
                                    {', '}
                                    <Link
                                        href={portalUrl('/izin-raporu?section=return')}
                                        title="İzinden Dönecekler"
                                        style={{ color: 'red', textDecoration: 'none', fontWeight: 'bold' }}
                                    >
                                        {homeSummary.leaves.return}
                                    </Link>
                                    {' | '}
                                    <strong style={{ color: 'var(--cte-text, #24333f)' }}>
                                        İzin Kontrol:{' '}
                                    </strong>
                                    <Link
                                        href={portalUrl('/izin-raporu?section=control')}
                                        title="İzinden Dönüşü Gecikenler (Saat 14:00)"
                                        style={
                                            homeSummary.leaves.control > 0
                                                ? {
                                                      color: '#d63031',
                                                      fontWeight: 'bold',
                                                      background: '#ffeaa7',
                                                      padding: '1px 5px',
                                                      borderRadius: '4px',
                                                      textDecoration: 'none',
                                                  }
                                                : { color: 'red', fontWeight: 'bold', textDecoration: 'none' }
                                        }
                                    >
                                        {homeSummary.leaves.control}
                                    </Link>
                                </>
                            )}
                        </p>
                        <p>
                            <strong>Koğuş Değişikliği: </strong>
                            <button
                                className="summary-link"
                                style={
                                    homeSummary.dataChange > 0
                                        ? {
                                              color: 'red',
                                              fontWeight: 'bold',
                                              background: 'none',
                                              border: 0,
                                              padding: 0,
                                              cursor: 'pointer',
                                              font: 'inherit',
                                          }
                                        : {
                                              background: 'none',
                                              border: 0,
                                              padding: 0,
                                              cursor: 'pointer',
                                              font: 'inherit',
                                          }
                                }
                                onClick={() =>
                                    visit(1, pagination.per_page, {
                                        ...queryState,
                                        filters: { status: 'VERİ DEĞİŞİKLİĞİ' },
                                    })
                                }
                            >
                                {homeSummary.dataChange}
                            </button>
                        </p>
                        <p>
                            <strong>Nöbetçi İnfaz Memuru: </strong>
                            {homeSummary.duty.infaznobet || 'Belirtilmemiş'}
                        </p>
                        <p>
                            <strong>Nöbetçi Müdür: </strong>
                            {homeSummary.duty.mudurnobet || 'Belirtilmemiş'}
                        </p>
                    </div>
                    {Object.values(errors).map((error, i) => (
                        <p className="form-error" role="alert" key={i}>
                            {error}
                        </p>
                    ))}
                </>
            }
        >
            <Head title="Ana sayfa" />
            {hidden.length > 0 && (
                <div className="row-actions">
                    <span>Gizli sütunlar:</span>
                    {hidden.map((key) => (
                        <button
                            className="filter-chip"
                            key={key}
                            onClick={() => {
                                const next = hidden.filter((k) => k !== key);
                                setHidden(next);
                                localStorage.setItem('cte:index:hidden-columns:v2', JSON.stringify(next));
                            }}
                        >
                            {columnLabels[key] ?? key} Göster
                        </button>
                    ))}
                </div>
            )}
            <DataTable
                emptyCellValue=""
                scrollToEnd
                rowClassName={personColor}
                rows={rows}
                columns={visibleColumns}
                headerActions={headerAction}
                sequenceOffset={(pagination.page - 1) * pagination.per_page}
                rowKey={personKey}
                caption="Kurum kayıtları"
                sort={
                    queryState.sort_field
                        ? { key: queryState.sort_field, direction: queryState.sort_direction }
                        : null
                }
                onSort={sort}
                onRowClick={openRecord}
                rowLabel={personLabel}
                emptyMessage="Filtrelere uygun kişi kaydı bulunamadı."
            />
            {recordDialog && homeOptions && (
                <HomeRecordDialog
                    privateWritable={privateWritable}
                    canWrite={canWrite}
                    key={recordDialog.id ?? 'new'}
                    id={recordDialog.id}
                    options={homeOptions}
                    baseUrl={homeUrl}
                    onClose={() => setRecordDialog(null)}
                    occupancy={housingSummary.rooms}
                    links={links}
                    photoUrl={rows.find((r) => r.id === recordDialog.id)?.photoUrl}
                />
            )}
            {noteModal && (
                <PrivateNoteModal
                    person={noteModal.id}
                    name={noteModal.full_name ?? ''}
                    onClose={() => {
                        setNoteModal(null);
                        router.reload({ only: ['rows'] });
                    }}
                />
            )}
            {healthAlert && <HealthWarning message={healthAlert} onClose={() => setHealthAlert(null)} />}
        </AppShell>
    );
}

function HealthWarning({ message, onClose }: { message: string; onClose: () => void }) {
    return (
        <PortalDialog title="Bulaşıcı Hastalık Uyarısı" className="health-warning-dialog" onClose={onClose}>
            <p className="editor-panel">{message}</p>
        </PortalDialog>
    );
}
