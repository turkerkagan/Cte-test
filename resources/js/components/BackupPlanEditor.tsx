import { useState } from 'react';
import {
    editableBackupPlan,
    type BackupPlan,
    type BackupPlanState,
    type BackupTarget,
} from '../lib/backupPlan';

type Props = {
    state: BackupPlanState;
    busy: boolean;
    submit: (data: Record<string, unknown>) => Promise<boolean>;
};
export default function BackupPlanEditor({ state, busy, submit }: Props) {
    const [targets, setTargets] = useState<BackupTarget[]>(state.targetState.values.items);
    const [items, setItems] = useState<BackupPlan[]>(() => state.values.items.map(editableBackupPlan));
    const change = (id: string, patch: Partial<BackupPlan>) =>
        setItems((current) => current.map((item) => (item.id === id ? { ...item, ...patch } : item)));
    return (
        <section>
            <details className="editor-panel">
                <summary>Yedek hedeflerini yönet</summary>
                <p>Hedef klasörler sunucunun özel yedek alanında oluşturulur.</p>
                {targets.map((target, index) => (
                    <div className="user-fields" key={target.id}>
                        <label>
                            Hedef adı
                            <input
                                disabled={busy}
                                value={target.name}
                                maxLength={100}
                                onChange={(event) =>
                                    setTargets(
                                        targets.map((item, position) =>
                                            position === index ? { ...item, name: event.target.value } : item,
                                        ),
                                    )
                                }
                            />
                        </label>
                        <label>
                            Klasör adı
                            <input
                                disabled={busy}
                                value={target.directory}
                                pattern="[a-z0-9][a-z0-9_-]*"
                                maxLength={100}
                                onChange={(event) =>
                                    setTargets(
                                        targets.map((item, position) =>
                                            position === index
                                                ? { ...item, directory: event.target.value }
                                                : item,
                                        ),
                                    )
                                }
                            />
                        </label>
                        <label>
                            <input
                                type="checkbox"
                                disabled={busy}
                                checked={target.is_active}
                                onChange={(event) =>
                                    setTargets(
                                        targets.map((item, position) =>
                                            position === index
                                                ? { ...item, is_active: event.target.checked }
                                                : item,
                                        ),
                                    )
                                }
                            />
                            Etkin
                        </label>
                        <button
                            type="button"
                            className="button"
                            disabled={busy}
                            onClick={() => setTargets(targets.filter((item) => item.id !== target.id))}
                        >
                            Hedefi kaldır
                        </button>
                    </div>
                ))}
                <button
                    type="button"
                    className="button"
                    disabled={busy}
                    onClick={() =>
                        setTargets([
                            ...targets,
                            { id: 'target_' + crypto.randomUUID(), name: '', directory: '', is_active: true },
                        ])
                    }
                >
                    Hedef ekle
                </button>
                <button
                    type="button"
                    className="button button--primary"
                    disabled={busy}
                    onClick={() =>
                        void submit({
                            action: 'targets',
                            revision: state.targetState.revision,
                            items: targets,
                        })
                    }
                >
                    Hedefleri kaydet
                </button>
            </details>
            <h3>Yedek planları</h3>
            <p>Günlük, haftalık ve aylık planlar sunucunun zamanlayıcısıyla çalışır.</p>
            <form
                onSubmit={(event) => {
                    event.preventDefault();
                    if (!busy)
                        void submit({
                            action: 'plans',
                            revision: state.revision,
                            items: items.map(editableBackupPlan),
                        });
                }}
            >
                <div className="admin-definition-list">
                    {items.map((plan) => {
                        const saved = state.values.items.find((item) => item.id === plan.id);
                        return (
                            <fieldset key={plan.id} disabled={busy}>
                                <legend>{plan.name || 'Yeni plan'}</legend>
                                <div className="user-fields">
                                    <label>
                                        Ad{' '}
                                        <input
                                            required
                                            maxLength={100}
                                            value={plan.name}
                                            onChange={(event) =>
                                                change(plan.id, { name: event.target.value })
                                            }
                                        />
                                    </label>
                                    <label>
                                        Hedef{' '}
                                        <select
                                            value={plan.target}
                                            onChange={(event) =>
                                                change(plan.id, { target: event.target.value })
                                            }
                                        >
                                            {Object.entries(state.targets).map(([key, name]) => (
                                                <option key={key} value={key}>
                                                    {name}
                                                </option>
                                            ))}
                                        </select>
                                    </label>
                                    <label>
                                        Sıklık{' '}
                                        <select
                                            value={plan.frequency}
                                            onChange={(event) =>
                                                change(plan.id, {
                                                    frequency: event.target.value as BackupPlan['frequency'],
                                                })
                                            }
                                        >
                                            <option value="daily">Günlük</option>
                                            <option value="weekly">Haftalık</option>
                                            <option value="monthly">Aylık</option>
                                        </select>
                                    </label>
                                    <label>
                                        Saat{' '}
                                        <input
                                            type="time"
                                            required
                                            value={plan.time}
                                            onChange={(event) =>
                                                change(plan.id, { time: event.target.value })
                                            }
                                        />
                                    </label>
                                    {plan.frequency === 'weekly' && (
                                        <label>
                                            Hafta günü{' '}
                                            <select
                                                value={plan.weekday}
                                                onChange={(event) =>
                                                    change(plan.id, { weekday: Number(event.target.value) })
                                                }
                                            >
                                                {[
                                                    'Pazartesi',
                                                    'Salı',
                                                    'Çarşamba',
                                                    'Perşembe',
                                                    'Cuma',
                                                    'Cumartesi',
                                                    'Pazar',
                                                ].map((name, index) => (
                                                    <option key={name} value={index + 1}>
                                                        {name}
                                                    </option>
                                                ))}
                                            </select>
                                        </label>
                                    )}
                                    {plan.frequency === 'monthly' && (
                                        <label>
                                            Ay günü{' '}
                                            <input
                                                type="number"
                                                required
                                                min={1}
                                                max={28}
                                                value={plan.monthday}
                                                onChange={(event) =>
                                                    change(plan.id, { monthday: Number(event.target.value) })
                                                }
                                            />
                                        </label>
                                    )}
                                    <label>
                                        Tutulacak adet{' '}
                                        <input
                                            type="number"
                                            required
                                            min={1}
                                            max={365}
                                            value={plan.keep}
                                            onChange={(event) =>
                                                change(plan.id, { keep: Number(event.target.value) })
                                            }
                                        />
                                    </label>
                                    <label>
                                        <input
                                            type="checkbox"
                                            checked={plan.enabled}
                                            onChange={(event) =>
                                                change(plan.id, { enabled: event.target.checked })
                                            }
                                        />
                                        Etkin
                                    </label>
                                </div>
                                <p>
                                    Son: {saved?.last_run ?? '—'} · Sonraki: {saved?.next_run ?? '—'} ·{' '}
                                    {saved?.status ?? 'Kaydedilmedi'}
                                </p>
                                <div className="row-actions">
                                    <button
                                        type="button"
                                        className="button"
                                        disabled={!saved}
                                        onClick={() => void submit({ action: 'plan-run', id: plan.id })}
                                    >
                                        Hemen çalıştır
                                    </button>
                                    <button
                                        type="button"
                                        className="button button--danger"
                                        onClick={() => {
                                            if (
                                                window.confirm(
                                                    'Bu plan silinsin mi? Mevcut yedekler korunur.',
                                                )
                                            )
                                                setItems(items.filter((item) => item.id !== plan.id));
                                        }}
                                    >
                                        Planı kaldır
                                    </button>
                                </div>
                            </fieldset>
                        );
                    })}
                </div>
                <button
                    type="button"
                    className="button"
                    disabled={busy}
                    onClick={() =>
                        setItems([
                            ...items,
                            {
                                id: crypto.randomUUID(),
                                name: '',
                                target: 'local',
                                frequency: 'daily',
                                time: '02:00',
                                weekday: 1,
                                monthday: 1,
                                keep: 14,
                                enabled: true,
                            },
                        ])
                    }
                >
                    Plan ekle
                </button>
                <button className="button button--primary" disabled={busy}>
                    Planları kaydet
                </button>
            </form>
        </section>
    );
}
