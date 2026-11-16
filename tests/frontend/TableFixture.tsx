// Browser-only synthetic fixture. Never imported by the production application.
import { useState } from 'react';
import { createRoot } from 'react-dom/client';
import AppShell from '../../resources/js/components/AppShell';
import DataTable from '../../resources/js/components/DataTable';
import {
    personColumns,
    searchName,
    sortRows,
    type PersonIdentity,
    type Sort,
} from '../../resources/js/lib/table';
import '../../resources/css/app.css';

type Row = PersonIdentity & { note: string };
const sample: Row[] = Array.from({ length: 100 }, (_, i) => ({
    recordId: 98700 + i,
    tc: String(i).padStart(11, '0'),
    fullName: i === 0 ? 'İlker IŞIK UZUN İSİM DENEMESİ' : `ÖRNEK KİŞİ ${i}`,
    note: 'X'.repeat(80),
}));

function Fixture() {
    const [count, setCount] = useState(100);
    const [wide, setWide] = useState(false);
    const [query, setQuery] = useState('');
    const [sort, setSort] = useState<Sort>(null);
    const columns = personColumns<Row>(
        Array.from({ length: wide ? 12 : 1 }, (_, i) => ({
            key: `note${i}`,
            label: `Açıklama ${i + 1}`,
            value: (row: Row) => row.note,
        })),
    );
    const rows = sortRows(
        sample.slice(0, count).filter((row) => searchName(row.fullName, query)),
        columns,
        sort,
    );
    return (
        <AppShell
            applicationName="SENTETİK TABLO TESTİ"
            toolbar={
                <>
                    <label>
                        Kayıt sayısı{' '}
                        <select value={count} onChange={(e) => setCount(Number(e.target.value))}>
                            <option>0</option>
                            <option>1</option>
                            <option>100</option>
                        </select>
                    </label>
                    <label>
                        <input type="checkbox" checked={wide} onChange={(e) => setWide(e.target.checked)} />{' '}
                        Çok sütun
                    </label>
                </>
            }
            search={
                <div className="search-field">
                    <label htmlFor="fixture-search">Hızlı ad soyad ara</label>
                    <input
                        id="fixture-search"
                        type="search"
                        value={query}
                        onChange={(e) => setQuery(e.target.value)}
                    />
                </div>
            }
        >
            <DataTable
                rows={rows}
                columns={columns}
                rowKey={(row) => row.recordId}
                caption="Sentetik kişiler"
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

createRoot(document.getElementById('app')!).render(<Fixture />);
