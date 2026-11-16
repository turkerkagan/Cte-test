import PortalDialog from './PortalDialog';
import PhoneDirectory from './PhoneDirectory';
export default function FooterPhoneModal({ open, onClose }: { open: boolean; onClose: () => void }) {
    return open ? (
        <PortalDialog title="Telefon Rehberi" className="cte-tel-modal-box" onClose={onClose}>
            <PhoneDirectory compact />
        </PortalDialog>
    ) : null;
}
