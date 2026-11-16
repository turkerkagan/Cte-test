import type { BackupPlanState } from './backupPlan';

export type DefinitionItem = {
    key?: string;
    code?: string;
    label: string;
    type?: string;
    group?: string;
    position: number;
    is_active?: boolean;
    table_visible?: boolean;
    form_visible?: boolean;
    required?: boolean;
    readonly?: boolean;
    filter_visible?: boolean;
    sortable?: boolean;
    max_length?: number;
    default?: string | null;
    option_list?: string | null;
};
export type AdminRow = {
    [key: string]: unknown;
    id?: number | string;
    revision?: number;
    user_id?: number | null;
    user_revision?: number;
    name?: string;
    path?: string;
    username?: string;
    full_name?: string;
    department?: string;
    job_title?: string;
    email?: string;
    phone?: string;
    message?: string;
    status?: string;
    kind?: string;
    resolution?: string;
    resource?: string;
    action?: string;
    outcome?: string;
    created_at?: string;
    deleted_at?: string;
    last_login_at?: string;
    code?: string;
    key?: string;
    label?: string;
    type?: string;
    is_active?: boolean;
    bytes?: number | string;
    details?: Record<string, unknown>;
    summary?: Record<string, unknown>;
};
export type AdminResult = {
    [key: string]: unknown;
    message?: string;
    errors?: Record<string, string[]>;
    redirect?: string;
    id?: number;
    download?: string;
    download_sql?: string;
    checksum?: string;
    target_checksum?: string;
    unused_columns?: string[];
    unsupported?: unknown[];
    verified?: boolean;
    schemaMatches?: boolean;
};
export type QueryState = Record<string, string | number | undefined>;
export type Appearance = {
    [key: string]: string | string[] | DefinitionItem[] | undefined;
    login_features?: string[];
    items?: DefinitionItem[];
};
export type AdminProps = {
    mode: string;
    title: string;
    applicationName: string;
    baseUrl: string;
    navigation?: { label: string; url: string }[];
    revision?: number;
    values?: Appearance;
    path: string;
    scope: string;
    text?: string;
    checksum?: string;
    bytes?: number;
    isFile?: boolean;
    editable?: boolean;
    actorId?: number;
    sessionMeasurement?: boolean;
    queryState: QueryState;
    pagination?: { page: number; pages: number; total: number };
    overviewContext?: { date: string; name: string };
    summary: Record<string, number>;
    health: Record<string, string | boolean>;
    lastBackup?: AdminRow | null;
    recent: AdminRow[];
    rows: AdminRow[];
    requests: AdminRow[];
    events: AdminRow[];
    backups: AdminRow[];
    entries: AdminRow[];
    trash: AdminRow[];
    versions: { id: string; at: string }[];
    resources: string[];
    actions: string[];
    pending: Record<string, number>;
    database: string;
    databases: string[];
    tables: AdminRow[];
    columns: { name: string; type_name: string }[];
    defaults: Record<string, DefinitionItem>;
    groups: Record<string, string>;
    sources: Record<string, string>;
    optionLists: { code: string; label: string }[];
    plans: BackupPlanState;
};

export function isRecord(value: unknown): value is Record<string, unknown> {
    return typeof value === 'object' && value !== null && !Array.isArray(value);
}

export function adminResult(value: unknown): AdminResult {
    if (!isRecord(value)) throw new Error('Sunucunun yanıtı geçersiz.');
    for (const key of ['message', 'redirect', 'download', 'download_sql', 'checksum', 'target_checksum']) {
        if (value[key] !== undefined && typeof value[key] !== 'string')
            throw new Error('Sunucunun yanıtı geçersiz.');
    }
    if (
        value.unused_columns !== undefined &&
        (!Array.isArray(value.unused_columns) || !value.unused_columns.every((v) => typeof v === 'string'))
    )
        throw new Error('Sunucunun yanıtı geçersiz.');
    for (const key of ['verified', 'schemaMatches'])
        if (value[key] !== undefined && typeof value[key] !== 'boolean')
            throw new Error('Sunucunun yanıtı geçersiz.');
    if (value.unsupported !== undefined && !Array.isArray(value.unsupported))
        throw new Error('Sunucunun yanıtı geçersiz.');
    return value as AdminResult;
}
