import {
    useEffect,
    useId,
    useRef,
    useState,
    type KeyboardEvent,
    type ReactNode,
    type SyntheticEvent,
} from 'react';
import { createPortal } from 'react-dom';

type Props = {
    name: string;
    children: ReactNode | (() => ReactNode);
    onActivate?: () => void;
    disabled?: boolean;
    className?: string;
};

/** Show only data already authorized for the current list; never fetch a wider record. */
export default function RecordHoverCard({
    name,
    children,
    onActivate,
    disabled = false,
    className = '',
}: Props) {
    const tooltipId = useId();
    const [active, setActive] = useState<{ anchor: HTMLElement; delay: number } | null>(null);
    const hide = () => setActive(null);
    function show(event: SyntheticEvent<HTMLElement>, delay: number) {
        if (!disabled) setActive({ anchor: event.currentTarget, delay });
    }
    const events = {
        onFocus: (event: SyntheticEvent<HTMLElement>) => show(event, 0),
        onBlur: hide,
        onMouseEnter: (event: SyntheticEvent<HTMLElement>) => show(event, 700),
        onMouseLeave: hide,
        onKeyDown: (event: KeyboardEvent<HTMLElement>) => {
            if (event.key === 'Escape') hide();
        },
        'aria-describedby': active ? tooltipId : undefined,
    };
    if (!name.trim()) return null;

    return (
        <>
            {onActivate ? (
                <button
                    {...events}
                    type="button"
                    className="record-name"
                    disabled={disabled}
                    onClick={(event) => {
                        event.stopPropagation();
                        hide();
                        onActivate();
                    }}
                >
                    {name}
                </button>
            ) : (
                <span {...events} className="record-name" tabIndex={disabled ? undefined : 0}>
                    {name}
                </span>
            )}
            {active && !disabled && (
                <HoverTooltip {...active} id={tooltipId} className={className} onClose={hide}>
                    {children}
                </HoverTooltip>
            )}
        </>
    );
}

function HoverTooltip({
    anchor,
    delay,
    id,
    className,
    children,
    onClose,
}: {
    anchor: HTMLElement;
    delay: number;
    id: string;
    className: string;
    children: Props['children'];
    onClose: () => void;
}) {
    const [position, setPosition] = useState<{ left: number; top: number } | null>(null);
    const card = useRef<HTMLDivElement>(null);
    const close = useRef(onClose);
    close.current = onClose;

    useEffect(() => {
        const timer = window.setTimeout(() => {
            if (!anchor.isConnected) return;
            const bounds = anchor.getBoundingClientRect();
            setPosition({
                left: Math.max(8, Math.min(bounds.left + 20, innerWidth - 378)),
                top: bounds.top < 180 ? bounds.bottom + 8 : bounds.top - 172,
            });
        }, delay);
        const dismiss = () => close.current();
        window.addEventListener('scroll', dismiss, true);
        window.addEventListener('resize', dismiss);
        return () => {
            window.clearTimeout(timer);
            window.removeEventListener('scroll', dismiss, true);
            window.removeEventListener('resize', dismiss);
        };
    }, [anchor, delay]);

    useEffect(() => {
        const bounds = card.current?.getBoundingClientRect();
        if (!bounds || !position) return;
        const left = Math.max(8, Math.min(position.left, innerWidth - bounds.width - 8));
        const top = Math.max(8, Math.min(position.top, innerHeight - bounds.height - 8));
        if (left !== position.left || top !== position.top) setPosition({ left, top });
    }, [position]);

    return (
        position &&
        createPortal(
            <div
                ref={card}
                id={id}
                role="tooltip"
                className={`cte-inmate-hover-card ${className}`}
                style={position}
            >
                {typeof children === 'function' ? children() : children}
            </div>,
            anchor.closest('dialog') ?? document.body,
        )
    );
}
