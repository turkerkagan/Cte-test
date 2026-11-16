import { router } from '@inertiajs/react';
import { useEffect, useRef } from 'react';

/** Keep drafts in memory; navigation protection never persists sensitive values. */
export default function useUnsavedChanges(dirty: boolean) {
    const pending = useRef(dirty);
    pending.current = dirty;
    useEffect(() => {
        const unload = (event: BeforeUnloadEvent) => {
            if (!pending.current) return;
            event.preventDefault();
            event.returnValue = '';
        };
        window.addEventListener('beforeunload', unload);
        const remove = router.on('before', (event) => {
            if (
                event.detail.visit.preserveState === true &&
                event.detail.visit.url.pathname === window.location.pathname
            )
                return;
            if (
                pending.current &&
                event.detail.visit.method === 'get' &&
                !window.confirm('Kaydedilmemiş değişiklikler var. Bu sayfadan ayrılmak istiyor musunuz?')
            ) {
                event.preventDefault();
            }
        });
        return () => {
            window.removeEventListener('beforeunload', unload);
            remove();
        };
    }, []);
    return () => {
        pending.current = false;
    };
}
