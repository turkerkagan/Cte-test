// Isolated synthetic preview. All writes are intercepted; no live endpoint is used.
import { createInertiaApp, router } from '@inertiajs/react';
import { createRoot } from 'react-dom/client';
import People from '../../resources/js/pages/People';
import PersonEditor from '../../resources/js/pages/PersonEditor';
import '../../resources/css/app.css';
const form = location.search.includes('form');
const edit = location.search.includes('edit');
const rows = [
    {
        id: 99001,
        tc_identity: '00000000001',
        full_name: 'SENTETİK IŞIK TÜRKER',
        admitted_on: '2024-02-29',
        registered_on: '2026-10-04',
    },
    {
        id: 99002,
        tc_identity: null,
        full_name: 'SENTETİK İPEK KURUM',
        admitted_on: null,
        registered_on: null,
    },
];
window.fetch = async () =>
    new Response(
        JSON.stringify(
            edit
                ? { message: 'SENTETİK: kayıt başka bir kullanıcı tarafından güncellendi.' }
                : { errors: { full_name: ['SENTETİK doğrulama: girdiler korunuyor.'] } },
        ),
        { status: edit ? 409 : 422, headers: { 'Content-Type': 'application/json' } },
    );
const shared = {
    applicationName: 'CTE · SENTETİK ÖNİZLEME',
    baseUrl: '/__synthetic_no_write_endpoint',
    logoutUrl: '/__synthetic_no_write_endpoint',
    navigation: [{ label: 'Ana sayfa', url: '/__synthetic_no_write_endpoint' }],
};
const props = form
    ? {
          ...shared,
          record: edit ? { ...rows[1], revision: 1 } : null,
          defaultRegisteredOn: edit ? null : '2026-10-04',
          submitUrl: '/__synthetic_no_write_endpoint',
      }
    : {
          ...shared,
          rows: rows.map((row) => ({ ...row, editUrl: '/__synthetic_no_write_endpoint' })),
          canWrite: true,
          createUrl: '/__synthetic_no_write_endpoint',
          housingSummary: {
              total: 3,
              active: 2,
              unassigned: 1,
              present: 2,
              physical: 12,
              reserved: 1,
              available: 11,
              bedless_reserved: 1,
              rooms: [
                  {
                      id: 1,
                      block: 'A',
                      room: '1',
                      is_active: true,
                      capacity: 12,
                      present: 1,
                      physical: 12,
                      reserved: 1,
                      available: 11,
                      bedless_reserved: 0,
                  },
                  {
                      id: 60,
                      block: 'HASTANE',
                      room: 'ÖZEL KOĞUŞ',
                      is_active: true,
                      capacity: 0,
                      present: 1,
                      physical: 0,
                      reserved: 0,
                      available: 0,
                      bedless_reserved: 1,
                  },
              ],
          },
          queryState: { scope: 'active', q: '', tc: '', sort_field: null, sort_direction: 'asc' },
          pagination: { page: 1, per_page: 50, total: 2, last_page: 1 },
      };
// Simulate GET responses locally while preserving the real People visit parameters.
router.get = (_url, data) => {
    const query = data as {
        scope: 'active' | 'all';
        q: string;
        tc: string;
        sort_field?: string;
        sort_direction?: 'asc' | 'desc';
        page: number;
        per_page: number;
    };
    const filtered = (
        query.scope === 'all' ? [...rows, { ...rows[1], id: 99003, full_name: 'SENTETİK DURUMSUZ' }] : rows
    ).filter(
        (row) =>
            (!query.q || row.full_name?.includes(query.q)) && (!query.tc || row.tc_identity === query.tc),
    );
    router.push({
        url: '/?' + new URLSearchParams(Object.entries(query).map(([key, value]) => [key, String(value)])),
        props: (current) => ({
            ...current,
            rows: filtered,
            queryState: {
                scope: query.scope,
                q: query.q,
                tc: query.tc,
                sort_field: query.sort_field ?? null,
                sort_direction: query.sort_direction ?? 'asc',
            },
            pagination: { page: query.page, per_page: query.per_page, total: filtered.length, last_page: 1 },
        }),
    });
};
void createInertiaApp({
    page: {
        flash: {},
        rememberedState: {},
        component: form ? 'PersonEditor' : 'People',
        props: { ...props, errors: {} },
        url: location.pathname,
        version: null,
        clearHistory: false,
        encryptHistory: false,
    },
    resolve: () => (form ? PersonEditor : People),
    setup: ({ el, App, props }) => createRoot(el).render(<App {...props} />),
});
