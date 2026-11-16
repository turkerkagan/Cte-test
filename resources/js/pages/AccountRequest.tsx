import { Head, Link, useForm } from '@inertiajs/react';
type Props = {
    kind: string;
    applicationName: string;
    submitUrl: string;
    loginUrl: string;
    departments: { id: number; name: string }[];
    jobTitles: { id: number; name: string }[];
};
export default function AccountRequest(p: Props) {
    const form = useForm({
        username: '',
        full_name: '',
        registry_number: '',
        email: '',
        phone: '',
        message: '',
        department_id: '',
        job_title_id: '',
    });
    const application = p.kind === 'application';
    const title = application ? 'Üyelik başvurusu' : 'Şifre talebi';
    return (
        <main className="public-request">
            <Head title={title} />
            <section className="editor-panel">
                <h1>{title}</h1>
                <p>{p.applicationName} · Talebiniz kurum yöneticisi tarafından incelenir.</p>
                <form
                    className="definition-form"
                    onSubmit={(e) => {
                        e.preventDefault();
                        form.post(p.submitUrl);
                    }}
                >
                    {(
                        [
                            'username',
                            ...(application ? ['full_name', 'registry_number'] : []),
                            'email',
                            'phone',
                        ] as (keyof typeof form.data)[]
                    ).map((key) => (
                        <label className="form-field" key={key}>
                            <span>
                                {
                                    (
                                        {
                                            username: 'Kullanıcı adı / sicil',
                                            full_name: 'Adı Soyadı',
                                            registry_number: 'Sicil numarası',
                                            email: 'E-posta',
                                            phone: 'Telefon',
                                        } as Record<string, string>
                                    )[key]
                                }
                            </span>
                            <input
                                required={['username', 'full_name'].includes(key)}
                                type={key === 'email' ? 'email' : 'text'}
                                maxLength={
                                    key === 'full_name'
                                        ? 150
                                        : key === 'email'
                                          ? 100
                                          : key === 'phone'
                                            ? 30
                                            : 50
                                }
                                value={form.data[key]}
                                onChange={(e) => form.setData(key, e.target.value)}
                            />
                            {form.errors[key] && (
                                <span role="alert" className="form-error">
                                    {form.errors[key]}
                                </span>
                            )}
                        </label>
                    ))}
                    {application &&
                        (['department_id', 'job_title_id'] as const).map((key) => (
                            <label className="form-field" key={key}>
                                <span>{key === 'department_id' ? 'Kurum birimi' : 'Unvan'}</span>
                                <select
                                    required
                                    value={form.data[key]}
                                    onChange={(e) => form.setData(key, e.target.value)}
                                >
                                    <option value="">Seçiniz</option>
                                    {(key === 'department_id' ? p.departments : p.jobTitles).map((item) => (
                                        <option value={item.id} key={item.id}>
                                            {item.name}
                                        </option>
                                    ))}
                                </select>
                                {form.errors[key] && (
                                    <span role="alert" className="form-error">
                                        {form.errors[key]}
                                    </span>
                                )}
                            </label>
                        ))}
                    <label className="form-field">
                        <span>Açıklama</span>
                        <textarea
                            maxLength={1000}
                            rows={4}
                            value={form.data.message}
                            onChange={(e) => form.setData('message', e.target.value)}
                        />
                    </label>
                    <div className="row-actions">
                        <Link href={p.loginUrl} className="button">
                            Girişe dön
                        </Link>
                        <button className="button button--primary" disabled={form.processing}>
                            {form.processing ? 'Gönderiliyor…' : 'Talebi gönder'}
                        </button>
                    </div>
                </form>
            </section>
        </main>
    );
}
