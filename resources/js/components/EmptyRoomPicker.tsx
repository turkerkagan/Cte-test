import { compareNatural } from '../lib/table';
import type { HomeOptions } from './HomeRecordDialog';
export type RoomOccupancy = { id: number; present: number; available: number; capacity: number };

export default function EmptyRoomPicker({
    catalog,
    occupancy,
    canSelect,
    onSelect,
}: {
    catalog: Pick<HomeOptions, 'blocks' | 'rooms' | 'beds'>;
    occupancy: RoomOccupancy[];
    canSelect: boolean;
    onSelect: (block: number, room: number, bed: number) => void;
}) {
    const blocks = [...catalog.blocks]
        .sort((a, b) => compareNatural(a.name, b.name))
        .map((block) => ({
            block,
            rooms: catalog.rooms
                .filter((room) => room.block_id === block.id)
                .sort((a, b) => compareNatural(a.name, b.name))
                .map((room) => ({
                    room,
                    beds: catalog.beds
                        .filter((b) => b.room_id === room.id && (b.is_empty ?? b.available))
                        .sort((a, b) => compareNatural(a.label, b.label)),
                    counts: occupancy.find((o) => o.id === room.id),
                }))
                .filter((r) => r.beds.length > 0),
        }))
        .filter((b) => b.rooms.length > 0);
    return (
        <div className="empty-room-picker">
            {!blocks.length && <p>Şu anda boş koğuş bulunmamaktadır.</p>}
            {blocks.map(({ block, rooms }) => (
                <section className="empty-room-block" key={block.id}>
                    <header>
                        <h3>
                            <i className="fas fa-building" aria-hidden="true" /> {block.name} BLOK
                        </h3>
                        <span>Toplam Boş: {rooms.reduce((sum, r) => sum + r.beds.length, 0)}</span>
                    </header>
                    <div className="empty-room-cards">
                        {rooms.map(({ room, beds, counts }) => (
                            <button
                                type="button"
                                className="empty-room-card"
                                key={room.id}
                                disabled={!canSelect}
                                aria-label={`${block.name} blok ${room.name} koğuş, ${beds[0].label} ranza seç`}
                                onClick={() => onSelect(block.id, room.id, beds[0].id)}
                            >
                                <span>
                                    <strong>{room.name}</strong>
                                    <b>BOŞ: {beds.length}</b>
                                </span>
                                <small>
                                    Mevcut: {counts?.present ?? '—'} / Kapasite: {room.capacity}
                                </small>
                                <small className="empty-room-beds">
                                    Boş Ranza: {beds.map((b) => b.label).join(', ')}
                                </small>
                            </button>
                        ))}
                    </div>
                </section>
            ))}
        </div>
    );
}
