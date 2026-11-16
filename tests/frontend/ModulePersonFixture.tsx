// Synthetic identity chooser and list. Every fetch is local mock data; no live writes.
import { createInertiaApp } from '@inertiajs/react';
import { createRoot } from 'react-dom/client';
import ModuleRecordEditor from '../../resources/js/pages/ModuleRecordEditor';
import ModuleRecords from '../../resources/js/pages/ModuleRecords';
import '../../resources/css/app.css';
const people = [
    { id: 901, tc_identity: '00000000001', full_name: 'SENTETİK IŞIK TÜRKER' },
    { id: 902, tc_identity: '00000000001', full_name: 'SENTETİK İPEK KURUM' },
];
window.fetch = async (_url, options) =>
    options?.method
        ? new Response(JSON.stringify({ message: 'SENTETİK kayıt revision çatışması.' }), {
              status: 409,
              headers: { 'Content-Type': 'application/json' },
          })
        : new Response(JSON.stringify({ people }), {
              status: 200,
              headers: { 'Content-Type': 'application/json' },
          });
const list = location.search.includes('list');
const shared = {
    applicationName: 'CTE · SENTETİK KİŞİ BAĞLANTISI',
    title: 'SENTETİK Kurum Birimi',
    recordMode: 'person_single',
    baseUrl: '/__synthetic_no_write_endpoint',
    logoutUrl: '/__synthetic_no_write_endpoint',
    definitionVersion: 1,
};
const props = list
    ? {
          ...shared,
          fields: [{ id: 801, type: 'text', label: 'Açıklama' }],
          rows: people.map((person, i) => ({
              id: 99001 + i,
              person,
              values: {
                  801:
                      i === 0
                          ? 'SENTETİK ortak kişi kaydı; bu uzun açıklama kırk karakter sınırında alt satıra geçer.'
                          : 'SENTETİK ikinci kayıt',
              },
              option_labels: [],
          })),
          pagination: { page: 1, per_page: 50, total: 2, last_page: 1 },
      }
    : {
          ...shared,
          peopleUrl: '/__synthetic_no_write_endpoint/people',
          submitUrl: '/__synthetic_no_write_endpoint/save',
          versionId: 9001,
          fields: [{ id: 801, type: 'text', label: 'Açıklama', helper: '', required: false, options: [] }],
          record: null,
      };
void createInertiaApp({
    page: {
        flash: {},
        rememberedState: {},
        component: list ? 'ModuleRecords' : 'ModuleRecordEditor',
        url: location.pathname,
        version: null,
        props: { ...props, errors: {} },
        clearHistory: false,
        encryptHistory: false,
    },
    resolve: () => (list ? ModuleRecords : ModuleRecordEditor),
    setup: ({ el, App, props }) => createRoot(el).render(<App {...props} />),
});
