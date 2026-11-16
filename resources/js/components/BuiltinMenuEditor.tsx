export type BuiltinMenuItem = {
    key: string;
    label: string;
    parent: string | null;
    position: number;
    is_active: boolean;
    icon?: string;
    target?: string;
    url?: string | null;
    visible_for?: number[];
};
export type BuiltinMenuState = {
    revision: number;
    items: BuiltinMenuItem[];
    groupKeys?: string[];
    users?: { id: number; full_name: string; username: string }[];
};
import { useState } from 'react';
import { router } from '@inertiajs/react';
import { useAdminMutation } from '../lib/adminMutation';
import { portalUrl } from '../lib/portalUrl';
export default function BuiltinMenuEditor({ state }: { state: BuiltinMenuState }) {
    return <Editor key={state.revision} state={state} />;
}
function Editor({ state }: { state: BuiltinMenuState }) {
    const [items, setItems] = useState(state.items);
    const mutation = useAdminMutation({
        onSaved: async () => {
            router.reload();
        },
    });
    return (
        <section className="editor-panel">
            <h2>Eski uygulamanın ana menüsü</h2>
            <p>
                Başlık, sıra ve görünürlüğü düzenleyin. Menüde gizleme sayfa erişim yetkisini değiştirmez. Ana
                Sayfa en üstte kalır.
            </p>
            <div className="admin-definition-list">
                {items.map((item, index) => (
                    <div className="admin-filters" key={item.key}>
                        <label>
                            {item.parent ? 'Alt menü' : 'Ana grup'}{' '}
                            <input
                                value={item.label}
                                maxLength={150}
                                required
                                disabled={mutation.busy}
                                onChange={(e) =>
                                    setItems(
                                        items.map((x, i) =>
                                            i === index ? { ...x, label: e.target.value } : x,
                                        ),
                                    )
                                }
                            />
                        </label>
                        <label>
                            Üst grup{' '}
                            <select
                                disabled={mutation.busy}
                                value={item.parent ?? ''}
                                onChange={(e) =>
                                    setItems(
                                        items.map((x, i) =>
                                            i === index ? { ...x, parent: e.target.value || null } : x,
                                        ),
                                    )
                                }
                            >
                                <option value="">Ana menü</option>
                                {items
                                    .filter(
                                        (x) =>
                                            x.key !== item.key &&
                                            ((state.groupKeys ?? []).includes(x.key) ||
                                                (x.key.startsWith('custom-') && !x.url)),
                                    )
                                    .map((x) => (
                                        <option key={x.key} value={x.key}>
                                            {x.label}
                                        </option>
                                    ))}
                            </select>
                        </label>
                        <label>
                            İkon{' '}
                            <input
                                value={item.icon ?? ''}
                                placeholder="folder"
                                maxLength={50}
                                onChange={(e) =>
                                    setItems(
                                        items.map((x, i) =>
                                            i === index ? { ...x, icon: e.target.value } : x,
                                        ),
                                    )
                                }
                            />
                        </label>
                        <label>
                            Hedef{' '}
                            <select
                                value={item.target ?? '_self'}
                                onChange={(e) =>
                                    setItems(
                                        items.map((x, i) =>
                                            i === index ? { ...x, target: e.target.value } : x,
                                        ),
                                    )
                                }
                            >
                                <option value="_self">Aynı pencere</option>
                                <option value="_blank">Yeni pencere</option>
                            </select>
                        </label>
                        {item.key.startsWith('custom-') && (
                            <label>
                                Bağlantı (boşsa grup)
                                <input
                                    value={item.url ?? ''}
                                    onChange={(e) =>
                                        setItems(
                                            items.map((x, i) =>
                                                i === index ? { ...x, url: e.target.value || null } : x,
                                            ),
                                        )
                                    }
                                />
                            </label>
                        )}
                        <label>
                            Kişisel görünürlük (boşsa herkes)
                            <select
                                multiple
                                value={(item.visible_for ?? []).map(String)}
                                onChange={(e) =>
                                    setItems(
                                        items.map((x, i) =>
                                            i === index
                                                ? {
                                                      ...x,
                                                      visible_for: Array.from(e.target.selectedOptions).map(
                                                          (o) => Number(o.value),
                                                      ),
                                                  }
                                                : x,
                                        ),
                                    )
                                }
                            >
                                {state.users?.map((u) => (
                                    <option key={u.id} value={u.id}>
                                        {u.full_name || u.username}
                                    </option>
                                ))}
                            </select>
                        </label>
                        <label>
                            Sıra{' '}
                            <input
                                type="number"
                                min={0}
                                max={1000}
                                value={item.position}
                                disabled={mutation.busy}
                                onChange={(e) =>
                                    setItems(
                                        items.map((x, i) =>
                                            i === index ? { ...x, position: Number(e.target.value) } : x,
                                        ),
                                    )
                                }
                            />
                        </label>
                        <label>
                            <input
                                type="checkbox"
                                checked={item.is_active}
                                disabled={mutation.busy}
                                onChange={(e) =>
                                    setItems(
                                        items.map((x, i) =>
                                            i === index ? { ...x, is_active: e.target.checked } : x,
                                        ),
                                    )
                                }
                            />{' '}
                            Göster
                        </label>
                    </div>
                ))}
            </div>
            <button
                className="button"
                disabled={mutation.busy}
                onClick={() =>
                    setItems([
                        ...items,
                        {
                            key: 'custom-' + crypto.randomUUID(),
                            label: 'Yeni öğe',
                            parent: null,
                            position: items.length,
                            is_active: true,
                            icon: '',
                            target: '_self',
                            url: null,
                            visible_for: [],
                        },
                    ])
                }
            >
                Bağlantı / grup ekle
            </button>
            {mutation.error && (
                <p role="alert" className="form-error">
                    {mutation.error}
                </p>
            )}
            <button
                className="button button--primary"
                disabled={mutation.busy}
                onClick={() =>
                    void mutation.submit(portalUrl('/yonetim/menu/sabit'), {
                        revision: state.revision,
                        items,
                    })
                }
            >
                Ana menüyü kaydet
            </button>
        </section>
    );
}
