import legacyColumns from './legacyWorkflowColumns.json';
import type { Fields, State } from './workflow';

export function workflowTable(workflow: string, fields: Fields, state: State) {
    const legacy = (legacyColumns as Record<string, { key: string; label: string }[]>)[workflow];
    const defaults = legacy?.map((c) => c.key).filter((key) => key in fields);
    const configured = Object.values(fields).some((f) => f.admin_configured);
    const configuredDefaults = [
        ...(defaults ?? Object.keys(fields)).filter((key) => fields[key].table_visible !== false),
        ...Object.entries(fields)
            .filter(([key, f]) => f.admin_configured && f.table_visible && !defaults?.includes(key))
            .map(([key]) => key),
    ];
    const ordered = [
        ...(legacy ?? [])
            .filter((c) => c.key in fields)
            .map(
                (c) =>
                    [
                        c.key,
                        {
                            ...fields[c.key],
                            label: fields[c.key].admin_configured ? fields[c.key].label : c.label,
                        },
                    ] as const,
            ),
        ...Object.entries(fields).filter(([key]) => !legacy?.some((c) => c.key === key)),
    ];
    return {
        orderedFields: configured
            ? [...ordered].sort(
                  (a, b) => Number(a[1].admin_position ?? -1) - Number(b[1].admin_position ?? -1),
              )
            : workflow === 'custom_rapor' && state.columns
              ? state.columns.filter((key) => key in fields).map((key) => [key, fields[key]] as const)
              : ordered,
        hidden: Object.keys(fields).filter(
            (key) =>
                !['tc_identity', 'full_name'].includes(key) &&
                !(
                    state.columns ??
                    (configured ? configuredDefaults : defaults) ??
                    Object.keys(fields)
                ).includes(key),
        ),
        sequenceLabel: 'ID',
    };
}
