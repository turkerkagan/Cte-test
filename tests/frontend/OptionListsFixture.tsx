// Synthetic fixture only. No live writes or credentials.
import { createInertiaApp } from '@inertiajs/react';
import { createRoot } from 'react-dom/client';
import OptionLists from '../../resources/js/pages/OptionLists';
import '../../resources/css/app.css';
const endpoint = '/__synthetic_no_write_endpoint';
void createInertiaApp({
    page: {
        flash: {},
        rememberedState: {},
        component: 'OptionLists',
        url: location.pathname,
        version: null,
        props: {
            errors: {},
            applicationName: 'CTE · SENTETİK SEÇENEK TESTİ',
            baseUrl: endpoint,
            modulesUrl: endpoint,
            logoutUrl: endpoint,
            lists: [
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
        },
        clearHistory: false,
        encryptHistory: false,
    },
    resolve: () => OptionLists,
    setup: ({ el, App, props }) => {
        createRoot(el).render(<App {...props} />);
    },
});
