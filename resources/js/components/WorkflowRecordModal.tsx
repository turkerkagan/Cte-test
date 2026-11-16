import { useCallback, useEffect, useState, type ReactNode } from 'react';
import { router } from '@inertiajs/react';
import PortalDialog from './PortalDialog';
import { WorkflowRecordForm, type WorkflowEditorProps } from '../pages/WorkflowEditor';

export default function WorkflowRecordModal({
    url,
    onClose,
    secondaryActions,
    externalBusy = false,
    operationError,
}: {
    url: string;
    onClose: () => void;
    secondaryActions?: ReactNode;
    externalBusy?: boolean;
    operationError?: string;
}) {
    const [data, setData] = useState<WorkflowEditorProps | null>(null);
    const [formBusy, setFormBusy] = useState(false);
    const [formConflict, setFormConflict] = useState(false);
    const activity = useCallback((busy: boolean, conflict: boolean) => {
        setFormBusy(busy);
        setFormConflict(conflict);
    }, []);
    const [error, setError] = useState('');
    const [attempt, setAttempt] = useState(0);
    useEffect(() => {
        const abort = new AbortController();
        setData(null);
        setError('');
        fetch(url, {
            credentials: 'same-origin',
            headers: { Accept: 'application/json' },
            signal: abort.signal,
        })
            .then(async (response) => {
                if (!response.ok) throw new Error('Kayıt açılamadı.');
                return response.json();
            })
            .then(setData)
            .catch((e) => {
                if (e.name !== 'AbortError') setError(e.message);
            });
        return () => abort.abort();
    }, [url, attempt]);
    return (
        <PortalDialog
            id="popupForm"
            title={data ? `${data.person.full_name} · Kayıt İşlemleri` : 'Kayıt İşlemleri'}
            onClose={onClose}
            subtitle={data?.person.tc_identity ? `TC: ${data.person.tc_identity}` : undefined}
            className={`cte-record-modal cte-${data?.workflow ?? 'workflow'}-modal`}
            busy={formBusy || externalBusy}
            actions={
                data?.canWrite ? (
                    <>
                        {!formBusy && !formConflict && secondaryActions}
                        <button
                            type="submit"
                            form={`workflow-record-form-${data.person.id}`}
                            disabled={formBusy || formConflict || externalBusy}
                            className="button button--primary"
                        >
                            Kaydet
                        </button>
                    </>
                ) : undefined
            }
        >
            {operationError && (
                <p role="alert" className="form-error">
                    {operationError}
                </p>
            )}
            {data ? (
                <fieldset className="admin-form-fields" disabled={externalBusy}>
                    <WorkflowRecordForm
                        {...data}
                        onActivity={activity}
                        onSaved={() => {
                            onClose();
                            router.reload();
                        }}
                    />
                </fieldset>
            ) : (
                <div className="editor-panel">
                    <p role={error ? 'alert' : 'status'}>{error || 'Kayıt yükleniyor…'}</p>
                    {error && (
                        <button className="button" onClick={() => setAttempt(attempt + 1)}>
                            Tekrar dene
                        </button>
                    )}
                </div>
            )}
        </PortalDialog>
    );
}
