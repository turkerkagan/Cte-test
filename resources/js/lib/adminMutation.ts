import { useRef, useState } from 'react';
import { writeJson } from './workflow';

export type AdminMutationOptions = {
    onSaved?: (id?: number, message?: string) => Promise<void>;
    onBusy?: (busy: boolean) => void;
};

/** The modal uses the same protected endpoints without replacing the list page. */
export function useAdminMutation({ onSaved, onBusy }: AdminMutationOptions) {
    const lock = useRef(false);
    const [busy, setBusy] = useState(false);
    const [conflict, setConflict] = useState(false);
    const [error, setError] = useState<string | null>(null);
    async function submit(url: string, data: unknown, method = 'POST') {
        if (lock.current) return;
        lock.current = true;
        setBusy(true);
        onBusy?.(true);
        setError(null);
        try {
            const result = await writeJson(url, data, method);
            if (!result.ok) {
                setConflict(result.status === 409 || !!result.data.errors?.revision);
                setError(
                    Object.values(result.data.errors ?? {})
                        .flat()
                        .join(' ') ||
                        result.data.message ||
                        'İşlem kaydedilemedi.',
                );
                return;
            }
            if (result.data.redirect) {
                window.location.assign(result.data.redirect);
                return;
            }
            await onSaved?.(result.data.id, result.data.message);
        } catch (cause) {
            setError(cause instanceof Error ? cause.message : 'İşlem tamamlanamadı. Girdileriniz korunuyor.');
        } finally {
            lock.current = false;
            setBusy(false);
            onBusy?.(false);
        }
    }
    return {
        submit,
        busy,
        error,
        conflict,
        clearConflict: () => {
            setConflict(false);
            setError(null);
        },
    };
}
