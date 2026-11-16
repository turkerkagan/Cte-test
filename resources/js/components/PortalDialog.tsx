import { useEffect, useId, useLayoutEffect, useRef, useState, type ReactNode } from 'react';
import { dialogDraft } from '../lib/dialogDraft';
import useUnsavedChanges from '../lib/useUnsavedChanges';

/** Native dialog supplies a focus trap and stays above the overlay sidebar. */
export default function PortalDialog({
    title,
    children,
    onClose,
    className = '',
    actions,
    busy = false,
    dirty = false,
    protectChanges = true,
    id,
    subtitle,
}: {
    title: string;
    children: ReactNode;
    onClose: () => void;
    className?: string;
    actions?: ReactNode;
    busy?: boolean;
    dirty?: boolean;
    protectChanges?: boolean;
    id?: string;
    subtitle?: string;
}) {
    const dialog = useRef<HTMLDialogElement>(null);
    const titleId = useId();
    const confirmationId = useId();
    const draft = useRef(dialogDraft());
    const [changed, setChanged] = useState(false);
    const [confirmClose, setConfirmClose] = useState(false);
    const previousControl = useRef<HTMLElement | null>(null);
    useUnsavedChanges(protectChanges && (dirty || changed));
    useLayoutEffect(() => {
        if (dialog.current) draft.current.register(dialog.current);
    });
    function checkChanges() {
        if (dialog.current) setChanged(draft.current.changed(dialog.current));
    }
    function requestClose() {
        if (busy || confirmClose) return;
        if (protectChanges && (dirty || (dialog.current && draft.current.changed(dialog.current)))) {
            previousControl.current = document.activeElement as HTMLElement | null;
            setConfirmClose(true);
        } else onClose();
    }
    function continueEditing() {
        setConfirmClose(false);
        requestAnimationFrame(() => previousControl.current?.focus());
    }
    useEffect(() => {
        const previous = document.activeElement as HTMLElement | null;
        const node = dialog.current;
        node?.showModal();
        node?.querySelector<HTMLElement>('[autofocus]')?.focus();
        const observer = new MutationObserver(() => {
            if (node) draft.current.register(node);
        });
        if (node) observer.observe(node, { childList: true, subtree: true });
        return () => {
            observer.disconnect();
            node?.close();
            previous?.focus();
        };
    }, []);
    return (
        <dialog
            id={id}
            ref={dialog}
            className={`cte-note-modal portal-dialog ${className}`}
            aria-labelledby={titleId}
            onInputCapture={checkChanges}
            onChangeCapture={checkChanges}
            onCancel={(e) => {
                e.preventDefault();
                e.stopPropagation();
                if (confirmClose) continueEditing();
                else requestClose();
            }}
            onClick={(e) => {
                if (busy || e.target !== e.currentTarget) return;
                const r = e.currentTarget.getBoundingClientRect();
                if (e.clientX < r.left || e.clientX > r.right || e.clientY < r.top || e.clientY > r.bottom)
                    requestClose();
            }}
        >
            <header className="cte-record-modal-header">
                <div>
                    <h2 id={titleId}>{title}</h2>
                    {subtitle && <p className="portal-dialog-subtitle">{subtitle}</p>}
                </div>
                <button
                    type="button"
                    className="button"
                    disabled={busy || confirmClose}
                    onClick={requestClose}
                    aria-label="Pencereyi kapat"
                >
                    <span aria-hidden="true">×</span>
                </button>
            </header>
            <div className="cte-note-modal-scroll" hidden={confirmClose}>
                {children}
            </div>
            {actions && (
                <footer className="portal-dialog__actions" hidden={confirmClose}>
                    {actions}
                </footer>
            )}
            {confirmClose && (
                <section className="editor-panel" role="alertdialog" aria-labelledby={confirmationId}>
                    <h3 id={confirmationId}>Kaydedilmemiş değişiklikler var</h3>
                    <p>Pencereyi kapatırsanız bu değişiklikler kaybolacak.</p>
                    <div className="row-actions">
                        <button
                            type="button"
                            className="button button--primary"
                            autoFocus
                            onClick={continueEditing}
                        >
                            Düzenlemeye devam et
                        </button>
                        <button type="button" className="button button--danger" onClick={onClose}>
                            Değişiklikleri bırak
                        </button>
                    </div>
                </section>
            )}
        </dialog>
    );
}
