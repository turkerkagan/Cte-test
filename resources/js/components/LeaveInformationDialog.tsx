import PortalDialog from './PortalDialog';

export default function LeaveInformationDialog({
    values,
    onClose,
}: {
    values: Record<string, string>;
    onClose: () => void;
}) {
    function date(value?: string) {
        const parts = /^(\d{4})-(\d{2})-(\d{2})$/.exec(value ?? '');
        return parts ? `${parts[3]}.${parts[2]}.${parts[1]}` : (value ?? '');
    }
    function moment(day?: string, time?: string) {
        return [date(day), time?.slice(0, 5)].filter(Boolean).join(' ') || 'Belirtilmemiş';
    }
    return (
        <PortalDialog
            id="infoPopup"
            title="İzin Bilgileri"
            className="leave-information-dialog"
            onClose={onClose}
        >
            <div className="leave-information-content">
                <p>
                    <strong>Hükümlü:</strong> {values.full_name}{' '}
                    {values.tc_identity && `(${values.tc_identity})`}
                </p>
                <p>
                    <strong>İzin Türü:</strong> {values.izin_turu || 'Belirtilmemiş'}
                    {values.izin_gun_sayisi !== undefined &&
                        values.izin_gun_sayisi !== '' &&
                        ` (${values.izin_gun_sayisi} Gün)`}
                </p>
                <p>
                    <strong>Çıkış:</strong> {moment(values.izin_cikis_tarihi, values.izin_cikis_saati)}
                </p>
                <p>
                    <strong>Dönüş:</strong> {moment(values.izin_donus_tarihi, values.izin_donus_saati)}
                </p>
            </div>
        </PortalDialog>
    );
}
