import { useState } from 'react';
import PersonNotes from './PersonNotes';
import PortalDialog from './PortalDialog';

export default function PrivateNoteModal({
    person,
    name,
    onClose,
    onActivity,
}: {
    person: number;
    name: string;
    onClose: () => void;
    onActivity?: (busy: boolean) => void;
}) {
    const [busy, setBusy] = useState(false);
    return (
        <PortalDialog
            id="privateNoteComposerModal"
            title={`Özel Not Ekle · ${name}`}
            className="private-note-dialog"
            onClose={onClose}
            busy={busy}
        >
            <p className="portal-dialog-message">
                Bu kayıt geçmişe yeni bir not olarak eklenir ve sonradan değiştirilmez.
            </p>
            <PersonNotes
                person={person}
                privateOnly
                onActivity={(active) => {
                    setBusy(active);
                    onActivity?.(active);
                }}
            />
        </PortalDialog>
    );
}
