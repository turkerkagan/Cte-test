// Synthetic browser fixture. No real login, database or mutation endpoint.
import { createInertiaApp } from '@inertiajs/react';
import { createRoot } from 'react-dom/client';
import PagePolicies from '../../resources/js/pages/PagePolicies';
import UserEditor from '../../resources/js/pages/UserEditor';
import '../../resources/css/app.css';
const personal = new URLSearchParams(location.search).has('personal');
const endpoint = '/__synthetic_no_write_endpoint';
const rows = [
    {
        id: 98701,
        code: 'hukumlu_kayit',
        name: 'Ana Sayfa',
        legacy_file: null,
        path: '/portal',
        is_active: true,
        revision: 1,
        permission_count: 0,
        open_url: '/portal-next/public/',
        rules: [],
        personal_permission: null,
        effective: { giris: true, yazma: false, sources: [{ type: 'homepage', permission: 'giris' }] },
    },
    {
        id: 98702,
        code: 'synthetic_unit',
        name: 'SENTETİK BİRİM',
        legacy_file: 'birimler/servisler/ilkgorus.php',
        path: null,
        is_active: true,
        revision: 3,
        permission_count: 1,
        open_url: '/portal-next/public/',
        rules: [{ target_type: 'birim', target_id: 1, name: 'SENTETİK BİRİM', permission: 'yazma' }],
        personal_permission: null,
        effective: { giris: true, yazma: true, sources: [{ type: 'birim', permission: 'yazma' }] },
    },
    {
        id: 98703,
        code: 'synthetic_archive',
        name: 'SENTETİK ARŞİV',
        legacy_file: null,
        path: '/portal',
        is_active: false,
        revision: 2,
        permission_count: 0,
        open_url: null,
        rules: [],
        personal_permission: null,
        effective: { giris: false, yazma: false, sources: [] },
    },
];
const common = { applicationName: 'CTE · SENTETİK EKRAN TESTİ', baseUrl: endpoint, logoutUrl: endpoint };
const props = personal
    ? {
          ...common,
          user: {
              id: 98750,
              username: 'synthetic_staff',
              registry_number: '000012',
              full_name: 'SENTETİK PERSONEL',
              email: null,
              phone: null,
              department_id: 1,
              job_title_id: 1,
              level: 'personnel',
              is_active: true,
              revision: 1,
              must_change_password: false,
              failed_login_attempts: 0,
              locked_at: null,
              last_login_at: null,
          },
          departments: [{ id: 1, name: 'SENTETİK BİRİM', is_active: true }],
          jobTitles: [{ id: 1, name: 'SENTETİK UNVAN', is_active: true }],
          events: [],
          submitUrl: endpoint,
          resetUrl: endpoint,
          accessUrl: endpoint,
          accessRows: rows,
      }
    : {
          ...common,
          rows,
          batchUrl: endpoint,
          targets: { birim: [{ id: 1, name: 'SENTETİK BİRİM', is_active: true }], unvan: [], kisi: [] },
      };
void createInertiaApp({
    page: {
        flash: {},
        rememberedState: {},
        component: personal ? 'UserEditor' : 'PagePolicies',
        url: location.pathname + location.search,
        version: null,
        props: { ...props, errors: {} },
        clearHistory: false,
        encryptHistory: false,
    },
    resolve: (name) => (name === 'UserEditor' ? UserEditor : PagePolicies),
    setup: ({ el, App, props }) => {
        createRoot(el).render(<App {...props} />);
    },
});
