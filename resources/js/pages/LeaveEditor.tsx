import ConflictReview from '../components/ConflictReview';
import { editableValues, workflowSnapshot } from '../lib/workflowConflict';
import { useEffect, useRef, useState } from 'react';
import { router } from '@inertiajs/react';
import type { WorkflowEditorProps } from './WorkflowEditor';
import { writeJson, type Values } from '../lib/workflow';
import { notify } from '../components/Toast';
export default function LeaveEditor(p: WorkflowEditorProps & { onSaved?: () => void }) {
    const [values, setValues] = useState<Values>({
        ...p.values,
        izin_turu:
            p.values.izin_turu ??
            p.options.izin_turu?.find((o) => o.semantic === 'ÖZEL İZİN' && o.is_active)?.id ??
            null,
        izin_cikis_saati: p.values.izin_cikis_saati ?? '14:00',
        izin_donus_saati: p.values.izin_donus_saati ?? '14:00',
        izin_gun_sayisi: p.values.izin_gun_sayisi ?? 11,
    });
    const [revision, setRevision] = useState(p.revision);
    const baseValues = useRef(editableValues(p, p.values));
    const [busy, setBusy] = useState(false);
    const saving = useRef(false);
    const [conflict, setConflict] = useState(false);
    const [error, setError] = useState('');
    useEffect(() => p.onActivity?.(busy, conflict), [busy, conflict, p.onActivity]);
    async function save() {
        if (saving.current || busy || conflict || !p.canWrite) return;
        saving.current = true;
        setBusy(true);
        setError('');
        try {
            const r = await writeJson(p.submitUrl, { revision, values: editableValues(p, values) });
            if (!r.ok) {
                setConflict(r.status === 409);
                throw new Error(
                    Object.values(r.data.errors ?? {})
                        .flat()
                        .join(' ') ||
                        r.data.message ||
                        'İzin kaydedilemedi.',
                );
            }
            notify('İzin kaydı güncellendi.');
            if (p.onSaved) p.onSaved();
            else router.visit(p.baseUrl);
        } catch (e) {
            setError(e instanceof Error ? e.message : 'İzin kaydedilemedi.');
        } finally {
            saving.current = false;
            setBusy(false);
        }
    }
    return (
        <div className="cte-record-modal-layout">
            {!p.onSaved && (
                <aside className="cte-record-summary">
                    <div className="cte-record-photo">
                        <i className="fas fa-user" />
                    </div>
                    <h3 className="cte-record-person-name">{p.person.full_name}</h3>
                    <div className="cte-record-info-card">
                        <span>TC Kimlik</span>
                        <strong>{p.person.tc_identity || 'Belirtilmemiş'}</strong>
                    </div>
                    <div className="cte-record-info-card">
                        <span>Dönüş Kontrol Saati</span>
                        <strong>14:00</strong>
                    </div>
                </aside>
            )}
            <section className="cte-record-edit-pane">
                <form
                    id={`workflow-record-form-${p.person.id}`}
                    onSubmit={(e) => {
                        e.preventDefault();
                        void save();
                    }}
                >
                    <fieldset className="cte-record-form-grid" disabled={!p.canWrite || busy || conflict}>
                        <div className="form-group">
                            <label htmlFor="leave-person-tc">TC Kimlik No</label>
                            <input id="leave-person-tc" readOnly value={p.person.tc_identity ?? ''} />
                        </div>
                        <div className="form-group leave-field-wide">
                            <label htmlFor="leave-person-name">Hükümlü Adı Soyadı</label>
                            <input id="leave-person-name" readOnly value={p.person.full_name} />
                        </div>
                        {Object.entries(p.fields)
                            .sort(
                                ([a], [b]) =>
                                    [
                                        'izin_turu',
                                        'izin_gun_sayisi',
                                        'izin_cikis_tarihi',
                                        'izin_cikis_saati',
                                        'izin_donus_tarihi',
                                        'izin_donus_saati',
                                    ].indexOf(a) -
                                    [
                                        'izin_turu',
                                        'izin_gun_sayisi',
                                        'izin_cikis_tarihi',
                                        'izin_cikis_saati',
                                        'izin_donus_tarihi',
                                        'izin_donus_saati',
                                    ].indexOf(b),
                            )
                            .map(([key, f]) => (
                                <div
                                    className={
                                        'form-group' +
                                        (f.type === 'longtext'
                                            ? ' is-full-width'
                                            : [
                                                    'izin_turu',
                                                    'izin_cikis_tarihi',
                                                    'izin_donus_tarihi',
                                                ].includes(key)
                                              ? ' leave-field-wide'
                                              : '')
                                    }
                                    key={key}
                                >
                                    <label htmlFor={key}>{f.label}</label>
                                    {f.type === 'option' ? (
                                        <select
                                            id={key}
                                            disabled={f.readonly}
                                            value={values[key] ?? ''}
                                            onChange={(e) =>
                                                setValues({
                                                    ...values,
                                                    [key]: e.target.value ? Number(e.target.value) : null,
                                                })
                                            }
                                        >
                                            <option value="">Belirtilmedi</option>
                                            {p.options[key]
                                                ?.filter((o) => o.is_active || o.id === p.values[key])
                                                .map((o) => (
                                                    <option key={o.id} value={o.id}>
                                                        {o.label}
                                                    </option>
                                                ))}
                                        </select>
                                    ) : f.type === 'longtext' ? (
                                        <textarea
                                            id={key}
                                            disabled={f.readonly}
                                            maxLength={10000}
                                            value={values[key] ?? ''}
                                            onChange={(e) =>
                                                setValues({ ...values, [key]: e.target.value || null })
                                            }
                                        />
                                    ) : (
                                        <input
                                            id={key}
                                            disabled={f.readonly}
                                            type={
                                                f.type === 'date'
                                                    ? 'date'
                                                    : f.type === 'time'
                                                      ? 'time'
                                                      : f.type === 'integer'
                                                        ? 'number'
                                                        : 'text'
                                            }
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
                            ))}
                    </fieldset>
                    <p className="muted">İzinden dönüş kontrolü saat 14:00'e göre yapılır.</p>
                    {error && (
                        <p className="form-error" role="alert">
                            {error}
                        </p>
                    )}
                    {conflict && (
                        <ConflictReview
                            base={baseValues.current}
                            draft={editableValues(p, values)}
                            labels={Object.fromEntries(
                                Object.entries(p.fields).map(([key, field]) => [key, field.label]),
                            )}
                            load={async (signal) => {
                                const latest = await workflowSnapshot(p, signal);
                                return { values: editableValues(latest, latest.values), metadata: latest };
                            }}
                            apply={(merged, latest) => {
                                baseValues.current = editableValues(latest, latest.values);
                                setValues({ ...latest.values, ...merged } as Values);
                                setRevision(latest.revision);
                                setConflict(false);
                                setError('');
                            }}
                        />
                    )}
                    {p.canWrite && !p.onSaved && (
                        <footer className="cte-record-modal-actions">
                            <button className="button button--primary" disabled={busy || conflict}>
                                {busy ? 'Kaydediliyor…' : 'Kaydet'}
                            </button>
                        </footer>
                    )}
                </form>
            </section>
        </div>
    );
}
