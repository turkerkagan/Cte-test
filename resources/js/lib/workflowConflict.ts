import type { WorkflowEditorProps } from '../pages/WorkflowEditor';
import type { Values } from './workflow';
import type { RecordValues } from './recordConflict';
import { workspaceRequest } from './workspace';

export function editableValues(props: Pick<WorkflowEditorProps, 'fields'>, values: Values): RecordValues {
    return Object.fromEntries(
        Object.entries(props.fields)
            .filter(([, field]) => !field.readonly)
            .map(([key]) => [key, values[key] ?? null]),
    );
}
export async function workflowSnapshot(
    props: WorkflowEditorProps,
    signal: AbortSignal,
): Promise<WorkflowEditorProps> {
    const latest = await workspaceRequest<WorkflowEditorProps>(props.submitUrl, undefined, signal);
    if (
        !latest.canWrite ||
        latest.person.id !== props.person.id ||
        latest.workflow !== props.workflow ||
        JSON.stringify(latest.fields) !== JSON.stringify(props.fields)
    ) {
        throw new Error('Form tanımı veya yazma yetkisi değişti. Girdileriniz korunuyor; güncel formu açın.');
    }
    return latest;
}
