import BulkPreviewChanges, { type BulkChanges } from './BulkPreviewChanges';
import { useRef, useState } from 'react';
import { router } from '@inertiajs/react';
import { writeJson } from '../lib/workflow';
import { notify } from './Toast';
export default function HomeShortcuts({
    baseUrl,
    statuses,
    canWrite = true,
    onActivity,
}: {
    baseUrl: string;
    statuses: { id: number; label: string; included_in_active_list?: boolean }[];
    canWrite?: boolean;
    onActivity?: (busy: boolean) => void;
}) {
    const [from, setFrom] = useState('');
    const [to, setTo] = useState('');
    const [preview, setPreview] = useState<
        (BulkChanges & { count: number; token: string; people?: { id: number; name: string }[] }) | null
    >(null);
    const [confirmed, setConfirmed] = useState(false);
    const [busy, setBusy] = useState(false);
    const lock = useRef(false);
    const [error, setError] = useState('');
    async function run(action: 'preview' | 'apply') {
        if (!canWrite || lock.current) return;
        lock.current = true;
        setBusy(true);
        onActivity?.(true);
        setError('');
        try {
            const r = await writeJson(baseUrl, { action, from, to, token: preview?.token }, 'POST');
            if (!r.ok) {
                setPreview(null);
                setConfirmed(false);
                throw new Error(
                    Object.values(r.data.errors ?? {})
                        .flat()
                        .join(' ') ||
                        r.data.message ||
                        'İşlem yapılamadı.',
                );
            }
            if (action === 'preview')
                setPreview(
                    r.data as unknown as {
                        count: number;
                        token: string;
                        people?: { id: number; name: string }[];
                    },
                );
            else {
                notify('Toplu durum değişikliği tamamlandı.');
                setPreview(null);
                setConfirmed(false);
                router.reload();
            }
        } catch (e) {
            setError(e instanceof Error ? e.message : 'İşlem yapılamadı.');
        } finally {
            lock.current = false;
            setBusy(false);
            onActivity?.(false);
        }
    }
    return (
        <section className="home-shortcuts">
            <h2>İşlem Kısayollar</h2>
            <form
                onSubmit={(e) => {
                    e.preventDefault();
                    void run('preview');
                }}
            >
                <fieldset disabled={!canWrite || busy}>
                    <div className="user-fields">
                        {[
                            ['from', 'Çevrilecek Durum'],
                            ['to', 'Hedef Durum'],
                        ].map(([key, label]) => (
                            <label key={key}>
                                {label}
                                <select
                                    required
                                    value={key === 'from' ? from : to}
                                    onChange={(e) => {
                                        (key === 'from' ? setFrom : setTo)(e.target.value);
                                        setPreview(null);
                                        setConfirmed(false);
                                    }}
                                >
                                    <option value="">Seçiniz</option>
                                    {statuses
                                        .filter((s) => s.included_in_active_list !== false)
                                        .map((s) => (
                                            <option key={s.id} value={s.id}>
                                                {s.label}
                                            </option>
                                        ))}
                                </select>
                            </label>
                        ))}
                    </div>
                    <button
                        type="submit"
                        className="button button--primary"
                        disabled={!from || !to || from === to}
                    >
                        Çevir
                    </button>
                    {preview && (
                        <section className="bulk-confirmation">
                            <p>{preview.count} kayıt etkilenecek.</p>
                            <BulkPreviewChanges key={preview.token} preview={preview} />
                            {!preview.changes &&
                                preview.people?.map((person) => <p key={person.id}>{person.name}</p>)}
                            <label className="checkbox-field">
                                <input
                                    type="checkbox"
                                    checked={confirmed}
                                    onChange={(e) => setConfirmed(e.target.checked)}
                                />
                                Bu kayıtların durumunu değiştirmeyi onaylıyorum.
                            </label>
                            <button
                                type="button"
                                className="button button--primary"
                                disabled={!confirmed || !preview.count}
                                onClick={() => void run('apply')}
                            >
                                Durumları Değiştir
                            </button>
                        </section>
                    )}
                </fieldset>
            </form>
            {busy && <p role="status">İşlem sürüyor…</p>}
            {error && (
                <p className="form-error" role="alert">
                    {error}
                </p>
            )}
        </section>
    );
}
