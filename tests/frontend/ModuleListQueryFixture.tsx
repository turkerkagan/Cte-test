// Synthetic read-only query preview. Contains no real portal records.
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
            applicationName: 'CTE · SENTETİK LİSTE TESTİ',
            title: 'SENTETİK Kurum Birimi',
            baseUrl: endpoint,
            logoutUrl: endpoint,
            definitionVersion: 2,
            fields: [
                { id: 801, type: 'text', label: 'Açıklama' },
                { id: 802, type: 'integer', label: 'Sayı' },
                { id: 803, type: 'select', label: 'Karar' },
            ],
            rows: [
                {
                    id: 987002,
                    values: { 801: 'SENTETİK ikinci kayıt', 802: 10, 803: 911 },
                    option_labels: [{ field_id: 803, option_id: 911, label: 'SENTETİK karar' }],
                },
                {
                    id: 987001,
                    values: { 801: 'SENTETİK ilk kayıt', 802: 5, 803: 912 },
                    option_labels: [{ field_id: 803, option_id: 912, label: 'SENTETİK geçmiş karar' }],
                },
            ],
            pagination: { page: 1, per_page: 50, total: 2, last_page: 1 },
            queryState: {
                q: 'SENTETİK',
                filter_field: 802,
                filter_op: 'gte',
                filter_value: '5',
                sort_field: 802,
                sort_direction: 'desc',
            },
            filterOptions: {
                803: [
                    { id: 911, label: 'SENTETİK karar' },
                    { id: 912, label: 'SENTETİK geçmiş karar (arşiv)' },
                ],
            },
        },
        clearHistory: false,
        encryptHistory: false,
    },
    resolve: () => ModuleRecords,
    setup: ({ el, App, props }) => createRoot(el).render(<App {...props} />),
});
