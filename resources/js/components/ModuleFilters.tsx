import { useEffect, useState } from 'react';
import type { ModuleFilter } from '../lib/moduleQuery';
type Field = { id: number; label: string; type: string };
type Props = {
    fields: Field[];
    filters: ModuleFilter[];
    options: Record<string, { id: number; label: string }[]>;
    onApply: (filters: ModuleFilter[]) => void;
};
const ranged = ['integer', 'decimal', 'date', 'datetime'];

export default function ModuleFilters({ fields, filters, options, onApply }: Props) {
    const [draft, setDraft] = useState(filters);
    useEffect(() => setDraft(filters), [JSON.stringify(filters)]);
    function change(field: number, op: ModuleFilter['op'], value: string) {
        setDraft((current) => [
            ...current.filter(
                (filter) =>
                    filter.field !== field ||
                    (filter.op !== op && !(value !== '' && (op === 'empty' || filter.op === 'empty'))),
            ),
            ...(value !== '' ? [{ field, op, value }] : []),
        ]);
    }
    const value = (id: number, op: string) =>
        draft.find((filter) => filter.field === id && filter.op === op)?.value ?? '';
    return (
        <details className="module-filters no-print">
            <summary>Alan filtreleri · sayı ve tarih aralıkları</summary>
            <form
                onSubmit={(event) => {
                    event.preventDefault();
                    onApply(draft);
                }}
            >
                <div className="module-filters__fields">
                    {fields.map((field) => (
                        <div className="form-field" key={field.id}>
                            <strong>{field.label}</strong>
                            {ranged.includes(field.type) ? (
                                <div className="module-filters__range">
                                    {(['gte', 'lte'] as const).map((op) => (
                                        <label key={op}>
                                            {op === 'gte' ? 'En az / başlangıç' : 'En çok / bitiş'}
                                            <input
                                                aria-label={`${field.label} ${op === 'gte' ? 'en az' : 'en çok'}`}
                                                type={
                                                    field.type === 'datetime'
                                                        ? 'datetime-local'
                                                        : field.type === 'date'
                                                          ? 'date'
                                                          : 'number'
                                                }
                                                step={field.type === 'decimal' ? 'any' : undefined}
                                                value={value(field.id, op)}
                                                onChange={(event) => change(field.id, op, event.target.value)}
                                            />
                                        </label>
                                    ))}
                                </div>
                            ) : field.type === 'boolean' || ['select', 'multiselect'].includes(field.type) ? (
                                <select
                                    aria-label={`${field.label} filtresi`}
                                    value={value(field.id, 'eq')}
                                    onChange={(event) => change(field.id, 'eq', event.target.value)}
                                >
                                    <option value="">Tümü</option>
                                    {field.type === 'boolean' ? (
                                        <>
                                            <option value="1">Evet</option>
                                            <option value="0">Hayır</option>
                                        </>
                                    ) : (
                                        (options[field.id] ?? []).map((option) => (
                                            <option key={option.id} value={option.id}>
                                                {option.label}
                                            </option>
                                        ))
                                    )}
                                </select>
                            ) : (
                                <input
                                    aria-label={`${field.label} içerir`}
                                    type="search"
                                    maxLength={255}
                                    value={value(field.id, 'contains')}
                                    onChange={(event) => change(field.id, 'contains', event.target.value)}
                                />
                            )}
                            <label className="checkbox-field">
                                <input
                                    type="checkbox"
                                    checked={draft.some(
                                        (filter) => filter.field === field.id && filter.op === 'empty',
                                    )}
                                    onChange={(event) =>
                                        change(field.id, 'empty', event.target.checked ? '1' : '')
                                    }
                                />
                                Boş değerler
                            </label>
                        </div>
                    ))}
                </div>
                <div className="row-actions">
                    <button className="button button--primary">Filtreleri uygula</button>
                    <button
                        type="button"
                        className="button"
                        onClick={() => {
                            setDraft([]);
                            onApply([]);
                        }}
                    >
                        Alan filtrelerini temizle
                    </button>
                </div>
            </form>
        </details>
    );
}
