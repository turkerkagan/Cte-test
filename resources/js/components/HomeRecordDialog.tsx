import ConflictReview from './ConflictReview';
import type { RecordValues } from '../lib/recordConflict';
import { workspaceRequest } from '../lib/workspace';
import { useEffect, useRef, useState } from 'react';
import { router } from '@inertiajs/react';
import { notify } from './Toast';
import HomeBulkOperations from './HomeBulkOperations';
import PortalDialog from './PortalDialog';
import PrivateNoteModal from './PrivateNoteModal';
import EmptyRoomPicker, { type RoomOccupancy } from './EmptyRoomPicker';
import HomeShortcuts from './HomeShortcuts';
import { writeJson } from '../lib/workflow';
import { compareNatural } from '../lib/table';
import useUnsavedChanges from '../lib/useUnsavedChanges';
export type HomeOptions = {
    birthWritable: boolean;
    registeredOn: string;
    riskWritable: boolean;
    caseOptions: Record<string, { id: number; label: string; is_active: boolean }[]>;
    statuses: { id: number; label: string; code: string; included_in_active_list?: boolean }[];
    blocks: { id: number; name: string }[];
    rooms: { id: number; block_id: number; name: string; capacity: number; bed_count: number }[];
    beds: { id: number; room_id: number; label: string; available: boolean; is_empty?: boolean }[];
};
type Identity = {
    tc_identity: string;
    full_name: string;
    registered_on: string;
    admitted_on: string;
    revision?: number;
};
type Placement = {
    status_id: number | null;
    block_id: number | null;
    room_id: number | null;
    bed_id: number | null;
    revision: number;
    status_revision: number;
};
type Values = Record<string, string | number | null>;
type Detail = {
    writableFields?: string[];
    canWrite?: boolean;
    identity: Identity;
    values: Values;
    caseRevision: number;
    sentenceRevision: number;
    placement: {
        record: Placement;
        statuses: HomeOptions['statuses'];
        blocks: HomeOptions['blocks'];
        rooms: HomeOptions['rooms'];
        beds: HomeOptions['beds'];
    };
    dates: Record<string, string | null> | null;
    leaves: Record<string, string | null> | null;
};
export default function HomeRecordDialog({
    id,
    options,
    baseUrl,
    onClose,
    occupancy,
    links,
    photoUrl,
    canWrite,
    privateWritable,
}: {
    id: number | null;
    options: HomeOptions;
    baseUrl: string;
    onClose: () => void;
    occupancy: RoomOccupancy[];
    links: { label: string; url: string }[];
    photoUrl?: string;
    canWrite: boolean;
    privateWritable: boolean;
}) {
    const [visited, setVisited] = useState({ bulk: false, shortcuts: false });
    const [photoFailed, setPhotoFailed] = useState(false);
    const [childBusy, setChildBusy] = useState(false);
    const [privateOpen, setPrivateOpen] = useState(false);
    const initialFocus = useRef(false);
    const requestLock = useRef(false);
    const [attempt, setAttempt] = useState(0);
    const [mode, setMode] = useState<'single' | 'bulk' | 'shortcuts'>('single');
    const batchUrl = links.find((l) =>
        new URL(l.url, location.origin).pathname.endsWith('/uyap-karsilastirma'),
    )?.url;
    const shortcutUrl = links.find((l) =>
        new URL(l.url, location.origin).pathname.endsWith('/toplu-durum'),
    )?.url;
    const [deletion, setDeletion] = useState<{ token: string; count: number } | null>(null);
    const [deleteConfirmation, setDeleteConfirmation] = useState('');
    const dialog = useRef<HTMLDialogElement>(null);
    const [identity, setIdentity] = useState<Identity>({
        tc_identity: '',
        full_name: '',
        registered_on: options.registeredOn,
        admitted_on: '',
    });
    const [placement, setPlacement] = useState<Placement>({
        status_id: options.statuses.find((s) => s.code === 'new_record')?.id ?? null,
        block_id: null,
        room_id: null,
        bed_id: null,
        revision: 0,
        status_revision: 0,
    });
    const [values, setValues] = useState<Values>({
        huk_calis: null,
        huk_karar:
            options.caseOptions.huk_karar?.find((o) => o.is_active && o.label === 'KARARSIZ')?.id ?? null,
        huk_saglik: 'SAĞLIKLI',
        huk_notlar: '',
        ...(options.birthWritable ? { infaz_dogum_tarihi: null } : {}),
        ...(options.riskWritable
            ? {
                  huk_risk:
                      options.caseOptions.huk_risk?.find((o) => o.label === '1' && o.is_active)?.id ?? null,
              }
            : {}),
    });
    const [detail, setDetail] = useState<Detail | null>(null);
    const [caseRevision, setCaseRevision] = useState(0);
    const [busy, setBusy] = useState(id !== null);
    const [loaded, setLoaded] = useState(id === null);
    const [errors, setErrors] = useState<Record<string, string[]>>({});
    const [conflict, setConflict] = useState(false);
    const [roomsOpen, setRoomsOpen] = useState(false);
    const draftValue = JSON.stringify({ identity, placement, values });
    function flat(identity: Identity, placement: Placement, values: Values): RecordValues {
        return {
            ...Object.fromEntries(
                ['tc_identity', 'full_name', 'registered_on', 'admitted_on'].map((key) => [
                    'identity:' + key,
                    identity[key as keyof Identity] ?? '',
                ]),
            ),
            ...Object.fromEntries(
                ['status_id', 'block_id', 'room_id', 'bed_id'].map((key) => [
                    'placement:' + key,
                    placement[key as keyof Placement] ?? null,
                ]),
            ),
            ...Object.fromEntries(Object.entries(values).map(([key, value]) => ['value:' + key, value])),
        };
    }
    const baseline = useRef<RecordValues>(flat(identity, placement, values));
    const initialDraft = useRef<string | null>(id === null ? draftValue : null);
    const loadedDraft = useRef(draftValue);
    loadedDraft.current = draftValue;
    useEffect(() => {
        if (loaded) {
            initialDraft.current = loadedDraft.current;
            baseline.current = flat(identity, placement, values);
        }
    }, [loaded]);
    const [confirmClose, setConfirmClose] = useState(false);
    const dirty = canWrite && loaded && initialDraft.current !== null && initialDraft.current !== draftValue;
    useUnsavedChanges(dirty);
    function requestClose() {
        if (busy || childBusy) return;
        if (dirty) setConfirmClose(true);
        else onClose();
    }
    useEffect(() => {
        const previous = document.activeElement as HTMLElement | null;
        const node = dialog.current;
        node?.showModal();
        node?.querySelector<HTMLInputElement>('#home-tc_identity')?.focus();
        return () => {
            node?.close();
            previous?.focus();
        };
    }, []);
    useEffect(() => {
        if (id === null) return;
        setBusy(true);
        setLoaded(false);
        setErrors({});
        setConflict(false);
        const abort = new AbortController();
        fetch(baseUrl + '/' + id, {
            credentials: 'same-origin',
            headers: { Accept: 'application/json' },
            signal: abort.signal,
        })
            .then(async (response) => {
                if (!response.ok) throw new Error('Kayıt açılamadı.');
                return (await response.json()) as Detail;
            })
            .then((d) => {
                const { id: _recordId, ...fields } = d.identity as Identity & { id: number };
                setIdentity({
                    ...fields,
                    tc_identity: d.identity.tc_identity ?? '',
                    full_name: d.identity.full_name ?? '',
                    registered_on: d.identity.registered_on ?? '',
                    admitted_on: d.identity.admitted_on ?? '',
                });
                setPlacement(d.placement.record);
                setValues(
                    Object.fromEntries(
                        Object.entries(d.values).filter(
                            ([key]) =>
                                (key !== 'huk_risk' || options.riskWritable) &&
                                (key !== 'infaz_dogum_tarihi' || options.birthWritable),
                        ),
                    ),
                );
                setCaseRevision(d.caseRevision);
                setDetail(d);
                setLoaded(true);
            })
            .catch((e) => {
                if (e.name !== 'AbortError') setErrors({ operation: [e.message] });
            })
            .finally(() => {
                if (!abort.signal.aborted) setBusy(false);
            });
        return () => abort.abort();
    }, [id, baseUrl, attempt]);
    useEffect(() => {
        if (loaded && !busy && !initialFocus.current) {
            dialog.current?.querySelector<HTMLInputElement>('#home-tc_identity')?.focus();
            initialFocus.current = true;
        }
    }, [loaded, busy]);
    const catalog = detail?.placement ?? options;
    async function save() {
        if (!canWrite || requestLock.current || busy || childBusy || conflict || !loaded) return;
        requestLock.current = true;
        setBusy(true);
        setErrors({});
        try {
            const result = await writeJson(
                baseUrl + (id === null ? '' : '/' + id),
                {
                    identity,
                    placement,
                    values,
                    caseRevision,
                    ...(options.birthWritable ? { sentenceRevision: detail?.sentenceRevision ?? 0 } : {}),
                },
                id === null ? 'POST' : 'PUT',
            );
            if (!result.ok) {
                setConflict(id !== null && result.status === 409);
                setErrors(
                    (result.data.errors as Record<string, string[]>) ?? {
                        operation: [String(result.data.message ?? 'Kaydedilemedi.')],
                    },
                );
                return;
            }
            notify(id === null ? 'Yeni kayıt eklendi.' : 'Kayıt güncellendi.');
            onClose();
            router.reload();
        } catch (e) {
            setErrors({ operation: [e instanceof Error ? e.message : 'Kaydedilemedi.'] });
        } finally {
            requestLock.current = false;
            setBusy(false);
        }
    }
    async function deleteRecord(stage: 'preview' | 'apply') {
        if (!canWrite || !batchUrl || id === null || requestLock.current || busy || conflict || !loaded)
            return;
        requestLock.current = true;
        setBusy(true);
        setErrors({});
        try {
            const r = await writeJson(
                batchUrl,
                {
                    action: 'delete-' + stage,
                    ids: [id],
                    token: deletion?.token,
                    confirmation: deleteConfirmation || null,
                    xml_only: false,
                },
                'POST',
            );
            if (!r.ok) {
                setDeletion(null);
                throw new Error(
                    r.data.message ??
                        Object.values(r.data.errors ?? {})
                            .flat()
                            .join(' '),
                );
            }
            if (stage === 'preview') setDeletion(r.data as unknown as { token: string; count: number });
            else {
                notify('Kayıt silindi.');
                onClose();
                router.reload();
            }
        } catch (e) {
            setErrors({ operation: [e instanceof Error ? e.message : 'İşlem yapılamadı.'] });
        } finally {
            requestLock.current = false;
            setBusy(false);
        }
    }
    const locked = busy || childBusy;
    function switchMode(next: typeof mode) {
        if (locked) return;
        setMode(next);
        if (next !== 'single') setVisited((v) => ({ ...v, [next]: true }));
    }
    const modes = [
        { key: 'single', label: 'Tekli Kayıt', icon: 'fa-user-plus' },
        { key: 'bulk', label: 'Toplu İşlemler', icon: 'fa-users-gear' },
        { key: 'shortcuts', label: 'Kısayollar', icon: 'fa-bolt' },
    ] as { key: typeof mode; label: string; icon: string }[];
    const format = (s: string | null | undefined) => (s ? s.split('-').reverse().join('.') : 'Belirtilmemiş');
    return (
        <dialog
            id="cteRecordPopup"
            ref={dialog}
            className={'cte-record-modal' + (id === null ? ' is-new-record' : '')}
            aria-labelledby="record-title"
            onCancel={(e) => {
                if (e.target !== e.currentTarget) return;
                e.preventDefault();
                if (!locked) requestClose();
            }}
            onClick={(e) => {
                if (locked || e.target !== e.currentTarget) return;
                const r = e.currentTarget.getBoundingClientRect();
                if (e.clientX < r.left || e.clientX > r.right || e.clientY < r.top || e.clientY > r.bottom)
                    requestClose();
            }}
        >
            <button
                className="popup-close"
                type="button"
                aria-label="Kayıt işlem penceresini kapat"
                disabled={locked}
                onClick={requestClose}
            >
                <i className="fas fa-xmark" />
            </button>
            <header className="cte-record-modal-header">
                <span aria-hidden="true" className="cte-record-modal-header-icon">
                    <i className="fas fa-user-pen" />
                </span>
                <div>
                    <h2 id="record-title">
                        {id === null ? 'Yeni Kayıt' : identity.full_name || 'Kayıt İşlemleri'}
                    </h2>
                    <p>
                        {id === null
                            ? 'Yeni kişi kaydı için gerekli alanları doldurun.'
                            : 'Kişi bilgilerini, tarihleri ve güncel izin bilgilerini görüntüleyip düzenleyin.'}
                    </p>
                </div>
            </header>
            {id === null && (
                <nav className="cte-record-mode-tabs" role="tablist" aria-label="Kayıt yöntemi">
                    {modes.map((m, i) => (
                        <button
                            key={m.key}
                            id={'record-tab-' + m.key}
                            type="button"
                            className={'cte-record-mode-tab' + (mode === m.key ? ' is-active' : '')}
                            role="tab"
                            aria-selected={mode === m.key}
                            aria-controls={'record-panel-' + m.key}
                            tabIndex={mode === m.key ? 0 : -1}
                            disabled={locked}
                            onClick={() => switchMode(m.key)}
                            onKeyDown={(e) => {
                                if (!['ArrowLeft', 'ArrowRight', 'Home', 'End'].includes(e.key) || locked)
                                    return;
                                e.preventDefault();
                                const n =
                                    e.key === 'Home'
                                        ? 0
                                        : e.key === 'End'
                                          ? modes.length - 1
                                          : (i + (e.key === 'ArrowRight' ? 1 : -1) + modes.length) %
                                            modes.length;
                                switchMode(modes[n].key);
                                document.getElementById('record-tab-' + modes[n].key)?.focus();
                            }}
                        >
                            <i className={'fas ' + m.icon} /> {m.label}
                        </button>
                    ))}
                </nav>
            )}
            {id === null && (
                <div
                    id="record-panel-bulk"
                    role="tabpanel"
                    aria-labelledby="record-tab-bulk"
                    className="cte-record-modal-scroll"
                    hidden={mode !== 'bulk'}
                >
                    {batchUrl ? (
                        visited.bulk && (
                            <HomeBulkOperations
                                baseUrl={batchUrl}
                                canWrite={canWrite}
                                statuses={options.statuses.filter((s) => s.included_in_active_list !== false)}
                                onActivity={setChildBusy}
                            />
                        )
                    ) : (
                        <p role="status">Toplu işlemleri kullanma yetkiniz bulunmuyor.</p>
                    )}
                </div>
            )}
            {id === null && (
                <div
                    id="record-panel-shortcuts"
                    role="tabpanel"
                    aria-labelledby="record-tab-shortcuts"
                    className="cte-record-modal-scroll"
                    hidden={mode !== 'shortcuts'}
                >
                    {shortcutUrl ? (
                        visited.shortcuts && (
                            <HomeShortcuts
                                baseUrl={shortcutUrl}
                                statuses={options.statuses}
                                canWrite={canWrite}
                                onActivity={setChildBusy}
                            />
                        )
                    ) : (
                        <p role="status">Kısayolları kullanma yetkiniz bulunmuyor.</p>
                    )}
                </div>
            )}
            <div
                id="record-panel-single"
                role={id === null ? 'tabpanel' : undefined}
                aria-labelledby={id === null ? 'record-tab-single' : undefined}
                className="cte-record-modal-scroll"
                hidden={mode !== 'single'}
            >
                <form
                    onSubmit={(e) => {
                        e.preventDefault();
                        void save();
                    }}
                >
                    <div className="cte-record-modal-layout">
                        {id !== null && (
                            <aside className="cte-record-summary">
                                <div className="cte-record-photo">
                                    {photoUrl && !photoFailed ? (
                                        <img
                                            src={photoUrl}
                                            alt="Hükümlü fotoğrafı"
                                            onError={() => setPhotoFailed(true)}
                                        />
                                    ) : (
                                        <span>
                                            <i className="fas fa-user-slash" aria-hidden="true" />
                                            <br />
                                            Fotoğraf yok
                                        </span>
                                    )}
                                </div>
                                <h3 className="cte-record-person-name">
                                    {identity.full_name || 'Kişi bilgisi'}
                                </h3>
                                {detail?.dates ? (
                                    <div className="cte-record-date-grid">
                                        <div className="cte-record-info-card">
                                            <span>DS Tarihi</span>
                                            <strong>{format(detail.dates.infaz_ds_tarihi)}</strong>
                                        </div>
                                        <div className="cte-record-info-card is-release">
                                            <span>Tahliye Tarihi</span>
                                            <strong>{format(detail.dates.infaz_tahliye_tarihi)}</strong>
                                        </div>
                                    </div>
                                ) : (
                                    <p className="muted">
                                        Tahliye ve DS bilgilerini görüntüleme yetkiniz bulunmamaktadır.
                                    </p>
                                )}
                                <section className="cte-record-leave-section">
                                    <h4>İzin Bilgileri</h4>
                                    {detail?.leaves ? (
                                        detail.leaves.izin_cikis_tarihi || detail.leaves.izin_donus_tarihi ? (
                                            <div className="cte-record-leave-item">
                                                <p>
                                                    <strong>Çıkış: </strong>
                                                    {format(detail.leaves.izin_cikis_tarihi)}{' '}
                                                    {detail.leaves.izin_cikis_saati?.slice(0, 5) || '-'}
                                                </p>
                                                <p>
                                                    <strong>Dönüş: </strong>
                                                    {format(detail.leaves.izin_donus_tarihi)}{' '}
                                                    {detail.leaves.izin_donus_saati?.slice(0, 5) || '-'}
                                                </p>
                                            </div>
                                        ) : (
                                            <p className="cte-record-empty">İzin bilgisi bulunamadı.</p>
                                        )
                                    ) : (
                                        <p className="muted">
                                            İzin bilgilerini görüntüleme yetkiniz bulunmamaktadır.
                                        </p>
                                    )}
                                </section>
                            </aside>
                        )}
                        <section className="cte-record-edit-pane">
                            <h3 className="cte-record-edit-heading">
                                <i className="fas fa-pen-to-square" aria-hidden="true" /> Düzenleme Bilgileri
                            </h3>
                            {busy && !loaded && <p role="status">Kayıt yükleniyor…</p>}
                            <fieldset
                                disabled={!canWrite || locked || conflict || !loaded}
                                className="cte-record-form-grid"
                            >
                                {(
                                    [
                                        { key: 'tc_identity', label: 'TC Kimlik No', type: 'text' },
                                        { key: 'full_name', label: 'Adı Soyadı', type: 'text' },
                                        { key: 'registered_on', label: 'Kayıt Tarihi', type: 'date' },
                                    ] as const
                                ).map((f) => (
                                    <div className="form-group" key={f.key}>
                                        <label htmlFor={'home-' + f.key}>{f.label}</label>
                                        <input
                                            id={'home-' + f.key}
                                            type={f.type}
                                            required={f.key === 'full_name'}
                                            maxLength={f.key === 'tc_identity' ? 11 : 255}
                                            inputMode={f.key === 'tc_identity' ? 'numeric' : undefined}
                                            pattern={f.key === 'tc_identity' ? '[0-9]{11}' : undefined}
                                            value={identity[f.key]}
                                            onInput={(e) =>
                                                setIdentity({ ...identity, [f.key]: e.currentTarget.value })
                                            }
                                            onChange={(e) =>
                                                setIdentity({ ...identity, [f.key]: e.target.value })
                                            }
                                        />
                                    </div>
                                ))}
                                {(
                                    [
                                        {
                                            key: 'block_id',
                                            label: 'Blok',
                                            items: catalog.blocks.map((b) => ({ id: b.id, label: b.name })),
                                        },
                                        {
                                            key: 'room_id',
                                            label: 'Koğuş',
                                            items: catalog.rooms
                                                .filter((r) => r.block_id === placement.block_id)
                                                .map((r) => ({ id: r.id, label: r.name })),
                                        },
                                        {
                                            key: 'bed_id',
                                            label: 'Ranza',
                                            items: catalog.beds.filter(
                                                (b) =>
                                                    b.room_id === placement.room_id &&
                                                    (b.available || b.id === placement.bed_id),
                                            ),
                                        },
                                    ] as const
                                ).map((f) => (
                                    <div className="form-group" key={f.key}>
                                        <label htmlFor={'home-' + f.key}>{f.label}</label>
                                        <select
                                            id={'home-' + f.key}
                                            disabled={
                                                f.key === 'room_id'
                                                    ? !placement.block_id
                                                    : f.key === 'bed_id'
                                                      ? !placement.room_id
                                                      : false
                                            }
                                            value={placement[f.key] ?? ''}
                                            onChange={(e) => {
                                                const nextValue = e.target.value
                                                    ? Number(e.target.value)
                                                    : null;
                                                const nextBlock =
                                                    f.key === 'block_id' ? nextValue : placement.block_id;
                                                const nextRoom =
                                                    f.key === 'block_id'
                                                        ? null
                                                        : f.key === 'room_id'
                                                          ? nextValue
                                                          : placement.room_id;
                                                const dataChangeId = options.statuses.find(
                                                    (s) => s.code === 'data_change',
                                                )?.id;
                                                const initialRecord = detail?.placement.record;
                                                setPlacement({
                                                    ...placement,
                                                    [f.key]: nextValue,
                                                    ...(f.key === 'block_id'
                                                        ? { room_id: null, bed_id: null }
                                                        : f.key === 'room_id'
                                                          ? { bed_id: null }
                                                          : {}),
                                                    ...(id !== null && f.key !== 'bed_id'
                                                        ? initialRecord &&
                                                          nextBlock === initialRecord.block_id &&
                                                          nextRoom === initialRecord.room_id &&
                                                          placement.status_id === dataChangeId
                                                            ? { status_id: initialRecord.status_id }
                                                            : nextValue !== placement[f.key]
                                                              ? {
                                                                    status_id:
                                                                        dataChangeId ?? placement.status_id,
                                                                }
                                                              : {}
                                                        : {}),
                                                });
                                            }}
                                        >
                                            <option value="">
                                                {f.key === 'room_id' && !placement.block_id
                                                    ? 'Önce Blok Seçin'
                                                    : f.key === 'bed_id' && !placement.room_id
                                                      ? 'Önce Koğuş Seçin'
                                                      : 'Seçin'}
                                            </option>
                                            {[...f.items]
                                                .sort((a, b) => compareNatural(a.label, b.label))
                                                .map((o) => (
                                                    <option key={o.id} value={o.id}>
                                                        {o.label}
                                                    </option>
                                                ))}
                                        </select>
                                    </div>
                                ))}
                                {[
                                    { key: 'huk_calis', label: 'Çalışma Yeri' },
                                    { key: 'huk_karar', label: 'Çalışma Kararı' },
                                ].map((f) => (
                                    <div className="form-group" key={f.key}>
                                        <label htmlFor={'home-' + f.key}>{f.label}</label>
                                        <select
                                            id={'home-' + f.key}
                                            value={values[f.key] ?? ''}
                                            onChange={(e) =>
                                                setValues({
                                                    ...values,
                                                    [f.key]: e.target.value ? Number(e.target.value) : null,
                                                })
                                            }
                                        >
                                            <option value="">Seçin</option>
                                            {options.caseOptions[f.key]
                                                ?.filter((o) => o.is_active || o.id === values[f.key])
                                                .map((o) => (
                                                    <option key={o.id} value={o.id}>
                                                        {o.label}
                                                    </option>
                                                ))}
                                        </select>
                                    </div>
                                ))}
                                <div className="form-group">
                                    <label htmlFor="home-health">Sağlık Durumu</label>
                                    <input
                                        id="home-health"
                                        maxLength={255}
                                        value={values.huk_saglik ?? ''}
                                        onChange={(e) => setValues({ ...values, huk_saglik: e.target.value })}
                                    />
                                </div>
                                {options.caseOptions.huk_risk && (
                                    <div className="form-group">
                                        <label htmlFor="home-risk">Değerlendirme</label>
                                        <select
                                            id="home-risk"
                                            disabled={!options.riskWritable}
                                            value={values.huk_risk ?? detail?.values.huk_risk ?? ''}
                                            onChange={(e) =>
                                                setValues({
                                                    ...values,
                                                    huk_risk: e.target.value ? Number(e.target.value) : null,
                                                })
                                            }
                                        >
                                            <option value="">Seçin</option>
                                            {options.caseOptions.huk_risk
                                                .filter(
                                                    (o) => o.is_active || o.id === detail?.values.huk_risk,
                                                )
                                                .sort((a, b) => compareNatural(a.label, b.label))
                                                .map((o) => (
                                                    <option key={o.id} value={o.id}>
                                                        {o.label}
                                                    </option>
                                                ))}
                                        </select>
                                    </div>
                                )}
                                <div className="form-group">
                                    <label htmlFor="home-status">Durum</label>
                                    <select
                                        id="home-status"
                                        value={placement.status_id ?? ''}
                                        onChange={(e) =>
                                            setPlacement({
                                                ...placement,
                                                status_id: e.target.value ? Number(e.target.value) : null,
                                            })
                                        }
                                    >
                                        <option value="">Seçin</option>
                                        {catalog.statuses.map((s) => (
                                            <option key={s.id} value={s.id}>
                                                {s.label}
                                            </option>
                                        ))}
                                    </select>
                                </div>
                                <div className="form-group is-full-width">
                                    <label htmlFor="home-notes">Notlar</label>
                                    <textarea
                                        id="home-notes"
                                        maxLength={10000}
                                        value={values.huk_notlar ?? ''}
                                        onChange={(e) => setValues({ ...values, huk_notlar: e.target.value })}
                                    />
                                </div>
                                {privateWritable && (
                                    <div className="form-group is-full-width">
                                        <button
                                            type="button"
                                            className="cte-record-room-button"
                                            disabled={id === null}
                                            title={
                                                id === null
                                                    ? 'Özel not eklemek için önce kişiyi kaydedin.'
                                                    : undefined
                                            }
                                            onClick={() => setPrivateOpen(true)}
                                        >
                                            <i className="fas fa-lock" /> Özel Not Ekle
                                        </button>
                                    </div>
                                )}
                            </fieldset>
                            {!loaded && !busy && (
                                <button
                                    type="button"
                                    className="button"
                                    onClick={() => setAttempt((a) => a + 1)}
                                >
                                    Tekrar dene
                                </button>
                            )}
                            {Object.entries(errors).map(([key, messages]) => (
                                <p className="form-error" role="alert" key={key}>
                                    {messages.join(' ')}
                                </p>
                            ))}
                            {conflict && (
                                <p className="form-error">
                                    Kayıt başka bir işlemde değişti. Girdileriniz korunuyor; güncel kaydı
                                    başka bir sekmede karşılaştırın.
                                </p>
                            )}
                            <footer className="cte-record-modal-actions">
                                <button
                                    type="button"
                                    className="cte-record-room-button"
                                    disabled={locked}
                                    onClick={() => setRoomsOpen(true)}
                                >
                                    <i className="fas fa-bed" /> Boş Koğuşları Göster
                                </button>
                                <div className="cte-record-modal-actions-right">
                                    {canWrite && id !== null && batchUrl && (
                                        <button
                                            type="button"
                                            className="button button--danger"
                                            disabled={locked || conflict || !loaded}
                                            onClick={() => void deleteRecord('preview')}
                                        >
                                            <i className="fas fa-trash" /> Sil
                                        </button>
                                    )}
                                    {canWrite && (
                                        <button
                                            className="cte-record-save-button button button--primary"
                                            type="submit"
                                            disabled={!canWrite || locked || conflict || !loaded}
                                        >
                                            {busy ? 'Kaydediliyor…' : 'Kaydet'}
                                        </button>
                                    )}
                                </div>
                            </footer>
                        </section>
                    </div>
                </form>
            </div>
            {confirmClose && (
                <PortalDialog title="Kaydedilmemiş değişiklikler var" onClose={() => setConfirmClose(false)}>
                    <section className="editor-panel">
                        <p>Pencereyi kapatırsanız bu değişiklikler kaybolacak.</p>
                        <div className="row-actions">
                            <button
                                type="button"
                                className="button button--primary"
                                autoFocus
                                onClick={() => setConfirmClose(false)}
                            >
                                Düzenlemeye devam et
                            </button>
                            <button type="button" className="button button--danger" onClick={onClose}>
                                Değişiklikleri bırak
                            </button>
                        </div>
                    </section>
                </PortalDialog>
            )}
            {deletion && (
                <PortalDialog
                    id="homeRecordDeleteConfirmation"
                    title="Silme Onayı"
                    busy={busy}
                    onClose={() => {
                        setDeletion(null);
                        setDeleteConfirmation('');
                    }}
                >
                    <p>Bu işlem kayıt ve bağlı kişi bilgilerini kalıcı siler.</p>
                    <label className="form-group">
                        Onay metni
                        <input
                            value={deleteConfirmation}
                            disabled={busy}
                            placeholder="KALICI SİL"
                            onChange={(e) => setDeleteConfirmation(e.target.value)}
                        />
                    </label>
                    <div className="toolbar">
                        <button
                            type="button"
                            className="button button--danger"
                            disabled={busy || deleteConfirmation !== 'KALICI SİL'}
                            onClick={() => void deleteRecord('apply')}
                        >
                            Silmeyi Onayla
                        </button>
                        <button
                            type="button"
                            className="button"
                            disabled={busy}
                            onClick={() => {
                                setDeletion(null);
                                setDeleteConfirmation('');
                            }}
                        >
                            Vazgeç
                        </button>
                    </div>
                </PortalDialog>
            )}
            {roomsOpen && (
                <PortalDialog
                    id="emptyRoomsPopup"
                    title="Boş Koğuşlar ve Ranzalar"
                    className="empty-rooms-dialog"
                    onClose={() => setRoomsOpen(false)}
                >
                    <EmptyRoomPicker
                        catalog={options}
                        occupancy={occupancy}
                        canSelect={canWrite && !locked && !conflict && loaded}
                        onSelect={(block, room, bed) => {
                            const dataChangeId = options.statuses.find((s) => s.code === 'data_change')?.id;
                            const initialRecord = detail?.placement.record;
                            setPlacement({
                                ...placement,
                                block_id: block,
                                room_id: room,
                                bed_id: bed,
                                ...(id !== null
                                    ? initialRecord &&
                                      block === initialRecord.block_id &&
                                      room === initialRecord.room_id &&
                                      placement.status_id === dataChangeId
                                        ? { status_id: initialRecord.status_id }
                                        : block !== placement.block_id || room !== placement.room_id
                                          ? { status_id: dataChangeId ?? placement.status_id }
                                          : {}
                                    : {}),
                            });
                            setRoomsOpen(false);
                            notify('Koğuş ve ilk boş ranza seçildi. Kaydet ile uygulayın.');
                        }}
                    />
                </PortalDialog>
            )}
            {privateOpen && id !== null && (
                <PrivateNoteModal
                    person={id}
                    name={identity.full_name}
                    onActivity={setChildBusy}
                    onClose={() => {
                        setChildBusy(false);
                        setPrivateOpen(false);
                    }}
                />
            )}
            {conflict && id !== null && (
                <ConflictReview
                    base={baseline.current}
                    draft={flat(identity, placement, values)}
                    labels={{
                        'identity:full_name': 'Adı Soyadı',
                        'identity:tc_identity': 'TC Kimlik',
                        'identity:registered_on': 'Kayıt tarihi',
                        'identity:admitted_on': 'Kuruma giriş',
                        'placement:status_id': 'Durum',
                        'placement:block_id': 'Blok',
                        'placement:room_id': 'Koğuş',
                        'placement:bed_id': 'Ranza',
                        'value:huk_calis': 'Çalışma yeri',
                        'value:huk_karar': 'Çalışma kararı',
                        'value:huk_saglik': 'Sağlık',
                        'value:huk_notlar': 'Notlar',
                        'value:huk_risk': 'Değerlendirme',
                        'value:infaz_dogum_tarihi': 'Doğum tarihi',
                    }}
                    load={async (signal) => {
                        const latest = await workspaceRequest<Detail>(baseUrl + '/' + id, undefined, signal);
                        if (latest.canWrite === false)
                            throw new Error('Yazma yetkiniz değişti. Girdileriniz korunuyor.');
                        latest.values = Object.fromEntries(
                            Object.entries(latest.values).filter(([key]) =>
                                latest.writableFields
                                    ? latest.writableFields.includes(key)
                                    : (key !== 'huk_risk' || options.riskWritable) &&
                                      (key !== 'infaz_dogum_tarihi' || options.birthWritable),
                            ),
                        );
                        return {
                            values: flat(latest.identity, latest.placement.record, latest.values),
                            metadata: latest,
                        };
                    }}
                    apply={(merged, latest) => {
                        const { id: _id, ...freshIdentity } = latest.identity as Identity & { id: number };
                        baseline.current = flat(freshIdentity, latest.placement.record, latest.values);
                        setIdentity({
                            ...freshIdentity,
                            ...Object.fromEntries(
                                Object.entries(merged)
                                    .filter(([key]) => key.startsWith('identity:'))
                                    .map(([key, value]) => [key.slice(9), value]),
                            ),
                        } as Identity);
                        setPlacement({
                            ...latest.placement.record,
                            ...Object.fromEntries(
                                Object.entries(merged)
                                    .filter(([key]) => key.startsWith('placement:'))
                                    .map(([key, value]) => [key.slice(10), value]),
                            ),
                        } as Placement);
                        setValues(
                            Object.fromEntries(
                                Object.entries(merged)
                                    .filter(([key]) => key.startsWith('value:'))
                                    .map(([key, value]) => [key.slice(6), value]),
                            ) as Values,
                        );
                        setCaseRevision(latest.caseRevision);
                        setDetail(latest);
                        setConflict(false);
                        setErrors({});
                    }}
                />
            )}
        </dialog>
    );
}
