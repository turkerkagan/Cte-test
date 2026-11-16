import { formatDateTime } from '../lib/format';
import { Head, Link, useForm } from '@inertiajs/react';
import { useId, useRef, useState } from 'react';
import ConflictReview from '../components/ConflictReview';
import { workspaceRequest } from '../lib/workspace';
import type { RecordValues } from '../lib/recordConflict';
import AppShell from '../components/AppShell';
import PageAccessTable, { type AccessRow } from '../components/PageAccessTable';
import { useAdminMutation, type AdminMutationOptions } from '../lib/adminMutation';

type Option = { id: number; name: string; is_active: boolean };
type User = {
    id: number;
    username: string;
    registry_number: string | null;
    full_name: string | null;
    email: string | null;
    phone: string | null;
    department_id: number | null;
    job_title_id: number | null;
    level: 'system_admin' | 'personnel';
    is_active: boolean;
    revision: number;
    must_change_password: boolean;
    failed_login_attempts: number;
    locked_at: string | null;
    last_login_at: string | null;
};
type Event = { id: number; action: string; created_at: string; actor: string | null };
export type UserEditorProps = {
    applicationName: string;
    navigation?: { label: string; url: string }[];
    notice?: string | null;
    user: User | null;
    departments: Option[];
    jobTitles: Option[];
    events: Event[];
    baseUrl: string;
    submitUrl: string;
    resetUrl: string | null;
    terminateUrl?: string | null;
    deleteUrl?: string | null;
    logoutUrl: string;
    accessRows?: AccessRow[];
    accessUrl?: string;
};
type Props = UserEditorProps;
const eventLabels: Record<string, string> = {
    created: 'Kullanıcı oluşturuldu',
    updated: 'Personel bilgileri güncellendi',
    password_reset: 'Parola sıfırlandı',
    password_changed: 'Parola değiştirildi',
    sessions_terminated: 'Tüm oturumlar sonlandırıldı',
};
const date = formatDateTime;

function PersonnelForm({
    user,
    departments,
    jobTitles,
    submitUrl,
    onSaved,
    onBusy,
}: Pick<Props, 'user' | 'departments' | 'jobTitles' | 'submitUrl'> & AdminMutationOptions) {
    const mutation = useAdminMutation({ onSaved, onBusy });
    const form = useForm({
        username: user?.username ?? '',
        registry_number: user?.registry_number ?? '',
        full_name: user?.full_name ?? '',
        email: user?.email ?? '',
        phone: user?.phone ?? '',
        department_id: String(user?.department_id ?? ''),
        job_title_id: String(user?.job_title_id ?? ''),
        level: user?.level ?? 'personnel',
        is_active: user?.is_active ?? true,
        revision: user?.revision ?? 1,
        password: '',
        password_confirmation: '',
    });
    const project = (data: typeof form.data): RecordValues => {
        const {
            revision: _revision,
            password: _password,
            password_confirmation: _confirmation,
            ...values
        } = data;
        return values;
    };
    const base = useRef(project(form.data));
    const conflict = mutation.conflict || !!form.errors.revision;
    const master = user?.username.toLocaleLowerCase('tr-TR') === 'türker';
    const errors = Object.values(form.errors);
    const fields = [
        ['username', 'Kullanıcı adı', 100],
        ['registry_number', 'Sicil', 50],
        ['full_name', 'Adı Soyadı', 150],
        ['email', 'E-posta', 100],
        ['phone', 'Telefon', 30],
    ] as const;
    return (
        <form
            className="definition-form"
            onSubmit={(event) => {
                event.preventDefault();
                if (conflict) return;
                if (onSaved) {
                    void mutation.submit(submitUrl, form.data, user ? 'PUT' : 'POST');
                    return;
                }
                const options = { onFinish: () => form.reset('password', 'password_confirmation') };
                if (user) form.put(submitUrl, options);
                else form.post(submitUrl, options);
            }}
        >
            {master && (
                <p className="muted">Ana Türker hesabının bilgileri yönetim panelinden değiştirilemez.</p>
            )}
            <fieldset
                disabled={form.processing || mutation.busy || master || conflict}
                className="admin-form-fields"
            >
                <div className="user-fields">
                    {fields.map(([key, label, max]) => (
                        <div className="form-field" key={key}>
                            <label htmlFor={`user-${key}`}>{label}</label>
                            <input
                                autoFocus={key === 'username'}
                                id={`user-${key}`}
                                type={key === 'email' ? 'email' : 'text'}
                                value={form.data[key]}
                                onChange={(event) => form.setData(key, event.target.value)}
                                required={key === 'username' || key === 'full_name'}
                                maxLength={max}
                                autoComplete="off"
                                aria-invalid={!!form.errors[key]}
                            />
                        </div>
                    ))}
                    {(
                        [
                            ['department_id', 'Birim', departments],
                            ['job_title_id', 'Unvan', jobTitles],
                        ] as const
                    ).map(([key, label, options]) => (
                        <div className="form-field" key={key}>
                            <label htmlFor={`user-${key}`}>{label}</label>
                            <select
                                id={`user-${key}`}
                                value={form.data[key]}
                                onChange={(event) => form.setData(key, event.target.value)}
                            >
                                <option value="">Atanmamış</option>
                                {options
                                    .filter((item) => item.is_active || item.id === user?.[key])
                                    .map((item) => (
                                        <option value={item.id} key={item.id}>
                                            {item.name}
                                            {!item.is_active && ' (pasif)'}
                                        </option>
                                    ))}
                            </select>
                        </div>
                    ))}
                    <div className="form-field">
                        <label htmlFor="user-level">Seviye</label>
                        <select
                            id="user-level"
                            value={form.data.level}
                            onChange={(event) => form.setData('level', event.target.value as User['level'])}
                        >
                            <option value="personnel">Kurum Personeli</option>
                            <option value="system_admin">Sistem Yöneticisi</option>
                        </select>
                    </div>
                </div>
                <label className="checkbox-field">
                    <input
                        type="checkbox"
                        checked={form.data.is_active}
                        onChange={(event) => form.setData('is_active', event.target.checked)}
                    />
                    Aktif hesap
                </label>
                {!user && (
                    <>
                        <p className="muted">
                            İlk parola en az 6 karakter olmalı. Kullanıcı ilk girişte parolasını değiştirecek.
                        </p>
                        {(['password', 'password_confirmation'] as const).map((key) => (
                            <div className="form-field" key={key}>
                                <label htmlFor={`user-${key}`}>
                                    {key === 'password' ? 'İlk parola' : 'İlk parola tekrar'}
                                </label>
                                <input
                                    id={`user-${key}`}
                                    type="password"
                                    autoComplete="new-password"
                                    minLength={6}
                                    maxLength={128}
                                    required
                                    value={form.data[key]}
                                    onChange={(event) => form.setData(key, event.target.value)}
                                />
                            </div>
                        ))}
                    </>
                )}
                {errors.length > 0 && (
                    <div className="form-error" role="alert">
                        {errors.map((error, i) => (
                            <p key={i}>{error}</p>
                        ))}
                    </div>
                )}
                {mutation.error && (
                    <p className="form-error" role="alert">
                        {mutation.error}
                    </p>
                )}
                <button className="button button--primary" type="submit" disabled={form.processing}>
                    Personel bilgilerini kaydet
                </button>
                {user && (
                    <p className="muted">Bilgi veya seviye değişikliğinde mevcut oturumlar sonlandırılır.</p>
                )}
            </fieldset>
            {conflict && user && !master && (
                <ConflictReview
                    base={base.current}
                    draft={project(form.data)}
                    labels={{
                        username: 'Kullanıcı adı',
                        registry_number: 'Sicil',
                        full_name: 'Adı Soyadı',
                        email: 'E-posta',
                        phone: 'Telefon',
                        department_id: 'Birim',
                        job_title_id: 'Unvan',
                        level: 'Seviye',
                        is_active: 'Aktif',
                    }}
                    load={async (signal) => {
                        const latest = await workspaceRequest<Props>(submitUrl, undefined, signal);
                        if (!latest.user || latest.user.id !== user.id)
                            throw new Error('Personel bulunamadı.');
                        const current = {
                            ...form.data,
                            ...latest.user,
                            registry_number: latest.user.registry_number ?? '',
                            full_name: latest.user.full_name ?? '',
                            email: latest.user.email ?? '',
                            phone: latest.user.phone ?? '',
                            department_id: String(latest.user.department_id ?? ''),
                            job_title_id: String(latest.user.job_title_id ?? ''),
                        };
                        // Project only editable personnel fields; security metadata and credentials never enter comparison.
                        return {
                            values: Object.fromEntries(
                                Object.keys(base.current).map((key) => [
                                    key,
                                    (current as unknown as RecordValues)[key],
                                ]),
                            ),
                            metadata: latest.user,
                        };
                    }}
                    apply={(merged, current) => {
                        base.current = Object.fromEntries(
                            Object.keys(base.current).map((key) => [
                                key,
                                ['department_id', 'job_title_id'].includes(key)
                                    ? String(current[key as keyof User] ?? '')
                                    : (current[key as keyof User] ?? ''),
                            ]),
                        ) as RecordValues;
                        form.setData(
                            (data) => ({ ...data, ...merged, revision: current.revision }) as typeof data,
                        );
                        form.clearErrors();
                        mutation.clearConflict();
                    }}
                />
            )}
        </form>
    );
}
function Security({
    user,
    resetUrl,
    terminateUrl,
    events,
    onSaved,
    onBusy,
}: { user: User; resetUrl: string; terminateUrl?: string | null; events: Event[] } & AdminMutationOptions) {
    const form = useForm({
        revision: user.revision,
        confirmed: false,
        password: '',
        password_confirmation: '',
    });
    const sessions = useForm({ revision: user.revision, confirmed: false });
    const mutation = useAdminMutation({ onSaved, onBusy });
    const busy = form.processing || sessions.processing || mutation.busy;
    return (
        <section className="editor-panel" aria-labelledby="security-title">
            <h2 id="security-title">Güvenlik ve hareketler</h2>
            <dl className="security-summary">
                <dt>Son giriş</dt>
                <dd>{date(user.last_login_at)}</dd>
                <dt>Zorunlu parola değişimi</dt>
                <dd>{user.must_change_password ? 'Evet' : 'Hayır'}</dd>
                <dt>Hatalı giriş sayısı</dt>
                <dd>{user.failed_login_attempts}</dd>
                <dt>Kilit başlangıcı</dt>
                <dd>{date(user.locked_at)}</dd>
            </dl>
            <form
                className="definition-form"
                onSubmit={(event) => {
                    event.preventDefault();
                    if (onSaved) void mutation.submit(resetUrl, form.data);
                    else form.post(resetUrl);
                }}
            >
                <fieldset disabled={busy} className="admin-form-fields">
                    <h3>Geçici parola</h3>
                    <p>
                        En az 6 karakterlik geçici parola belirleyin. Eski oturumlar kapanır; kullanıcı ilk
                        girişte parolasını değiştirmek zorundadır.
                    </p>
                    <div className="user-fields">
                        {(['password', 'password_confirmation'] as const).map((key) => (
                            <div className="form-field" key={key}>
                                <label htmlFor={`reset-${key}`}>
                                    {key === 'password' ? 'Geçici parola' : 'Geçici parola tekrar'}
                                </label>
                                <input
                                    id={`reset-${key}`}
                                    type="password"
                                    autoComplete="new-password"
                                    minLength={6}
                                    maxLength={128}
                                    required
                                    value={form.data[key]}
                                    onChange={(event) => form.setData(key, event.target.value)}
                                />
                            </div>
                        ))}
                    </div>
                    <label className="checkbox-field">
                        <input
                            type="checkbox"
                            checked={form.data.confirmed}
                            onChange={(event) => form.setData('confirmed', event.target.checked)}
                            required
                        />
                        Bu hesabın parolasını sıfırlamayı onaylıyorum
                    </label>
                    {Object.values(form.errors).map((error, i) => (
                        <p className="form-error" role="alert" key={i}>
                            {error}
                        </p>
                    ))}
                    <button
                        className="button button--danger"
                        type="submit"
                        disabled={
                            form.processing ||
                            !form.data.confirmed ||
                            form.data.password.length < 6 ||
                            form.data.password !== form.data.password_confirmation
                        }
                    >
                        Parolayı yenile
                    </button>
                </fieldset>
            </form>
            {terminateUrl && (
                <form
                    className="definition-form"
                    onSubmit={(event) => {
                        event.preventDefault();
                        if (onSaved) void mutation.submit(terminateUrl, sessions.data);
                        else sessions.post(terminateUrl);
                    }}
                >
                    <fieldset disabled={busy} className="admin-form-fields">
                        <p>
                            Bu hesabın açık oturumları ve hatırla girişleri geçersizleşir. Kullanıcı mevcut
                            parolasıyla tekrar giriş yapabilir.
                        </p>
                        <label className="checkbox-field">
                            <input
                                type="checkbox"
                                checked={sessions.data.confirmed}
                                required
                                onChange={(event) => sessions.setData('confirmed', event.target.checked)}
                            />
                            Tüm oturumları sonlandırmayı onaylıyorum
                        </label>
                        {Object.values(sessions.errors).map((error, i) => (
                            <p className="form-error" role="alert" key={i}>
                                {error}
                            </p>
                        ))}
                        <button
                            type="submit"
                            className="button button--danger"
                            disabled={!sessions.data.confirmed}
                        >
                            Tüm oturumları sonlandır
                        </button>
                    </fieldset>
                </form>
            )}
            {mutation.error && (
                <p className="form-error" role="alert">
                    {mutation.error}
                </p>
            )}
            <h3>Son 20 yönetim ve parola işlemi</h3>
            {events.length ? (
                <ol className="event-list">
                    {events.map((event) => (
                        <li key={event.id}>
                            <strong>{eventLabels[event.action] ?? event.action}</strong>
                            <span>
                                {date(event.created_at)} · {event.actor ?? 'Hesap bilgisi yok'}
                            </span>
                        </li>
                    ))}
                </ol>
            ) : (
                <p className="muted">Henüz hareket kaydı yok.</p>
            )}
        </section>
    );
}
function DeleteUser({ user, url, onBusy, onSaved }: { user: User; url: string } & AdminMutationOptions) {
    const mutation = useAdminMutation({ onSaved, onBusy });
    const form = useForm({ revision: user.revision, confirmed: false, confirmation: '' });
    return (
        <form
            className="editor-panel definition-form"
            onSubmit={(e) => {
                e.preventDefault();
                if (onSaved) void mutation.submit(url, form.data, 'DELETE');
                else form.delete(url, { onStart: () => onBusy?.(true), onFinish: () => onBusy?.(false) });
            }}
        >
            <h3>Kullanıcıyı kalıcı sil</h3>
            <p>
                Hesap, kişisel erişimleri ve özel mesajları silinir. Kurum kayıtları korunur. KULLANICIYI SİL
                yazın.
            </p>
            <input
                aria-label="Kullanıcı silme onayı"
                required
                value={form.data.confirmation}
                onChange={(e) => form.setData('confirmation', e.target.value)}
            />
            <label>
                <input
                    type="checkbox"
                    required
                    checked={form.data.confirmed}
                    onChange={(e) => form.setData('confirmed', e.target.checked)}
                />
                Kalıcı silmeyi onaylıyorum
            </label>
            {Object.values(form.errors).map((e) => (
                <p role="alert" key={e}>
                    {e}
                </p>
            ))}
            {mutation.error && <p role="alert">{mutation.error}</p>}
            <button
                className="button button--danger"
                disabled={
                    form.processing ||
                    mutation.busy ||
                    form.data.confirmation !== 'KULLANICIYI SİL' ||
                    !form.data.confirmed
                }
            >
                Kullanıcıyı sil
            </button>
        </form>
    );
}
export function UserEditorContent({
    user,
    departments,
    jobTitles,
    events,
    submitUrl,
    resetUrl,
    terminateUrl,
    deleteUrl,
    accessRows = [],
    accessUrl,
    onSaved,
    onBusy,
}: Props & AdminMutationOptions) {
    const [tab, setTab] = useState(0);
    const [busy, setBusy] = useState(false);
    const prefix = useId();
    const activity = (value: boolean) => {
        setBusy(value);
        onBusy?.(value);
    };
    const tabs = ['Personel ve atama', 'Sayfa erişimleri', 'Güvenlik ve hareketler'];
    return (
        <div className="admin-user-editor">
            {user && (
                <div
                    role="tablist"
                    aria-label="Kullanıcı özellikleri"
                    className="admin-tabs"
                    onKeyDown={(event) => {
                        if (!['ArrowLeft', 'ArrowRight', 'Home', 'End'].includes(event.key) || busy) return;
                        event.preventDefault();
                        const next =
                            event.key === 'Home'
                                ? 0
                                : event.key === 'End'
                                  ? 2
                                  : (tab + (event.key === 'ArrowRight' ? 1 : 2)) % 3;
                        setTab(next);
                        (event.currentTarget.querySelectorAll('button')[next] as HTMLButtonElement)?.focus();
                    }}
                >
                    {tabs.map((label, index) => (
                        <button
                            type="button"
                            key={label}
                            role="tab"
                            id={`${prefix}-tab-${index}`}
                            aria-controls={`${prefix}-panel-${index}`}
                            aria-selected={tab === index}
                            tabIndex={tab === index ? 0 : -1}
                            disabled={busy}
                            onClick={() => setTab(index)}
                        >
                            {label}
                        </button>
                    ))}
                </div>
            )}
            <fieldset className="admin-form-fields" disabled={busy}>
                <section
                    role={user ? 'tabpanel' : undefined}
                    id={`${prefix}-panel-0`}
                    aria-labelledby={user ? `${prefix}-tab-0` : undefined}
                    hidden={tab !== 0}
                    className="editor-panel"
                >
                    <h2>Personel ve atama</h2>
                    <PersonnelForm
                        key={`${user?.id ?? 'new'}-${user?.revision ?? 0}`}
                        user={user}
                        departments={departments}
                        jobTitles={jobTitles}
                        submitUrl={submitUrl}
                        onSaved={onSaved}
                        onBusy={activity}
                    />
                </section>
                {user && (
                    <section
                        role="tabpanel"
                        id={`${prefix}-panel-1`}
                        aria-labelledby={`${prefix}-tab-1`}
                        hidden={tab !== 1}
                        className="editor-panel"
                    >
                        <h2>Sayfa erişimleri</h2>
                        <div className="access-summary" aria-label="Erişim özeti">
                            <span>
                                {accessRows.filter((row) => row.is_active && row.effective.giris).length}{' '}
                                erişilebilir
                            </span>
                            <span>
                                {
                                    accessRows.filter(
                                        (row) => row.is_active && row.effective.giris && !row.effective.yazma,
                                    ).length
                                }{' '}
                                yalnız okuma
                            </span>
                            <span>
                                {accessRows.filter((row) => row.is_active && row.effective.yazma).length}{' '}
                                okuma ve yazma
                            </span>
                            <span>
                                {accessRows.filter((row) => row.is_active && !row.effective.giris).length}{' '}
                                kapalı
                            </span>
                        </div>
                        <p className="muted">
                            Sayfa erişimleri kaynakların birleşimidir. Özel not ve risk bilgilerinde kişisel
                            seçim birim ve unvan izninin önüne geçer; kişisel tanımı kaldırmak devralmaya
                            döner.
                        </p>
                        {accessUrl && (
                            <PageAccessTable
                                rows={accessRows}
                                batchUrl={accessUrl}
                                personal
                                onSaved={onSaved}
                                onBusy={activity}
                            />
                        )}
                    </section>
                )}
                {user && resetUrl && (
                    <div
                        role="tabpanel"
                        id={`${prefix}-panel-2`}
                        aria-labelledby={`${prefix}-tab-2`}
                        hidden={tab !== 2}
                    >
                        {deleteUrl && (
                            <DeleteUser user={user} url={deleteUrl} onBusy={activity} onSaved={onSaved} />
                        )}
                        <Security
                            key={`security-${user.id}-${user.revision}`}
                            user={user}
                            resetUrl={resetUrl}
                            terminateUrl={terminateUrl}
                            events={events}
                            onSaved={onSaved}
                            onBusy={activity}
                        />
                    </div>
                )}
            </fieldset>
        </div>
    );
}
export default function UserEditor(props: Props) {
    const { applicationName, navigation, notice, user, baseUrl } = props;
    return (
        <AppShell
            applicationName={applicationName}
            navigation={navigation}
            pageTitle={user ? 'Kullanıcı özellikleri' : 'Yeni kullanıcı'}
            toolbar={
                <Link className="button" href={baseUrl}>
                    Kullanıcılara dön
                </Link>
            }
            search={
                <p className="muted">
                    {user?.full_name ?? 'Yeni personel hesabı'}
                    {user && ` · ${user.username}`}
                </p>
            }
        >
            <Head title={user ? 'Kullanıcı özellikleri' : 'Yeni kullanıcı'} />
            {notice && (
                <p className="notice" role="status">
                    {notice}
                </p>
            )}
            <UserEditorContent {...props} />
        </AppShell>
    );
}
