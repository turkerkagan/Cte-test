import { portalFetch } from '../lib/http';
import { useState } from 'react';
import { Head, router } from '@inertiajs/react';
import BuiltinMenuEditor, { type BuiltinMenuState } from '../components/BuiltinMenuEditor';
import AppShell from '../components/AppShell';
type Item = {
    id: number | null;
    key: string;
    parent_key: string | null;
    label: string;
    page_id: number | null;
    is_active: boolean;
};
type Props = {
    builtinMenu: BuiltinMenuState;
    applicationName: string;
    navigation?: { label: string; url: string }[];
    revision: number;
    configured: boolean;
    items: Item[];
    pages: { id: number; name: string }[];
    saveUrl: string;
    logoutUrl: string;
};
export default function MenuEditor(props: Props) {
    return <Editor key={props.revision} {...props} />;
}
function Editor({
    builtinMenu,
    applicationName,
    navigation,
    revision,
    configured,
    items: saved,
    pages,
    saveUrl,
}: Props) {
    const [items, setItems] = useState<Item[]>(
        configured
            ? saved
            : pages.map((page) => ({
                  id: null,
                  key: `page-${page.id}`,
                  parent_key: null,
                  label: page.name,
                  page_id: page.id,
                  is_active: true,
              })),
    );
    const [processing, setProcessing] = useState(false),
        [conflict, setConflict] = useState(false),
        [errors, setErrors] = useState<string[]>([]);
    function update(key: string, patch: Partial<Item>) {
        setItems((current) => current.map((item) => (item.key === key ? { ...item, ...patch } : item)));
    }
    function move(index: number, change: number) {
        setItems((current) => {
            const next = [...current];
            [next[index], next[index + change]] = [next[index + change], next[index]];
            return next;
        });
    }
    async function save() {
        setProcessing(true);
        setErrors([]);
        try {
            const response = await portalFetch(saveUrl, {
                method: 'PUT',
                credentials: 'same-origin',
                headers: { Accept: 'application/json', 'Content-Type': 'application/json' },
                body: JSON.stringify({ revision, items }),
            });
            if (!response.headers.get('content-type')?.includes('application/json'))
                throw new Error('Oturumu kontrol edin. Menü değişiklikleriniz korunuyor.');
            const result = await response.json();
            if (!response.ok) {
                setConflict(response.status === 409);
                setErrors(
                    result.errors
                        ? (Object.values(result.errors).flat() as string[])
                        : [result.message ?? 'Menü kaydedilemedi.'],
                );
                return;
            }
            router.reload({ only: ['items', 'revision', 'configured', 'pages', 'navigation'] });
        } catch (error) {
            setErrors([error instanceof Error ? error.message : 'Menü kaydedilemedi.']);
        } finally {
            setProcessing(false);
        }
    }
    return (
        <AppShell
            applicationName={applicationName}
            navigation={navigation}
            pageTitle="Menü düzenlemesi"
            toolbar={<strong>Sol menü</strong>}
            search={
                <p className="muted">
                    Yayımlanmış sayfaları gruplandırın, sıralayın veya gizleyin. Menü görünürlüğü erişim
                    iznini değiştirmez.
                </p>
            }
        >
            <Head title="Menü düzenlemesi" />
            <BuiltinMenuEditor state={builtinMenu} />
            <section className="editor-panel">
                <h2>Menü yapısı</h2>
                <p className="muted">
                    Ana sayfa, yönetim paneli ve parola bağlantıları korunur. İlk kayıttan sonra yeni
                    yayımlanan modülleri bu menüye ayrıca ekleyin. Menüden kaldırmak sayfayı veya kayıtlarını
                    silmez.
                </p>
                <form
                    className="definition-form"
                    onSubmit={(event) => {
                        event.preventDefault();
                        void save();
                    }}
                >
                    {items.map((item, index) => {
                        const hasChildren = items.some((child) => child.parent_key === item.key);
                        return (
                            <fieldset
                                className="module-field"
                                key={item.key}
                                disabled={processing || conflict}
                            >
                                <legend>{index + 1}. menü öğesi</legend>
                                <div className="user-fields">
                                    <div className="form-field">
                                        <label htmlFor={`menu-label-${item.key}`}>Başlık</label>
                                        <input
                                            id={`menu-label-${item.key}`}
                                            required
                                            maxLength={150}
                                            value={item.label}
                                            onChange={(event) =>
                                                update(item.key, { label: event.target.value })
                                            }
                                        />
                                    </div>
                                    <div className="form-field">
                                        <label htmlFor={`menu-page-${item.key}`}>Hedef sayfa</label>
                                        <select
                                            id={`menu-page-${item.key}`}
                                            disabled={hasChildren}
                                            value={item.page_id ?? ''}
                                            required={item.parent_key !== null}
                                            onChange={(event) =>
                                                update(item.key, {
                                                    page_id: event.target.value
                                                        ? Number(event.target.value)
                                                        : null,
                                                })
                                            }
                                        >
                                            <option value="">Grup başlığı</option>
                                            {pages.map((page) => (
                                                <option key={page.id} value={page.id}>
                                                    {page.name}
                                                </option>
                                            ))}
                                            {item.page_id &&
                                                !pages.some((page) => page.id === item.page_id) && (
                                                    <option value={item.page_id}>
                                                        Hedef şu an kullanılamıyor
                                                    </option>
                                                )}
                                        </select>
                                    </div>
                                    <div className="form-field">
                                        <label htmlFor={`menu-parent-${item.key}`}>Üst grup</label>
                                        <select
                                            id={`menu-parent-${item.key}`}
                                            disabled={item.page_id === null || hasChildren}
                                            value={item.parent_key ?? ''}
                                            onChange={(event) =>
                                                update(item.key, { parent_key: event.target.value || null })
                                            }
                                        >
                                            <option value="">Ana menü</option>
                                            {items
                                                .filter(
                                                    (group) =>
                                                        group.key !== item.key &&
                                                        group.page_id === null &&
                                                        group.parent_key === null,
                                                )
                                                .map((group) => (
                                                    <option key={group.key} value={group.key}>
                                                        {group.label || 'Başlıksız grup'}
                                                    </option>
                                                ))}
                                        </select>
                                    </div>
                                </div>
                                <label className="checkbox-field">
                                    <input
                                        type="checkbox"
                                        checked={item.is_active}
                                        onChange={(event) =>
                                            update(item.key, { is_active: event.target.checked })
                                        }
                                    />
                                    Menüde göster
                                </label>
                                <div className="row-actions">
                                    <button
                                        className="button"
                                        type="button"
                                        disabled={index === 0}
                                        onClick={() => move(index, -1)}
                                    >
                                        Yukarı
                                    </button>
                                    <button
                                        className="button"
                                        type="button"
                                        disabled={index === items.length - 1}
                                        onClick={() => move(index, 1)}
                                    >
                                        Aşağı
                                    </button>
                                    <button
                                        className="button button--danger"
                                        type="button"
                                        onClick={() => {
                                            if (
                                                hasChildren &&
                                                !window.confirm(
                                                    'Grubu kaldırınca alt öğeleri ana menüye taşınacak. Devam edilsin mi?',
                                                )
                                            )
                                                return;
                                            setItems((current) =>
                                                current
                                                    .filter((entry) => entry.key !== item.key)
                                                    .map((entry) =>
                                                        entry.parent_key === item.key
                                                            ? { ...entry, parent_key: null }
                                                            : entry,
                                                    ),
                                            );
                                        }}
                                    >
                                        Menüden kaldır
                                    </button>
                                </div>
                            </fieldset>
                        );
                    })}
                    {errors.map((error, index) => (
                        <p className="form-error" role="alert" key={index}>
                            {error}
                        </p>
                    ))}
                    {conflict && (
                        <p className="form-error">
                            Girdileriniz korunuyor. Güncel menüyü yeniden açıp karşılaştırın.
                        </p>
                    )}
                    <div className="row-actions">
                        <button
                            type="button"
                            className="button"
                            disabled={processing || conflict || items.length >= 100}
                            onClick={() =>
                                setItems([
                                    ...items,
                                    {
                                        id: null,
                                        key: `new-${crypto.randomUUID()}`,
                                        parent_key: null,
                                        label: '',
                                        page_id: null,
                                        is_active: true,
                                    },
                                ])
                            }
                        >
                            Öğe / grup ekle
                        </button>
                        <button
                            className="button button--primary"
                            type="submit"
                            disabled={processing || conflict}
                        >
                            Menüyü kaydet
                        </button>
                    </div>
                </form>
            </section>
        </AppShell>
    );
}
