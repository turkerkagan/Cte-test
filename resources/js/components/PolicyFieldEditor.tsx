import { useEffect, useState } from 'react';
import PortalDialog from './PortalDialog';
import AdministrationFields from './AdministrationFields';
import { writeJson } from '../lib/workflow';
import { notify } from './Toast';
import type { DefinitionItem } from '../lib/adminTypes';

type State = {
    revision: number;
    values: { items: DefinitionItem[] };
    defaults: Record<string, DefinitionItem>;
    groups: Record<string, string>;
    sources: Record<string, string>;
    optionLists: { code: string; label: string }[];
    group: string;
    fieldKeys: string[];
    supported: boolean;
    saveUrl: string;
};
export default function PolicyFieldEditor({
    url,
    title,
    onClose,
}: {
    url: string;
    title: string;
    onClose: () => void;
}) {
    const [state, setState] = useState<State | null>(null);
    const [items, setItems] = useState<DefinitionItem[]>([]);
    const [busy, setBusy] = useState(true);
    const [error, setError] = useState('');
    useEffect(() => {
        const controller = new AbortController();
        fetch(url, {
            credentials: 'same-origin',
            headers: { Accept: 'application/json' },
            signal: controller.signal,
        })
            .then(async (response) => {
                if (!response.ok) throw new Error('Sayfa başlıkları yüklenemedi.');
                return (await response.json()) as State;
            })
            .then((data) => {
                setState(data);
                setItems(data.values.items);
            })
            .catch((e) => {
                if (!controller.signal.aborted)
                    setError(e instanceof Error ? e.message : 'Sayfa başlıkları yüklenemedi.');
            })
            .finally(() => {
                if (!controller.signal.aborted) setBusy(false);
            });
        return () => controller.abort();
    }, [url]);
    const dirty = state && JSON.stringify(items) !== JSON.stringify(state.values.items);
    const close = () => {
        if (!dirty || window.confirm('Kaydedilmemiş başlık değişiklikleri kapatılsın mı?')) onClose();
    };
    async function save() {
        if (busy || !state) return;
        setBusy(true);
        setError('');
        try {
            const response = await writeJson(state.saveUrl, { revision: state.revision, items }, 'POST');
            if (!response.ok) {
                setError(
                    Object.values(response.data.errors ?? {})
                        .flat()
                        .join(' ') ||
                        response.data.message ||
                        'Başlıklar kaydedilemedi.',
                );
                return;
            }
            notify('Tablo başlıkları kaydedildi.');
            onClose();
        } catch (e) {
            setError(e instanceof Error ? e.message : 'Başlıklar kaydedilemedi.');
        } finally {
            setBusy(false);
        }
    }
    return (
        <PortalDialog
            title={`${title} · Tablo Başlıkları`}
            className="admin-catalog-dialog policy-fields-dialog"
            busy={busy}
            onClose={close}
        >
            {error && (
                <p className="form-error" role="alert">
                    {error}
                </p>
            )}
            {!state && busy && <p role="status">Başlıklar yükleniyor…</p>}
            {state?.supported && (
                <>
                    <AdministrationFields
                        defaults={state.defaults}
                        groups={state.groups}
                        sources={state.sources}
                        optionLists={state.optionLists}
                        initialGroup={state.group}
                        fieldKeys={state.fieldKeys}
                        items={items}
                        setItems={setItems}
                        busy={busy}
                    />
                    <div className="row-actions">
                        <button
                            type="button"
                            className="button button--primary"
                            disabled={busy || !dirty}
                            onClick={() => void save()}
                        >
                            Başlıkları kaydet
                        </button>
                        <button type="button" className="button" disabled={busy} onClick={close}>
                            Kapat
                        </button>
                    </div>
                </>
            )}
            {state && !state.supported && (
                <p>Bu sayfanın alanları kendi sayfa tasarımı üzerinden yönetilir.</p>
            )}
        </PortalDialog>
    );
}
