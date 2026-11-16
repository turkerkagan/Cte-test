import { useEffect, useId, useRef, useState } from 'react';
import { writeJson } from '../lib/workflow';
import { portalUrl } from '../lib/portalUrl';
type Notes = {
    page: number;
    total: number;
    notes: { id: number; body: string; is_private: boolean; author_name: string; created_at: string }[];
    canWrite: boolean;
    canWritePrivate: boolean;
};

export default function PersonNotes({
    person,
    privateOnly = false,
    onActivity,
}: {
    person: number;
    privateOnly?: boolean;
    onActivity?: (busy: boolean) => void;
}) {
    const [data, setData] = useState<Notes | null>(null);
    const [body, setBody] = useState('');
    const [privateNote, setPrivate] = useState(privateOnly);
    const [request, setRequest] = useState(() => crypto.randomUUID());
    const [error, setError] = useState('');
    const [busy, setBusy] = useState(false);
    const [loading, setLoading] = useState(true);
    const read = useRef<AbortController | null>(null);
    const saving = useRef(false);
    const formId = useId();
    const base = portalUrl(`/kisiler/${person}/notlar`);
    const writable = privateOnly
        ? data?.canWritePrivate
        : privateNote
          ? data?.canWritePrivate
          : data?.canWrite;

    async function load(page = 1) {
        read.current?.abort();
        const abort = new AbortController();
        read.current = abort;
        setLoading(true);
        setError('');
        try {
            const response = await fetch(`${base}?page=${page}${privateOnly ? '&kind=private' : ''}`, {
                credentials: 'same-origin',
                headers: { Accept: 'application/json' },
                signal: abort.signal,
            });
            if (!response.ok) throw new Error('Notlar yüklenemedi.');
            const next: Notes = await response.json();
            if (abort.signal.aborted) return;
            setData(next);
            if (privateOnly || (!next.canWrite && next.canWritePrivate)) setPrivate(true);
        } catch (e) {
            if (!abort.signal.aborted) setError(e instanceof Error ? e.message : 'Notlar yüklenemedi.');
        } finally {
            if (!abort.signal.aborted) setLoading(false);
        }
    }
    useEffect(() => {
        setData(null);
        void load();
        return () => read.current?.abort();
    }, [person, privateOnly]);
    useEffect(() => {
        onActivity?.(busy);
    }, [busy, onActivity]);

    async function save() {
        if (saving.current || !writable) return;
        saving.current = true;
        setBusy(true);
        setError('');
        try {
            const response = await writeJson(
                base,
                { body, is_private: privateOnly || privateNote, request_id: request },
                'POST',
            );
            if (!response.ok) {
                setError(
                    Object.values(response.data.errors ?? {})
                        .flat()
                        .join(' ') ||
                        response.data.message ||
                        'Not kaydedilemedi.',
                );
                return;
            }
            setBody('');
            setRequest(crypto.randomUUID());
            await load();
        } catch (e) {
            setError(e instanceof Error ? e.message : 'Not kaydedilemedi. Girdileriniz korunuyor.');
        } finally {
            saving.current = false;
            setBusy(false);
        }
    }
    return (
        <section className="editor-panel person-notes-panel">
            <h2>{privateOnly ? 'Özel not geçmişi' : 'Kişi notları'}</h2>
            {loading && <p role="status">Notlar yükleniyor…</p>}
            {data?.notes.map((note) => (
                <article key={note.id}>
                    <strong>
                        {note.is_private ? 'Özel not' : 'Genel not'} · {note.author_name}
                    </strong>
                    <small> · {note.created_at}</small>
                    <p style={{ whiteSpace: 'pre-wrap' }}>{note.body}</p>
                </article>
            ))}
            {data && !loading && !data.notes.length && (
                <p className="muted">
                    {privateOnly ? 'Henüz özel not bulunmuyor.' : 'Henüz not bulunmuyor.'}
                </p>
            )}
            {data && data.total > 100 && (
                <div className="row-actions">
                    <button
                        className="button"
                        disabled={busy || loading || data.page <= 1}
                        onClick={() => void load(data.page - 1)}
                    >
                        Daha yeni notlar
                    </button>
                    <span>
                        {data.total} not · sayfa {data.page}
                    </span>
                    <button
                        className="button"
                        disabled={busy || loading || data.page * 100 >= data.total}
                        onClick={() => void load(data.page + 1)}
                    >
                        Daha eski notlar
                    </button>
                </div>
            )}
            {data && (privateOnly ? data.canWritePrivate : data.canWrite || data.canWritePrivate) && (
                <form
                    onSubmit={(e) => {
                        e.preventDefault();
                        void save();
                    }}
                >
                    <fieldset disabled={busy} className="person-notes-fields">
                        {!privateOnly && (
                            <div className="form-field">
                                <label htmlFor={formId + '-kind'}>Not türü</label>
                                <select
                                    id={formId + '-kind'}
                                    value={privateNote ? 'private' : 'general'}
                                    onChange={(e) => {
                                        setPrivate(e.target.value === 'private');
                                        setRequest(crypto.randomUUID());
                                    }}
                                >
                                    {data.canWrite && <option value="general">Genel not</option>}
                                    {data.canWritePrivate && <option value="private">Özel not</option>}
                                </select>
                            </div>
                        )}
                        <div className="form-field">
                            <label htmlFor={formId + '-body'}>{privateOnly ? 'Özel not' : 'Yeni not'}</label>
                            <textarea
                                id={formId + '-body'}
                                required
                                rows={privateOnly ? 8 : 4}
                                maxLength={privateOnly ? 5000 : 10000}
                                value={body}
                                onChange={(e) => {
                                    setBody(e.target.value);
                                    setRequest(crypto.randomUUID());
                                }}
                            />
                        </div>
                        <button disabled={!writable} className="button button--primary">
                            {busy ? 'Kaydediliyor…' : privateOnly ? 'Özel Notu Kaydet' : 'Not ekle'}
                        </button>
                    </fieldset>
                </form>
            )}
            {error && (
                <div role="alert">
                    <p className="form-error">{error}</p>
                    {!busy && (
                        <button className="button" onClick={() => void load(data?.page ?? 1)}>
                            Notları yeniden yükle
                        </button>
                    )}
                </div>
            )}
        </section>
    );
}
