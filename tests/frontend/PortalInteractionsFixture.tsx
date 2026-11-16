// Disposable browser acceptance fixture. Every write stays in this page; no live endpoint is used.
import { createInertiaApp, router } from '@inertiajs/react';
import { createRoot } from 'react-dom/client';
import { useState, type ComponentProps } from 'react';
import ModuleRecords from '../../resources/js/pages/ModuleRecords';
import Statuses from '../../resources/js/pages/Statuses';
import DutyProgramTable from '../../resources/js/components/DutyProgramTable';
import PageRuleEditor from '../../resources/js/components/PageRuleEditor';
import PortalHealth from '../../resources/js/pages/PortalHealth';
import Workspace from '../../resources/js/pages/Workspace';
import HomeRecordDialog from '../../resources/js/components/HomeRecordDialog';
import '../../resources/css/app.css';
import '../../resources/css/frontend-parity.css';
import '../../resources/css/announcements.css';
import '../../resources/css/shared-tables.css';
import '../../resources/css/chat.css';

const query = new URLSearchParams(location.search);
const view = query.get('view') ?? 'module';
const readOnly = query.has('readonly');
const endpoint = '/__synthetic_no_live_endpoint';
const person = { id: 99001, tc_identity: '00000000001', full_name: 'SENTETİK AYŞE IŞIK' };
let record = { id: 99101, revision: 3, person, values: { '801': 'Eski değer' } };
const fields = [{ id: 801, type: 'text', label: 'Açıklama', helper: '', required: true, options: [] }];
const shared = {
    errors: {},
    applicationName: 'SENTETİK KABUL TESTİ',
    baseUrl: endpoint,
    logoutUrl: endpoint,
    navigation: [
        { label: 'Çalışma merkezi', url: '/?view=workspace' },
        { label: 'Kayıtlar', url: '/?view=module' },
    ],
};
const moduleProps: ComponentProps<typeof ModuleRecords> = {
    ...shared,
    title: 'SENTETİK MODÜL',
    recordMode: 'person_single',
    fields,
    definitionVersion: 2,
    rows: [{ ...record, editUrl: endpoint + '/edit', option_labels: [] }],
    pagination: { page: 1, per_page: 50, total: 1, last_page: 1 },
    canWrite: !readOnly,
    createUrl: endpoint + '/new',
};

function status(text: string) {
    const element = document.getElementById('fixture-status');
    if (element) element.textContent = text;
}
let conflictDelivered = false;
window.fetch = async (input, options) => {
    if (view === 'workspace') {
        const url = String(input),
            body = options?.body ? JSON.parse(String(options.body)) : {};
        if (url.includes('/yetkiler'))
            return Response.json({
                users: [{ id: person.id, full_name: person.full_name, username: 'sentetik' }],
                selected: url.includes('user=')
                    ? {
                          id: person.id,
                          name: person.full_name,
                          active: true,
                          passwordChangeRequired: false,
                          department: 'SENTETİK BİRİM',
                          jobTitle: 'SENTETİK UNVAN',
                      }
                    : null,
                pages: [
                    {
                        id: 1,
                        name: 'İzin kayıtları',
                        code: 'izin',
                        is_active: true,
                        read_only: false,
                        personal_reason: 'SENTETİK',
                        effective: {
                            giris: true,
                            yazma: false,
                            sources: [{ type: 'birim', permission: 'giris' }],
                        },
                    },
                ],
            });
        if (url.includes('/veri-kalitesi'))
            return Response.json({
                issues: {
                    duplicate_identity: { label: 'Aynı TC ile birden fazla kayıt', count: 2 },
                    missing_name: { label: 'Adı soyadı eksik', count: 0 },
                },
                issue: 'duplicate_identity',
                rows: [person, { ...person, id: 99002, full_name: 'SENTETİK DİĞER KİŞİ' }],
                page: 1,
                pages: 1,
                generatedAt: new Date().toISOString(),
            });
        if (url.includes('/veri-kokeni/'))
            return Response.json({
                person: { id: person.id, name: person.full_name },
                fields: [
                    {
                        key: 'full_name',
                        label: 'Adı Soyadı',
                        value: person.full_name,
                        source: 'people.full_name',
                        lastVerifiedChange: null,
                        eventId: null,
                    },
                ],
                imports: [],
                note: 'SENTETİK: Değişiklik kaydı olmayan alanın doğruluk tarihi varsayılmaz.',
            });
        if (url.includes('/yedek-provalari'))
            return Response.json(
                options?.method === 'POST'
                    ? {
                          status: 'passed',
                          at: new Date().toISOString(),
                          tables: 40,
                          rows: 250,
                          files: 2,
                          durationMs: 100,
                          temporaryResourcesCleaned: true,
                      }
                    : {
                          supported: true,
                          backups: [
                              {
                                  id: 1,
                                  name: 'cte-sentetik.cte',
                                  created_at: new Date().toISOString(),
                                  bytes: 1000,
                              },
                          ],
                          last: null,
                      },
            );
        if (body.action === 'apply') {
            status('SENTETİK: senaryo uygulandı.');
            return Response.json({ created: 2 });
        }
        const candidates = [
            { id: person.id, name: person.full_name },
            { id: 99002, name: 'SENTETİK DİĞER KİŞİ' },
        ];
        const rows = [
            { date: body.from, user_id: person.id, name: person.full_name },
            { date: body.to, user_id: 99002, name: candidates[1].name },
        ];
        return Response.json({
            token: 'a'.repeat(64),
            input: { from: body.from, to: body.to, exclude: body.exclude },
            candidates,
            scenarios: ['balanced', 'round_robin'].map((strategy) => ({
                strategy,
                label: strategy === 'balanced' ? '90 günlük yükü dengele' : 'Sırayla dağıt',
                rows,
                skipped: [],
                preserved: 0,
                spread: 0,
                workload: candidates.map((item) => ({ ...item, new: 1, total: 1, weekends: 0 })),
            })),
        });
    }
    if ((options?.method ?? 'GET') === 'GET') {
        return Response.json({
            ...shared,
            title: 'SENTETİK MODÜL',
            fields,
            versionId: 7,
            definitionVersion: 2,
            recordMode: 'person_single',
            record: String(input).endsWith('/new') ? null : record,
            submitUrl: endpoint + '/save',
        });
    }
    if (query.has('conflict') && !conflictDelivered) {
        conflictDelivered = true;
        record = {
            ...record,
            revision: record.revision + 1,
            values: { '801': 'Başka kullanıcının güncel değeri' },
        };
        return Response.json(
            { message: 'SENTETİK: kayıt başka bir kullanıcı tarafından değiştirildi.' },
            { status: 409 },
        );
    }
    const payload = JSON.parse(String(options?.body));
    if (view === 'module' && payload.revision !== record.revision)
        return Response.json({ message: 'SENTETİK: eski sürüm' }, { status: 409 });
    if (view === 'module') {
        record = { ...record, revision: record.revision + 1, values: payload.values };
        status('Kaydedildi: ' + record.values['801']);
    } else status('Sentetik kayıt kaydedildi.');
    return Response.json({ id: record.id, revision: record.revision });
};
router.reload = () =>
    router.replace({
        preserveState: true,
        preserveScroll: true,
        props: (current) =>
            view === 'module'
                ? { ...current, rows: [{ ...record, editUrl: endpoint + '/edit', option_labels: [] }] }
                : current,
    });

function DutyFixture() {
    const [rows, setRows] = useState<ComponentProps<typeof DutyProgramTable>['rows']>([
        {
            id: 99101,
            revision: 3,
            values: { date: '2026-10-16', user_id: 99001, deputy: 'SENTETİK VEKİL' },
            display: { user_id: person.full_name, deputy: 'SENTETİK VEKİL' },
        },
    ]);
    const [error, setError] = useState('');
    return (
        <main style={{ padding: 24 }}>
            <h1>Sentetik nöbet tablosu</h1>
            <DutyProgramTable
                kind="mudurnobet"
                rows={rows}
                candidates={[{ id: person.id, name: person.full_name }]}
                canWrite={!readOnly}
                busy={false}
                error={error}
                onSave={async (opened, values) => {
                    if (opened.revision !== rows[0].revision) {
                        setError('Kayıt değişti; güncel kaydı yeniden açın.');
                        return false;
                    }
                    setRows((current) =>
                        current.map((row) => ({
                            ...row,
                            values: { ...row.values, ...values },
                            display: { ...row.display, deputy: String(values.deputy ?? '') },
                            revision: row.revision + 1,
                        })),
                    );
                    status('Nöbet kaydedildi.');
                    return true;
                }}
            />
        </main>
    );
}

function PolicyFixture() {
    const props: ComponentProps<typeof PageRuleEditor> = {
        page: {
            id: 1,
            code: 'egitim',
            name: 'SENTETİK EĞİTİM',
            legacy_file: null,
            path: '/egitim',
            is_active: true,
            revision: 1,
            permission_count: 1,
            open_url: null,
            personal_permission: null,
            effective: { giris: true, yazma: true, sources: [] },
            rules: [
                {
                    target_type: 'kisi',
                    target_id: person.id,
                    name: person.full_name,
                    permission: 'giris',
                    reason: 'Sentetik erişim gerekçesi',
                },
            ],
        },
        targets: { birim: [], unvan: [], kisi: [{ id: person.id, name: person.full_name, is_active: true }] },
        batchUrl: endpoint,
    };
    return (
        <main style={{ padding: 24 }}>
            <PageRuleEditor {...props} />
        </main>
    );
}

function HomeFixture() {
    const [open, setOpen] = useState(false);
    return (
        <main>
            <button type="button" onClick={() => setOpen(true)}>
                Sentetik kişi kaydı aç
            </button>
            {open && (
                <HomeRecordDialog
                    id={null}
                    options={{
                        birthWritable: false,
                        registeredOn: '2026-10-16',
                        riskWritable: false,
                        caseOptions: {},
                        statuses: [],
                        blocks: [],
                        rooms: [],
                        beds: [],
                    }}
                    baseUrl={endpoint}
                    occupancy={[]}
                    links={[]}
                    canWrite
                    privateWritable={false}
                    onClose={() => setOpen(false)}
                />
            )}
        </main>
    );
}

const component =
    view === 'workspace'
        ? Workspace
        : view === 'home'
          ? HomeFixture
          : view === 'health'
            ? PortalHealth
            : view === 'duty'
              ? DutyFixture
              : view === 'policy'
                ? PolicyFixture
                : view === 'statuses'
                  ? Statuses
                  : ModuleRecords;
const props =
    view === 'workspace'
        ? {
              ...shared,
              canManage: !readOnly,
              dutyKinds: readOnly ? [] : [{ id: 'infaznobet', label: 'İnfaz nöbeti' }],
              handover: {
                  hours: 24,
                  since: new Date().toISOString(),
                  generatedAt: new Date().toISOString(),
                  tasks: [],
                  activity: [],
              },
          }
        : view === 'health'
          ? {
                ...shared,
                health: {
                    version: 'SENTETİK',
                    php: '8.2',
                    database: true,
                    installed: true,
                    maintenance: false,
                    debugDisabled: true,
                    assets: true,
                    backup: {
                        at: '2026-10-16T12:00:00+03:00',
                        success: true,
                        last_success_at: '2026-10-16T12:00:00+03:00',
                    },
                    job: null,
                    schedulerHealthy: true,
                    day: null,
                    measurementEnabled: false,
                    institutionAcceptance: 'SENTETİK KABUL TESTİ',
                    routes: [],
                    cleanup: { pending: 2, oldest: '2026-10-16T12:00:00+03:00' },
                    pendingMigrations: [],
                    backupPlans: [
                        {
                            id: 'synthetic',
                            name: 'SENTETİK ARŞİV PLANI',
                            enabled: true,
                            status: 'Başarısız',
                            attempts: 3,
                            retry_at: '2026-10-16T12:35:00+03:00',
                            last_local_success: '2026-10-16T12:00:00+03:00',
                            last_target_success: null,
                        },
                    ],
                },
            }
          : view === 'statuses'
            ? {
                  ...shared,
                  rows: [
                      {
                          id: 1,
                          code: 'active',
                          label: 'SENTETİK AKTİF',
                          included_in_active_list: true,
                          counts_room: true,
                          reserves_bed: true,
                          is_active: true,
                          revision: 1,
                          usage_count: 0,
                      },
                  ],
              }
            : moduleProps;
void createInertiaApp({
    page: {
        flash: {},
        rememberedState: {},
        component: 'Synthetic',
        props: { ...props, errors: {} },
        url: location.pathname + location.search,
        version: null,
        clearHistory: false,
        encryptHistory: false,
    },
    resolve: () => component,
    setup: ({ el, App, props }) => createRoot(el).render(<App {...props} />),
});
