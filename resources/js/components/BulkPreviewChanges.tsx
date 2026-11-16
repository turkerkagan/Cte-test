import { useState } from 'react';
import DataTable from './DataTable';
export type BulkChanges = {
    changedCount?: number;
    effect?: string;
    changes?: { id: number; name: string; field: string; before: string; after: string }[];
    excluded?: { id: number; name: string; reason: string }[];
};
export default function BulkPreviewChanges({ preview }: { preview: BulkChanges }) {
    const [page, setPage] = useState(1);
    const rows = preview.changes ?? [],
        pages = Math.max(1, Math.ceil(rows.length / 50));
    return (
        <>
            {preview.changedCount !== undefined && (
                <p>
                    <strong>{preview.changedCount} kaydın alan değeri değişecek.</strong>
                </p>
            )}
            {preview.effect && <p>{preview.effect}</p>}
            {rows.length > 0 && (
                <>
                    <DataTable
                        rows={rows.slice((page - 1) * 50, page * 50)}
                        columns={[
                            { key: 'name', label: 'Adı Soyadı', kind: 'name', value: (row) => row.name },
                            { key: 'field', label: 'Alan', value: (row) => row.field },
                            { key: 'before', label: 'Önce', value: (row) => row.before },
                            { key: 'after', label: 'Sonra', value: (row) => row.after },
                        ]}
                        rowKey={(row) => row.id}
                        caption="Uygulanacak alan değişiklikleri"
                    />
                    {pages > 1 && (
                        <div className="row-actions">
                            <button
                                type="button"
                                className="button"
                                disabled={page <= 1}
                                onClick={() => setPage(page - 1)}
                            >
                                Önceki
                            </button>
                            <span>
                                {page} / {pages}
                            </span>
                            <button
                                type="button"
                                className="button"
                                disabled={page >= pages}
                                onClick={() => setPage(page + 1)}
                            >
                                Sonraki
                            </button>
                        </div>
                    )}
                </>
            )}
            {!!preview.excluded?.length && (
                <details>
                    <summary>{preview.excluded.length} kayıtta değişiklik yapılmayacak</summary>
                    {preview.excluded.map((row) => (
                        <p key={row.id}>
                            {row.name} · {row.reason}
                        </p>
                    ))}
                </details>
            )}
        </>
    );
}
