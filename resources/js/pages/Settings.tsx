import { useState } from 'react';
import { Panel } from '@/components/form';
import AppLayout from '@/layouts/app-layout';

export default function Settings() {
    const [dark, setDark] = useState(() => localStorage.getItem('theme') === 'dark');
    function changeTheme(value: boolean) {
        setDark(value);
        localStorage.setItem('theme', value ? 'dark' : 'light');
        document.documentElement.classList.toggle('dark', value);
    }
    return <AppLayout title="Settings"><div><h1 className="text-2xl font-semibold">Settings</h1><p className="mt-1 text-sm text-muted-foreground">Personalize your experience on this device.</p></div><Panel title="Appearance"><label className="flex items-center gap-3 text-sm"><input type="checkbox" checked={dark} onChange={event => changeTheme(event.target.checked)} />Dark mode</label></Panel></AppLayout>;
}
