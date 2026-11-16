import { useEffect, useState } from 'react';
export function notify(message: string, type: 'success' | 'error' = 'success') {
    window.dispatchEvent(new CustomEvent('cte-toast', { detail: { message, type } }));
}
export default function Toast() {
    const [toast, setToast] = useState<{ message: string; type: string } | null>(null);
    useEffect(() => {
        let timer: number;
        const show = (e: Event) => {
            setToast((e as CustomEvent).detail);
            clearTimeout(timer);
            timer = window.setTimeout(() => setToast(null), 5000);
        };
        window.addEventListener('cte-toast', show);
        return () => {
            window.removeEventListener('cte-toast', show);
            clearTimeout(timer);
        };
    }, []);
    return toast ? (
        <div
            className={'cte-toast cte-toast-' + toast.type}
            role={toast.type === 'error' ? 'alert' : 'status'}
        >
            <i
                className={'fas fa-' + (toast.type === 'success' ? 'check-circle' : 'triangle-exclamation')}
                aria-hidden="true"
            />
            {toast.message}
            <button aria-label="Bildirimi kapat" onClick={() => setToast(null)}>
                <i className="fas fa-xmark" />
            </button>
        </div>
    ) : null;
}
