// Disposable browser fixture; no database or live mutation endpoints.
import { createInertiaApp } from '@inertiajs/react';
import { createRoot } from 'react-dom/client';
import Organization from '../../resources/js/pages/Organization';
import '../../resources/css/app.css';

void createInertiaApp({
    page: {
        flash: {},
        rememberedState: {},
        component: 'Organization',
        url: location.pathname,
        version: null,
        props: {
            errors: {},
            applicationName: 'SENTETİK YÖNETİM TESTİ',
            title: 'Kurum birimleri',
            kind: 'birimler',
            baseUrl: '/__fixture_has_no_write_endpoint',
            logoutUrl: '/__fixture_has_no_write_endpoint',
            rows: [
                {
                    id: 98701,
                    code: 'sentetik_1',
                    name: 'SENTETİK BİRİM',
                    is_active: true,
                    revision: 1,
                    permission_count: 2,
                    permission_snapshot: 'synthetic_source_snapshot',
                    assigned_count: 8,
                },
                {
                    id: 98702,
                    code: 'sentetik_2',
                    name: 'SENTETİK HEDEF',
                    is_active: true,
                    revision: 1,
                    permission_count: 1,
                    permission_snapshot: 'synthetic_target_snapshot',
                    assigned_count: 0,
                },
                {
                    id: 98703,
                    code: 'sentetik_3',
                    name: 'SENTETİK PASİF',
                    is_active: false,
                    revision: 1,
                    permission_count: 1,
                    permission_snapshot: 'synthetic_target_snapshot',
                    assigned_count: 0,
                },
            ],
        },
        clearHistory: false,
        encryptHistory: false,
    },
    resolve: () => Organization,
    setup: ({ el, App, props }) => {
        createRoot(el).render(<App {...props} />);
    },
});
