import { router } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import PortalDialog from './PortalDialog';
import { UserEditorContent, type UserEditorProps } from '../pages/UserEditor';

export default function UserAdministrationDialog({
    id,
    baseUrl,
    onClose,
}: {
    id: number | 'new';
    baseUrl: string;
    onClose: () => void;
}) {
    const [data, setData] = useState<UserEditorProps | null>(null);
    const [error, setError] = useState<string | null>(null);
    const [notice, setNotice] = useState<string | null>(null);
    const [busy, setBusy] = useState(false);
    const currentId = useRef(id);
    useEffect(() => {
        const abort = new AbortController();
        void load(id, abort.signal);
        return () => abort.abort();
    }, [id, baseUrl]);
    async function load(target: number | 'new', signal?: AbortSignal) {
        setError(null);
        try {
            const response = await fetch(`${baseUrl}/${target === 'new' ? 'yeni' : target}`, {
                credentials: 'same-origin',
                headers: { Accept: 'application/json' },
                signal,
            });
            if (!response.ok || !response.headers.get('content-type')?.includes('application/json'))
                throw new Error('Kullanıcı bilgileri yüklenemedi. Oturumunuzu kontrol edip yeniden deneyin.');
            const next = (await response.json()) as UserEditorProps;
            if (signal?.aborted) return;
            setData(next);
            currentId.current = target;
        } catch (cause) {
            if (!signal?.aborted)
                setError(cause instanceof Error ? cause.message : 'Kullanıcı bilgileri yüklenemedi.');
        }
    }
    async function saved(savedId?: number, message?: string) {
        setNotice(message ?? 'İşlem kaydedildi.');
        await load(savedId ?? currentId.current);
        router.reload({ only: ['rows'] });
    }
    return (
        <PortalDialog
            title={
                data?.user
                    ? 'Kullanıcı özellikleri'
                    : id === 'new'
                      ? 'Yeni kullanıcı'
                      : 'Kullanıcı özellikleri'
            }
            subtitle={
                data?.user
                    ? `${data.user.full_name ?? data.user.username} · ${data.user.username}`
                    : undefined
            }
            className="admin-user-dialog"
            onClose={onClose}
            busy={busy}
        >
            {notice && (
                <p className="notice" role="status">
                    {notice}
                </p>
            )}
            {error && (
                <div className="form-error" role="alert">
                    <p>{error}</p>
                    <button
                        type="button"
                        className="button"
                        disabled={busy}
                        onClick={() => void load(currentId.current)}
                    >
                        Yeniden yükle
                    </button>
                </div>
            )}
            {!data && !error && (
                <p role="status" className="portal-dialog-message">
                    Kullanıcı bilgileri yükleniyor…
                </p>
            )}
            {data && <UserEditorContent {...data} onSaved={saved} onBusy={setBusy} />}
        </PortalDialog>
    );
}
