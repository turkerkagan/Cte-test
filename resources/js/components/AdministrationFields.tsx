import type { DefinitionItem } from '../lib/adminTypes';
import { useState } from 'react';
type Props = {
    defaults: Record<string, DefinitionItem>;
    groups: Record<string, string>;
    sources: Record<string, string>;
    optionLists: { code: string; label: string }[];
    items: DefinitionItem[];
    setItems: (items: DefinitionItem[]) => void;
    busy: boolean;
    initialGroup?: string;
    fieldKeys?: string[];
};
const types = [
    ['text', 'Metin'],
    ['longtext', 'Uzun metin'],
    ['integer', 'Tam sayı'],
    ['decimal', 'Ondalık'],
    ['date', 'Tarih'],
    ['datetime', 'Tarih ve saat'],
    ['time', 'Saat'],
    ['boolean', 'Evet / hayır'],
    ['option', 'Seçenek'],
];
export default function AdministrationFields(p: Props) {
    const [group, setGroup] = useState(p.initialGroup ?? Object.keys(p.groups)[0]),
        [adding, setAdding] = useState(false),
        [code, setCode] = useState('custom_'),
        [label, setLabel] = useState(''),
        [type, setType] = useState('text'),
        [err, setErr] = useState('');
    const configured = new Set(p.items.map((i) => i.key));
    const all: DefinitionItem[] = [
        ...Object.entries(p.defaults)
            .filter(([key]) => !configured.has(key))
            .map(([key, f], position) => ({
                key,
                label: f.label,
                type: f.type,
                group: f.group,
                table_visible: f.table_visible ?? true,
                form_visible: f.form_visible ?? true,
                required: f.required ?? false,
                readonly: f.readonly ?? false,
                position,
                filter_visible: true,
                sortable: true,
                max_length: f.type === 'longtext' ? 10000 : 255,
                default: null,
                option_list: null,
            })),
        ...p.items,
    ];
    const update = (item: DefinitionItem, patch: Partial<DefinitionItem>) =>
        p.setItems(
            p.items.some((x) => x.key === item.key)
                ? p.items.map((x) => (x.key === item.key ? { ...x, ...patch } : x))
                : [...p.items, { ...item, ...patch }],
        );
    return (
        <section className="editor-panel">
            <h2>Sayfa alanları</h2>
            {!p.initialGroup && (
                <label>
                    Birim{' '}
                    <select
                        value={group}
                        onChange={(e) => {
                            setGroup(e.target.value);
                            setAdding(false);
                        }}
                    >
                        {Object.entries(p.groups).map(([key, name]) => (
                            <option key={key} value={key}>
                                {name}
                            </option>
                        ))}
                    </select>
                </label>
            )}
            <p>Veri kaynağı: {p.sources[group]} · Ayarlar form, tablo ve çıktılara uygulanır.</p>
            <div className="admin-definition-list">
                {all
                    .filter((i) =>
                        p.fieldKeys
                            ? p.fieldKeys.includes(i.key ?? '') || i.group === group
                            : i.group === group,
                    )
                    .sort((a, b) => (a.position ?? 0) - (b.position ?? 0))
                    .map((item) => (
                        <fieldset key={item.key} disabled={p.busy}>
                            <legend>{item.key}</legend>
                            <div className="user-fields">
                                <label className="form-field">
                                    <span>Başlık</span>
                                    <input
                                        maxLength={150}
                                        required
                                        value={item.label}
                                        onChange={(e) => update(item, { label: e.target.value })}
                                    />
                                </label>
                                <label className="form-field">
                                    <span>Sıra</span>
                                    <input
                                        type="number"
                                        min={0}
                                        max={9999}
                                        value={item.position}
                                        onChange={(e) => update(item, { position: Number(e.target.value) })}
                                    />
                                </label>
                                <label className="form-field">
                                    <span>Metin uzunluğu</span>
                                    <input
                                        type="number"
                                        min={1}
                                        max={10000}
                                        value={item.max_length ?? 255}
                                        onChange={(e) => update(item, { max_length: Number(e.target.value) })}
                                    />
                                </label>
                                <label className="form-field">
                                    <span>Varsayılan değer</span>
                                    <input
                                        maxLength={1000}
                                        value={item.default ?? ''}
                                        onChange={(e) => update(item, { default: e.target.value || null })}
                                    />
                                </label>
                                {item.type === 'option' && (
                                    <label className="form-field">
                                        <span>Seçenek listesi</span>
                                        <select
                                            value={item.option_list ?? (item.key ?? '').replaceAll('_', '-')}
                                            onChange={(e) => update(item, { option_list: e.target.value })}
                                        >
                                            <option value="">Seçin</option>
                                            {p.optionLists.map((o) => (
                                                <option key={o.code} value={o.code}>
                                                    {o.label}
                                                </option>
                                            ))}
                                        </select>
                                    </label>
                                )}
                                <p>Tür: {types.find(([t]) => t === item.type)?.[1] ?? item.type}</p>
                            </div>
                            {[
                                ['table_visible', 'Tabloda göster'],
                                ['form_visible', 'Formda göster'],
                                ['required', 'Zorunlu'],
                                ['readonly', 'Salt okunur'],
                                ['filter_visible', 'Filtrelenebilir'],
                                ['sortable', 'Sıralanabilir'],
                            ].map(([key, name]) => (
                                <label key={key}>
                                    <input
                                        type="checkbox"
                                        checked={
                                            item[key as keyof DefinitionItem] === undefined
                                                ? true
                                                : item[key as keyof DefinitionItem] === true
                                        }
                                        onChange={(e) => update(item, { [key]: e.target.checked })}
                                    />
                                    {name}{' '}
                                </label>
                            ))}
                        </fieldset>
                    ))}
            </div>
            <button className="button" disabled={p.busy} onClick={() => setAdding(true)}>
                Alan ekle
            </button>
            {adding && (
                <form
                    className="definition-form"
                    onSubmit={(e) => {
                        e.preventDefault();
                        const key = p.sources[group] === 'institution_records' ? group + '__' + code : code;
                        if (all.some((x) => x.key === key)) {
                            setErr('Bu kod kullanılıyor.');
                            return;
                        }
                        p.setItems([
                            ...p.items,
                            {
                                key,
                                label,
                                type,
                                group,
                                table_visible: true,
                                form_visible: true,
                                required: false,
                                readonly: false,
                                position: 999,
                                filter_visible: true,
                                sortable: true,
                                max_length: 255,
                                default: null,
                                option_list: null,
                            },
                        ]);
                        setAdding(false);
                        setErr('');
                    }}
                >
                    <label>
                        Kod{' '}
                        <input
                            required
                            pattern="custom_[a-z0-9_]+"
                            maxLength={50}
                            value={code}
                            onChange={(e) => setCode(e.target.value)}
                        />
                    </label>
                    <label>
                        Başlık{' '}
                        <input
                            required
                            maxLength={150}
                            value={label}
                            onChange={(e) => setLabel(e.target.value)}
                        />
                    </label>
                    <label>
                        Tür{' '}
                        <select value={type} onChange={(e) => setType(e.target.value)}>
                            {types.map(([t, name]) => (
                                <option key={t} value={t}>
                                    {name}
                                </option>
                            ))}
                        </select>
                    </label>
                    {err && <p role="alert">{err}</p>}
                    <button className="button">Taslağa ekle</button>
                </form>
            )}
        </section>
    );
}
