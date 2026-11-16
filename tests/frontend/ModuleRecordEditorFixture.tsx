// Isolated UI fixture: synthetic validation/conflict responses; never writes to a server.
import { createInertiaApp } from '@inertiajs/react';
import { createRoot } from 'react-dom/client';
import ModuleRecordEditor from '../../resources/js/pages/ModuleRecordEditor';
import '../../resources/css/app.css';
const conflictFixture = location.search.includes('conflict');
window.fetch = async () =>
    conflictFixture
        ? new Response(JSON.stringify({ message: 'SENTETİK kayıt revision çatışması.' }), {
              status: 409,
              headers: { 'Content-Type': 'application/json' },
          })
        : new Response(
              JSON.stringify({
                  errors: { 'values.801': ['SENTETİK doğrulama hatası; girdiler korunuyor.'] },
              }),
              { status: 422, headers: { 'Content-Type': 'application/json' } },
          );
const types = [
    'text',
    'longtext',
    'integer',
    'decimal',
    'boolean',
    'date',
    'datetime',
    'select',
    'multiselect',
];
const labels = [
    'Açıklama',
    'Notlar',
    'Sayı',
    'Ondalık',
    'Tamamlandı',
    'Tarih',
    'Yerel saat',
    'Karar',
    'Etiketler',
];
void createInertiaApp({
    page: {
        flash: {},
        rememberedState: {},
        component: 'ModuleRecordEditor',
        url: location.pathname,
        version: null,
        props: {
            errors: {},
            applicationName: 'CTE · SENTETİK FORM TESTİ',
            title: 'SENTETİK Kurum Birimi',
            baseUrl: '/__synthetic_no_write_endpoint',
            submitUrl: '/__synthetic_no_write_endpoint',
            logoutUrl: '/__synthetic_no_write_endpoint',
            versionId: 9001,
            definitionVersion: 2,
            fields: types.map((type, i) => ({
                id: 801 + i,
                type,
                label: labels[i],
                helper: i === 3 ? 'Ondalık ayırıcı olarak nokta kullanın.' : '',
                required: i === 0,
                options: [
                    { id: 911, label: 'SENTETİK etkin seçenek', archived: false },
                    { id: 912, label: 'SENTETİK eski seçenek', archived: true },
                ],
            })),
            record: {
                id: 987001,
                revision: 2,
                values: {
                    801: 'SENTETİK mevcut kayıt',
                    802: 'SENTETİK not',
                    803: 0,
                    804: '123456789012.123456',
                    805: false,
                    806: '2026-10-04',
                    807: '2026-10-04 14:00:00',
                    808: 912,
                    809: [911, 912],
                },
            },
        },
        clearHistory: false,
        encryptHistory: false,
    },
    resolve: () => ModuleRecordEditor,
    setup: ({ el, App, props }) => createRoot(el).render(<App {...props} />),
});
