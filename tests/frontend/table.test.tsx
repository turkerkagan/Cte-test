import assert from 'node:assert/strict';
import test from 'node:test';
import { renderToStaticMarkup } from 'react-dom/server';
import DataTable from '../../resources/js/components/DataTable';
import {
    compareNatural,
    personColumns,
    searchName,
    sortRows,
    standardColumns,
    tableCharacterLimits,
    wrapTableText,
    type Column,
    type PersonIdentity,
} from '../../resources/js/lib/table';
import EmptyRoomPicker from '../../resources/js/components/EmptyRoomPicker';
import legacyContracts from './fixtures/legacy-table-contracts.json';
import { mergeRecordValues, recordChoices } from '../../resources/js/lib/recordConflict';

test('Record comparison preserves disjoint changes without mutating either draft', () => {
    const base = { '1': 'old', '2': 'base' },
        mine = { '1': 'mine', '2': 'base' },
        current = { '1': 'old', '2': 'remote' };
    const choices = recordChoices(base, mine, current);
    assert.deepEqual(choices, { '1': 'mine', '2': 'current' });
    assert.deepEqual(mergeRecordValues(mine, current, choices), { '1': 'mine', '2': 'remote' });
    assert.equal(mine['2'], 'base');
    assert.equal(current['1'], 'old');
});

test('Overlapping record changes require an explicit selection before rebasing', () => {
    const base = { '1': 'old' },
        mine = { '1': 'mine' },
        current = { '1': 'remote' };
    const choices = recordChoices(base, mine, current);
    assert.equal(choices['1'], '');
    assert.equal(mergeRecordValues(mine, current, choices), null);
    assert.deepEqual(mergeRecordValues(mine, current, { '1': 'mine' }), mine);
    assert.deepEqual(mergeRecordValues(mine, current, { '1': 'current' }), current);
});

test('Comparison treats multiselect order as equivalent and identical concurrent edits as resolved', () => {
    assert.deepEqual(
        recordChoices({ '1': [1, 2], '2': false }, { '1': [2, 1], '2': true }, { '1': [1, 2], '2': true }),
        { '1': 'current', '2': 'current' },
    );
});

type Row = PersonIdentity & { note?: string; score?: number | null; enabled?: boolean };
const records: Row[] = [
    { recordId: 98771, tc: '00000000001', fullName: 'İlker IŞIK', score: 10 },
    { recordId: 33221, tc: '00000000002', fullName: 'Ayşe Çelik', score: 2 },
    { recordId: 55119, tc: null, fullName: null, score: null },
];
const columns = personColumns<Row>([
    { key: 'score', label: 'Puan', value: (row) => row.score, sortable: true },
]);

test('Numeric labels grow naturally including negative and decimal values', () => {
    assert.deepEqual(['24', '10', '2', '1'].sort(compareNatural), ['1', '2', '10', '24']);
    assert.deepEqual(['Koğuş 24', 'Koğuş 10', 'Koğuş 2', 'Koğuş 1'].sort(compareNatural), [
        'Koğuş 1',
        'Koğuş 2',
        'Koğuş 10',
        'Koğuş 24',
    ]);
    assert.deepEqual(['10', '2.5', '-2', '0', '-10'].sort(compareNatural), ['-10', '-2', '0', '2.5', '10']);
});

test('Empty room picker orders blocks, rooms and beds and selects the lowest available bed', () => {
    const catalog = {
        blocks: [
            { id: 3, name: 'B' },
            { id: 1, name: 'A' },
        ],
        rooms: [24, 10, 2, 1].map((id) => ({
            id,
            block_id: 1,
            name: String(id),
            capacity: 24,
            bed_count: 24,
        })),
        beds: [24, 10, 2, 1].flatMap((room) =>
            [24, 10, 2, 1].map((number) => ({
                id: room * 100 + number,
                room_id: room,
                label: String(number),
                available: number !== 1,
            })),
        ),
    };
    const html = renderToStaticMarkup(
        <EmptyRoomPicker catalog={catalog} occupancy={[]} canSelect onSelect={() => {}} />,
    );
    const rooms = [...html.matchAll(/aria-label="A blok (\d+) koğuş, (\d+) ranza seç"/g)].map((match) => [
        match[1],
        match[2],
    ]);
    assert.deepEqual(rooms, [
        ['1', '2'],
        ['2', '2'],
        ['10', '2'],
        ['24', '2'],
    ]);
    assert.equal((html.match(/Boş Ranza: 2, 10, 24/g) ?? []).length, 4);
    assert.deepEqual(
        catalog.rooms.map((room) => room.name),
        ['24', '10', '2', '1'],
    );
});

test('Turkish name search preserves I/İ semantics and normalized Unicode', () => {
    assert.equal(searchName('İlker IŞIK', ' ilker '), true);
    assert.equal(searchName('İlker IŞIK', 'ışık'), true);
    assert.equal(searchName('İlker IŞIK', 'isik'), false);
    assert.equal(searchName('İlker', 'I\u0307lker'), true);
    assert.equal(searchName(null, ''), true);
    assert.equal(searchName(null, 'Ali'), false);
});

test('Sorting is numeric and stable, leaves empty values last and does not mutate source', () => {
    const original = [...records];
    assert.deepEqual(
        sortRows(records, columns, { key: 'score', direction: 'asc' }).map((row) => row.recordId),
        [33221, 98771, 55119],
    );
    assert.deepEqual(
        sortRows(records, columns, { key: 'score', direction: 'desc' }).map((row) => row.recordId),
        [98771, 33221, 55119],
    );
    assert.deepEqual(records, original);
    const equal = [{ ...records[0], score: 2 }, records[1]];
    assert.deepEqual(sortRows(equal, columns, { key: 'score', direction: 'asc' }), equal);
});

test('Visible sequence is recalculated after filtering and sorting, never database ID', () => {
    const visible = sortRows(
        records.filter((row) => searchName(row.fullName, 'çelik')),
        columns,
        { key: 'fullName', direction: 'asc' },
    );
    const html = renderToStaticMarkup(
        <DataTable rows={visible} columns={columns} rowKey={(row) => row.recordId} caption="Test" />,
    );
    assert.match(html, /cell--sequence"><span class="data-table__content">1<\/span><\/td>/);
    assert.doesNotMatch(html, /33221|98771/);
    assert.match(html, /00000000002/);
});

test('Table safely displays zero, boolean and arbitrary text without interpreting HTML', () => {
    const row: Row = {
        recordId: 1,
        tc: '00000000000',
        fullName: '<script>alert(1)</script>',
        score: 0,
        enabled: false,
    };
    const extra = personColumns<Row>([
        { key: 'score', label: 'Puan', value: (item) => item.score },
        { key: 'enabled', label: 'Aktif', value: (item) => item.enabled },
    ]);
    const html = renderToStaticMarkup(
        <DataTable rows={[row]} columns={extra} rowKey={(item) => item.recordId} caption="Test" />,
    );
    assert.match(html, /&lt;script&gt;/);
    assert.doesNotMatch(html, /<script>/);
    assert.match(html, /data-table__content"[^>]*>0<\/span>/);
    assert.match(html, /data-table__content"[^>]*>Hayır<\/span>/);
});

test('Empty tables retain accessible headers without extra status or record footer by default', () => {
    const html = renderToStaticMarkup(
        <DataTable
            rows={[]}
            columns={personColumns<Row>()}
            rowKey={(row) => row.recordId}
            caption="Kişiler"
        />,
    );
    assert.match(html, /TC Kimlik/);
    assert.match(html, /Adı Soyadı/);
    assert.doesNotMatch(html, /data-table__empty|data-table__footer/);
    assert.match(html, /role="region".*tabindex="0"/);
});

test('Tables can explicitly enable empty status and record count when needed', () => {
    const html = renderToStaticMarkup(
        <DataTable
            rows={[]}
            columns={personColumns<Row>()}
            rowKey={(row) => row.recordId}
            caption="Kişiler"
            showEmptyMessage
            showRecordCount
            emptyMessage="Sonuç bulunamadı."
        />,
    );
    assert.match(html, /role="status"/);
    assert.match(html, /Sonuç bulunamadı\./);
    assert.match(html, /0 kayıt/);
});

test('Name cells expose shared hover triggers and preserve row activation without an actions column', () => {
    const readOnly = renderToStaticMarkup(
        <DataTable rows={records} columns={columns} rowKey={(row) => row.recordId} caption="Kişiler" />,
    );
    assert.match(readOnly, /<span class="record-name" tabindex="0">/);
    assert.doesNotMatch(readOnly, /role="tooltip"/);
    const editable = renderToStaticMarkup(
        <DataTable
            rows={records}
            columns={columns}
            rowKey={(row) => row.recordId}
            caption="Kişiler"
            onRowClick={() => {}}
        />,
    );
    assert.match(editable, /<button[^>]*class="record-name"/);
    assert.match(editable, /data-table__record-row/);
    assert.doesNotMatch(editable, /<th[^>]*>İşlem/);
    const disabled = renderToStaticMarkup(
        <DataTable
            rows={records.slice(0, 1)}
            columns={columns}
            rowKey={(row) => row.recordId}
            caption="Kişiler"
            onRowClick={() => {}}
            rowDisabled={() => true}
        />,
    );
    assert.match(disabled, /class="record-name" disabled=""/);
});

test('Identity aliases with custom keys receive canonical headings, ordering and an automatic ID', () => {
    const result = standardColumns([
        { key: 'person', label: 'Ad Soyad', value: () => 'Örnek Kişi' },
        { key: 'identity', label: 'T.C. Kimlik No', value: () => '00000000001' },
        { key: 'room', label: 'Koğuş Adı', value: () => 'A1' },
    ]);
    assert.deepEqual(
        result.map(({ label, kind }) => [label, kind]),
        [
            ['ID', 'sequence'],
            ['TC Kimlik', 'tc'],
            ['Adı Soyadı', 'name'],
            ['Koğuş Adı', undefined],
        ],
    );
});

test('Identity headings are first, canonical and automatic even when supplied in a different order', () => {
    const supplied: Column<Row>[] = [
        { key: 'select', label: 'Seç', value: () => false },
        { key: 'full_name', label: 'Ad soyad', value: (row) => row.fullName },
        { key: 'tc_identity', label: 'T.C. No', value: (row) => row.tc },
        { key: 'sequence', label: '#', value: () => null },
    ];
    assert.deepEqual(
        standardColumns(supplied).map((column) => column.label),
        ['ID', 'TC Kimlik', 'Adı Soyadı', 'Seç'],
    );
    assert.equal(supplied[0].key, 'select');
    assert.equal(supplied[1].label, 'Ad soyad');
    const html = renderToStaticMarkup(
        <DataTable
            rows={records}
            columns={supplied}
            rowKey={(row) => row.recordId}
            caption="Test"
            sequenceOffset={10000}
        />,
    );
    assert.match(html, /width:calc\(4ch \+ 12px\)/);
    assert.match(html, /width:calc\(11ch \+ 40px\)/);
    assert.doesNotMatch(html, /width:(400|600|800)/);
    assert.doesNotMatch(html, /aria-label="(ID|TC Kimlik|Adı Soyadı) sütun genişliği"/);
    assert.match(html, /title="10001"/);
});

test('Table wrapping counts Turkish letters and digits, preserves long values and wraps at word boundaries', () => {
    for (const [value, limit, expected] of [
        ['9999', tableCharacterLimits.sequence, '9999'],
        ['10000', tableCharacterLimits.sequence, '1000\n0'],
        ['12345678901', tableCharacterLimits.tc, '12345678901'],
        ['İĞÜŞÖÇ'.repeat(6), tableCharacterLimits.name, 'İĞÜŞÖÇ'.repeat(4) + 'İ\nĞÜŞÖÇİĞÜŞÖÇ'],
        ['A'.repeat(40), tableCharacterLimits.text, 'A'.repeat(40)],
        ['A'.repeat(41), tableCharacterLimits.text, 'A'.repeat(40) + '\nA'],
        ['ALİ '.repeat(10).trim(), tableCharacterLimits.name, 'ALİ ALİ ALİ ALİ ALİ ALİ\nALİ ALİ ALİ ALİ'],
    ] as const) {
        const wrapped = wrapTableText(value, limit);
        assert.equal(wrapped, expected);
        assert.ok(wrapped.split('\n').every((line) => Array.from(line).length <= limit));
        assert.equal(wrapped.replace(/\s/g, ''), value.replace(/\s/g, ''));
    }
    const note = 'Ü'.repeat(41);
    const html = renderToStaticMarkup(
        <DataTable
            rows={[{ ...records[0], note }]}
            columns={personColumns<Row>([{ key: 'note', label: note, value: (row) => row.note }])}
            rowKey={(row) => row.recordId}
            caption="Test"
        />,
    );
    assert.match(html, new RegExp(`title="${note}"`));
    assert.equal((html.match(new RegExp('Ü'.repeat(40) + '\nÜ', 'g')) ?? []).length, 2);
});

test('Live refresh reuses unchanged rows, replaces changed details and keeps server order', async () => {
    const { reconcileRows } = await import('../../resources/js/lib/useStableRows');
    const previous = [
        { id: 1, placement: { status: 'MEVCUT' } },
        { id: 2, placement: { status: 'ÖZEL İZİN' } },
    ];
    const same = structuredClone(previous);
    assert.equal(reconcileRows(previous, same), previous);
    const changed = [{ id: 2, placement: { status: 'TAHLİYE' } }, same[0]];
    const result = reconcileRows(previous, changed);
    assert.equal(result[0], changed[0]);
    assert.equal(result[1], previous[0]);
    assert.notEqual(result, previous);
    assert.equal(reconcileRows(previous, [same[1]])[0], previous[1]);
});

test('Module range filters preserve both bounds, zero and Unicode in output links', async () => {
    const { moduleQueryString } = await import('../../resources/js/lib/moduleQuery');
    const params = new URLSearchParams(
        moduleQueryString({
            q: 'İŞ & +',
            filter_field: null,
            filter_op: 'eq',
            filter_value: '',
            sort_field: null,
            sort_direction: 'asc',
            filters: [
                { field: 2, op: 'gte', value: '0' },
                { field: 2, op: 'lte', value: '10' },
                { field: 3, op: 'empty' },
            ],
        }),
    );
    assert.equal(params.get('q'), 'İŞ & +');
    assert.equal(params.get('filters[0][value]'), '0');
    assert.equal(params.get('filters[1][op]'), 'lte');
    assert.equal(params.get('filters[1][value]'), '10');
    assert.equal(params.get('filters[2][op]'), 'empty');
    assert.equal(params.has('filter_op'), false);
});

test('Record rows support keyboard activation while busy and report rows stay out of the tab order', () => {
    const html = renderToStaticMarkup(
        <DataTable
            rows={records}
            columns={columns}
            rowKey={(row) => row.recordId}
            caption="Kişiler"
            onRowClick={() => {}}
            rowLabel={(row) => `${row.fullName} kaydını aç`}
            rowDisabled={(row) => row.recordId === 33221}
        />,
    );
    assert.match(html, /<tr class="data-table__record-row" tabindex="0" aria-label="İlker IŞIK kaydını aç"/);
    assert.match(
        html,
        /<tr class="data-table__record-row" aria-label="Ayşe Çelik kaydını aç" aria-disabled="true"/,
    );
    assert.equal((html.match(/tabindex="0"/g) ?? []).length, 3); // Scroll region and two available records.
    const report = renderToStaticMarkup(
        <DataTable rows={records} columns={columns} rowKey={(row) => row.recordId} caption="Rapor" />,
    );
    assert.doesNotMatch(report, /data-table__record-row|<tr[^>]*tabindex/);
});

test('Only selected columns exist in DOM; no hidden placeholder column is reserved', () => {
    const html = renderToStaticMarkup(
        <DataTable
            rows={records}
            columns={personColumns<Row>()}
            rowKey={(row) => row.recordId}
            caption="Test"
        />,
    );
    assert.equal((html.match(/scope="col"/g) ?? []).length, 3);
    assert.doesNotMatch(html, /Puan/);
    assert.equal((html.match(/<col(?:\s[^>]*)?\/>/g) ?? []).length, 3);
});

test('Workflow query preserves nested filters, Unicode, explicit empty exclusions and selected columns', async () => {
    const { queryString } = await import('../../resources/js/lib/workflow');
    const params = new URLSearchParams(
        queryString({
            excluded: [],
            columns: ['tc_identity', 'full_name'],
            selections: { full_name: ['İŞ & +'], huk_meslek: [''], huk_ds_durum: ['0'] },
            selection_none: ['block'],
            filters: [
                { field: 'infaz_gelis_nedeni', op: 'in', value: [12, 13] },
                { field: 'full_name', op: 'contains', value: 'İŞ & +' },
            ],
            page: 2,
        }),
    );
    assert.equal(params.get('excluded'), '');
    assert.equal(params.get('filters[0][value][1]'), '13');
    assert.equal(params.get('filters[1][value]'), 'İŞ & +');
    assert.equal(params.get('columns[1]'), 'full_name');
    assert.equal(params.get('selections[full_name][0]'), 'İŞ & +');
    assert.equal(params.get('selections[huk_meslek][0]'), '');
    assert.equal(params.get('selections[huk_ds_durum][0]'), '0');
    assert.equal(params.get('selection_none[0]'), 'block');
});

test('Legacy unit and report header contracts match the original primary tables', async () => {
    const schema = (await import('../../resources/js/lib/legacyWorkflowColumns.json')).default;
    assert.deepEqual(Object.keys(schema).sort(), Object.keys(legacyContracts.workflows).sort());
    for (const [page, contract] of Object.entries(legacyContracts.workflows)) {
        const expected = (schema as Record<string, { key: string; label: string }[]>)[page];
        assert.deepEqual(
            expected.map((c) => c.label),
            contract.headers,
            page,
        );
    }
});

test('Unit defaults exclude extra backend fields and respect authorized or explicitly selected columns', async () => {
    const { workflowTable } = await import('../../resources/js/lib/workflowTable');
    const schema = (await import('../../resources/js/lib/legacyWorkflowColumns.json')).default;
    const fields = Object.fromEntries(schema.infaz.map((c) => [c.key, { label: c.key, type: 'text' }]));
    fields.infaz_dogum_tarihi = { label: 'Doğum Tarihi', type: 'date' };
    fields.status = { label: 'Durum', type: 'text' };
    const table = workflowTable('infaz', fields, {});
    assert.equal(table.orderedFields.filter(([key]) => !table.hidden.includes(key)).length, 13);
    assert.deepEqual(table.hidden, ['infaz_dogum_tarihi', 'status']);
    delete fields.infaz_not;
    assert.equal(
        workflowTable('infaz', fields, {}).orderedFields.some(([key]) => key === 'infaz_not'),
        false,
    );
    const report = workflowTable('custom_rapor', fields, { columns: ['age', 'full_name'] });
    assert.deepEqual(
        report.orderedFields.map(([key]) => key),
        ['age', 'full_name'],
    );
    assert.equal(workflowTable('izin_rapor', fields, {}).sequenceLabel, 'ID');
});

test('Count table separates all nine measures and preserves zero, missing sides and collisions', async () => {
    const { default: CountComparisonTable } =
        await import('../../resources/js/components/CountComparisonTable');
    const html = renderToStaticMarkup(
        <CountComparisonTable
            rows={[
                {
                    key: 'A1',
                    status: 'Tam uyum',
                    system: [{ room: 'A1', registered: 2, away: 0, ready: 2 }],
                    file: [{ room: 'A1', registered: 2, away: 0, ready: 2 }],
                    difference: { registered: 0, away: 0, ready: 0 },
                },
                {
                    key: 'A2',
                    status: 'Çakışan anahtar',
                    system: [],
                    file: [
                        { room: 'A2', registered: 4, away: 1, ready: 3 },
                        { room: 'A02', registered: 7, away: 0, ready: 7 },
                    ],
                    difference: [],
                },
            ]}
        />,
    );
    assert.match(html, /colSpan="3"/i);
    assert.match(html, /Kayıtlı Δ/);
    assert.match(html, /<td>0<\/td>/);
    assert.match(html, /title="A2">4/);
    assert.match(html, /title="A02">7/);
    assert.match(html, /<td>—<\/td>/);
});

test('Duty tables retain original headers even when empty and do not add action columns to inline schedules', async () => {
    const { default: DutyProgramTable } = await import('../../resources/js/components/DutyProgramTable');
    for (const [kind, labels] of [
        ['mudurnobet', ['Adı Soyadı', 'Nöbet Tarihi', 'Nöbet Günü', 'Vekil Nöbetçi']],
        ['infaznobet', ['Adı Soyadı', 'Nöbet Tarihi', 'Nöbet Günü']],
        [
            'vardiyanobet',
            ['Adı Soyadı', 'Gündüz Nöbet Tarihi', 'Gece Nöbet Tarihi', '1. Nöbet İzni', '2. Nöbet İzni'],
        ],
    ] as const) {
        const html = renderToStaticMarkup(
            <DutyProgramTable
                kind={kind}
                rows={[]}
                candidates={[]}
                canWrite
                busy={false}
                onSave={async () => true}
            />,
        );
        assert.deepEqual(
            [...html.matchAll(/<th>(.*?)<\/th>/g)].map((m) => m[1]),
            labels,
        );
    }
});

test('Board pairs name and title under the original ten commission headings', async () => {
    const { default: BoardTable } = await import('../../resources/js/components/BoardTable');
    const labels = legacyContracts.board.headers;
    const html = renderToStaticMarkup(
        <BoardTable
            rows={[
                {
                    id: 88,
                    values: { position: 1, discipline_top: 'TEST İSİM', discipline_bottom: 'TEST ÜNVAN' },
                },
            ]}
        />,
    );
    assert.deepEqual(
        [...html.matchAll(/<th(?: .*?)?>(.*?)<\/th>/g)].map((m) => m[1]),
        ['ID', ...labels],
    );
    const firstCell = html.match(/class="board-cell">([\s\S]*?)<\/div>/)?.[1] ?? '';
    assert.equal(firstCell.replace(/<[^>]*>/g, ''), 'TEST İSİMTEST ÜNVAN');
    assert.match(firstCell, /class="record-name"/);
    assert.doesNotMatch(html, /· Üst|· Alt/);
});

test('MESEM headers and percentages follow the original table while general statistics retain decision labels', async () => {
    const { default: StatisticsTables } = await import('../../resources/js/components/StatisticsTables');
    const expected = legacyContracts.mesemstat.headers;
    const work = [
        {
            place: 'TEST',
            total: 4,
            isyurtlari: 1,
            genel: 1,
            muhtelif: 1,
            kararsiz: 1,
            interviewed: 2,
            applications: 1,
            petitions: 1,
            decisions: { İŞYURTLARI: 1, 'GENEL BÜTÇE': 1, MUHTELİF: 1, KARARSIZ: 1 },
        },
    ];
    const html = renderToStaticMarkup(
        <StatisticsTables kind="mesemstat" work={work} sections={[]} total={4} />,
    );
    assert.deepEqual(
        [...html.matchAll(/<th\b[^>]*>(.*?)<\/th>/gs)].map((m) =>
            (m[1].match(/<span class="data-table__label">(.*?)<\/span>/s)?.[1] ?? '').trim(),
        ),
        ['ID', ...expected.slice(1)],
    );
    assert.match(html, /50\.0%/);
    const general = renderToStaticMarkup(
        <StatisticsTables kind="istatistik" work={work} sections={[]} total={4} />,
    );
    assert.match(general, /İŞYURTLARI/);
    assert.doesNotMatch(general, /Görüşülen|Başvuru|Dilekçe/);
});

import { workflowTable } from '../../resources/js/lib/workflowTable';
import BoardTable from '../../resources/js/components/BoardTable';
test('Admin field labels and extra fields preserve the legacy default column set', () => {
    const fields: any = {
        tc_identity: { label: 'TC', type: 'text' },
        full_name: { label: 'Adı Soyadı', type: 'text' },
        infaz_uyap_no: {
            label: 'Yönetici başlığı',
            type: 'text',
            admin_configured: true,
            table_visible: true,
            admin_position: 1,
        },
        infaz_ceza_yil: { label: 'Eski gizli sütun', type: 'text' },
        custom_new: {
            label: 'Yeni alan',
            type: 'text',
            admin_configured: true,
            table_visible: true,
            admin_position: 2,
        },
    };
    const table = workflowTable('infaz', fields, {} as any);
    assert.equal(table.orderedFields.find(([key]) => key === 'infaz_uyap_no')?.[1].label, 'Yönetici başlığı');
    assert.equal(table.hidden.includes('custom_new'), false);
    assert.equal(table.hidden.includes('infaz_ceza_yil'), true);
    assert.equal(table.hidden.includes('full_name'), false);
});
test('Board headers use configured columns and preserve escaped member values', () => {
    const html = renderToStaticMarkup(
        <BoardTable
            columns={[['new_board', 'Yeni kurul']]}
            rows={[
                {
                    id: 1,
                    values: {
                        position: 1,
                        new_board_top: '<script>sentetik</script>',
                        new_board_bottom: 'Üye',
                        discipline_top: 'ESKİ',
                    },
                },
            ]}
        />,
    );
    assert.match(html, /Yeni kurul/);
    assert.match(html, /&lt;script&gt;/);
    assert.doesNotMatch(html, /DİSİPLİN KURULU|ESKİ/);
});

test('Command navigation deduplicates safe authorized destinations and searches Turkish labels', async () => {
    const { navigationCommands, searchCommands } = await import('../../resources/js/lib/commandPalette');
    const commands = navigationCommands(
        [
            {
                label: 'Kurum',
                url: '#group',
                children: [
                    { label: 'İzin İşlemleri', url: '/izin' },
                    { label: 'Tekrar', url: 'http://localhost/izin' },
                ],
            },
            { label: 'Script', url: 'javascript:alert(1)' },
            { label: 'Dış', url: 'https://example.org' },
        ],
        'http://localhost',
    );
    assert.equal(commands.length, 1);
    assert.equal(searchCommands(commands, 'İZİN KURUM').length, 1);
    assert.equal(searchCommands(commands, 'olmayan').length, 0);
});
