// Disposable browser fixture with synthetic people and no live write endpoint.
import { createInertiaApp } from '@inertiajs/react';
import { createRoot } from 'react-dom/client';
import Users from '../../resources/js/pages/Users';
import UserEditor from '../../resources/js/pages/UserEditor';
import '../../resources/css/app.css';
const editor = new URLSearchParams(location.search).get('view') === 'editor';
const common = {
    applicationName: 'SENTETİK KULLANICI TESTİ',
    baseUrl: '/__fixture_no_endpoint',
    logoutUrl: '/__fixture_no_endpoint',
};
const props = editor
    ? {
          ...common,
          user: {
              id: 98701,
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
              last_login_at: '2026-10-03T09:00:00Z',
          },
          departments: [{ id: 1, name: 'SENTETİK BİRİM', is_active: true }],
          jobTitles: [{ id: 1, name: 'SENTETİK MESLEK UNVANI', is_active: true }],
          events: [
              { id: 1, action: 'created', created_at: '2026-10-03T08:00:00Z', actor: 'SENTETİK YÖNETİCİ' },
          ],
          submitUrl: '/__fixture_no_endpoint',
          resetUrl: '/__fixture_no_endpoint',
      }
    : {
          ...common,
          rows: [
              {
                  id: 98701,
                  username: 'synthetic_staff',
                  registry_number: '000012',
                  full_name: 'SENTETİK PERSONEL',
                  department: 'SENTETİK BİRİM',
                  job_title: 'SENTETİK MESLEK UNVANI',
                  level: 'Kurum Personeli',
                  is_active: true,
              },
              {
                  id: 98702,
                  username: 'synthetic_admin',
                  registry_number: null,
                  full_name: 'SENTETİK YÖNETİCİ',
                  department: null,
                  job_title: 'SENTETİK DİĞER UNVAN',
                  level: 'Sistem Yöneticisi',
                  is_active: true,
              },
          ],
      };
void createInertiaApp({
    page: {
        flash: {},
        rememberedState: {},
        component: editor ? 'UserEditor' : 'Users',
        url: location.pathname + location.search,
        version: null,
        props: { ...props, errors: {} },
        clearHistory: false,
        encryptHistory: false,
    },
    resolve: (name) => (name === 'Users' ? Users : UserEditor),
    setup: ({ el, App, props }) => {
        createRoot(el).render(<App {...props} />);
    },
});
