type Props = {
    page: number;
    pages: number;
    total: number;
    perPage?: number;
    onPage: (page: number) => void;
    onPerPage?: (count: number) => void;
    allowAll?: boolean;
};

export default function ListPagination({
    page,
    pages,
    total,
    perPage,
    onPage,
    onPerPage,
    allowAll = false,
}: Props) {
    const counts = [25, 50, 100, ...(allowAll ? [0] : [])];
    if (perPage !== undefined && !counts.includes(perPage)) counts.push(perPage);
    return (
        <nav className="row-actions list-pagination" aria-label="Liste sayfaları">
            <button type="button" className="button" disabled={page <= 1} onClick={() => onPage(page - 1)}>
                Önceki
            </button>
            <span>
                Sayfa {page} / {pages} · Toplam {total} kayıt
            </span>
            <button
                type="button"
                className="button"
                disabled={page >= pages}
                onClick={() => onPage(page + 1)}
            >
                Sonraki
            </button>
            {perPage !== undefined && onPerPage && (
                <label>
                    Sayfada kayıt{' '}
                    <select
                        aria-label="Sayfada kayıt"
                        value={perPage}
                        onChange={(event) => onPerPage(Number(event.target.value))}
                    >
                        {counts.map((count) => (
                            <option key={count} value={count}>
                                {count === 0 ? 'Tümünü göster' : count}
                            </option>
                        ))}
                    </select>
                </label>
            )}
        </nav>
    );
}
