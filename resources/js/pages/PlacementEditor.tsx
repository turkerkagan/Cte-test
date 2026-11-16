import { portalFetch } from '../lib/http';
import { useRef, useState } from 'react';
import { Head, Link, router } from '@inertiajs/react';
import ConflictReview from '../components/ConflictReview';
import { workspaceRequest } from '../lib/workspace';
import type { RecordValues } from '../lib/recordConflict';
import AppShell from '../components/AppShell';
type Id = number | null;
type Record = {
    status_id: Id;
    block_id: Id;
    room_id: Id;
    bed_id: Id;
    revision: number;
    status_revision: number;
};
type Props = {
    applicationName: string;
    navigation?: { label: string; url: string }[];
    baseUrl: string;
    logoutUrl: string;
    submitUrl: string;
    person: { id: number; full_name: string | null; tc_identity: string | null };
    record: Record;
    statuses: { id: number; label: string; is_active: boolean }[];
    blocks: { id: number; name: string; is_active: boolean }[];
    rooms: { id: number; block_id: number; name: string; is_active: boolean }[];
    beds: { id: number; room_id: number; label: string; available: boolean; is_active: boolean }[];
};
export default function PlacementEditor({
    applicationName,
    navigation,
    baseUrl,
    submitUrl,
    person,
    record,
    statuses,
    blocks,
    rooms,
    beds,
}: Props) {
    const [values, setValues] = useState(record);
    const [errors, setErrors] = useState<{ [key: string]: string[] }>({});
    const [busy, setBusy] = useState(false);
    const [conflict, setConflict] = useState(false);
    const [options, setOptions] = useState({ statuses, blocks, rooms, beds });
    const fields = ['status_id', 'block_id', 'room_id', 'bed_id'];
    const project = (source: Record): RecordValues =>
        Object.fromEntries(fields.map((key) => [key, source[key as keyof Record]]));
    const base = useRef(project(record));
    async function save() {
        if (busy || conflict) return;
        setBusy(true);
        setErrors({});
        try {
            const response = await portalFetch(submitUrl, {
                method: 'PUT',
                credentials: 'same-origin',
                headers: { Accept: 'application/json', 'Content-Type': 'application/json' },
                body: JSON.stringify(values),
            });
            if (!response.headers.get('content-type')?.includes('application/json'))
                throw new Error('Oturumu kontrol edip yeniden deneyin. Girdileriniz korunuyor.');
            const result = await response.json();
            if (!response.ok) {
                setConflict(response.status === 409);
                setErrors(result.errors ?? { operation: [result.message ?? 'Yerleşim kaydedilemedi.'] });
                return;
            }
            router.visit(baseUrl);
        } catch (e) {
            setErrors({ operation: [e instanceof Error ? e.message : 'Yerleşim kaydedilemedi.'] });
        } finally {
            setBusy(false);
        }
    }
    return (
        <AppShell
            applicationName={applicationName}
            navigation={navigation}
            pageTitle="Durum ve yerleşim"
            toolbar={
                <Link className="button" href={baseUrl}>
                    Kayıtlara dön
                </Link>
            }
            search={
                <p>
                    <strong>{person.full_name ?? 'Adı boş'}</strong> · {person.tc_identity ?? 'TC boş'}
                </p>
            }
        >
            <Head title="Durum ve yerleşim" />
            <section className="editor-panel">
                <form
                    className="definition-form"
                    onSubmit={(event) => {
                        event.preventDefault();
                        void save();
                    }}
                >
                    <fieldset className="module-field" disabled={busy || conflict}>
                        <legend>Durum ve konum</legend>
                        <div className="user-fields">
                            <div className="form-field">
                                <label htmlFor="placement-status">Durum</label>
                                <select
                                    id="placement-status"
                                    value={values.status_id ?? ''}
                                    onChange={(e) =>
                                        setValues({
                                            ...values,
                                            status_id: e.target.value ? Number(e.target.value) : null,
                                        })
                                    }
                                >
                                    <option value="">Durum belirtilmedi</option>
                                    {options.statuses.map((s) => (
                                        <option
                                            key={s.id}
                                            value={s.id}
                                            disabled={!s.is_active && s.id !== record.status_id}
                                        >
                                            {s.label}
                                            {!s.is_active && ' (arşiv)'}
                                        </option>
                                    ))}
                                </select>
                            </div>
                            <div className="form-field">
                                <label htmlFor="placement-block">Blok</label>
                                <select
                                    id="placement-block"
                                    value={values.block_id ?? ''}
                                    onChange={(e) =>
                                        setValues({
                                            ...values,
                                            block_id: e.target.value ? Number(e.target.value) : null,
                                            room_id: null,
                                            bed_id: null,
                                        })
                                    }
                                >
                                    <option value="">Blok belirtilmedi</option>
                                    {options.blocks.map((b) => (
                                        <option
                                            key={b.id}
                                            value={b.id}
                                            disabled={!b.is_active && b.id !== record.block_id}
                                        >
                                            {b.name}
                                            {!b.is_active && ' (arşiv)'}
                                        </option>
                                    ))}
                                </select>
                            </div>
                            <div className="form-field">
                                <label htmlFor="placement-room">Koğuş</label>
                                <select
                                    id="placement-room"
                                    disabled={!values.block_id}
                                    value={values.room_id ?? ''}
                                    onChange={(e) =>
                                        setValues({
                                            ...values,
                                            room_id: e.target.value ? Number(e.target.value) : null,
                                            bed_id: null,
                                        })
                                    }
                                >
                                    <option value="">Koğuş belirtilmedi</option>
                                    {options.rooms
                                        .filter((r) => r.block_id === values.block_id)
                                        .map((r) => (
                                            <option
                                                key={r.id}
                                                value={r.id}
                                                disabled={!r.is_active && r.id !== record.room_id}
                                            >
                                                {r.name}
                                                {!r.is_active && ' (arşiv)'}
                                            </option>
                                        ))}
                                </select>
                            </div>
                            <div className="form-field">
                                <label htmlFor="placement-bed">Ranza</label>
                                <select
                                    id="placement-bed"
                                    disabled={!values.room_id}
                                    value={values.bed_id ?? ''}
                                    onChange={(e) =>
                                        setValues({
                                            ...values,
                                            bed_id: e.target.value ? Number(e.target.value) : null,
                                        })
                                    }
                                >
                                    <option value="">Ranza belirtilmedi</option>
                                    {options.beds
                                        .filter((b) => b.room_id === values.room_id)
                                        .map((b) => (
                                            <option
                                                key={b.id}
                                                value={b.id}
                                                disabled={
                                                    !b.available || (!b.is_active && b.id !== record.bed_id)
                                                }
                                            >
                                                {b.label}
                                                {!b.available && ' (dolu)'}
                                                {!b.is_active && ' (arşiv)'}
                                            </option>
                                        ))}
                                </select>
                            </div>
                        </div>
                        <p className="muted">
                            Blok değişirse koğuş ve ranza; koğuş değişirse ranza seçimi temizlenir. Dolu ranza
                            sunucuda tekrar kontrol edilir.
                        </p>
                    </fieldset>
                    {Object.entries(errors).map(([key, messages]) => (
                        <p key={key} className="form-error" role="alert">
                            {messages.join(' ')}
                        </p>
                    ))}
                    {conflict && (
                        <ConflictReview
                            base={base.current}
                            draft={project(values)}
                            labels={{
                                status_id: 'Durum',
                                block_id: 'Blok',
                                room_id: 'Koğuş',
                                bed_id: 'Ranza',
                            }}
                            display={(key, value) => {
                                const rows =
                                    key === 'status_id'
                                        ? options.statuses
                                        : key === 'block_id'
                                          ? options.blocks
                                          : key === 'room_id'
                                            ? options.rooms
                                            : options.beds;
                                const item = rows.find((row) => row.id === value);
                                return value === null
                                    ? 'Belirtilmedi'
                                    : item
                                      ? 'label' in item
                                          ? item.label
                                          : item.name
                                      : String(value);
                            }}
                            load={async (signal) => {
                                const latest = await workspaceRequest<Props>(submitUrl, undefined, signal);
                                if (latest.person.id !== person.id) throw new Error('Kayıt eşleşmedi.');
                                return { values: project(latest.record), metadata: latest };
                            }}
                            apply={(merged, latest) => {
                                base.current = project(latest.record);
                                setValues({ ...latest.record, ...merged } as Record);
                                setOptions(latest);
                                setConflict(false);
                                setErrors({});
                            }}
                        />
                    )}
                    <button className="button button--primary" disabled={busy || conflict}>
                        {busy ? 'Kaydediliyor…' : 'Yerleşimi kaydet'}
                    </button>
                </form>
            </section>
        </AppShell>
    );
}
