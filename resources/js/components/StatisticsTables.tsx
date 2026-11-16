import DataTable from './DataTable';
import type { Column } from '../lib/table';

type Work = {
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
};
type Section = { key: string; label: string; rows: { label: string; count: number }[] };
export default function StatisticsTables({
    kind,
    work,
    sections,
    total,
}: {
    kind: string;
    work: Work[];
    sections: Section[];
    total: number;
}) {
    const mesem = kind === 'mesemstat';
    const decisions = Array.from(new Set(work.flatMap((row) => Object.keys(row.decisions ?? {}))));
    const columns: Column<Work>[] = mesem
        ? [
              { key: 'sequence', label: '#', kind: 'sequence', value: () => null },
              { key: 'place', label: 'Çalışma Yeri', value: (r) => r.place },
              { key: 'total', label: 'Toplam', value: (r) => r.total },
              { key: 'isyurtlari', label: 'İş Yurtları', value: (r) => r.isyurtlari },
              { key: 'genel', label: 'Genel Bütçe', value: (r) => r.genel },
              { key: 'muhtelif', label: 'Muhtelif', value: (r) => r.muhtelif },
              { key: 'kararsiz', label: 'Kararsız', value: (r) => r.kararsiz },
              { key: 'interviewed', label: 'Görüşülen', value: (r) => r.interviewed },
              {
                  key: 'rate',
                  label: 'Görüşme %',
                  value: (r) => (r.total ? ((r.interviewed / r.total) * 100).toFixed(1) + '%' : '0%'),
              },
              { key: 'applications', label: 'Başvuru', value: (r) => r.applications },
              { key: 'petitions', label: 'Dilekçe', value: (r) => r.petitions },
          ]
        : [
              { key: 'place', label: 'Çalışma Yeri', value: (r) => r.place },
              ...decisions.map((label) => ({
                  key: 'decision:' + label,
                  label,
                  value: (r: Work) => r.decisions?.[label] ?? 0,
              })),
              { key: 'total', label: 'Toplam', value: (r) => r.total },
          ];
    return (
        <>
            <section className="table-card">
                <h2>Çalışma Yeri ve Karar Dağılımı</h2>
                <DataTable
                    rows={work}
                    columns={columns}
                    rowKey={(r) => r.place}
                    caption="Çalışma yeri karar dağılımı"
                />
            </section>
            {(mesem ? ['mesem_mevcut_belge'] : ['huk_meslek', 'huk_calis']).flatMap((key) => {
                const section = sections.find((s) => s.key === key);
                if (!section) return [];
                const maximum = Math.max(1, ...section.rows.map((r) => r.count));
                const label =
                    key === 'mesem_mevcut_belge'
                        ? 'Mevcut Belge'
                        : key === 'huk_meslek'
                          ? 'Meslek'
                          : 'Çalışma Yeri';
                return [
                    <section className="table-card" key={key}>
                        <h2>{label} Dağılımı</h2>
                        <DataTable
                            rows={section.rows}
                            columns={[
                                { key: 'label', label, value: (r) => r.label },
                                {
                                    key: 'count',
                                    label: mesem ? 'Kişi' : 'Kişi Sayısı',
                                    value: (r) => r.count,
                                },
                                {
                                    key: 'percent',
                                    label: mesem ? '%' : 'Yüzde',
                                    value: (r) => (total ? ((r.count / total) * 100).toFixed(1) + '%' : '0%'),
                                },
                                ...(!mesem
                                    ? [
                                          {
                                              key: 'chart',
                                              label: 'Grafik',
                                              value: (r: { count: number }) => r.count,
                                              render: (r: { count: number }) => (
                                                  <progress
                                                      max={maximum}
                                                      value={r.count}
                                                      aria-label={label + ' kişi sayısı'}
                                                  />
                                              ),
                                          },
                                      ]
                                    : []),
                            ]}
                            rowKey={(r) => r.label}
                            caption={label + ' dağılımı'}
                        />
                    </section>,
                ];
            })}
        </>
    );
}
