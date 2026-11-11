import { Head, Link, router, usePage } from '@inertiajs/react';
import { Building2, Languages, LayoutDashboard, LogOut, Menu, MessageCircle, Moon, Settings, Sun, UserRound, Users, X } from 'lucide-react';
import { useState, type ReactNode } from 'react';
import { Button } from '@/components/ui/button';
import { useTranslation } from '@/hooks/use-translation';
import { cn } from '@/lib/utils';
import type { Shared } from '@/types';

export default function AppLayout({ title, children }: { title: string; children: ReactNode }) {
    const { auth, flash, locale, currency } = usePage<Shared>().props;
    const t = useTranslation();
    const [open, setOpen] = useState(false);
    const [dark, setDark] = useState(() => document.documentElement.classList.contains('dark'));
    const [currencyPending, setCurrencyPending] = useState(false);
    const [currencyError, setCurrencyError] = useState('');
    function toggleTheme() {
        const next = !dark;
        setDark(next);
        localStorage.setItem('theme', next ? 'dark' : 'light');
        document.documentElement.classList.toggle('dark', next);
    }
    function changeCurrency(code: string) {
        setCurrencyError('');
        router.patch('/settings/currency/preference', { currency: code }, {
            preserveScroll: true,
            onStart: () => setCurrencyPending(true),
            onError: errors => setCurrencyError(errors.currency || t('currency.update_failed')),
            onFinish: () => setCurrencyPending(false),
        });
    }
    const can = (permission: string) => auth.user?.type === 'admin' || auth.permissions.includes(permission);
    const targetLocale = locale === 'en' ? 'ar' : 'en';
    const links = [
        { label: t('nav.dashboard'), href: '/dashboard', icon: LayoutDashboard, visible: can('dashboard.view') },
        { label: t('nav.users'), href: '/users', icon: Users, visible: auth.user?.type === 'admin' },
        { label: t('nav.workspaces'), href: '/workspaces', icon: Building2, visible: can('workspaces.view') },
        { label: t('nav.profile'), href: '/profile', icon: UserRound, visible: true },
        { label: t('nav.settings'), href: '/settings', icon: Settings, visible: true },
        { label: t('nav.translations'), href: '/translations', icon: Languages, visible: auth.user?.type === 'admin' },
    ];
    const currentUrl = usePage().url.split('?')[0];

    return (
        <div className="min-h-screen md:flex">
            <Head title={title} />
            {
                open && <button aria-label={t('common.close_navigation')} className="fixed inset-0 z-30 bg-black/50 md:hidden" onClick={() => setOpen(false)} />
            }
            <aside className={cn('fixed inset-y-0 start-0 z-40 flex w-64 flex-col border-e border-border bg-card p-5 transition-transform md:sticky md:top-0 md:h-screen', open ? 'translate-x-0' : 'max-md:-translate-x-full max-md:rtl:translate-x-full')}>
                <div className="flex items-center justify-between gap-3 pb-8">
                    <div className="flex items-center gap-2 font-bold">
                        <span className="rounded-lg bg-primary p-2 text-primary-foreground">
                            <MessageCircle size={19} />
                        </span>Namaa
                    </div>
                    <Button aria-label={t('common.close_menu')} variant="ghost" size="icon" className="md:hidden" 
                    onClick={() => setOpen(false)}><X size={20} />
                    </Button>
                </div>
                <p className="px-3 pb-3 text-xs font-semibold uppercase tracking-wider text-muted-foreground">{t('nav.workspace')}</p>
                <nav aria-label={t('nav.main_navigation')} className="flex flex-col gap-1">
                    {
                        links.filter(link => link.visible).map(
                            ({
                                label, 
                                href, 
                                icon: Icon 
                            }) => 
                            <Link key={href} href={href} aria-current={currentUrl === href || (href === '/settings' && currentUrl.startsWith('/settings/')) ? 'page' : undefined}
                                onClick={() => setOpen(false)} className={
                                    cn('flex items-center gap-3 rounded-lg px-3 py-2.5 text-sm font-medium text-muted-foreground hover:bg-muted hover:text-foreground', (currentUrl === href || (href === '/settings' && currentUrl.startsWith('/settings/'))) && 'bg-muted text-primary')
                                }>
                                <Icon size={18} />{label}
                            </Link>
                        )
                    }
                </nav>
                <div className="mt-auto border-t border-border pt-4">
                    <p className="truncate text-sm font-medium">{auth.user?.name}</p>
                    <p className="truncate text-xs text-muted-foreground">{auth.user?.email}</p>
                </div>
            </aside>
            <div className="min-w-0 flex-1">
                <header className="flex min-h-16 flex-wrap items-center justify-between gap-2 border-b border-border bg-card px-4 py-2 md:px-8">
                    <div className="flex min-w-0 items-center gap-2">
                        <Button aria-label={t('common.open_menu')} variant="ghost" size="icon" className="md:hidden" onClick={() => setOpen(true)}>
                            <Menu size={20} />
                        </Button>

                        <span className="truncate font-semibold">{title}</span>
                    </div>
                    <div className="flex items-center gap-1 sm:gap-3">
                        <span className="hidden text-sm text-muted-foreground sm:block">{auth.user?.name}</span>
                        <div>
                            <select aria-label={t('currency.select')} value={currency.selected} disabled={currencyPending}
                                onChange={event => changeCurrency(event.target.value)}
                                className="h-10 rounded-lg border border-border bg-card px-2 text-sm cursor-pointer">
                                {currency.available.map(code => <option key={code} value={code}>{code}</option>)}
                            </select>
                            {currencyError && <p role="alert" className="absolute end-4 top-16 z-10 rounded-lg border border-border bg-card p-2 text-sm text-red-600">{currencyError}</p>}
                        </div>
                        <Button type="button" className="cursor-pointer" variant="outline" size="icon" aria-label={t(dark ? 'settings.switch_to_light' : 'settings.switch_to_dark')}
                            title={t(dark ? 'settings.switch_to_light' : 'settings.switch_to_dark')} onClick={toggleTheme}>
                            {dark ? <Sun size={18} /> : <Moon size={18} />}
                        </Button>
                        <Button asChild variant="outline" className="cursor-pointer">
                            <Link href={`/locale/${targetLocale}`} method="post" as="button" aria-label={t('common.switch_language')}>
                                {targetLocale === 'ar' ? 'العربية' : 'English'}
                            </Link>
                        </Button>
                        <Button asChild variant="outline" className="cursor-pointer">
                            <Link href="/logout" method="post" as="button" aria-label={t('nav.sign_out')}>
                                <LogOut size={16} />
                                <span className="hidden sm:inline">{t('nav.sign_out')}</span>
                            </Link>
                        </Button>
                    </div>
                </header>
                <main className="mx-auto flex max-w-6xl flex-col gap-6 p-4 md:p-8">
                    {
                        flash.success && <div role="status" className="rounded-lg border border-primary bg-card p-3 text-sm">{t(flash.success)}</div>
                    }
                    {children}
                </main>
            </div>
        </div>
    );
}
