import { useEffect, useRef, useState } from 'react';
import {
    mergeRecordValues,
    recordChoices,
    type ConflictChoice,
    type RecordValue,
    type RecordValues,
} from '../lib/recordConflict';

export type ConflictSnapshot<T> = { values: RecordValues; metadata: T };
export default function ConflictReview<T>({
    base,
    draft,
    labels,
    load,
    apply,
    display,
}: {
    base: RecordValues;
    draft: RecordValues;
    labels: Record<string, string>;
    load: (signal: AbortSignal) => Promise<ConflictSnapshot<T>>;
    apply: (values: RecordValues, metadata: T) => void;
    display?: (key: string, value: RecordValue) => string;
}) {
    const [current, setCurrent] = useState<ConflictSnapshot<T> | null>(null),
        [choices, setChoices] = useState<Record<string, ConflictChoice>>({});
    const [busy, setBusy] = useState(false),
        [error, setError] = useState('');
    const request = useRef<AbortController | null>(null);
    useEffect(() => () => request.current?.abort(), []);
    async function compare() {
        if (busy) return;
        const controller = new AbortController();
        request.current = controller;
        setBusy(true);
        setError('');
        setCurrent(null);
        setChoices({});
        try {
            const latest = await load(controller.signal);
            if (controller.signal.aborted) return;
            if (
                JSON.stringify(Object.keys(latest.values).sort()) !== JSON.stringify(Object.keys(base).sort())
            )
                throw new Error(
                    'Düzenlenebilir alanlar veya yetkiler değişti. Taslağınızı koruyup güncel formu açın.',
                );
            setCurrent(latest);
            setChoices(recordChoices(base, draft, latest.values));
        } catch (cause) {
            if (!controller.signal.aborted)
                setError(cause instanceof Error ? cause.message : 'Güncel kayıt alınamadı.');
        } finally {
            if (!controller.signal.aborted) setBusy(false);
        }
    }
    const text = (key: string, value: RecordValue) =>
        display?.(key, value) ??
        (value === null || value === ''
            ? 'Boş'
            : typeof value === 'boolean'
              ? value
                  ? 'Evet'
                  : 'Hayır'
              : Array.isArray(value)
                ? value.join(', ')
                : String(value));
    return (
        <section className="editor-panel" aria-label="Kayıt çakışması karşılaştırması">
            <p role="alert">
                Kayıt değişti. Girdileriniz korunuyor; güncel değerleri karşılaştırıp seçiminizi forma
                aktarabilirsiniz.
            </p>
            <button type="button" className="button" disabled={busy} onClick={() => void compare()}>
                {busy ? 'Karşılaştırılıyor…' : 'Güncel kayıtla karşılaştır'}
            </button>
            {error && (
                <p role="alert" className="form-error">
                    {error}
                </p>
            )}
            {current && (
                <>
                    <div className="table-scroll">
                        <table className="portal-table">
                            <thead>
                                <tr>
                                    <th>Alan</th>
                                    <th>Benim girdim</th>
                                    <th>Güncel kayıt</th>
                                </tr>
                            </thead>
                            <tbody>
                                {Object.keys(current.values).map((key) => (
                                    <tr key={key}>
                                        <th scope="row">{labels[key] ?? key}</th>
                                        {(['mine', 'current'] as const).map((source) => (
                                            <td key={source}>
                                                <label>
                                                    <input
                                                        type="radio"
                                                        name={`review-${key}`}
                                                        aria-label={`${labels[key] ?? key} · ${source === 'mine' ? 'Benim girdim' : 'Güncel kayıt'}`}
                                                        checked={choices[key] === source}
                                                        onChange={() =>
                                                            setChoices((previous) => ({
                                                                ...previous,
                                                                [key]: source,
                                                            }))
                                                        }
                                                    />
                                                    {text(
                                                        key,
                                                        (source === 'mine' ? draft : current.values)[key],
                                                    )}
                                                </label>
                                            </td>
                                        ))}
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                    <button
                        type="button"
                        className="button"
                        disabled={Object.values(choices).some((choice) => !choice)}
                        onClick={() => {
                            const merged = mergeRecordValues(draft, current.values, choices);
                            if (merged) apply(merged, current.metadata);
                        }}
                    >
                        Seçimleri forma aktar
                    </button>
                </>
            )}
        </section>
    );
}
