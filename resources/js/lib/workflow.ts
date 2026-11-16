import { portalFetch } from './http';
export type Field = {
    admin_configured?: boolean;
    admin_position?: number;
    label: string;
    type: string;
    kind?: 'tc' | 'name';
    readonly?: boolean;
    required?: boolean;
    form_visible?: boolean;
    table_visible?: boolean;
    filter_visible?: boolean;
    sortable?: boolean;
    max_length?: number;
    default?: string | null;
    option_list?: string;
};
export type Option = { id: number; label: string; semantic: string | null; is_active: boolean };
export type Fields = Record<string, Field>;
export type Values = Record<string, string | number | null>;
export type Filter = { field: string; op: string; value: string | number | (string | number)[] | null };
export type State = {
    scope?: 'current' | 'all';
    columns?: string[];
    selections?: Record<string, string[]>;
    selection_none?: string[];
    q?: string;
    filters?: Filter[];
    sort?: string;
    direction?: string;
    page?: number;
    per_page?: number;
    type?: string;
    days?: number;
    age?: number;
    excluded?: string[];
    date?: string;
    section?: string;
};
export const ds = ['NORMAL', 'DS İhlal', 'TAHLİYE', 'UYAP’A GİRİLDİ'];
export function queryString(state: State): string {
    const params = new URLSearchParams();
    for (const [key, value] of Object.entries(state)) {
        if (value === undefined || value === null) continue;
        if (key === 'selections') {
            Object.entries(value as Record<string, string[]>).forEach(([field, values]) =>
                values.forEach((item, index) => params.set(`selections[${field}][${index}]`, item)),
            );
        } else if (key === 'filters')
            (value as Filter[]).forEach((filter, i) =>
                Object.entries(filter).forEach(([k, v]) => {
                    if (Array.isArray(v))
                        v.forEach((x, j) => params.set(`filters[${i}][${k}][${j}]`, String(x)));
                    else if (v !== null) params.set(`filters[${i}][${k}]`, String(v));
                }),
            );
        else if (Array.isArray(value)) {
            if (!value.length) params.set(key, '');
            else value.forEach((v, i) => params.set(`${key}[${i}]`, String(v)));
        } else params.set(key, String(value));
    }
    return params.toString();
}
export async function writeJson(
    url: string,
    body: unknown,
    method = 'PUT',
): Promise<{
    ok: boolean;
    status: number;
    data: {
        message?: string;
        errors?: Record<string, string[]>;
        revision?: number;
        id?: number;
        redirect?: string;
    };
}> {
    const response = await portalFetch(url, {
        method,
        credentials: 'same-origin',
        headers: { Accept: 'application/json', 'Content-Type': 'application/json' },
        body: JSON.stringify(body),
    });
    if (!response.headers.get('content-type')?.includes('application/json'))
        throw new Error('Oturumu kontrol edin. Girdileriniz korunuyor.');
    const data: unknown = await response.json();
    if (typeof data !== 'object' || data === null || Array.isArray(data))
        throw new Error('Sunucunun yanıtı geçersiz. Girdileriniz korunuyor.');
    const reply = data as Record<string, unknown>;
    for (const key of ['message', 'redirect']) {
        if (reply[key] !== undefined && typeof reply[key] !== 'string')
            throw new Error('Sunucunun yanıtı geçersiz.');
    }
    if (
        reply.errors !== undefined &&
        (typeof reply.errors !== 'object' ||
            reply.errors === null ||
            Array.isArray(reply.errors) ||
            !Object.values(reply.errors).every(
                (value) => Array.isArray(value) && value.every((item) => typeof item === 'string'),
            ))
    )
        throw new Error('Sunucunun doğrulama yanıtı geçersiz.');
    return { ok: response.ok, status: response.status, data: reply };
}
