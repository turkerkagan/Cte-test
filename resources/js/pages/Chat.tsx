import { Head, router } from '@inertiajs/react';
import { useCallback, useEffect, useRef, useState } from 'react';
import AppShell from '../components/AppShell';
import { writeJson } from '../lib/workflow';
import { notify } from '../components/Toast';

type Message = { id: number; sender_id: number; sender_name: string; body: string; created_at: string };
type Person = { id: number; name: string };
type Room = { id: number; name: string; owner_id: number | null; is_general: boolean };
type Feed = { canWrite: boolean; rooms: Room[]; users: Person[]; messages: Message[] };
type Props = Feed & {
    applicationName: string;
    navigation?: { label: string; url: string }[];
    baseUrl: string;
    logoutUrl: string;
    room: number | null;
    recipient: number | null;
    actorId: number;
};
type Action = {
    action: 'send' | 'create' | 'invite';
    body?: string;
    name?: string;
    members?: number[];
    room?: number | null;
    recipient?: number;
};

export default function Chat(p: Props) {
    return <ChatRoom key={`${p.room}:${p.recipient}`} {...p} />;
}

function MemberPicker({
    id,
    label,
    users,
    selected,
    onChange,
    disabled,
}: {
    id: string;
    label: string;
    users: Person[];
    selected: number[];
    onChange: (ids: number[]) => void;
    disabled: boolean;
}) {
    return (
        <label className="portal-chat__members" htmlFor={id}>
            {label}
            <select
                id={id}
                multiple
                disabled={disabled}
                value={selected.map(String)}
                onChange={(event) =>
                    onChange(Array.from(event.target.selectedOptions, (option) => Number(option.value)))
                }
            >
                {users.map((user) => (
                    <option key={user.id} value={user.id}>
                        {user.name}
                    </option>
                ))}
            </select>
        </label>
    );
}

function ChatRoom(p: Props) {
    const [feed, setFeed] = useState<Feed>(p);
    const [body, setBody] = useState('');
    const [name, setName] = useState('');
    const [newMembers, setNewMembers] = useState<number[]>([]);
    const [invitees, setInvitees] = useState<number[]>([]);
    const [userSearch, setUserSearch] = useState('');
    const [error, setError] = useState('');
    const [busy, setBusy] = useState(false);
    const lock = useRef(false);
    const request = useRef<AbortController | null>(null);
    const messageList = useRef<HTMLElement | null>(null);
    const nearBottom = useRef(true);
    const query = p.recipient ? `recipient=${p.recipient}` : `room=${p.room ?? ''}`;

    useEffect(() => {
        setFeed(p);
    }, [p.messages, p.rooms, p.users, p.canWrite]);
    useEffect(() => {
        if (nearBottom.current && messageList.current)
            messageList.current.scrollTop = messageList.current.scrollHeight;
    }, [feed.messages]);

    const refresh = useCallback(async () => {
        request.current?.abort();
        const controller = new AbortController();
        request.current = controller;
        try {
            const response = await fetch(`${p.baseUrl}/mesajlar?${query}`, {
                credentials: 'same-origin',
                headers: { Accept: 'application/json' },
                signal: controller.signal,
            });
            if (!response.ok) throw new Error('Mesajlar alınamadı. Yenile ile tekrar deneyin.');
            const data: Feed = await response.json();
            if (!controller.signal.aborted) {
                setFeed(data);
                setError('');
            }
        } catch (cause) {
            if (!controller.signal.aborted)
                setError(cause instanceof Error ? cause.message : 'Mesajlar alınamadı.');
        } finally {
            if (request.current === controller) request.current = null;
        }
    }, [p.baseUrl, query]);
    useEffect(() => {
        const timer = window.setInterval(() => {
            if (!lock.current && !request.current && document.visibilityState === 'visible') void refresh();
        }, 10000);
        return () => {
            window.clearInterval(timer);
            request.current?.abort();
        };
    }, [refresh]);

    async function action(payload: Action) {
        if (
            !feed.canWrite ||
            lock.current ||
            (payload.action === 'send' && (!body.trim() || (!p.room && !p.recipient)))
        )
            return;
        lock.current = true;
        setBusy(true);
        setError('');
        request.current?.abort();
        try {
            const response = await writeJson(p.baseUrl, payload, 'POST');
            if (!response.ok) {
                setError(
                    Object.values(response.data.errors ?? {})
                        .flat()
                        .join(' ') ||
                        response.data.message ||
                        'İşlem tamamlanamadı.',
                );
                return;
            }
            if (payload.action === 'create') {
                router.visit(`${p.baseUrl}?room=${response.data.id}`);
            } else {
                if (payload.action === 'send') {
                    setBody('');
                    nearBottom.current = true;
                } else {
                    setInvitees([]);
                    notify('Davetler eklendi.');
                }
                await refresh();
            }
        } catch (cause) {
            setError(cause instanceof Error ? cause.message : 'İşlem tamamlanamadı.');
        } finally {
            lock.current = false;
            setBusy(false);
        }
    }
    const activeRoom = feed.rooms.find((room) => room.id === p.room);
    const activeUser = feed.users.find((user) => user.id === p.recipient);
    const canInvite =
        feed.canWrite &&
        !p.recipient &&
        !!activeRoom &&
        (activeRoom.is_general || activeRoom.owner_id === p.actorId);
    const filteredUsers = feed.users.filter((user) =>
        user.name.toLocaleLowerCase('tr').includes(userSearch.trim().toLocaleLowerCase('tr')),
    );
    const send = () =>
        void action({
            action: 'send',
            body,
            ...(p.recipient ? { recipient: p.recipient } : { room: p.room }),
        });

    return (
        <AppShell
            applicationName={p.applicationName}
            navigation={p.navigation}
            pageTitle="Kurum Sohbeti"
            toolbar={
                <button
                    className="button portal-chat__refresh"
                    disabled={busy}
                    onClick={() => void refresh()}
                >
                    <i className="fas fa-rotate" aria-hidden="true" /> Yenile
                </button>
            }
            search={null}
        >
            <Head title="Kurum Sohbeti" />
            {!feed.canWrite && (
                <p className="notice" role="status">
                    Bu sohbeti yalnız okuyabilirsiniz.
                </p>
            )}
            {error && (
                <p className="form-error" role="alert">
                    {error}
                </p>
            )}
            <div className="portal-chat">
                <aside className="portal-chat__panel">
                    <h2>Sohbet Odaları</h2>
                    <ul className="portal-chat__people">
                        {feed.rooms.map((room) => (
                            <li key={room.id}>
                                <button
                                    className={room.id === p.room ? 'is-active' : ''}
                                    disabled={busy}
                                    onClick={() => router.visit(`${p.baseUrl}?room=${room.id}`)}
                                >
                                    <i
                                        className={room.is_general ? 'fas fa-bullhorn' : 'fas fa-hashtag'}
                                        aria-hidden="true"
                                    />
                                    {room.name}
                                </button>
                            </li>
                        ))}
                    </ul>
                    {feed.canWrite && (
                        <details className="portal-chat__drawer">
                            <summary>Yeni Oda Oluştur</summary>
                            <form
                                onSubmit={(event) => {
                                    event.preventDefault();
                                    void action({ action: 'create', name, members: newMembers });
                                }}
                            >
                                <label htmlFor="chat-room-name">
                                    Oda adı
                                    <input
                                        id="chat-room-name"
                                        required
                                        maxLength={150}
                                        value={name}
                                        disabled={busy}
                                        onChange={(event) => setName(event.target.value)}
                                    />
                                </label>
                                <MemberPicker
                                    id="chat-new-members"
                                    label="Davet edilecek kişiler"
                                    users={feed.users}
                                    selected={newMembers}
                                    onChange={setNewMembers}
                                    disabled={busy}
                                />
                                <button className="button button--primary" disabled={busy || !name.trim()}>
                                    Odayı Aç
                                </button>
                            </form>
                        </details>
                    )}
                </aside>
                <section className="portal-chat__main" aria-label="Sohbet">
                    <header>
                        <h2>
                            {activeRoom?.name ??
                                (activeUser ? `Birebir Sohbet: ${activeUser.name}` : 'Genel Sohbet')}
                        </h2>
                        <small>
                            {activeRoom?.is_general
                                ? 'Tüm kurum personeline açık oda'
                                : activeUser
                                  ? 'Özel mesajlaşma'
                                  : 'Davetli oda'}
                        </small>
                    </header>
                    <section
                        className="portal-chat__messages"
                        ref={messageList}
                        role="log"
                        aria-label="Mesajlar"
                        aria-live="polite"
                        onScroll={(event) => {
                            const el = event.currentTarget;
                            nearBottom.current = el.scrollHeight - el.scrollTop - el.clientHeight < 60;
                        }}
                    >
                        {feed.messages.map((message) => (
                            <article
                                key={message.id}
                                className={`portal-chat__message${message.sender_id === p.actorId ? ' is-self' : ''}`}
                            >
                                <span>{message.sender_id === p.actorId ? 'Siz' : message.sender_name}</span>
                                <p>{message.body}</p>
                                <small>{message.created_at}</small>
                            </article>
                        ))}
                        {!feed.messages.length && (
                            <p className="portal-chat__empty">Henüz mesaj yok. İlk mesajı yazın.</p>
                        )}
                    </section>
                    <form
                        className="portal-chat__composer"
                        onSubmit={(event) => {
                            event.preventDefault();
                            send();
                        }}
                    >
                        <label className="sr-only" htmlFor="chat-message">
                            Mesajınızı yazın
                        </label>
                        <textarea
                            id="chat-message"
                            required
                            maxLength={1000}
                            placeholder="Mesajınızı yazın…"
                            disabled={busy || !feed.canWrite}
                            value={body}
                            onChange={(event) => setBody(event.target.value)}
                            onKeyDown={(event) => {
                                if (
                                    event.key === 'Enter' &&
                                    !event.shiftKey &&
                                    !event.nativeEvent.isComposing
                                ) {
                                    event.preventDefault();
                                    send();
                                }
                            }}
                        />
                        <button
                            className="button button--primary"
                            disabled={busy || !feed.canWrite || !body.trim() || (!p.room && !p.recipient)}
                            aria-label="Gönder"
                        >
                            <i className="fas fa-paper-plane" aria-hidden="true" />
                        </button>
                    </form>
                </section>
                <aside className="portal-chat__panel">
                    <h2>
                        Personel <small aria-live="polite">{feed.users.length} kişi</small>
                    </h2>
                    <input
                        className="portal-chat__search"
                        aria-label="Personel ara"
                        type="search"
                        placeholder="Personel ara…"
                        value={userSearch}
                        onChange={(event) => setUserSearch(event.target.value)}
                    />
                    <ul className="portal-chat__people">
                        {filteredUsers.map((user) => (
                            <li key={user.id}>
                                <button
                                    className={user.id === p.recipient ? 'is-active' : ''}
                                    disabled={busy}
                                    onClick={() => router.visit(`${p.baseUrl}?recipient=${user.id}`)}
                                >
                                    <i className="fas fa-user" aria-hidden="true" />
                                    {user.name}
                                </button>
                            </li>
                        ))}
                    </ul>
                    {canInvite && (
                        <details className="portal-chat__drawer">
                            <summary>Odaya Davet Et</summary>
                            <form
                                onSubmit={(event) => {
                                    event.preventDefault();
                                    void action({ action: 'invite', room: p.room, members: invitees });
                                }}
                            >
                                <MemberPicker
                                    id="chat-invite-members"
                                    label="Davet edilecek kişiler"
                                    users={feed.users}
                                    selected={invitees}
                                    onChange={setInvitees}
                                    disabled={busy}
                                />
                                <button
                                    className="button button--primary"
                                    disabled={busy || !invitees.length}
                                >
                                    Davet Gönder
                                </button>
                            </form>
                        </details>
                    )}
                </aside>
            </div>
        </AppShell>
    );
}
