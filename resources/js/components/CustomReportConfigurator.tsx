import { useState } from 'react';
import { searchName } from '../lib/table';
import type { Fields, State } from '../lib/workflow';
const names: Record<string, string> = {
    common: 'Ortak Bilgiler',
    alan_inceleme: 'Alan İnceleme',
    calisma: 'Çalışma Yeri',
    ilkgorus: 'Sorumlu Başmemurluk',
    infaz: 'İnfaz',
    izin: 'İzin',
    revir: 'Revir',
    egitim: 'Eğitim',
    ziyaret: 'Ziyaret',
    manevi: 'Manevi Rehberlik',
    mesem: 'MESEM',
    hijyen_isg: 'Hijyen ve İSG',
    kurslar: 'Kurslar',
    psikososyal: 'Psikososyal',
};
export default function CustomReportConfigurator({
    fields,
    state,
    onGenerate,
}: {
    fields: Fields;
    state: State;
    onGenerate: (columns: string[], scope: 'current' | 'all') => void;
}) {
    const [query, setQuery] = useState('');
    const [scope, setScope] = useState<'current' | 'all'>(state.scope ?? 'current');
    const [selected, setSelected] = useState(state.columns ?? ['tc_identity', 'full_name']);
    const groups = Object.entries(fields).reduce<Record<string, string[]>>((out, [key, field]) => {
        const group = (field as typeof field & { group?: string }).group ?? 'common';
        (out[group] ??= []).push(key);
        return out;
    }, {});
    return (
        <section className="custom-report-config no-print">
            <header>
                <h1>Özel Rapor Oluşturucu</h1>
                <p>İstediğiniz sütunları seçerek özel raporlar oluşturun</p>
            </header>
            <div className="custom-report-content">
                <h2>Sütun Seçimi</h2>
                <p>
                    {Object.keys(fields).length} kullanılabilir sütun · {selected.length} seçili
                </p>
                <label>
                    Kayıt Kapsamı
                    <select value={scope} onChange={(e) => setScope(e.target.value as 'current' | 'all')}>
                        <option value="current">Güncel Kayıtlar</option>
                        <option value="all">Tüm Kayıtlar</option>
                    </select>
                </label>
                <input
                    aria-label="Sütun veya sayfa adı ara"
                    placeholder="Sütun veya sayfa adı ile ara…"
                    value={query}
                    onChange={(e) => setQuery(e.target.value)}
                />
                <div className="row-actions">
                    <button className="button" onClick={() => setSelected(Object.keys(fields))}>
                        Tümünü Seç
                    </button>
                    <button className="button" onClick={() => setSelected([])}>
                        Seçimi Temizle
                    </button>
                    <button
                        className="button button--primary"
                        disabled={!selected.length}
                        onClick={() => onGenerate(selected, scope)}
                    >
                        Rapor Oluştur
                    </button>
                </div>
                <div className="custom-report-groups">
                    {Object.entries(groups).map(([group, keys]) => {
                        const visible = keys.filter((k) =>
                            searchName(`${fields[k].label} ${names[group] ?? group}`, query),
                        );
                        return visible.length ? (
                            <details key={group} open={group === 'common' || Boolean(query)}>
                                <summary>
                                    {names[group] ?? group}{' '}
                                    <small>
                                        {keys.filter((k) => selected.includes(k)).length} / {keys.length} alan
                                        seçildi
                                    </small>
                                </summary>
                                <div>
                                    {visible.map((key) => (
                                        <label key={key}>
                                            <input
                                                type="checkbox"
                                                checked={selected.includes(key)}
                                                onChange={(e) =>
                                                    setSelected(
                                                        e.target.checked
                                                            ? [...selected, key]
                                                            : selected.filter((k) => k !== key),
                                                    )
                                                }
                                            />
                                            {fields[key].label}
                                        </label>
                                    ))}
                                </div>
                            </details>
                        ) : null;
                    })}
                </div>
            </div>
        </section>
    );
}
