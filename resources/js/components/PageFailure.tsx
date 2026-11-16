import { Component, type ReactNode } from 'react';
import { portalUrl } from '../lib/portalUrl';

export default function PageFailure() {
    return (
        <main className="editor-panel" role="alert">
            <h1>Sayfa açılamadı</h1>
            <p>Sayfayı yeniden yükleyebilir veya ana sayfaya dönebilirsiniz.</p>
            <div className="row-actions">
                <button type="button" className="button" onClick={() => window.location.reload()}>
                    Yeniden dene
                </button>
                <a className="button" href={portalUrl('/')}>
                    Ana sayfaya dön
                </a>
            </div>
        </main>
    );
}

export class PortalErrorBoundary extends Component<{ children: ReactNode }, { failed: boolean }> {
    state = { failed: false };
    static getDerivedStateFromError() {
        return { failed: true };
    }
    render() {
        return this.state.failed ? <PageFailure /> : this.props.children;
    }
}
