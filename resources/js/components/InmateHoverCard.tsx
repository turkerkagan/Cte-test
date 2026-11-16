import { useState } from 'react';
import RecordHoverCard from './RecordHoverCard';

export type HoverPerson = {
    full_name: string | null;
    tc_identity: string | null;
    photoUrl?: string | null;
    placement?: {
        block: string | null;
        room: string | null;
        bed: string | number | null;
        status: string | null;
    } | null;
    release?: { ds: string | null; release: string | null };
    uyap?: string;
    sentence?: string;
};

export default function InmateHoverCard({
    person,
    onEdit,
    disabled = false,
}: {
    person: HoverPerson;
    onEdit?: () => void;
    disabled?: boolean;
}) {
    return (
        <RecordHoverCard name={person.full_name ?? ''} onActivate={onEdit} disabled={disabled}>
            {() => <PersonSummary key={person.photoUrl} person={person} />}
        </RecordHoverCard>
    );
}

function PersonSummary({ person }: { person: HoverPerson }) {
    const [photoFailed, setPhotoFailed] = useState(false);
    const date = (value: string | null) => (value ? value.split('-').reverse().join('-') : 'Belirtilmemiş');
    return (
        <>
            <div className="cte-hover-photo">
                {person.photoUrl && !photoFailed ? (
                    <img src={person.photoUrl} alt="Kişi fotoğrafı" onError={() => setPhotoFailed(true)} />
                ) : (
                    <i className="fas fa-user-circle" aria-hidden="true" />
                )}
            </div>
            <div>
                <strong>{person.full_name}</strong>
                <p>TC Kimlik: {person.tc_identity || 'Belirtilmemiş'}</p>
                {person.uyap && <p>UYAP No: {person.uyap}</p>}
                <p>
                    {person.placement?.block || '—'} Blok · {person.placement?.room || '—'}. Koğuş · Ranza:{' '}
                    {person.placement?.bed ?? '—'}
                </p>
                <span className="cte-status-badge">{person.placement?.status || 'Belirtilmemiş'}</span>
                {person.sentence && <p>Ceza: {person.sentence}</p>}
                {person.release && (
                    <div className="cte-hover-release">
                        <p>
                            <i className="fas fa-arrow-right-from-bracket" aria-hidden="true" /> Tahliye:{' '}
                            <b>{date(person.release.release)}</b>
                        </p>
                        <p>
                            <i className="fas fa-calendar-check" aria-hidden="true" /> DS Tahliye:{' '}
                            <b>{date(person.release.ds)}</b>
                        </p>
                    </div>
                )}
            </div>
        </>
    );
}
