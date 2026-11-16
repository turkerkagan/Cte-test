import { Head, router } from '@inertiajs/react';
import { useState } from 'react';
import AppShell from '../components/AppShell';
import InmateHoverCard from '../components/InmateHoverCard';
import { writeJson } from '../lib/workflow';
import { notify } from '../components/Toast';

type Person = {
    id: number;
    name: string;
    tc: string;
    block: string;
    room: string;
    bed: number | string | null;
    work: string;
};

type Room = {
    id: number;
    name: string;
    code: string;
    block: string;
    capacity: number;
    bedCount: number;
    revision: number;
    people: Person[];
};

type Props = {
    applicationName: string;
    navigation?: { label: string; url: string }[];
    title: string;
    baseUrl: string;
    logoutUrl: string;
    canWrite: boolean;
    rooms: Room[];
    unassigned: Person[];
};

export default function RoomLists(p: Props) {
    const [q, setQ] = useState('');
    const [infoOpen, setInfoOpen] = useState(false);
    const [editingRoomId, setEditingRoomId] = useState<number | null>(null);
    const [editName, setEditName] = useState('');
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState('');

    // Toplam istatistikler
    const totalRooms = p.rooms.length;
    const totalPeople = p.rooms.reduce((acc, r) => acc + r.people.length, 0);
    const totalCapacity = p.rooms.reduce((acc, r) => acc + Math.max(0, r.capacity || 0), 0);
    const emptyBeds = Math.max(0, totalCapacity - totalPeople);
    const occupancyPercent = totalCapacity > 0 ? Math.round((totalPeople / totalCapacity) * 100) : 0;

    async function saveRoomName(room: Room) {
        if (!editName.trim()) return;
        setBusy(true);
        setError('');
        try {
            const res = await writeJson(
                `${p.baseUrl}/${room.id}`,
                { name: editName, revision: room.revision },
                'PUT',
            );
            if (!res.ok) {
                setError(res.data.message ?? 'Koğuş adı kaydedilemedi.');
            } else {
                notify('Koğuş adı güncellendi.');
                setEditingRoomId(null);
                router.reload();
            }
        } catch (e) {
            setError(e instanceof Error ? e.message : 'Kaydedilemedi.');
        } finally {
            setBusy(false);
        }
    }

    const filteredRooms = p.rooms.filter((r) => {
        if (!q.trim()) return true;
        const searchTarget =
            `${r.block} ${r.code} ${r.name} ${r.people.map((x) => `${x.name} ${x.work}`).join(' ')}`.toLocaleLowerCase(
                'tr',
            );
        return searchTarget.includes(q.toLocaleLowerCase('tr'));
    });

    return (
        <AppShell
            applicationName={p.applicationName}
            navigation={p.navigation}
            pageTitle={p.title}
            toolbar={
                <div
                    className="cte-koguslar-toolbar-actions"
                    style={{ marginLeft: 'auto', display: 'flex', gap: '8px' }}
                >
                    <button
                        type="button"
                        className="cte-koguslar-info-btn button"
                        aria-expanded={infoOpen}
                        onClick={() => setInfoOpen(!infoOpen)}
                    >
                        <i className="fas fa-circle-info" aria-hidden="true" /> Bilgi
                    </button>
                    <a className="cte-koguslar-excel-btn button" href={`${p.baseUrl}/cikti`}>
                        <i className="fas fa-file-excel" aria-hidden="true" /> Excel İndir
                    </a>
                </div>
            }
            search={
                <div className="cte-dashboard-quick-search" style={{ width: '100%' }}>
                    <div className="cte-search-box-inner">
                        <i className="fas fa-search cte-search-icon" aria-hidden="true" />
                        <input
                            id="room-search-input"
                            type="search"
                            value={q}
                            onChange={(e) => setQ(e.target.value)}
                            placeholder="Koğuş adı, blok veya kişi ara..."
                            autoComplete="off"
                            spellCheck="false"
                        />
                        {q && (
                            <button
                                type="button"
                                className="cte-search-clear-btn"
                                title="Aramayı temizle"
                                onClick={() => setQ('')}
                            >
                                &times;
                            </button>
                        )}
                    </div>
                </div>
            }
        >
            <Head title={p.title} />

            <div className="cte-koguslar-container" id="cteKoguslarContainer">
                {error && (
                    <p role="alert" className="form-error" style={{ marginBottom: '10px' }}>
                        {error}
                    </p>
                )}

                <div className="cte-koguslar-toolbar">
                    <div>
                        <h1>Koğuşlar</h1>
                        <div className="cte-koguslar-summary">
                            {totalRooms} koğuş · {totalPeople} kişi
                        </div>
                    </div>
                </div>

                {/* BİLGİ ÖZET PANELİ (1:1 ESKİ CTE DÜZENİ) */}
                {infoOpen && (
                    <div className="cte-koguslar-info-panel">
                        <div>
                            <span>Toplam koğuş</span>
                            <strong>{totalRooms}</strong>
                        </div>
                        <div>
                            <span>Kayıtlı hükümlü</span>
                            <strong>{totalPeople}</strong>
                        </div>
                        <div>
                            <span>Toplam kapasite</span>
                            <strong>{totalCapacity}</strong>
                        </div>
                        <div>
                            <span>Boş ranza</span>
                            <strong>{emptyBeds}</strong>
                        </div>
                        <div>
                            <span>Doluluk</span>
                            <strong>%{occupancyPercent}</strong>
                        </div>
                    </div>
                )}

                {/* 4 SÜTUNLU KOMPAKT KOĞUŞ KART IZGARASI (1:1 ESKİ CTE DÜZENİ) */}
                <div className="cte-koguslar-grid">
                    {filteredRooms.map((room) => {
                        const displayName = room.name || `${room.block} Blok ${room.code}. Koğuş`;
                        const isEditing = editingRoomId === room.id;

                        return (
                            <article className="cte-kogus-card" key={room.id} data-room-id={room.id}>
                                <header>
                                    <div>
                                        <h2 className="room-name">{displayName}</h2>
                                        <small>
                                            {room.block} Blok · {room.code} Koğuş
                                        </small>
                                    </div>
                                    <span className="count">
                                        {room.people.length} / {Math.max(0, room.capacity || 0)}
                                    </span>
                                </header>

                                {room.people.length > 0 ? (
                                    <>
                                        <div className="cte-kogus-head">
                                            <span>Ranza</span>
                                            <span>Adı Soyadı</span>
                                            <span>Çalıştığı Yer</span>
                                        </div>
                                        <ul>
                                            {room.people.map((person) => (
                                                <li key={person.id}>
                                                    <span title={String(person.bed ?? '')}>
                                                        {person.bed ? `${person.bed}.` : '—'}
                                                    </span>
                                                    <strong>
                                                        <InmateHoverCard
                                                            person={{
                                                                full_name: person.name,
                                                                tc_identity: person.tc,
                                                                placement: {
                                                                    block: person.block,
                                                                    room: person.room,
                                                                    bed: person.bed,
                                                                    status: null,
                                                                },
                                                            }}
                                                        />
                                                    </strong>
                                                    <span title={person.work || 'Belirtilmemiş'}>
                                                        {person.work || '—'}
                                                    </span>
                                                </li>
                                            ))}
                                        </ul>
                                    </>
                                ) : (
                                    <div className="empty">Bu koğuşta kayıtlı kişi yok.</div>
                                )}

                                {p.canWrite && !isEditing && (
                                    <button
                                        type="button"
                                        className="edit-name"
                                        onClick={() => {
                                            setEditingRoomId(room.id);
                                            setEditName(room.name || displayName);
                                        }}
                                    >
                                        Adı düzenle
                                    </button>
                                )}

                                {p.canWrite && isEditing && (
                                    <form
                                        onSubmit={(e) => {
                                            e.preventDefault();
                                            void saveRoomName(room);
                                        }}
                                    >
                                        <input
                                            type="text"
                                            name="ad"
                                            maxLength={100}
                                            value={editName}
                                            onChange={(e) => setEditName(e.target.value)}
                                            required
                                            autoFocus
                                        />
                                        <button type="submit" disabled={busy}>
                                            Kaydet
                                        </button>
                                        <button
                                            type="button"
                                            disabled={busy}
                                            onClick={() => setEditingRoomId(null)}
                                        >
                                            Vazgeç
                                        </button>
                                    </form>
                                )}
                            </article>
                        );
                    })}
                </div>

                {/* ATANMAMIŞ KİŞİLER BİLDİRİMİ */}
                {p.unassigned.length > 0 && (
                    <div style={{ marginTop: '20px' }}>
                        <p
                            className="cte-koguslar-summary"
                            style={{ color: 'var(--cte-danger, #a85f67)', fontWeight: 'bold' }}
                        >
                            Koğuş tanımlarında bulunmayan veya pasif koğuşta {p.unassigned.length} kişi var:
                        </p>
                        <div className="cte-kogus-card" style={{ maxWidth: '600px', marginTop: '8px' }}>
                            <div className="cte-kogus-head">
                                <span>Ranza</span>
                                <span>Adı Soyadı</span>
                                <span>Tanımsız Koğuş</span>
                            </div>
                            <ul>
                                {p.unassigned.map((x) => (
                                    <li key={x.id}>
                                        <span>{x.bed ? `${x.bed}.` : '—'}</span>
                                        <strong>{x.name}</strong>
                                        <span>
                                            {`${x.block ?? ''} ${x.room ?? ''}`.trim() || 'Atanmamış'}
                                        </span>
                                    </li>
                                ))}
                            </ul>
                        </div>
                    </div>
                )}
            </div>
        </AppShell>
    );
}
