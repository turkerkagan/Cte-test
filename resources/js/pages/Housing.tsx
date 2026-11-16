import { portalFetch } from '../lib/http';
import { useRef, useState } from 'react';
import { Head, router } from '@inertiajs/react';
import HousingBulk from '../components/HousingBulk';
import AppShell from '../components/AppShell';
import ConflictReview from '../components/ConflictReview';
import { workspaceRequest } from '../lib/workspace';
import type { RecordValues } from '../lib/recordConflict';
import PortalDialog from '../components/PortalDialog';
import DataTable from '../components/DataTable';
import type { Column } from '../lib/table';
export type Block = { id: number; code: string; name: string; is_active: boolean; revision: number };
export type Room = Block & {
    block_id: number;
    bed_count: number;
    capacity: number;
    kind: string;
    occupied_count: number;
};
type Props = {
    applicationName: string;
    navigation?: { label: string; url: string }[];
    baseUrl: string;
    logoutUrl: string;
    blocks: Block[];
    rooms: Room[];
};
export function SettingsForm({
    row,
    block,
    blocks,
    baseUrl,
    room,
    onSaved,
    onBusy,
}: {
    row: Block | Room | null;
    block?: Block;
    blocks: Block[];
    baseUrl: string;
    room: boolean;
    onSaved: () => void;
    onBusy: (busy: boolean) => void;
}) {
    const prior = room && row ? (row as Room) : null;
    const [values, setValues] = useState({
        code: row?.code ?? '',
        name: row?.name ?? '',
        is_active: row?.is_active ?? true,
        block_id: prior?.block_id ?? block?.id ?? 0,
        bed_count: prior?.bed_count ?? 12,
        capacity: prior?.capacity ?? 12,
        kind: prior?.kind ?? 'normal',
    });
    const [errors, setErrors] = useState<Record<string, string[]>>({});
    const [busy, setBusy] = useState(false);
    const [conflict, setConflict] = useState(false);
    const [revision, setRevision] = useState(row?.revision ?? 0);
    const fields = room
        ? ['code', 'name', 'is_active', 'block_id', 'bed_count', 'capacity', 'kind']
        : ['code', 'name', 'is_active'];
    const project = (source: typeof values | Block | Room): RecordValues =>
        Object.fromEntries(fields.map((key) => [key, (source as unknown as RecordValues)[key]]));
    const base = useRef(project(values));
    async function save() {
        if (busy || conflict) return;
        setBusy(true);
        onBusy(true);
        setErrors({});
        try {
            const response = await portalFetch(
                baseUrl + '/' + (room ? 'rooms' : 'blocks') + (row ? '/' + row.id : ''),
                {
                    method: row ? 'PUT' : 'POST',
                    credentials: 'same-origin',
                    headers: { Accept: 'application/json', 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        code: values.code,
                        name: values.name,
                        is_active: values.is_active,
                        ...(room
                            ? {
                                  block_id: values.block_id,
                                  bed_count: values.bed_count,
                                  capacity: values.capacity,
                                  kind: values.kind,
                              }
                            : {}),
                        ...(row ? { revision } : {}),
                    }),
                },
            );
            if (!response.headers.get('content-type')?.includes('application/json'))
                throw new Error('Oturumu kontrol edip yeniden deneyin. Girdileriniz korunuyor.');
            const result = await response.json();
            if (!response.ok) {
                setConflict(response.status === 409);
                setErrors(result.errors ?? { operation: [result.message ?? 'Ayar kaydedilemedi.'] });
                return;
            }
            onSaved();
            router.reload();
        } catch (e) {
            setErrors({ operation: [e instanceof Error ? e.message : 'Ayar kaydedilemedi.'] });
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
                <legend>
                    {room ? (row ? 'Koğuşu düzenle' : 'Koğuş ekle') : row ? 'Bloğu düzenle' : 'Blok ekle'}
                </legend>
                <div className="user-fields">
                    <div className="form-field">
                        <label htmlFor={room ? 'room-code' : 'block-code'}>Kod</label>
                        <input
                            id={room ? 'room-code' : 'block-code'}
                            required
                            maxLength={room ? 100 : 50}
                            value={values.code}
                            onChange={(e) => setValues({ ...values, code: e.target.value })}
                        />
                    </div>
                    <div className="form-field">
                        <label htmlFor={room ? 'room-name' : 'block-name'}>Ad</label>
                        <input
                            id={room ? 'room-name' : 'block-name'}
                            required
                            maxLength={120}
                            value={values.name}
                            onChange={(e) => setValues({ ...values, name: e.target.value })}
                        />
                    </div>
                    {room && (
                        <>
                            <div className="form-field">
                                <label htmlFor="room-parent">Blok</label>
                                <select
                                    id="room-parent"
                                    value={values.block_id}
                                    onChange={(e) =>
                                        setValues({ ...values, block_id: Number(e.target.value) })
                                    }
                                >
                                    {blocks
                                        .filter((b) => b.is_active || b.id === values.block_id)
                                        .map((b) => (
                                            <option key={b.id} value={b.id}>
                                                {b.name}
                                                {!b.is_active && ' (arşiv)'}
                                            </option>
                                        ))}
                                </select>
                            </div>
                            <div className="form-field">
                                <label htmlFor="room-beds">Ranza sayısı</label>
                                <input
                                    id="room-beds"
                                    type="number"
                                    min={0}
                                    max={500}
                                    required
                                    value={values.bed_count}
                                    onChange={(e) =>
                                        setValues({ ...values, bed_count: Number(e.target.value) })
                                    }
                                />
                            </div>
                            <div className="form-field">
                                <label htmlFor="room-capacity">Kişi kapasitesi</label>
                                <input
                                    id="room-capacity"
                                    type="number"
                                    min={0}
                                    max={500}
                                    required
                                    value={values.capacity}
                                    onChange={(e) =>
                                        setValues({ ...values, capacity: Number(e.target.value) })
                                    }
                                />
                            </div>
                            <div className="form-field">
                                <label htmlFor="room-kind">Koğuş türü</label>
                                <select
                                    id="room-kind"
                                    value={values.kind}
                                    onChange={(e) => setValues({ ...values, kind: e.target.value })}
                                >
                                    <option value="normal">Normal</option>
                                    <option value="gecici">Geçici</option>
                                    <option value="ozel">Özel</option>
                                </select>
                            </div>
                        </>
                    )}
                    <label>
                        <input
                            type="checkbox"
                            checked={values.is_active}
                            onChange={(e) => setValues({ ...values, is_active: e.target.checked })}
                        />{' '}
                        Aktif
                    </label>
                </div>
                <p className="muted">
                    Aktif işaretini kaldırmak tanımı arşivler. Dolu yerleşimi etkileyen değişiklikler
                    engellenir.
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
                    draft={project(values)}
                    labels={{
                        code: 'Kod',
                        name: 'Ad',
                        is_active: 'Aktif',
                        block_id: 'Blok',
                        bed_count: 'Ranza sayısı',
                        capacity: 'Kapasite',
                        kind: 'Koğuş türü',
                    }}
                    load={async (signal) => {
                        const data = await workspaceRequest<{ blocks: Block[]; rooms: Room[] }>(
                            baseUrl,
                            undefined,
                            signal,
                        );
                        const latest = (room ? data.rooms : data.blocks).find((item) => item.id === row.id);
                        if (!latest) throw new Error('Tanım artık bulunamıyor.');
                        return { values: project(latest), metadata: latest };
                    }}
                    apply={(merged, latest) => {
                        base.current = project(latest);
                        setValues((previous) => ({ ...previous, ...merged }) as typeof values);
                        setRevision(latest.revision);
                        setConflict(false);
                        setErrors({});
                    }}
                />
            )}
            <button className="button button--primary" disabled={busy || conflict}>
                {busy ? 'Kaydediliyor…' : 'Ayarı kaydet'}
            </button>
        </form>
    );
}
export default function Housing({ applicationName, navigation, baseUrl, blocks, rooms }: Props) {
    const [selected, setSelected] = useState<number | null>(blocks[0]?.id ?? null);
    const [editId, setEditId] = useState<number | null>(null);
    const [addRoom, setAddRoom] = useState(false);
    const [addBlock, setAddBlock] = useState(false);
    const [busy, setBusy] = useState(false);
    const [editBlock, setEditBlock] = useState(false);
    const block = blocks.find((b) => b.id === selected);
    const visible = rooms.filter((r) => r.block_id === selected);
    const edit = visible.find((r) => r.id === editId);
    const columns: Column<Room>[] = [
        { key: 'code', label: 'Koğuş Kodu', value: (r) => r.code },
        { key: 'name', label: 'Koğuş Adı', value: (r) => r.name },
        { key: 'bed_count', label: 'Ranza Sayısı', value: (r) => r.bed_count },
        { key: 'capacity', label: 'Kapasite', value: (r) => r.capacity },
        {
            key: 'kind',
            label: 'Koğuş Türü',
            value: (r) =>
                (({ normal: 'Normal', gecici: 'Geçici', ozel: 'Özel' }) as Record<string, string>)[r.kind] ??
                r.kind,
        },
        {
            key: 'active',
            label: 'Durum',
            value: (r) => (r.is_active && block?.is_active ? 'Aktif' : 'Arşiv'),
        },
    ];
    return (
        <AppShell
            applicationName={applicationName}
            navigation={navigation}
            pageTitle="Yerleşim ayarları"
            toolbar={<p className="muted">Blok → Koğuş → Ranza</p>}
            search={
                <div className="form-field">
                    <label htmlFor="housing-block">Blok seçin</label>
                    <select
                        id="housing-block"
                        value={selected ?? ''}
                        onChange={(e) => {
                            setSelected(Number(e.target.value));
                            setEditId(null);
                            setAddRoom(false);
                            setAddBlock(false);
                            setEditBlock(false);
                        }}
                    >
                        {blocks.map((b) => (
                            <option value={b.id} key={b.id}>
                                {b.name}
                                {!b.is_active && ' (arşiv)'}
                            </option>
                        ))}
                    </select>
                </div>
            }
        >
            <Head title="Yerleşim ayarları" />
            <HousingBulk baseUrl={baseUrl} />
            <div className="row-actions">
                <button className="button" onClick={() => setAddBlock(!addBlock)}>
                    {addBlock ? 'Blok eklemeyi kapat' : 'Yeni blok'}
                </button>
                {block && (
                    <button className="button" onClick={() => setEditBlock(true)}>
                        Bloğu düzenle
                    </button>
                )}
                {block?.is_active && (
                    <button
                        className="button"
                        onClick={() => {
                            setAddRoom(!addRoom);
                            setEditId(null);
                        }}
                    >
                        Koğuş ekle
                    </button>
                )}
            </div>
            {(addBlock || (editBlock && block)) && (
                <PortalDialog
                    title={addBlock ? 'Yeni blok' : 'Bloğu düzenle'}
                    busy={busy}
                    onClose={() => {
                        setAddBlock(false);
                        setEditBlock(false);
                    }}
                >
                    <section className="editor-panel">
                        <SettingsForm
                            key={addBlock ? 'new-block' : `block-${block?.id}-${block?.revision}`}
                            row={addBlock ? null : (block ?? null)}
                            blocks={blocks}
                            room={false}
                            baseUrl={baseUrl}
                            onBusy={setBusy}
                            onSaved={() => {
                                setAddBlock(false);
                                setEditBlock(false);
                            }}
                        />
                    </section>
                </PortalDialog>
            )}
            {block && (
                <>
                    {(edit || addRoom) && (
                        <PortalDialog
                            title={edit ? 'Koğuşu düzenle' : 'Yeni koğuş'}
                            busy={busy}
                            onClose={() => {
                                setEditId(null);
                                setAddRoom(false);
                            }}
                        >
                            <section className="editor-panel">
                                <SettingsForm
                                    key={edit ? `room-${edit.id}-${edit.revision}` : `new-room-${block.id}`}
                                    row={edit ?? null}
                                    block={block}
                                    blocks={blocks}
                                    room={true}
                                    baseUrl={baseUrl}
                                    onBusy={setBusy}
                                    onSaved={() => {
                                        setEditId(null);
                                        setAddRoom(false);
                                    }}
                                />
                            </section>
                        </PortalDialog>
                    )}
                    <h2>{block.name} koğuşları</h2>
                    <DataTable
                        rows={visible}
                        columns={columns}
                        onRowClick={(row) => {
                            setEditId(row.id);
                            setAddRoom(false);
                        }}
                        rowLabel={(row) => `${row.name} koğuşunu düzenle`}
                        rowKey={(row) => row.id}
                        caption={block.name + ' koğuşları'}
                    />
                </>
            )}
        </AppShell>
    );
}
