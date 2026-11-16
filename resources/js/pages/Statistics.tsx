import { Head } from '@inertiajs/react';
import { useState } from 'react';
import AppShell from '../components/AppShell';
import { ActiveFilters, QuickSearch } from '../components/ListToolbar';
import { searchName } from '../lib/table';
import DataTable from '../components/DataTable';
import StatisticsTables from '../components/StatisticsTables';
import StatisticsChart from '../components/StatisticsChart';
type Props = {
    applicationName: string;
    navigation?: { label: string; url: string }[];
    logoutUrl: string;
    title: string;
    kind: string;
    summary: Record<string, number>;
    sections: { key: string; label: string; rows: { label: string; count: number }[] }[];
    metrics: { key: string; label: string; count: number }[];
    interviewRate: number;
    trend: Record<string, number>;
    ages: Record<string, number>;
    work: {
        place: string;
        total: number;
        isyurtlari: number;
        genel: number;
        muhtelif: number;
        kararsiz: number;
        interviewed: number;
        applications: number;
        petitions: number;
        decisions?: Record<string, number>;
    }[];
    waiting: Record<string, { id: number; tc_identity: string | null; name: string; place: string }[]>;
};
export default function Statistics(p: Props) {
    const [query, setQuery] = useState('');
    const work = p.work.filter((row) => searchName(row.place, query));
    const sections = p.sections.map((section) => ({
        ...section,
        rows: section.rows.filter((row) => searchName(row.label, query)),
    }));
    const summaries = [
        ['total', 'Toplam Kayıt', 'Aktif durumda olan toplam kayıt', 'fa-users'],
        ['today', 'Bugün', 'Bugün eklenen kayıtlar', 'fa-calendar-day'],
        ['month', 'Bu Ay', 'Bu ay eklenen kayıtlar', 'fa-calendar'],
        ['year', 'Bu Yıl', 'Bu yıl eklenen kayıtlar', 'fa-calendar-days'],
        ['specialLeave', 'İzinli', 'Şu anda izinde olan', 'fa-person-walking'],
        ['departures', 'İzne Ayrılacak', 'Bugün izne çıkacak', 'fa-arrow-right'],
        ['returns', 'İzinden Dönecek', 'Bugün izinden dönecek', 'fa-arrow-left'],
        ['work', 'Çalışma Yeri', 'Farklı çalışma yeri', 'fa-briefcase'],
    ];
    const groups =
        p.kind === 'mesemstat'
            ? [{ label: 'MESEM Dağılımları', keys: p.sections.map((s) => s.key) }]
            : [
                  { label: 'Temel Dağılımlar', keys: ['block', 'status', 'huk_risk', 'huk_karar'] },
                  { label: 'Çalışma İstatistikleri', keys: ['huk_calis'] },
                  { label: 'Eğitim ve Kurs İstatistikleri', keys: ['egt_ogrenim', 'kurs_adi', 'egt_okul'] },
                  { label: 'Sağlık İstatistikleri', keys: ['huk_saglik', 'huk_kronik'] },
                  { label: 'Demografik İstatistikler', keys: ['room', 'huk_meslek'] },
              ];
    return (
        <AppShell
            listing
            applicationName={p.applicationName}
            navigation={p.navigation}
            pageTitle={p.title}
            toolbar={
                <ActiveFilters
                    chips={
                        query
                            ? [{ key: 'q', label: 'Tablo araması: ' + query, onRemove: () => setQuery('') }]
                            : []
                    }
                    onClear={() => setQuery('')}
                    emptyMessage="Tablo satırlarında arama yapmak için hızlı aramayı kullanın."
                    actions={
                        <button className="cte-primary-action" onClick={() => window.print()}>
                            Yazdır / PDF
                        </button>
                    }
                />
            }
            search={
                <>
                    <QuickSearch
                        value={query}
                        onSearch={setQuery}
                        placeholder="Hızlı Tablo Ara..."
                        label="Tablo satırlarında hızlı ara"
                    />
                    <div className="summary-info">
                        <p>
                            <strong>{p.title}: </strong>
                            {p.summary.total} kişi
                        </p>
                    </div>
                </>
            }
        >
            <Head title={p.title} />
            <div className="cte-statistics-container">
                <header className="statistics-page-header">
                    <h1>
                        <i className="fas fa-chart-line" aria-hidden="true" />{' '}
                        {p.kind === 'mesemstat'
                            ? 'MESEM İstatistik ve Analiz Paneli'
                            : 'Kapsamlı İstatistik ve Analiz Paneli'}
                    </h1>
                    <p>Tüm modüllerden gerçek zamanlı verilerle dinamik analizler</p>
                </header>
                <div className="stats-grid">
                    {summaries.map(([key, label, description, icon], i) => (
                        <article className="stat-card" key={key}>
                            <div className="stat-header">
                                <span className="stat-title">{label}</span>
                                <span className={`stat-icon stat-icon--${i}`}>
                                    <i className={`fas ${icon}`} aria-hidden="true" />
                                </span>
                            </div>
                            <div className="stat-value">
                                {key === 'work' ? p.work.length : (p.summary[key] ?? 0)}
                            </div>
                            <span className="stat-description">{description}</span>
                        </article>
                    ))}
                </div>
                {p.metrics.length > 0 && (
                    <>
                        <h2 className="section-title">MESEM Ölçümleri · Görüşme Oranı %{p.interviewRate}</h2>
                        <div className="stats-grid">
                            {p.metrics.map((m) => (
                                <article className="stat-card" key={m.key}>
                                    <span className="stat-title">{m.label}</span>
                                    <div className="stat-value">{m.count}</div>
                                </article>
                            ))}
                        </div>
                    </>
                )}
                {groups.map((g) => (
                    <section key={g.label}>
                        <h2 className="section-title">{g.label}</h2>
                        <div className="chart-grid">
                            {g.keys.flatMap((key) => {
                                const s = p.sections.find((s) => s.key === key);
                                return s
                                    ? [
                                          <StatisticsChart
                                              key={s.key}
                                              title={s.label + ' Dağılımı'}
                                              rows={s.rows}
                                              type={
                                                  [
                                                      'huk_calis',
                                                      'egt_ogrenim',
                                                      'egt_okul',
                                                      'kurs_adi',
                                                      'room',
                                                      'huk_meslek',
                                                  ].includes(key)
                                                      ? 'bar'
                                                      : 'doughnut'
                                              }
                                          />,
                                      ]
                                    : [];
                            })}
                        </div>
                    </section>
                ))}
                <h2 className="section-title">Trend ve Yaş Dağılımı</h2>
                <div className="chart-grid">
                    <StatisticsChart
                        title="Son 12 Ay Kayıt Eğilimi"
                        rows={Object.entries(p.trend).map(([label, count]) => ({ label, count }))}
                        type="line"
                    />
                    <StatisticsChart
                        title="İlk Görüş Yaş Grupları"
                        rows={Object.entries(p.ages).map(([label, count]) => ({ label, count }))}
                        type="bar"
                    />
                </div>
                <StatisticsTables kind={p.kind} work={work} sections={sections} total={p.summary.total} />
                {Object.entries(p.waiting).map(([key, rows]) => (
                    <section className="table-card" key={key}>
                        <h2>
                            {key === 'isyurtlari' ? 'İşyurtları' : 'Genel Bütçe'} Görüşme Bekleyen İlk 100
                            Kişi
                        </h2>
                        <DataTable
                            rows={rows.filter((row) => searchName(`${row.name} ${row.place}`, query))}
                            columns={[
                                {
                                    key: 'tc_identity',
                                    label: 'TC Kimlik',
                                    kind: 'tc',
                                    value: (r) => r.tc_identity,
                                },
                                { key: 'name', label: 'Adı Soyadı', kind: 'name', value: (r) => r.name },
                                { key: 'place', label: 'Çalışma Yeri', value: (r) => r.place },
                            ]}
                            rowKey={(r) => r.id}
                            caption="Görüşme bekleyenler"
                        />
                    </section>
                ))}
            </div>
        </AppShell>
    );
}
