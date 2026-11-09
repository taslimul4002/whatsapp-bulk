import '../css/app.css';
import { createInertiaApp } from '@inertiajs/react';
import { createRoot } from 'react-dom/client';
import type { ComponentType } from 'react';

document.documentElement.classList.toggle('dark', localStorage.getItem('theme') === 'dark');

createInertiaApp({
    title: title => title ? `${title} - Namaa` : 'Namaa',
    resolve: async name => {
        const pages = import.meta.glob<{ default: ComponentType }>('./pages/*.tsx');
        const page = pages[`./pages/${name}.tsx`];
        if (!page) throw new Error(`Unknown page: ${name}`);
        return (await page()).default;
    },
    setup({ el, App, props }) {
        createRoot(el).render(<App {...props} />);
    },
});
