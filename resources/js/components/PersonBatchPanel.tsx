import { useRef, useState } from 'react';
import DataTable from './DataTable';
import { writeJson } from '../lib/workflow';
import type { Column } from '../lib/table';

type Person = {
    id: number;
    full_name?: string;
    tc_identity?: string;
    tc?: string;
    name?: string;
    uygulama_adi?: string;
    status?: string;
    durum?: string;
};
type Props = {
    baseUrl: string;
    canWrite: boolean;
    statuses: { id: number; label: string }[];
    xmlOnly?: Record<string, unknown>[];
};
type Preview = { token: string; count: number; people: Person[] };
const personName = (person: Person) => person.full_name ?? person.uygulama_adi ?? person.name ?? '';
const identityColumns: Column<Person>[] = [
    { key: 'sequence', label: 'ID', kind: 'sequence', value: () => null },
    {
        key: 'tc_identity',
        label: 'TC Kimlik',
        kind: 'tc',
        value: (person) => person.tc_identity ?? person.tc ?? '',
    },
    { key: 'full_name', label: 'Adı Soyadı', kind: 'name', value: personName },
];

export default function PersonBatchPanel(p: Props) {
    const [text, setText] = useState('');
    const [rows, setRows] = useState<Person[]>([]);
    const [ids, setIds] = useState<number[]>([]);
    const [status, setStatus] = useState('');
    const [xmlOnly, setXmlOnly] = useState(false);
    const [preview, setPreview] = useState<Preview | null>(null);
    const [operation, setOperation] = useState<'status' | 'delete'>('status');
    const [confirmation, setConfirmation] = useState('');
    const [busy, setBusy] = useState(false);
    const [message, setMessage] = useState('');
    const requestLock = useRef(false);

    async function search() {
        if (requestLock.current) return;
        requestLock.current = true;
        setBusy(true);
        setMessage('');
        try {
            const result = await writeJson(p.baseUrl, { action: 'search', text }, 'POST');
            if (!result.ok) throw new Error(result.data.message ?? 'Arama yapılamadı.');
            setRows((result.data as unknown as { people: Person[] }).people);
            setXmlOnly(false);
            setIds([]);
            setPreview(null);
        } catch (error) {
            setMessage(error instanceof Error ? error.message : 'Arama yapılamadı.');
        } finally {
            requestLock.current = false;
            setBusy(false);
        }
    }

    async function action(stage: 'preview' | 'apply') {
        if (!p.canWrite || requestLock.current) return;
        requestLock.current = true;
        setBusy(true);
        setMessage('');
        try {
            const result = await writeJson(
                p.baseUrl,
                {
                    action: operation + '-' + stage,
                    ids,
                    status: operation === 'status' ? status : null,
                    token: preview?.token,
                    confirmation: confirmation || null,
                    xml_only: xmlOnly,
                },
                'POST',
            );
            if (!result.ok) {
                setPreview(null);
                throw new Error(result.data.message ?? 'İşlem yapılamadı.');
            }
            if (stage === 'preview') setPreview(result.data as unknown as Preview);
            else {
                setPreview(null);
                setIds([]);
                setRows([]);
                setConfirmation('');
                setMessage(`${(result.data as unknown as { count: number }).count} kayıt işlendi.`);
            }
        } catch (error) {
            setMessage(error instanceof Error ? error.message : 'İşlem yapılamadı.');
        } finally {
            requestLock.current = false;
            setBusy(false);
        }
    }

    const columns: Column<Person>[] = [
        ...identityColumns,
        {
            key: 'select',
            label: 'Seç',
            value: (person) => ids.includes(person.id),
            render: (person) => (
                <input
                    aria-label={`${personName(person)} seç`}
                    type="checkbox"
                    disabled={busy}
                    checked={ids.includes(person.id)}
                    onChange={(event) => {
                        setIds(
                            event.target.checked ? [...ids, person.id] : ids.filter((id) => id !== person.id),
                        );
                        setPreview(null);
                    }}
                />
            ),
        },
        { key: 'record_id', label: 'Kayıt kimliği', value: (person) => person.id },
        { key: 'status', label: 'Durum', value: (person) => person.status ?? person.durum },
    ];
    return (
        <details className="editor-panel no-print">
            <summary>Mevcut kişileri toplu ara ve işle</summary>
            <p>
                Her satıra tam adı soyadı veya TC yazın. Aynı ada/TC’ye ait bütün eşleşmeler ayrı kayıt
                kimliğiyle gösterilir.
            </p>
            <textarea
                aria-label="Toplu mevcut kişi arama"
                maxLength={200000}
                disabled={busy}
                value={text}
                onChange={(event) => {
                    setText(event.target.value);
                    setPreview(null);
                    setConfirmation('');
                }}
            />
            <button className="button" disabled={busy || !text.trim()} onClick={() => void search()}>
                Tam eşleşmeleri getir
            </button>
            {p.xmlOnly && (
                <button
                    className="button"
                    disabled={busy}
                    onClick={() => {
                        setRows(p.xmlOnly as Person[]);
                        setXmlOnly(true);
                        setIds([]);
                        setPreview(null);
                    }}
                >
                    Yalnız uygulamada bulunan XML karşılaştırma kayıtlarını getir
                </button>
            )}
            {message && <p role="status">{message}</p>}
            <DataTable
                rows={rows}
                columns={columns}
                rowKey={(person) => person.id}
                caption="Tam eşleşen mevcut kişiler"
            />
            {p.canWrite && rows.length > 0 && (
                <>
                    <label>
                        İşlem
                        <select
                            disabled={busy}
                            value={operation}
                            onChange={(event) => {
                                setOperation(event.target.value as typeof operation);
                                setPreview(null);
                                setConfirmation('');
                            }}
                        >
                            <option value="status">Seçili kişilerin durumunu değiştir</option>
                            <option value="delete">Seçili kişileri kalıcı sil</option>
                        </select>
                    </label>
                    {operation === 'status' && (
                        <label>
                            Hedef durum
                            <select
                                required
                                disabled={busy}
                                value={status}
                                onChange={(event) => {
                                    setStatus(event.target.value);
                                    setPreview(null);
                                }}
                            >
                                <option value="">Seçin</option>
                                {p.statuses.map((item) => (
                                    <option key={item.id} value={item.id}>
                                        {item.label}
                                    </option>
                                ))}
                            </select>
                        </label>
                    )}
                    <button
                        className="button"
                        disabled={busy || !ids.length || (operation === 'status' && !status)}
                        onClick={() => void action('preview')}
                    >
                        Seçili işlem önizlemesini getir
                    </button>
                    {preview && (
                        <section>
                            <h3>
                                {preview.count} kişi ·{' '}
                                {operation === 'delete' ? 'Kalıcı silme' : 'Durum değişikliği'}
                            </h3>
                            <DataTable
                                rows={preview.people}
                                columns={identityColumns}
                                rowKey={(person) => person.id}
                                caption="Onay önizlemesi"
                            />
                            {operation === 'delete' && (
                                <>
                                    <p>
                                        Kimlik, birim alanları, notlar ve fotoğraf kaldırılır. Bağlı modül
                                        kaydı olan kişiler silinmez. Bu işlem geri alınamaz.
                                    </p>
                                    <label>
                                        Onay için KALICI SİL yazın
                                        <input
                                            disabled={busy}
                                            value={confirmation}
                                            onChange={(event) => setConfirmation(event.target.value)}
                                        />
                                    </label>
                                </>
                            )}
                            <button
                                className="button button--danger"
                                disabled={busy || (operation === 'delete' && confirmation !== 'KALICI SİL')}
                                onClick={() => void action('apply')}
                            >
                                Önizlemedeki kişilere işlemi uygula
                            </button>
                        </section>
                    )}
                </>
            )}
        </details>
    );
}
