import { useEffect, useRef, useState } from 'react';
import { createPortal } from 'react-dom';
import { compareNatural } from '../lib/table';
export type ColumnValue = { value: string; label: string; count?: number };
type Props = {
    label: string;
    selected?: string[];
    none?: boolean;
    values?: ColumnValue[];
    valuesUrl?: string;
    onApply: (values: string[] | undefined) => void;
    onSort?: (direction: 'asc' | 'desc') => void;
    onHide?: () => void;
};
export default function ColumnFilterMenu(p: Props) {
    const [open, setOpen] = useState(false);
    const [anchor, setAnchor] = useState({ left: 0, top: 0 });
    const [values, setValues] = useState<ColumnValue[]>(p.values ?? []);
    const [selected, setSelected] = useState<string[]>([]);
    const [search, setSearch] = useState('');
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState('');
    const panel = useRef<HTMLDivElement>(null);
    const trigger = useRef<HTMLButtonElement>(null);
    useEffect(() => {
        if (!open) return;
        const abort = new AbortController();
        setSearch('');
        setError('');
        function choose(items: ColumnValue[]) {
            setValues(
                [...items].sort((a, b) =>
                    compareNatural(a.value === '' ? null : a.label, b.value === '' ? null : b.label),
                ),
            );
            setSelected(
                p.none
                    ? []
                    : (p.selected?.filter((value) => items.some((item) => item.value === value)) ??
                          items.map((v) => v.value)),
            );
        }
        if (p.valuesUrl) {
            setBusy(true);
            fetch(p.valuesUrl, {
                headers: { Accept: 'application/json' },
                credentials: 'same-origin',
                signal: abort.signal,
            })
                .then(async (r) => {
                    const data = await r.json();
                    if (!r.ok) throw new Error(data.message ?? 'Değerler yüklenemedi.');
                    return data.values as ColumnValue[];
                })
                .then(choose)
                .catch((e) => {
                    if (e.name !== 'AbortError') setError(e.message);
                })
                .finally(() => {
                    if (!abort.signal.aborted) setBusy(false);
                });
        } else choose(p.values ?? []);
        function escape(e: KeyboardEvent) {
            if (e.key === 'Escape') {
                setOpen(false);
                trigger.current?.focus();
            }
        }
        function outside(e: PointerEvent) {
            if (!panel.current?.contains(e.target as Node) && !trigger.current?.contains(e.target as Node))
                setOpen(false);
        }
        document.addEventListener('keydown', escape);
        document.addEventListener('pointerdown', outside);
        return () => {
            abort.abort();
            document.removeEventListener('keydown', escape);
            document.removeEventListener('pointerdown', outside);
        };
    }, [open, p.valuesUrl]);
    return (
        <>
            <button
                data-portal-command="filters"
                ref={trigger}
                className="cte-column-menu-trigger"
                type="button"
                aria-label={p.label + ' filtresi'}
                aria-haspopup="dialog"
                aria-expanded={open}
                onClick={(e) => {
                    const rect = e.currentTarget.getBoundingClientRect();
                    setAnchor({
                        left: Math.max(8, Math.min(rect.left, window.innerWidth - 330)),
                        top: Math.max(8, Math.min(rect.bottom + 4, window.innerHeight - 420)),
                    });
                    setOpen(!open);
                }}
            >
                <i
                    className={'fas fa-filter' + (p.selected || p.none ? ' is-filtered' : '')}
                    aria-hidden="true"
                />
            </button>
            {open &&
                createPortal(
                    <div
                        ref={panel}
                        role="dialog"
                        aria-label={p.label + ' sütun işlemleri'}
                        className="cte-column-menu react-column-menu"
                        style={{ position: 'fixed', left: anchor.left, top: anchor.top, zIndex: 100100 }}
                    >
                        <h3>{p.label}</h3>
                        {p.onSort && (
                            <div className="cte-column-sort-actions">
                                <button
                                    className="button"
                                    onClick={() => {
                                        p.onSort?.('asc');
                                        setOpen(false);
                                    }}
                                >
                                    <i className="fas fa-arrow-down-a-z" /> A'dan Z'ye Sırala
                                </button>
                                <button
                                    className="button"
                                    onClick={() => {
                                        p.onSort?.('desc');
                                        setOpen(false);
                                    }}
                                >
                                    <i className="fas fa-arrow-down-z-a" /> Z'den A'ya Sırala
                                </button>
                            </div>
                        )}
                        <input
                            aria-label="Değerlerde ara"
                            type="search"
                            placeholder="Değerlerde ara…"
                            value={search}
                            onChange={(e) => setSearch(e.target.value)}
                        />
                        <div className="row-actions">
                            <button
                                className="button"
                                disabled={busy}
                                onClick={() => setSelected(values.map((v) => v.value))}
                            >
                                Tümünü Seç
                            </button>
                            <button className="button" disabled={busy} onClick={() => setSelected([])}>
                                Temizle
                            </button>
                        </div>
                        {busy ? (
                            <p role="status">Değerler yükleniyor…</p>
                        ) : (
                            <div className="cte-column-values">
                                {values
                                    .filter((v) =>
                                        v.label
                                            .toLocaleLowerCase('tr')
                                            .includes(search.toLocaleLowerCase('tr')),
                                    )
                                    .map((v) => (
                                        <label key={v.value} className="checkbox-field">
                                            <input
                                                type="checkbox"
                                                checked={selected.includes(v.value)}
                                                onChange={(e) =>
                                                    setSelected(
                                                        e.target.checked
                                                            ? [...selected, v.value]
                                                            : selected.filter((s) => s !== v.value),
                                                    )
                                                }
                                            />
                                            <span>{v.label}</span>
                                            {v.count !== undefined && <small>{v.count}</small>}
                                        </label>
                                    ))}
                            </div>
                        )}
                        {error && (
                            <p className="form-error" role="alert">
                                {error}
                            </p>
                        )}
                        <footer className="cte-column-menu-footer">
                            <button
                                className="button button--primary"
                                disabled={busy || !!error}
                                onClick={() => {
                                    p.onApply(selected.length === values.length ? undefined : selected);
                                    setOpen(false);
                                }}
                            >
                                Uygula
                            </button>
                            <button
                                className="button"
                                onClick={() => {
                                    p.onApply(undefined);
                                    setOpen(false);
                                }}
                            >
                                Filtreyi Kaldır
                            </button>
                            {p.onHide && (
                                <button
                                    className="button"
                                    onClick={() => {
                                        p.onHide?.();
                                        setOpen(false);
                                    }}
                                >
                                    Sütunu Gizle
                                </button>
                            )}
                            <button className="button" onClick={() => setOpen(false)}>
                                Kapat
                            </button>
                        </footer>
                    </div>,
                    document.body,
                )}
        </>
    );
}
