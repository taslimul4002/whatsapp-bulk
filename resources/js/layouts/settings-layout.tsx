import { Link } from '@inertiajs/react';
import { Coins, CreditCard, MessageCircle } from 'lucide-react';
import type { ReactNode } from 'react';
import { useTranslation } from '@/hooks/use-translation';
import AppLayout from '@/layouts/app-layout';
import { cn } from '@/lib/utils';
import { usePage } from '@inertiajs/react';
import type { Shared } from '@/types';

type SettingsSection = 'currency' | 'whatsapp' | 'billing';

export default function SettingsLayout({ section, children }: { section: SettingsSection; children: ReactNode }) {
    const t = useTranslation();
    const { auth } = usePage<Shared>().props;
    const sections = [
        { id: 'currency', label: t('currency.title'), href: '/settings/currency', icon: Coins },
        ...(auth.user?.type === 'admin' ? [{ id: 'billing', label: t('billing.title'), href: '/settings/billing', icon: CreditCard }] : []),
        ...(auth.user?.type === 'admin' ? [{ id: 'whatsapp', label: t('whatsapp.title'), href: '/settings/whatsapp', icon: MessageCircle }] : []),
    ] as const;

    return (
        <AppLayout title={t('settings.title')}>
            <h1 className="text-2xl font-semibold">{t('settings.title')}</h1>
            <div className="grid gap-6 md:grid-cols-[12rem_minmax(0,1fr)]">
                <nav aria-label={t('settings.title')} className="flex flex-col gap-1">
                    {sections.map(({ id, label, href, icon: Icon }) => (
                        <Link key={id} href={href} aria-current={section === id ? 'page' : undefined}
                            className={cn('flex items-center gap-3 rounded-lg px-3 py-2.5 text-sm font-medium text-muted-foreground hover:bg-muted hover:text-foreground', section === id && 'bg-muted text-primary')}>
                            <Icon size={18} aria-hidden="true" />
                            {label}
                        </Link>
                    ))}
                </nav>
                <div className="flex min-w-0 flex-col gap-6">{children}</div>
            </div>
        </AppLayout>
    );
}
