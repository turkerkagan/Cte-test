import { formatDateTime } from '../lib/format';
import { Head, Link } from '@inertiajs/react';
import { useMemo, useState } from 'react';
import { portalUrl } from '../lib/portalUrl';
import AppShell from '../components/AppShell';
import ColumnFilterMenu from '../components/ColumnFilterMenu';
import { ActiveFilters, QuickSearch } from '../components/ListToolbar';
import DataTable from '../components/DataTable';
import UserAdministrationDialog from '../components/UserAdministrationDialog';
import { searchName, sortRows, type Column, type Sort } from '../lib/table';

type Row = {
    id: number;
    username: string;
    registry_number: string | null;
    full_name: string | null;
    email?: string | null;
    department_id?: number | null;
    department: string | null;
    job_title: string | null;
    level: string;
    is_active: boolean;
    last_login_at?: string | null;
};
type Props = {
    applicationName: string;
    navigation?: { label: string; url: string }[];
    rows: Row[];
    baseUrl: string;
    logoutUrl: string;
};
const lastLogin = formatDateTime;
export default function Users({ applicationName, navigation, rows, baseUrl }: Props) {
    const [query, setQuery] = useState('');
    const [sort, setSort] = useState<Sort>(null);
    const [selections, setSelections] = useState<Record<string, string[]>>({});
    const [editor, setEditor] = useState<number | 'new' | null>(null);
    const columns = useMemo<Column<Row>[]>(
        () => [
            {
                key: 'registry_number',
                label: 'Sicil',
                value: (row) => row.registry_number || row.username,
                sortable: true,
            },
            {
                key: 'full_name',
                label: 'Ad Soyad',
                kind: 'name',
                value: (row) => row.full_name ?? row.username,
                sortable: true,
            },
            { key: 'department', label: 'Birim', value: (row) => row.department, sortable: true },
            { key: 'job_title', label: 'Unvan', value: (row) => row.job_title, sortable: true },
            { key: 'level', label: 'Seviye', value: (row) => row.level, sortable: true },
            {
                key: 'last_login_at',
                label: 'Son Giriş',
                value: (row) => row.last_login_at ?? null,
                render: (row) => lastLogin(row.last_login_at),
                sortable: true,
            },
            {
                key: 'is_active',
                label: 'Durum',
                value: (row) => (row.is_active ? 'Aktif' : 'Pasif'),
                sortable: true,
            },
        ],
        [],
    );
    function cell(row: Row, key: string) {
        if (key === 'is_active') return row.is_active ? 'Aktif' : 'Pasif';
        if (key === 'last_login_at') return lastLogin(row.last_login_at);
        return String(columns.find((column) => column.key === key)?.value(row) ?? '');
    }
    const visibleRows = useMemo(
        () =>
            sortRows(
                rows.filter(
                    (row) =>
                        searchName(
                            `${row.username} ${row.registry_number ?? ''} ${row.full_name ?? ''} ${row.email ?? ''}`,
                            query,
                        ) &&
                        Object.entries(selections).every(([key, values]) => values.includes(cell(row, key))),
                ),
                columns,
                sort,
            ),
        [rows, query, selections, columns, sort],
    );
    function remove(key: string) {
        setSelections((current) => {
            const next = { ...current };
            delete next[key];
            return next;
        });
    }
    const chips = [
        ...(query ? [{ key: 'q', label: 'Arama: ' + query, onRemove: () => setQuery('') }] : []),
        ...Object.entries(selections).map(([key, values]) => ({
            key,
            label: `${columns.find((column) => column.key === key)?.label}: ${values.length ? values.map((value) => value || '(Boş)').join(', ') : 'değer seçilmedi'}`,
            onRemove: () => remove(key),
        })),
    ];
    const clear = () => {
        setQuery('');
        setSelections({});
        setSort(null);
    };
    const dropdowns = [
        {
            key: 'level',
            label: 'Yetki Seviyesi',
            all: 'Tüm kullanıcılar',
            values: [...new Set(rows.map((row) => row.level))],
        },
        {
            key: 'department',
            label: 'Birim',
            all: 'Tüm birimler',
            values: [...new Set(rows.map((row) => row.department ?? ''))],
        },
        { key: 'is_active', label: 'Durum', all: 'Tümü', values: ['Aktif', 'Pasif'] },
    ];
    return (
        <AppShell
            listing
            applicationName={applicationName}
            navigation={navigation}
            pageTitle="Kullanıcılar ve erişim"
            toolbar={
                <>
                    <Link className="button" href={portalUrl('/yonetim/talepler')}>
                        <i className="fas fa-key" aria-hidden="true" /> Şifre Talepleri
                    </Link>
                    <button type="button" className="cte-primary-action" onClick={() => setEditor('new')}>
                        <i className="fas fa-user-plus" aria-hidden="true" /> Yeni Kullanıcı
                    </button>
                </>
            }
            search={
                <>
                    <p className="admin-user-description">
                        Personel ataması, etkili erişim ve güvenlik işlemlerini tek yerden yönetin.
                    </p>
                    <div className="admin-user-filters">
                        <div>
                            <span className="admin-filter-label">Arama</span>
                            <QuickSearch
                                value={query}
                                onSearch={setQuery}
                                placeholder="Sicil, ad soyad veya e-posta"
                                label="Kullanıcı adı, sicil, ad soyad veya e-posta ara"
                            />
                        </div>
                        {dropdowns.map((filter) => (
                            <label key={filter.key}>
                                {filter.label}
                                <select
                                    value={
                                        selections[filter.key]?.length === 1
                                            ? selections[filter.key][0] || '__empty'
                                            : ''
                                    }
                                    onChange={(event) =>
                                        event.target.value === ''
                                            ? remove(filter.key)
                                            : setSelections((current) => ({
                                                  ...current,
                                                  [filter.key]: [
                                                      event.target.value === '__empty'
                                                          ? ''
                                                          : event.target.value,
                                                  ],
                                              }))
                                    }
                                >
                                    <option value="">{filter.all}</option>
                                    {filter.values.map((value) => (
                                        <option key={value} value={value || '__empty'}>
                                            {value || 'Atanmamış'}
                                        </option>
                                    ))}
                                </select>
                            </label>
                        ))}
                        <button type="button" className="button" onClick={clear}>
                            <i className="fas fa-xmark" aria-hidden="true" /> Temizle
                        </button>
                    </div>
                </>
            }
        >
            <Head title="Kullanıcılar ve erişim" />
            {chips.length > 0 && (
                <div className="admin-user-active-filters">
                    <ActiveFilters chips={chips} />
                </div>
            )}
            <div className="admin-list-heading">
                <strong>Kullanıcı listesi</strong>
                <span>
                    {visibleRows.length} / {rows.length} kullanıcı
                </span>
            </div>
            <DataTable
                headerActions={(column) => (
                    <ColumnFilterMenu
                        label={column.label}
                        values={[...new Set(rows.map((row) => cell(row, column.key)))].map((value) => ({
                            value,
                            label: value || '(Boş)',
                        }))}
                        selected={selections[column.key]}
                        none={selections[column.key]?.length === 0}
                        onApply={(values) =>
                            values === undefined
                                ? remove(column.key)
                                : setSelections({ ...selections, [column.key]: values })
                        }
                        onSort={(direction) => setSort({ key: column.key, direction })}
                    />
                )}
                rows={visibleRows}
                columns={columns}
                onRowClick={(row) => setEditor(row.id)}
                rowLabel={(row) => `${row.full_name ?? row.username} kaydını aç`}
                rowKey={(row) => row.id}
                caption="Kullanıcılar"
                sort={sort}
                onSort={(key) =>
                    setSort((current) => ({
                        key,
                        direction: current?.key === key && current.direction === 'asc' ? 'desc' : 'asc',
                    }))
                }
            />
            {editor !== null && (
                <UserAdministrationDialog
                    key={editor}
                    id={editor}
                    baseUrl={baseUrl}
                    onClose={() => setEditor(null)}
                />
            )}
        </AppShell>
    );
}
