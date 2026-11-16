import type { AccessRow } from '../../resources/js/components/PageAccessTable';
import { formatDate, formatDateTime, todayInIstanbul } from '../../resources/js/lib/format';
import { portalFetch } from '../../resources/js/lib/http';
import { shouldLoadLoginAnimation } from '../../resources/js/lib/loginAnimation';
import assert from 'node:assert/strict';
import { after, afterEach, before, test } from 'node:test';
import { JSDOM } from 'jsdom';
import React from 'react';

let dom: JSDOM;
let createRoot: (typeof import('react-dom/client'))['createRoot'];
let act: (typeof import('react'))['act'];
let PageAccessTable: (typeof import('../../resources/js/components/PageAccessTable'))['default'];
let PortalDialog: (typeof import('../../resources/js/components/PortalDialog'))['default'];
let PortalErrorBoundary: (typeof import('../../resources/js/components/PageFailure'))['PortalErrorBoundary'];
let ModuleRecordDialog: (typeof import('../../resources/js/components/ModuleRecordDialog'))['default'];
let HomeRecordDialog: (typeof import('../../resources/js/components/HomeRecordDialog'))['default'];
let BackupPlanEditor: (typeof import('../../resources/js/components/BackupPlanEditor'))['default'];
let ConflictReview: (typeof import('../../resources/js/components/ConflictReview'))['default'];
let CommandPalette: (typeof import('../../resources/js/components/CommandPalette'))['default'];
let LeaveEditor: (typeof import('../../resources/js/pages/LeaveEditor'))['default'];
let SentenceEditor: (typeof import('../../resources/js/pages/SentenceEditor'))['default'];
let StatusEditor: (typeof import('../../resources/js/pages/Statuses'))['Editor'];
let HousingForm: (typeof import('../../resources/js/pages/Housing'))['SettingsForm'];
let router: (typeof import('@inertiajs/react'))['router'];
let root: ReturnType<typeof createRoot> | undefined;
const originalFetch = globalThis.fetch;

before(async () => {
    dom = new JSDOM('<!doctype html><html><body><div id="app"></div></body></html>', {
        url: 'http://localhost/',
        pretendToBeVisual: true,
    });
    for (const name of [
        'window',
        'document',
        'navigator',
        'location',
        'HTMLElement',
        'HTMLInputElement',
        'HTMLTextAreaElement',
        'HTMLSelectElement',
        'Event',
        'MouseEvent',
        'KeyboardEvent',
        'MutationObserver',
        'CustomEvent',
        'requestAnimationFrame',
        'cancelAnimationFrame',
    ]) {
        Object.defineProperty(globalThis, name, {
            configurable: true,
            writable: true,
            value: (dom.window as unknown as Record<string, unknown>)[name],
        });
    }
    Object.defineProperty(globalThis, 'IS_REACT_ACT_ENVIRONMENT', { configurable: true, value: true });
    dom.window.HTMLDialogElement.prototype.showModal = function () {
        this.setAttribute('open', '');
    };
    dom.window.HTMLDialogElement.prototype.close = function () {
        this.removeAttribute('open');
    };
    ({ createRoot } = await import('react-dom/client'));
    ({ act } = await import('react'));
    ({ default: PageAccessTable } = await import('../../resources/js/components/PageAccessTable'));
    ({ default: PortalDialog } = await import('../../resources/js/components/PortalDialog'));
    ({ PortalErrorBoundary } = await import('../../resources/js/components/PageFailure'));
    ({ default: ModuleRecordDialog } = await import('../../resources/js/components/ModuleRecordDialog'));
    ({ default: HomeRecordDialog } = await import('../../resources/js/components/HomeRecordDialog'));
    ({ default: BackupPlanEditor } = await import('../../resources/js/components/BackupPlanEditor'));
    ({ default: ConflictReview } = await import('../../resources/js/components/ConflictReview'));
    ({ default: CommandPalette } = await import('../../resources/js/components/CommandPalette'));
    ({ default: LeaveEditor } = await import('../../resources/js/pages/LeaveEditor'));
    ({ default: SentenceEditor } = await import('../../resources/js/pages/SentenceEditor'));
    ({ Editor: StatusEditor } = await import('../../resources/js/pages/Statuses'));
    ({ SettingsForm: HousingForm } = await import('../../resources/js/pages/Housing'));
    ({ router } = await import('@inertiajs/react'));
    // Component tests have no Inertia page lifecycle or server; prefetch must stay local.
    router.prefetch = () => {};
    router.reload = () => {};
});

afterEach(async () => {
    if (root) await act(() => root!.unmount());
    root = undefined;
    globalThis.fetch = originalFetch;
});
after(() => dom.window.close());

async function render(element: React.ReactNode) {
    await act(async () => {
        root = createRoot(document.getElementById('app')!);
        root.render(element);
    });
}

test('React rendering failure offers retry and home navigation', async () => {
    function BrokenPage(): React.ReactNode {
        throw new Error('Synthetic render failure');
    }
    const originalError = console.error;
    const errors: unknown[][] = [];
    console.error = (...args: unknown[]) => errors.push(args);
    try {
        await render(
            <PortalErrorBoundary>
                <BrokenPage />
            </PortalErrorBoundary>,
        );
        assert.equal(document.querySelector('h1')?.textContent, 'Sayfa açılamadı');
        assert.ok(
            Array.from(document.querySelectorAll('button')).some(
                (button) => button.textContent === 'Yeniden dene',
            ),
        );
        assert.equal(document.querySelector<HTMLAnchorElement>('a')?.getAttribute('href'), '/');
        assert.ok(
            errors.some((args) => args.some((value) => String(value).includes('Synthetic render failure'))),
        );
    } finally {
        console.error = originalError;
    }
});
async function click(label: string) {
    const button = Array.from(document.querySelectorAll<HTMLButtonElement>('button')).find(
        (node) => node.textContent?.trim() === label || node.getAttribute('aria-label') === label,
    );
    assert.ok(button, `Button missing: ${label}`);
    await act(async () => {
        button.click();
    });
}
async function fill(selector: string, value: string) {
    const input = document.querySelector<HTMLInputElement>(selector)!;
    assert.ok(input);
    await act(async () => {
        Object.getOwnPropertyDescriptor(dom.window.HTMLInputElement.prototype, 'value')!.set!.call(
            input,
            value,
        );
        input.dispatchEvent(new dom.window.Event('input', { bubbles: true }));
    });
}
function Draft({ close, busy = false }: { close: () => void; busy?: boolean }) {
    const [value, setValue] = React.useState('Eski değer');
    return (
        <PortalDialog title="Sentetik düzenleme" onClose={close} busy={busy}>
            <label>
                Açıklama
                <input id="draft" value={value} onChange={(event) => setValue(event.target.value)} />
            </label>
        </PortalDialog>
    );
}

test('Escape and header close preserve the draft until discard is explicitly selected', async () => {
    let closed = 0;
    await render(<Draft close={() => closed++} />);
    await fill('#draft', 'Korunacak girdi');
    await act(() => {
        document
            .querySelector('dialog')!
            .dispatchEvent(new dom.window.Event('cancel', { bubbles: true, cancelable: true }));
    });
    assert.equal(closed, 0);
    assert.ok(document.querySelector('[role=alertdialog]'));
    await click('Düzenlemeye devam et');
    assert.equal(document.querySelector<HTMLInputElement>('#draft')!.value, 'Korunacak girdi');
    await click('Pencereyi kapat');
    await click('Değişiklikleri bırak');
    assert.equal(closed, 1);
});

test('Restoring initial values closes without an unnecessary warning', async () => {
    let closed = 0;
    await render(<Draft close={() => closed++} />);
    await fill('#draft', 'Değişti');
    await fill('#draft', 'Eski değer');
    await click('Pencereyi kapat');
    assert.equal(closed, 1);
    assert.equal(document.querySelector('[role=alertdialog]'), null);
});

test('A busy dialog rejects Escape and cannot be closed by its header', async () => {
    let closed = 0;
    await render(<Draft close={() => closed++} busy />);
    await act(() => {
        document
            .querySelector('dialog')!
            .dispatchEvent(new dom.window.Event('cancel', { bubbles: true, cancelable: true }));
    });
    assert.equal(closed, 0);
    assert.equal(
        document.querySelector<HTMLButtonElement>('button[aria-label="Pencereyi kapat"]')!.disabled,
        true,
    );
});

test('Controls added by an asynchronous child are included in draft protection', async () => {
    function DynamicFields() {
        const [loaded, setLoaded] = React.useState(false);
        const [value, setValue] = React.useState('Eski değer');
        return (
            <>
                <button type="button" onClick={() => setLoaded(true)}>
                    Alanları yükle
                </button>
                {loaded && (
                    <input id="late-field" value={value} onChange={(event) => setValue(event.target.value)} />
                )}
            </>
        );
    }
    let closed = 0;
    await render(
        <PortalDialog title="Sentetik geç yükleme" onClose={() => closed++}>
            <DynamicFields />
        </PortalDialog>,
    );
    await click('Alanları yükle');
    await fill('#late-field', 'Korunacak girdi');
    await click('Pencereyi kapat');
    assert.equal(closed, 0);
    assert.ok(document.querySelector('[role=alertdialog]'));
});

test('Main person dialog preserves input when closing is cancelled', async () => {
    let closed = 0;
    await render(
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
            baseUrl="/synthetic"
            occupancy={[]}
            links={[]}
            canWrite
            privateWritable={false}
            onClose={() => closed++}
        />,
    );
    await fill('#home-full_name', 'SENTETİK KORUNACAK KİŞİ');
    await click('Kayıt işlem penceresini kapat');
    assert.equal(closed, 0);
    assert.equal(document.querySelectorAll('dialog[open]').length, 2);
    await click('Düzenlemeye devam et');
    assert.equal(
        document.querySelector<HTMLInputElement>('#home-full_name')!.value,
        'SENTETİK KORUNACAK KİŞİ',
    );
    await click('Kayıt işlem penceresini kapat');
    await click('Değişiklikleri bırak');
    assert.equal(closed, 1);
});

test('Conflict comparison requires a choice and submits the current revision while preserving the draft', async () => {
    let closed = 0,
        reads = 0,
        writes = 0;
    const fields = [{ id: 801, type: 'text', label: 'Açıklama', helper: '', required: true, options: [] }];
    globalThis.fetch = async (_input, options) => {
        if ((options?.method ?? 'GET') === 'GET') {
            reads++;
            return Response.json({
                applicationName: 'SENTETIK',
                title: 'Modül',
                baseUrl: '/synthetic',
                submitUrl: '/synthetic/save',
                logoutUrl: '/logout',
                fields,
                versionId: 7,
                definitionVersion: 2,
                record: {
                    id: 1,
                    revision: reads === 1 ? 3 : 4,
                    values: { '801': reads === 1 ? 'Eski değer' : 'Sunucudaki değer' },
                },
            });
        }
        writes++;
        if (writes === 1) return Response.json({ message: 'SENTETIK CONFLICT' }, { status: 409 });
        const payload = JSON.parse(String(options?.body));
        assert.equal(payload.revision, 4);
        assert.equal(payload.values['801'], 'Benim girdim');
        return Response.json({ id: 1, revision: 5 });
    };
    await render(<ModuleRecordDialog url="/synthetic/edit" onClose={() => closed++} />);
    await fill('#record-field-801', 'Benim girdim');
    await act(async () => {
        document
            .querySelector('form')!
            .dispatchEvent(new dom.window.Event('submit', { bubbles: true, cancelable: true }));
    });
    assert.equal(closed, 0);
    assert.equal(document.querySelector<HTMLInputElement>('#record-field-801')!.value, 'Benim girdim');
    await click('Güncel kayıtla karşılaştır');
    assert.equal(reads, 2);
    const apply = Array.from(document.querySelectorAll('button')).find(
        (node) => node.textContent === 'Seçimleri forma aktar',
    )!;
    assert.equal(apply.disabled, true);
    await act(() => document.querySelector<HTMLInputElement>('input[type=radio][value=mine]')!.click());
    await click('Seçimleri forma aktar');
    await act(async () => {
        document
            .querySelector('form')!
            .dispatchEvent(new dom.window.Event('submit', { bubbles: true, cancelable: true }));
    });
    assert.equal(writes, 2);
    assert.equal(closed, 1);
});

test('Backup plan updates exclude monitoring fields from the write payload', async () => {
    const plan = {
        id: 'synthetic',
        name: 'SENTETIK',
        target: 'local',
        frequency: 'daily' as const,
        time: '02:00',
        weekday: 1,
        monthday: 1,
        keep: 14,
        enabled: true,
    };
    let submitted: Record<string, unknown> | null = null;
    const monitored = {
        ...plan,
        status: 'Başarısız',
        last_run: '2026-10-16',
        next_run: '2026-10-17',
        attempts: 3,
        retry_at: '2026-10-16',
        last_local_success: '2026-10-15',
        last_target_success: null,
    };
    await render(
        <BackupPlanEditor
            state={{
                revision: 2,
                values: { items: [monitored] },
                targets: { local: 'Yerel' },
                targetState: { revision: 0, values: { items: [] } },
            }}
            busy={false}
            submit={async (data) => {
                submitted = data;
                return true;
            }}
        />,
    );
    await act(() => {
        document
            .querySelector('form')!
            .dispatchEvent(new dom.window.Event('submit', { bubbles: true, cancelable: true }));
    });
    assert.deepEqual(submitted, { action: 'plans', revision: 2, items: [plan] });
});

async function submitForm() {
    await act(async () => {
        document
            .querySelector('form')!
            .dispatchEvent(new dom.window.Event('submit', { bubbles: true, cancelable: true }));
    });
}
async function selectMine(label: string) {
    const radio = document.querySelector<HTMLInputElement>(`input[aria-label="${label} · Benim girdim"]`);
    assert.ok(radio);
    await act(() => radio.click());
}

test('Generic comparison never writes, rejects a changed field definition and keeps unresolved choices explicit', async () => {
    let applied = 0;
    let attempt = 0;
    await render(
        <ConflictReview
            base={{ name: 'Eski' }}
            draft={{ name: 'Benim' }}
            labels={{ name: 'Ad' }}
            load={async () => {
                const values: Record<string, string> =
                    ++attempt === 1 ? { name: 'Güncel' } : { name: 'Güncel', newField: 'new' };
                return { values, metadata: { revision: attempt + 1 } };
            }}
            apply={() => applied++}
        />,
    );
    await click('Güncel kayıtla karşılaştır');
    assert.ok(document.querySelector('input[type=radio]'));
    await click('Güncel kayıtla karşılaştır');
    assert.match(document.body.textContent ?? '', /yetkiler değişti/);
    assert.equal(applied, 0);
    assert.equal(document.querySelector('input[type=radio]'), null);
});

const workflowProps: import('../../resources/js/pages/WorkflowEditor').WorkflowEditorProps = {
    applicationName: 'SENTETIK',
    logoutUrl: '/logout',
    baseUrl: '/synthetic',
    workflow: 'izin',
    title: 'SENTETIK',
    submitUrl: '/synthetic/1',
    person: {
        id: 1,
        full_name: 'SENTETİK AYŞE',
        tc_identity: null,
        registered_on: null,
        admitted_on: '2026-10-01',
        revision: 2,
    },
    revision: 3,
    fields: { izin_gun_sayisi: { label: 'Gün sayısı', type: 'integer' } },
    values: { izin_gun_sayisi: 11 },
    options: {},
    canWrite: true,
    placement: null,
    photoRevision: 0,
};

test('Leave comparison rebases repeated conflicts onto fresh case revisions without discarding input', async () => {
    let writes = 0,
        reads = 0,
        saved = 0;
    globalThis.fetch = async (_input, options) => {
        if ((options?.method ?? 'GET') === 'GET') {
            reads++;
            return Response.json({
                ...workflowProps,
                revision: 3 + reads,
                values: { izin_gun_sayisi: 20 + reads },
            });
        }
        writes++;
        const payload = JSON.parse(String(options?.body));
        assert.equal(payload.revision, 2 + writes);
        assert.equal(payload.values.izin_gun_sayisi, 15);
        return writes < 3
            ? Response.json({ message: 'SENTETIK CONFLICT' }, { status: 409 })
            : Response.json({ revision: 6 });
    };
    await render(<LeaveEditor {...workflowProps} onSaved={() => saved++} />);
    await fill('#izin_gun_sayisi', '15');
    for (let index = 0; index < 2; index++) {
        await submitForm();
        await click('Güncel kayıtla karşılaştır');
        await selectMine('Gün sayısı');
        await click('Seçimleri forma aktar');
    }
    await submitForm();
    assert.equal(writes, 3);
    assert.equal(reads, 2);
    assert.equal(saved, 1);
});

test('Sentence comparison refreshes both identity and case revisions', async () => {
    const props = {
        ...workflowProps,
        workflow: 'infaz',
        fields: { infaz_uyap_no: { label: 'UYAP No', type: 'text' } },
        values: { infaz_uyap_no: 'Eski' },
    };
    let writes = 0,
        saved = 0;
    globalThis.fetch = async (_input, options) => {
        if ((options?.method ?? 'GET') === 'GET')
            return Response.json({
                ...props,
                revision: 8,
                person: { ...props.person, revision: 9, admitted_on: '2026-10-02' },
                values: { infaz_uyap_no: 'Güncel' },
            });
        writes++;
        if (writes === 1) return Response.json({ message: 'SENTETIK CONFLICT' }, { status: 409 });
        const payload = JSON.parse(String(options?.body));
        assert.equal(payload.revision, 8);
        assert.equal(payload.identity.revision, 9);
        assert.equal(payload.values.infaz_uyap_no, 'Benim');
        return Response.json({ revision: 10 });
    };
    await render(<SentenceEditor {...props} onSaved={() => saved++} />);
    await fill('#infaz_uyap_no', 'Benim');
    await submitForm();
    await click('Güncel kayıtla karşılaştır');
    await selectMine('UYAP No');
    await click('Seçimleri forma aktar');
    await submitForm();
    assert.equal(saved, 1);
});

test('Revoked write access blocks comparison and cannot unlock a workflow form', async () => {
    let reads = 0;
    globalThis.fetch = async (_input, options) => {
        if ((options?.method ?? 'GET') === 'GET') {
            reads++;
            return Response.json({ ...workflowProps, canWrite: false });
        }
        return Response.json({ message: 'SENTETIK CONFLICT' }, { status: 409 });
    };
    await render(<LeaveEditor {...workflowProps} />);
    await submitForm();
    await click('Güncel kayıtla karşılaştır');
    assert.equal(reads, 1);
    assert.match(document.body.textContent ?? '', /yazma yetkisi değişti/);
    assert.equal(document.querySelector('input[type=radio]'), null);
});

test('Status and housing comparisons keep the current revision for the explicit resubmission', async () => {
    for (const kind of ['status', 'housing']) {
        if (root) {
            await act(() => root!.unmount());
            root = undefined;
        }
        let writes = 0;
        const row = {
            id: 1,
            revision: 3,
            code: 'synthetic',
            label: 'Eski',
            name: 'Eski',
            is_active: true,
            included_in_active_list: true,
            counts_room: false,
            reserves_bed: false,
            usage_count: 0,
        };
        globalThis.fetch = async (_input, options) => {
            if ((options?.method ?? 'GET') === 'GET')
                return Response.json(
                    kind === 'status'
                        ? { rows: [{ ...row, label: 'Güncel', revision: 4 }] }
                        : { blocks: [{ ...row, name: 'Güncel', revision: 4 }], rooms: [] },
                );
            writes++;
            if (writes === 1) return Response.json({ message: 'SENTETIK CONFLICT' }, { status: 409 });
            const payload = JSON.parse(String(options?.body));
            assert.equal(payload.revision, 4);
            assert.equal(payload[kind === 'status' ? 'label' : 'name'], 'Benim');
            return Response.json({ id: 1 });
        };
        await render(
            kind === 'status' ? (
                <StatusEditor row={row} baseUrl="/synthetic" onBusy={() => {}} onSaved={() => {}} />
            ) : (
                <HousingForm
                    row={row}
                    room={false}
                    blocks={[]}
                    baseUrl="/synthetic"
                    onBusy={() => {}}
                    onSaved={() => {}}
                />
            ),
        );
        await fill(kind === 'status' ? '#status-label' : '#block-name', 'Benim');
        await submitForm();
        await click('Güncel kayıtla karşılaştır');
        await selectMine(kind === 'status' ? 'Durum adı' : 'Ad');
        await click('Seçimleri forma aktar');
        await submitForm();
        assert.equal(writes, 2);
    }
});

test('Command palette handles keyboard search and Escape without a draft warning or invented write action', async () => {
    await render(
        <>
            <main id="main-content">
                <input data-portal-command="search" />
                <button data-portal-command="new-record" disabled>
                    Yetkisiz yeni kayıt
                </button>
            </main>
            <CommandPalette
                navigation={[
                    { label: 'İzin kayıtları', url: '/izin' },
                    { label: 'Dış bağlantı', url: 'https://example.org' },
                ]}
            />
        </>,
    );
    await act(() => {
        window.dispatchEvent(
            new dom.window.KeyboardEvent('keydown', { key: 'k', ctrlKey: true, bubbles: true }),
        );
    });
    assert.ok(document.querySelector('dialog[open]'));
    assert.match(document.body.textContent ?? '', /Mevcut listede ara/);
    assert.ok(!document.querySelector('#command-results')!.textContent?.includes('Yeni kayıt ekle'));
    await fill('#command-search', 'İZİN');
    assert.equal(document.querySelectorAll('#command-results a').length, 1);
    await act(() => {
        document
            .querySelector('dialog')!
            .dispatchEvent(new dom.window.Event('cancel', { bubbles: true, cancelable: true }));
    });
    assert.equal(document.querySelector('dialog[open]'), null);
    assert.equal(document.querySelector('[role=alertdialog]'), null);
});

test('Batch access edits keep drafts but invalidate confirmation and update submitted selections', async () => {
    const row = (id: number): AccessRow => ({
        id,
        revision: 1,
        name: `Page ${id}`,
        code: `page_${id}`,
        legacy_file: null,
        path: null,
        is_active: true,
        permission_count: 0,
        open_url: null,
        rules: [],
        personal_permission: null,
        effective: { giris: false, yazma: false, sources: [] },
    });
    let body: { pages: { id: number; revision: number }[]; reason: string; confirmed: boolean } | undefined;
    globalThis.fetch = async (_url, init) => {
        body = JSON.parse(String(init?.body));
        return Response.json({ saved: true });
    };
    await render(<PageAccessTable rows={[row(1), row(2)]} batchUrl="/synthetic" onSaved={async () => {}} />);
    await act(() => document.querySelector<HTMLInputElement>('[aria-label="Page 1 seç"]')!.click());
    await fill('input[maxlength="500"]', 'Korunacak gerekçe');
    const confirmation = () => document.querySelector<HTMLInputElement>('input[type=checkbox][required]')!;
    await act(() => confirmation().click());
    assert.equal(confirmation().checked, true);
    await act(() => document.querySelector<HTMLInputElement>('[aria-label="Page 2 seç"]')!.click());
    assert.equal(
        document.querySelector<HTMLInputElement>('input[maxlength="500"]')!.value,
        'Korunacak gerekçe',
    );
    assert.equal(confirmation().checked, false);
    await act(() =>
        root!.render(
            <PageAccessTable
                rows={[{ ...row(1), revision: 4 }, row(2)]}
                batchUrl="/synthetic"
                onSaved={async () => {}}
            />,
        ),
    );
    assert.equal(
        document.querySelector<HTMLInputElement>('input[maxlength="500"]')!.value,
        'Korunacak gerekçe',
    );
    assert.equal(confirmation().checked, false);
    await act(() => confirmation().click());
    await act(() =>
        document
            .querySelector('form')!
            .dispatchEvent(new dom.window.Event('submit', { bubbles: true, cancelable: true })),
    );
    assert.deepEqual(body?.pages, [
        { id: 1, revision: 4 },
        { id: 2, revision: 1 },
    ]);
    assert.equal(body?.reason, 'Korunacak gerekçe');
});

test('Istanbul calendar dates and timestamps preserve day boundaries', () => {
    assert.equal(todayInIstanbul(new Date('2026-10-18T22:30:00Z')), '2026-10-19');
    assert.equal(formatDate('2026-10-18'), '18.10.2026');
    assert.ok(formatDateTime('2026-10-18T22:30:00Z').includes('19.10.2026'));
    assert.equal(formatDateTime('invalid'), '—');
});

test('Transport decodes XSRF and leaves multipart content type to the browser', async () => {
    document.cookie = 'XSRF-TOKEN=encoded%2Btoken; path=/';
    let headers: Headers | undefined;
    globalThis.fetch = async (_url, init) => {
        headers = new Headers(init?.headers);
        return Response.json({});
    };
    await portalFetch('/synthetic', {
        method: 'POST',
        body: new FormData(),
        headers: { 'Content-Type': 'multipart/form-data' },
    });
    assert.equal(headers?.get('X-XSRF-TOKEN'), 'encoded+token');
    assert.equal(headers?.has('Content-Type'), false);
});

test('Login animation is eligible only on desktop without reduced motion', () => {
    const original = window.matchMedia;
    try {
        for (const [desktop, reduced, expected] of [
            [false, false, false],
            [true, true, false],
            [true, false, true],
        ]) {
            window.matchMedia = (query: string) =>
                ({ matches: query.includes('min-width') ? desktop : reduced }) as MediaQueryList;
            assert.equal(shouldLoadLoginAnimation(), expected);
        }
    } finally {
        window.matchMedia = original;
    }
});
