// Synthetic menu editor; no permissions, accounts, or database mutations.
import { createInertiaApp } from '@inertiajs/react';
import { createRoot } from 'react-dom/client';
import MenuEditor from '../../resources/js/pages/MenuEditor';
import '../../resources/css/app.css';
window.fetch = async () =>
    new Response(JSON.stringify({ message: 'SENTETİK menü revision çatışması.' }), {
        status: 409,
        headers: { 'Content-Type': 'application/json' },
    });
const navigation = [
    { label: 'Ana sayfa', url: '/__synthetic_no_write_endpoint' },
    {
        label: 'Kurum birimleri',
        url: '#synthetic-group',
        children: [
            { label: 'SENTETİK Birim A', url: '/__synthetic_no_write_endpoint/a' },
            { label: 'SENTETİK Birim B', url: '/__synthetic_no_write_endpoint/b' },
        ],
    },
    {
        label: 'Yönetim paneli',
        url: '#management',
        children: [
            { label: 'Erişim politikaları', url: '/__synthetic_no_write_endpoint/policies' },
            { label: 'Sayfa düzenlemesi', url: '/__synthetic_no_write_endpoint/modules' },
            { label: 'Menü düzenlemesi', url: '/__synthetic_no_write_endpoint/menu' },
        ],
    },
];
void createInertiaApp({
    page: {
        flash: {},
        rememberedState: {},
        component: 'MenuEditor',
        url: location.pathname,
        version: null,
        props: {
            errors: {},
            applicationName: 'CTE · SENTETİK MENÜ TESTİ',
            revision: 2,
            configured: true,
            navigation,
            items: [
                {
                    id: 9001,
                    key: 'saved-9001',
                    parent_key: null,
                    label: 'Kurum birimleri',
                    page_id: null,
                    is_active: true,
                },
                {
                    id: 9002,
                    key: 'saved-9002',
                    parent_key: 'saved-9001',
                    label: 'SENTETİK Birim A',
                    page_id: 801,
                    is_active: true,
                },
                {
                    id: 9003,
                    key: 'saved-9003',
                    parent_key: 'saved-9001',
                    label: 'SENTETİK Birim B',
                    page_id: 802,
                    is_active: true,
                },
            ],
            pages: [
                { id: 801, name: 'SENTETİK Birim A' },
                { id: 802, name: 'SENTETİK Birim B' },
            ],
            saveUrl: '/__synthetic_no_write_endpoint',
            logoutUrl: '/__synthetic_no_write_endpoint',
        },
        clearHistory: false,
        encryptHistory: false,
    },
    resolve: () => MenuEditor,
    setup: ({ el, App, props }) => createRoot(el).render(<App {...props} />),
});
