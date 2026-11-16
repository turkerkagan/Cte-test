// Synthetic placement fixture; every network write is mocked, no database access.
import { createInertiaApp } from '@inertiajs/react';
import { createRoot } from 'react-dom/client';
import PlacementEditor from '../../resources/js/pages/PlacementEditor';
import '../../resources/css/app.css';
let attempt = 0;
window.fetch = async () =>
    new Response(
        JSON.stringify(
            ++attempt === 1
                ? { errors: { bed_id: ['SENTETİK: seçilen ranza dolu.'] } }
                : { message: 'SENTETİK: yerleşim başka bir işlemde değişti.' },
        ),
        { status: attempt === 1 ? 422 : 409, headers: { 'Content-Type': 'application/json' } },
    );
const props = {
    applicationName: 'CTE · SENTETİK YERLEŞİM',
    baseUrl: '/__synthetic_no_write',
    logoutUrl: '/__synthetic_no_write',
    submitUrl: '/__synthetic_no_write',
    person: { id: 9001, full_name: 'ÖRNEK KİŞİ', tc_identity: null },
    record: { status_id: 1, block_id: 6, room_id: 60, bed_id: 9001, revision: 1, status_revision: 1 },
    statuses: [{ id: 1, label: 'HASTANE', is_active: true }],
    blocks: [
        { id: 6, name: 'HASTANE', is_active: true },
        { id: 1, name: 'A', is_active: true },
    ],
    rooms: [
        { id: 60, block_id: 6, name: 'HASTANE ERKEK', is_active: true },
        { id: 1, block_id: 1, name: '1', is_active: true },
    ],
    beds: [
        { id: 9001, room_id: 60, label: 'Ranzasız', available: true, is_active: true },
        { id: 9002, room_id: 1, label: '1', available: false, is_active: true },
        { id: 9003, room_id: 1, label: '2', available: true, is_active: true },
    ],
};
void createInertiaApp({
    page: {
        flash: {},
        rememberedState: {},
        component: 'PlacementEditor',
        props: { ...props, errors: {} },
        url: location.pathname,
        version: null,
        clearHistory: false,
        encryptHistory: false,
    },
    resolve: () => PlacementEditor,
    setup: ({ el, App, props }) => createRoot(el).render(<App {...props} />),
});
