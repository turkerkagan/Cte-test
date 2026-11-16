import { router } from '@inertiajs/react';
import { useId, useRef, useState, type FormEvent } from 'react';
import { writeJson } from '../lib/workflow';
import AnnouncementCards, { type AnnouncementRow } from './AnnouncementCards';
import PortalDialog from './PortalDialog';
import { notify } from './Toast';

type Draft = { title: string; body: string };
const emptyDraft = (): Draft => ({ title: '', body: '' });

export default function AnnouncementBoard({
    rows,
    canWrite,
    baseUrl,
}: {
    rows: AnnouncementRow[];
    canWrite: boolean;
    baseUrl: string;
}) {
    const [create, setCreate] = useState<Draft>(emptyDraft);
    const [draft, setDraft] = useState<Draft>(emptyDraft);
    const [editing, setEditing] = useState<AnnouncementRow | null>(null);
    const [removing, setRemoving] = useState<AnnouncementRow | null>(null);
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState('');
    const [conflict, setConflict] = useState(false);
    const lock = useRef(false);

    async function mutate(payload: unknown, id?: number, afterSave?: () => void) {
        if (lock.current) return;
        lock.current = true;
        setBusy(true);
        setError('');
        try {
            const response = await writeJson(id ? `${baseUrl}/${id}` : baseUrl, payload, 'POST');
            if (!response.ok) {
                setError(
                    response.status === 409
                        ? 'Bu duyuru başka bir işlemde değişti. Güncel duyuruyu inceleyip yeniden açın.'
                        : response.data.message ||
                              Object.values(response.data.errors ?? {})
                                  .flat()
                                  .join(' ') ||
                              'İşlem tamamlanamadı.',
                );
                if (response.status === 409) setConflict(true);
                return;
            }
            // Keep the lock until new revisions and personal read status arrive.
            await new Promise<void>((resolve) => router.reload({ onFinish: () => resolve() }));
            afterSave?.();
            notify('İşlem tamamlandı.');
        } catch (cause) {
            setError(cause instanceof Error ? cause.message : 'İşlem tamamlanamadı. Girdileriniz korunuyor.');
        } finally {
            lock.current = false;
            setBusy(false);
        }
    }
    function edit(row: AnnouncementRow) {
        setEditing(row);
        setDraft({ title: String(row.values.title ?? ''), body: String(row.values.body ?? '') });
        setError('');
        setConflict(false);
    }
    const errorMessage = error && (
        <p className="form-error" role="alert">
            {error}
        </p>
    );
    return (
        <section className="cte-duyurular-container" aria-label="Duyurular">
            <header className="duyurular-header">
                <h1 className="duyurular-title">
                    <i className="fas fa-bullhorn" aria-hidden="true" /> Duyurular
                </h1>
                <button
                    type="button"
                    className="tumu-okundu-btn"
                    disabled={busy}
                    onClick={() => void mutate({ action: 'receipt', read: true })}
                >
                    Tümünü Okundu Olarak İşaretle
                </button>
            </header>
            {!editing && !removing && errorMessage}
            <AnnouncementCards
                rows={rows}
                canWrite={canWrite}
                busy={busy}
                onEdit={edit}
                onReceipt={(row) => void mutate({ action: 'receipt', id: row.id, read: !row.isRead })}
                onArchive={(row) => {
                    setRemoving(row);
                    setError('');
                    setConflict(false);
                }}
                baseUrl={baseUrl}
            />
            {canWrite && (
                <section className="duyuru-ekle-bolum">
                    <h2>
                        <i className="fas fa-bullhorn" aria-hidden="true" /> Yeni Duyuru Ekle (Yönetici)
                    </h2>
                    <AnnouncementForm
                        draft={create}
                        onChange={setCreate}
                        busy={busy}
                        button="Duyuru Ekle"
                        rows={4}
                        onSubmit={(event) => {
                            event.preventDefault();
                            void mutate({ values: create }, undefined, () => setCreate(emptyDraft()));
                        }}
                    />
                </section>
            )}
            {editing && (
                <PortalDialog
                    title="Duyuruyu Düzenle"
                    className="announcement-edit-dialog"
                    busy={busy}
                    onClose={() => {
                        setEditing(null);
                        setError('');
                    }}
                >
                    <div className="announcement-dialog-content">
                        {errorMessage}
                        {conflict && (
                            <>
                                <p role="status">
                                    Girdileriniz korunuyor. Güncel duyuruyu yeniden açmadan tekrar kayıt
                                    yapılamaz.
                                </p>
                                <button
                                    type="button"
                                    className="button"
                                    disabled={busy}
                                    onClick={() => {
                                        setBusy(true);
                                        router.reload({ onFinish: () => setBusy(false) });
                                    }}
                                >
                                    Güncel listeyi yükle
                                </button>
                            </>
                        )}
                        <AnnouncementForm
                            draft={draft}
                            onChange={setDraft}
                            busy={busy}
                            blocked={conflict}
                            rows={5}
                            button="Değişiklikleri Kaydet"
                            onCancel={() => {
                                setEditing(null);
                                setError('');
                            }}
                            onSubmit={(event) => {
                                event.preventDefault();
                                if (!conflict)
                                    void mutate(
                                        { revision: editing.revision, values: draft },
                                        editing.id,
                                        () => setEditing(null),
                                    );
                            }}
                        />
                    </div>
                </PortalDialog>
            )}
            {removing && (
                <PortalDialog
                    title="Duyuruyu Listeden Kaldır"
                    className="announcement-edit-dialog"
                    busy={busy}
                    onClose={() => {
                        setRemoving(null);
                        setError('');
                    }}
                    actions={
                        <>
                            <button
                                type="button"
                                className="button"
                                disabled={busy}
                                onClick={() => {
                                    setRemoving(null);
                                    setError('');
                                }}
                            >
                                İptal
                            </button>
                            <button
                                type="button"
                                className="button button--danger"
                                disabled={busy || conflict}
                                onClick={() =>
                                    void mutate(
                                        { action: 'archive', revision: removing.revision },
                                        removing.id,
                                        () => setRemoving(null),
                                    )
                                }
                            >
                                Aktif listeden kaldır
                            </button>
                        </>
                    }
                >
                    <div className="announcement-dialog-content">
                        {errorMessage}
                        <p>
                            <strong>{String(removing.values.title ?? '')}</strong> duyurusu aktif listeden
                            kaldırılacak.
                        </p>
                        {conflict && (
                            <>
                                <p role="status">
                                    Kayıt değişmiş. Güncel listeyi yükleyip işlemi yeniden açın.
                                </p>
                                <button
                                    type="button"
                                    className="button"
                                    disabled={busy}
                                    onClick={() => {
                                        setBusy(true);
                                        router.reload({ onFinish: () => setBusy(false) });
                                    }}
                                >
                                    Güncel listeyi yükle
                                </button>
                            </>
                        )}
                    </div>
                </PortalDialog>
            )}
        </section>
    );
}

function AnnouncementForm({
    draft,
    onChange,
    onSubmit,
    onCancel,
    busy,
    blocked = false,
    button,
    rows,
}: {
    draft: Draft;
    onChange: (draft: Draft) => void;
    onSubmit: (event: FormEvent) => void;
    onCancel?: () => void;
    busy: boolean;
    blocked?: boolean;
    button: string;
    rows: number;
}) {
    const id = useId();
    return (
        <form className="duyuru-ekle-form" onSubmit={onSubmit}>
            <fieldset disabled={busy}>
                <label htmlFor={`${id}-title`}>Başlık</label>
                <input
                    id={`${id}-title`}
                    type="text"
                    required
                    maxLength={255}
                    value={draft.title}
                    onChange={(event) => onChange({ ...draft, title: event.target.value })}
                />
                <label htmlFor={`${id}-body`}>İçerik</label>
                <textarea
                    id={`${id}-body`}
                    rows={rows}
                    required
                    maxLength={10000}
                    value={draft.body}
                    onChange={(event) => onChange({ ...draft, body: event.target.value })}
                />
            </fieldset>
            <div className="announcement-form-actions">
                {onCancel && (
                    <button type="button" className="button" disabled={busy} onClick={onCancel}>
                        İptal
                    </button>
                )}
                <button type="submit" disabled={busy || blocked}>
                    {busy ? 'Kaydediliyor…' : button}
                </button>
            </div>
        </form>
    );
}
