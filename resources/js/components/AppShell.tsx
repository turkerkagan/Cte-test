import { portalUrl } from '../lib/portalUrl';
import { Head, Link, router, usePage } from '@inertiajs/react';
import Toast, { notify } from './Toast';
import FooterPhoneModal from './FooterPhoneModal';
import { applyFontPreference } from '../lib/appearance';
import { useEffect, useRef, useState, type ReactNode } from 'react';

type NavigationItem = {
    label: string;
    url: string;
    icon?: string;
    target?: string;
    children?: NavigationItem[];
};
type Props = {
    applicationName: string;
    navigation?: NavigationItem[];
    pageTitle?: string;
    home?: boolean;
    children: ReactNode;
    toolbar: ReactNode;
    search: ReactNode;
    listing?: boolean;
    managementHeader?: boolean;
};

function MenuIcon({
    label = '',
    icon = '',
    group = false,
}: {
    label?: string;
    icon?: string;
    group?: boolean;
}) {
    const icons: Record<string, string> = {
        'Kullanıcı İşlemleri': 'user-gear',
        'Raporlar ve Araçlar': 'chart-bar',
        Kısayollar: 'bolt',
        'UYAP Karşılaştırması': 'code-compare',
        'Sistem sağlığı': 'heart-pulse',
        'Ana Sayfa': 'home',
        'Ana sayfa': 'home',
        'Hükümlü İşlemleri': 'users',
        Koğuşlar: 'bed',
        'Çalışma Yeri': 'hard-hat',
        Sayım: 'clipboard-list',
        'Kısmi Arama': 'search',
        Fotoğraf: 'camera',
        'Kimlik Kartı Baskısı': 'id-badge',
        'Kurum Birimleri': 'sitemap',
        'Sorumlu Başmemurluk': 'user-shield',
        'Revir Birimi': 'briefcase-medical',
        'İnfaz Birimi': 'folder',
        İnfaz: 'file-contract',
        İzinler: 'door-open',
        Tebliğler: 'stamp',
        'Bakanlık Sevk': 'truck',
        'Sevk Raporu': 'scale-balanced',
        'İzin Raporu': 'scale-balanced',
        'Ziyaret Birimi': 'handshake',
        'Manevi Rehberlik': 'mosque',
        'Eğitim Servisi': 'graduation-cap',
        Eğitim: 'graduation-cap',
        Kurslar: 'chalkboard-user',
        'Hijyen ve İSG': 'shield-halved',
        'Psiko Sosyal Servisi': 'brain',
        'Mesleki Eğitim Merkezi': 'tools',
        'MESEM İstatistikleri': 'file-lines',
        'Kurum Hakkında': 'building',
        Duyurular: 'bullhorn',
        Nöbetler: 'clock',
        'Müdür Nöbetleri': 'user-tie',
        'Vardiya Nöbetleri': 'users',
        'İnfaz Nöbetleri': 'shield',
        'Kurul ve Komisyonlar': 'gavel',
        'Haftalık Yemek Listesi': 'utensils',
        'Personel Kadrosu': 'sitemap',
        'İstatistik & Analiz': 'chart-bar',
        'Sohbet Odası': 'comments',
        'Telefon Rehberi': 'phone',
        'Yönetim Paneli': 'sliders',
        'Genel Bakış': 'gauge-high',
        'Kullanıcılar ve Erişim': 'users',
        'Başvurular ve Şifre Talepleri': 'user-check',
        'Erişim Politikaları': 'shield-halved',
        Unvanlar: 'id-badge',
        'Blok – Koğuş – Ranza': 'bed',
        'Kurul Başlıkları': 'gavel',
        'Giriş & Footer Metinleri': 'pen-to-square',
        'Sayfa Alanları': 'table-columns',
        'İş Akışı Seçenekleri': 'diagram-project',
        'Durum Seçenekleri': 'tags',
        'Seçenek Listeleri': 'list-check',
        'Yerleşim Ayarları': 'hotel',
        'Sayfa Düzenlemesi': 'palette',
        'Özel Sayfa ve Tablolar': 'table-list',
        'Menü Düzenlemesi': 'bars-staggered',
        'Kullanıcı Girişleri': 'right-to-bracket',
        'İşlem Kayıtları': 'clipboard-check',
        'Veritabanı & Sistem': 'database',
        'Kurtarma & Dosya Yöneticisi': 'screwdriver-wrench',
        'Sistem Sağlığı': 'heart-pulse',
        'Sistem Sağlığı & Veritabanı': 'database',
        Araçlar: 'screwdriver-wrench',
        'Excel Birleştirme': 'file-excel',
        'Özel Rapor': 'file-export',
        'Parolayı Değiştir': 'key',
        'Profil ve Ayarlar': 'user-gear',
        Mesajlar: 'envelope',
        'Kişisel Mesajlar': 'envelope',
    };
    return (
        <span
            className="app-shell__nav-icon"
            style={{ width: '20px', display: 'inline-block', textAlign: 'center', opacity: 0.85 }}
        >
            <i
                aria-hidden="true"
                className={
                    'fas fa-' +
                    (icon ||
                        (icons[label] ??
                            Object.entries(icons).find(
                                ([key]) => key.toLocaleLowerCase('tr') === label.toLocaleLowerCase('tr'),
                            )?.[1] ??
                            (group ? 'folder' : 'file-lines')))
                }
            />
        </span>
    );
}

export default function AppShell({
    applicationName,
    pageTitle,
    home = false,
    navigation = [],
    children,
    toolbar,
    search,
    listing = false,
    managementHeader = true,
}: Props) {
    const [menuOpen, setMenuOpen] = useState(false);
    const sidebar = useRef<HTMLElement>(null);
    const menuButton = useRef<HTMLButtonElement>(null);
    const [phoneModalOpen, setPhoneModalOpen] = useState(false);
    const [theme, setTheme] = useState(() =>
        localStorage.getItem('cte-theme') === 'dark' ? 'dark' : 'light',
    );

    useEffect(() => {
        document.documentElement.dataset.theme = theme;
        localStorage.setItem('cte-theme', theme);
    }, [theme]);

    useEffect(() => {
        applyFontPreference();
    }, []);

    const [indicatorFailures, setIndicatorFailures] = useState(0);
    const {
        account,
        appearance,
        managementNavigation = [],
        institutionName,
        localTest,
        indicators: initialIndicators,
        indicatorUrl,
        logoutUrl: sharedLogoutUrl,
    } = usePage<{
        appearance?: Record<string, string>;
        managementNavigation?: NavigationItem[];
        account?: { name: string; role: string };
        institutionName?: string;
        localTest?: boolean;
        indicators?: { messages: number; announcements: number };
        indicatorUrl?: string;
        logoutUrl?: string;
    }>().props;

    const [indicators, setIndicators] = useState(initialIndicators ?? { messages: 0, announcements: 0 });
    const prevMessagesRef = useRef(initialIndicators?.messages ?? 0);

    useEffect(() => {
        setIndicators(initialIndicators ?? { messages: 0, announcements: 0 });
    }, [initialIndicators?.messages, initialIndicators?.announcements]);

    useEffect(() => {
        if (!indicatorUrl) return;
        const abort = new AbortController();
        const timer = window.setInterval(() => {
            if (document.hidden) return;
            fetch(indicatorUrl, {
                credentials: 'same-origin',
                headers: { Accept: 'application/json' },
                signal: abort.signal,
            })
                .then((r) => {
                    if (!r.ok) throw new Error('indicators');
                    return r.json();
                })
                .then((data) => {
                    if (data) {
                        setIndicatorFailures(0);
                        if (typeof data.messages === 'number' && data.messages > prevMessagesRef.current) {
                            notify(`Yeni bir kişisel mesajınız var! (${data.messages} okunmamış)`);
                        }
                        prevMessagesRef.current = data.messages ?? 0;
                        setIndicators(data);
                    }
                })
                .catch(() => {
                    if (!abort.signal.aborted) setIndicatorFailures((value) => value + 1);
                });
        }, 20000);

        return () => {
            clearInterval(timer);
            abort.abort();
        };
    }, [indicatorUrl]);

    const currentPath = usePage().url.split('?')[0];
    const management = navigation.find((item) => item.url === '#management');
    const managementPage =
        managementNavigation.length > 0 &&
        (currentPath.includes('/yonetim') ||
            Boolean(
                management?.children?.some(
                    (item) =>
                        !item.url.startsWith('#') &&
                        currentPath === new URL(item.url, window.location.origin).pathname,
                ),
            ));
    const [wideScreen, setWideScreen] = useState(() => window.matchMedia('(min-width: 1024px)').matches);
    useEffect(() => {
        const media = window.matchMedia('(min-width: 1024px)');
        const update = () => setWideScreen(media.matches);
        media.addEventListener('change', update);
        return () => media.removeEventListener('change', update);
    }, []);
    const fixedManagement = managementPage && wideScreen;
    const drawerOpen = menuOpen && !fixedManagement;
    const closeAfterNavigation = () => {
        setMenuOpen(false);
    };

    useEffect(() => {
        if (!drawerOpen) return;
        sidebar.current?.querySelector<HTMLButtonElement>('.sidebar-toggle')?.focus();
        const closeOnEscape = (event: KeyboardEvent) => {
            if (event.key === 'Escape') {
                setMenuOpen(false);
                requestAnimationFrame(() => menuButton.current?.focus());
            }
            if (event.key === 'Tab') {
                const controls = Array.from(
                    sidebar.current?.querySelectorAll<HTMLElement>('a[href],button,summary') ?? [],
                ).filter((node) => node.getClientRects().length > 0);
                const first = controls[0],
                    last = controls[controls.length - 1];
                if (event.shiftKey && document.activeElement === first) {
                    event.preventDefault();
                    last?.focus();
                } else if (!event.shiftKey && document.activeElement === last) {
                    event.preventDefault();
                    first?.focus();
                }
            }
        };
        document.addEventListener('keydown', closeOnEscape);
        return () => document.removeEventListener('keydown', closeOnEscape);
    }, [drawerOpen]);

    function isCurrent(item: NavigationItem): boolean {
        return (
            item.children?.some(isCurrent) ??
            (new URL(item.url, window.location.origin).pathname === currentPath && !item.url.startsWith('#'))
        );
    }

    function currentLabel(items: NavigationItem[]): string | undefined {
        for (const item of items) {
            const label = item.children
                ? currentLabel(item.children)
                : isCurrent(item)
                  ? item.label
                  : undefined;
            if (label) return label;
        }
        return undefined;
    }

    function renderItem(item: NavigationItem, depth = 0): ReactNode {
        const label = item.children?.length
            ? (({ 'Raporlar ve Araçlar': 'İstatistik & Analiz' } as Record<string, string>)[item.label] ??
              item.label)
            : item.label;
        if (item.url === '#workflows') return item.children?.map((child) => renderItem(child, depth));
        if (item.url === '#management' && depth === 0) {
            const toolsItem = item.children?.find((child) => child.url === '#cte-tools');
            const mgmtChildren = item.children?.filter((child) => child.url !== '#cte-tools');
            return (
                <div key="mgmt-group" style={{ display: 'contents' }}>
                    <div className="nav-group">
                        <span>Yönetim</span>
                    </div>
                    <details
                        className="nav-tree"
                        key={item.url}
                        open={Boolean(mgmtChildren?.some(isCurrent)) || managementPage}
                    >
                        <summary>
                            <MenuIcon label={item.label} icon={item.icon} group />
                            <span className="nav-label">{label}</span>
                        </summary>
                        <div className="nav-tree-children">
                            {mgmtChildren?.map((child) => renderItem(child, depth + 1))}
                        </div>
                    </details>
                    {toolsItem && renderItem(toolsItem, 0)}
                </div>
            );
        }
        if (item.children?.length) {
            const cls = depth > 0 ? 'nav-tree nav-tree-nested' : 'nav-tree';
            const isKurumBirimleri = depth === 0 && item.label === 'Kurum Birimleri';
            return (
                <details className={cls} key={item.url} open={isCurrent(item) || isKurumBirimleri}>
                    <summary>
                        <MenuIcon label={item.label} icon={item.icon} group />
                        <span className="nav-label">{label}</span>
                    </summary>
                    <div className="nav-tree-children">
                        {item.children.map((child) => renderItem(child, depth + 1))}
                    </div>
                </details>
            );
        }
        const unreadCount = item.url.includes('/mesajlar')
            ? indicators.messages
            : item.url.includes('/duyurular')
              ? indicators.announcements
              : 0;

        return (
            <Link
                key={item.url}
                href={item.url}
                target={item.target}
                rel={item.target === '_blank' ? 'noopener noreferrer' : undefined}
                className={'nav-link' + (isCurrent(item) ? ' active' : '')}
                aria-current={isCurrent(item) ? 'page' : undefined}
                onClick={closeAfterNavigation}
            >
                <MenuIcon label={item.label} icon={item.icon} />
                <span className="nav-label">{item.label}</span>
                {unreadCount > 0 && (
                    <span
                        className="cte-msg-badge"
                        style={{ position: 'relative', top: 'auto', right: 'auto' }}
                    >
                        {unreadCount}
                    </span>
                )}
            </Link>
        );
    }

    const displayNavigation = navigation.flatMap((item) =>
        item.url === '#workflows' ? (item.children ?? []) : [item],
    );
    const activePageTitle =
        currentLabel(managementPage ? managementNavigation : navigation) ?? pageTitle ?? 'CTE Portalı';
    const profileContent = (
        <>
            <span className="user-avatar" aria-hidden="true">
                <i className="fas fa-user" />
            </span>
            <div className="user-copy">
                <strong>{account?.name ?? applicationName}</strong>
                <small>{account?.role ?? 'Kurum Personeli'}</small>
            </div>
        </>
    );

    return (
        <div
            className={`portal-shell${drawerOpen ? ' menu-open' : ''}${home ? ' portal-shell--home' : ''}${home || listing ? ' portal-shell--listing' : ''}${managementPage ? ' portal-shell--admin' : ''}${fixedManagement ? ' portal-shell--management' : ''}`}
        >
            <Toast />
            <Head>
                <link rel="stylesheet" href={portalUrl('legacy/fontawesome/css/all.min.css')} />
            </Head>
            <a className="skip-link" href="#main-content">
                İçeriğe geç
            </a>

            {/* ZERO-REFLOW SABİT DRAWER SİDEBAR */}
            <aside
                ref={sidebar}
                id="portal-menu"
                className="sidebar"
                aria-label="Sol Menü"
                inert={!menuOpen && !fixedManagement}
            >
                <div className="brand">
                    <Link className="brand-copy" href={portalUrl('/')} onClick={closeAfterNavigation}>
                        <strong>
                            {managementPage && <i className="fas fa-shield-halved" aria-hidden="true" />}{' '}
                            {managementPage ? 'CTE Yönetim' : applicationName}
                        </strong>
                        {!managementPage && <span>Cezaevi Yönetim Sistemi{localTest && ' (Test)'}</span>}
                    </Link>
                    <div className="brand-actions">
                        <Link
                            className="sidebar-message-btn"
                            href={portalUrl('/mesajlar')}
                            aria-label="Mesaj kutusu"
                            title="Kişisel Mesajlar"
                            onClick={closeAfterNavigation}
                        >
                            <i className="fas fa-envelope" aria-hidden="true" />
                            {indicators.messages > 0 && (
                                <span className="cte-msg-badge">{indicators.messages}</span>
                            )}
                        </Link>
                        <button
                            className="sidebar-toggle"
                            aria-label="Menüyü daralt"
                            onClick={() => {
                                setMenuOpen(false);
                                menuButton.current?.focus();
                            }}
                        >
                            <i className="fas fa-chevron-left" aria-hidden="true" />
                        </button>
                    </div>
                </div>

                <nav className="nav-list" aria-label="Ana menü">
                    {navigation.length ? (
                        managementPage ? (
                            <>
                                {managementNavigation.map((group) => (
                                    <div key={group.url}>
                                        <div className="nav-group">
                                            <span>{group.label}</span>
                                        </div>
                                        {group.children?.map((item) => renderItem(item))}
                                    </div>
                                ))}
                            </>
                        ) : (
                            displayNavigation.map((item) => renderItem(item, 0))
                        )
                    ) : (
                        <p className="app-shell__nav-empty" style={{ padding: '12px', color: '#b8d0d1' }}>
                            Sayfalara erişmek için giriş yapın.
                        </p>
                    )}
                </nav>

                {/* SOL ALT KULLANICI KARTI (1:1 ESKİ CTE DÜZENİ) */}
                <div className="sidebar-user">
                    {managementPage ? (
                        <Link href={portalUrl('/portal')} className="nav-link" onClick={closeAfterNavigation}>
                            <i className="fas fa-arrow-left" aria-hidden="true" /> Portala Dön
                        </Link>
                    ) : account ? (
                        <Link
                            className="sidebar-user-profile"
                            href={portalUrl('/parola')}
                            title="Profil ve Ayarlar"
                            onClick={closeAfterNavigation}
                        >
                            {profileContent}
                        </Link>
                    ) : (
                        <div className="sidebar-user-profile">{profileContent}</div>
                    )}
                    <div className="sidebar-user-actions">
                        <button
                            className="sidebar-action"
                            type="button"
                            onClick={() => setTheme(theme === 'dark' ? 'light' : 'dark')}
                            aria-label="Koyu/açık tema"
                        >
                            <i className={`fas fa-${theme === 'dark' ? 'sun' : 'moon'}`} aria-hidden="true" />
                            <span>Tema</span>
                        </button>
                        <button
                            className="sidebar-action logout-link"
                            type="button"
                            onClick={() => {
                                if (sharedLogoutUrl) router.post(sharedLogoutUrl);
                            }}
                        >
                            <i className="fas fa-right-from-bracket" aria-hidden="true" />
                            <span>Çıkış</span>
                        </button>
                        {indicatorFailures >= 3 && <p role="status">Bildirimler güncellenemiyor</p>}
                    </div>
                </div>
            </aside>

            {/* ZERO-REFLOW BACKDROP: Menü açıkken tablo üzerine biner */}
            <button
                className="sidebar-backdrop"
                id="cteMenuBackdrop"
                type="button"
                aria-label="Menüyü kapat"
                onClick={() => {
                    setMenuOpen(false);
                    requestAnimationFrame(() => menuButton.current?.focus());
                }}
            />

            {/* SAĞ: İÇERİK VE ORTAK FOOTER */}
            <div className="portal-main" inert={drawerOpen}>
                <main id="main-content" className="content" tabIndex={-1}>
                    {managementPage ? (
                        <>
                            <div className="admin-mobile-bar">
                                <button
                                    ref={menuButton}
                                    className="button"
                                    type="button"
                                    aria-expanded={drawerOpen}
                                    aria-controls="portal-menu"
                                    onClick={() => setMenuOpen(!menuOpen)}
                                >
                                    <i className="fas fa-bars" aria-hidden="true" /> Menü
                                </button>
                                <span>CTE Yönetim</span>
                            </div>
                            {managementHeader && (
                                <header className="admin-page-heading">
                                    <h1>{activePageTitle}</h1>
                                    <div className="admin-page-tools">{toolbar}</div>
                                    <div className="admin-page-search">{search}</div>
                                </header>
                            )}
                        </>
                    ) : (
                        <div className="cte-standard-bars" aria-label="Sayfa ve tablo araçları">
                            <div className="cte-standard-bar cte-standard-primary-bar">
                                <button
                                    ref={menuButton}
                                    className="mobile-menu-toggle"
                                    id="cteModuleMenuToggle"
                                    type="button"
                                    aria-expanded={drawerOpen}
                                    aria-controls="portal-menu"
                                    title="Menüyü Aç/Kapat"
                                    onClick={() => setMenuOpen(!menuOpen)}
                                >
                                    <i className="fas fa-bars" aria-hidden="true" />
                                </button>
                                {toolbar}
                                {!home && !listing && (
                                    <div className="cte-premium-unit-label" style={{ marginLeft: 'auto' }}>
                                        <span>{activePageTitle}</span>
                                    </div>
                                )}
                            </div>
                            {search && (
                                <div className="cte-standard-bar cte-standard-secondary-bar">{search}</div>
                            )}
                        </div>
                    )}

                    <div className="portal-content-stage">{children}</div>
                </main>

                {/* Ortak kurum footer'ı */}
                <footer className="cte-footer">
                    <div className="cte-footer-content">
                        <span>{appearance?.footer_left ?? '© 2026 CTE Bilgi İşlem'}</span>
                        <span className="dot" />
                        <span>{appearance?.footer_center ?? institutionName ?? applicationName}</span>
                        <span className="dot" />
                        <span>{appearance?.footer_version ?? 'v2.6'}</span>
                        {appearance?.footer_contact && (
                            <a href={'mailto:' + appearance.footer_contact}>{appearance.footer_contact}</a>
                        )}
                    </div>
                    {!currentPath.endsWith('/telefon') && (
                        <div className="cte-footer-right">
                            <button
                                type="button"
                                className="cte-footer-phone-btn"
                                title="Telefon Rehberi"
                                onClick={() => setPhoneModalOpen(true)}
                            >
                                <i className="fas fa-phone-alt" aria-hidden="true" /> Telefon Rehberi
                            </button>
                        </div>
                    )}
                </footer>
            </div>

            {/* GLOBAL TELEFON REHBERİ POPUP */}
            <FooterPhoneModal open={phoneModalOpen} onClose={() => setPhoneModalOpen(false)} />
        </div>
    );
}
