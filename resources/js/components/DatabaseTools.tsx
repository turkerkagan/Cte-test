import { useState } from 'react';
export default function DatabaseTools({
    database,
    databases,
    showDatabaseSelector = true,
    busy,
    submit,
    visit,
}: {
    showDatabaseSelector?: boolean;
    database: string;
    databases: string[];
    busy: boolean;
    submit: (data: Record<string, unknown>, url?: string, reload?: boolean) => Promise<boolean>;
    visit: (d: Record<string, string | number>) => void;
}) {
    const [sql, setSql] = useState(''),
        [confirmation, setConfirmation] = useState(''),
        [checksum, setChecksum] = useState(''),
        [table, setTable] = useState('custom_'),
        [column, setColumn] = useState(''),
        [type, setType] = useState('text'),
        [operation, setOperation] = useState('create'),
        [layout, setLayout] = useState('plain');
    return (
        <>
            {showDatabaseSelector && (
                <label>
                    Çalışılacak veritabanı{' '}
                    <select
                        value={database}
                        onChange={(e) => visit({ database: e.target.value, table: '', page: 1 })}
                    >
                        {databases.map((d) => (
                            <option key={d}>{d}</option>
                        ))}
                    </select>
                </label>
            )}
            <details className="editor-panel">
                <summary>SQL ve fiziksel tablo araçları</summary>
                <h3>SQL konsolu</h3>
                <p>Tek komut çalıştırın. Veri değiştiren komutlar ön yedek ve yazılı onay ister.</p>
                <textarea
                    aria-label="SQL komutu"
                    rows={6}
                    value={sql}
                    onChange={(e) => {
                        setSql(e.target.value);
                        setChecksum('');
                    }}
                />
                <button
                    className="button"
                    disabled={busy || !sql}
                    onClick={() => void submit({ action: 'sql', sql, database }, undefined, false)}
                >
                    Sorgula / önizle
                </button>
                <label>
                    Önizlemede verilen doğrulama kodu{' '}
                    <input value={checksum} onChange={(e) => setChecksum(e.target.value)} />
                </label>
                <label>
                    SQL ÇALIŞTIR onayı{' '}
                    <input value={confirmation} onChange={(e) => setConfirmation(e.target.value)} />
                </label>
                <button
                    className="button button--danger"
                    disabled={busy || confirmation !== 'SQL ÇALIŞTIR' || checksum.length !== 64}
                    onClick={() =>
                        void submit(
                            { action: 'sql', sql, database, confirmed: true, confirmation, checksum },
                            undefined,
                            false,
                        )
                    }
                >
                    Yedekle ve SQL çalıştır
                </button>
                <h3>Tablo / kolon / indeks yönetimi</h3>
                <div className="user-fields">
                    <label>
                        İşlem{' '}
                        <select value={operation} onChange={(e) => setOperation(e.target.value)}>
                            {[
                                ['create', 'Tablo oluştur'],
                                ['drop', 'Tablo sil'],
                                ['empty', 'Tabloyu boşalt'],
                                ['add-column', 'Kolon ekle'],
                                ['drop-column', 'Kullanılmayan kolon sil'],
                                ['add-index', 'İndeks ekle'],
                                ['drop-index', 'İndeks sil'],
                            ].map(([key, name]) => (
                                <option key={key} value={key}>
                                    {name}
                                </option>
                            ))}
                        </select>
                    </label>
                    <label>
                        Tablo <input required value={table} onChange={(e) => setTable(e.target.value)} />
                    </label>
                    {operation === 'create' && (
                        <label>
                            Yapı{' '}
                            <select value={layout} onChange={(e) => setLayout(e.target.value)}>
                                <option value="plain">Temel tablo</option>
                                <option value="institution">Kurum sayfası kayıt tablosu</option>
                            </select>
                        </label>
                    )}
                    <label>
                        Kolon / indeks <input value={column} onChange={(e) => setColumn(e.target.value)} />
                    </label>
                    <label>
                        Kolon türü{' '}
                        <select value={type} onChange={(e) => setType(e.target.value)}>
                            {[
                                'text',
                                'longtext',
                                'integer',
                                'decimal',
                                'date',
                                'datetime',
                                'time',
                                'boolean',
                            ].map((v) => (
                                <option key={v}>{v}</option>
                            ))}
                        </select>
                    </label>
                </div>
                <button
                    className="button button--danger"
                    disabled={busy || !table}
                    onClick={() => {
                        if (
                            window.confirm(
                                'Seçilen tablo işleminden önce yedek alınacak. TABLOYU DEĞİŞTİR onayıyla uygula?',
                            )
                        )
                            void submit(
                                {
                                    action: 'structure',
                                    database,
                                    operation,
                                    table,
                                    column: column || null,
                                    type,
                                    layout,
                                    confirmed: true,
                                    confirmation: 'TABLOYU DEĞİŞTİR',
                                },
                                undefined,
                                false,
                            );
                    }}
                >
                    Yedekle ve uygula
                </button>
            </details>
        </>
    );
}
