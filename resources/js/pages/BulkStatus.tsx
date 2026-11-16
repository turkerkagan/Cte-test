import { Head } from '@inertiajs/react';
import AppShell from '../components/AppShell';
import HomeShortcuts from '../components/HomeShortcuts';
type Props = {
    applicationName: string;
    navigation?: { label: string; url: string }[];
    title: string;
    baseUrl: string;
    logoutUrl: string;
    canWrite: boolean;
    statuses: { id: number; label: string }[];
};
export default function BulkStatus(p: Props) {
    return (
        <AppShell
            search={null}
            toolbar={null}
            applicationName={p.applicationName}
            navigation={p.navigation}
            pageTitle={p.title}
        >
            <Head title={p.title} />
            <HomeShortcuts baseUrl={p.baseUrl} statuses={p.statuses} canWrite={p.canWrite} />
        </AppShell>
    );
}
