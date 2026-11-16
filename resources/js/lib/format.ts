const zone = 'Europe/Istanbul';
const dateTime = new Intl.DateTimeFormat('tr-TR', { dateStyle: 'short', timeStyle: 'short', timeZone: zone });
const calendarDate = new Intl.DateTimeFormat('tr-TR', { dateStyle: 'short', timeZone: zone });

export function formatDateTime(value?: string | null, empty = '—'): string {
    if (!value) return empty;
    const timestamp = /^\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}(?::\d{2}(?:\.\d+)?)?$/.test(value)
        ? value.replace(' ', 'T') + '+03:00'
        : value;
    const date = new Date(timestamp);
    return Number.isNaN(date.getTime()) ? empty : dateTime.format(date);
}

/** Calendar dates are not UTC timestamps: preserve their written day. */
export function formatDate(value?: string | null, empty = '—'): string {
    if (!value) return empty;
    const date = /^\d{4}-\d{2}-\d{2}$/.test(value) ? new Date(`${value}T12:00:00+03:00`) : new Date(value);
    return Number.isNaN(date.getTime()) ? empty : calendarDate.format(date);
}

export function todayInIstanbul(now = new Date()): string {
    const parts = new Intl.DateTimeFormat('en-GB', {
        year: 'numeric',
        month: '2-digit',
        day: '2-digit',
        timeZone: zone,
    }).formatToParts(now);
    const part = (type: string) => parts.find((value) => value.type === type)?.value ?? '';
    return `${part('year')}-${part('month')}-${part('day')}`;
}
