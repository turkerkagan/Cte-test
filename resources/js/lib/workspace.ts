import { portalFetch } from './http';
import { useEffect, useState } from 'react';

export async function workspaceRequest<T>(url: string, body?: unknown, signal?: AbortSignal): Promise<T> {
    const response = await portalFetch(url, {
        credentials: 'same-origin',
        cache: 'no-store',
        signal,
        method: body === undefined ? 'GET' : 'POST',
        headers: {
            Accept: 'application/json',
            ...(body === undefined ? {} : { 'Content-Type': 'application/json' }),
        },
        body: body === undefined ? undefined : JSON.stringify(body),
    });
    if (!response.headers.get('content-type')?.includes('application/json'))
        throw new Error('Oturumunuzu kontrol edip tekrar deneyin.');
    const data = await response.json();
    if (!response.ok)
        throw new Error(
            Object.values(data.errors ?? {})
                .flat()
                .join(' ') ||
                data.message ||
                'İşlem tamamlanamadı.',
        );
    return data as T;
}

export function useWorkspaceData<T>(url: string | null) {
    const [data, setData] = useState<T | null>(null);
    const [error, setError] = useState('');
    const [loading, setLoading] = useState(false);
    const [attempt, setAttempt] = useState(0);
    useEffect(() => {
        setData(null);
        setError('');
        if (!url) {
            setLoading(false);
            return;
        }
        const controller = new AbortController();
        setLoading(true);
        workspaceRequest<T>(url, undefined, controller.signal)
            .then((result) => {
                if (!controller.signal.aborted) setData(result);
            })
            .catch((cause) => {
                if (!controller.signal.aborted)
                    setError(cause instanceof Error ? cause.message : 'Veri alınamadı.');
            })
            .finally(() => {
                if (!controller.signal.aborted) setLoading(false);
            });
        return () => controller.abort();
    }, [url, attempt]);
    return { data, error, loading, reload: () => setAttempt((value) => value + 1) };
}
