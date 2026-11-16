import { portalFetch } from '../lib/http';
import { useEffect, useRef, useState } from 'react';
import { Head, Link, router } from '@inertiajs/react';
import AppShell from '../components/AppShell';
import ModulePersonPicker, { type ModulePerson } from '../components/ModulePersonPicker';
import useUnsavedChanges from '../lib/useUnsavedChanges';
import {
    mergeRecordValues,
    recordChoices,
    type ConflictChoice,
    type RecordValue as Value,
    type RecordValues,
} from '../lib/recordConflict';
type Field = {
    id: number;
    type: string;
    label: string;
    helper: string;
    required: boolean;
    options: { id: number; label: string; archived: boolean }[];
};
export type ModuleRecordEditorProps = {
    applicationName: string;
    navigation?: { label: string; url: string }[];
    title: string;
    recordMode?: string;
    peopleUrl?: string;
    fields: Field[];
    versionId: number;
    definitionVersion: number;
    record: {
        id: number;
        revision: number;
        values: Record<string, Value>;
        person?: ModulePerson | null;
    } | null;
    baseUrl: string;
    submitUrl: string;
    editUrl?: string | null;
    logoutUrl: string;
};
function initial(field: Field, value: Value | undefined): Value {
    if (field.type === 'multiselect') return Array.isArray(value) ? value : [];
    if (field.type === 'datetime' && typeof value === 'string') return value.replace(' ', 'T');
    if (field.type === 'boolean') return value === true ? '1' : value === false ? '0' : '';
    return value ?? '';
}
export default function ModuleRecordEditor(props: ModuleRecordEditorProps) {
    return (
        <AppShell
            applicationName={props.applicationName}
            navigation={props.navigation}
            pageTitle={props.title}
            toolbar={
                <Link className="button" href={props.baseUrl}>
                    Kayıtlara dön
                </Link>
            }
            search={null}
        >
            <Head title={`${props.title} · ${props.record ? 'Düzenle' : 'Yeni kayıt'}`} />
            <ModuleRecordForm
                key={`${props.record?.id ?? 'new'}-${props.record?.revision ?? 0}`}
                {...props}
            />
        </AppShell>
    );
}

export function ModuleRecordForm({
    fields,
    versionId,
    record,
    baseUrl,
    submitUrl,
    recordMode = 'standalone',
    peopleUrl,
    onSaved,
    onBusy,
    onDirty,
    editUrl,
}: ModuleRecordEditorProps & {
    onSaved?: () => void;
    onBusy?: (busy: boolean) => void;
    onDirty?: (dirty: boolean) => void;
}) {
    const [person, setPerson] = useState<ModulePerson | null>(record?.person ?? null);
    const [values, setValues] = useState<Record<string, Value>>(() =>
        Object.fromEntries(
            fields.map((field) => [field.id, initial(field, record?.values[String(field.id)])]),
        ),
    );
    const [errors, setErrors] = useState<Record<string, string[]>>({});
    const [processing, setProcessing] = useState(false);
    const [conflict, setConflict] = useState(false);
    const [revision, setRevision] = useState(record?.revision);
    const [current, setCurrent] = useState<{ revision: number; values: RecordValues } | null>(null);
    const [choices, setChoices] = useState<Record<string, ConflictChoice>>({});
    const [comparing, setComparing] = useState(false);
    const baseline = useRef(values);
    const baselinePerson = useRef(person?.id);
    const dirty =
        JSON.stringify(values) !== JSON.stringify(baseline.current) || person?.id !== baselinePerson.current;
    const markSaved = useUnsavedChanges(dirty && !onDirty);
    useEffect(() => {
        onDirty?.(dirty);
    }, [dirty, onDirty]);
    const compareRequest = useRef<AbortController | null>(null);
    useEffect(() => () => compareRequest.current?.abort(), []);
    const saving = useRef(false);
    function change(id: number, value: Value) {
        setValues((current) => ({ ...current, [id]: value }));
    }
    async function save() {
        if (saving.current || conflict) return;
        compareRequest.current?.abort();
        saving.current = true;
        setProcessing(true);
        onBusy?.(true);
        setErrors({});
        try {
            const response = await portalFetch(submitUrl, {
                method: record ? 'PUT' : 'POST',
                credentials: 'same-origin',
                headers: { Accept: 'application/json', 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    version_id: versionId,
                    revision,
                    ...(recordMode !== 'standalone' ? { person_id: person?.id ?? null } : {}),
                    values,
                }),
            });
            if (!response.headers.get('content-type')?.includes('application/json'))
                throw new Error('Oturumu kontrol edip yeniden deneyin. Girdileriniz korunuyor.');
            const result = await response.json();
            if (!response.ok) {
                setConflict(Boolean(record) && response.status === 409);
                setErrors(
                    result.errors ?? {
                        operation: [
                            response.status === 419
                                ? 'Oturum doğrulaması yenilenmeli.'
                                : (result.message ?? 'Kayıt kaydedilemedi.'),
                        ],
                    },
                );
                return;
            }
            baseline.current = values;
            baselinePerson.current = person?.id;
            markSaved();
            if (onSaved) onSaved();
            else router.visit(baseUrl);
        } catch (error) {
            setErrors({ operation: [error instanceof Error ? error.message : 'Kayıt kaydedilemedi.'] });
        } finally {
            saving.current = false;
            setProcessing(false);
            onBusy?.(false);
        }
    }
    async function compare() {
        if (!editUrl || comparing) return;
        compareRequest.current?.abort();
        setComparing(true);
        setCurrent(null);
        setChoices({});
        const controller = new AbortController();
        compareRequest.current = controller;
        try {
            const response = await portalFetch(editUrl, {
                credentials: 'same-origin',
                headers: { Accept: 'application/json' },
                cache: 'no-store',
                signal: controller.signal,
            });
            if (!response.ok || !response.headers.get('content-type')?.includes('application/json'))
                throw new Error('Güncel kayıt alınamadı. Yetkinizi ve oturumu kontrol edin.');
            const latest = (await response.json()) as ModuleRecordEditorProps;
            if (!latest.record || latest.record.id !== record?.id || latest.versionId !== versionId)
                throw new Error(
                    'Kayıt veya alan tanımı değişti. Girdileriniz korunuyor; güncel formu ayrı bir sekmede açın.',
                );
            const remote = Object.fromEntries(
                fields.map((field) => [field.id, initial(field, latest.record!.values[String(field.id)])]),
            );
            setCurrent({ revision: latest.record.revision, values: remote });
            setChoices(recordChoices(baseline.current, values, remote));
        } catch (error) {
            if (!controller.signal.aborted)
                setErrors({
                    operation: [error instanceof Error ? error.message : 'Karşılaştırma hazırlanamadı.'],
                });
        } finally {
            if (!controller.signal.aborted) setComparing(false);
        }
    }
    function applyComparison() {
        if (!current) return;
        const merged = mergeRecordValues(values, current.values, choices);
        if (!merged) return;
        baseline.current = current.values;
        setValues(merged);
        setRevision(current.revision);
        setCurrent(null);
        setConflict(false);
        setErrors({});
    }
    function display(field: Field, value: Value) {
        if (field.type === 'boolean') return value === '1' ? 'Evet' : value === '0' ? 'Hayır' : 'Boş';
        if (['select', 'multiselect'].includes(field.type))
            return (
                (Array.isArray(value) ? value : [value])
                    .map(
                        (id) =>
                            field.options.find((option) => String(option.id) === String(id))?.label ??
                            String(id),
                    )
                    .join(', ') || 'Boş'
            );
        return String(value ?? '') || 'Boş';
    }
    return (
        <section className="editor-panel">
            <h2>{record ? 'Kaydı düzenle' : 'Yeni kayıt'}</h2>
            <form
                className="definition-form"
                onSubmit={(event) => {
                    event.preventDefault();
                    void save();
                }}
            >
                <fieldset disabled={processing || conflict} className="module-field">
                    <legend>Kayıt bilgileri</legend>
                    {recordMode !== 'standalone' &&
                        (record ? (
                            <div className="form-field">
                                <strong>Bağlı kişi</strong>
                                <p>
                                    {person?.full_name ?? 'Adı boş'} · {person?.tc_identity ?? 'TC boş'}
                                </p>
                                <span className="muted">Bu kayıt başka kişiye taşınamaz.</span>
                            </div>
                        ) : (
                            peopleUrl && (
                                <ModulePersonPicker url={peopleUrl} person={person} onChange={setPerson} />
                            )
                        ))}
                    <div className="user-fields">
                        {fields.map((field) => {
                            const id = `record-field-${field.id}`,
                                value = values[String(field.id)],
                                messages = errors[`values.${field.id}`];
                            const common = {
                                id,
                                required: field.required,
                                'aria-invalid': !!messages,
                                'aria-describedby': `${id}-description`,
                            };
                            return (
                                <div className="form-field" key={field.id}>
                                    <label htmlFor={id}>
                                        {field.label}
                                        {field.required && ' *'}
                                    </label>
                                    {field.type === 'longtext' ? (
                                        <textarea
                                            {...common}
                                            maxLength={10000}
                                            value={String(value)}
                                            onChange={(event) => change(field.id, event.target.value)}
                                        />
                                    ) : field.type === 'boolean' ? (
                                        <select
                                            {...common}
                                            value={String(value)}
                                            onChange={(event) => change(field.id, event.target.value)}
                                        >
                                            <option value="">Seçin</option>
                                            <option value="1">Evet</option>
                                            <option value="0">Hayır</option>
                                        </select>
                                    ) : field.type === 'select' || field.type === 'multiselect' ? (
                                        <select
                                            {...common}
                                            multiple={field.type === 'multiselect'}
                                            value={
                                                field.type === 'multiselect'
                                                    ? (value as number[]).map(String)
                                                    : String(value)
                                            }
                                            onChange={(event) =>
                                                change(
                                                    field.id,
                                                    field.type === 'multiselect'
                                                        ? Array.from(event.target.selectedOptions, (item) =>
                                                              Number(item.value),
                                                          )
                                                        : event.target.value,
                                                )
                                            }
                                        >
                                            {field.type === 'select' && <option value="">Seçin</option>}
                                            {field.options.map((option) => (
                                                <option
                                                    key={option.id}
                                                    value={option.id}
                                                    disabled={
                                                        option.archived &&
                                                        !(Array.isArray(value)
                                                            ? value.includes(option.id)
                                                            : String(value) === String(option.id))
                                                    }
                                                >
                                                    {option.label}
                                                    {option.archived && ' (arşiv)'}
                                                </option>
                                            ))}
                                        </select>
                                    ) : (
                                        <input
                                            {...common}
                                            type={
                                                field.type === 'date'
                                                    ? 'date'
                                                    : field.type === 'datetime'
                                                      ? 'datetime-local'
                                                      : 'text'
                                            }
                                            step={field.type === 'datetime' ? '1' : undefined}
                                            inputMode={
                                                field.type === 'integer'
                                                    ? 'numeric'
                                                    : field.type === 'decimal'
                                                      ? 'decimal'
                                                      : undefined
                                            }
                                            maxLength={field.type === 'text' ? 255 : undefined}
                                            value={String(value)}
                                            onChange={(event) => change(field.id, event.target.value)}
                                        />
                                    )}
                                    <div id={`${id}-description`}>
                                        {field.helper && <span className="muted">{field.helper}</span>}
                                        {messages?.map((message, i) => (
                                            <p className="form-error" role="alert" key={i}>
                                                {message}
                                            </p>
                                        ))}
                                    </div>
                                </div>
                            );
                        })}
                    </div>
                </fieldset>
                {Object.entries(errors)
                    .filter(([key]) => !fields.some((field) => key === `values.${field.id}`))
                    .map(([key, messages]) => (
                        <p className="form-error" role="alert" key={key}>
                            {messages.join(' ')}
                        </p>
                    ))}
                {conflict && (
                    <section aria-label="Kayıt çakışması">
                        <p className="form-error" role="alert">
                            Kayıt değişti. Girdileriniz korunuyor; eski sürümle kaydetme engellendi.
                        </p>
                        {editUrl && record && (
                            <button
                                type="button"
                                className="button"
                                disabled={comparing}
                                onClick={() => void compare()}
                            >
                                {comparing ? 'Karşılaştırılıyor…' : 'Güncel kayıtla karşılaştır'}
                            </button>
                        )}
                        {current && (
                            <>
                                <p>
                                    Her alan için korunacak değeri seçin. Seçimler formunuza aktarılır;
                                    kaydetmeden önce kontrol edebilirsiniz.
                                </p>
                                <div className="table-scroll">
                                    <table className="portal-table">
                                        <thead>
                                            <tr>
                                                <th>Alan</th>
                                                <th>Benim girdim</th>
                                                <th>Güncel kayıt</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            {fields.map((field) => (
                                                <tr key={field.id}>
                                                    <th scope="row">{field.label}</th>
                                                    {(['mine', 'current'] as const).map((source) => (
                                                        <td key={source}>
                                                            <label>
                                                                <input
                                                                    type="radio"
                                                                    name={`conflict-${field.id}`}
                                                                    aria-label={`${field.label} · ${source === 'mine' ? 'Benim girdim' : 'Güncel kayıt'}`}
                                                                    value={source}
                                                                    checked={
                                                                        choices[String(field.id)] === source
                                                                    }
                                                                    onChange={() =>
                                                                        setChoices((previous) => ({
                                                                            ...previous,
                                                                            [field.id]: source,
                                                                        }))
                                                                    }
                                                                />
                                                                {display(
                                                                    field,
                                                                    (source === 'mine'
                                                                        ? values
                                                                        : current.values)[String(field.id)],
                                                                )}
                                                            </label>
                                                        </td>
                                                    ))}
                                                </tr>
                                            ))}
                                        </tbody>
                                    </table>
                                </div>
                                <button
                                    type="button"
                                    className="button"
                                    disabled={Object.values(choices).some((choice) => !choice)}
                                    onClick={applyComparison}
                                >
                                    Seçimleri forma aktar
                                </button>
                            </>
                        )}
                    </section>
                )}
                <button
                    type="submit"
                    className="button button--primary"
                    disabled={processing || conflict || (recordMode !== 'standalone' && !person)}
                >
                    {processing ? 'Kaydediliyor…' : 'Kaydı kaydet'}
                </button>
            </form>
        </section>
    );
}
