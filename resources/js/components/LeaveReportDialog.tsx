import { useEffect, useState } from 'react';
import PortalDialog from './PortalDialog';
import DataTable from './DataTable';
import { queryString, type Fields, type State } from '../lib/workflow';
type Result = {
    reportError?: string;
    rows: { id: number; values: Record<string, string> }[];
    fields: Fields;
    generatedAt?: string;
};
export const leaveSections = {
    departure: 'İzne Ayrılacaklar',
    return: 'İzinden Dönecekler',
    control: 'İzin Kontrol (Saat 14:00)',
};
export default function LeaveReportDialog({
    baseUrl,
    state,
    section,
    onClose,
}: {
    baseUrl: string;
    state: State;
    section: keyof typeof leaveSections;
    onClose: () => void;
}) {
    const [result, setResult] = useState<Result | null>(null);
    const [error, setError] = useState('');
    const [attempt, setAttempt] = useState(0);
    const query = queryString({ ...state, section });
    useEffect(() => {
        const abort = new AbortController();
        setResult(null);
        setError('');
        fetch(`${baseUrl}?${query}`, {
            credentials: 'same-origin',
            headers: { Accept: 'application/json' },
            signal: abort.signal,
        })
            .then(async (r) => {
                if (!r.ok) throw new Error('İzin listesi yüklenemedi.');
                return r.json();
            })
            .then((data) => {
                if (data.reportError) throw new Error(data.reportError);
                setResult(data);
            })
            .catch((e) => {
                if (e.name !== 'AbortError') setError(e.message);
            });
        return () => abort.abort();
    }, [baseUrl, query, attempt]);
    return (
        <PortalDialog
            title={`${leaveSections[section]}${result ? ` · ${result.rows.length} kişi` : ''}`}
            onClose={onClose}
            className="leave-report-dialog"
            actions={
                <>
                    {result && (
                        <a
                            className="button"
                            href={`${baseUrl}/yazdir?${query}`}
                            target="_blank"
                            rel="noopener"
                        >
                            Yazdır / PDF
                        </a>
                    )}
                    <button className="button" onClick={onClose}>
                        Kapat
                    </button>
                </>
            }
        >
            {error ? (
                <div className="portal-dialog-message" role="alert">
                    <p className="form-error">{error}</p>
                    <button className="button" onClick={() => setAttempt(attempt + 1)}>
                        Tekrar dene
                    </button>
                </div>
            ) : !result ? (
                <p className="portal-dialog-message" role="status">
                    Liste yükleniyor…
                </p>
            ) : (
                <>
                    <p className="portal-dialog-message">
                        Rapor tarihi: {state.date} · {result.generatedAt}
                    </p>
                    <DataTable
                        rows={result.rows}
                        columns={[
                            { key: 'sequence', label: '#', kind: 'sequence', value: () => null },
                            ...Object.entries(result.fields).map(([key, f]) => ({
                                key,
                                label: f.label,
                                kind: f.kind,
                                value: (row: Result['rows'][number]) => row.values[key],
                            })),
                        ]}
                        rowKey={(r) => r.id}
                        caption={leaveSections[section]}
                    />
                </>
            )}
        </PortalDialog>
    );
}
