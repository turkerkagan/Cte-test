import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import FooterPhoneModal from '../components/FooterPhoneModal';
import { applyFontPreference, fontPreset } from '../lib/appearance';

type Profile = { username: string; full_name: string | null; email: string | null; revision: number };
type Props = {
    submitUrl: string;
    logoutUrl: string;
    homeUrl: string;
    messagesUrl: string;
    profileUrl: string;
    applicationName: string;
    mustChangePassword: boolean;
    profile: Profile;
};
export default function Password(p: Props) {
    const { account, notice, institutionName } = usePage<{
        account?: { name: string; role: string };
        notice?: string;
        institutionName?: string;
    }>().props;
    const password = useForm({ current_password: '', password: '', password_confirmation: '' });
    const profile = useForm({
        full_name: p.profile.full_name ?? '',
        email: p.profile.email ?? '',
        revision: p.profile.revision,
    });
    const [phoneOpen, setPhoneOpen] = useState(false);
    const [theme, setTheme] = useState(() =>
        localStorage.getItem('cte-theme') === 'dark' ? 'dark' : 'light',
    );
    const [font, setFont] = useState(() => fontPreset());
    const [custom, setCustom] = useState(() => localStorage.getItem('cte-font-px') ?? '16');
    const [customActive, setCustomActive] = useState(() => localStorage.getItem('cte-font-px') !== null);
    useEffect(() => {
        document.documentElement.dataset.theme = theme;
        localStorage.setItem('cte-theme', theme);
    }, [theme]);
    useEffect(() => applyFontPreference(), []);
    function preset(size: string, name: string) {
        setFont(name as ReturnType<typeof fontPreset>);
        setCustomActive(false);
        localStorage.removeItem('cte-font-px');
        localStorage.setItem('cte-table-font-size', size);
        localStorage.setItem('cte-font-size', name);
        applyFontPreference();
    }
    function customFont(value: string) {
        setCustom(value);
        const size = Number(value);
        if (!Number.isInteger(size) || size < 8 || size > 48) return;
        setCustomActive(true);
        localStorage.setItem('cte-font-px', String(size));
        applyFontPreference();
    }
    const errors = Object.values(profile.errors);
    return (
        <main className="profile-page">
            <Head title="Profil ve Ayarlar" />
            <header className="profile-topbar">
                <strong>
                    <i className="fas fa-user-circle" aria-hidden="true" /> Profil ve Ayarlar
                </strong>
                <Link href={p.homeUrl}>← Ana Sayfaya Dön</Link>
            </header>
            <div className="cte-profile-page">
                <section className="profile-card">
                    <header className="profile-person">
                        <div className="profile-avatar">
                            {(account?.name || p.profile.username).slice(0, 1).toLocaleUpperCase('tr')}
                        </div>
                        <div>
                            <h1>{account?.name || p.profile.username}</h1>
                            <p>
                                {account?.role} · {p.profile.username}
                            </p>
                        </div>
                    </header>
                    <div className="row-actions">
                        <Link className="button" href={p.messagesUrl}>
                            <i className="fas fa-envelope" aria-hidden="true" /> Mesaj Kutusu
                        </Link>
                        <button type="button" className="button" onClick={() => router.post(p.logoutUrl)}>
                            Çıkış Yap
                        </button>
                    </div>
                    {notice && (
                        <p role="status" className="profile-notice">
                            {notice}
                        </p>
                    )}
                    {p.mustChangePassword && (
                        <p role="status" className="profile-notice">
                            Devam etmek için parolanızı güncelleyin. Kişisel bilgilerinizi ardından
                            düzenleyebilirsiniz.
                        </p>
                    )}
                    <h2>Görünüm ve Arayüz Tercihleri</h2>
                    <div className="profile-preferences">
                        <section>
                            <h3>Yazı Boyutu (Punto)</h3>
                            <div className="profile-button-row">
                                {[
                                    ['11px', 'compact', 'Kompakt'],
                                    ['12px', 'normal', 'Standart'],
                                    ['13px', 'large', 'Geniş'],
                                ].map(([size, name, label]) => (
                                    <button
                                        key={size}
                                        type="button"
                                        className={`button${!customActive && font === name ? ' button--primary' : ''}`}
                                        aria-pressed={!customActive && font === name}
                                        onClick={() => preset(size, name)}
                                    >
                                        {label}
                                    </button>
                                ))}
                            </div>
                            <p>
                                Hazır boyutlardan birini seçin veya aşağıya istediğiniz piksel değerini girin.
                            </p>
                            <label>
                                Özel yazı boyutu (8–48 px)
                                <input
                                    type="number"
                                    aria-label="Özel yazı boyutu"
                                    min={8}
                                    max={48}
                                    step={1}
                                    value={custom}
                                    onChange={(e) => customFont(e.target.value)}
                                />
                            </label>
                        </section>
                        <section>
                            <h3>Arayüz Teması</h3>
                            <div className="profile-button-row">
                                {[
                                    ['light', 'Gündüz Modu'],
                                    ['dark', 'Gece Modu'],
                                ].map(([key, label]) => (
                                    <button
                                        key={key}
                                        type="button"
                                        className={`button${theme === key ? ' button--primary' : ''}`}
                                        aria-pressed={theme === key}
                                        onClick={() => setTheme(key)}
                                    >
                                        {label}
                                    </button>
                                ))}
                            </div>
                            <p>Tüm sayfalar ve tablolar için koyu/açık kontrastı ayarlar.</p>
                        </section>
                    </div>
                    <h2>Kişisel Bilgiler</h2>
                    <form
                        onSubmit={(e) => {
                            e.preventDefault();
                            profile.post(p.profileUrl, {
                                preserveScroll: true,
                                onSuccess: (page) => {
                                    const fresh = page.props.profile as Profile;
                                    profile.setData({
                                        full_name: fresh.full_name ?? '',
                                        email: fresh.email ?? '',
                                        revision: fresh.revision,
                                    });
                                },
                            });
                        }}
                    >
                        <label>
                            Kullanıcı Adı
                            <input readOnly value={p.profile.username} />
                        </label>
                        <p className="profile-hint">
                            Kullanıcı adı değiştirilemez. Değişiklik için yöneticinize başvurun.
                        </p>
                        <fieldset
                            className="profile-person-fields"
                            disabled={profile.processing || p.mustChangePassword}
                        >
                            <label>
                                Ad Soyad
                                <input
                                    required
                                    maxLength={150}
                                    placeholder="Adınızı ve soyadınızı girin"
                                    value={profile.data.full_name}
                                    onChange={(e) => profile.setData('full_name', e.target.value)}
                                    aria-invalid={!!profile.errors.full_name}
                                />
                            </label>
                            <label>
                                E-posta
                                <input
                                    type="email"
                                    maxLength={100}
                                    placeholder="ornek@cte.gov.tr"
                                    value={profile.data.email}
                                    onChange={(e) => profile.setData('email', e.target.value)}
                                    aria-invalid={!!profile.errors.email}
                                />
                            </label>
                        </fieldset>
                        {errors.length > 0 && (
                            <p className="form-error" role="alert">
                                {errors.join(' ')}
                            </p>
                        )}
                        <button
                            className="button button--primary"
                            disabled={profile.processing || p.mustChangePassword}
                        >
                            {profile.processing ? 'Kaydediliyor…' : 'Bilgileri Kaydet'}
                        </button>
                    </form>
                    <h2>Şifre Değiştir</h2>
                    <p className="profile-hint">Yeni parolanız en az 6 karakterden oluşmalıdır.</p>
                    <form
                        className="profile-password-form"
                        onSubmit={(e) => {
                            e.preventDefault();
                            password.post(p.submitUrl, { onFinish: () => password.reset() });
                        }}
                    >
                        {(
                            [
                                ['current_password', 'Mevcut Şifre', 'current-password', 1],
                                ['password', 'Yeni Şifre', 'new-password', 6],
                                ['password_confirmation', 'Yeni Şifre (Tekrar)', 'new-password', 6],
                            ] as const
                        ).map(([key, label, autocomplete, min]) => (
                            <label key={key}>
                                {label}
                                <input
                                    type="password"
                                    autoComplete={autocomplete}
                                    required
                                    minLength={min}
                                    maxLength={128}
                                    value={password.data[key]}
                                    onChange={(e) => password.setData(key, e.target.value)}
                                    aria-invalid={!!password.errors[key]}
                                />
                                {password.errors[key] && (
                                    <span className="form-error" role="alert">
                                        {password.errors[key]}
                                    </span>
                                )}
                            </label>
                        ))}
                        <button className="button button--primary" disabled={password.processing}>
                            {password.processing ? 'Güncelleniyor…' : 'Parolayı Güncelle'}
                        </button>
                    </form>
                </section>
            </div>
            <footer className="profile-footer">
                <span>© 2026 CTE Bilgi İşlem · {institutionName ?? p.applicationName} · v2.6</span>
                <button type="button" className="button" onClick={() => setPhoneOpen(true)}>
                    Telefon Rehberi
                </button>
            </footer>
            <FooterPhoneModal open={phoneOpen} onClose={() => setPhoneOpen(false)} />
        </main>
    );
}
