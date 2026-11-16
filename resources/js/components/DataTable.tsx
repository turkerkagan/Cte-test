import { useEffect, useId, useMemo, useRef, type ReactNode } from 'react';
import {
    standardColumns,
    tableCharacterLimits,
    wrapTableText,
    type CellValue,
    type Column,
    type Sort,
} from '../lib/table';
import { isRowControl } from '../lib/rowActivation';
import RecordHoverCard from './RecordHoverCard';

type Props<Row> = {
    rows: readonly Row[];
    columns: readonly Column<Row>[];
    rowKey: (row: Row) => string | number;
    caption: string;
    rowClassName?: (row: Row) => string | undefined;
    emptyMessage?: string;
    showEmptyMessage?: boolean;
    showRecordCount?: boolean;
    emptyCellValue?: string;
    sort?: Sort;
    headerActions?: (column: Column<Row>) => ReactNode;
    sequenceOffset?: number;
    onSort?: (key: string) => void;
    onRowDoubleClick?: (row: Row) => void;
    onRowClick?: (row: Row) => void;
    rowDisabled?: (row: Row) => boolean;
    rowLabel?: (row: Row) => string;
    scrollToEnd?: boolean;
};

function display(value: CellValue): string {
    if (value === null || value === undefined || value === '') return '—';
    if (typeof value === 'boolean') return value ? 'Evet' : 'Hayır';
    return String(value);
}

export default function DataTable<Row>({
    rows,
    columns: suppliedColumns,
    rowKey,
    caption,
    emptyMessage = 'Gösterilecek kayıt bulunamadı.',
    showEmptyMessage = false,
    showRecordCount = false,
    emptyCellValue = '—',
    sort = null,
    onSort,
    rowClassName,
    headerActions,
    sequenceOffset = 0,
    onRowDoubleClick,
    onRowClick,
    rowDisabled,
    rowLabel,
    scrollToEnd = false,
}: Props<Row>) {
    const columns = useMemo(() => standardColumns(suppliedColumns), [suppliedColumns]);
    const captionId = useId();
    const table = useRef<HTMLTableElement>(null);
    const columnStyle = (column: Column<Row>) => {
        if (column.kind === 'sequence') return { width: 'calc(4ch + 12px)' };
        if (column.kind === 'tc') return { width: 'calc(11ch + 40px)' };
        return undefined;
    };
    const initialScroll = useRef(false);
    useEffect(() => {
        if (!scrollToEnd || initialScroll.current || !rows.length) return;
        const frame = requestAnimationFrame(() => {
            const container = table.current?.parentElement;
            if (container) container.scrollTop = container.scrollHeight;
            initialScroll.current = true;
        });
        return () => cancelAnimationFrame(frame);
    }, [scrollToEnd, rows.length]);
    const body = useMemo(
        () => (
            <tbody>
                {rows.map((row, index) => (
                    <tr
                        key={rowKey(row)}
                        className={
                            [rowClassName?.(row), onRowClick ? 'data-table__record-row' : '']
                                .filter(Boolean)
                                .join(' ') || undefined
                        }
                        tabIndex={onRowClick && !rowDisabled?.(row) ? 0 : undefined}
                        aria-label={onRowClick ? rowLabel?.(row) : undefined}
                        aria-disabled={rowDisabled?.(row) || undefined}
                        onClick={(event) => {
                            if (
                                onRowClick &&
                                !event.defaultPrevented &&
                                !rowDisabled?.(row) &&
                                !isRowControl(event.target) &&
                                !window.getSelection()?.toString()
                            )
                                onRowClick(row);
                        }}
                        onKeyDown={(event) => {
                            if (
                                onRowClick &&
                                event.target === event.currentTarget &&
                                !rowDisabled?.(row) &&
                                ['Enter', ' '].includes(event.key)
                            ) {
                                event.preventDefault();
                                onRowClick(row);
                            }
                        }}
                        onDoubleClick={(event) => {
                            if (!onRowClick && !rowDisabled?.(row) && !isRowControl(event.target))
                                onRowDoubleClick?.(row);
                        }}
                        style={{ cursor: onRowClick || onRowDoubleClick ? 'pointer' : undefined }}
                    >
                        {columns.map((column) => {
                            const value =
                                column.kind === 'sequence' ? sequenceOffset + index + 1 : column.value(row);
                            const content =
                                column.render && column.kind !== 'sequence' ? (
                                    column.render(row)
                                ) : column.kind === 'name' &&
                                  value !== null &&
                                  value !== undefined &&
                                  value !== '' ? (
                                    <RecordHoverCard
                                        name={display(value)}
                                        disabled={rowDisabled?.(row)}
                                        onActivate={onRowClick ? () => onRowClick(row) : undefined}
                                        className="cte-record-hover-summary"
                                    >
                                        {() => (
                                            <div>
                                                <strong>{display(value)}</strong>
                                                <dl>
                                                    {columns
                                                        .filter(
                                                            (detail) =>
                                                                detail.kind !== 'sequence' &&
                                                                detail.kind !== 'name',
                                                        )
                                                        .filter((detail) => {
                                                            const cell = detail.value(row);
                                                            return (
                                                                cell !== null &&
                                                                cell !== undefined &&
                                                                cell !== ''
                                                            );
                                                        })
                                                        .slice(0, 6)
                                                        .map((detail) => (
                                                            <div key={detail.key}>
                                                                <dt>{detail.label}</dt>
                                                                <dd>
                                                                    {display(detail.value(row)).slice(0, 200)}
                                                                </dd>
                                                            </div>
                                                        ))}
                                                </dl>
                                            </div>
                                        )}
                                    </RecordHoverCard>
                                ) : value === null || value === undefined || value === '' ? (
                                    emptyCellValue
                                ) : (
                                    display(value)
                                );
                            return (
                                <td
                                    key={column.key}
                                    title={value == null ? undefined : String(value)}
                                    className={`data-table__cell data-table__cell--${column.kind ?? 'text'}`}
                                >
                                    {content !== '' && content !== null && content !== undefined && (
                                        <span className="data-table__content">
                                            {(typeof content === 'string' || typeof content === 'number') &&
                                            column.kind !== 'name'
                                                ? wrapTableText(
                                                      String(content),
                                                      tableCharacterLimits[column.kind ?? 'text'],
                                                  )
                                                : content}
                                        </span>
                                    )}
                                </td>
                            );
                        })}
                    </tr>
                ))}
            </tbody>
        ),
        [
            rows,
            columns,
            rowKey,
            rowClassName,
            onRowDoubleClick,
            onRowClick,
            rowDisabled,
            rowLabel,
            sequenceOffset,
            emptyCellValue,
        ],
    );
    return (
        <section
            className={'data-table' + (headerActions ? ' data-table--column-menus' : '')}
            aria-labelledby={captionId}
        >
            <div className="data-table__scroll" role="region" aria-label={`${caption} tablosu`} tabIndex={0}>
                <table ref={table}>
                    <caption id={captionId} className="sr-only">
                        {caption}
                    </caption>
                    <colgroup>
                        {columns.map((column) => (
                            <col key={column.key} style={columnStyle(column)} />
                        ))}
                    </colgroup>
                    <thead>
                        <tr>
                            {columns.map((column) => (
                                <th
                                    key={column.key}
                                    scope="col"
                                    title={
                                        column.kind === 'sequence'
                                            ? `${column.label} · Görünen sıra numarası`
                                            : column.label
                                    }
                                    className={`data-table__cell data-table__cell--${column.kind ?? 'text'}`}
                                    aria-sort={
                                        column.sortable && onSort
                                            ? sort?.key === column.key
                                                ? sort.direction === 'asc'
                                                    ? 'ascending'
                                                    : 'descending'
                                                : 'none'
                                            : undefined
                                    }
                                >
                                    <span className="data-table__content">
                                        {column.sortable && onSort ? (
                                            <button
                                                type="button"
                                                className="data-table__sort"
                                                onClick={() => onSort(column.key)}
                                            >
                                                {wrapTableText(
                                                    column.label,
                                                    tableCharacterLimits[column.kind ?? 'text'],
                                                )}
                                                <span aria-hidden="true">
                                                    {sort?.key === column.key
                                                        ? sort.direction === 'asc'
                                                            ? ' ↑'
                                                            : ' ↓'
                                                        : ''}
                                                </span>
                                            </button>
                                        ) : (
                                            <span className="data-table__label">
                                                {wrapTableText(
                                                    column.label,
                                                    tableCharacterLimits[column.kind ?? 'text'],
                                                )}
                                            </span>
                                        )}
                                        {column.kind !== 'sequence' && headerActions?.(column)}
                                    </span>
                                </th>
                            ))}
                        </tr>
                    </thead>
                    {body}
                </table>
            </div>
            {showEmptyMessage && rows.length === 0 && (
                <p className="data-table__empty" role="status">
                    {emptyMessage}
                </p>
            )}
            {showRecordCount && (
                <footer className="data-table__footer" aria-live="polite">
                    {rows.length} kayıt
                </footer>
            )}
        </section>
    );
}
