import { portalFetch } from '../lib/http';
import { useRef, useState } from 'react';
import { Head, router } from '@inertiajs/react';
import AppShell from '../components/AppShell';
import ConflictReview from '../components/ConflictReview';
import { workspaceRequest } from '../lib/workspace';
import type { RecordValues } from '../lib/recordConflict';
import PortalDialog from '../components/PortalDialog';
import DataTable from '../components/DataTable';
import type { Column } from '../lib/table';
export type Status = {
    id: number;
    code: string;
    label: string;
    included_in_active_list: boolean;
    counts_room: boolean;
    reserves_bed: boolean;
    is_active: boolean;
    revision: number;
    usage_count: number;
};
const flags = [
    { key: 'included_in_active_list', label: 'Aktif kayıt listesine dahil' },
    { key: 'counts_room', label: 'Koğuş doluluğuna dahil' },
    { key: 'reserves_bed', label: 'Tam yerleşimde ranzayı işgal eder' },
] as const;
export function Editor({
    row,
    baseUrl,
    onSaved,
    onBusy,
}: {
    row: Status | null;
    baseUrl: string;
    onSaved: () => void;
    onBusy: (busy: boolean) => void;
}) {
    const [code, setCode] = useState(row?.code ?? '');
    const [label, setLabel] = useState(row?.label ?? '');
    const [active, setActive] = useState(row?.is_active ?? true);
    const [values, setValues] = useState<Record<(typeof flags)[number]['key'], string>>({
        included_in_active_list: row ? String(Number(row.included_in_active_list)) : '',
        counts_room: row ? String(Number(row.counts_room)) : '',
        reserves_bed: row ? String(Number(row.reserves_bed)) : '',
    });
    const [errors, setErrors] = useState<Record<string, string[]>>({});
    const [busy, setBusy] = useState(false);
    const [conflict, setConflict] = useState(false);
    const [revision, setRevision] = useState(row?.revision ?? 0);
    const draft = { label, is_active: active, ...values };
    const base = useRef<RecordValues>(draft);
    async function save() {
        if (busy || conflict) return;
        setBusy(true);
        onBusy(true);
        setErrors({});
        try {
            const response = await portalFetch(baseUrl + (row ? '/' + row.id : ''), {
                method: row ? 'PUT' : 'POST',
                credentials: 'same-origin',
                headers: { Accept: 'application/json', 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    code,
                    label,
                    is_active: active,
                    ...Object.fromEntries(flags.map((flag) => [flag.key, values[flag.key] === '1'])),
                    ...(row ? { revision } : {}),
                }),
            });
            if (!response.headers.get('content-type')?.includes('application/json'))
                throw new Error('Oturumu kontrol edip yeniden deneyin. Girdileriniz korunuyor.');
            const result = await response.json();
            if (!response.ok) {
                setConflict(response.status === 409);
                setErrors(result.errors ?? { operation: [result.message ?? 'Durum kaydedilemedi.'] });
                return;
            }
            onSaved();
            router.reload();
        } catch (e) {
            setErrors({ operation: [e instanceof Error ? e.message : 'Durum kaydedilemedi.'] });
        } finally {
            setBusy(false);
            onBusy(false);
        }
    }
    return (
        <form
            className="definition-form"
            onSubmit={(event) => {
                event.preventDefault();
                void save();
            }}
        >
            <fieldset className="module-field" disabled={busy || conflict}>
                <legend>{row ? 'Durumu düzenle' : 'Durum ekle'}</legend>
                <div className="user-fields">
                    <div className="form-field">
                        <label htmlFor="status-code">Sabit kod</label>
                        <input
                            id="status-code"
                            required
                            pattern="[a-z][a-z0-9_]*"
                            maxLength={80}
                            readOnly={!!row}
                            value={code}
                            onChange={(e) => setCode(e.target.value)}
                        />
                    </div>
                    <div className="form-field">
                        <label htmlFor="status-label">Durum adı</label>
                        <input
                            id="status-label"
                            required
                            maxLength={120}
                            value={label}
                            onChange={(e) => setLabel(e.target.value)}
                        />
                    </div>
                    {flags.map((flag) => (
                        <div className="form-field" key={flag.key}>
                            <label htmlFor={flag.key}>{flag.label}</label>
                            <select
                                id={flag.key}
                                required
                                disabled={!!row?.usage_count}
                                value={values[flag.key]}
                                onChange={(e) => setValues({ ...values, [flag.key]: e.target.value })}
                            >
                                <option value="">Seçin</option>
                                <option value="1">Evet</option>
                                <option value="0">Hayır</option>
                            </select>
                        </div>
                    ))}
                    <label>
                        <input
                            type="checkbox"
                            checked={active}
                            onChange={(e) => setActive(e.target.checked)}
                        />{' '}
                        Aktif
                    </label>
                </div>
                <p className="muted">
                    Ad değişikliği davranışı değiştirmez. Kullanılan durumun davranışı değiştirilemez. Aktif
                    işaretini kaldırmak durumu arşivler; bağlı kayıtlar korunur.
                </p>
            </fieldset>
            {Object.entries(errors).map(([key, messages]) => (
                <p className="form-error" role="alert" key={key}>
                    {messages.join(' ')}
                </p>
            ))}
            {conflict && row && (
                <ConflictReview
                    base={base.current}
                    draft={draft}
                    labels={{
                        label: 'Durum adı',
                        is_active: 'Aktif',
                        ...Object.fromEntries(flags.map((flag) => [flag.key, flag.label])),
                    }}
                    load={async (signal) => {
                        const data = await workspaceRequest<{ rows: Status[] }>(baseUrl, undefined, signal);
                        const latest = data.rows.find((item) => item.id === row.id);
                        if (!latest) throw new Error('Durum artık bulunamıyor.');
                        return {
                            values: {
                                label: latest.label,
                                is_active: latest.is_active,
                                ...Object.fromEntries(
                                    flags.map((flag) => [flag.key, String(Number(latest[flag.key]))]),
                                ),
                            },
                            metadata: latest,
                        };
                    }}
                    apply={(merged, latest) => {
                        base.current = {
                            label: latest.label,
                            is_active: latest.is_active,
                            ...Object.fromEntries(
                                flags.map((flag) => [flag.key, String(Number(latest[flag.key]))]),
                            ),
                        };
                        setLabel(String(merged.label));
                        setActive(Boolean(merged.is_active));
                        setValues(
                            Object.fromEntries(
                                flags.map((flag) => [flag.key, String(merged[flag.key])]),
                            ) as typeof values,
                        );
                        setRevision(latest.revision);
                        setConflict(false);
                        setErrors({});
                    }}
                />
            )}
            <button
                className="button button--primary"
                disabled={busy || conflict || flags.some((flag) => values[flag.key] === '')}
            >
                {busy ? 'Kaydediliyor…' : 'Durumu kaydet'}
            </button>
        </form>
    );
}
export default function Statuses({
    applicationName,
    navigation,
    rows,
    baseUrl,
}: {
    applicationName: string;
    navigation?: { label: string; url: string }[];
    rows: Status[];
    baseUrl: string;
    logoutUrl: string;
}) {
    const [busy, setBusy] = useState(false);
    const [editing, setEditing] = useState<number | 'new' | null>(null);
    const row = rows.find((r) => r.id === editing);
    const columns: Column<Status>[] = [
        { key: 'sequence', label: 'ID', kind: 'sequence', value: () => null },
        { key: 'label', label: 'Durum', value: (r) => r.label },
        ...flags.map((flag) => ({ key: flag.key, label: flag.label, value: (r: Status) => r[flag.key] })),
        { key: 'usage', label: 'Bağlı kişi', value: (r) => r.usage_count },
        { key: 'active', label: 'Kullanım', value: (r) => (r.is_active ? 'Aktif' : 'Arşiv') },
    ];
    return (
        <AppShell
            applicationName={applicationName}
            navigation={navigation}
            pageTitle="Durum seçenekleri"
            toolbar={<p className="muted">Durum ve davranış ayarları</p>}
            search={
                <button className="button button--primary" onClick={() => setEditing('new')}>
                    Yeni durum
                </button>
            }
        >
            <Head title="Durum seçenekleri" />
            {editing !== null && (
                <PortalDialog
                    title={row ? 'Durumu düzenle' : 'Yeni durum'}
                    busy={busy}
                    onClose={() => setEditing(null)}
                >
                    <section className="editor-panel">
                        <Editor
                            key={row ? row.id + '-' + row.revision : 'new'}
                            row={row ?? null}
                            baseUrl={baseUrl}
                            onSaved={() => setEditing(null)}
                            onBusy={setBusy}
                        />
                    </section>
                </PortalDialog>
            )}
            <DataTable
                rows={rows}
                columns={columns}
                onRowClick={(r) => setEditing(r.id)}
                rowKey={(r) => r.id}
                caption="Durum seçenekleri"
            />
        </AppShell>
    );
}
