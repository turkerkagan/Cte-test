import { useEffect, useRef } from 'react';
import { router } from '@inertiajs/react';

/** Refresh visible data while preserving filters, scroll and unsaved form state. */
export default function useLiveList(only: string[], blocked = false) {
    const current = useRef({ only, blocked });
    current.current = { only, blocked };
    useEffect(() => {
        let visiting = false;
        let refreshing = false;
        let disposed = false;
        let cancel: (() => void) | undefined;
        const offStart = router.on('start', () => {
            visiting = true;
        });
        const offFinish = router.on('finish', () => {
            visiting = false;
        });
        function refresh() {
            if (
                disposed ||
                visiting ||
                refreshing ||
                current.current.blocked ||
                document.visibilityState !== 'visible' ||
                document.querySelector('dialog[open], .react-column-menu') ||
                document.activeElement?.matches('input, textarea, select, [contenteditable="true"]')
            )
                return;
            refreshing = true;
            router.reload({
                only: current.current.only,
                onCancelToken: (token) => {
                    cancel = () => token.cancel();
                },
                onFinish: () => {
                    refreshing = false;
                    cancel = undefined;
                },
            });
        }
        const timer = window.setInterval(refresh, 20000);
        const onVisible = () => {
            if (document.visibilityState === 'visible') refresh();
        };
        document.addEventListener('visibilitychange', onVisible);
        return () => {
            disposed = true;
            window.clearInterval(timer);
            document.removeEventListener('visibilitychange', onVisible);
            offStart();
            offFinish();
            cancel?.();
        };
    }, []);
}
