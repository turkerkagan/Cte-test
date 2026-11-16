import { useEffect, useRef, useState, type ReactNode } from 'react';

export type FilterChip = { key: string; label: string; onRemove: () => void };

export function ActiveFilters({
    chips,
    onClear,
    sorted = false,
    actions,
    emptyMessage = 'Filtre eklemek için bir sütun başlığındaki simgeyi kullanın.',
}: {
    chips: FilterChip[];
    onClear?: () => void;
    sorted?: boolean;
    actions?: ReactNode;
    emptyMessage?: string;
}) {
    return (
        <>
            {onClear && (
                <button
                    type="button"
                    className="cte-clear-all-filters cte-standard-clear-toggle"
                    disabled={!chips.length && !sorted}
                    onClick={onClear}
                    title="Filtreleri temizle"
                    aria-label="Filtreleri temizle"
                >
                    <i className="fas fa-filter-circle-xmark" aria-hidden="true" />
                </button>
            )}
            <div className="cte-active-filter-panel cte-portal-filter-panel" aria-live="polite">
                <div className="cte-filter-panel-title">
                    <i className="fas fa-filter" aria-hidden="true" /> <span>Aktif filtreler</span>
                </div>
                <div className="cte-active-filter-list">
                    {chips.length ? (
                        chips.map((chip) => (
                            <button
                                type="button"
                                className="filter-chip"
                                key={chip.key}
                                onClick={chip.onRemove}
                                aria-label={`${chip.label} filtresini kaldır`}
                            >
                                {chip.label} &times;
                            </button>
                        ))
                    ) : (
                        <span className="cte-filter-empty">{emptyMessage}</span>
                    )}
                </div>
            </div>
            {actions && <div className="cte-table-primary-actions">{actions}</div>}
        </>
    );
}

export function QuickSearch({
    value,
    onSearch,
    disabled = false,
    label = 'Adı Soyadı veya TC ile hızlı ara',
    placeholder = 'Hızlı Ad Soyad Ara...',
}: {
    value: string;
    onSearch: (query: string) => void;
    disabled?: boolean;
    label?: string;
    placeholder?: string;
}) {
    const [draft, setDraft] = useState(value);
    const callback = useRef(onSearch);
    callback.current = onSearch;
    const timer = useRef<ReturnType<typeof setTimeout> | undefined>(undefined);
    const submitted = useRef(value);
    useEffect(() => {
        setDraft(value);
        submitted.current = value;
    }, [value]);
    function submit(query: string) {
        clearTimeout(timer.current);
        if (disabled || query === submitted.current) return;
        submitted.current = query;
        callback.current(query);
    }
    useEffect(() => {
        if (disabled || draft === value) return;
        timer.current = setTimeout(() => submit(draft), 350);
        return () => clearTimeout(timer.current);
    }, [draft, value, disabled]);
    return (
        <form
            className="cte-dashboard-quick-search"
            role="search"
            onSubmit={(event) => {
                event.preventDefault();
                submit(draft);
            }}
        >
            <div className="cte-search-box-inner">
                <i className="fas fa-search cte-search-icon" aria-hidden="true" />
                <input
                    data-portal-command="search"
                    type="search"
                    maxLength={150}
                    value={draft}
                    onChange={(event) => setDraft(event.target.value)}
                    placeholder={placeholder}
                    autoComplete="off"
                    spellCheck={false}
                    aria-label={label}
                    disabled={disabled}
                />
                {draft && (
                    <button
                        type="button"
                        className="cte-search-clear-btn"
                        title="Aramayı temizle"
                        aria-label="Aramayı temizle"
                        disabled={disabled}
                        onClick={() => {
                            setDraft('');
                            submit('');
                        }}
                    >
                        &times;
                    </button>
                )}
            </div>
        </form>
    );
}
