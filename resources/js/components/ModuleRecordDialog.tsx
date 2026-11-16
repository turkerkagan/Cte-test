import { router } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import { ModuleRecordForm, type ModuleRecordEditorProps } from '../pages/ModuleRecordEditor';
import PortalDialog from './PortalDialog';

export default function ModuleRecordDialog({ url, onClose }: { url: string; onClose: () => void }) {
    const [record, setRecord] = useState<ModuleRecordEditorProps | null>(null);
    const [busy, setBusy] = useState(false);
    const [dirty, setDirty] = useState(false);
    const [error, setError] = useState('');
    const [attempt, setAttempt] = useState(0);

    useEffect(() => {
        const controller = new AbortController();
        setRecord(null);
        setError('');
        fetch(url, {
            credentials: 'same-origin',
            headers: { Accept: 'application/json' },
            signal: controller.signal,
        })
            .then(async (response) => {
                if (!response.ok || !response.headers.get('content-type')?.includes('application/json')) {
                    throw new Error(
                        response.status === 403
                            ? 'Bu kaydı düzenleme yetkiniz yok.'
                            : 'Kayıt yüklenemedi. Oturumunuzu kontrol edip tekrar deneyin.',
                    );
                }
                return response.json() as Promise<ModuleRecordEditorProps>;
            })
            .then((data) => {
                if (!controller.signal.aborted) setRecord(data);
            })
            .catch((cause) => {
                if (!controller.signal.aborted)
                    setError(cause instanceof Error ? cause.message : 'Kayıt yüklenemedi.');
            });
        return () => controller.abort();
    }, [url, attempt]);

    return (
        <PortalDialog
            title={
                record
                    ? `${record.title} · ${record.record ? 'Kaydı düzenle' : 'Yeni kayıt'}`
                    : 'Kayıt İşlemleri'
            }
            className="cte-record-modal"
            onClose={onClose}
            busy={busy}
            dirty={dirty}
        >
            {record ? (
                <ModuleRecordForm
                    {...record}
                    editUrl={url}
                    onBusy={setBusy}
                    onDirty={setDirty}
                    onSaved={() => {
                        onClose();
                        router.reload();
                    }}
                />
            ) : (
                <section className="editor-panel">
                    <p role={error ? 'alert' : 'status'}>{error || 'Kayıt yükleniyor…'}</p>
                    {error && (
                        <button
                            type="button"
                            className="button"
                            onClick={() => setAttempt((value) => value + 1)}
                        >
                            Tekrar dene
                        </button>
                    )}
                </section>
            )}
        </PortalDialog>
    );
}
