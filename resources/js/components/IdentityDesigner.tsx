import { useState } from 'react';
import { router } from '@inertiajs/react';
type Person = {
    id: number;
    name: string;
    tc: string | null;
    photo: string | null;
    block: string | null;
    room: string | null;
    registered: string | null;
};
const rules = [
    'Bu kimlik sadece ceza infaz kurumu hükümlü/tutukluları içindir. Başka yerde kimlik olarak kullanılamaz.',
    'Bu kimlik, salıverilme işlemi sırasında görevli memura geri verilecektir.',
    'Kimliğini kaybeden veya başkasının kimliğini kullanan hükümlü veya tutuklu hakkında disiplin işlemi yapılacaktır.',
    'Kimlik olmaksızın salıverme işlemi, kimliğin başka yollarla ispat edilmesi halinde gerçekleştirilecektir.',
    'Bu kimlik, sadece sahibi tarafından kullanılabilir.',
    'Bu kimlik her istenildiğinde görevlilere mutlaka gösterilecektir.',
];
export default function IdentityDesigner({
    people,
    selected,
    onSelection,
    total,
    today,
    baseUrl,
    q,
    from = '',
    to = '',
    page,
    applicationName,
    previewReady = false,
}: {
    people: Person[];
    selected: number[];
    onSelection: (ids: number[]) => void;
    total: number;
    today: string;
    baseUrl: string;
    q: string;
    from?: string;
    to?: string;
    page: number;
    applicationName: string;
    previewReady?: boolean;
}) {
    const [settings, setSettings] = useState({
        width: 85.6,
        height: 53.98,
        photoWidth: 30,
        photoHeight: 40,
        columns: 2,
        institution: applicationName,
    });
    const [dates, setDates] = useState({ from, to });
    const [search, setSearch] = useState(q);
    const [preview, setPreview] = useState(previewReady);
    const chosen = people.filter((p) => selected.includes(p.id));
    function filter(range = dates) {
        router.get(
            baseUrl,
            { q: search, from: range.from, to: range.to, ids: selected, page: 1 },
            { preserveScroll: true },
        );
    }
    function quick(range: string) {
        const d = new Date(today + 'T12:00:00');
        const format = (d: Date) =>
            `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
        let end = today;
        let start = today;
        if (range === 'yesterday') {
            d.setDate(d.getDate() - 1);
            start = end = format(d);
        }
        if (range === 'last7' || range === 'last30') {
            d.setDate(d.getDate() - (range === 'last7' ? 6 : 29));
            start = format(d);
        }
        if (range === 'month') start = today.slice(0, 7) + '-01';
        if (range === 'all') start = end = '';
        const next = { from: start, to: end };
        setDates(next);
        filter(next);
    }
    function create() {
        if (selected.some((id) => !people.some((p) => p.id === id))) {
            router.get(baseUrl, { ids: selected, preview: 1, q: search, from: dates.from, to: dates.to });
            return;
        }
        setPreview(true);
    }
    return (
        <div className="kimlik-page">
            <header className="kimlik-page-header">
                <h1>Kimlik Kartı Baskı Sistemi</h1>
                <p>Hükümlüler için kimlik kartı hazırlama ve baskı aracı</p>
            </header>
            <div className="kimlik-top-grid no-print">
                <section className="kimlik-panel">
                    <h2>
                        <i className="fas fa-gear" aria-hidden="true" /> Kimlik Kartı Ayarları
                    </h2>
                    <div className="kimlik-controls">
                        {(
                            [
                                ['width', 'Kart Genişliği (mm)', 50, 150],
                                ['height', 'Kart Yüksekliği (mm)', 30, 150],
                                ['photoWidth', 'Fotoğraf Genişliği (mm)', 15, 40],
                                ['photoHeight', 'Fotoğraf Yüksekliği (mm)', 20, 50],
                            ] as const
                        ).map(([key, label, min, max]) => (
                            <label key={key}>
                                {label}
                                <input
                                    type="number"
                                    min={min}
                                    max={max}
                                    step="0.1"
                                    value={settings[key]}
                                    onChange={(e) =>
                                        setSettings({
                                            ...settings,
                                            [key]: Math.max(
                                                min,
                                                Math.min(max, Number(e.target.value) || min),
                                            ),
                                        })
                                    }
                                />
                            </label>
                        ))}
                        <label>
                            Satırdaki Kart Sayısı
                            <select
                                value={settings.columns}
                                onChange={(e) =>
                                    setSettings({ ...settings, columns: Number(e.target.value) })
                                }
                            >
                                {[2, 3, 4].map((n) => (
                                    <option key={n} value={n}>
                                        {n} Adet
                                    </option>
                                ))}
                            </select>
                        </label>
                        <label>
                            Kurum Adı
                            <input
                                maxLength={50}
                                value={settings.institution}
                                onChange={(e) => setSettings({ ...settings, institution: e.target.value })}
                            />
                        </label>
                    </div>
                </section>
                <section className="kimlik-panel">
                    <h2>
                        <i className="fas fa-users" aria-hidden="true" /> Hükümlü Seçimi
                    </h2>
                    <form
                        className="kimlik-dates"
                        onSubmit={(e) => {
                            e.preventDefault();
                            filter();
                        }}
                    >
                        <strong>Cezaevine Giriş / Kayıt Tarihine Göre Hızlı Seçim</strong>
                        <div className="user-fields">
                            <label>
                                Başlangıç
                                <input
                                    type="date"
                                    value={dates.from}
                                    onChange={(e) => setDates({ ...dates, from: e.target.value })}
                                />
                            </label>
                            <label>
                                Bitiş
                                <input
                                    type="date"
                                    value={dates.to}
                                    onChange={(e) => setDates({ ...dates, to: e.target.value })}
                                />
                            </label>
                        </div>
                        <div className="row-actions">
                            <button className="button">Filtrele</button>
                            <button type="button" className="button" onClick={() => quick('all')}>
                                Sıfırla
                            </button>
                        </div>
                        <div className="kimlik-date-pills">
                            {[
                                ['today', 'Bugün'],
                                ['yesterday', 'Dün'],
                                ['last7', 'Son 7 Gün'],
                                ['last30', 'Son 30 Gün'],
                                ['month', 'Bu Ay'],
                                ['all', 'Tüm Tarihler'],
                            ].map(([key, label]) => (
                                <button className="button" type="button" key={key} onClick={() => quick(key)}>
                                    {label}
                                </button>
                            ))}
                        </div>
                    </form>
                    <form
                        className="row-actions"
                        onSubmit={(e) => {
                            e.preventDefault();
                            filter();
                        }}
                    >
                        <input
                            aria-label="Kimlik için isim veya TC ara"
                            placeholder="İsim veya TC ile ara…"
                            value={search}
                            onChange={(e) => setSearch(e.target.value)}
                        />
                        <button className="button">Ara</button>
                        <span>Toplam: {total} kişi</span>
                    </form>
                    <div className="row-actions">
                        <button
                            className="button"
                            onClick={() =>
                                onSelection(Array.from(new Set([...selected, ...people.map((p) => p.id)])))
                            }
                        >
                            Görünenleri seç
                        </button>
                        <button className="button" onClick={() => onSelection([])}>
                            Temizle
                        </button>
                        <button
                            className="button button--primary"
                            disabled={!selected.length}
                            onClick={create}
                        >
                            Kartları Oluştur
                        </button>
                    </div>
                    <ul className="kimlik-selection-list">
                        {people.map((p) => (
                            <li key={p.id}>
                                <label>
                                    <input
                                        type="checkbox"
                                        checked={selected.includes(p.id)}
                                        onChange={(e) =>
                                            onSelection(
                                                e.target.checked
                                                    ? [...selected, p.id]
                                                    : selected.filter((id) => id !== p.id),
                                            )
                                        }
                                    />
                                    <span>
                                        <strong>{p.name}</strong>
                                        <small>
                                            TC: {p.tc} · {p.registered} · {p.block} {p.room}
                                        </small>
                                    </span>
                                </label>
                            </li>
                        ))}
                    </ul>
                    <div className="row-actions kimlik-pagination">
                        <button
                            className="button"
                            disabled={page <= 1}
                            onClick={() =>
                                router.get(baseUrl, {
                                    q: search,
                                    from: dates.from,
                                    to: dates.to,
                                    ids: selected,
                                    page: page - 1,
                                })
                            }
                        >
                            Önceki
                        </button>
                        <span>
                            {page} · {selected.length} seçili
                        </span>
                        <button
                            className="button"
                            disabled={page * 50 >= total}
                            onClick={() =>
                                router.get(baseUrl, {
                                    q: search,
                                    from: dates.from,
                                    to: dates.to,
                                    ids: selected,
                                    page: page + 1,
                                })
                            }
                        >
                            Sonraki
                        </button>
                    </div>
                </section>
            </div>
            {preview && (
                <section className="identity-output">
                    <h2>Kimlik Kartları Önizlemesi</h2>
                    <p className="no-print">
                        {chosen.length} kimlik kartı hazırlandı. Kartlar ön ve arka yüzleri yan yana basılır.{' '}
                        <button className="button" onClick={() => window.print()}>
                            Yazdır (A4)
                        </button>
                    </p>
                    <div
                        className="identity-print-grid"
                        style={{ gridTemplateColumns: `repeat(${settings.columns},max-content)` }}
                    >
                        {chosen.map((p) => (
                            <div className="identity-card-pair" key={p.id}>
                                <article
                                    className="identity-print-card"
                                    style={{ width: settings.width + 'mm', height: settings.height + 'mm' }}
                                >
                                    <div
                                        className="identity-print-photo"
                                        style={{
                                            width: Math.min(settings.photoWidth, settings.width - 8) + 'mm',
                                            height:
                                                Math.min(settings.photoHeight, settings.height - 8) + 'mm',
                                        }}
                                    >
                                        {p.photo ? (
                                            <img src={p.photo} alt={p.name} />
                                        ) : (
                                            <span>FOTOĞRAF YOK</span>
                                        )}
                                    </div>
                                    <div className="identity-print-text">
                                        <h3>{p.name}</h3>
                                        <p>TC: {p.tc ?? 'Belirtilmemiş'}</p>
                                        <p>
                                            Koğuş: {p.block} {p.room}
                                        </p>
                                        <small>{settings.institution}</small>
                                    </div>
                                </article>
                                <article
                                    className="identity-print-card identity-print-rules"
                                    style={{ width: settings.width + 'mm', height: settings.height + 'mm' }}
                                >
                                    <h4>TALİMAT</h4>
                                    {rules.map((rule) => (
                                        <p key={rule}>• {rule}</p>
                                    ))}
                                </article>
                            </div>
                        ))}
                    </div>
                </section>
            )}
        </div>
    );
}
