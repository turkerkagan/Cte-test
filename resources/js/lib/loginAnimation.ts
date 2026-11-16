export function shouldLoadLoginAnimation(): boolean {
    return (
        window.matchMedia('(min-width: 881px)').matches &&
        !window.matchMedia('(prefers-reduced-motion: reduce)').matches
    );
}
