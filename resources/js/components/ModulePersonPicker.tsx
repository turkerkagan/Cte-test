import { useState } from 'react';
export type ModulePerson = { id: number; tc_identity: string | null; full_name: string | null };
type Props = { url: string; person: ModulePerson | null; onChange: (person: ModulePerson | null) => void };
export default function ModulePersonPicker({ url, person, onChange }: Props) {
    const [query, setQuery] = useState('');
    const [people, setPeople] = useState<ModulePerson[]>([]);
    const [error, setError] = useState('');
    const [searched, setSearched] = useState(false);
    const [processing, setProcessing] = useState(false);
    async function search() {
        setProcessing(true);
        setError('');
        setPeople([]);
        setSearched(false);
        try {
            const endpoint = new URL(url, window.location.href);
            endpoint.searchParams.set('q', query);
            const response = await fetch(endpoint, {
                credentials: 'same-origin',
                headers: { Accept: 'application/json' },
                cache: 'no-store',
            });
            if (!response.headers.get('content-type')?.includes('application/json'))
                throw new Error('Oturumu kontrol edip yeniden deneyin.');
            const result = await response.json();
            if (!response.ok)
                throw new Error(
                    result.errors?.q?.join(' ') ??
                        'Kişi araması yapılamadı. Yetkinizi ve oturumu kontrol edin.',
                );
            setPeople(result.people);
            setSearched(true);
        } catch (error) {
            setError(error instanceof Error ? error.message : 'Kişi araması yapılamadı.');
        } finally {
            setProcessing(false);
        }
    }
    return (
        <div className="form-field">
            <label htmlFor="module-person-search">Kişi seçimi *</label>
            <input
                id="module-person-search"
                disabled={processing}
                maxLength={150}
                value={query}
                onKeyDown={(event) => {
                    if (event.key === 'Enter') {
                        event.preventDefault();
                        if (!processing && query.trim().length >= 2) void search();
                    }
                }}
                onChange={(event) => {
                    setQuery(event.target.value);
                    setPeople([]);
                    setSearched(false);
                    onChange(null);
                }}
                placeholder="En az iki karakter: ad soyad veya TC"
            />
            <div className="row-actions">
                <button
                    className="button"
                    type="button"
                    disabled={processing || query.trim().length < 2}
                    onClick={() => void search()}
                >
                    {processing ? 'Aranıyor…' : 'Kişi ara'}
                </button>
                {person && (
                    <button className="button" type="button" onClick={() => onChange(null)}>
                        Seçimi kaldır
                    </button>
                )}
            </div>
            {error && (
                <p className="form-error" role="alert">
                    {error}
                </p>
            )}
            {searched && people.length === 0 && (
                <p role="status" className="muted">
                    Bu aramayla kişi bulunamadı.
                </p>
            )}
            {people.length > 0 && (
                <>
                    <label htmlFor="module-person-results">Arama sonuçları</label>
                    <select
                        id="module-person-results"
                        value={person?.id ?? ''}
                        onChange={(event) =>
                            onChange(people.find((item) => item.id === Number(event.target.value)) ?? null)
                        }
                    >
                        <option value="">Kişi seçin</option>
                        {people.map((item) => (
                            <option key={item.id} value={item.id}>
                                {item.full_name ?? 'Adı boş'} · {item.tc_identity ?? 'TC boş'} · kişi kaydı{' '}
                                {item.id}
                            </option>
                        ))}
                    </select>
                    <p className="muted">
                        En fazla 25 sonuç gösterilir. Aynı ad veya TC'li kayıtlar ayrı kişilerdir; aramayı
                        daraltın.
                    </p>
                </>
            )}
            {person && (
                <p role="status">
                    Seçilen kişi: {person.full_name ?? 'Adı boş'} · {person.tc_identity ?? 'TC boş'}
                </p>
            )}
        </div>
    );
}
