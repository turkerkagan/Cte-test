import { Head, router } from '@inertiajs/react';
import {
    Background,
    Controls,
    ReactFlow,
    Position,
    useNodesState,
    type Node,
    type Viewport,
} from '@xyflow/react';
import '@xyflow/react/dist/style.css';
import { useEffect, useMemo, useRef, useState } from 'react';
import AppShell from '../components/AppShell';
import ManagementStaff from '../components/ManagementStaff';
import { identityHeadings } from '../lib/table';
import ConflictReview from '../components/ConflictReview';
import { workspaceRequest } from '../lib/workspace';
import type { RecordValues } from '../lib/recordConflict';
import PortalDialog from '../components/PortalDialog';
import RecordHoverCard from '../components/RecordHoverCard';
import { writeJson } from '../lib/workflow';
export type Staff = {
    id: number;
    parent_id: number | null;
    user_id: number | null;
    full_name: string;
    title: string;
    description: string | null;
    position: number;
    revision: number;
    is_active: boolean;
    review_reason: string | null;
};
export type Props = {
    management?: boolean;
    applicationName: string;
    navigation?: { label: string; url: string }[];
    baseUrl: string;
    logoutUrl: string;
    nodes: Staff[];
    users: { id: number; name: string }[];
    canWrite: boolean;
};
const blank = {
    parent_id: null,
    user_id: null,
    full_name: '',
    title: '',
    description: null,
    position: 0,
    is_active: true,
} as const;
function descendants(rows: Staff[], id: number): Set<number> {
    const result = new Set([id]);
    let size = 0;
    while (size !== result.size) {
        size = result.size;
        for (const row of rows) if (row.parent_id !== null && result.has(row.parent_id)) result.add(row.id);
    }
    return result;
}
export default function StaffTree(p: Props) {
    return p.management ? <ManagementStaff {...p} /> : <StaffTreeWorkspace {...p} />;
}
function StaffTreeWorkspace(p: Props) {
    const [list, setList] = useState(!!p.management);
    const [edit, setEdit] = useState(!!p.management);
    const [selected, setSelected] = useState<Staff | null>(null);
    const [form, setForm] = useState<Omit<Staff, 'id' | 'revision' | 'review_reason'>>(blank);
    const [error, setError] = useState('');
    const [busy, setBusy] = useState(false);
    const [conflict, setConflict] = useState(false);
    const base = useRef<RecordValues>({ ...blank });
    const project = (row: Staff): typeof form => ({
        parent_id: row.parent_id,
        user_id: row.user_id,
        full_name: row.full_name,
        title: row.title,
        description: row.description,
        position: row.position,
        is_active: Boolean(row.is_active),
    });
    const [editorOpen, setEditorOpen] = useState(false);
    const layout = useMemo(() => {
        const rows = p.nodes.filter((r) => r.is_active && !r.review_reason);
        const children = (id: number | null) => rows.filter((r) => r.parent_id === id);
        let leaf = 0;
        const out: Node[] = [];
        function place(row: Staff, depth: number): number {
            const nested = children(row.id);
            const xs = nested.map((r) => place(r, depth + 1));
            const x = xs.length ? (xs[0] + xs[xs.length - 1]) / 2 : leaf++ * 320;
            out.push({
                id: String(row.id),
                position: { x, y: depth * 280 },
                data: {
                    label: (
                        <div>
                            <strong>{row.full_name}</strong>
                            <p>{row.title}</p>
                        </div>
                    ),
                },
                sourcePosition: Position.Bottom,
                targetPosition: Position.Top,
                style: {
                    width: 280,
                    height: 220,
                    overflow: 'auto',
                    whiteSpace: 'normal',
                    overflowWrap: 'anywhere',
                    fontSize: 15,
                },
            });
            return x;
        }
        for (const root of children(null)) place(root, 0);
        return out;
    }, [p.nodes]);
    const [nodes, setNodes, onNodesChange] = useNodesState(layout);
    useEffect(() => setNodes(layout), [layout, setNodes]);
    const edges = useMemo(
        () =>
            p.nodes
                .filter((r) => r.is_active && !r.review_reason && r.parent_id !== null)
                .map((r) => ({
                    id: `${r.parent_id}-${r.id}`,
                    source: String(r.parent_id),
                    target: String(r.id),
                    type: 'smoothstep',
                })),
        [p.nodes],
    );
    const viewport = useMemo(() => {
        try {
            return JSON.parse(sessionStorage.getItem('staff-tree-viewport') ?? 'null') as Viewport | null;
        } catch {
            return null;
        }
    }, []);
    function select(row: Staff | null) {
        setEditorOpen(true);
        setSelected(row);
        setConflict(false);
        base.current = row ? project(row) : { ...blank };
        setForm(
            row
                ? {
                      parent_id: row.parent_id,
                      user_id: row.user_id,
                      full_name: row.full_name,
                      title: row.title,
                      description: row.description,
                      position: row.position,
                      is_active: Boolean(row.is_active),
                  }
                : blank,
        );
        setError('');
    }
    async function save() {
        if (busy || conflict || !p.canWrite) return;
        setBusy(true);
        setError('');
        try {
            const result = await writeJson(
                selected ? `${p.baseUrl}/${selected.id}` : p.baseUrl,
                { ...form, ...(selected ? { revision: selected.revision } : {}) },
                selected ? 'PUT' : 'POST',
            );
            if (!result.ok) {
                setConflict(result.status === 409);
                setError(
                    result.data.message ??
                        Object.values(result.data.errors ?? {})
                            .flat()
                            .join(' '),
                );
                return;
            }
            setEditorOpen(false);
            router.reload();
        } catch (e) {
            setError(e instanceof Error ? e.message : 'Kaydedilemedi.');
        } finally {
            setBusy(false);
        }
    }
    const forbidden = selected ? descendants(p.nodes, selected.id) : new Set<number>();
    return (
        <AppShell
            applicationName={p.applicationName}
            navigation={p.navigation}
            pageTitle={p.management ? 'Personel Kadrosu' : 'Personel organizasyon ağacı'}
            search={<p>Etkin hiyerarşi ve incelenecek bağlantılar</p>}
            toolbar={
                <>
                    <button className="button" onClick={() => setList((v) => !v)}>
                        {list ? 'Şema görünümü' : 'Liste görünümü'}
                    </button>
                    {p.canWrite && (
                        <>
                            <button
                                className="button"
                                onClick={() => {
                                    setEdit((v) => !v);
                                    setNodes(layout);
                                }}
                            >
                                Düzenleme modu {edit ? 'açık' : 'kapalı'}
                            </button>
                            <button
                                className="button"
                                onClick={() => {
                                    setEdit(true);
                                    select(null);
                                }}
                            >
                                Düğüm ekle
                            </button>
                        </>
                    )}
                </>
            }
        >
            <Head title="Personel organizasyon ağacı" />
            {!list && (
                <>
                    <p>
                        Zemini sürükleyerek kaydırın; yakınlaştırma ve ekrana sığdır kontrollerini kullanın.{' '}
                        {edit &&
                            'Düğümü bir üst düğüme yaklaştırın, ardından bağlantı ve sırayı inceleyip kaydedin.'}
                    </p>
                    <div style={{ height: '65vh', minHeight: 400 }}>
                        <ReactFlow
                            minZoom={0.02}
                            fitViewOptions={{ minZoom: 0.02, padding: 0.1 }}
                            nodes={nodes}
                            edges={edges}
                            onNodesChange={onNodesChange}
                            nodesDraggable={edit && p.canWrite}
                            nodesConnectable={false}
                            edgesFocusable={false}
                            fitView={!viewport}
                            defaultViewport={viewport ?? undefined}
                            onMoveEnd={(_, v) => {
                                try {
                                    sessionStorage.setItem('staff-tree-viewport', JSON.stringify(v));
                                } catch {
                                    /* preference optional */
                                }
                            }}
                            onNodeClick={(_, node) =>
                                select(p.nodes.find((r) => String(r.id) === node.id) ?? null)
                            }
                            onNodeDragStop={(_, node) => {
                                const row = p.nodes.find((r) => String(r.id) === node.id)!;
                                const banned = descendants(p.nodes, row.id);
                                const candidates = nodes.filter((n) => !banned.has(Number(n.id)));
                                const nearest = candidates.sort(
                                    (a, b) =>
                                        Math.hypot(
                                            a.position.x - node.position.x,
                                            a.position.y - node.position.y,
                                        ) -
                                        Math.hypot(
                                            b.position.x - node.position.x,
                                            b.position.y - node.position.y,
                                        ),
                                )[0];
                                select(row);
                                setForm({
                                    parent_id:
                                        nearest &&
                                        Math.hypot(
                                            nearest.position.x - node.position.x,
                                            nearest.position.y - node.position.y,
                                        ) < 300
                                            ? Number(nearest.id)
                                            : null,
                                    user_id: row.user_id,
                                    full_name: row.full_name,
                                    title: row.title,
                                    description: row.description,
                                    position: row.position,
                                    is_active: Boolean(row.is_active),
                                });
                            }}
                        >
                            <Background />
                            <Controls showInteractive={false} />
                        </ReactFlow>
                    </div>
                </>
            )}
            {list && (
                <div className="staff-tree-list">
                    <table className="portal-table">
                        <caption className="sr-only">Personel Kadrosu</caption>
                        <thead>
                            <tr>
                                <th>{identityHeadings.name}</th>
                                <th>Unvan</th>
                                <th>Üst düğüm</th>
                                <th>Sıra</th>
                                <th>Durum</th>
                            </tr>
                        </thead>
                        <tbody>
                            {p.nodes.map((r) => (
                                <tr
                                    key={r.id}
                                    tabIndex={0}
                                    style={{ cursor: 'pointer' }}
                                    onClick={() => select(r)}
                                    onKeyDown={(e) => {
                                        if (e.target === e.currentTarget && ['Enter', ' '].includes(e.key)) {
                                            e.preventDefault();
                                            select(r);
                                        }
                                    }}
                                >
                                    <td>
                                        <RecordHoverCard name={r.full_name} onActivate={() => select(r)}>
                                            <div>
                                                <strong>{r.full_name}</strong>
                                                <p>{r.title}</p>
                                                {r.description && <p>{r.description}</p>}
                                            </div>
                                        </RecordHoverCard>
                                    </td>
                                    <td>
                                        <div className="staff-tree-list__title">{r.title}</div>
                                    </td>
                                    <td>{p.nodes.find((n) => n.id === r.parent_id)?.full_name ?? 'Kök'}</td>
                                    <td>{r.position}</td>
                                    <td>
                                        {r.is_active ? 'Etkin' : 'Pasif'} {r.review_reason}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            )}
            {p.nodes.some((r) => r.review_reason) && (
                <section className="editor-panel">
                    <h2>İncelenecek bağlantılar</h2>
                    {p.nodes
                        .filter((r) => r.review_reason)
                        .map((r) => (
                            <p key={r.id}>
                                <button className="button" onClick={() => select(r)}>
                                    {r.full_name}
                                </button>{' '}
                                {r.review_reason}
                            </p>
                        ))}
                </section>
            )}
            {editorOpen && (
                <PortalDialog
                    title={selected ? selected.full_name : 'Yeni personel düğümü'}
                    busy={busy}
                    onClose={() => setEditorOpen(false)}
                >
                    <section className="editor-panel">
                        <h2>{selected ? selected.full_name : 'Yeni düğüm'}</h2>
                        <form
                            onSubmit={(e) => {
                                e.preventDefault();
                                void save();
                            }}
                        >
                            <fieldset disabled={!p.canWrite || busy || conflict} className="user-fields">
                                <div className="form-field">
                                    <label htmlFor="staff-name">Adı soyadı</label>
                                    <input
                                        id="staff-name"
                                        required
                                        maxLength={255}
                                        value={form.full_name}
                                        onChange={(e) => setForm({ ...form, full_name: e.target.value })}
                                    />
                                </div>
                                <div className="form-field">
                                    <label htmlFor="staff-title">Unvan</label>
                                    <input
                                        id="staff-title"
                                        required
                                        maxLength={255}
                                        value={form.title}
                                        onChange={(e) => setForm({ ...form, title: e.target.value })}
                                    />
                                </div>
                                <div className="form-field">
                                    <label htmlFor="staff-parent">Üst düğüm</label>
                                    <select
                                        id="staff-parent"
                                        value={form.parent_id ?? ''}
                                        onChange={(e) =>
                                            setForm({
                                                ...form,
                                                parent_id: e.target.value ? Number(e.target.value) : null,
                                            })
                                        }
                                    >
                                        <option value="">Kök düğüm</option>
                                        {p.nodes
                                            .filter((r) => r.is_active && !forbidden.has(r.id))
                                            .map((r) => (
                                                <option key={r.id} value={r.id}>
                                                    {r.full_name} · {r.title}
                                                </option>
                                            ))}
                                    </select>
                                </div>
                                <div className="form-field">
                                    <label htmlFor="staff-user">Bağlı personel</label>
                                    <select
                                        id="staff-user"
                                        value={form.user_id ?? ''}
                                        onChange={(e) =>
                                            setForm({
                                                ...form,
                                                user_id: e.target.value ? Number(e.target.value) : null,
                                            })
                                        }
                                    >
                                        <option value="">Bağımsız düğüm</option>
                                        {p.users.map((u) => (
                                            <option value={u.id} key={u.id}>
                                                {u.name}
                                            </option>
                                        ))}
                                    </select>
                                </div>
                                <div className="form-field">
                                    <label htmlFor="staff-order">Kardeş sırası</label>
                                    <input
                                        type="number"
                                        id="staff-order"
                                        min={0}
                                        max={100000}
                                        value={form.position}
                                        onInput={(e) =>
                                            setForm({ ...form, position: Number(e.currentTarget.value) })
                                        }
                                        onChange={(e) =>
                                            setForm({ ...form, position: Number(e.target.value) })
                                        }
                                    />
                                </div>
                                <div className="form-field">
                                    <label htmlFor="staff-description">Açıklama</label>
                                    <textarea
                                        id="staff-description"
                                        maxLength={10000}
                                        value={form.description ?? ''}
                                        onChange={(e) =>
                                            setForm({ ...form, description: e.target.value || null })
                                        }
                                    />
                                </div>
                                <label className="checkbox-field">
                                    <input
                                        type="checkbox"
                                        checked={Boolean(form.is_active)}
                                        onChange={(e) => setForm({ ...form, is_active: e.target.checked })}
                                    />
                                    Etkin
                                </label>
                            </fieldset>
                            {error && (
                                <p className="form-error" role="alert">
                                    {error}
                                </p>
                            )}
                            {conflict && selected && (
                                <ConflictReview
                                    base={base.current}
                                    draft={form}
                                    labels={{
                                        parent_id: 'Üst düğüm',
                                        user_id: 'Bağlı personel',
                                        full_name: 'Adı soyadı',
                                        title: 'Unvan',
                                        description: 'Açıklama',
                                        position: 'Sıra',
                                        is_active: 'Etkin',
                                    }}
                                    load={async (signal) => {
                                        const latest = await workspaceRequest<Props>(
                                            p.baseUrl,
                                            undefined,
                                            signal,
                                        );
                                        if (!latest.canWrite) throw new Error('Yazma yetkiniz değişti.');
                                        const node = latest.nodes.find((row) => row.id === selected.id);
                                        if (!node) throw new Error('Düğüm bulunamadı.');
                                        return { values: project(node), metadata: node };
                                    }}
                                    apply={(merged, node) => {
                                        base.current = project(node);
                                        setSelected(node);
                                        setForm(merged as typeof form);
                                        setConflict(false);
                                        setError('');
                                    }}
                                />
                            )}
                            {p.canWrite && (
                                <button className="button button--primary" disabled={busy || conflict}>
                                    Bağlantı ve sırayı onayla, kaydet
                                </button>
                            )}
                        </form>
                    </section>
                </PortalDialog>
            )}
        </AppShell>
    );
}
