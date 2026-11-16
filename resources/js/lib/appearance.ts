export type FontPreset = 'compact' | 'normal' | 'large';

export function fontPreset(): FontPreset {
    const stored = localStorage.getItem('cte-font-size');
    if (stored === 'compact' || stored === 'normal' || stored === 'large') return stored;
    return (
        ({ '11px': 'compact', '12px': 'normal', '13px': 'large' } as const)[
            localStorage.getItem('cte-table-font-size') ?? '11px'
        ] ?? 'compact'
    );
}

export function applyFontPreference() {
    const root = document.documentElement;
    const custom = Number(localStorage.getItem('cte-font-px'));
    if (Number.isInteger(custom) && custom >= 8 && custom <= 48) {
        root.style.fontSize = `${custom}px`;
        root.style.setProperty('--cte-table-font-size', `${Math.max(9, custom * 0.78)}px`);
        root.style.setProperty('--cte-th-font-size', `${Math.max(10, custom * 0.83)}px`);
        root.dataset.fontSize = 'custom';
        return;
    }
    root.style.removeProperty('font-size');
    root.style.removeProperty('--cte-table-font-size');
    root.style.removeProperty('--cte-th-font-size');
    root.dataset.fontSize = fontPreset();
}
