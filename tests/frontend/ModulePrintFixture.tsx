// Synthetic print preview: no institutional records or printer side effects.
import { createInertiaApp } from '@inertiajs/react';
import { createRoot } from 'react-dom/client';
import ModulePrint from '../../resources/js/pages/ModulePrint';
import '../../resources/css/app.css';
void createInertiaApp({
    page: {
        flash: {},
        rememberedState: {},
        component: 'ModulePrint',
        url: location.pathname,
        version: null,
        props: {
            errors: {},
            applicationName: 'CTE · SENTETİK ÇIKTI TESTİ',
            title: 'SENTETİK Kurum Birimi',
            baseUrl: '/__synthetic_no_write_endpoint',
            definitionVersion: 2,
            fields: [
                { id: 801, type: 'text', label: 'Açıklama' },
                { id: 802, type: 'decimal', label: 'Ondalık' },
                { id: 803, type: 'boolean', label: 'Tamamlandı' },
                { id: 804, type: 'select', label: 'Karar' },
            ],
            rows: [
                ['SENTETİK ilk kayıt', '123456789012.123456', 'Hayır', 'SENTETİK geçmiş karar'],
                ['<img src=x onerror=alert(1)>', '0', 'Evet', 'SENTETİK karar'],
            ],
            queryState: {
                q: 'SENTETİK',
                filter_field: null,
                filter_op: 'eq',
                filter_value: '',
                sort_field: 802,
                sort_direction: 'desc',
            },
        },
        clearHistory: false,
        encryptHistory: false,
    },
    resolve: () => ModulePrint,
    setup: ({ el, App, props }) => createRoot(el).render(<App {...props} />),
});
