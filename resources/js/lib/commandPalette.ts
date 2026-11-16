export type CommandNavigation = { label: string; url: string; children?: CommandNavigation[] };
export type NavigationCommand = { label: string; url: string; group: string };

export function navigationCommands(items: CommandNavigation[], origin: string): NavigationCommand[] {
    const commands: NavigationCommand[] = [],
        seen = new Set<string>();
    function walk(rows: CommandNavigation[], group = '') {
        for (const row of rows) {
            if (row.children?.length) walk(row.children, row.label);
            if (!row.url || row.url.startsWith('#')) continue;
            try {
                const url = new URL(row.url, origin);
                if (
                    url.origin !== origin ||
                    !['http:', 'https:'].includes(url.protocol) ||
                    seen.has(url.href)
                )
                    continue;
                seen.add(url.href);
                commands.push({ label: row.label, url: url.href, group });
            } catch {
                /* A malformed menu entry must not prevent opening the other commands. */
            }
        }
    }
    walk(items);
    return commands;
}

export function searchCommands(commands: NavigationCommand[], query: string): NavigationCommand[] {
    const tokens = query.normalize('NFC').toLocaleLowerCase('tr-TR').trim().split(/\s+/).filter(Boolean);
    return commands
        .filter((command) =>
            tokens.every((token) =>
                `${command.label} ${command.group}`
                    .normalize('NFC')
                    .toLocaleLowerCase('tr-TR')
                    .includes(token),
            ),
        )
        .slice(0, 30);
}
