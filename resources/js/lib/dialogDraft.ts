type Control = HTMLInputElement | HTMLTextAreaElement | HTMLSelectElement;

export function controlValue(control: Control): string {
    if (control instanceof HTMLInputElement && ['checkbox', 'radio'].includes(control.type))
        return String(control.checked);
    if (control instanceof HTMLSelectElement && control.multiple)
        return JSON.stringify(Array.from(control.selectedOptions, (option) => option.value));
    return control.value;
}

/** Track DOM controls too, so existing dialog forms gain protection without copying their data. */
export function dialogDraft() {
    const initial = new Map<Control, string>();
    return {
        register(root: HTMLElement) {
            root.querySelectorAll<Control>(
                'input:not([type=hidden]):not([type=submit]):not([type=button]),textarea,select',
            ).forEach((control) => {
                if (!initial.has(control)) initial.set(control, controlValue(control));
            });
        },
        changed(root: HTMLElement): boolean {
            return Array.from(initial).some(
                ([control, value]) => root.contains(control) && controlValue(control) !== value,
            );
        },
    };
}
