// Synthetic admin fixture; every write is intercepted and returns a conflict.
import { createInertiaApp } from '@inertiajs/react';
import { createRoot } from 'react-dom/client';
import Statuses from '../../resources/js/pages/Statuses';
import defaults from '../../resources/defaults/person-statuses.json';
import '../../resources/css/app.css';
window.fetch = async () =>
    new Response(JSON.stringify({ message: 'SENTETİK: durum başka bir işlemde değişti.' }), {
        status: 409,
        headers: { 'Content-Type': 'application/json' },
    });
const props = {
    applicationName: 'CTE · SENTETİK DURUMLAR',
    baseUrl: '/__synthetic_status_no_write',
    logoutUrl: '/__synthetic_status_no_write',
    rows: defaults.map((r, i) => ({ ...r, id: 9001 + i, revision: 1, usage_count: i === 0 ? 1 : 0 })),
};
void createInertiaApp({
    page: {
        flash: {},
        rememberedState: {},
        component: 'Statuses',
        props: { ...props, errors: {} },
        url: location.pathname,
        version: null,
        clearHistory: false,
        encryptHistory: false,
    },
    resolve: () => Statuses,
    setup: ({ el, App, props }) => createRoot(el).render(<App {...props} />),
});
