import { Head, Link, router } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import CustomReportConfigurator from '../components/CustomReportConfigurator';
import TransferReportCriteria from '../components/TransferReportCriteria';
import ColumnFilterMenu from '../components/ColumnFilterMenu';
import LeaveReportDialog, { leaveSections } from '../components/LeaveReportDialog';
import WorkflowRecordModal from '../components/WorkflowRecordModal';
import LeaveInformationDialog from '../components/LeaveInformationDialog';
import { notify } from '../components/Toast';
import AppShell from '../components/AppShell';
import { ActiveFilters, QuickSearch, type FilterChip } from '../components/ListToolbar';
import { portalUrl } from '../lib/portalUrl';
import DataTable from '../components/DataTable';
import InmateHoverCard, { type HoverPerson } from '../components/InmateHoverCard';
import type { Column } from '../lib/table';
import { workflowTable } from '../lib/workflowTable';
import useLiveList from '../lib/useLiveList';
import ListPagination from '../components/ListPagination';
import { queryString, writeJson, type Fields, type Option, type State } from '../lib/workflow';
type Row = {
    hover?: HoverPerson;
    color?: string | null;
    id: number;
    revision?: number;
    values: Record<string, string>;
};
type Props = {
    reportError?: string;
    errors?: Record<string, string>;
    applicationName: string;
    navigation?: { label: string; url: string }[];
    logoutUrl: string;
    baseUrl: string;
    workflow: string;
    unitList?: boolean;
    title: string;
    fields: Fields;
    options?: Record<string, Option[]>;
    rows: Row[];
    queryState: State;
    canWrite: boolean;
    pagination?: { page: number; pages: number; total: number };
    counts?: Record<string, number>;
    generatedAt?: string;
    reasons?: Option[];
};
export default function WorkflowList(p: Props) {
    // Inertia can reuse this page between units; keep column and editor state local to each unit.
    return <WorkflowListPage key={p.workflow} {...p} />;
}
function WorkflowListPage(p: Props) {
    const [leaveSection, setLeaveSection] = useState<keyof typeof leaveSections | null>(null);
    const [recordUrl, setRecordUrl] = useState<string | null>(null);
    const [leaveInformation, setLeaveInformation] = useState<Record<string, string> | null>(null);
    const quickLocks = useRef(new Set<number>());
    const [quickBusy, setQuickBusy] = useState(new Set<number>());
    const [state, setState] = useState(p.queryState);
    const [error, setError] = useState('');
    useEffect(() => setState(p.queryState), [JSON.stringify(p.queryState)]);
    const {
        orderedFields,
        hidden: initialHidden,
        sequenceLabel,
    } = workflowTable(p.workflow, p.fields, p.queryState);
    const [hidden, setHidden] = useState(initialHidden);
    const visibleKeys = orderedFields.map(([key]) => key).filter((key) => !hidden.includes(key));
    const report =
        p.workflow === 'izin_rapor' || p.workflow === 'infaz_sevk_raporu' || p.workflow === 'custom_rapor';
    useLiveList(['rows', 'pagination', 'canWrite'], report || !!recordUrl || quickBusy.size > 0);
    function visit(next: State) {
        router.visit(`${p.baseUrl}?${queryString(next)}`, { preserveScroll: true, preserveState: true });
    }
    async function runQuick(row: Row, action: 'basvuru' | 'istemiyor') {
        if (!p.canWrite || quickLocks.current.has(row.id)) return;
        quickLocks.current.add(row.id);
        setQuickBusy(new Set(quickLocks.current));
        setError('');
        try {
            const result = await writeJson(
                `${p.baseUrl}/kisiler/${row.id}/hizli`,
                { revision: row.revision, action },
                'POST',
            );
            if (!result.ok) {
                const message =
                    Object.values(result.data.errors ?? {})
                        .flat()
                        .join(' ') ||
                    result.data.message ||
                    'Hızlı işlem kaydedilemedi.';
                setError(
                    result.status === 409
                        ? `${message} Güncel kaydı görmek için listeyi yenileyin.`
                        : message,
                );
                return;
            }
            notify(
                action === 'basvuru'
                    ? 'MESEM başvuru süreci güncellendi.'
                    : 'MESEM istemiyor durumu kaydedildi.',
            );
            // Keep this row locked until the new revision reaches the page.
            await new Promise<void>((resolve) => router.reload({ onFinish: () => resolve() }));
            setRecordUrl(null);
        } catch (e) {
            setError(e instanceof Error ? e.message : 'İşlem yapılamadı.');
        } finally {
            quickLocks.current.delete(row.id);
            setQuickBusy(new Set(quickLocks.current));
        }
    }
    function openRecord(row: Row) {
        if (!report && !quickBusy.has(row.id)) setRecordUrl(`${p.baseUrl}/kisiler/${row.id}`);
    }
    const selectedRecord = p.rows.find((row) => recordUrl === `${p.baseUrl}/kisiler/${row.id}`);
    const hasPersonSummary = p.rows.some((row) => row.hover !== undefined);
    const columns: Column<Row>[] = [
        { key: 'sequence', label: sequenceLabel, kind: 'sequence', value: () => null },
        ...orderedFields
            .filter(([key]) => !hidden.includes(key))
            .map(([key, field]) => ({
                key,
                label: field.label,
                kind: field.kind,
                sortable: (field.sortable ?? true) && !report && !['remaining', 'sentence'].includes(key),
                value: (row: Row) => row.values[key],
                ...(key === 'full_name' && hasPersonSummary
                    ? {
                          render: (row: Row) => (
                              <InmateHoverCard
                                  person={
                                      row.hover ?? {
                                          full_name: row.values.full_name,
                                          tc_identity: row.values.tc_identity ?? null,
                                      }
                                  }
                                  disabled={quickBusy.has(row.id)}
                                  onEdit={report ? undefined : () => openRecord(row)}
                              />
                          ),
                      }
                    : {}),
            })),
    ];
    function removeField(key: string) {
        const selections = { ...p.queryState.selections };
        delete selections[key];
        visit({
            ...p.queryState,
            page: 1,
            selections,
            selection_none: p.queryState.selection_none?.filter((field) => field !== key),
            filters: p.queryState.filters?.filter((filter) => filter.field !== key),
        });
    }
    const chips: FilterChip[] = [
        ...(p.queryState.q
            ? [
                  {
                      key: 'q',
                      label: 'Arama: ' + p.queryState.q,
                      onRemove: () => visit({ ...p.queryState, q: '', page: 1 }),
                  },
              ]
            : []),
        ...(p.queryState.filters ?? []).map((filter, index) => ({
            key: 'filter-' + index,
            label: `${p.fields[filter.field]?.label ?? filter.field}: ${filter.op === 'empty' ? '(Boş)' : Array.isArray(filter.value) ? filter.value.join(', ') : (filter.value ?? '')}`,
            onRemove: () =>
                visit({
                    ...p.queryState,
                    page: 1,
                    filters: p.queryState.filters?.filter((_, i) => i !== index),
                }),
        })),
        ...Object.entries(p.queryState.selections ?? {}).map(([key, values]) => ({
            key: 'selection-' + key,
            label: `${p.fields[key]?.label ?? key}: ${values.map((value) => value || '(Boş)').join(', ')}`,
            onRemove: () => removeField(key),
        })),
        ...(p.queryState.selection_none ?? []).map((key) => ({
            key: 'none-' + key,
            label: `${p.fields[key]?.label ?? key}: değer seçilmedi`,
            onRemove: () => removeField(key),
        })),
    ];
    return (
        <AppShell
            listing
            applicationName={p.applicationName}
            navigation={p.navigation}
            pageTitle={p.title}
            toolbar={
                <ActiveFilters
                    chips={chips}
                    sorted={!!p.queryState.sort}
                    onClear={() =>
                        visit({
                            ...p.queryState,
                            q: '',
                            filters: [],
                            selections: {},
                            selection_none: [],
                            sort: undefined,
                            direction: undefined,
                            page: 1,
                        })
                    }
                    actions={
                        !p.reportError &&
                        (p.workflow !== 'custom_rapor' || Array.isArray(p.queryState.columns)) && (
                            <Link
                                className="cte-primary-action"
                                href={`${p.baseUrl}/yazdir?${queryString({ ...p.queryState, columns: visibleKeys })}`}
                            >
                                <i className="fas fa-print" aria-hidden="true" /> Yazdır / PDF
                            </Link>
                        )
                    }
                />
            }
            search={
                <>
                    <QuickSearch
                        value={p.queryState.q ?? ''}
                        onSearch={(q) => visit({ ...p.queryState, q, page: 1 })}
                    />
                    {!p.unitList && (
                        <div className="summary-info">
                            <p>
                                <strong>{p.title}: </strong>
                                {p.pagination?.total ?? p.rows.length} kayıt
                            </p>
                        </div>
                    )}
                </>
            }
        >
            <Head title={p.title} />
            {p.workflow === 'custom_rapor' && (
                <CustomReportConfigurator
                    fields={p.fields}
                    state={p.queryState}
                    onGenerate={(columns, scope) => visit({ ...p.queryState, columns, scope, page: 1 })}
                />
            )}
            {p.workflow === 'infaz_sevk_raporu' && (
                <TransferReportCriteria
                    state={state}
                    reasons={p.reasons ?? []}
                    onChange={setState}
                    onApply={() => visit({ ...state, page: 1 })}
                    count={p.rows.length}
                />
            )}

            {p.workflow === 'izin_rapor' && (
                <form
                    className="leave-report-heading"
                    onSubmit={(e) => {
                        e.preventDefault();
                        visit({ ...p.queryState, date: state.date });
                    }}
                >
                    <h1>İzin Raporu · Günlük İzin Giriş/Çıkış Tablosu</h1>
                    <label>
                        Tarih
                        <input
                            required
                            type="date"
                            value={state.date}
                            onChange={(e) => setState({ ...state, date: e.target.value })}
                        />
                    </label>
                    <button className="button button--primary">Raporla</button>
                    <Link className="button" href={portalUrl('/infaz')}>
                        İnfaz'a Dön
                    </Link>
                </form>
            )}
            {p.workflow === 'izin_rapor' && (
                <div className="leave-report-cards">
                    {(Object.keys(leaveSections) as (keyof typeof leaveSections)[]).map((section) => (
                        <button
                            key={section}
                            type="button"
                            className={`leave-report-card leave-report-card--${section}`}
                            onClick={() => setLeaveSection(section)}
                        >
                            <i
                                className={
                                    'fas ' +
                                    {
                                        departure: 'fa-person-walking-arrow-right',
                                        return: 'fa-person-walking',
                                        control: 'fa-clock',
                                    }[section]
                                }
                                aria-hidden="true"
                            />
                            <span>
                                <strong>{p.counts?.[section] ?? 0}</strong>
                                <span>{leaveSections[section]}</span>
                            </span>
                        </button>
                    ))}
                </div>
            )}
            {leaveSection && (
                <LeaveReportDialog
                    baseUrl={p.baseUrl}
                    state={p.queryState}
                    section={leaveSection}
                    onClose={() => setLeaveSection(null)}
                />
            )}
            {p.reportError && (
                <p className="form-error" role="alert">
                    {p.reportError}
                </p>
            )}
            {Object.values(p.errors ?? {}).map((message, i) => (
                <p className="form-error" role="alert" key={i}>
                    {message}
                </p>
            ))}
            {error && (
                <p className="form-error" role="alert">
                    {error}
                </p>
            )}
            {p.generatedAt && <p className="muted">Rapor zamanı: {p.generatedAt}</p>}
            {!p.reportError &&
                p.workflow !== 'izin_rapor' &&
                (p.workflow !== 'custom_rapor' || Array.isArray(p.queryState.columns)) && (
                    <DataTable
                        sequenceOffset={
                            p.pagination ? (p.pagination.page - 1) * (p.queryState.per_page ?? 25) : 0
                        }
                        emptyCellValue=""
                        headerActions={
                            report
                                ? undefined
                                : ({ key }) =>
                                      p.fields[key] &&
                                      (p.fields[key].filter_visible ?? true) &&
                                      !['remaining', 'sentence'].includes(key) ? (
                                          <ColumnFilterMenu
                                              label={
                                                  orderedFields.find(([fieldKey]) => fieldKey === key)?.[1]
                                                      .label ?? p.fields[key].label
                                              }
                                              valuesUrl={`${p.baseUrl}/sutun-degerleri?${queryString(p.queryState)}&field=${encodeURIComponent(key)}`}
                                              selected={p.queryState.selections?.[key]}
                                              none={p.queryState.selection_none?.includes(key)}
                                              onApply={(selected) => {
                                                  const next = {
                                                      ...p.queryState,
                                                      page: 1,
                                                      selections: { ...p.queryState.selections },
                                                      selection_none: (
                                                          p.queryState.selection_none ?? []
                                                      ).filter((field) => field !== key),
                                                      filters: (p.queryState.filters ?? []).filter(
                                                          (filter) => filter.field !== key,
                                                      ),
                                                  };
                                                  delete next.selections[key];
                                                  if (selected === undefined) {
                                                      // No explicit selection filter remains.
                                                  } else if (!selected.length) next.selection_none.push(key);
                                                  else next.selections[key] = selected;
                                                  visit(next);
                                              }}
                                              onSort={
                                                  (p.fields[key].sortable ?? true)
                                                      ? (direction) =>
                                                            visit({
                                                                ...p.queryState,
                                                                page: 1,
                                                                sort: key,
                                                                direction,
                                                            })
                                                      : undefined
                                              }
                                              onHide={
                                                  ['tc_identity', 'full_name'].includes(key)
                                                      ? undefined
                                                      : () => setHidden([...hidden, key])
                                              }
                                          />
                                      ) : null
                        }
                        rowClassName={(r) =>
                            ['kirmizi', 'sari', 'yesil', 'mavi', 'turuncu', 'siyah'].includes(r.color ?? '')
                                ? `case-color-${r.color}`
                                : undefined
                        }
                        rows={p.rows}
                        columns={columns}
                        rowKey={(r) => r.id}
                        caption={p.title}
                        onRowClick={report ? undefined : openRecord}
                        rowDisabled={(row) => quickBusy.has(row.id)}
                        rowLabel={(row) => `${row.values.full_name} kaydını aç`}
                        sort={
                            report
                                ? null
                                : {
                                      key: p.queryState.sort ?? 'full_name',
                                      direction: p.queryState.direction === 'desc' ? 'desc' : 'asc',
                                  }
                        }
                        onSort={
                            report
                                ? undefined
                                : (key) =>
                                      visit({
                                          ...p.queryState,
                                          sort: key,
                                          direction:
                                              p.queryState.sort === key && p.queryState.direction === 'asc'
                                                  ? 'desc'
                                                  : 'asc',
                                          page: 1,
                                      })
                        }
                    />
                )}
            {p.pagination && !report && !p.unitList && (
                <ListPagination
                    page={p.pagination.page}
                    pages={p.pagination.pages}
                    total={p.pagination.total}
                    perPage={p.queryState.per_page ?? 25}
                    onPage={(page) => visit({ ...p.queryState, page })}
                    onPerPage={(per_page) => visit({ ...p.queryState, page: 1, per_page })}
                />
            )}
            {hidden.some((key) => !initialHidden.includes(key)) && (
                <div className="row-actions">
                    <span>Gizli sütunlar:</span>
                    {hidden
                        .filter((key) => !initialHidden.includes(key))
                        .map((key) => (
                            <button
                                type="button"
                                className="filter-chip"
                                key={key}
                                onClick={() => setHidden(hidden.filter((field) => field !== key))}
                            >
                                {p.fields[key]?.label ?? key} Göster
                            </button>
                        ))}
                </div>
            )}
            {recordUrl && (
                <WorkflowRecordModal
                    operationError={error}
                    url={recordUrl}
                    onClose={() => setRecordUrl(null)}
                    externalBusy={!!selectedRecord && quickBusy.has(selectedRecord.id)}
                    secondaryActions={
                        selectedRecord && (
                            <>
                                {p.workflow === 'izin' && (
                                    <button
                                        type="button"
                                        className="button"
                                        onClick={() => setLeaveInformation(selectedRecord.values)}
                                    >
                                        İzin bilgileri
                                    </button>
                                )}
                                {p.workflow === 'mesem' && (
                                    <>
                                        <button
                                            type="button"
                                            className="button"
                                            disabled={quickBusy.has(selectedRecord.id)}
                                            onClick={() => void runQuick(selectedRecord, 'basvuru')}
                                        >
                                            Başvuru Sürecinde
                                        </button>
                                        <button
                                            type="button"
                                            className="button button--danger"
                                            disabled={quickBusy.has(selectedRecord.id)}
                                            onClick={() => void runQuick(selectedRecord, 'istemiyor')}
                                        >
                                            İstemiyor
                                        </button>
                                    </>
                                )}
                            </>
                        )
                    }
                />
            )}
            {leaveInformation && (
                <LeaveInformationDialog values={leaveInformation} onClose={() => setLeaveInformation(null)} />
            )}
        </AppShell>
    );
}
