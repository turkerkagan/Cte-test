import { portalFetch } from '../lib/http';
import { Head, router } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import AppShell from '../components/AppShell';
import { writeJson } from '../lib/workflow';
import { notify } from '../components/Toast';

type Message = {
    id: number;
    sender_name: string;
    recipient_name: string;
    subject: string;
    created_at: string;
    read_at: string | null;
    attachments: number;
};

type Detail = Omit<Message, 'attachments'> & {
    body: string;
    attachments: { id: number; name: string; size: number; expires_at: string }[];
};

type Props = {
    applicationName: string;
    navigation?: { label: string; url: string }[];
    title: string;
    baseUrl: string;
    logoutUrl: string;
    box: string;
    page: number;
    total: number;
    canWrite: boolean;
    attachmentRetryMinutes?: number;
    messages: Message[];
    users: { id: number; full_name: string; username: string }[];
};

export default function PersonalMessages(p: Props) {
    const [detail, setDetail] = useState<Detail | null>(null);
    const [selectedId, setSelectedId] = useState<number | null>(null);
    const [recipient, setRecipient] = useState('');
    const [subject, setSubject] = useState('');
    const [body, setBody] = useState('');
    const [file, setFile] = useState<File | null>(null);
    const [error, setError] = useState('');
    const [busy, setBusy] = useState(false);
    const [requestId, setRequestId] = useState(() => crypto.randomUUID());
    const readAbort = useRef<AbortController | null>(null);
    useEffect(() => () => readAbort.current?.abort(), []);

    async function read(id: number) {
        readAbort.current?.abort();
        const controller = new AbortController();
        readAbort.current = controller;
        setError('');
        setSelectedId(id);
        try {
            const r = await portalFetch(`${p.baseUrl}/${id}`, {
                headers: { Accept: 'application/json' },
                credentials: 'same-origin',
                signal: controller.signal,
            });
            const data = await r.json();
            if (controller.signal.aborted) return;
            if (!r.ok) throw new Error(data.message ?? 'Mesaj açılamadı.');
            setDetail(data.message as Detail);
            if (p.box === 'inbox') {
                await writeJson(p.baseUrl, { action: 'read', message: id }, 'POST');
            }
        } catch (e) {
            if (e instanceof Error && e.name === 'AbortError') return;
            if (!controller.signal.aborted) {
                setError(e instanceof Error ? e.message : 'Mesaj açılamadı.');
            }
        }
    }

    async function send() {
        if (!recipient || !subject.trim() || (!body.trim() && !file)) return;
        setBusy(true);
        setError('');
        try {
            const form = new FormData();
            for (const [k, v] of Object.entries({
                action: 'send',
                recipient_id: recipient,
                subject,
                body,
                request_id: requestId,
            })) {
                form.set(k, v);
            }
            if (file) form.set('attachment', file);

            const r = await portalFetch(p.baseUrl, {
                method: 'POST',
                credentials: 'same-origin',
                headers: { Accept: 'application/json' },
                body: form,
            });
            if (!r.headers.get('content-type')?.includes('application/json'))
                throw new Error('Oturumu kontrol edin.');
            const data = await r.json();
            if (!r.ok) throw new Error(data.message ?? 'Mesaj gönderilemedi.');
            notify('Mesajınız gönderildi.');
            setSubject('');
            setBody('');
            setFile(null);
            setRequestId(crypto.randomUUID());
            router.get(p.baseUrl, { box: 'sent' });
        } catch (e) {
            setError(e instanceof Error ? e.message : 'Mesaj gönderilemedi.');
        } finally {
            setBusy(false);
        }
    }

    async function remove(id: number) {
        if (busy) return;
        setBusy(true);
        setError('');
        try {
            const r = await writeJson(p.baseUrl, { action: 'delete', message: id }, 'POST');
            if (!r.ok) {
                setError(r.data.message ?? 'Kaldırılamadı.');
            } else {
                notify('Mesaj kutunuzdan kaldırıldı.');
                setDetail(null);
                setSelectedId(null);
                router.reload();
            }
        } finally {
            setBusy(false);
        }
    }

    return (
        <AppShell
            applicationName={p.applicationName}
            navigation={p.navigation}
            pageTitle="Kişisel Mesajlar"
            toolbar={null}
            search={null}
        >
            <Head title="Kişisel Mesajlar" />

            {error && (
                <p className="form-error" role="alert" style={{ margin: '8px 12px' }}>
                    {error}
                </p>
            )}

            {/* 3 SÜTUNLU OUTLOOK DÜZENİ (1:1 ESKİ CTE MESAJLAR) */}
            <div
                className="cte-mesajlar-shell"
                style={{
                    display: 'grid',
                    gridTemplateColumns: '320px minmax(0, 1fr) 340px',
                    gap: '14px',
                    height: 'calc(100vh - 175px)',
                    minHeight: '520px',
                    padding: '6px 12px',
                }}
            >
                {/* 1. SÜTUN: KUTULAR VE MESAJ LİSTESİ */}
                <section
                    style={{
                        border: '1px solid var(--cte-border, #d8e2e2)',
                        borderRadius: '10px',
                        background: 'var(--cte-surface, #fff)',
                        display: 'flex',
                        flexDirection: 'column',
                        overflow: 'hidden',
                    }}
                >
                    <div style={{ display: 'flex', borderBottom: '1px solid var(--cte-border, #d8e2e2)' }}>
                        <button
                            type="button"
                            style={{
                                flex: 1,
                                padding: '11px',
                                border: 0,
                                background:
                                    p.box === 'inbox' ? 'var(--cte-primary-soft, #e3eff0)' : 'transparent',
                                color:
                                    p.box === 'inbox'
                                        ? 'var(--cte-primary, #245461)'
                                        : 'var(--cte-muted, #687983)',
                                fontWeight: 700,
                                fontSize: '13px',
                                cursor: 'pointer',
                                borderBottom:
                                    p.box === 'inbox' ? '3px solid var(--cte-primary, #245461)' : '0',
                            }}
                            onClick={() => router.get(p.baseUrl, { box: 'inbox' })}
                        >
                            <i className="fas fa-inbox" style={{ marginRight: '6px' }} /> Gelen Kutusu
                        </button>
                        <button
                            type="button"
                            style={{
                                flex: 1,
                                padding: '11px',
                                border: 0,
                                background:
                                    p.box === 'sent' ? 'var(--cte-primary-soft, #e3eff0)' : 'transparent',
                                color:
                                    p.box === 'sent'
                                        ? 'var(--cte-primary, #245461)'
                                        : 'var(--cte-muted, #687983)',
                                fontWeight: 700,
                                fontSize: '13px',
                                cursor: 'pointer',
                                borderBottom:
                                    p.box === 'sent' ? '3px solid var(--cte-primary, #245461)' : '0',
                            }}
                            onClick={() => router.get(p.baseUrl, { box: 'sent' })}
                        >
                            <i className="fas fa-paper-plane" style={{ marginRight: '6px' }} /> Giden Kutusu
                        </button>
                    </div>

                    <div style={{ flex: '1 1 auto', overflowY: 'auto' }}>
                        {!p.messages.length && (
                            <div
                                style={{
                                    padding: '30px',
                                    textAlign: 'center',
                                    color: 'var(--cte-muted, #687983)',
                                    fontSize: '13px',
                                }}
                            >
                                Bu kutuda mesaj yok.
                            </div>
                        )}
                        {p.messages.map((m) => {
                            const isUnread = p.box === 'inbox' && !m.read_at;
                            const isSelected = selectedId === m.id;
                            const person = p.box === 'sent' ? m.recipient_name : m.sender_name;

                            return (
                                <div
                                    key={m.id}
                                    onClick={() => void read(m.id)}
                                    style={{
                                        padding: '11px 14px',
                                        borderBottom: '1px solid var(--cte-border, #d8e2e2)',
                                        background: isSelected
                                            ? 'var(--cte-primary-soft, #e3eff0)'
                                            : isUnread
                                              ? '#f0fdfa'
                                              : 'transparent',
                                        cursor: 'pointer',
                                    }}
                                >
                                    <strong
                                        style={{
                                            display: 'block',
                                            fontSize: '13px',
                                            color: isUnread
                                                ? 'var(--cte-primary-deep, #17394b)'
                                                : 'var(--cte-text, #24333f)',
                                            fontWeight: isUnread ? 800 : 600,
                                            whiteSpace: 'nowrap',
                                            overflow: 'hidden',
                                            textOverflow: 'ellipsis',
                                        }}
                                    >
                                        {m.subject}
                                    </strong>
                                    <div
                                        style={{
                                            display: 'flex',
                                            alignItems: 'center',
                                            justifyContent: 'space-between',
                                            marginTop: '4px',
                                            fontSize: '11.5px',
                                            color: 'var(--cte-muted, #687983)',
                                        }}
                                    >
                                        <span>
                                            <i
                                                className={`fas ${p.box === 'sent' ? 'fa-arrow-up-right-from-square' : 'fa-user'}`}
                                                style={{ marginRight: '4px' }}
                                            />
                                            {person}
                                        </span>
                                        {m.attachments > 0 && (
                                            <i className="fas fa-paperclip" title="Ek dosya var" />
                                        )}
                                    </div>
                                    <span
                                        style={{
                                            display: 'block',
                                            fontSize: '10.5px',
                                            color: '#94a3b8',
                                            marginTop: '2px',
                                        }}
                                    >
                                        {m.created_at}
                                    </span>
                                </div>
                            );
                        })}
                    </div>

                    <div
                        style={{
                            padding: '8px 12px',
                            borderTop: '1px solid var(--cte-border, #d8e2e2)',
                            fontSize: '11px',
                            color: 'var(--cte-muted, #687983)',
                            display: 'flex',
                            justifyContent: 'space-between',
                            alignItems: 'center',
                        }}
                    >
                        <span>Toplam {p.total} mesaj</span>
                        <div style={{ display: 'flex', gap: '4px' }}>
                            <button
                                type="button"
                                className="button"
                                style={{ padding: '2px 8px', fontSize: '11px' }}
                                disabled={p.page <= 1}
                                onClick={() => router.get(p.baseUrl, { box: p.box, page: p.page - 1 })}
                            >
                                Önceki
                            </button>
                            <button
                                type="button"
                                className="button"
                                style={{ padding: '2px 8px', fontSize: '11px' }}
                                disabled={p.page * 80 >= p.total}
                                onClick={() => router.get(p.baseUrl, { box: p.box, page: p.page + 1 })}
                            >
                                Sonraki
                            </button>
                        </div>
                    </div>
                </section>

                {/* 2. SÜTUN: SEÇİLİ MESAJIN DETAYI */}
                <section
                    style={{
                        border: '1px solid var(--cte-border, #d8e2e2)',
                        borderRadius: '10px',
                        background: 'var(--cte-surface, #fff)',
                        padding: '20px',
                        display: 'flex',
                        flexDirection: 'column',
                        overflowY: 'auto',
                    }}
                >
                    {detail ? (
                        <>
                            <div
                                style={{
                                    display: 'flex',
                                    justifyContent: 'space-between',
                                    alignItems: 'flex-start',
                                    marginBottom: '12px',
                                }}
                            >
                                <div>
                                    <h2
                                        style={{
                                            fontSize: '18px',
                                            fontWeight: 800,
                                            margin: '0 0 6px',
                                            color: 'var(--cte-text, #24333f)',
                                        }}
                                    >
                                        {detail.subject}
                                    </h2>
                                    <div style={{ fontSize: '12.5px', color: 'var(--cte-muted, #687983)' }}>
                                        <strong>{detail.sender_name}</strong> &rarr;{' '}
                                        <strong>{detail.recipient_name}</strong> · {detail.created_at}
                                    </div>
                                </div>
                                <button
                                    type="button"
                                    className="button button--danger"
                                    disabled={busy}
                                    style={{ padding: '6px 12px', fontSize: '12px' }}
                                    onClick={() => void remove(detail.id)}
                                >
                                    <i className="fas fa-trash" /> Kutumdan Kaldır
                                </button>
                            </div>

                            <div
                                style={{
                                    flex: '1 1 auto',
                                    padding: '16px',
                                    borderRadius: '8px',
                                    border: '1px solid var(--cte-border, #d8e2e2)',
                                    background: 'var(--cte-surface-soft, #f8fafc)',
                                    whiteSpace: 'pre-wrap',
                                    lineHeight: 1.6,
                                    fontSize: '13.5px',
                                    marginBottom: '16px',
                                }}
                            >
                                {detail.body || '(Mesaj metni yok)'}
                            </div>

                            {detail.attachments.length > 0 && (
                                <div
                                    style={{
                                        borderTop: '1px solid var(--cte-border, #d8e2e2)',
                                        paddingTop: '12px',
                                    }}
                                >
                                    <h4 style={{ margin: '0 0 8px', fontSize: '12px', fontWeight: 700 }}>
                                        Ek Dosyalar:
                                    </h4>
                                    {detail.attachments.map((att) => (
                                        <div
                                            key={att.id}
                                            style={{
                                                display: 'flex',
                                                alignItems: 'center',
                                                justifyContent: 'space-between',
                                                padding: '8px 12px',
                                                border: '1px solid var(--cte-border, #d8e2e2)',
                                                borderRadius: '6px',
                                                marginBottom: '6px',
                                                background: '#fff',
                                                fontSize: '12px',
                                            }}
                                        >
                                            <span>
                                                <i
                                                    className="fas fa-file"
                                                    style={{
                                                        marginRight: '6px',
                                                        color: 'var(--cte-primary, #245461)',
                                                    }}
                                                />
                                                {att.name} ({Math.ceil(att.size / 1024)} KB)
                                            </span>
                                            <a
                                                href={`${p.baseUrl.replace(/\/mesajlar$/, '')}/mesaj-ekleri/${att.id}`}
                                                style={{
                                                    color: 'var(--cte-primary, #245461)',
                                                    fontWeight: 700,
                                                    textDecoration: 'none',
                                                }}
                                            >
                                                <i className="fas fa-download" /> İndir
                                            </a>
                                        </div>
                                    ))}
                                    <small style={{ color: 'var(--cte-muted, #687983)', fontSize: '11px' }}>
                                        Ekler en fazla 7 gün saklanır. İlk alıcı indirmesinden sonra{' '}
                                        {(p.attachmentRetryMinutes ?? 5) > 0
                                            ? `${p.attachmentRetryMinutes ?? 5} dakika yeniden indirilebilir; süre sonraki indirmelerde uzamaz.`
                                            : 'ek kaldırılır.'}
                                    </small>
                                </div>
                            )}
                        </>
                    ) : (
                        <div
                            style={{
                                margin: 'auto',
                                textAlign: 'center',
                                color: 'var(--cte-muted, #687983)',
                            }}
                        >
                            <i
                                className="fas fa-envelope-open-text fa-3x"
                                style={{ marginBottom: '12px', opacity: 0.4 }}
                            />
                            <p>Okumak için sol listeden bir mesaj seçin.</p>
                        </div>
                    )}
                </section>

                {/* 3. SÜTUN: YENİ MESAJ OLUŞTURMA FORMU */}
                <section
                    style={{
                        border: '1px solid var(--cte-border, #d8e2e2)',
                        borderRadius: '10px',
                        background: 'var(--cte-surface, #fff)',
                        padding: '16px',
                        display: 'flex',
                        flexDirection: 'column',
                        overflowY: 'auto',
                    }}
                >
                    <h3
                        style={{
                            fontSize: '14px',
                            fontWeight: 800,
                            margin: '0 0 12px',
                            display: 'flex',
                            alignItems: 'center',
                            gap: '8px',
                        }}
                    >
                        <i
                            className="fas fa-pen-to-square"
                            style={{ color: 'var(--cte-primary, #245461)' }}
                        />
                        Yeni Mesaj Gönder
                    </h3>

                    {p.canWrite ? (
                        <form
                            onSubmit={(e) => {
                                e.preventDefault();
                                void send();
                            }}
                            style={{ display: 'grid', gap: '10px', flex: '1 1 auto' }}
                        >
                            <div>
                                <label
                                    style={{
                                        display: 'block',
                                        fontSize: '11.5px',
                                        fontWeight: 700,
                                        marginBottom: '4px',
                                    }}
                                >
                                    Alıcı Personel
                                </label>
                                <select
                                    required
                                    value={recipient}
                                    onChange={(e) => setRecipient(e.target.value)}
                                    style={{
                                        width: '100%',
                                        padding: '7px 10px',
                                        border: '1px solid var(--cte-border, #d8e2e2)',
                                        borderRadius: '6px',
                                        fontSize: '12.5px',
                                    }}
                                >
                                    <option value="">Personel Seçin</option>
                                    {p.users.map((u) => (
                                        <option key={u.id} value={u.id}>
                                            {u.full_name || u.username}
                                        </option>
                                    ))}
                                </select>
                            </div>

                            <div>
                                <label
                                    style={{
                                        display: 'block',
                                        fontSize: '11.5px',
                                        fontWeight: 700,
                                        marginBottom: '4px',
                                    }}
                                >
                                    Konu
                                </label>
                                <input
                                    required
                                    maxLength={255}
                                    value={subject}
                                    onChange={(e) => setSubject(e.target.value)}
                                    placeholder="Mesaj konusu…"
                                    style={{
                                        width: '100%',
                                        padding: '7px 10px',
                                        border: '1px solid var(--cte-border, #d8e2e2)',
                                        borderRadius: '6px',
                                        fontSize: '12.5px',
                                    }}
                                />
                            </div>

                            <div>
                                <label
                                    style={{
                                        display: 'block',
                                        fontSize: '11.5px',
                                        fontWeight: 700,
                                        marginBottom: '4px',
                                    }}
                                >
                                    Mesaj Metni
                                </label>
                                <textarea
                                    maxLength={20000}
                                    value={body}
                                    onChange={(e) => setBody(e.target.value)}
                                    placeholder="Mesajınızı buraya yazın…"
                                    style={{
                                        width: '100%',
                                        height: '110px',
                                        padding: '8px 10px',
                                        border: '1px solid var(--cte-border, #d8e2e2)',
                                        borderRadius: '6px',
                                        fontSize: '12.5px',
                                        resize: 'vertical',
                                    }}
                                />
                            </div>

                            <div>
                                <label
                                    style={{
                                        display: 'block',
                                        fontSize: '11.5px',
                                        fontWeight: 700,
                                        marginBottom: '4px',
                                    }}
                                >
                                    Dosya Eki (Azami 25 MB)
                                </label>
                                <input
                                    type="file"
                                    accept=".pdf,.doc,.docx,.xls,.xlsx,.jpg,.jpeg,.png,.txt,.zip,.rar"
                                    onChange={(e) => setFile(e.target.files?.[0] ?? null)}
                                    style={{ fontSize: '11px', width: '100%' }}
                                />
                            </div>

                            <button
                                type="submit"
                                className="button button--primary"
                                disabled={busy || !recipient || !subject.trim()}
                                style={{ padding: '8px 16px', justifySelf: 'start', marginTop: '6px' }}
                            >
                                <i className="fas fa-paper-plane" />{' '}
                                {busy ? 'Gönderiliyor…' : 'Mesajı Gönder'}
                            </button>
                        </form>
                    ) : (
                        <p className="muted" style={{ fontSize: '12px' }}>
                            Mesaj gönderme yetkiniz bulunmuyor.
                        </p>
                    )}
                </section>
            </div>
        </AppShell>
    );
}
