import { portalFetch } from '../lib/http';
import { useState } from 'react';
import { Head, Link, router } from '@inertiajs/react';
import PersonNotes from '../components/PersonNotes';
import AppShell from '../components/AppShell';
type Fields = {
    tc_identity: string | null;
    full_name: string | null;
    admitted_on: string | null;
    registered_on: string | null;
};
type Props = {
    applicationName: string;
    navigation?: { label: string; url: string }[];
    record: (Fields & { id: number; revision: number }) | null;
    defaultRegisteredOn: string | null;
    baseUrl: string;
    submitUrl: string;
    logoutUrl: string;
};
export default function PersonEditor({
    applicationName,
    navigation,
    record,
    defaultRegisteredOn,
    baseUrl,
    submitUrl,
}: Props) {
    const title = record ? 'Kişi kaydını düzenle' : 'Yeni kişi kaydı';
    const [values, setValues] = useState({
        tc_identity: record?.tc_identity ?? '',
        full_name: record?.full_name ?? '',
        admitted_on: record?.admitted_on ?? '',
        registered_on: record ? (record.registered_on ?? '') : (defaultRegisteredOn ?? ''),
    });
    const [errors, setErrors] = useState<Record<string, string[]>>({});
    const [processing, setProcessing] = useState(false);
    const [conflict, setConflict] = useState(false);
    async function save() {
        setProcessing(true);
        setErrors({});
        try {
            const response = await portalFetch(submitUrl, {
                method: record ? 'PUT' : 'POST',
                credentials: 'same-origin',
                headers: { Accept: 'application/json', 'Content-Type': 'application/json' },
                body: JSON.stringify({ ...values, ...(record ? { revision: record.revision } : {}) }),
            });
            if (!response.headers.get('content-type')?.includes('application/json'))
                throw new Error('Oturumu kontrol edip yeniden deneyin. Girdileriniz korunuyor.');
            const result = await response.json();
            if (!response.ok) {
                setConflict(response.status === 409);
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
            router.visit(record ? baseUrl : baseUrl + '?scope=all');
        } catch (error) {
            setErrors({ operation: [error instanceof Error ? error.message : 'Kayıt kaydedilemedi.'] });
        } finally {
            setProcessing(false);
        }
    }
    return (
        <AppShell
            applicationName={applicationName}
            navigation={navigation}
            pageTitle={title}
            toolbar={
                <Link className="button" href={baseUrl}>
                    Kayıtlara dön
                </Link>
            }
            search={
                <p className="muted">
                    Adı soyadı zorunludur. TC Kimlik ve tarihler boş bırakılabilir. Yeni kayıt durum atanana
                    kadar tüm kayıtlar kapsamında görünür.
                </p>
            }
        >
            <Head title={title} />
            <section className="editor-panel">
                <h2>{title}</h2>
                <form
                    className="definition-form"
                    onSubmit={(event) => {
                        event.preventDefault();
                        void save();
                    }}
                >
                    <fieldset className="module-field" disabled={processing || conflict}>
                        <legend>Kimlik ve tarih bilgileri</legend>
                        <div className="user-fields">
                            {(
                                [
                                    { key: 'tc_identity', label: 'TC Kimlik', type: 'text', maxLength: 11 },
                                    { key: 'full_name', label: 'Adı Soyadı', type: 'text', maxLength: 255 },
                                    { key: 'admitted_on', label: 'Kuruma Geliş Tarihi', type: 'date' },
                                    { key: 'registered_on', label: 'Kayıt Tarihi', type: 'date' },
                                ] as const
                            ).map((field) => (
                                <div className="form-field" key={field.key}>
                                    <label htmlFor={field.key}>
                                        {field.label}
                                        {field.key === 'full_name' && ' *'}
                                    </label>
                                    <input
                                        id={field.key}
                                        type={field.type}
                                        required={field.key === 'full_name'}
                                        maxLength={'maxLength' in field ? field.maxLength : undefined}
                                        inputMode={field.key === 'tc_identity' ? 'numeric' : undefined}
                                        pattern={field.key === 'tc_identity' ? '[0-9]{11}' : undefined}
                                        min={field.type === 'date' ? '1000-01-01' : undefined}
                                        max={field.type === 'date' ? '9999-12-31' : undefined}
                                        value={values[field.key]}
                                        onChange={(event) =>
                                            setValues((current) => ({
                                                ...current,
                                                [field.key]: event.target.value,
                                            }))
                                        }
                                        aria-invalid={!!errors[field.key]}
                                        aria-describedby={field.key + '-errors'}
                                    />
                                    <div id={field.key + '-errors'}>
                                        {errors[field.key]?.map((message, i) => (
                                            <p className="form-error" role="alert" key={i}>
                                                {message}
                                            </p>
                                        ))}
                                    </div>
                                </div>
                            ))}
                        </div>
                    </fieldset>
                    {Object.entries(errors)
                        .filter(([key]) => !(key in values))
                        .map(([key, messages]) => (
                            <p className="form-error" role="alert" key={key}>
                                {messages.join(' ')}
                            </p>
                        ))}
                    {conflict && (
                        <p className="form-error" role="alert">
                            Girdileriniz korunuyor. Güncel kaydı başka bir sekmede açıp karşılaştırın; eski
                            sürümle kaydetme engellendi.
                        </p>
                    )}
                    <button
                        type="submit"
                        className="button button--primary"
                        disabled={processing || conflict}
                    >
                        {processing ? 'Kaydediliyor…' : 'Kaydı kaydet'}
                    </button>
                </form>
            </section>
            {record && <PersonNotes person={record.id} />}
        </AppShell>
    );
}
