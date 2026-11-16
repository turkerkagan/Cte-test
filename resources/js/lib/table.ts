import type { ReactNode } from 'react';
export type CellValue = string | number | boolean | null | undefined;
export type Sort = { key: string; direction: 'asc' | 'desc' } | null;
export type Column<Row> = {
    key: string;
    label: string;
    kind?: 'sequence' | 'tc' | 'name';
    value: (row: Row) => CellValue;
    sortable?: boolean;
    render?: (row: Row) => ReactNode;
};

export const tableCharacterLimits = { sequence: 4, tc: 11, name: 25, text: 40 } as const;
export const identityHeadings = { sequence: 'ID', tc: 'TC Kimlik', name: 'Adı Soyadı' } as const;

function identityKind(label: string): Column<unknown>['kind'] {
    const normalized = label
        .normalize('NFC')
        .toLocaleLowerCase('tr-TR')
        .replace(/[^a-zçğıöşü0-9]/g, '');
    if (['tckimlik', 'tckimlikno', 'tckimliknumarası', 'tcno'].includes(normalized)) return 'tc';
    if (['adısoyadı', 'adsoyad', 'adıvesoyadı'].includes(normalized)) return 'name';
    return undefined;
}

/** Wrap visible characters rather than approximating letter widths with CSS ch. */
export function wrapTableText(value: string, limit: number = tableCharacterLimits.text): string {
    const normalized = value.normalize('NFC');
    if (normalized.length <= limit) return normalized;
    return normalized
        .split('\n')
        .map((line) => {
            if (line.length <= limit) return line;
            const characters = Array.from(line);
            const lines: string[] = [];
            let start = 0;
            while (characters.length - start > limit) {
                const space = characters.slice(start, start + limit + 1).lastIndexOf(' ');
                const end = start + (space > 0 ? space : limit);
                lines.push(characters.slice(start, end).join(''));
                start = end + (characters[end] === ' ' ? 1 : 0);
            }
            lines.push(characters.slice(start).join(''));
            return lines.join('\n');
        })
        .join('\n');
}

/** Keep identity headings and their order consistent in lists, reports and dialogs. */
export function standardColumns<Row>(columns: readonly Column<Row>[]): Column<Row>[] {
    const normalized = columns.map((column) => {
        const kind =
            column.kind ??
            (column.key === 'sequence'
                ? 'sequence'
                : ['tc', 'tc_identity'].includes(column.key)
                  ? 'tc'
                  : ['full_name', 'fullName'].includes(column.key)
                    ? 'name'
                    : identityKind(column.label));
        return kind ? { ...column, kind, label: identityHeadings[kind] } : column;
    });
    if (
        normalized.some((column) => column.kind === 'tc' || column.kind === 'name') &&
        !normalized.some((column) => column.kind === 'sequence')
    ) {
        normalized.unshift({
            key: 'sequence',
            label: identityHeadings.sequence,
            kind: 'sequence',
            value: () => null,
        });
    }
    return [
        ...normalized.filter((column) => column.kind === 'sequence'),
        ...normalized.filter((column) => column.kind === 'tc'),
        ...normalized.filter((column) => column.kind === 'name'),
        ...normalized.filter((column) => !column.kind),
    ];
}

export function searchName(value: string | null, query: string): boolean {
    const normalize = (text: string) => text.normalize('NFC').toLocaleLowerCase('tr-TR');
    return normalize(value ?? '').includes(normalize(query.trim()));
}

const naturalCollator = new Intl.Collator('tr', { numeric: true, sensitivity: 'base' });
export function compareNatural(left: unknown, right: unknown): number {
    const leftEmpty = left === null || left === undefined || left === '';
    const rightEmpty = right === null || right === undefined || right === '';
    if (leftEmpty || rightEmpty) return Number(leftEmpty) - Number(rightEmpty);
    const numeric = (value: unknown) =>
        typeof value === 'number' || (typeof value === 'string' && /^[+-]?\d+(?:\.\d+)?$/.test(value));
    return numeric(left) && numeric(right)
        ? Number(left) - Number(right)
        : naturalCollator.compare(String(left), String(right));
}

export function sortRows<Row>(rows: readonly Row[], columns: readonly Column<Row>[], sort: Sort): Row[] {
    const column = columns.find((candidate) => candidate.key === sort?.key);
    if (!sort || !column?.sortable) return [...rows];
    return [...rows].sort((a, b) => {
        const left = column.value(a);
        const right = column.value(b);
        const leftEmpty = left === null || left === undefined || left === '';
        const rightEmpty = right === null || right === undefined || right === '';
        if (leftEmpty || rightEmpty) return Number(leftEmpty) - Number(rightEmpty);
        const comparison = compareNatural(left, right);
        return sort.direction === 'asc' ? comparison : -comparison;
    });
}

export type PersonIdentity = { recordId: string | number; tc: string | null; fullName: string | null };

export function personColumns<Row extends PersonIdentity>(extra: readonly Column<Row>[] = []): Column<Row>[] {
    return [
        { key: 'sequence', label: identityHeadings.sequence, kind: 'sequence', value: () => null },
        { key: 'tc', label: identityHeadings.tc, kind: 'tc', value: (row) => row.tc, sortable: true },
        {
            key: 'fullName',
            label: identityHeadings.name,
            kind: 'name',
            value: (row) => row.fullName,
            sortable: true,
        },
        ...extra,
    ];
}
