import { useRef } from 'react';

/** Reuse unchanged JSON rows so a live refresh does not redraw an entire list. */
export function reconcileRows<Row extends { id: number }>(
    previous: readonly Row[],
    next: readonly Row[],
): readonly Row[] {
    const byId = new Map(previous.map((row) => [row.id, row]));
    let unchanged = previous.length === next.length;
    const rows = next.map((row, index) => {
        const prior = byId.get(row.id);
        const stable = prior && JSON.stringify(prior) === JSON.stringify(row) ? prior : row;
        if (stable !== previous[index]) unchanged = false;
        return stable;
    });
    return unchanged ? previous : rows;
}

export default function useStableRows<Row extends { id: number }>(incoming: readonly Row[]): readonly Row[] {
    const current = useRef({ incoming, rows: incoming });
    if (current.current.incoming !== incoming) {
        current.current = { incoming, rows: reconcileRows(current.current.rows, incoming) };
    }
    return current.current.rows;
}
