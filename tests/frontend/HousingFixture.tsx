// Synthetic settings preview; fetch writes never reach the backend.
import { createInertiaApp } from '@inertiajs/react';
import { createRoot } from 'react-dom/client';
import Housing from '../../resources/js/pages/Housing';
import defaults from '../../resources/defaults/housing.json';
import '../../resources/css/app.css';
window.fetch = async () =>
    new Response(JSON.stringify({ message: 'SENTETİK: yerleşim ayarı başka bir işlemde değişti.' }), {
        status: 409,
        headers: { 'Content-Type': 'application/json' },
    });
const props = {
    applicationName: 'CTE · SENTETİK YERLEŞİM',
    baseUrl: '/__synthetic_housing_no_write',
    logoutUrl: '/__synthetic_housing_no_write',
    blocks: defaults.blocks.map((b) => ({ ...b, is_active: !!b.is_active, revision: 1 })),
    rooms: [...defaults.rooms]
        .sort((a, b) => a.block_id - b.block_id || a.code.localeCompare(b.code, 'tr', { numeric: true }))
        .map((r) => ({ ...r, is_active: !!r.is_active, revision: 1, occupied_count: r.id === 1 ? 1 : 0 })),
};
void createInertiaApp({
    page: {
        flash: {},
        rememberedState: {},
        component: 'Housing',
        props: { ...props, errors: {} },
        url: location.pathname,
        version: null,
        clearHistory: false,
        encryptHistory: false,
    },
    resolve: () => Housing,
    setup: ({ el, App, props }) => createRoot(el).render(<App {...props} />),
});
