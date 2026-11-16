import { useForm } from '@inertiajs/react';
import { useEffect, useMemo, useState } from 'react';
import DataTable from './DataTable';
import { searchName, sortRows, type Column, type Sort } from '../lib/table';
import { useAdminMutation, type AdminMutationOptions } from '../lib/adminMutation';

export type AccessRow = {
    id: number;
    code: string;
    name: string;
    description?: string | null;
    data_table?: string | null;
    legacy_file: string | null;
    path: string | null;
    managed_module?: boolean;
    read_only?: boolean;
    is_active: boolean;
    revision: number;
    permission_count: number;
    open_url: string | null;
    menu_order?: number | null;
    history?: {
        id: number;
        actor: string | null;
        created_at: string;
        details: {
            target_type?: string;
            target_id?: number;
            before?: string | null;
            after?: string | null;
            reason?: string;
        };
    }[];
    rules: {
        target_type: string;
        target_id: number;
        name: string | null;
        permission: string;
        reason?: string | null;
    }[];
    personal_permission: string | null;
    personal_reason?: string | null;
    effective: {
        giris: boolean;
        yazma: boolean;
        sources: { type: string; permission: string; page_name?: string }[];
    };
};
export type Targets = Record<'birim' | 'unvan' | 'kisi', { id: number; name: string; is_active: boolean }[]>;
const sourceLabels: Record<string, string> = {
    birim: 'Birim',
    unvan: 'Unvan',
    kisi: 'Kişisel',
    herkes: 'Herkes',
    system_admin: 'Sistem yöneticisi',
    homepage: 'Sabit ana sayfa',
};
export function permissionLabel(permission: string | null): string {
    return permission === 'yok'
        ? 'Erişim yok (kişisel)'
        : permission === 'yazma'
          ? 'Okuma ve yazma'
          : permission === 'giris'
            ? 'Yalnız okuma'
            : 'Tanım yok';
}

function BatchEditor({
    rows,
    selections,
    batchUrl,
    targets,
    personal,
    done,
    onSaved,
    onBusy,
}: {
    rows: AccessRow[];
    selections: Record<number, number>;
    batchUrl: string;
    targets?: Targets;
    personal: boolean;
    done: () => void;
} & AdminMutationOptions) {
    const mutation = useAdminMutation({
        onSaved: async (id, message) => {
            await onSaved?.(id, message);
            done();
        },
        onBusy,
    });
    const form = useForm({
        pages: Object.entries(selections).map(([id, revision]) => ({ id: Number(id), revision })),
        target_type: 'herkes' as 'herkes' | 'birim' | 'unvan' | 'kisi',
        target_id: '0',
        operation: 'grant',
        permission: 'giris',
        reason: '',
        confirmed: false,
    });
    useEffect(() => {
        form.setData((data) => ({
            ...data,
            pages: Object.entries(selections).map(([id, revision]) => ({ id: Number(id), revision })),
            confirmed: false,
        }));
    }, [selections]);
    useEffect(() => {
        if (!onSaved) onBusy?.(form.processing);
    }, [form.processing, onSaved, onBusy]);
    const selectedRows = rows.filter((row) => selections[row.id] !== undefined);
    const options = form.data.target_type === 'herkes' ? [] : (targets?.[form.data.target_type] ?? []);
    const targetName = personal
        ? 'Bu kişi'
        : form.data.target_type === 'herkes'
          ? 'Herkes'
          : (options.find((option) => String(option.id) === form.data.target_id)?.name ?? 'Hedef seçilmedi');
    return (
        <section className="editor-panel" aria-labelledby="batch-title">
            <h2 id="batch-title">Toplu yetkilendirme · {selectedRows.length} sayfa</h2>
            <form
                className="definition-form"
                onSubmit={(event) => {
                    event.preventDefault();
                    if (onSaved) void mutation.submit(batchUrl, form.data);
                    else form.post(batchUrl, { preserveScroll: true, onSuccess: done });
                }}
            >
                <fieldset className="admin-form-fields" disabled={form.processing || mutation.busy}>
                    {!personal && (
                        <div className="user-fields">
                            <div className="form-field">
                                <label htmlFor="policy-target-type">Hedef türü</label>
                                <select
                                    id="policy-target-type"
                                    value={form.data.target_type}
                                    onChange={(event) => {
                                        const type = event.target.value as typeof form.data.target_type;
                                        form.setData((data) => ({
                                            ...data,
                                            target_type: type,
                                            target_id: type === 'herkes' ? '0' : '',
                                            permission: type === 'herkes' ? 'giris' : data.permission,
                                            confirmed: false,
                                        }));
                                    }}
                                >
                                    <option value="herkes">Herkes</option>
                                    <option value="birim">Birim</option>
                                    <option value="unvan">Unvan</option>
                                    <option value="kisi">Kişi</option>
                                </select>
                            </div>
                            {form.data.target_type !== 'herkes' && (
                                <div className="form-field">
                                    <label htmlFor="policy-target-id">Hedef</label>
                                    <select
                                        id="policy-target-id"
                                        required
                                        value={form.data.target_id}
                                        onChange={(event) =>
                                            form.setData((data) => ({
                                                ...data,
                                                target_id: event.target.value,
                                                confirmed: false,
                                            }))
                                        }
                                    >
                                        <option value="">Hedef seçin</option>
                                        {options.map((option) => (
                                            <option
                                                key={option.id}
                                                value={option.id}
                                                disabled={
                                                    !option.is_active && form.data.operation === 'grant'
                                                }
                                            >
                                                {option.name}
                                                {!option.is_active && ' (pasif)'}
                                            </option>
                                        ))}
                                        {(personal || form.data.target_type === 'kisi') &&
                                            selectedRows.every((row) =>
                                                ['hassas_ozel_not', 'hassas_risk'].includes(row.code),
                                            ) && (
                                                <option value="yok">
                                                    Erişim yok (birim / unvan iznini de kapat)
                                                </option>
                                            )}
                                    </select>
                                </div>
                            )}
                        </div>
                    )}
                    <div className="user-fields">
                        <div className="form-field">
                            <label htmlFor="policy-operation">İşlem</label>
                            <select
                                id="policy-operation"
                                value={form.data.operation}
                                onChange={(event) =>
                                    form.setData((data) => ({
                                        ...data,
                                        operation: event.target.value,
                                        confirmed: false,
                                    }))
                                }
                            >
                                <option value="grant">İzin ver veya güncelle</option>
                                <option value="remove">Bu hedefin iznini kaldır</option>
                            </select>
                        </div>
                        {form.data.operation === 'grant' && (
                            <div className="form-field">
                                <label htmlFor="policy-permission">Yetki</label>
                                <select
                                    id="policy-permission"
                                    value={form.data.permission}
                                    onChange={(event) =>
                                        form.setData((data) => ({
                                            ...data,
                                            permission: event.target.value,
                                            confirmed: false,
                                        }))
                                    }
                                >
                                    <option value="giris">Yalnız okuma</option>
                                    {(personal || form.data.target_type !== 'herkes') && (
                                        <option value="yazma">Okuma ve yazma</option>
                                    )}
                                    {(personal || form.data.target_type === 'kisi') &&
                                        selectedRows.every((row) =>
                                            ['hassas_ozel_not', 'hassas_risk'].includes(row.code),
                                        ) && (
                                            <option value="yok">
                                                Erişim yok (birim / unvan iznini de kapat)
                                            </option>
                                        )}
                                </select>
                            </div>
                        )}
                    </div>
                    <p>
                        <strong>Etki:</strong> {targetName} için {selectedRows.length} sayfada{' '}
                        {form.data.operation === 'remove'
                            ? 'seçili hedefin izin tanımı kaldırılır'
                            : permissionLabel(form.data.permission).toLocaleLowerCase('tr-TR') +
                              ' izni tanımlanır'}
                        . Diğer hedeflerin izinleri korunur.
                    </p>
                    <ul className="selection-list">
                        {selectedRows.map((row) => (
                            <li key={row.id}>{row.name}</li>
                        ))}
                    </ul>
                    <p className="muted">
                        İzni kaldırmak birim, unvan veya herkesten gelen erişimi engellemez. Yazma izni
                        okumayı da kapsar.
                    </p>
                    <label>
                        Gerekçe (kişisel erişimde zorunlu)
                        <input
                            maxLength={500}
                            required={personal || form.data.target_type === 'kisi'}
                            value={form.data.reason}
                            onChange={(e) => form.setData('reason', e.target.value)}
                        />
                    </label>
                    <label className="checkbox-field">
                        <input
                            type="checkbox"
                            required
                            checked={form.data.confirmed}
                            onChange={(event) => form.setData('confirmed', event.target.checked)}
                        />
                        Seçili sayfaları ve işlem etkisini inceledim
                    </label>
                    {Object.values(form.errors).map((error, i) => (
                        <p className="form-error" role="alert" key={i}>
                            {error}
                        </p>
                    ))}
                    {mutation.error && (
                        <p className="form-error" role="alert">
                            {mutation.error}
                        </p>
                    )}
                    <div className="row-actions">
                        <button
                            className="button button--primary"
                            disabled={
                                form.processing ||
                                !form.data.confirmed ||
                                (!personal && form.data.target_type !== 'herkes' && !form.data.target_id)
                            }
                        >
                            Seçili sayfalara uygula
                        </button>
                        <button type="button" className="button" onClick={done} disabled={form.processing}>
                            Seçimi temizle
                        </button>
                    </div>
                </fieldset>
            </form>
        </section>
    );
}

export default function PageAccessTable({
    rows,
    batchUrl,
    targets,
    personal = false,
    onEdit,
    onSaved,
    onBusy,
}: {
    rows: AccessRow[];
    batchUrl: string;
    targets?: Targets;
    personal?: boolean;
    onEdit?: (row: AccessRow) => void;
} & AdminMutationOptions) {
    const [query, setQuery] = useState('');
    const [sort, setSort] = useState<Sort>(null);
    const [selections, setSelections] = useState<Record<number, number>>({});
    useEffect(() => {
        setSelections((current) => {
            const next = Object.fromEntries(
                rows
                    .filter((row) => row.is_active && current[row.id] !== undefined)
                    .map((row) => [row.id, row.revision]),
            );
            const unchanged =
                Object.keys(current).length === Object.keys(next).length &&
                Object.entries(next).every(([id, revision]) => current[Number(id)] === revision);
            return unchanged ? current : next;
        });
    }, [rows]);
    const count = Object.keys(selections).length;
    const [busy, setBusy] = useState(false);
    const activity = (value: boolean) => {
        setBusy(value);
        onBusy?.(value);
    };
    const done = () => {
        activity(false);
        setSelections({});
    };
    const columns = useMemo<Column<AccessRow>[]>(
        () => [
            { key: 'sequence', label: 'ID', kind: 'sequence', value: () => null },
            {
                key: 'select',
                label: 'Seç',
                value: () => null,
                render: (row) => (
                    <input
                        type="checkbox"
                        aria-label={`${row.name} seç`}
                        checked={selections[row.id] !== undefined}
                        disabled={!row.is_active || (count >= 200 && selections[row.id] === undefined)}
                        onChange={(event) =>
                            setSelections((current) => {
                                const next = { ...current };
                                if (event.target.checked) next[row.id] = row.revision;
                                else delete next[row.id];
                                return next;
                            })
                        }
                    />
                ),
            },
            { key: 'name', label: 'Birim / sayfa adı', value: (row) => row.name, sortable: true },
            {
                key: 'file',
                label: 'PHP dosyası / uygulama yolu',
                value: (row) => row.legacy_file ?? row.path,
                sortable: true,
            },
            {
                key: 'link',
                label: 'Bağlantı',
                value: () => null,
                render: (row) =>
                    row.open_url ? (
                        <a
                            className="button"
                            href={row.open_url}
                            target="_blank"
                            rel="noopener noreferrer"
                            aria-label={`${row.name} aç`}
                        >
                            Sayfayı aç
                        </a>
                    ) : (
                        'Bağlantı yok'
                    ),
            },
            ...(personal
                ? [
                      {
                          key: 'personal',
                          label: 'Kişisel izin',
                          value: (row: AccessRow) =>
                              [permissionLabel(row.personal_permission), row.personal_reason]
                                  .filter(Boolean)
                                  .join(' · '),
                      },
                      {
                          key: 'effective',
                          label: 'Etkin erişim',
                          value: (row: AccessRow) =>
                              row.effective.yazma
                                  ? 'Okuma ve yazma'
                                  : row.effective.giris
                                    ? 'Yalnız okuma'
                                    : 'Kapalı',
                      },
                      {
                          key: 'sources',
                          label: 'Erişim kaynakları',
                          value: (row: AccessRow) =>
                              (row.effective.sources
                                  .map(
                                      (source) =>
                                          `${sourceLabels[source.type] ?? source.type}${source.page_name ? ` (${source.page_name})` : ''}: ${permissionLabel(source.permission)}`,
                                  )
                                  .join(' · ') || 'Tanım yok') +
                              (row.read_only ? ' · Sayfa salt okunur' : ''),
                      },
                  ]
                : [
                      {
                          key: 'rules',
                          label: 'Yetki tanımları',
                          value: (row: AccessRow) =>
                              row.rules
                                  .map(
                                      (rule) =>
                                          `${sourceLabels[rule.target_type] ?? rule.target_type} · ${rule.name ?? 'Silinmiş hedef'}: ${permissionLabel(rule.permission)}`,
                                  )
                                  .join(' / ') || 'Tanım yok',
                      },
                  ]),
            {
                key: 'active',
                label: 'Durum',
                value: (row) => (row.is_active ? 'Aktif' : 'Arşiv'),
                sortable: true,
            },
        ],
        [selections, count, personal, onEdit],
    );
    const visible = useMemo(
        () =>
            sortRows(
                rows.filter((row) =>
                    searchName(`${row.name} ${row.code} ${row.legacy_file ?? row.path ?? ''}`, query),
                ),
                columns,
                sort,
            ),
        [rows, columns, sort, query],
    );
    return (
        <fieldset className="admin-form-fields" disabled={busy}>
            <div className="policy-tools">
                <div className="search-field">
                    <label htmlFor={personal ? 'personal-policy-search' : 'policy-search'}>
                        Hızlı sayfa ara
                    </label>
                    <input
                        id={personal ? 'personal-policy-search' : 'policy-search'}
                        type="search"
                        value={query}
                        onChange={(event) => setQuery(event.target.value)}
                    />
                </div>
                <div className="row-actions">
                    <button
                        type="button"
                        className="button"
                        onClick={() =>
                            setSelections(
                                Object.fromEntries(
                                    visible
                                        .filter((row) => row.is_active)
                                        .slice(0, 200)
                                        .map((row) => [row.id, row.revision]),
                                ),
                            )
                        }
                    >
                        Görünen aktif sayfaları seç
                    </button>
                    <button type="button" className="button" onClick={() => setSelections({})}>
                        Seçimi temizle
                    </button>
                    <span role="status">{count} sayfa seçili · en fazla 200</span>
                </div>
            </div>
            {count > 0 && (
                <BatchEditor
                    rows={rows}
                    selections={selections}
                    batchUrl={batchUrl}
                    targets={targets}
                    personal={personal}
                    done={done}
                    onSaved={onSaved}
                    onBusy={activity}
                />
            )}
            <DataTable
                rows={visible}
                columns={columns}
                onRowClick={onEdit}
                rowDisabled={() => busy}
                rowLabel={(row) => `${row.name} sayfa tanımını aç`}
                rowKey={(row) => row.id}
                caption={personal ? 'Kişinin sayfa erişimleri' : 'Erişim politikaları'}
                sort={sort}
                onSort={(key) =>
                    setSort((current) => ({
                        key,
                        direction: current?.key === key && current.direction === 'asc' ? 'desc' : 'asc',
                    }))
                }
            />
        </fieldset>
    );
}
