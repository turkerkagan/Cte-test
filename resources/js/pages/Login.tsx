import { portalUrl } from '../lib/portalUrl';
import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { useEffect } from 'react';
import { shouldLoadLoginAnimation } from '../lib/loginAnimation';
export default function Login({
    applicationName,
    submitUrl,
    setupRequired,
    notice,
    guestEnabled,
}: {
    applicationName: string;
    submitUrl: string;
    setupRequired: boolean;
    notice?: string | null;
    guestEnabled?: boolean;
}) {
    const { institutionName = applicationName, appearance = {} } = usePage<{
        institutionName?: string;
        appearance?: {
            login_badge?: string;
            login_title?: string;
            login_accent?: string;
            login_description?: string;
            login_features?: string[];
            footer_left?: string;
            footer_center?: string;
            footer_version?: string;
            footer_contact?: string;
        };
    }>().props;
    const form = useForm({ username: '', password: '' });
    useEffect(() => {
        if (!shouldLoadLoginAnimation()) return;
        let dispose: (() => void) | undefined;
        let mounted = true;
        import('../legacy/login-scene')
            .then((module) => {
                if (mounted) dispose = module.loginScene();
            })
            .catch(() => {});
        return () => {
            mounted = false;
            dispose?.();
        };
    }, []);
    return (
        <div className="legacy-login">
            <Head title={institutionName + ' · Giriş'}>
                <link rel="stylesheet" href={portalUrl('legacy/fontawesome/css/all.min.css')} />
            </Head>
            <div className="auth-wrapper">
                <section className="auth-left">
                    <canvas id="loginThreeCanvas" aria-hidden="true" />
                    <div className="brand-badge">
                        <i className="fas fa-shield-halved" aria-hidden="true" />{' '}
                        {appearance.login_badge ?? 'T.C. Adalet Bakanlığı'}
                    </div>
                    <h1>
                        {appearance.login_title ?? institutionName}
                        {appearance.login_accent && (
                            <>
                                <br />
                                <span>{appearance.login_accent}</span>
                            </>
                        )}
                    </h1>
                    <p className="lead">
                        {appearance.login_description ??
                            'Kurum verilerini tek noktadan güvenli, hızlı ve düzenli biçimde yönetin.'}
                    </p>
                    {appearance.login_features && appearance.login_features.length > 0 && (
                        <ul>
                            {appearance.login_features.map((feature: string, i: number) => (
                                <li key={i}>{feature}</li>
                            ))}
                        </ul>
                    )}
                </section>
                <section className="auth-right">
                    <div className="login-card">
                        <h2>Giriş Yap</h2>
                        <p className="sub">Hesabınıza erişmek için bilgilerinizi girin.</p>
                        {notice && (
                            <p className="notice" role="status">
                                {notice}
                            </p>
                        )}
                        {setupRequired ? (
                            <p role="status">
                                İlk kurulum henüz tamamlanmamış. Kurulum sorumlusuna başvurun.
                            </p>
                        ) : (
                            <form
                                onSubmit={(event) => {
                                    event.preventDefault();
                                    form.post(submitUrl, { onFinish: () => form.reset('password') });
                                }}
                            >
                                <div className="field">
                                    <label htmlFor="login-username">Kullanıcı Adı / Sicil No</label>
                                    <div className="input-shell">
                                        <i className="fas fa-user-shield" aria-hidden="true" />
                                        <input
                                            id="login-username"
                                            name="username"
                                            placeholder="Sicil numaranız veya kullanıcı adınız"
                                            autoComplete="username"
                                            autoFocus
                                            maxLength={100}
                                            required
                                            value={form.data.username}
                                            onChange={(event) => form.setData('username', event.target.value)}
                                            aria-invalid={!!form.errors.username}
                                            aria-describedby={
                                                form.errors.username ? 'username-error' : undefined
                                            }
                                        />
                                    </div>
                                    {form.errors.username && (
                                        <p id="username-error" className="form-error" role="alert">
                                            {form.errors.username}
                                        </p>
                                    )}
                                </div>
                                <div className="field">
                                    <label htmlFor="login-password">Şifre</label>
                                    <div className="input-shell">
                                        <i className="fas fa-lock" aria-hidden="true" />
                                        <input
                                            id="login-password"
                                            name="password"
                                            type="password"
                                            placeholder="••••••••"
                                            autoComplete="current-password"
                                            maxLength={128}
                                            required
                                            value={form.data.password}
                                            onChange={(event) => form.setData('password', event.target.value)}
                                            aria-invalid={!!form.errors.password}
                                        />
                                    </div>
                                    {form.errors.password && (
                                        <p className="form-error" role="alert">
                                            {form.errors.password}
                                        </p>
                                    )}
                                </div>
                                <button className="login-btn" type="submit" disabled={form.processing}>
                                    <span aria-hidden="true">➜</span>
                                    {form.processing ? 'Giriş yapılıyor…' : 'Giriş Yap'}
                                </button>
                            </form>
                        )}
                        {!setupRequired && (
                            <div className="login-request-links">
                                <Link className="login-secondary-action" href={portalUrl('/sifre-talebi')}>
                                    <i className="fas fa-key" aria-hidden="true" />
                                    <span>Şifre talebi</span>
                                </Link>
                                <Link className="login-secondary-action" href={portalUrl('/basvuru')}>
                                    <i className="fas fa-user-plus" aria-hidden="true" />
                                    <span>Üyelik başvurusu</span>
                                </Link>
                            </div>
                        )}
                        {guestEnabled && (
                            <button className="button" onClick={() => router.post(portalUrl('/misafir'))}>
                                Misafir olarak gir
                            </button>
                        )}
                        <div className="login-foot">{applicationName}</div>
                    </div>
                </section>
            </div>
            <footer className="auth-footer">
                <div className="auth-footer-copy">
                    <span>{appearance.footer_left ?? '© 2026 CTE Bilgi İşlem'}</span>
                    <span>·</span>
                    <span>{appearance.footer_center ?? institutionName}</span>
                    {appearance.footer_version && <span>· {appearance.footer_version}</span>}
                    {appearance.footer_contact && (
                        <a href={'mailto:' + appearance.footer_contact}>{appearance.footer_contact}</a>
                    )}
                </div>
            </footer>
        </div>
    );
}
