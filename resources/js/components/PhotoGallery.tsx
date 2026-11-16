import { useState } from 'react';
import PortalDialog from './PortalDialog';
import InmateHoverCard from './InmateHoverCard';
type Person = { id: number; name: string; tc: string | null; photo: string | null };
export default function PhotoGallery({
    people,
    total,
    canWrite,
    busy,
    error,
    onUpload,
    onCamera,
    onRemove,
    photoFilter,
    onFilter,
}: {
    people: Person[];
    total: number;
    canWrite: boolean;
    busy: boolean;
    error: string;
    onUpload: (person: Person, file: File) => void;
    onCamera: (id: number) => void;
    onRemove: (person: Person) => void;
    photoFilter: string;
    onFilter: (value: string) => void;
}) {
    const [personId, setPersonId] = useState<number | null>(null);
    const person = people.find((p) => p.id === personId) ?? null;
    const visible = people;
    return (
        <section className="cte-photo-gallery">
            <div className="row-actions">
                <h2>
                    Kişi Fotoğrafları <small>({total} kişi)</small>
                </h2>
                <select
                    aria-label="Fotoğraf durumuna göre filtrele"
                    value={photoFilter}
                    onChange={(e) => onFilter(e.target.value)}
                >
                    <option value="all">Tümü</option>
                    <option value="with">Fotoğrafı olanlar</option>
                    <option value="without">Fotoğrafı olmayanlar</option>
                </select>
            </div>
            <div className="gallery-grid">
                {visible.map((p) => (
                    <article className="photo-person-card" key={p.id}>
                        <h3>
                            <InmateHoverCard
                                person={{ full_name: p.name, tc_identity: p.tc, photoUrl: p.photo }}
                                onEdit={() => setPersonId(p.id)}
                            />
                        </h3>
                        <p>{p.tc ?? 'TC Yok'}</p>
                        <button
                            className="photo-card-image"
                            onClick={() => setPersonId(p.id)}
                            aria-label={p.name + ' fotoğrafını görüntüle'}
                        >
                            {p.photo ? (
                                <img src={p.photo} alt={p.name} loading="lazy" />
                            ) : (
                                <>
                                    <i className="fas fa-user" aria-hidden="true" />
                                    <em>Fotoğraf yok</em>
                                </>
                            )}
                        </button>
                        <button className="button button--primary" onClick={() => setPersonId(p.id)}>
                            {canWrite ? (p.photo ? 'Fotoğrafı Değiştir' : 'Fotoğraf Ekle') : 'Görüntüle'}
                        </button>
                    </article>
                ))}
            </div>
            {!visible.length && <p role="status">Bu filtreye uygun fotoğraf kaydı bulunamadı.</p>}
            {person && (
                <PortalDialog
                    className="photo-dialog"
                    title={person.photo ? 'Fotoğraf İşlemleri' : 'Fotoğraf Ekle'}
                    onClose={() => setPersonId(null)}
                    busy={busy}
                >
                    <section className="editor-panel">
                        <p className="photo-identity">
                            {person.name} · {person.tc}
                        </p>
                        <div className="photo-preview">
                            {person.photo ? (
                                <img src={person.photo} alt={person.name} />
                            ) : (
                                <i className="fas fa-user" aria-hidden="true" />
                            )}
                        </div>
                        {canWrite && (
                            <>
                                <label className="form-field">
                                    Fotoğraf yükle
                                    <input
                                        disabled={busy}
                                        type="file"
                                        accept="image/jpeg,image/png,image/webp,image/gif"
                                        onChange={(e) => {
                                            const file = e.target.files?.[0];
                                            if (file) onUpload(person, file);
                                        }}
                                    />
                                </label>
                                <div className="row-actions">
                                    <button
                                        className="button"
                                        disabled={busy}
                                        onClick={() => onCamera(person.id)}
                                    >
                                        Kamera ile Çek
                                    </button>
                                    {person.photo && (
                                        <button
                                            className="button button--danger"
                                            disabled={busy}
                                            onClick={() => onRemove(person)}
                                        >
                                            Fotoğrafı kaldır
                                        </button>
                                    )}
                                </div>
                            </>
                        )}
                        {error && (
                            <p className="form-error" role="alert">
                                {error}
                            </p>
                        )}
                    </section>
                </PortalDialog>
            )}
        </section>
    );
}
