// Cell controls keep their own action; activating a data cell opens its record.
export const rowControlSelector =
    'a, button, input, select, textarea, label, summary, [role="button"], [contenteditable="true"], [data-row-click-ignore]';

export function isRowControl(target: EventTarget | null): boolean {
    return target instanceof Element && !!target.closest(rowControlSelector);
}
