import { Head, Link, useForm } from '@inertiajs/react';
import { useRef, useState } from 'react';
import ConflictReview from '../components/ConflictReview';
import { workspaceRequest } from '../lib/workspace';
import AppShell from '../components/AppShell';
import PortalDialog from '../components/PortalDialog';
import DataTable from '../components/DataTable';
import { searchName, type Column } from '../lib/table';
export type OptionItem = { id: number | null; label: string; is_active: boolean };
export type OptionList = {
    id: number;
    code: string;
    name: string;
    is_active: boolean;
    revision: number;
    items: OptionItem[];
};
type Props = {
    applicationName: string;
    navigation?: { label: string; url: string }[];
    lists: OptionList[];
    baseUrl: string;
    modulesUrl: string;
    logoutUrl: string;
    allowCreate?: boolean;
};
function ListEditor({
    list,
    baseUrl,
    close,
    onBusy,
}: {
    list: OptionList;
    baseUrl: string;
    close: () => void;
    onBusy: (busy: boolean) => void;
}) {
    const form = useForm({
        name: list.name,
        is_active: list.is_active,
        revision: list.revision,
        items: list.items,
    });
    const project = (value: typeof form.data) => ({
        name: value.name,
        is_active: value.is_active,
        items: JSON.stringify(value.items),
    });
    const base = useRef(project(form.data));
    function update(index: number, values: Partial<OptionItem>) {
        form.setData(
            'items',
            form.data.items.map((item, i) => (i === index ? { ...item, ...values } : item)),
        );
    }
    function move(index: number, direction: number) {
        const next = [...form.data.items];
        [next[index], next[index + direction]] = [next[index + direction], next[index]];
        form.setData('items', next);
    }
    return (
        <section className="editor-panel">
            <h2>Seçenek listesi · {list.code}</h2>
            <p className="muted">
                Kaydedilmiş seçenekleri etkin işaretini kaldırarak arşivleyin. Kimlikleri korunur;
                arşivlenenler yeni seçimlerde görünmez.
            </p>
            <form
                className="definition-form"
                onSubmit={(event) => {
                    event.preventDefault();
                    if (form.errors.revision) return;
                    form.put(`${baseUrl}/${list.id}`, {
                        preserveScroll: true,
                        onSuccess: close,
                        onStart: () => onBusy(true),
                        onFinish: () => onBusy(false),
                    });
                }}
            >
                <fieldset className="module-field" disabled={form.processing || !!form.errors.revision}>
                    <div className="form-field">
                        <label htmlFor="option-list-name">Liste adı</label>
                        <input
                            id="option-list-name"
                            required
                            maxLength={150}
                            value={form.data.name}
                            onChange={(event) => form.setData('name', event.target.value)}
                        />
                    </div>
                    <label className="checkbox-field">
                        <input
                            type="checkbox"
                            checked={form.data.is_active}
                            onChange={(event) => form.setData('is_active', event.target.checked)}
                        />
                        Liste etkin
                    </label>
                    {form.data.items.map((item, index) => (
                        <fieldset
                            className="module-field"
                            key={item.id === null ? `new-${index}` : `saved-${item.id}`}
                        >
                            <legend>
                                {index + 1}. seçenek{item.id !== null && ` · kimlik ${item.id}`}
                            </legend>
                            <div className="form-field">
                                <label htmlFor={`option-item-${index}`}>Etiket</label>
                                <input
                                    id={`option-item-${index}`}
                                    required
                                    maxLength={150}
                                    value={item.label}
                                    onChange={(event) => update(index, { label: event.target.value })}
                                />
                            </div>
                            <label className="checkbox-field">
                                <input
                                    type="checkbox"
                                    checked={item.is_active}
                                    onChange={(event) => update(index, { is_active: event.target.checked })}
                                />
                                Etkin
                            </label>
                            <div className="row-actions">
                                <button
                                    type="button"
                                    className="button"
                                    disabled={index === 0}
                                    onClick={() => move(index, -1)}
                                >
                                    Yukarı
                                </button>
                                <button
                                    type="button"
                                    className="button"
                                    disabled={index === form.data.items.length - 1}
                                    onClick={() => move(index, 1)}
                                >
                                    Aşağı
                                </button>
                                {item.id === null && (
                                    <button
                                        type="button"
                                        className="button button--danger"
                                        onClick={() =>
                                            form.setData(
                                                'items',
                                                form.data.items.filter((_, i) => i !== index),
                                            )
                                        }
                                    >
                                        Kaydedilmemiş seçeneği kaldır
                                    </button>
                                )}
                            </div>
                        </fieldset>
                    ))}
                    <button
                        type="button"
                        className="button"
                        disabled={form.data.items.length >= 500}
                        onClick={() =>
                            form.setData('items', [
                                ...form.data.items,
                                { id: null, label: '', is_active: true },
                            ])
                        }
                    >
                        Seçenek ekle
                    </button>
                </fieldset>
                {Object.values(form.errors).map((error, index) => (
                    <p className="form-error" role="alert" key={index}>
                        {error}
                    </p>
                ))}
                {form.errors.revision && (
                    <ConflictReview
                        base={base.current}
                        draft={project(form.data)}
                        labels={{
                            name: 'Liste adı',
                            is_active: 'Liste etkin',
                            items: 'Seçeneklerin tamamı ve sırası',
                        }}
                        display={(key, value) =>
                            key === 'items'
                                ? (JSON.parse(String(value)) as OptionItem[])
                                      .map(
                                          (item, index) =>
                                              `${index + 1}. ${item.label} (${item.is_active ? 'etkin' : 'arşiv'})`,
                                      )
                                      .join(' · ')
                                : String(value)
                        }
                        load={async (signal) => {
                            const data = await workspaceRequest<{ lists: OptionList[] }>(
                                baseUrl,
                                undefined,
                                signal,
                            );
                            const latest = data.lists.find((item) => item.id === list.id);
                            if (!latest) throw new Error('Liste bulunamadı.');
                            return { values: project(latest), metadata: latest };
                        }}
                        apply={(merged, latest) => {
                            base.current = project(latest);
                            form.setData({
                                name: String(merged.name),
                                is_active: Boolean(merged.is_active),
                                items: JSON.parse(String(merged.items)) as OptionItem[],
                                revision: latest.revision,
                            });
                            form.clearErrors();
                        }}
                    />
                )}
                <div className="row-actions">
                    <button
                        className="button button--primary"
                        disabled={form.processing || !!form.errors.revision}
                    >
                        Listeyi kaydet
                    </button>
                    <button type="button" className="button" disabled={form.processing} onClick={close}>
                        Düzenlemeyi kapat
                    </button>
                </div>
            </form>
        </section>
    );
}
export default function OptionLists({
    applicationName,
    navigation,
    lists,
    baseUrl,
    modulesUrl,
    allowCreate = true,
}: Props) {
    const [query, setQuery] = useState('');
    const [selected, setSelected] = useState<number | null>(null);
    const [creating, setCreating] = useState(false);
    const [listBusy, setListBusy] = useState(false);
    const form = useForm({ code: '', name: '' });
    const list = lists.find((entry) => entry.id === selected);
    const columns: Column<OptionList>[] = [
        { key: 'sequence', label: 'ID', kind: 'sequence', value: () => null },
        { key: 'name', label: 'Liste adı', value: (row) => row.name },
        { key: 'code', label: 'Kod', value: (row) => row.code },
        { key: 'active', label: 'Durum', value: (row) => (row.is_active ? 'Etkin' : 'Arşiv') },
        {
            key: 'count',
            label: 'Etkin seçenek',
            value: (row) => row.items.filter((item) => item.is_active).length,
        },
    ];
    return (
        <AppShell
            applicationName={applicationName}
            navigation={navigation}
            pageTitle="Seçenek listeleri"
            toolbar={
                <>
                    <Link className="button" href={modulesUrl}>
                        Sayfa düzenlemesine dön
                    </Link>
                    <div className="active-filters">
                        <strong>Aktif filtreler</strong>
                        <span className="muted">{query || 'Filtre uygulanmadı'}</span>
                    </div>
                    {allowCreate && (
                        <button
                            type="button"
                            className="button"
                            onClick={() => setCreating((value) => !value)}
                        >
                            Yeni seçenek listesi
                        </button>
                    )}
                </>
            }
            search={
                <div className="search-field">
                    <label htmlFor="option-search">Hızlı liste ara</label>
                    <input
                        id="option-search"
                        type="search"
                        value={query}
                        onChange={(event) => setQuery(event.target.value)}
                    />
                </div>
            }
        >
            <Head title="Seçenek listeleri" />
            {creating && (
                <PortalDialog
                    title="Yeni seçenek listesi"
                    busy={form.processing}
                    onClose={() => setCreating(false)}
                >
                    <section className="editor-panel">
                        <h2>Yeni seçenek listesi</h2>
                        <form
                            className="definition-form"
                            onSubmit={(event) => {
                                event.preventDefault();
                                form.post(baseUrl, {
                                    onSuccess: () => {
                                        form.reset();
                                        setCreating(false);
                                    },
                                });
                            }}
                        >
                            <label htmlFor="new-option-name">Liste adı</label>
                            <input
                                id="new-option-name"
                                required
                                maxLength={150}
                                value={form.data.name}
                                onChange={(event) => form.setData('name', event.target.value)}
                            />
                            <label htmlFor="new-option-code">Sabit kod</label>
                            <input
                                id="new-option-code"
                                required
                                maxLength={80}
                                value={form.data.code}
                                onChange={(event) => form.setData('code', event.target.value)}
                            />
                            {Object.values(form.errors).map((error, index) => (
                                <p className="form-error" role="alert" key={index}>
                                    {error}
                                </p>
                            ))}
                            <button className="button button--primary" disabled={form.processing}>
                                Liste oluştur
                            </button>
                        </form>
                    </section>
                </PortalDialog>
            )}
            {list && (
                <PortalDialog
                    title="Seçenek listesini düzenle"
                    busy={listBusy}
                    onClose={() => setSelected(null)}
                >
                    <ListEditor
                        key={`${list.id}-${list.revision}`}
                        list={list}
                        baseUrl={baseUrl}
                        close={() => setSelected(null)}
                        onBusy={setListBusy}
                    />
                </PortalDialog>
            )}
            <DataTable
                rows={lists.filter((row) => searchName(`${row.name} ${row.code}`, query))}
                columns={columns}
                onRowClick={(row) => setSelected(row.id)}
                rowKey={(row) => row.id}
                caption="Seçenek listeleri"
            />
        </AppShell>
    );
}
