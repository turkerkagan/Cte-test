// Synthetic read-only page, no credentials or real mutations.
import { createInertiaApp } from '@inertiajs/react';
import { createRoot } from 'react-dom/client';
import ModuleRecords from '../../resources/js/pages/ModuleRecords';
import '../../resources/css/app.css';
const endpoint = '/__synthetic_no_write_endpoint';
void createInertiaApp({
    page: {
        flash: {},
        rememberedState: {},
        component: 'ModuleRecords',
        url: location.pathname,
        version: null,
        props: {
            errors: {},
            applicationName: 'CTE · SENTETİK YAYIN TESTİ',
            title: 'SENTETİK Demirbaş Listesi',
            baseUrl: endpoint,
            logoutUrl: endpoint,
            definitionVersion: 2,
            fields: [
                { id: 801, type: 'text', label: 'Demirbaş adı' },
                { id: 802, type: 'integer', label: 'Adet' },
                { id: 803, type: 'select', label: 'Kategori' },
            ],
            rows: [
                {
                    id: 987001,
                    values: { 801: 'SENTETİK Masa', 802: 0, 803: 911 },
                    option_labels: [{ field_id: 803, option_id: 911, label: 'SENTETİK Tarihî etiket' }],
                },
                {
                    id: 987002,
                    values: { 801: 'SENTETİK Sandalye', 802: 5, 803: 912 },
                    option_labels: [{ field_id: 803, option_id: 912, label: 'SENTETİK Mobilya' }],
                },
            ],
            pagination: { page: 1, per_page: 50, total: 2, last_page: 1 },
        },
        clearHistory: false,
        encryptHistory: false,
    },
    resolve: () => ModuleRecords,
    setup: ({ el, App, props }) => {
        createRoot(el).render(<App {...props} />);
    },
});
