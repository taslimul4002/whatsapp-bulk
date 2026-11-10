import { useState } from 'react';
import { Panel } from '@/components/form';
import { useTranslation } from '@/hooks/use-translation';
import AppLayout from '@/layouts/app-layout';

export default function Settings() {
    const t = useTranslation();
    const [dark, setDark] = useState(() => localStorage.getItem('theme') === 'dark');
    function changeTheme(value: boolean) {
        setDark(value);
        localStorage.setItem('theme', value ? 'dark' : 'light');
        document.documentElement.classList.toggle('dark', value);
    }
    return (
        <AppLayout title={t('settings.title')}>
            <div>
                <h1 className="text-2xl font-semibold">{t('settings.title')}</h1>
                <p className="mt-1 text-sm text-muted-foreground">{t('settings.description')}</p>
            </div>
            <Panel title={t('settings.appearance')}>
                <label className="flex items-center gap-3 text-sm">
                    <input type="checkbox" checked={dark} onChange={event => changeTheme(event.target.checked)} />
                    {t('settings.dark_mode')}
                </label>
            </Panel>
        </AppLayout>
    );
}
