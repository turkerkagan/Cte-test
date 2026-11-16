import { formatDateTime } from '../lib/format';
import { useForm } from '@inertiajs/react';
import { useState } from 'react';
import { type AccessRow, type Targets, permissionLabel } from './PageAccessTable';
import { searchName } from '../lib/table';
import PortalDialog from './PortalDialog';
import RecordHoverCard from './RecordHoverCard';

const targetLabels: Record<string, string> = {
    herkes: 'Herkes',
    misafir: 'Misafir',
    birim: 'Birim',
    unvan: 'Unvan',
    kisi: 'Kişi',
};
type Rule = AccessRow['rules'][number];
export default function PageRuleEditor({
    page,
    targets,
    batchUrl,
    onEditPage,
    onEditFields,
}: {
    page: AccessRow;
    targets: Targets;
    batchUrl: string;
    onEditPage?: () => void;
    onEditFields?: () => void;
}) {
    const form = useForm({
        pages: [{ id: page.id, revision: page.revision }],
        target_type: 'birim' as keyof Targets | 'herkes' | 'misafir',
        target_id: '',
        operation: 'grant',
        permission: 'giris',
        reason: '',
        confirmed: false,
    });
    const remove = useForm({
        pages: [{ id: page.id, revision: page.revision }],
        target_type: '',
        target_id: 0,
        operation: 'remove',
        permission: null,
        reason: '',
        confirmed: false,
    });
    const edit = useForm({
        pages: [{ id: page.id, revision: page.revision }],
        target_type: '',
        target_id: 0,
        operation: 'grant',
        permission: 'giris',
        reason: '',
        confirmed: false,
    });
    const [query, setQuery] = useState('');
    const [removal, setRemoval] = useState<Rule | null>(null);
    const [inherited, setInherited] = useState(false);
    const busy = form.processing || remove.processing || edit.processing;
    const available = ['herkes', 'misafir'].includes(form.data.target_type)
        ? []
        : targets[form.data.target_type as keyof Targets];
    const options = available.filter(
        (item) => String(item.id) === form.data.target_id || searchName(item.name, query),
    );
    const writeRules = page.rules.filter(
        (rule) => rule.permission === 'yazma' && rule.target_type !== 'herkes',
    );
    const readRules = page.rules.filter(
        (rule) =>
            ['giris', 'yok'].includes(rule.permission) ||
            (rule.permission === 'yazma' && rule.target_type !== 'herkes'),
    );
    const sensitive = page.code.startsWith('hassas_');
    function openRule(rule: Rule, readOnly = false) {
        setInherited(readOnly);
        setRemoval(rule);
        const pages = [{ id: page.id, revision: page.revision }];
        edit.setData({
            pages,
            target_type: rule.target_type,
            target_id: rule.target_id,
            operation: 'grant',
            permission: rule.permission,
            reason: rule.reason ?? '',
            confirmed: false,
        });
        remove.setData((data) => ({
            ...data,
            pages,
            target_type: rule.target_type,
            target_id: rule.target_id,
            confirmed: false,
            reason: '',
        }));
        edit.clearErrors();
        remove.clearErrors();
    }
    function rulesTable(rules: Rule[], reading: boolean) {
        return (
            <div className="policy-rule-scroll">
                <table className="portal-table policy-rule-table">
                    <thead>
                        <tr>
                            <th>Kime</th>
                            <th>Tür</th>
                            <th>Gerekçe / yetki kaynağı</th>
                            <th aria-label="İşlem" />
                        </tr>
                    </thead>
                    <tbody>
                        {reading && page.code === 'hukumlu_kayit' && (
                            <tr className="policy-inherited-row">
                                <td>Giriş yapmış tüm aktif personel</td>
                                <td>
                                    <span className="policy-badge">Sabit okuma</span>
                                </td>
                                <td>Ana Sayfa her zaman okunabilir; yazma ayrıca tanımlanır.</td>
                                <td>
                                    <i className="fas fa-lock" aria-label="Sabit izin" />
                                </td>
                            </tr>
                        )}
                        {rules.map((rule) => {
                            const readOnly = reading && rule.permission === 'yazma';
                            const open = () => openRule(rule, readOnly);
                            return (
                                <tr
                                    key={`${rule.target_type}-${rule.target_id}`}
                                    tabIndex={busy ? undefined : 0}
                                    className="data-table__record-row"
                                    aria-label={`${rule.name ?? rule.target_type} yetki tanımını aç`}
                                    aria-disabled={busy || undefined}
                                    onClick={() => {
                                        if (!busy) open();
                                    }}
                                    onKeyDown={(event) => {
                                        if (
                                            !busy &&
                                            event.target === event.currentTarget &&
                                            ['Enter', ' '].includes(event.key)
                                        ) {
                                            event.preventDefault();
                                            open();
                                        }
                                    }}
                                >
                                    <td>
                                        {rule.target_type === 'kisi' ? (
                                            <RecordHoverCard
                                                name={rule.name ?? 'Silinmiş hedef'}
                                                onActivate={open}
                                                disabled={busy}
                                            >
                                                <div>
                                                    <strong>{rule.name ?? 'Silinmiş hedef'}</strong>
                                                    <p>{permissionLabel(rule.permission)}</p>
                                                    {rule.reason && <p>{rule.reason}</p>}
                                                </div>
                                            </RecordHoverCard>
                                        ) : (
                                            (rule.name ?? 'Silinmiş hedef')
                                        )}
                                    </td>
                                    <td>
                                        <span
                                            className={`policy-badge${readOnly ? ' policy-badge--inherited' : ''}`}
                                        >
                                            {readOnly
                                                ? 'Yazma yetkisiyle dahil'
                                                : (targetLabels[rule.target_type] ?? rule.target_type)}
                                        </span>
                                    </td>
                                    <td>
                                        {rule.reason ||
                                            (readOnly
                                                ? 'Yazma izninden geliyor · Yazma bölümünden yönetilir'
                                                : permissionLabel(rule.permission))}
                                    </td>
                                    <td>
                                        {readOnly ? (
                                            <i
                                                className="fas fa-lock"
                                                aria-label="Yazma yetkisinden gelen okuma"
                                            />
                                        ) : (
                                            <button
                                                type="button"
                                                className="button policy-edit-rule"
                                                disabled={busy}
                                                aria-label={`${rule.name ?? rule.target_type} yetkisini düzenle`}
                                                onClick={(event) => {
                                                    event.stopPropagation();
                                                    open();
                                                }}
                                            >
                                                <i className="fas fa-pen-to-square" aria-hidden="true" />
                                            </button>
                                        )}
                                    </td>
                                </tr>
                            );
                        })}
                        {!rules.length && !(reading && page.code === 'hukumlu_kayit') && (
                            <tr>
                                <td colSpan={4} className="policy-empty">
                                    Henüz yetki tanımlanmadı.
                                </td>
                            </tr>
                        )}
                    </tbody>
                </table>
            </div>
        );
    }
    return (
        <section className="policy-page-detail" aria-label={`${page.name} erişimleri`}>
            <details className="policy-help">
                <summary>
                    <i className="fas fa-circle-question" aria-hidden="true" /> Bu ekran ne işe yarar? Erişim
                    nasıl hesaplanır?
                </summary>
                <div>
                    <p>
                        Bir sayfanın kimin açabileceğini ve kimin düzenleyebileceğini birim, unvan, kişi ve
                        herkes hedefleriyle belirleyin.
                    </p>
                    <p>
                        <strong>Giriş:</strong> Sayfayı açıp okuyabilir. <strong>Yazma:</strong> Veri
                        düzenleyebilir ve otomatik olarak giriş izni kazanır.
                    </p>
                    <ul>
                        <li>
                            Kurallar VEYA mantığıyla birleşir. Bir kuralın kaldırılması diğer kaynaklardan
                            gelen izni kaldırmaz.
                        </li>
                        <li>
                            Herkese yazma izni verilemez. Ana Sayfa aktif hesaplar için her zaman okunabilir.
                        </li>
                        <li>
                            Birim veya unvan değişikliğinde güncel atamalar uygulanır. Pasif hesaplar giriş
                            yapamaz.
                        </li>
                        <li>
                            Sistem yöneticisi her zaman tam yetkilidir. Yetkiler kaydetme sırasında sunucuda
                            da denetlenir.
                        </li>
                    </ul>
                </div>
            </details>
            <div className="policy-rules-card">
                <header className="policy-detail-heading">
                    <div>
                        <h2>
                            <i className="fas fa-shield-halved" aria-hidden="true" /> {page.name}
                        </h2>
                        <p className="muted">
                            {sensitive
                                ? 'Hassas veri — görme ve düzenleme'
                                : page.legacy_file || page.path || page.code}
                            {!page.is_active && ' · Arşiv'}
                        </p>
                    </div>
                    <div className="row-actions">
                        <span className="policy-badge">
                            {!page.is_active
                                ? 'Arşiv'
                                : page.code === 'hukumlu_kayit'
                                  ? 'Sabit okuma izni'
                                  : page.rules.some((rule) => rule.permission !== 'yok')
                                    ? 'Kural tanımlı'
                                    : 'Kapalı — kural tanımlanmadı'}
                        </span>
                        {onEditPage && (
                            <button
                                type="button"
                                className="button"
                                onClick={onEditPage}
                                aria-label="Seçili sayfa tanımını düzenle"
                            >
                                <i className="fas fa-gear" aria-hidden="true" />
                                Sayfa Ayarı
                            </button>
                        )}
                        {onEditFields && (
                            <button type="button" className="button" onClick={onEditFields}>
                                <i className="fas fa-table-columns" aria-hidden="true" /> Tablo Başlıkları
                            </button>
                        )}
                        {page.open_url && (
                            <a
                                className="button"
                                href={page.open_url}
                                target="_blank"
                                rel="noopener noreferrer"
                            >
                                Sayfayı aç{' '}
                                <i className="fas fa-arrow-up-right-from-square" aria-hidden="true" />
                            </a>
                        )}
                    </div>
                </header>
                {page.read_only && (
                    <p className="notice">
                        Bu sayfa salt okunur. Yazma tanımı bulunsa da kayıt düzenleme işlemi açılmaz.
                    </p>
                )}
                {page.is_active && (
                    <form
                        className="definition-form policy-grant-form"
                        onSubmit={(event) => {
                            event.preventDefault();
                            form.post(batchUrl, {
                                preserveScroll: true,
                                onSuccess: () => {
                                    form.reset('reason', 'confirmed');
                                    setRemoval(null);
                                },
                            });
                        }}
                    >
                        <fieldset className="admin-form-fields" disabled={busy}>
                            <div className="user-fields">
                                <div className="form-field">
                                    <label htmlFor="rule-permission">Yetki türü</label>
                                    <select
                                        id="rule-permission"
                                        value={form.data.permission}
                                        onChange={(event) =>
                                            form.setData((data) => ({
                                                ...data,
                                                permission: event.target.value,
                                                confirmed: false,
                                            }))
                                        }
                                    >
                                        <option value="giris">
                                            {sensitive
                                                ? 'Görme — hassas veriyi okuyabilir'
                                                : 'Giriş — açıp okuyabilir'}
                                        </option>
                                        {!['herkes', 'misafir'].includes(form.data.target_type) &&
                                            !page.read_only && (
                                                <option value="yazma">Yazma — düzenleyebilir</option>
                                            )}
                                    </select>
                                </div>
                                <div className="form-field">
                                    <label htmlFor="rule-target-type">Kime</label>
                                    <select
                                        id="rule-target-type"
                                        value={form.data.target_type}
                                        onChange={(event) => {
                                            const type = event.target.value as typeof form.data.target_type;
                                            form.setData((data) => ({
                                                ...data,
                                                target_type: type,
                                                target_id: ['herkes', 'misafir'].includes(type) ? '0' : '',
                                                permission: ['herkes', 'misafir'].includes(type)
                                                    ? 'giris'
                                                    : data.permission,
                                                confirmed: false,
                                            }));
                                            setQuery('');
                                        }}
                                    >
                                        <option value="birim">Birim</option>
                                        <option value="unvan">Unvan</option>
                                        <option value="kisi">Kişi</option>
                                        <option value="herkes">Herkes</option>
                                        {!sensitive && (
                                            <option value="misafir">Misafir (yalnız okuma)</option>
                                        )}
                                    </select>
                                </div>
                                <div className="form-field">
                                    <label htmlFor="rule-target">Hedef</label>
                                    {form.data.target_type === 'kisi' && (
                                        <input
                                            aria-label="Hedef ara"
                                            id="rule-target-search"
                                            type="search"
                                            value={query}
                                            onChange={(event) => setQuery(event.target.value)}
                                            placeholder="Ad soyad, kullanıcı adı veya sicil ara…"
                                        />
                                    )}
                                    <select
                                        id="rule-target"
                                        required
                                        value={
                                            ['herkes', 'misafir'].includes(form.data.target_type)
                                                ? '0'
                                                : form.data.target_id
                                        }
                                        disabled={['herkes', 'misafir'].includes(form.data.target_type)}
                                        onChange={(event) =>
                                            form.setData((data) => ({
                                                ...data,
                                                target_id: event.target.value,
                                                confirmed: false,
                                            }))
                                        }
                                    >
                                        {['herkes', 'misafir'].includes(form.data.target_type) ? (
                                            <option value="0">
                                                {form.data.target_type === 'herkes'
                                                    ? 'Tüm aktif personel'
                                                    : 'Misafir hesabı'}
                                            </option>
                                        ) : (
                                            <>
                                                <option value="">Hedef seçin</option>
                                                {options.map((item) => (
                                                    <option
                                                        key={item.id}
                                                        value={item.id}
                                                        disabled={!item.is_active}
                                                    >
                                                        {item.name}
                                                        {!item.is_active && ' (pasif)'}
                                                    </option>
                                                ))}
                                            </>
                                        )}
                                    </select>
                                </div>
                                <div className="form-field">
                                    <label htmlFor="rule-reason">
                                        Gerekçe{' '}
                                        {form.data.target_type === 'kisi'
                                            ? '(kişisel erişimde zorunlu)'
                                            : '(isteğe bağlı)'}
                                    </label>
                                    <input
                                        id="rule-reason"
                                        required={form.data.target_type === 'kisi'}
                                        placeholder="Neden bu yetki verildi?"
                                        maxLength={500}
                                        value={form.data.reason}
                                        onChange={(event) => form.setData('reason', event.target.value)}
                                    />
                                </div>
                            </div>
                            <label className="checkbox-field">
                                <input
                                    type="checkbox"
                                    required
                                    checked={form.data.confirmed}
                                    onChange={(event) => form.setData('confirmed', event.target.checked)}
                                />
                                Hedefi ve yetki etkisini inceledim
                            </label>
                            {Object.values(form.errors).map((error, index) => (
                                <p key={index} className="form-error" role="alert">
                                    {error}
                                </p>
                            ))}
                            <button
                                type="submit"
                                className="button button--primary"
                                disabled={!form.data.confirmed || !form.data.target_id}
                            >
                                <i className="fas fa-plus" aria-hidden="true" /> Yetkiyi ekle
                            </button>
                        </fieldset>
                    </form>
                )}
                <h3 className="policy-level-heading policy-level-heading--entry">
                    <i className="fas fa-right-to-bracket" aria-hidden="true" />
                    {sensitive ? 'Görme yetkileri' : 'Giriş yetkileri'}
                    <small>Sayfayı açıp okuyabilir</small>
                </h3>
                {rulesTable(readRules, true)}
                <h3 className="policy-level-heading policy-level-heading--write">
                    <i className="fas fa-pen-to-square" aria-hidden="true" />
                    {sensitive ? 'Düzenleme yetkileri' : 'Yazma yetkileri'}
                    <small>Düzenleyebilir; girişi de kapsar</small>
                </h3>
                {rulesTable(writeRules, false)}
            </div>
            {removal && (
                <PortalDialog
                    title="Yetki tanımı"
                    className="admin-catalog-dialog"
                    busy={busy}
                    onClose={() => setRemoval(null)}
                >
                    <section className="editor-panel">
                        {inherited || !page.is_active ? (
                            <>
                                <h3>{removal.name ?? removal.target_type}</h3>
                                <p>{permissionLabel(removal.permission)}</p>
                                {removal.reason && <p>{removal.reason}</p>}
                                <p>
                                    {inherited
                                        ? 'Bu okuma izni yazma tanımından gelir. Değiştirmek için Yazma yetkileri bölümündeki kaydı açın.'
                                        : 'Arşivlenmiş sayfanın yetkisi değiştirilemez.'}
                                </p>
                            </>
                        ) : (
                            <>
                                <form
                                    className="definition-form"
                                    onSubmit={(event) => {
                                        event.preventDefault();
                                        edit.post(batchUrl, {
                                            preserveScroll: true,
                                            onSuccess: () => setRemoval(null),
                                        });
                                    }}
                                >
                                    <fieldset className="admin-form-fields" disabled={busy}>
                                        <h3>{removal.name ?? removal.target_type} · Yetkiyi düzenle</h3>
                                        <div className="form-field">
                                            <label htmlFor="edit-rule-permission">Yetki</label>
                                            <select
                                                autoFocus
                                                id="edit-rule-permission"
                                                value={edit.data.permission}
                                                onChange={(event) =>
                                                    edit.setData((data) => ({
                                                        ...data,
                                                        permission: event.target.value,
                                                        confirmed: false,
                                                    }))
                                                }
                                            >
                                                <option value="giris">
                                                    {sensitive
                                                        ? 'Görme (yalnız okuma)'
                                                        : 'Giriş (yalnız okuma)'}
                                                </option>
                                                {!['herkes', 'misafir'].includes(removal.target_type) &&
                                                    !page.read_only && (
                                                        <option value="yazma">
                                                            {sensitive
                                                                ? 'Görme ve düzenleme'
                                                                : 'Yazma (okumayı kapsar)'}
                                                        </option>
                                                    )}
                                                {sensitive && removal.target_type === 'kisi' && (
                                                    <option value="yok">Erişim yok (kişisel)</option>
                                                )}
                                            </select>
                                        </div>
                                        <div className="form-field">
                                            <label htmlFor="edit-rule-reason">
                                                Gerekçe (kişisel erişimde zorunlu)
                                            </label>
                                            <input
                                                id="edit-rule-reason"
                                                required={removal.target_type === 'kisi'}
                                                maxLength={500}
                                                value={edit.data.reason}
                                                onChange={(event) =>
                                                    edit.setData((data) => ({
                                                        ...data,
                                                        reason: event.target.value,
                                                        confirmed: false,
                                                    }))
                                                }
                                            />
                                        </div>
                                        <label className="checkbox-field">
                                            <input
                                                type="checkbox"
                                                required
                                                checked={edit.data.confirmed}
                                                onChange={(event) =>
                                                    edit.setData('confirmed', event.target.checked)
                                                }
                                            />
                                            Hedefi ve yetki etkisini inceledim
                                        </label>
                                        {Object.values(edit.errors).map((error, index) => (
                                            <p key={index} className="form-error" role="alert">
                                                {error}
                                            </p>
                                        ))}
                                        <button
                                            className="button button--primary"
                                            disabled={!edit.data.confirmed}
                                        >
                                            Değişiklikleri kaydet
                                        </button>
                                    </fieldset>
                                </form>
                                <details>
                                    <summary>Bu yetki tanımını kaldır</summary>
                                    <form
                                        className="definition-form policy-remove-confirmation"
                                        onSubmit={(event) => {
                                            event.preventDefault();
                                            remove.post(batchUrl, {
                                                preserveScroll: true,
                                                onSuccess: () => setRemoval(null),
                                            });
                                        }}
                                    >
                                        <fieldset className="admin-form-fields" disabled={busy}>
                                            <h3>Yetkiyi kaldır</h3>
                                            <p>
                                                <strong>{removal.name ?? removal.target_type}</strong> için{' '}
                                                {permissionLabel(removal.permission).toLocaleLowerCase(
                                                    'tr-TR',
                                                )}{' '}
                                                tanımı kaldırılacak. Diğer erişim kaynakları korunur.
                                            </p>
                                            <label htmlFor="remove-rule-reason">
                                                Kaldırma gerekçesi (isteğe bağlı)
                                            </label>
                                            <input
                                                id="remove-rule-reason"
                                                maxLength={500}
                                                value={remove.data.reason}
                                                onChange={(event) =>
                                                    remove.setData('reason', event.target.value)
                                                }
                                            />
                                            <label className="checkbox-field">
                                                <input
                                                    required
                                                    type="checkbox"
                                                    checked={remove.data.confirmed}
                                                    onChange={(event) =>
                                                        remove.setData('confirmed', event.target.checked)
                                                    }
                                                />
                                                Bu izin tanımını kaldırmayı onaylıyorum
                                            </label>
                                            {Object.values(remove.errors).map((error, index) => (
                                                <p className="form-error" role="alert" key={index}>
                                                    {error}
                                                </p>
                                            ))}
                                            <div className="row-actions">
                                                <button
                                                    className="button button--danger"
                                                    disabled={!remove.data.confirmed}
                                                >
                                                    İzni kaldır
                                                </button>
                                                <button
                                                    type="button"
                                                    className="button"
                                                    onClick={() => setRemoval(null)}
                                                >
                                                    Vazgeç
                                                </button>
                                            </div>
                                        </fieldset>
                                    </form>
                                </details>
                            </>
                        )}
                    </section>
                </PortalDialog>
            )}
            <div className="policy-audit-card">
                <h3>
                    <i className="fas fa-clock-rotate-left" aria-hidden="true" /> Son 15 yetki işlemi
                </h3>
                {page.history?.length ? (
                    <ol className="event-list">
                        {page.history.map((event) => (
                            <li key={event.id}>
                                <strong>
                                    {event.actor ?? 'Hesap bilgisi yok'} ·{' '}
                                    {event.details.after
                                        ? permissionLabel(event.details.after)
                                        : 'İzin kaldırıldı'}
                                </strong>
                                <span>
                                    {formatDateTime(event.created_at)} ·{' '}
                                    {targetLabels[event.details.target_type ?? ''] ?? 'Hedef'} #
                                    {event.details.target_id ?? '—'}
                                    {event.details.reason ? ` · ${event.details.reason}` : ''}
                                </span>
                            </li>
                        ))}
                    </ol>
                ) : (
                    <p className="muted">Henüz yetki hareketi yok.</p>
                )}
            </div>
        </section>
    );
}
