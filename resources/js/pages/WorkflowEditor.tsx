import { portalFetch } from '../lib/http';
import ConflictReview from '../components/ConflictReview';
import { editableValues, workflowSnapshot } from '../lib/workflowConflict';
import type { RecordValues } from '../lib/recordConflict';
import { Head, Link, router } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import LeaveEditor from './LeaveEditor';
import SentenceEditor from './SentenceEditor';
import { notify } from '../components/Toast';
import PortalDialog from '../components/PortalDialog';
import EmptyRoomPicker from '../components/EmptyRoomPicker';
import PersonNotes from '../components/PersonNotes';
import AppShell from '../components/AppShell';
import { ds, writeJson, type Fields, type Option, type Values } from '../lib/workflow';
type Placement = {
    record: {
        status_id: number | null;
        block_id: number | null;
        room_id: number | null;
        bed_id: number | null;
        revision: number;
        status_revision: number;
    };
    statuses: { id: number; label: string; is_active: boolean }[];
    blocks: { id: number; name: string; is_active: boolean }[];
    rooms: {
        id: number;
        block_id: number;
        name: string;
        is_active: boolean;
        capacity: number;
        bed_count: number;
        present: number;
    }[];
    beds: {
        id: number;
        room_id: number;
        label: string;
        is_active: boolean;
        available: boolean;
        is_empty?: boolean;
    }[];
};
export type WorkflowEditorProps = {
    applicationName: string;
    navigation?: { label: string; url: string }[];
    logoutUrl: string;
    baseUrl: string;
    workflow: string;
    title: string;
    submitUrl: string;
    person: {
        status?: string | null;
        id: number;
        full_name: string;
        tc_identity: string | null;
        admitted_on: string | null;
        registered_on: string | null;
        revision: number;
    };
    revision: number;
    fields: Fields;
    values: Values;
    options: Record<string, Option[]>;
    canWrite: boolean;
    placement: Placement | null;
    photoRevision: number;
    onActivity?: (busy: boolean, conflict: boolean) => void;
    photoUrl?: string | null;
};
function Photo({
    person,
    writable,
    revision,
    url,
    onActivity,
}: {
    person: number;
    writable: boolean;
    revision: number;
    url: string;
    onActivity?: (busy: boolean) => void;
}) {
    const [version, setVersion] = useState(revision);
    const [visible, setVisible] = useState(revision > 0);
    const [error, setError] = useState('');
    const [busy, setBusy] = useState(false);
    const [stream, setStream] = useState<MediaStream | null>(null);
    const video = useRef<HTMLVideoElement>(null);
    useEffect(() => {
        if (video.current) video.current.srcObject = stream;
        return () => stream?.getTracks().forEach((t) => t.stop());
    }, [stream]);
    const photoLock = useRef(false);
    const [retryFile, setRetryFile] = useState<File | null>(null);
    useEffect(() => onActivity?.(busy), [busy, onActivity]);
    async function change(file: File | null) {
        if (!writable || photoLock.current) return;
        photoLock.current = true;
        setBusy(true);
        setError('');
        try {
            const data = new FormData();
            data.set('revision', String(version));
            if (file) data.set('photo', file);
            const response = await portalFetch(url, {
                method: file ? 'POST' : 'DELETE',
                credentials: 'same-origin',
                headers: {
                    Accept: 'application/json',
                    ...(!file ? { 'Content-Type': 'application/json' } : {}),
                },
                body: file ? data : JSON.stringify({ revision: version }),
            });
            if (!response.headers.get('content-type')?.includes('application/json'))
                throw new Error('Oturumu kontrol edin.');
            const result = await response.json();
            if (!response.ok)
                throw new Error(
                    result.message ??
                        Object.values(result.errors ?? {})
                            .flat()
                            .join(' '),
                );
            setVersion(result.revision);
            setVisible(Boolean(file));
            setRetryFile(null);
        } catch (e) {
            setRetryFile(file);
            setError(e instanceof Error ? e.message : 'Fotoğraf kaydedilemedi.');
        } finally {
            photoLock.current = false;
            setBusy(false);
        }
    }
    async function camera() {
        try {
            setError('');
            setStream(await navigator.mediaDevices.getUserMedia({ video: true }));
        } catch {
            setError('Kamera açılamadı. Kamera iznini kontrol edin veya dosya yükleyin.');
        }
    }
    function capture() {
        if (!video.current) return;
        const canvas = document.createElement('canvas');
        canvas.width = video.current.videoWidth;
        canvas.height = video.current.videoHeight;
        if (!canvas.width) return;
        canvas.getContext('2d')?.drawImage(video.current, 0, 0);
        canvas.toBlob(
            (blob) => {
                if (blob) void change(new File([blob], `person-${person}.jpg`, { type: 'image/jpeg' }));
            },
            'image/jpeg',
            0.9,
        );
        setStream(null);
    }
    return (
        <section className="editor-panel">
            <div className="interview-photo-preview">
                {visible ? (
                    <img
                        className="person-photo"
                        src={`${url}?v=${version}`}
                        alt="Kişi fotoğrafı"
                        onError={() => setVisible(false)}
                    />
                ) : (
                    <>
                        <i className="fas fa-user" aria-hidden="true" />
                        <span>Fotoğraf Yok</span>
                    </>
                )}
            </div>
            {writable && (
                <>
                    <label className="form-field" htmlFor="person-photo-file">
                        Fotoğraf yükle (JPEG, PNG, WebP, GIF; en fazla 5 MB)
                        <input
                            id="person-photo-file"
                            disabled={busy}
                            type="file"
                            accept="image/jpeg,image/png,image/webp,image/gif"
                            onChange={(e) => {
                                const f = e.target.files?.[0];
                                if (f) void change(f);
                                e.target.value = '';
                            }}
                        />
                    </label>
                    <div className="row-actions">
                        <button
                            disabled={busy}
                            className="button"
                            type="button"
                            onClick={() => void camera()}
                        >
                            Kamerayı aç
                        </button>
                        <button
                            disabled={busy}
                            className="button button--danger"
                            type="button"
                            onClick={() => void change(null)}
                        >
                            Fotoğrafı sil
                        </button>
                    </div>
                    {stream && (
                        <>
                            <video className="person-photo" ref={video} autoPlay muted playsInline />
                            <div className="row-actions">
                                <button type="button" className="button" onClick={capture}>
                                    Çek ve kaydet
                                </button>
                                <button type="button" className="button" onClick={() => setStream(null)}>
                                    Kamerayı kapat
                                </button>
                            </div>
                        </>
                    )}
                </>
            )}
            {error && (
                <p role="alert" className="form-error">
                    {error}
                </p>
            )}
            {retryFile && (
                <button
                    type="button"
                    className="button"
                    disabled={busy}
                    onClick={() => void change(retryFile)}
                >
                    Fotoğrafı yeniden yükle
                </button>
            )}
        </section>
    );
}
function GeneralWorkflowForm(p: WorkflowEditorProps & { onSaved?: () => void }) {
    const initial = { ...p.values };
    if (p.workflow === 'ilkgorus') {
        for (const [key, semantic] of [
            ['huk_infaz', 'HAYIR'],
            ['huk_bilgi', 'EVET'],
        ])
            initial[key] ??= p.options[key]?.find((o) => o.semantic === semantic && o.is_active)?.id ?? null;
    }
    if (p.workflow === 'izin') {
        initial.izin_gun_sayisi ??= 11;
        initial.izin_cikis_saati ??= '14:00';
        initial.izin_donus_saati ??= '14:00';
        initial.izin_turu ??=
            p.options.izin_turu.find((o) => o.semantic === 'ÖZEL İZİN' && o.is_active)?.id ?? null;
    }
    const [values, setValues] = useState(initial);
    const [revision, setRevision] = useState(p.revision);
    const [identity, setIdentity] = useState({
        full_name: p.person.full_name,
        tc_identity: p.person.tc_identity,
        admitted_on: p.person.admitted_on,
    });
    const [placement, setPlacement] = useState(p.placement?.record);
    const [busy, setBusy] = useState(false);
    const [conflict, setConflict] = useState(false);
    const [error, setError] = useState('');
    const [personRevision, setPersonRevision] = useState(p.person.revision);
    function flat(source: Values, person: typeof identity, location: typeof placement): RecordValues {
        return {
            ...Object.fromEntries(
                Object.entries(editableValues(p, source)).map(([key, value]) => ['value:' + key, value]),
            ),
            ...(p.workflow === 'ilkgorus'
                ? { 'identity:full_name': person.full_name, 'identity:tc_identity': person.tc_identity }
                : {}),
            ...(location
                ? Object.fromEntries(
                      ['status_id', 'block_id', 'room_id', 'bed_id'].map((key) => [
                          'placement:' + key,
                          location[key as keyof typeof location] ?? null,
                      ]),
                  )
                : {}),
        };
    }
    const baseline = useRef(flat(values, identity, placement));
    const formLock = useRef(false);
    const [photoBusy, setPhotoBusy] = useState(false);
    const [notesBusy, setNotesBusy] = useState(false);
    const locked = busy || photoBusy || notesBusy;
    const [detailDraft, setDetailDraft] = useState<Record<string, string>>(() =>
        Object.fromEntries(
            ['huk_engelli', 'huk_aile', 'huk_tanidik'].map((key) => [
                key,
                initial[key] && initial[key] !== 'YOK' && initial[key] !== 'VAR' ? String(initial[key]) : '',
            ]),
        ),
    );
    useEffect(() => p.onActivity?.(locked, conflict), [locked, conflict, p.onActivity]);
    async function save() {
        if (formLock.current || locked || conflict || !p.canWrite) return;
        formLock.current = true;
        setBusy(true);
        setError('');
        const changedPlacement =
            p.placement && JSON.stringify(placement) !== JSON.stringify(p.placement.record);
        const payload = {
            revision,
            values: Object.fromEntries(Object.entries(values).filter(([key]) => !p.fields[key].readonly)),
            ...(p.workflow === 'ilkgorus'
                ? {
                      identity: {
                          revision: personRevision,
                          full_name: identity.full_name,
                          tc_identity: identity.tc_identity,
                      },
                  }
                : p.workflow === 'infaz'
                  ? { identity: { revision: personRevision, admitted_on: identity.admitted_on } }
                  : {}),
            ...(changedPlacement ? { placement } : {}),
        };
        try {
            const result = await writeJson(p.submitUrl, payload);
            if (!result.ok) {
                setConflict(result.status === 409);
                setError(
                    Object.values(result.data.errors ?? {})
                        .flat()
                        .join(' ') ||
                        result.data.message ||
                        'Kaydedilemedi.',
                );
                return;
            }
            notify('Kayıt güncellendi.');
            if (p.onSaved) p.onSaved();
            else router.visit(p.baseUrl);
        } catch (e) {
            setError(e instanceof Error ? e.message : 'Kaydedilemedi.');
        } finally {
            formLock.current = false;
            setBusy(false);
        }
    }
    async function quickDs(value: string) {
        if (formLock.current || locked || conflict || !p.canWrite) return;
        formLock.current = true;
        setBusy(true);
        setError('');
        try {
            const result = await writeJson(p.submitUrl, { revision, values: { huk_ds_durum: value } });
            if (!result.ok) {
                setConflict(result.status === 409);
                setError(result.data.message ?? 'DS durumu kaydedilemedi.');
                return;
            }
            setRevision(result.data.revision ?? revision);
            setValues((current) => ({ ...current, huk_ds_durum: value }));
            notify('DS durumu güncellendi.');
        } catch (e) {
            setError(e instanceof Error ? e.message : 'DS durumu kaydedilemedi.');
        } finally {
            formLock.current = false;
            setBusy(false);
        }
    }
    const [emptyRooms, setEmptyRooms] = useState(false);
    const field = (key: string) => {
        const f = p.fields[key];
        if (!f) return null;
        return (
            <div className={'form-field' + (f.type === 'longtext' ? ' is-full-width' : '')} key={key}>
                <label htmlFor={key}>
                    {p.workflow === 'ilkgorus'
                        ? ((
                              {
                                  huk_engelli: 'Engellilik Durumu',
                                  huk_aile: 'Ailede Suç Öyküsü',
                                  huk_tanidik: 'Kurumda Yakını veya Tanıdığı',
                              } as Record<string, string>
                          )[key] ?? f.label)
                        : f.label}
                </label>
                {p.workflow === 'ilkgorus' && ['huk_engelli', 'huk_aile', 'huk_tanidik'].includes(key) ? (
                    <>
                        <select
                            disabled={f.readonly}
                            id={key}
                            value={values[key] && values[key] !== 'YOK' ? 'VAR' : 'YOK'}
                            onChange={(e) =>
                                setValues({
                                    ...values,
                                    [key]: e.target.value === 'VAR' ? detailDraft[key] || 'VAR' : 'YOK',
                                })
                            }
                        >
                            <option>YOK</option>
                            <option>VAR</option>
                        </select>
                        {values[key] && values[key] !== 'YOK' && (
                            <label className="conditional-detail">
                                {
                                    (
                                        {
                                            huk_engelli: 'Engellilik Detayı',
                                            huk_aile: 'Ailede Suç Öyküsü Olan Kişi',
                                            huk_tanidik: 'Tanıdık / Yakın Bilgisi',
                                        } as Record<string, string>
                                    )[key]
                                }
                                <input
                                    disabled={f.readonly}
                                    maxLength={255}
                                    value={detailDraft[key] ?? ''}
                                    onChange={(e) => {
                                        setDetailDraft({ ...detailDraft, [key]: e.target.value });
                                        setValues({ ...values, [key]: e.target.value || 'VAR' });
                                    }}
                                />
                            </label>
                        )}
                    </>
                ) : f.type === 'option' ? (
                    <select
                        disabled={f.readonly}
                        id={key}
                        value={values[key] ?? ''}
                        onChange={(e) =>
                            setValues({ ...values, [key]: e.target.value ? Number(e.target.value) : null })
                        }
                    >
                        <option value="">Belirtilmedi</option>
                        {p.options[key]
                            ?.filter((o) => o.is_active || o.id === p.values[key])
                            .map((o) => (
                                <option key={o.id} value={o.id}>
                                    {o.label}
                                    {!o.is_active && ' (arşiv)'}
                                </option>
                            ))}
                    </select>
                ) : f.type === 'boolean' ? (
                    <select
                        id={key}
                        disabled={f.readonly}
                        value={values[key] ?? ''}
                        onChange={(e) =>
                            setValues({
                                ...values,
                                [key]: e.target.value === '' ? null : Number(e.target.value),
                            })
                        }
                    >
                        <option value="">Belirtilmedi</option>
                        <option value={1}>Evet</option>
                        <option value={0}>Hayır</option>
                    </select>
                ) : f.type === 'ds' ? (
                    <select
                        disabled={f.readonly}
                        id={key}
                        value={values[key] ?? '0'}
                        onChange={(e) => setValues({ ...values, [key]: e.target.value })}
                    >
                        {ds.map((label, i) => (
                            <option key={i} value={i}>
                                {label}
                            </option>
                        ))}
                    </select>
                ) : f.type === 'longtext' ? (
                    <textarea
                        disabled={f.readonly}
                        id={key}
                        rows={4}
                        maxLength={f.max_length ?? 10000}
                        value={values[key] ?? ''}
                        onChange={(e) => setValues({ ...values, [key]: e.target.value || null })}
                    />
                ) : (
                    <input
                        disabled={f.readonly}
                        id={key}
                        type={
                            ['integer', 'decimal'].includes(f.type)
                                ? 'number'
                                : f.type === 'datetime'
                                  ? 'datetime-local'
                                  : f.type === 'date'
                                    ? 'date'
                                    : f.type === 'time'
                                      ? 'time'
                                      : f.type === 'secret'
                                        ? 'password'
                                        : 'text'
                        }
                        step={f.type === 'time' ? 1 : f.type === 'decimal' ? 'any' : undefined}
                        maxLength={f.max_length ?? 255}
                        required={f.required}
                        value={values[key] ?? ''}
                        onInput={(e) =>
                            setValues({
                                ...values,
                                [key]:
                                    e.currentTarget.value === ''
                                        ? null
                                        : f.type === 'integer'
                                          ? Number(e.currentTarget.value)
                                          : e.currentTarget.value,
                            })
                        }
                        onChange={(e) =>
                            setValues({
                                ...values,
                                [key]:
                                    e.target.value === ''
                                        ? null
                                        : f.type === 'integer'
                                          ? Number(e.target.value)
                                          : e.target.value,
                            })
                        }
                    />
                )}
            </div>
        );
    };
    const placementPanel = p.placement && placement && (
        <fieldset className="module-field" disabled={!p.canWrite || locked || conflict}>
            <legend>Durum ve yerleşim</legend>
            <div className="user-fields">
                {(['status_id', 'block_id', 'room_id', 'bed_id'] as const)
                    .filter((key) => p.workflow !== 'ilkgorus' || key !== 'status_id')
                    .map((key) => {
                        const options =
                            key === 'status_id'
                                ? p.placement!.statuses.map((v) => ({ ...v, label: v.label }))
                                : key === 'block_id'
                                  ? p.placement!.blocks.map((v) => ({ ...v, label: v.name }))
                                  : key === 'room_id'
                                    ? p
                                          .placement!.rooms.filter((v) => v.block_id === placement.block_id)
                                          .map((v) => ({ ...v, label: v.name }))
                                    : p.placement!.beds.filter((v) => v.room_id === placement.room_id);
                        return (
                            <div className="form-field" key={key}>
                                <label htmlFor={key}>
                                    {
                                        {
                                            status_id: 'Durum',
                                            block_id: 'Blok',
                                            room_id: 'Koğuş',
                                            bed_id: 'Ranza',
                                        }[key]
                                    }
                                </label>
                                <select
                                    id={key}
                                    value={placement[key] ?? ''}
                                    onChange={(e) =>
                                        setPlacement({
                                            ...placement,
                                            [key]: e.target.value ? Number(e.target.value) : null,
                                            ...(key === 'block_id'
                                                ? { room_id: null, bed_id: null }
                                                : key === 'room_id'
                                                  ? { bed_id: null }
                                                  : {}),
                                        })
                                    }
                                >
                                    <option value="">Belirtilmedi</option>
                                    {options.map((v) => (
                                        <option
                                            key={v.id}
                                            value={v.id}
                                            disabled={
                                                ('available' in v && !v.available) ||
                                                (!v.is_active && v.id !== p.placement!.record[key])
                                            }
                                        >
                                            {v.label}
                                            {!v.is_active && ' (arşiv)'}
                                            {'available' in v && !v.available && ' (dolu)'}
                                        </option>
                                    ))}
                                </select>
                            </div>
                        );
                    })}
                <button type="button" className="button" onClick={() => setEmptyRooms(true)}>
                    Boş Koğuşlar
                </button>
            </div>
        </fieldset>
    );
    return (
        <div className="cte-record-modal-layout">
            {(!p.onSaved || p.workflow === 'ilkgorus') && (
                <aside className="cte-record-summary">
                    <div className="cte-record-info-card">
                        <span>TC Kimlik</span>
                        <strong>{p.person.tc_identity ?? 'Belirtilmemiş'}</strong>
                    </div>
                    <h3 className="cte-record-person-name">{p.person.full_name}</h3>
                    {p.workflow === 'ilkgorus' ? (
                        <Photo
                            person={p.person.id}
                            writable={p.canWrite && !busy && !notesBusy}
                            revision={p.photoRevision}
                            url={`${p.submitUrl}/foto`}
                            onActivity={setPhotoBusy}
                        />
                    ) : (
                        <div className="cte-record-photo">
                            <i className="fas fa-user" aria-hidden="true" />
                        </div>
                    )}
                    <div className="cte-record-info-card">
                        <span>Kayıt Tarihi</span>
                        <strong>{p.person.registered_on ?? 'Belirtilmemiş'}</strong>
                    </div>
                    {p.workflow === 'ilkgorus' && (
                        <div className="workflow-ds-actions">
                            {ds.map((label, i) => (
                                <button
                                    className={`button${String(values.huk_ds_durum ?? 0) === String(i) ? ' button--primary' : ''}`}
                                    disabled={!p.canWrite || locked || conflict}
                                    type="button"
                                    key={label}
                                    onClick={() => void quickDs(String(i))}
                                >
                                    {label}
                                </button>
                            ))}
                        </div>
                    )}
                </aside>
            )}
            <div className="cte-record-edit-pane">
                <section className="editor-panel">
                    <form
                        id={`workflow-record-form-${p.person.id}`}
                        className="definition-form"
                        onSubmit={(e) => {
                            e.preventDefault();
                            void save();
                        }}
                    >
                        <fieldset disabled={!p.canWrite || locked || conflict} className="module-field">
                            <legend>Kayıt bilgileri</legend>
                            <div className="user-fields">
                                {p.workflow !== 'ilkgorus' && p.workflow !== 'infaz' && (
                                    <>
                                        <div className="form-field">
                                            <label htmlFor="workflow-person-tc">TC Kimlik</label>
                                            <input
                                                id="workflow-person-tc"
                                                readOnly
                                                value={p.person.tc_identity ?? ''}
                                            />
                                        </div>
                                        <div className="form-field">
                                            <label htmlFor="workflow-person-name">Adı Soyadı</label>
                                            <input
                                                id="workflow-person-name"
                                                readOnly
                                                value={p.person.full_name}
                                            />
                                        </div>
                                    </>
                                )}
                                {p.workflow === 'infaz' && (
                                    <div className="form-field">
                                        <label htmlFor="case-admitted">Kuruma giriş tarihi</label>
                                        <input
                                            id="case-admitted"
                                            type="date"
                                            value={identity.admitted_on ?? ''}
                                            onInput={(e) =>
                                                setIdentity({
                                                    ...identity,
                                                    admitted_on: e.currentTarget.value || null,
                                                })
                                            }
                                            onChange={(e) =>
                                                setIdentity({
                                                    ...identity,
                                                    admitted_on: e.target.value || null,
                                                })
                                            }
                                        />
                                    </div>
                                )}
                                {p.workflow === 'ilkgorus' ? (
                                    <div className="interview-field-groups">
                                        <section className="interview-identity-row">
                                            <h4>Düzenleme Bilgileri</h4>
                                            <div className="user-fields">
                                                <div className="form-field">
                                                    <label htmlFor="case-tc">TC Kimlik</label>
                                                    <input
                                                        id="case-tc"
                                                        inputMode="numeric"
                                                        pattern="[0-9]{11}"
                                                        maxLength={11}
                                                        value={identity.tc_identity ?? ''}
                                                        onChange={(e) =>
                                                            setIdentity({
                                                                ...identity,
                                                                tc_identity: e.target.value || null,
                                                            })
                                                        }
                                                    />
                                                </div>
                                                <div className="form-field">
                                                    <label htmlFor="case-name">Adı Soyadı</label>
                                                    <input
                                                        id="case-name"
                                                        required
                                                        maxLength={255}
                                                        value={identity.full_name}
                                                        onChange={(e) =>
                                                            setIdentity({
                                                                ...identity,
                                                                full_name: e.target.value,
                                                            })
                                                        }
                                                    />
                                                </div>
                                                {['huk_yas', 'huk_meslek'].map(field)}
                                                {p.placement && placement && (
                                                    <div className="form-field">
                                                        <label htmlFor="status_id">Durum</label>
                                                        <select
                                                            id="status_id"
                                                            value={placement.status_id ?? ''}
                                                            onChange={(e) =>
                                                                setPlacement({
                                                                    ...placement,
                                                                    status_id: e.target.value
                                                                        ? Number(e.target.value)
                                                                        : null,
                                                                })
                                                            }
                                                        >
                                                            <option value="">Belirtilmedi</option>
                                                            {p.placement.statuses.map((status) => (
                                                                <option
                                                                    key={status.id}
                                                                    value={status.id}
                                                                    disabled={
                                                                        !status.is_active &&
                                                                        status.id !== placement.status_id
                                                                    }
                                                                >
                                                                    {status.label}
                                                                </option>
                                                            ))}
                                                        </select>
                                                    </div>
                                                )}
                                            </div>
                                        </section>
                                        {placementPanel}
                                        <section className="interview-work-row">
                                            <div className="user-fields">
                                                {['huk_calis', 'huk_notlar'].map(field)}
                                            </div>
                                        </section>
                                        <section>
                                            <h4>Sağlık ve Güvenlik Değerlendirmesi</h4>
                                            <div className="user-fields">
                                                {['huk_saglik', 'huk_engelli', 'huk_kendine_zarar'].map(
                                                    field,
                                                )}
                                            </div>
                                        </section>
                                        <section>
                                            <h4>İnfaz ve Sosyal Çevre Bilgisi</h4>
                                            <div className="user-fields">
                                                {[
                                                    'huk_infaz',
                                                    'huk_aile',
                                                    'huk_tanidik',
                                                    'huk_bilgi',
                                                    ...Object.keys(p.fields).filter(
                                                        (k) =>
                                                            ![
                                                                'huk_yas',
                                                                'huk_meslek',
                                                                'huk_calis',
                                                                'huk_notlar',
                                                                'huk_saglik',
                                                                'huk_engelli',
                                                                'huk_kendine_zarar',
                                                                'huk_infaz',
                                                                'huk_aile',
                                                                'huk_tanidik',
                                                                'huk_bilgi',
                                                                'huk_ds_durum',
                                                            ].includes(k),
                                                    ),
                                                ].map(field)}
                                            </div>
                                        </section>
                                    </div>
                                ) : (
                                    Object.keys(p.fields).map(field)
                                )}
                            </div>
                        </fieldset>
                        <p className="muted">
                            Kayıt tarihi: {p.person.registered_on ?? 'Belirtilmedi'}
                            {p.workflow === 'izin' && ' · İzin kaydı kişi durumunu değiştirmez.'}
                        </p>
                        {p.workflow !== 'ilkgorus' && placementPanel}
                        {emptyRooms && p.placement && placement && (
                            <PortalDialog
                                title="Boş Koğuş ve Ranzalar"
                                className="empty-rooms-dialog"
                                onClose={() => setEmptyRooms(false)}
                            >
                                <EmptyRoomPicker
                                    catalog={{
                                        blocks: p.placement.blocks.filter((b) => b.is_active),
                                        rooms: p.placement.rooms.filter((r) => r.is_active),
                                        beds: p.placement.beds.filter((b) => b.is_active),
                                    }}
                                    occupancy={p.placement.rooms.map((r) => ({
                                        id: r.id,
                                        present: r.present,
                                        capacity: r.capacity,
                                        available: p.placement!.beds.filter(
                                            (b) => b.room_id === r.id && b.is_empty,
                                        ).length,
                                    }))}
                                    canSelect={p.canWrite && !locked && !conflict}
                                    onSelect={(block, room, bed) => {
                                        setPlacement({
                                            ...placement,
                                            block_id: block,
                                            room_id: room,
                                            bed_id: bed,
                                        });
                                        setEmptyRooms(false);
                                        notify('Koğuş ve ilk boş ranza seçildi. Kaydet ile uygulayın.');
                                    }}
                                />
                            </PortalDialog>
                        )}
                        {error && (
                            <p className="form-error" role="alert">
                                {error}
                            </p>
                        )}
                        {conflict && (
                            <ConflictReview
                                base={baseline.current}
                                draft={flat(values, identity, placement)}
                                labels={{
                                    ...Object.fromEntries(
                                        Object.entries(p.fields).map(([key, field]) => [
                                            'value:' + key,
                                            field.label,
                                        ]),
                                    ),
                                    'identity:full_name': 'Adı Soyadı',
                                    'identity:tc_identity': 'TC Kimlik',
                                    'placement:status_id': 'Durum',
                                    'placement:block_id': 'Blok',
                                    'placement:room_id': 'Koğuş',
                                    'placement:bed_id': 'Ranza',
                                }}
                                load={async (signal) => {
                                    const latest = await workflowSnapshot(p, signal);
                                    return {
                                        values: flat(latest.values, latest.person, latest.placement?.record),
                                        metadata: latest,
                                    };
                                }}
                                apply={(merged, latest) => {
                                    const selectedValues = Object.fromEntries(
                                        Object.entries(merged)
                                            .filter(([key]) => key.startsWith('value:'))
                                            .map(([key, value]) => [key.slice(6), value]),
                                    );
                                    baseline.current = flat(
                                        latest.values,
                                        latest.person,
                                        latest.placement?.record,
                                    );
                                    setValues({ ...latest.values, ...selectedValues } as Values);
                                    setIdentity({
                                        full_name: String(
                                            merged['identity:full_name'] ?? latest.person.full_name,
                                        ),
                                        tc_identity:
                                            merged['identity:tc_identity'] === null
                                                ? null
                                                : String(
                                                      merged['identity:tc_identity'] ??
                                                          latest.person.tc_identity ??
                                                          '',
                                                  ),
                                        admitted_on: latest.person.admitted_on,
                                    });
                                    if (latest.placement)
                                        setPlacement({
                                            ...latest.placement.record,
                                            ...Object.fromEntries(
                                                Object.entries(merged)
                                                    .filter(([key]) => key.startsWith('placement:'))
                                                    .map(([key, value]) => [key.slice(10), value]),
                                            ),
                                        });
                                    setRevision(latest.revision);
                                    setPersonRevision(latest.person.revision);
                                    setConflict(false);
                                    setError('');
                                }}
                            />
                        )}
                        {p.canWrite && !p.onSaved && (
                            <button className="button button--primary" disabled={busy || conflict}>
                                {busy ? 'Kaydediliyor…' : 'Kaydet'}
                            </button>
                        )}
                    </form>
                </section>
                <details className="workflow-record-notes">
                    <summary>Kişi notları</summary>
                    <PersonNotes person={p.person.id} onActivity={setNotesBusy} />
                </details>
            </div>
        </div>
    );
}

export function WorkflowRecordForm(p: WorkflowEditorProps & { onSaved?: () => void }) {
    return p.workflow === 'infaz' ? (
        <SentenceEditor {...p} />
    ) : p.workflow === 'izin' ? (
        <LeaveEditor {...p} />
    ) : (
        <GeneralWorkflowForm {...p} />
    );
}
export default function WorkflowEditor(p: WorkflowEditorProps) {
    return (
        <AppShell
            applicationName={p.applicationName}
            navigation={p.navigation}
            pageTitle={p.title}
            toolbar={
                <Link className="button" href={p.baseUrl}>
                    Listeye dön
                </Link>
            }
            search={
                <p>
                    {p.person.full_name} · {p.person.tc_identity}
                </p>
            }
        >
            <Head title={p.title} />
            <WorkflowRecordForm {...p} />
        </AppShell>
    );
}
