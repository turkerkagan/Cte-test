export type BackupPlan = {
    id: string;
    name: string;
    target: string;
    frequency: 'daily' | 'weekly' | 'monthly';
    time: string;
    weekday: number;
    monthday: number;
    keep: number;
    enabled: boolean;
};
export type BackupTarget = { id: string; name: string; directory: string; is_active: boolean };
export type BackupPlanState = {
    revision: number;
    values: {
        items: (BackupPlan & { last_run?: string | null; next_run?: string | null; status?: string })[];
    };
    targetState: { revision: number; values: { items: BackupTarget[] } };
    targets: Record<string, string>;
};

/** Monitoring fields are never part of a plan update, including future read-only additions. */
export function editableBackupPlan(plan: BackupPlan): BackupPlan {
    const { id, name, target, frequency, time, weekday, monthday, keep, enabled } = plan;
    return { id, name, target, frequency, time, weekday, monthday, keep, enabled };
}
