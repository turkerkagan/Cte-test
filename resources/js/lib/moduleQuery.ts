export type ModuleFilter = {
    field: number;
    op: 'eq' | 'gte' | 'lte' | 'contains' | 'empty';
    value?: string | null;
};
export type ModuleQuery = {
    q: string;
    filter_field: number | null;
    filter_op: string;
    filter_value: string;
    filters?: ModuleFilter[];
    sort_field: number | null;
    sort_direction: 'asc' | 'desc';
};

export function moduleParameters(state: ModuleQuery) {
    return {
        q: state.q,
        filters: state.filters ?? [],
        ...(state.filter_field
            ? {
                  filter_field: state.filter_field,
                  filter_op: state.filter_op,
                  filter_value: state.filter_value,
              }
            : {}),
        ...(state.sort_field ? { sort_field: state.sort_field, sort_direction: state.sort_direction } : {}),
    };
}

export function moduleQueryString(state: ModuleQuery): string {
    const params = new URLSearchParams();
    const { filters, ...simple } = moduleParameters(state);
    for (const [key, value] of Object.entries(simple)) params.set(key, String(value));
    filters.forEach((filter, index) => {
        params.set(`filters[${index}][field]`, String(filter.field));
        params.set(`filters[${index}][op]`, filter.op);
        if (filter.value !== null && filter.value !== undefined)
            params.set(`filters[${index}][value]`, filter.value);
    });
    return params.toString();
}
