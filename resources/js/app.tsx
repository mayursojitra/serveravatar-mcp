import '../css/app.css';

import { createInertiaApp } from '@inertiajs/react';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import { createRoot } from 'react-dom/client';
import { route as routeFn } from 'ziggy-js';
import { initializeTheme } from './hooks/use-appearance';

declare global {
    const route: typeof routeFn;
}

// The UI library that locks page scrolling (used by dialogs and menus) adds a <style> tag at runtime. It
// reads this global to put the page's CSP nonce on that tag, otherwise the browser would refuse the styles.
const cspNonce = document.querySelector<HTMLMetaElement>('meta[name="csp-nonce"]')?.content;
if (cspNonce) {
    (window as unknown as { __webpack_nonce__?: string }).__webpack_nonce__ = cspNonce;
}

const appName = import.meta.env.VITE_APP_NAME || 'Laravel';

createInertiaApp({
    title: (title) => `${title} - ${appName}`,
    resolve: (name) => resolvePageComponent(`./pages/${name}.tsx`, import.meta.glob('./pages/**/*.tsx')),
    setup({ el, App, props }) {
        const root = createRoot(el);

        root.render(<App {...props} />);
    },
    // The progress bar's styles live in app.css: Inertia's own <style> tag has no CSP nonce and would be blocked.
    progress: {
        includeCSS: false,
    },
});

// This will set light / dark mode on load...
initializeTheme();
