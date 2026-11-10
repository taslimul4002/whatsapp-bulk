import { Panel } from '@/components/form';
import { useTranslation } from '@/hooks/use-translation';
import AppLayout from '@/layouts/app-layout';

export default function Dashboard() {
    const t = useTranslation();

    return (
        <AppLayout title={t('dashboard.title')}>
            <div>
                <h1 className="text-2xl font-semibold">{t('dashboard.title')}</h1>
                <p className="mt-1 text-sm text-muted-foreground">{t('dashboard.description')}</p>
            </div>
            <Panel title={t('dashboard.getting_started')}>
                <p className="text-sm text-muted-foreground">{t('dashboard.foundation_ready')}</p>
            </Panel>
        </AppLayout>
    );
}
