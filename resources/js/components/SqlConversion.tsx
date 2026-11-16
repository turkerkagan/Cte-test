import { adminResult, type AdminResult } from '../lib/adminTypes';
import { useState } from 'react';
import { portalUrl } from '../lib/portalUrl';
export default function SqlConversion({ busy, baseUrl }: { busy: boolean; baseUrl: string }) {
    const [sql, setSql] = useState(''),
        [result, setResult] = useState<AdminResult | null>(null),
        [working, setWorking] = useState(false),
        [error, setError] = useState('');
    async function run(apply = false) {
        setWorking(true);
        setError('');
        try {
            const { writeJson } = await import('../lib/workflow');
            const r = await writeJson(
                baseUrl,
                {
                    action: apply ? 'sql-conversion' : 'sql-preview',
                    sql,
                    ...(apply
                        ? {
                              confirmed: true,
                              checksum: result?.checksum,
                              target_checksum: result?.target_checksum,
                          }
                        : {}),
                },
                'POST',
            );
            if (!r.ok) {
                setError(
                    Object.values(r.data.errors ?? {})
                        .flat()
                        .join(' ') ||
                        r.data.message ||
                        'Aktarım tamamlanamadı.',
                );
                return;
            }
            setResult(adminResult(r.data));
        } catch (e) {
            setError(e instanceof Error ? e.message : 'Aktarım tamamlanamadı.');
        } finally {
            setWorking(false);
        }
    }
    function download() {
        const a = document.createElement('a'),
            url = URL.createObjectURL(
                new Blob([result?.download_sql ?? ''], { type: 'application/sql;charset=utf-8' }),
            );
        a.href = url;
        a.download = 'cte-donusturulmus.sql';
        a.click();
        URL.revokeObjectURL(url);
    }
    return (
        <section className="editor-panel">
            <h2>Eski SQL dökümünü dönüştür</h2>
            <p>
                Kişiler, birim verileri, koğuşlar ve kurum kayıtları yeni şemaya eşlenir. Mevcut kayıtla
                çelişen veya eşlemesi olmayan veri önizlemede belirtilir.
            </p>
            <input
                aria-label="Eski SQL dosyası"
                type="file"
                accept=".sql"
                disabled={busy || working}
                onChange={async (e) => {
                    const file = e.target.files?.[0];
                    if (file && file.size <= 20 * 1024 * 1024) {
                        setSql(await file.text());
                        setResult(null);
                    } else setError('En fazla 20 MB SQL seçin.');
                }}
            />
            <textarea
                aria-label="Eski SQL içeriği"
                rows={8}
                value={sql}
                onChange={(e) => {
                    setSql(e.target.value);
                    setResult(null);
                }}
            />
            <div className="row-actions">
                <button className="button" disabled={busy || working || !sql} onClick={() => void run()}>
                    Dönüştür ve önizle
                </button>
                <a className="button" href={portalUrl('/yonetim/sql-indir') + '?kind=legacy-template'}>
                    Boş eski şema indir
                </a>
                <a className="button" href={portalUrl('/yonetim/sql-indir') + '?kind=schema'}>
                    Güncel boş şema indir
                </a>
                {result?.download_sql && (
                    <button className="button" onClick={download}>
                        Dönüştürülmüş SQL indir
                    </button>
                )}
                {result?.checksum && (
                    <button
                        className="button button--primary"
                        disabled={busy || working || (result.unsupported?.length ?? 0) > 0}
                        onClick={() => {
                            if (window.confirm('Ön yedek alınıp önizlenen SQL verileri aktarılsın mı?'))
                                void run(true);
                        }}
                    >
                        Yedekle ve aktar
                    </button>
                )}
            </div>
            {error && (
                <p role="alert" className="form-error">
                    {error}
                </p>
            )}
            {result && (
                <pre>
                    {JSON.stringify(
                        Object.fromEntries(Object.entries(result).filter(([key]) => key !== 'download_sql')),
                        null,
                        2,
                    )}
                </pre>
            )}
        </section>
    );
}
