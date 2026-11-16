export type RecordValue = string | number | boolean | null | number[];
export type RecordValues = Record<string, RecordValue>;
export type ConflictChoice = 'mine' | 'current' | '';

function same(left: RecordValue | undefined, right: RecordValue | undefined) {
    const normalize = (value: RecordValue | undefined) =>
        Array.isArray(value) ? [...value].sort((a, b) => a - b) : (value ?? '');
    return JSON.stringify(normalize(left)) === JSON.stringify(normalize(right));
}

export function recordChoices(
    base: RecordValues,
    mine: RecordValues,
    current: RecordValues,
): Record<string, ConflictChoice> {
    return Object.fromEntries(
        Object.keys(current).map((key) => [
            key,
            same(mine[key], current[key]) || same(mine[key], base[key])
                ? 'current'
                : same(current[key], base[key])
                  ? 'mine'
                  : '',
        ]),
    );
}

export function mergeRecordValues(
    mine: RecordValues,
    current: RecordValues,
    choices: Record<string, ConflictChoice>,
): RecordValues | null {
    if (Object.keys(current).some((key) => !choices[key])) return null;
    return Object.fromEntries(
        Object.keys(current).map((key) => [key, choices[key] === 'mine' ? mine[key] : current[key]]),
    );
}
