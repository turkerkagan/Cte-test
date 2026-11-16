import ConflictReview from '../components/ConflictReview';
import { editableValues, workflowSnapshot } from '../lib/workflowConflict';
import { useEffect, useRef, useState } from 'react';
import { router } from '@inertiajs/react';
import type { WorkflowEditorProps } from './WorkflowEditor';
import { writeJson } from '../lib/workflow';
import { notify } from '../components/Toast';
export default function SentenceEditor(p: WorkflowEditorProps & { onSaved?: () => void }) {
    const [photoFailed, setPhotoFailed] = useState(false);
    const [values, setValues] = useState(p.values);
    const [admitted, setAdmitted] = useState(p.person.admitted_on ?? '');
    const [revision, setRevision] = useState(p.revision);
    const [personRevision, setPersonRevision] = useState(p.person.revision);
    const baseValues = useRef({
        ...editableValues(p, p.values),
        identity_admitted_on: p.person.admitted_on ?? null,
    });
    const [busy, setBusy] = useState(false);
    const saving = useRef(false);
    const [error, setError] = useState('');
    const [conflict, setConflict] = useState(false);
    const [calc, setCalc] = useState<{
        remaining: string;
        age: number | null;
        uyapValid: boolean;
        mean: number;
    } | null>(null);
    useEffect(() => p.onActivity?.(busy, conflict), [busy, conflict, p.onActivity]);
    async function save() {
        if (saving.current || busy || conflict || !p.canWrite) return;
        saving.current = true;
        setBusy(true);
        setError('');
        try {
            const r = await writeJson(p.submitUrl, {
                revision,
                values: editableValues(p, values),
                identity: { revision: personRevision, admitted_on: admitted || null },
            });
            if (!r.ok) {
                setConflict(r.status === 409);
                throw new Error(
                    Object.values(r.data.errors ?? {})
                        .flat()
                        .join(' ') ||
                        r.data.message ||
                        'Kaydedilemedi.',
                );
            }
            notify('İnfaz kaydı güncellendi.');
            if (p.onSaved) p.onSaved();
            else router.visit(p.baseUrl);
        } catch (e) {
            setError(e instanceof Error ? e.message : 'Kaydedilemedi.');
        } finally {
            saving.current = false;
            setBusy(false);
        }
    }
    async function calculate() {
        setError('');
        try {
            const r = await writeJson(
                p.baseUrl + '/hesapla',
                {
                    birth: values.infaz_dogum_tarihi,
                    ds: values.infaz_ds_tarihi,
                    release: values.infaz_tahliye_tarihi,
                    uyap: values.infaz_uyap_no,
                    year: values.infaz_ceza_yil,
                    month: values.infaz_ceza_ay,
                    day: values.infaz_ceza_gun,
                },
                'POST',
            );
            if (!r.ok)
                throw new Error(
                    Object.values(r.data.errors ?? {})
                        .flat()
                        .join(' ') ||
                        r.data.message ||
                        'Hesaplama yapılamadı.',
                );
            setCalc(r.data as unknown as typeof calc);
        } catch (e) {
            setError(e instanceof Error ? e.message : 'Hesaplama yapılamadı.');
        }
    }
    function field(key: string) {
        const f = p.fields[key];
        if (!f) return null;
        return (
            <div className="form-group" key={key}>
                <label htmlFor={key}>{f.label}</label>
                {f.type === 'option' ? (
                    <select
                        id={key}
                        disabled={f.readonly}
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
                                </option>
                            ))}
                    </select>
                ) : f.type === 'longtext' ? (
                    <textarea
                        id={key}
                        disabled={f.readonly}
                        maxLength={10000}
                        value={values[key] ?? ''}
                        onChange={(e) => setValues({ ...values, [key]: e.target.value || null })}
                    />
                ) : (
                    <input
                        id={key}
                        disabled={f.readonly}
                        type={f.type === 'date' ? 'date' : f.type === 'integer' ? 'number' : 'text'}
                        value={values[key] ?? ''}
                        maxLength={255}
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
    }
    const groups = [
        ['infaz_dogum_tarihi', 'infaz_uyap_no'],
        ['infaz_ds_tarihi', 'infaz_tahliye_tarihi', 'infaz_durumu'],
        ['infaz_ceza_yil', 'infaz_ceza_ay', 'infaz_ceza_gun'],
        ['infaz_ozel_kurul', 'infaz_ozel_kurul_durumu', 'infaz_ozel_kurul_tarihi', 'infaz_gelis_nedeni'],
        ['infaz_ilave', 'infaz_satir_rengi'],
        ['infaz_not'],
    ];
    return (
        <form
            id={`workflow-record-form-${p.person.id}`}
            onSubmit={(e) => {
                e.preventDefault();
                void save();
            }}
            className="cte-record-modal-layout cte-infaz-modal-layout"
        >
            <aside className="cte-record-summary cte-infaz-summary">
                <div className="cte-record-photo cte-infaz-photo">
                    {p.photoUrl && !photoFailed ? (
                        <img src={p.photoUrl} alt="Hükümlü fotoğrafı" onError={() => setPhotoFailed(true)} />
                    ) : (
                        <i className="fas fa-user" />
                    )}
                </div>
                <h3 className="cte-record-person-name">{p.person.full_name}</h3>
                <div className="cte-record-info-card">
                    <span>TC Kimlik</span>
                    <strong>{p.person.tc_identity || 'Belirtilmemiş'}</strong>
                </div>
                <div className="cte-record-info-card">
                    <span>Durumu</span>
                    <strong>{p.person.status || 'Belirtilmemiş'}</strong>
                </div>
            </aside>
            <section className="cte-record-edit-pane">
                <fieldset
                    disabled={!p.canWrite || busy || conflict}
                    className="cte-record-form-grid cte-infaz-grid"
                >
                    {groups.map((keys, i) => (
                        <div
                            className={
                                'cte-infaz-row' + (i === groups.length - 1 ? ' cte-infaz-row-full' : '')
                            }
                            key={i}
                        >
                            {i === 0 && (
                                <>
                                    <div className="form-group">
                                        <label htmlFor="sentence-tc">TC Kimlik</label>
                                        <input id="sentence-tc" readOnly value={p.person.tc_identity ?? ''} />
                                    </div>
                                    <div className="form-group">
                                        <label htmlFor="sentence-name">Adı Soyadı</label>
                                        <input id="sentence-name" readOnly value={p.person.full_name} />
                                    </div>
                                </>
                            )}
                            {i === 1 && (
                                <div className="form-group">
                                    <label htmlFor="sentence-admitted">Cezaevine Giriş</label>
                                    <input
                                        id="sentence-admitted"
                                        type="date"
                                        value={admitted}
                                        onInput={(e) => setAdmitted(e.currentTarget.value)}
                                        onChange={(e) => setAdmitted(e.target.value)}
                                    />
                                </div>
                            )}
                            {keys.map(field)}
                        </div>
                    ))}
                    {Object.keys(p.fields)
                        .filter((k) => !groups.flat().includes(k))
                        .map(field)}
                </fieldset>
                <section className="sentence-tools">
                    <button className="button" type="button" onClick={() => void calculate()}>
                        <i className="fas fa-calculator" /> Kalan Gün / Yaş / UYAP Kontrol
                    </button>
                    {calc && (
                        <dl>
                            <div>
                                <dt>Kalan Süre</dt>
                                <dd>{calc.remaining}</dd>
                            </div>
                            <div>
                                <dt>Yaş</dt>
                                <dd>{calc.age ?? 'Belirtilmemiş'}</dd>
                            </div>
                            <div>
                                <dt>UYAP No</dt>
                                <dd>{calc.uyapValid ? 'Geçerli' : 'Kontrol edin'}</dd>
                            </div>
                            <div>
                                <dt>Ceza Ortalaması (Yıl)</dt>
                                <dd>{calc.mean}</dd>
                            </div>
                        </dl>
                    )}
                </section>
                {error && (
                    <p className="form-error" role="alert">
                        {error}
                    </p>
                )}
                {conflict && (
                    <p className="form-error">
                        Kayıt değişti. Girdileriniz korunuyor; güncel kayıtla karşılaştırın.
                    </p>
                )}
                {conflict && (
                    <ConflictReview
                        base={baseValues.current}
                        draft={{ ...editableValues(p, values), identity_admitted_on: admitted || null }}
                        labels={{
                            ...Object.fromEntries(
                                Object.entries(p.fields).map(([key, field]) => [key, field.label]),
                            ),
                            identity_admitted_on: 'Kuruma giriş',
                        }}
                        load={async (signal) => {
                            const latest = await workflowSnapshot(p, signal);
                            return {
                                values: {
                                    ...editableValues(latest, latest.values),
                                    identity_admitted_on: latest.person.admitted_on ?? null,
                                },
                                metadata: latest,
                            };
                        }}
                        apply={(merged, latest) => {
                            const { identity_admitted_on, ...fields } = merged;
                            baseValues.current = {
                                ...editableValues(latest, latest.values),
                                identity_admitted_on: latest.person.admitted_on ?? null,
                            };
                            setValues({ ...latest.values, ...fields } as typeof values);
                            setAdmitted(String(identity_admitted_on ?? ''));
                            setRevision(latest.revision);
                            setPersonRevision(latest.person.revision);
                            setConflict(false);
                            setError('');
                        }}
                    />
                )}
                {!p.onSaved && (
                    <footer className="cte-record-modal-actions">
                        {p.canWrite && (
                            <button className="button button--primary" disabled={busy || conflict}>
                                {busy ? 'Kaydediliyor…' : 'Kaydet'}
                            </button>
                        )}
                    </footer>
                )}
            </section>
        </form>
    );
}
