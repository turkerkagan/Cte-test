import { Head, Link, router, useForm } from '@inertiajs/react';
import { useMemo, useState } from 'react';
import AppShell from '../components/AppShell';
import DataTable from '../components/DataTable';
import { searchName, type Column } from '../lib/table';
type Row = {
    id: number;
    code: string;
    name: string;
    record_mode: string;
    number: number;
    has_draft: boolean;
};
type Props = {
    applicationName: string;
    navigation?: { label: string; url: string }[];
    rows: Row[];
    baseUrl: string;
    logoutUrl: string;
    optionsUrl: string;
};
export default function Modules({ applicationName, navigation, rows, baseUrl, optionsUrl }: Props) {
    const [query, setQuery] = useState('');
    const [creating, setCreating] = useState(false);
    const form = useForm({ code: '', name: '', record_mode: 'standalone' });
    const columns = useMemo<Column<Row>[]>(
        () => [
            { key: 'sequence', label: 'ID', kind: 'sequence', value: () => null },
            { key: 'name', label: 'Modül adı', value: (row) => row.name },
            { key: 'code', label: 'Kod', value: (row) => row.code },
            {
                key: 'mode',
                label: 'Kayıt modu',
                value: (row) =>
                    ({
                        standalone: 'Bağımsız liste',
                        person_single: 'Kişi başına tek kayıt',
                        person_multiple: 'Kişiye bağlı çoklu kayıt',
                    })[row.record_mode] ?? row.record_mode,
            },
            { key: 'number', label: 'Son sürüm', value: (row) => row.number },
            {
                key: 'status',
                label: 'Durum',
                value: (row) => (row.has_draft ? 'Taslak var' : 'Yayımlanmış tanım'),
            },
        ],
        [baseUrl],
    );
    return (
        <AppShell
            applicationName={applicationName}
            navigation={navigation}
            pageTitle="Sayfa düzenlemesi"
            toolbar={
                <>
                    <div className="active-filters">
                        <strong>Aktif filtreler</strong>
                        <span className="muted">{query || 'Filtre uygulanmadı'}</span>
                    </div>
                    <Link className="button" href={optionsUrl}>
                        Seçenek listeleri
                    </Link>
                    <button type="button" className="button" onClick={() => setCreating((value) => !value)}>
                        Yeni modül taslağı
                    </button>
                </>
            }
            search={
                <div className="search-field">
                    <label htmlFor="module-search">Hızlı modül ara</label>
                    <input
                        id="module-search"
                        type="search"
                        value={query}
                        onChange={(event) => setQuery(event.target.value)}
                    />
                </div>
            }
        >
            <Head title="Sayfa düzenlemesi" />
            {creating && (
                <section className="editor-panel">
                    <h2>Yeni modül taslağı</h2>
                    <form
                        className="definition-form"
                        onSubmit={(event) => {
                            event.preventDefault();
                            form.post(baseUrl);
                        }}
                    >
                        <label htmlFor="module-name">Modül adı</label>
                        <input
                            id="module-name"
                            autoFocus
                            required
                            maxLength={150}
                            value={form.data.name}
                            onChange={(event) => form.setData('name', event.target.value)}
                        />
                        <label htmlFor="module-code">Sabit kod</label>
                        <input
                            id="module-code"
                            required
                            maxLength={80}
                            placeholder="kurum-birimi"
                            value={form.data.code}
                            onChange={(event) => form.setData('code', event.target.value)}
                        />
                        <label htmlFor="module-record-mode">Kayıt modu</label>
                        <select
                            id="module-record-mode"
                            value={form.data.record_mode}
                            onChange={(event) => form.setData('record_mode', event.target.value)}
                        >
                            <option value="standalone">Bağımsız liste</option>
                            <option value="person_single">Kişi başına tek kayıt</option>
                            <option value="person_multiple">Kişiye bağlı çoklu işlem kaydı</option>
                        </select>
                        <p className="muted">
                            Kod küçük Latin harf, rakam ve tire içerir. Kayıt modu oluşturulduktan sonra
                            değişmez. Kişiye bağlı modüller mevcut ortak kişi kaydını kullanır. Taslak
                            oluşturmak personelin menüsüne yeni bir sayfa eklemez.
                        </p>
                        {Object.values(form.errors).map((error, i) => (
                            <p className="form-error" role="alert" key={i}>
                                {error}
                            </p>
                        ))}
                        <button className="button button--primary" disabled={form.processing}>
                            Taslak oluştur
                        </button>
                    </form>
                </section>
            )}
            <DataTable
                rows={rows.filter((row) => searchName(`${row.name} ${row.code}`, query))}
                columns={columns}
                onRowClick={(row) => router.visit(`${baseUrl}/${row.id}`)}
                rowLabel={(row) => `${row.name} kaydını aç`}
                rowKey={(row) => row.id}
                caption="Modül tanımları"
            />
        </AppShell>
    );
}
