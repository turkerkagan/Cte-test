import { useEffect, useMemo, useRef, useState } from 'react';
import { Link } from '@inertiajs/react';
import PortalDialog from './PortalDialog';
import { navigationCommands, searchCommands, type CommandNavigation } from '../lib/commandPalette';

export default function CommandPalette({ navigation }: { navigation: CommandNavigation[] }) {
    const [open, setOpen] = useState(false),
        [query, setQuery] = useState(''),
        [active, setActive] = useState(0);
    const [actions, setActions] = useState<{ id: string; label: string; target: HTMLElement }[]>([]);
    const list = useRef<HTMLUListElement>(null);
    const commands = useMemo(() => navigationCommands(navigation, window.location.origin), [navigation]);
    const filtered = searchCommands(commands, query);
    const actionMatches = actions.filter((action) =>
        query
            .toLocaleLowerCase('tr-TR')
            .trim()
            .split(/\s+/)
            .every((token) => action.label.toLocaleLowerCase('tr-TR').includes(token)),
    );
    useEffect(() => {
        if (!open) return;
        const definitions = [
            { id: 'search', label: 'Mevcut listede ara' },
            { id: 'filters', label: 'Tablo filtresini aç' },
            { id: 'new-record', label: 'Yeni kayıt ekle' },
        ];
        setActions(
            definitions.flatMap((action) => {
                const target = document.querySelector<HTMLElement>(
                    `#main-content [data-portal-command="${action.id}"]:not(:disabled)`,
                );
                return target && !target.closest('[hidden],[inert],dialog') ? [{ ...action, target }] : [];
            }),
        );
    }, [open]);
    function close() {
        setOpen(false);
        setQuery('');
        setActive(0);
    }
    useEffect(() => {
        const shortcut = (event: KeyboardEvent) => {
            if ((event.ctrlKey || event.metaKey) && event.key.toLowerCase() === 'k') {
                if (document.querySelector('dialog[open]')) return;
                event.preventDefault();
                setOpen(true);
            }
        };
        window.addEventListener('keydown', shortcut);
        return () => window.removeEventListener('keydown', shortcut);
    }, []);
    return (
        <>
            <button
                type="button"
                className="button"
                onClick={() => setOpen(true)}
                aria-keyshortcuts="Control+K Meta+K"
                title="Ekran ara (Ctrl/⌘+K)"
            >
                Ekran ara <kbd>⌘/Ctrl K</kbd>
            </button>
            {open && (
                <PortalDialog title="Ekran ara" onClose={close} protectChanges={false}>
                    <section className="editor-panel">
                        <label htmlFor="command-search">Yetkili ekranlarda ara</label>
                        <input
                            id="command-search"
                            type="search"
                            autoFocus
                            value={query}
                            aria-controls="command-results"
                            onChange={(event) => {
                                setQuery(event.target.value);
                                setActive(0);
                            }}
                            onKeyDown={(event) => {
                                if (event.key === 'Escape') {
                                    event.preventDefault();
                                    event.stopPropagation();
                                    close();
                                    return;
                                }
                                if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
                                    event.preventDefault();
                                    setActive((index) =>
                                        filtered.length + actionMatches.length
                                            ? (index +
                                                  (event.key === 'ArrowDown' ? 1 : -1) +
                                                  filtered.length +
                                                  actionMatches.length) %
                                              (filtered.length + actionMatches.length)
                                            : 0,
                                    );
                                }
                                if (event.key === 'Enter') {
                                    event.preventDefault();
                                    list.current?.querySelectorAll<HTMLElement>('a,button')[active]?.click();
                                }
                            }}
                        />
                        <ul id="command-results" ref={list} className="command-results">
                            {actionMatches.map((action, index) => (
                                <li key={action.id}>
                                    <button
                                        type="button"
                                        className={index === active ? 'button button--primary' : 'button'}
                                        onFocus={() => setActive(index)}
                                        onClick={() => {
                                            close();
                                            setTimeout(() => {
                                                if (
                                                    !action.target.isConnected ||
                                                    action.target.matches(':disabled')
                                                )
                                                    return;
                                                if (action.id === 'search') action.target.focus();
                                                else action.target.click();
                                            }, 0);
                                        }}
                                    >
                                        {action.label}
                                    </button>
                                </li>
                            ))}
                            {filtered.map((command, index) => (
                                <li key={command.url}>
                                    <Link
                                        href={command.url}
                                        className={
                                            index + actionMatches.length === active
                                                ? 'button button--primary'
                                                : 'button'
                                        }
                                        onClick={close}
                                        onFocus={() => setActive(index + actionMatches.length)}
                                    >
                                        {command.label}
                                        {command.group && <small> · {command.group}</small>}
                                    </Link>
                                </li>
                            ))}
                        </ul>
                        {filtered.length + actionMatches.length === 0 && (
                            <p role="status">Bu aramayla yetkili ekran bulunamadı.</p>
                        )}
                    </section>
                </PortalDialog>
            )}
        </>
    );
}
