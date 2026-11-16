import { Head, router } from '@inertiajs/react';
import { useRef, useState } from 'react';
import AppShell from './AppShell';
import { writeJson } from '../lib/workflow';
import { notify } from './Toast';
import { identityHeadings } from '../lib/table';
import type { Staff, Props } from '../pages/StaffTree';

type Draft = Omit<Staff, 'id' | 'review_reason' | 'revision'> & { revision?: number };
const blank: Draft = {
    parent_id: null,
    user_id: null,
    full_name: '',
    title: '',
    description: null,
    position: 0,
    is_active: true,
};
const draftOf = (row: Staff): Draft => ({
    parent_id: row.parent_id,
    user_id: row.user_id,
    full_name: row.full_name,
    title: row.title,
    description: row.description,
    position: row.position,
    is_active: !!row.is_active,
    revision: row.revision,
});

export default function ManagementStaff(p: Props) {
    const [draft, setDraft] = useState<Draft>({ ...blank });
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState('');
    const lock = useRef(false);
    async function save(value: Draft, id?: number) {
        if (lock.current || !p.canWrite) return false;
        lock.current = true;
        setBusy(true);
        setError('');
        try {
            const response = await writeJson(
                id ? `${p.baseUrl}/${id}` : p.baseUrl,
                value,
                id ? 'PUT' : 'POST',
            );
            if (!response.ok) {
                setError(
                    response.data.message ||
                        Object.values(response.data.errors ?? {})
                            .flat()
                            .join(' ') ||
                        'Kayıt kaydedilemedi.',
                );
                return false;
            }
            notify('Personel kadrosu güncellendi.');
            if (!id) setDraft({ ...blank });
            await new Promise<void>((resolve) => router.reload({ onFinish: () => resolve() }));
            return true;
        } catch (e) {
            setError(e instanceof Error ? e.message : 'Kayıt kaydedilemedi.');
            return false;
        } finally {
            lock.current = false;
            setBusy(false);
        }
    }
    return (
        <AppShell
            applicationName={p.applicationName}
            navigation={p.navigation}
            pageTitle="Personel Kadrosu"
            toolbar={null}
            search={<p>Personel ağacını ve portal kullanıcı bağlantılarını yönetin.</p>}
        >
            <Head title="Personel Kadrosu" />
            {error && (
                <p role="alert" className="form-error">
                    {error}
                </p>
            )}
            {p.canWrite && (
                <section className="editor-panel">
                    <h2>
                        <i className="fas fa-plus-circle" aria-hidden="true" /> Yeni Düğüm / Personel
                    </h2>
                    <form
                        onSubmit={(event) => {
                            event.preventDefault();
                            void save(draft);
                        }}
                    >
                        <fieldset className="admin-staff-create" disabled={busy}>
                            <label>
                                Üst Düğüm
                                <select
                                    value={draft.parent_id ?? ''}
                                    onChange={(e) =>
                                        setDraft({ ...draft, parent_id: Number(e.target.value) || null })
                                    }
                                >
                                    <option value="">En üst</option>
                                    {p.nodes
                                        .filter((row) => row.is_active)
                                        .map((row) => (
                                            <option key={row.id} value={row.id}>
                                                {row.full_name}
                                            </option>
                                        ))}
                                </select>
                            </label>
                            <label>
                                Portal Kullanıcısı
                                <select
                                    value={draft.user_id ?? ''}
                                    onChange={(e) => {
                                        const user = p.users.find((row) => row.id === Number(e.target.value));
                                        setDraft({
                                            ...draft,
                                            user_id: user?.id ?? null,
                                            full_name: user?.name ?? draft.full_name,
                                        });
                                    }}
                                >
                                    <option value="">Serbest kişi</option>
                                    {p.users.map((row) => (
                                        <option key={row.id} value={row.id}>
                                            {row.name}
                                        </option>
                                    ))}
                                </select>
                            </label>
                            <label>
                                {identityHeadings.name}
                                <input
                                    required
                                    maxLength={255}
                                    value={draft.full_name}
                                    onChange={(e) => setDraft({ ...draft, full_name: e.target.value })}
                                />
                            </label>
                            <label>
                                Unvan
                                <input
                                    required
                                    maxLength={255}
                                    value={draft.title}
                                    onChange={(e) => setDraft({ ...draft, title: e.target.value })}
                                />
                            </label>
                            <label>
                                Sıra
                                <input
                                    type="number"
                                    min={0}
                                    max={100000}
                                    value={draft.position}
                                    onChange={(e) => setDraft({ ...draft, position: Number(e.target.value) })}
                                />
                            </label>
                            <button className="button button--primary" type="submit">
                                <i className="fas fa-plus" aria-hidden="true" /> Ekle
                            </button>
                            <label className="checkbox-field">
                                <input
                                    type="checkbox"
                                    checked={draft.is_active}
                                    onChange={(e) => setDraft({ ...draft, is_active: e.target.checked })}
                                />{' '}
                                Aktif
                            </label>
                        </fieldset>
                    </form>
                </section>
            )}
            <section className="editor-panel">
                <div className="admin-staff-scroll">
                    <table className="portal-table admin-staff-table">
                        <caption className="sr-only">Personel Kadrosu</caption>
                        <thead>
                            <tr>
                                <th>{identityHeadings.name}</th>
                                <th>Üst Düğüm</th>
                                <th>Portal Bağı</th>
                                <th>Unvan</th>
                                <th>Sıra</th>
                                <th>Durum</th>
                                {p.canWrite && <th>İşlem</th>}
                            </tr>
                        </thead>
                        <tbody>
                            {p.nodes.map((row) => (
                                <StaffRow
                                    key={`${row.id}:${row.revision}`}
                                    row={row}
                                    nodes={p.nodes}
                                    users={p.users}
                                    busy={busy}
                                    canWrite={p.canWrite}
                                    save={save}
                                />
                            ))}
                        </tbody>
                    </table>
                </div>
            </section>
        </AppShell>
    );
}
function StaffRow({
    row,
    nodes,
    users,
    busy,
    canWrite,
    save,
}: {
    row: Staff;
    nodes: Staff[];
    users: Props['users'];
    busy: boolean;
    canWrite: boolean;
    save: (draft: Draft, id?: number) => Promise<boolean>;
}) {
    const [draft, setDraft] = useState(() => draftOf(row));
    const formId = `staff-${row.id}`;
    // Exclude descendants as well as this row when choosing a parent.
    const excluded = new Set([row.id]);
    let previous = 0;
    while (previous !== excluded.size) {
        previous = excluded.size;
        for (const node of nodes)
            if (node.parent_id !== null && excluded.has(node.parent_id)) excluded.add(node.id);
    }
    return (
        <tr>
            <td>
                <input
                    aria-label={`${row.full_name} adı soyadı`}
                    form={formId}
                    required
                    maxLength={255}
                    disabled={busy || !canWrite}
                    value={draft.full_name}
                    onChange={(e) => setDraft({ ...draft, full_name: e.target.value })}
                />
                {row.review_reason && <small className="form-error">{row.review_reason}</small>}
            </td>
            <td>
                <select
                    aria-label={`${row.full_name} üst düğümü`}
                    form={formId}
                    disabled={busy || !canWrite}
                    value={draft.parent_id ?? ''}
                    onChange={(e) => setDraft({ ...draft, parent_id: Number(e.target.value) || null })}
                >
                    <option value="">En üst</option>
                    {nodes
                        .filter((node) => !excluded.has(node.id) && node.is_active)
                        .map((node) => (
                            <option key={node.id} value={node.id}>
                                {node.full_name}
                            </option>
                        ))}
                </select>
            </td>
            <td>{users.find((user) => user.id === row.user_id)?.name ?? 'Serbest'}</td>
            <td>
                <input
                    aria-label={`${row.full_name} unvanı`}
                    form={formId}
                    required
                    maxLength={255}
                    disabled={busy || !canWrite}
                    value={draft.title}
                    onChange={(e) => setDraft({ ...draft, title: e.target.value })}
                />
            </td>
            <td>
                <input
                    aria-label={`${row.full_name} sırası`}
                    form={formId}
                    type="number"
                    min={0}
                    max={100000}
                    disabled={busy || !canWrite}
                    value={draft.position}
                    onChange={(e) => setDraft({ ...draft, position: Number(e.target.value) })}
                />
            </td>
            <td>
                <label className="checkbox-field">
                    <input
                        form={formId}
                        type="checkbox"
                        disabled={busy || !canWrite}
                        checked={draft.is_active}
                        onChange={(e) => setDraft({ ...draft, is_active: e.target.checked })}
                    />{' '}
                    Aktif
                </label>
            </td>
            {canWrite && (
                <td>
                    <form
                        id={formId}
                        onSubmit={(e) => {
                            e.preventDefault();
                            void save(draft, row.id);
                        }}
                    >
                        <button
                            className="button"
                            disabled={busy}
                            type="submit"
                            aria-label={`${row.full_name} kaydet`}
                            title="Kaydet"
                        >
                            <i className="fas fa-save" aria-hidden="true" />
                        </button>
                        <button
                            className="button"
                            disabled={busy || !row.is_active}
                            type="button"
                            aria-label={`${row.full_name} pasife al`}
                            title="Pasife al"
                            onClick={() => void save({ ...draft, is_active: false }, row.id)}
                        >
                            <i className="fas fa-eye-slash" aria-hidden="true" />
                        </button>
                    </form>
                </td>
            )}
        </tr>
    );
}
