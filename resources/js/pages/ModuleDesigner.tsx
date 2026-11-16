import { portalFetch } from '../lib/http';
import { Head, Link, router, useForm } from '@inertiajs/react';
import { useRef, useState } from 'react';
import AppShell from '../components/AppShell';
import DataTable from '../components/DataTable';
import type { Column } from '../lib/table';
import type { OptionList } from './OptionLists';
export type FieldType =
    'text' | 'longtext' | 'integer' | 'decimal' | 'date' | 'datetime' | 'boolean' | 'select' | 'multiselect';
export type ModuleField = {
    id: number | null;
    type: FieldType;
    label: string;
    helper: string;
    required: boolean;
    show_in_form: boolean;
    show_in_table: boolean;
    option_list_id?: number | null;
};
type LocalField = ModuleField & { localKey: string };
type Module = { id: number; code: string; name: string; record_mode: string; revision: number };
type Version = {
    id: number;
    number: number;
    revision: number;
    status: string;
    definition: { name: string; fields: ModuleField[] };
};
type Publication = {
    revision: number;
    version_id: number;
    version_revision: number;
    record_count: number;
    record_snapshot: string;
    blocked: string[];
};
type Props = {
    applicationName: string;
    navigation?: { label: string; url: string }[];
    module: Module;
    version: Version;
    history: { id: number; number: number; status: string }[];
    baseUrl: string;
    logoutUrl: string;
    saveUrl: string;
    forkUrl: string;
    optionsUrl?: string;
    optionLists?: OptionList[];
    errors?: Record<string, string>;
    publication?: Publication | null;
    publishUrl?: string;
    runtimeUrl?: string | null;
};
function PublicationPanel({
    impact,
    url,
    disabled,
}: {
    impact: Publication;
    url: string;
    disabled: boolean;
}) {
    const form = useForm({ ...impact, confirmed: false });
    return (
        <section className="editor-panel">
            <h2>Kaydedilmiş taslağı yayımla</h2>
            <p>
                {impact.record_count} kayıt etkileniyor. Başlık ve görünüm değişikliklerinde değerler korunur;
                kayıt sürümleri güncellenir.
            </p>
            {impact.blocked.map((message, i) => (
                <p className="form-error" key={i}>
                    {message}
                </p>
            ))}
            {disabled && <p className="form-error">Önce taslak değişikliklerini kaydedin.</p>}
            <form
                className="definition-form"
                onSubmit={(event) => {
                    event.preventDefault();
                    form.post(url);
                }}
            >
                <label className="checkbox-field">
                    <input
                        type="checkbox"
                        disabled={disabled || form.processing}
                        checked={form.data.confirmed}
                        onChange={(event) => form.setData('confirmed', event.target.checked)}
                    />
                    Kaydedilmiş taslağı ve etkilenen kayıt sayısını kontrol ettim.
                </label>
                {Object.values(form.errors).map((message, i) => (
                    <p className="form-error" role="alert" key={i}>
                        {message}
                    </p>
                ))}
                <button
                    className="button button--primary"
                    disabled={
                        disabled || form.processing || !form.data.confirmed || impact.blocked.length > 0
                    }
                >
                    Kaydedilmiş taslağı yayımla
                </button>
            </form>
        </section>
    );
}
const typeLabels: Record<FieldType, string> = {
    text: 'Kısa metin',
    longtext: 'Uzun metin',
    integer: 'Tam sayı',
    decimal: 'Ondalık sayı',
    date: 'Tarih',
    datetime: 'Tarih ve saat',
    boolean: 'Evet / hayır',
    select: 'Tek seçim',
    multiselect: 'Çoklu seçim',
};
function Editor({
    module,
    version,
    saveUrl,
    optionLists = [],
    publication,
    publishUrl,
}: Pick<Props, 'module' | 'version' | 'saveUrl' | 'optionLists' | 'publication' | 'publishUrl'>) {
    const counter = useRef(0);
    const [name, setName] = useState(version.definition.name);
    const [fields, setFields] = useState<LocalField[]>(
        version.definition.fields.map((field) => ({ ...field, localKey: `saved-${field.id}` })),
    );
    const [errors, setErrors] = useState<string[]>([]);
    const [conflict, setConflict] = useState(false);
    const [processing, setProcessing] = useState(false);
    const editable = version.status === 'draft';
    function update(index: number, values: Partial<ModuleField>) {
        setFields((current) => current.map((field, i) => (i === index ? { ...field, ...values } : field)));
    }
    function move(index: number, direction: number) {
        setFields((current) => {
            const next = [...current];
            [next[index], next[index + direction]] = [next[index + direction], next[index]];
            return next;
        });
    }
    async function save() {
        setProcessing(true);
        setErrors([]);
        setConflict(false);
        try {
            const response = await portalFetch(saveUrl, {
                method: 'PUT',
                credentials: 'same-origin',
                headers: { Accept: 'application/json', 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    revision: module.revision,
                    version_id: version.id,
                    version_revision: version.revision,
                    name,
                    fields: fields.map(({ localKey, ...field }) => field),
                }),
            });
            if (!response.headers.get('content-type')?.includes('application/json'))
                throw new Error('İşlem tamamlanamadı. Oturumu kontrol edip yeniden deneyin.');
            const result = await response.json();
            if (!response.ok) {
                setConflict(response.status === 409);
                setErrors(
                    result.errors
                        ? (Object.values(result.errors).flat() as string[])
                        : [
                              response.status === 419
                                  ? 'Oturum doğrulaması yenilenmeli. Sayfayı yeniden açın.'
                                  : (result.message ?? 'Taslak kaydedilemedi.'),
                          ],
                );
                return;
            }
            router.reload({
                only: ['module', 'version', 'history', 'optionLists', 'publication', 'runtimeUrl'],
            });
        } catch (error) {
            setErrors([error instanceof Error ? error.message : 'Taslak kaydedilemedi.']);
        } finally {
            setProcessing(false);
        }
    }
    const columns: Column<Record<string, never>>[] = [
        { key: 'sequence', label: 'ID', kind: 'sequence', value: () => null },
        ...fields
            .filter((field) => field.show_in_table)
            .map((field) => ({
                key: field.localKey,
                label: field.label || 'Başlıksız alan',
                value: () => null,
            })),
    ];
    return (
        <>
            <section className="editor-panel">
                <h2>Taslak alanları · sürüm {version.number}</h2>
                <form
                    className="definition-form"
                    onSubmit={(event) => {
                        event.preventDefault();
                        void save();
                    }}
                >
                    <label htmlFor="designer-name">Modül adı</label>
                    <input
                        id="designer-name"
                        required
                        maxLength={150}
                        disabled={!editable || processing}
                        value={name}
                        onChange={(event) => setName(event.target.value)}
                    />
                    {fields.map((field, index) => (
                        <fieldset
                            className="module-field"
                            key={field.localKey}
                            disabled={!editable || processing}
                        >
                            <legend>
                                {index + 1}. alan{field.id !== null && ` · kimlik ${field.id}`}
                            </legend>
                            <div className="user-fields">
                                <div className="form-field">
                                    <label htmlFor={`label-${field.localKey}`}>Başlık</label>
                                    <input
                                        id={`label-${field.localKey}`}
                                        required
                                        maxLength={150}
                                        value={field.label}
                                        onChange={(event) => update(index, { label: event.target.value })}
                                    />
                                </div>
                                <div className="form-field">
                                    <label htmlFor={`type-${field.localKey}`}>Alan türü</label>
                                    <select
                                        id={`type-${field.localKey}`}
                                        disabled={field.id !== null}
                                        value={field.type}
                                        onChange={(event) =>
                                            update(index, {
                                                type: event.target.value as FieldType,
                                                option_list_id: null,
                                            })
                                        }
                                    >
                                        {Object.entries(typeLabels).map(([value, label]) => (
                                            <option key={value} value={value}>
                                                {label}
                                            </option>
                                        ))}
                                    </select>
                                </div>
                            </div>
                            {(field.type === 'select' || field.type === 'multiselect') && (
                                <div className="form-field">
                                    <label htmlFor={`source-${field.localKey}`}>Seçenek listesi</label>
                                    <select
                                        id={`source-${field.localKey}`}
                                        required
                                        disabled={field.id !== null}
                                        value={field.option_list_id ?? ''}
                                        onChange={(event) =>
                                            update(index, {
                                                option_list_id: event.target.value
                                                    ? Number(event.target.value)
                                                    : null,
                                            })
                                        }
                                    >
                                        <option value="">Liste seçin</option>
                                        {optionLists
                                            .filter(
                                                (list) => list.is_active || list.id === field.option_list_id,
                                            )
                                            .map((list) => (
                                                <option key={list.id} value={list.id}>
                                                    {list.name}
                                                    {!list.is_active && ' (arşiv)'}
                                                </option>
                                            ))}
                                    </select>
                                    <span className="muted">
                                        Kaydedildikten sonra listenin kimliği değişmez.
                                    </span>
                                </div>
                            )}
                            <div className="form-field">
                                <label htmlFor={`helper-${field.localKey}`}>Yardımcı açıklama</label>
                                <input
                                    id={`helper-${field.localKey}`}
                                    maxLength={255}
                                    value={field.helper}
                                    onChange={(event) => update(index, { helper: event.target.value })}
                                />
                            </div>
                            <div className="row-actions">
                                <label className="checkbox-field">
                                    <input
                                        type="checkbox"
                                        checked={field.required}
                                        onChange={(event) =>
                                            update(index, {
                                                required: event.target.checked,
                                                show_in_form: event.target.checked || field.show_in_form,
                                            })
                                        }
                                    />
                                    Zorunlu
                                </label>
                                <label className="checkbox-field">
                                    <input
                                        type="checkbox"
                                        disabled={field.required}
                                        checked={field.show_in_form}
                                        onChange={(event) =>
                                            update(index, { show_in_form: event.target.checked })
                                        }
                                    />
                                    Formda göster
                                </label>
                                <label className="checkbox-field">
                                    <input
                                        type="checkbox"
                                        checked={field.show_in_table}
                                        onChange={(event) =>
                                            update(index, { show_in_table: event.target.checked })
                                        }
                                    />
                                    Tabloda göster
                                </label>
                            </div>
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
                                    disabled={index === fields.length - 1}
                                    onClick={() => move(index, 1)}
                                >
                                    Aşağı
                                </button>
                                <button
                                    type="button"
                                    className="button button--danger"
                                    onClick={() =>
                                        setFields((current) => current.filter((_, i) => i !== index))
                                    }
                                >
                                    Taslağın alanını kaldır
                                </button>
                            </div>
                        </fieldset>
                    ))}
                    {editable && (
                        <button
                            type="button"
                            className="button"
                            disabled={processing || fields.length >= 100}
                            onClick={() =>
                                setFields((current) => [
                                    ...current,
                                    {
                                        localKey: `new-${++counter.current}`,
                                        id: null,
                                        type: 'text',
                                        label: '',
                                        helper: '',
                                        required: false,
                                        show_in_form: true,
                                        show_in_table: true,
                                    },
                                ])
                            }
                        >
                            Alan ekle
                        </button>
                    )}
                    {errors.length > 0 && (
                        <div className="form-error" role="alert">
                            {errors.map((error, i) => (
                                <p key={i}>{error}</p>
                            ))}
                        </div>
                    )}
                    {conflict && (
                        <button
                            type="button"
                            className="button"
                            onClick={() =>
                                router.visit(window.location.href, {
                                    preserveState: false,
                                    preserveScroll: true,
                                })
                            }
                        >
                            Sunucudaki taslağı yükle (yazdıklarınız silinir)
                        </button>
                    )}
                    {editable && (
                        <button className="button button--primary" disabled={processing}>
                            Taslağı kaydet
                        </button>
                    )}
                </form>
            </section>
            <section className="editor-panel" aria-labelledby="module-preview">
                <h2 id="module-preview">Form önizlemesi · {name}</h2>
                <p className="muted">Bu önizleme veri kaydı oluşturmaz.</p>
                <div className="definition-form">
                    {fields
                        .filter((field) => field.show_in_form)
                        .map((field) => {
                            const list = optionLists.find((entry) => entry.id === field.option_list_id);
                            return (
                                <div className="form-field" key={field.localKey}>
                                    <label htmlFor={`preview-${field.localKey}`}>
                                        {field.label || 'Başlıksız alan'}
                                        {field.required && ' *'}
                                    </label>
                                    {field.type === 'select' || field.type === 'multiselect' ? (
                                        <select
                                            id={`preview-${field.localKey}`}
                                            disabled
                                            multiple={field.type === 'multiselect'}
                                        >
                                            {field.type === 'select' && <option value="">Seçin</option>}
                                            {list?.is_active &&
                                                list.items
                                                    .filter((item) => item.is_active)
                                                    .map((item) => (
                                                        <option key={item.id} value={item.id ?? ''}>
                                                            {item.label}
                                                        </option>
                                                    ))}
                                        </select>
                                    ) : field.type === 'boolean' ? (
                                        <input id={`preview-${field.localKey}`} type="checkbox" disabled />
                                    ) : field.type === 'longtext' ? (
                                        <textarea id={`preview-${field.localKey}`} readOnly rows={3} />
                                    ) : (
                                        <input
                                            id={`preview-${field.localKey}`}
                                            readOnly
                                            type={
                                                field.type === 'integer' || field.type === 'decimal'
                                                    ? 'number'
                                                    : field.type === 'datetime'
                                                      ? 'datetime-local'
                                                      : field.type === 'date'
                                                        ? 'date'
                                                        : 'text'
                                            }
                                        />
                                    )}
                                    {(field.type === 'select' || field.type === 'multiselect') &&
                                        (!list?.is_active || !list.items.some((item) => item.is_active)) && (
                                            <span className="muted">
                                                Etkin seçenek yok. Seçenek listelerini kontrol edin.
                                            </span>
                                        )}
                                    {field.helper && <span className="muted">{field.helper}</span>}
                                </div>
                            );
                        })}
                </div>
            </section>
            <DataTable rows={[]} columns={columns} rowKey={() => 'preview'} caption="Tablo önizlemesi" />
            {editable && publication && publishUrl && (
                <PublicationPanel
                    impact={publication}
                    url={publishUrl}
                    disabled={
                        processing ||
                        name !== version.definition.name ||
                        JSON.stringify(fields.map(({ localKey, ...field }) => field)) !==
                            JSON.stringify(version.definition.fields)
                    }
                />
            )}
        </>
    );
}
export default function ModuleDesigner({
    applicationName,
    navigation,
    module,
    version,
    history,
    baseUrl,
    saveUrl,
    forkUrl,
    optionsUrl,
    optionLists = [],
    errors = {},
    publication,
    publishUrl,
    runtimeUrl,
}: Props) {
    return (
        <AppShell
            applicationName={applicationName}
            navigation={navigation}
            pageTitle="Modül tasarımı"
            toolbar={
                <>
                    <Link className="button" href={baseUrl}>
                        Modüllere dön
                    </Link>
                    {optionsUrl && (
                        <Link className="button" href={optionsUrl}>
                            Seçenek listeleri
                        </Link>
                    )}
                    {runtimeUrl && (
                        <Link className="button" href={runtimeUrl}>
                            Yayımlanmış sayfayı aç
                        </Link>
                    )}
                    <span className="muted">
                        {module.code} · {version.status === 'draft' ? 'Taslak' : 'Yayımlanmış tanım'}
                    </span>
                </>
            }
            search={
                <p className="muted">
                    Kaydedilmiş taslağı inceleyip yayımlayın. Sayfa erişimini Erişim politikaları üzerinden
                    yönetin.
                </p>
            }
        >
            <Head title="Modül tasarımı" />
            {Object.values(errors).map((error, i) => (
                <p className="form-error" role="alert" key={i}>
                    {error}
                </p>
            ))}
            {version.status !== 'draft' && (
                <button
                    type="button"
                    className="button"
                    onClick={() => router.post(forkUrl, { revision: module.revision })}
                >
                    Bu tanımdan yeni taslak aç
                </button>
            )}
            <Editor
                key={`${version.id}-${version.revision}`}
                module={module}
                version={version}
                saveUrl={saveUrl}
                optionLists={optionLists}
                publication={publication}
                publishUrl={publishUrl}
            />
            <section className="editor-panel">
                <h2>Tanım sürümleri</h2>
                <ol className="event-list">
                    {history.map((entry) => (
                        <li key={entry.id}>
                            Sürüm {entry.number} · {entry.status === 'draft' ? 'Taslak' : 'Yayımlanmış tanım'}
                        </li>
                    ))}
                </ol>
            </section>
        </AppShell>
    );
}
