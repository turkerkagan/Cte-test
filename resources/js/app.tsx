import { resolvePage } from './lib/pageResolver';
import PageFailure, { PortalErrorBoundary } from './components/PageFailure';
import { createInertiaApp } from '@inertiajs/react';
import { createRoot } from 'react-dom/client';
import '../css/app.css';
import '../css/frontend-parity.css';
import '../css/announcements.css';
import '../css/shared-tables.css';
import '../css/chat.css';
import '../css/administration.css';

void createInertiaApp({
    resolve: resolvePage,
    setup({ el, App, props }) {
        createRoot(el).render(
            <PortalErrorBoundary>
                <App {...props} />
            </PortalErrorBoundary>,
        );
    },
    progress: { color: '#16716b' },
}).catch(() => {
    const el = document.getElementById('app');
    if (el) createRoot(el).render(<PageFailure />);
});
