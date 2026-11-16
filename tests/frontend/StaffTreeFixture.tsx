// Synthetic browser fixture. No database or mutation endpoint.
import { createInertiaApp } from '@inertiajs/react';
import { createRoot } from 'react-dom/client';
import StaffTree from '../../resources/js/pages/StaffTree';
import '../../resources/css/app.css';
const nodes = Array.from({ length: 100 }, (_, i) => ({
    id: i + 1,
    parent_id: i === 0 ? null : i < 6 ? i : Math.max(1, Math.floor((i - 5) / 3) + 1),
    full_name: `SENTETİK PERSONEL ${i + 1}`,
    title: i % 3 === 0 ? 'UZUN UNVAN '.repeat(24) : `SENTETİK UNVAN ${i + 1}`,
    description: null,
    user_id: null,
    position: i,
    revision: 1,
    is_active: true,
    review_reason: null,
}));
void createInertiaApp({
    page: {
        flash: {},
        rememberedState: {},
        component: 'StaffTree',
        url: location.pathname,
        version: null,
        props: {
            errors: {},
            applicationName: 'SENTETİK AĞAÇ TESTİ',
            baseUrl: '/no-write-endpoint',
            logoutUrl: '/no-write-endpoint',
            nodes,
            users: [],
            canWrite: true,
        },
        clearHistory: false,
        encryptHistory: false,
    },
    resolve: () => StaffTree,
    setup: ({ el, App, props }) => createRoot(el).render(<App {...props} />),
});
