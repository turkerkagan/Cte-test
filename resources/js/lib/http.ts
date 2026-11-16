/** Browser transport shared by JSON and multipart requests. */
export function xsrfToken(): string {
    const prefix = 'XSRF-TOKEN=';
    const cookie = document.cookie
        .split(';')
        .map((part) => part.trim())
        .find((part) => part.startsWith(prefix));
    try {
        return decodeURIComponent(cookie?.slice(prefix.length) ?? '');
    } catch {
        throw new Error('Oturum bilgisi okunamadı. Sayfayı yenileyin.');
    }
}

export function portalFetch(url: RequestInfo | URL, options: RequestInit = {}): Promise<Response> {
    const headers = new Headers(options.headers);
    if (!headers.has('Accept')) headers.set('Accept', 'application/json');
    const method = (options.method ?? 'GET').toUpperCase();
    if (!['GET', 'HEAD', 'OPTIONS'].includes(method)) headers.set('X-XSRF-TOKEN', xsrfToken());
    if (options.body instanceof FormData) headers.delete('Content-Type');
    return fetch(url, { credentials: 'same-origin', ...options, headers });
}
