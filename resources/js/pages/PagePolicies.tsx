import { Head, useForm } from '@inertiajs/react';
import { useEffect, useMemo, useState } from 'react';
import AppShell from '../components/AppShell';
import PageAccessTable, { type AccessRow, type Targets } from '../components/PageAccessTable';
import PageRuleEditor from '../components/PageRuleEditor';
import PortalDialog from '../components/PortalDialog';
import PolicyFieldEditor from '../components/PolicyFieldEditor';
import { searchName } from '../lib/table';

type Props = {
    applicationName: string;
    navigation?: { label: string; url: string }[];
    notice?: string | null;
    rows: AccessRow[];
    targets: Targets;
    baseUrl: string;
    batchUrl: string;
    logoutUrl: string;
    selectedPageId?: number | null;
    sensitiveOnly?: boolean;
};
type Selection = {
    mode: 'new' | 'edit' | 'archive' | 'view';
    row?: AccessRow;
    template?: 'hassas_ozel_not' | 'hassas_risk';
};
const sensitiveNames = { hassas_ozel_not: 'Özel Not', hassas_risk: 'Risk Bilgileri' };
const defaultOrder = [
    'hukumlu_kayit',
    'ilkgorus',
    'revir',
    'infaz',
    'ziyaret',
    'manevi',
    'egitim',
    'psikososyal',
    'mesem',
    'hijyen_isg',
    'kurslar',
];
const pageOrder = (row: AccessRow) =>
    row.menu_order ??
    (defaultOrder.includes(row.code) ? defaultOrder.indexOf(row.code) : Number.MAX_SAFE_INTEGER);
function CatalogEditor({
    selection,
    baseUrl,
    close,
    onBusy,
    onArchive,
}: {
    selection: Selection;
    baseUrl: string;
    close: () => void;
    onBusy: (busy: boolean) => void;
    onArchive: () => void;
}) {
    const { row, mode } = selection;
    const form = useForm({
        code: row?.code ?? selection.template ?? '',
        description: row?.description ?? '',
        data_table: row?.data_table ?? '',
        name: row?.name ?? (selection.template ? sensitiveNames[selection.template] : ''),
        legacy_file: row?.legacy_file ?? '',
        path: row?.path ?? '',
        revision: row?.revision ?? 1,
        permission_count: row?.permission_count ?? 0,
        confirmed: false,
    });
    const sensitivePolicy = form.data.code === 'hassas_ozel_not' || form.data.code === 'hassas_risk';
    useEffect(() => {
        onBusy(form.processing);
    }, [form.processing, onBusy]);
    const removing = mode === 'archive' && row!.is_active;
    return (
        <section className="editor-panel">
            <h2>
                {mode === 'new'
                    ? 'Sayfa tanımı ekle'
                    : mode === 'view'
                      ? 'Sayfa tanımı'
                      : mode === 'edit'
                        ? 'Sayfa tanımını düzenle'
                        : removing
                          ? 'Sayfayı kaldır'
                          : 'Sayfayı geri al'}
            </h2>
            <form
                className="definition-form"
                onSubmit={(event) => {
                    event.preventDefault();
                    if (mode === 'view') return;
                    const options = { preserveScroll: true, onSuccess: close };
                    if (mode === 'new') form.post(baseUrl, options);
                    else if (mode === 'edit') form.put(`${baseUrl}/${row!.id}`, options);
                    else if (removing) form.delete(`${baseUrl}/${row!.id}`, options);
                    else form.post(`${baseUrl}/${row!.id}/geri-al`, options);
                }}
            >
                {mode === 'view' ? (
                    <p>{row!.name} · Sayfa düzenlemesinden yönetilir.</p>
                ) : mode === 'archive' ? (
                    <>
                        <p>
                            <strong>{row!.name}</strong>{' '}
                            {removing
                                ? `arşivlenir ve ${row!.permission_count} yetki tanımı kaldırılır. PHP dosyası ve sayfanın veri kayıtları korunur.`
                                : 'yeniden etkinleşir. Önceki izinler geri gelmez; yeniden yetkilendirme gerekir.'}
                        </p>
                        <label className="checkbox-field">
                            <input
                                type="checkbox"
                                required
                                checked={form.data.confirmed}
                                onChange={(event) => form.setData('confirmed', event.target.checked)}
                            />
                            İşlemi onaylıyorum
                        </label>
                    </>
                ) : (
                    <>
                        <label htmlFor="page-code">Modül kodu</label>
                        <input
                            autoFocus
                            id="page-code"
                            required
                            maxLength={80}
                            value={form.data.code}
                            readOnly={mode === 'edit'}
                            onChange={(event) => form.setData('code', event.target.value)}
                        />
                        <label>
                            Açıklama{' '}
                            <textarea
                                maxLength={1000}
                                value={form.data.description}
                                onChange={(e) => form.setData('description', e.target.value)}
                            />
                        </label>
                        <label>
                            Kurum sayfası fiziksel tablosu{' '}
                            <input
                                placeholder="institution_records"
                                maxLength={100}
                                value={form.data.data_table}
                                onChange={(e) => form.setData('data_table', e.target.value)}
                            />
                        </label>
                        <label htmlFor="page-name">Birim / sayfa adı</label>
                        <input
                            id="page-name"
                            required
                            maxLength={150}
                            value={form.data.name}
                            onChange={(event) => form.setData('name', event.target.value)}
                        />
                        {!sensitivePolicy && (
                            <>
                                <label htmlFor="page-file">Eski PHP dosyası</label>
                                <input
                                    id="page-file"
                                    placeholder="birimler/servisler/ilkgorus.php"
                                    maxLength={150}
                                    value={form.data.legacy_file}
                                    onChange={(event) => form.setData('legacy_file', event.target.value)}
                                />
                                <label htmlFor="page-path">Yeni uygulama yolu</label>
                                <input
                                    id="page-path"
                                    placeholder="/portal"
                                    maxLength={200}
                                    value={form.data.path}
                                    onChange={(event) => form.setData('path', event.target.value)}
                                />
                                <p className="muted">
                                    Dosya veya yol alanlarından yalnız birini doldurun. Bu işlem mevcut
                                    sayfayı kataloğa ekler. Yeni modülleri Sayfa düzenlemesinde oluşturup
                                    yayımlayın.
                                </p>
                            </>
                        )}
                        {sensitivePolicy && (
                            <p className="muted">
                                Bu tanım ilgili kayıtlardaki hassas alanların görünmesini ve düzenlenmesini
                                yönetir. Ayrı bir sayfa adresi gerekmez. Tanımı oluşturmak kullanıcıya izin
                                vermez; hedefleri erişim kurallarından belirleyin.
                            </p>
                        )}
                    </>
                )}
                {Object.values(form.errors).map((error, i) => (
                    <p className="form-error" role="alert" key={i}>
                        {error}
                    </p>
                ))}
                <div className="row-actions">
                    <button
                        hidden={mode === 'view'}
                        className={`button ${removing ? 'button--danger' : 'button--primary'}`}
                        disabled={form.processing || (mode === 'archive' && !form.data.confirmed)}
                    >
                        {mode === 'archive' ? (removing ? 'Sayfayı kaldır' : 'Sayfayı geri al') : 'Kaydet'}
                    </button>
                    <button type="button" className="button" disabled={form.processing} onClick={close}>
                        Vazgeç
                    </button>
                </div>
            </form>
            {row && mode !== 'archive' && (
                <button
                    type="button"
                    className="button button--danger"
                    disabled={form.processing || (row.code === 'hukumlu_kayit' && row.is_active)}
                    onClick={onArchive}
                >
                    {row.is_active ? 'Sayfayı kaldır' : 'Sayfayı geri al'}
                </button>
            )}
        </section>
    );
}
export default function PagePolicies({
    applicationName,
    navigation,
    notice,
    rows,
    targets,
    baseUrl,
    batchUrl,
    selectedPageId,
    sensitiveOnly = false,
}: Props) {
    const [selection, setSelection] = useState<Selection | null>(null);
    const [catalogBusy, setCatalogBusy] = useState(false);
    const [query, setQuery] = useState('');
    const [sensitive, setSensitive] = useState(sensitiveOnly);
    const [pageId, setPageId] = useState<number | null>(selectedPageId ?? null);
    const [fieldPage, setFieldPage] = useState<AccessRow | null>(null);
    const syncForm = useForm({});
    useEffect(() => {
        setPageId(selectedPageId ?? null);
        setSensitive(sensitiveOnly);
    }, [selectedPageId, sensitiveOnly]);
    const visible = useMemo(
        () =>
            rows
                .filter(
                    (row) =>
                        (!sensitive || row.code.startsWith('hassas_')) &&
                        searchName(`${row.name} ${row.code}`, query),
                )
                .sort(
                    (a, b) =>
                        Number(b.code === 'hukumlu_kayit') - Number(a.code === 'hukumlu_kayit') ||
                        pageOrder(a) - pageOrder(b) ||
                        a.name.localeCompare(b.name, 'tr-TR', { numeric: true }),
                ),
        [rows, query, sensitive],
    );
    const page = visible.find((row) => row.id === pageId) ?? visible[0];
    return (
        <AppShell
            applicationName={applicationName}
            navigation={navigation}
            pageTitle="Erişim Politikaları"
            managementHeader={false}
            toolbar={null}
            search={null}
        >
            <Head title="Erişim Politikaları" />
            {notice && (
                <p className="notice" role="status">
                    {notice}
                </p>
            )}
            <header className="admin-hero policy-hero">
                <div>
                    <span className="policy-eyebrow">
                        <i className="fas fa-shield-halved" aria-hidden="true" /> ERİŞİM YÖNETİMİ
                    </span>
                    <h1>Erişim Politikaları</h1>
                    <p>
                        Önce soldan bir sayfa seçin; ardından o sayfanın giriş ve yazma yetkilerini
                        belirleyin.
                    </p>
                </div>
                <div className="admin-summary">
                    <span className="admin-pill">
                        <i className="fas fa-layer-group" aria-hidden="true" />{' '}
                        {rows.filter((row) => row.is_active).length} aktif sayfa
                    </span>
                    <span className="admin-pill">
                        <i className="fas fa-users" aria-hidden="true" /> Birim · Unvan · Kişi
                    </span>
                </div>
            </header>
            <div className="policy-master-detail">
                <aside className="policy-page-picker" aria-label="Sayfa seçimi">
                    <div className="policy-list-heading">
                        <h2>
                            <i className="fas fa-layer-group" aria-hidden="true" /> Sayfalar
                        </h2>
                        <span className="policy-count">{visible.length}</span>
                    </div>
                    <label className="sr-only" htmlFor="policy-page-query">
                        Sayfa ara
                    </label>
                    <input
                        id="policy-page-query"
                        type="search"
                        placeholder="Sayfa adı veya kodu ara…"
                        value={query}
                        onChange={(event) => setQuery(event.target.value)}
                    />
                    <div className="policy-scope" aria-label="Sayfa kapsamı">
                        <button type="button" aria-pressed={!sensitive} onClick={() => setSensitive(false)}>
                            Tüm sayfalar
                        </button>
                        <button type="button" aria-pressed={sensitive} onClick={() => setSensitive(true)}>
                            Hassas veriler
                        </button>
                    </div>
                    <div className="policy-page-list">
                        {visible.map((row, index) => (
                            <button
                                key={row.id}
                                type="button"
                                className="policy-page-choice"
                                aria-pressed={page?.id === row.id}
                                onClick={() => setPageId(row.id)}
                            >
                                <span className="policy-page-number">
                                    {String(index + 1).padStart(2, '0')}
                                </span>
                                <span className="policy-page-label">
                                    <strong>{row.name}</strong>
                                    <small>
                                        {row.is_active ? row.legacy_file || row.path || row.code : 'Arşiv'}
                                    </small>
                                </span>
                                <i className="fas fa-chevron-right" aria-hidden="true" />
                            </button>
                        ))}
                        {!visible.length && <p className="muted">Eşleşen sayfa yok.</p>}
                    </div>
                    <div className="policy-add-page">
                        <button
                            type="button"
                            className="button"
                            disabled={syncForm.processing}
                            onClick={() =>
                                syncForm.post(`${baseUrl}/sabit-sayfalar`, { preserveScroll: true })
                            }
                        >
                            Sabit sayfaları tamamla
                        </button>
                        <button
                            type="button"
                            className="button"
                            onClick={() => setSelection({ mode: 'new' })}
                        >
                            <i className="fas fa-plus" aria-hidden="true" /> Sayfa tanımı ekle
                        </button>
                        {sensitive &&
                            (Object.entries(sensitiveNames) as [keyof typeof sensitiveNames, string][])
                                .filter(([code]) => !rows.some((row) => row.code === code))
                                .map(([code, name]) => (
                                    <button
                                        type="button"
                                        className="button"
                                        key={code}
                                        onClick={() => setSelection({ mode: 'new', template: code })}
                                    >
                                        {name} izin tanımı ekle
                                    </button>
                                ))}
                    </div>
                </aside>
                <div className="policy-content">
                    {page ? (
                        <PageRuleEditor
                            key={`${page.id}-${page.revision}`}
                            page={page}
                            targets={targets}
                            batchUrl={batchUrl}
                            onEditPage={() =>
                                setSelection({ mode: page.managed_module ? 'view' : 'edit', row: page })
                            }
                            onEditFields={
                                !page.managed_module && !page.code.startsWith('hassas_')
                                    ? () => setFieldPage(page)
                                    : undefined
                            }
                        />
                    ) : (
                        <div className="editor-panel">
                            <p className="muted">İncelenecek sayfa bulunamadı.</p>
                        </div>
                    )}
                </div>
            </div>
            <details className="policy-bulk-section">
                <summary>
                    <i className="fas fa-table-list" aria-hidden="true" /> Toplu yetkilendirme ve sayfa
                    tanımları
                </summary>
                <PageAccessTable
                    rows={rows}
                    targets={targets}
                    batchUrl={batchUrl}
                    onEdit={(row) => setSelection({ mode: row.managed_module ? 'view' : 'edit', row })}
                />
            </details>
            {fieldPage && (
                <PolicyFieldEditor
                    key={fieldPage.id}
                    url={`${baseUrl}/${fieldPage.id}/alanlar`}
                    title={fieldPage.name}
                    onClose={() => setFieldPage(null)}
                />
            )}
            {selection && (
                <PortalDialog
                    title={
                        selection.mode === 'new'
                            ? 'Sayfa tanımı ekle'
                            : selection.mode === 'view'
                              ? 'Sayfa tanımı'
                              : selection.mode === 'edit'
                                ? 'Sayfa tanımını düzenle'
                                : selection.row?.is_active
                                  ? 'Sayfayı kaldır'
                                  : 'Sayfayı geri al'
                    }
                    className="admin-catalog-dialog"
                    busy={catalogBusy}
                    onClose={() => setSelection(null)}
                >
                    <CatalogEditor
                        key={`${selection.mode}-${selection.row?.id ?? selection.template ?? 'new'}-${selection.row?.revision ?? 0}`}
                        selection={selection}
                        baseUrl={baseUrl}
                        close={() => setSelection(null)}
                        onBusy={setCatalogBusy}
                        onArchive={() => setSelection({ mode: 'archive', row: selection.row })}
                    />
                </PortalDialog>
            )}
        </AppShell>
    );
}
