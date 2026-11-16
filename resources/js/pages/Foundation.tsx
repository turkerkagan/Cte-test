import { Head } from '@inertiajs/react';
import { useMemo, useState } from 'react';
import AppShell from '../components/AppShell';
import DataTable from '../components/DataTable';
import { personColumns, searchName, sortRows, type PersonIdentity, type Sort } from '../lib/table';

type Props = {
    applicationName: string;
    navigation?: { label: string; url: string }[];
    logoutUrl?: string | null;
    loginUrl?: string;
};
const columns = personColumns<PersonIdentity>();
const rows: readonly PersonIdentity[] = [];

export default function Foundation({ applicationName, navigation }: Props) {
    const [query, setQuery] = useState('');
    const [sort, setSort] = useState<Sort>(null);
    const visibleRows = useMemo(
        () =>
            sortRows(
                rows.filter((row) => searchName(row.fullName, query)),
                columns,
                sort,
            ),
        [query, sort],
    );
    return (
        <AppShell
            applicationName={applicationName}
            navigation={navigation}
            pageTitle="Ana sayfa"
            toolbar={
                <div className="active-filters" aria-label="Aktif filtreler">
                    <span className="active-filters__label">Aktif filtreler</span>
                    {query.trim() ? (
                        <button type="button" className="filter-chip" onClick={() => setQuery('')}>
                            Ad soyad: {query.trim()} <span aria-hidden="true">×</span>
                            <span className="sr-only"> filtresini kaldır</span>
                        </button>
                    ) : (
                        <span className="muted">Filtre uygulanmadı</span>
                    )}
                </div>
            }
            search={
                <div className="search-field">
                    <label htmlFor="person-name-search">Hızlı ad soyad ara</label>
                    <input
                        id="person-name-search"
                        type="search"
                        value={query}
                        onChange={(event) => setQuery(event.target.value)}
                        placeholder="Ad veya soyad yazın"
                        autoComplete="off"
                    />
                </div>
            }
        >
            <Head title={applicationName} />
            <DataTable
                rows={visibleRows}
                columns={columns}
                rowKey={(row) => row.recordId}
                caption="Kurum kayıtları"
                sort={sort}
                onSort={(key) =>
                    setSort((current) => ({
                        key,
                        direction: current?.key === key && current.direction === 'asc' ? 'desc' : 'asc',
                    }))
                }
                emptyMessage="İlk kurulum tamamlandığında kurum kayıtları burada listelenecek."
            />
            <p className="setup-note">
                Kişi kayıtlarını görüntülemek için giriş yapın. İlk kullanımda kurulumun tamamlanması gerekir.
            </p>
        </AppShell>
    );
}
