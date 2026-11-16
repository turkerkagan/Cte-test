import type { Option, State } from '../lib/workflow';
export default function TransferReportCriteria({
    state,
    reasons,
    onChange,
    onApply,
    count,
}: {
    state: State;
    reasons: Option[];
    onChange: (state: State) => void;
    onApply: () => void;
    count: number;
}) {
    const patch = (next: Partial<State>) => onChange({ ...state, ...next });
    return (
        <section className="transfer-report-criteria no-print">
            <h1>Sevk Öneri Listesi</h1>
            <div className="transfer-type-tabs" role="tablist" aria-label="Sevk türü">
                {[
                    ['il_disi', 'İl Dışı Sevk'],
                    ['il_ici', 'İl İçi Sevk'],
                ].map(([type, label]) => (
                    <button
                        key={type}
                        type="button"
                        role="tab"
                        aria-selected={state.type === type}
                        className={'button' + (state.type === type ? ' button--primary' : '')}
                        onClick={() => patch({ type, days: type === 'il_ici' ? 40 : 100 })}
                    >
                        {label}
                    </button>
                ))}
            </div>
            <form
                onSubmit={(e) => {
                    e.preventDefault();
                    onApply();
                }}
            >
                <div className="user-fields">
                    <label>
                        En az kalan gün
                        <input
                            type="number"
                            required
                            min={1}
                            max={365}
                            value={state.days ?? 100}
                            onChange={(e) => patch({ days: Number(e.target.value) })}
                        />
                    </label>
                    {state.type !== 'il_ici' && (
                        <>
                            <label>
                                Yaş bu değerden küçük
                                <input
                                    type="number"
                                    required
                                    min={18}
                                    max={100}
                                    value={state.age ?? 60}
                                    onChange={(e) => patch({ age: Number(e.target.value) })}
                                />
                            </label>
                            <label>
                                Hariç tutulacak geliş nedenleri
                                <select
                                    multiple
                                    value={state.excluded ?? []}
                                    onChange={(e) =>
                                        patch({
                                            excluded: Array.from(e.target.selectedOptions, (o) => o.value),
                                        })
                                    }
                                >
                                    {Array.from(
                                        new Set([
                                            ...reasons.map((r) => r.semantic ?? r.label),
                                            ...(state.excluded ?? []),
                                        ]),
                                    ).map((reason) => (
                                        <option key={reason}>{reason}</option>
                                    ))}
                                </select>
                                <button
                                    type="button"
                                    className="button"
                                    onClick={() => patch({ excluded: [] })}
                                >
                                    Hariç seçimleri kaldır
                                </button>
                            </label>
                        </>
                    )}
                </div>
                <button className="button button--primary">Raporu Oluştur</button>
                <span className="transfer-result-count">{count} kişi listelendi</span>
            </form>
        </section>
    );
}
