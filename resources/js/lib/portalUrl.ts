export function portalUrl(path: string): string {
    const base =
        document.querySelector<HTMLMetaElement>('meta[name="portal-base"]')?.content.replace(/\/$/, '') ?? '';
    return `${base}/${path.replace(/^\//, '')}`;
}
