import { useEffect, useRef, useState } from 'react';
import { portalUrl } from '../lib/portalUrl';
type ChartInstance = { destroy: () => void };
type ChartConstructor = new (canvas: HTMLCanvasElement, options: unknown) => ChartInstance;
declare global {
    interface Window {
        Chart?: ChartConstructor;
    }
}
let library: Promise<ChartConstructor> | undefined;
function loadChart() {
    if (window.Chart) return Promise.resolve(window.Chart);
    return (library ??= new Promise<ChartConstructor>((resolve, reject) => {
        const script = document.createElement('script');
        script.src = portalUrl('/vendor/chart.js/chart.umd.min.js');
        script.onload = () =>
            window.Chart ? resolve(window.Chart) : reject(new Error('Grafik yüklenemedi.'));
        script.onerror = () => {
            library = undefined;
            script.remove();
            reject(new Error('Grafik kütüphanesi yüklenemedi.'));
        };
        document.head.appendChild(script);
    }));
}
const colors = [
    '#667eea',
    '#764ba2',
    '#f093fb',
    '#4fd1c5',
    '#f6ad55',
    '#fc8181',
    '#63b3ed',
    '#68d391',
    '#d69e2e',
    '#9f7aea',
];
export default function StatisticsChart({
    title,
    rows,
    type = 'doughnut',
}: {
    title: string;
    rows: { label: string; count: number }[];
    type?: 'doughnut' | 'bar' | 'line';
}) {
    const canvas = useRef<HTMLCanvasElement>(null);
    const [error, setError] = useState('');
    const [attempt, setAttempt] = useState(0);
    useEffect(() => {
        let active = true;
        let chart: ChartInstance | undefined;
        setError('');
        if (rows.some((r) => r.count > 0))
            loadChart()
                .then((Chart) => {
                    if (!active || !canvas.current) return;
                    chart = new Chart(canvas.current, {
                        type,
                        data: {
                            labels: rows.map((r) => r.label),
                            datasets: [
                                {
                                    label: 'Kişi',
                                    data: rows.map((r) => r.count),
                                    backgroundColor: type === 'line' ? '#667eea22' : colors,
                                    borderColor: type === 'line' ? '#667eea' : undefined,
                                    borderWidth: type === 'line' ? 2 : 0,
                                    fill: type === 'line',
                                },
                            ],
                        },
                        options: {
                            responsive: true,
                            maintainAspectRatio: false,
                            animation: false,
                            plugins: {
                                legend: {
                                    display: type === 'doughnut',
                                    position: 'bottom',
                                    labels: { font: { family: 'Segoe UI', size: 11 }, boxWidth: 12 },
                                },
                            },
                            ...(type === 'doughnut'
                                ? {}
                                : { scales: { y: { beginAtZero: true, ticks: { precision: 0 } } } }),
                        },
                    });
                })
                .catch((e) => {
                    if (active) setError(e.message);
                });
        return () => {
            active = false;
            chart?.destroy();
        };
    }, [rows, type, attempt]);
    return (
        <article className="chart-card">
            <h3 className="chart-title">
                <i className="fas fa-chart-pie" aria-hidden="true" /> {title}
            </h3>
            <div className="chart-container">
                {error ? (
                    <div role="alert">
                        <p>{error}</p>
                        <button className="button" onClick={() => setAttempt(attempt + 1)}>
                            Tekrar dene
                        </button>
                    </div>
                ) : rows.some((r) => r.count > 0) ? (
                    <canvas
                        ref={canvas}
                        role="img"
                        aria-label={`${title}: ${rows.map((r) => `${r.label} ${r.count}`).join(', ')}`}
                    />
                ) : (
                    <p className="statistics-empty">Bu dağılım için kayıt bulunmuyor.</p>
                )}
            </div>
            <details className="chart-data">
                <summary>Verileri göster</summary>
                <table className="portal-table">
                    <thead>
                        <tr>
                            <th>Değer</th>
                            <th>Kişi</th>
                        </tr>
                    </thead>
                    <tbody>
                        {rows.map((r) => (
                            <tr key={r.label}>
                                <td>{r.label}</td>
                                <td>{r.count}</td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </details>
        </article>
    );
}
