// Synthetic designer fixture; no live database, credentials or mutation endpoint.
import { createInertiaApp } from '@inertiajs/react';
import { createRoot } from 'react-dom/client';
import ModuleDesigner from '../../resources/js/pages/ModuleDesigner';
import '../../resources/css/app.css';
const endpoint = '/__synthetic_no_write_endpoint';
void createInertiaApp({
    page: {
        flash: {},
        rememberedState: {},
        component: 'ModuleDesigner',
        url: location.pathname,
        version: null,
        props: {
            errors: {},
            applicationName: 'CTE · SENTETİK MODÜL TESTİ',
            baseUrl: endpoint,
            logoutUrl: endpoint,
            saveUrl: endpoint,
            forkUrl: endpoint,
            publishUrl: endpoint,
            publication: {
                revision: 2,
                version_id: 98702,
                version_revision: 2,
                record_count: 0,
                record_snapshot: '0'.repeat(64),
                blocked: [],
            },
            optionLists: [
                {
                    id: 901,
                    code: 'synthetic-category',
                    name: 'SENTETİK Kategori',
                    revision: 1,
                    is_active: true,
                    items: [
                        { id: 911, label: 'SENTETİK Mobilya', is_active: true },
                        { id: 912, label: 'SENTETİK Elektronik', is_active: true },
                        { id: 913, label: 'SENTETİK ARŞİV', is_active: false },
                    ],
                },
            ],
            module: {
                id: 98701,
                code: 'synthetic-demirbas',
                name: 'SENTETİK Demirbaş Listesi',
                record_mode: 'standalone',
                revision: 2,
            },
            version: {
                id: 98702,
                number: 1,
                revision: 2,
                status: 'draft',
                definition: {
                    schema_version: 1,
                    name: 'SENTETİK Demirbaş Listesi',
                    record_mode: 'standalone',
                    fields: [
                        {
                            id: 801,
                            type: 'text',
                            label: 'Demirbaş adı',
                            helper: 'SENTETİK alan açıklaması',
                            required: true,
                            show_in_form: true,
                            show_in_table: true,
                        },
                        {
                            id: 802,
                            type: 'integer',
                            label: 'Adet',
                            helper: '',
                            required: false,
                            show_in_form: true,
                            show_in_table: true,
                        },
                        {
                            id: 803,
                            type: 'select',
                            option_list_id: 901,
                            label: 'Kategori',
                            helper: '',
                            required: false,
                            show_in_form: true,
                            show_in_table: true,
                        },
                        {
                            id: 804,
                            type: 'multiselect',
                            option_list_id: 901,
                            label: 'Etiketler',
                            helper: '',
                            required: false,
                            show_in_form: true,
                            show_in_table: true,
                        },
                    ],
                },
            },
            history: [{ id: 98702, number: 1, status: 'draft' }],
        },
        clearHistory: false,
        encryptHistory: false,
    },
    resolve: () => ModuleDesigner,
    setup: ({ el, App, props }) => {
        createRoot(el).render(<App {...props} />);
    },
});
