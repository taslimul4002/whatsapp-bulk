import { Head, Link, usePage } from '@inertiajs/react';
import { Building2, LayoutDashboard, LogOut, Menu, MessageCircle, Settings, UserRound, Users, X } from 'lucide-react';
import { useState, type ReactNode } from 'react';
import { Button } from '@/components/ui/button';
import { cn } from '@/lib/utils';
import type { Shared } from '@/types';

export default function AppLayout({ title, children }: { title: string; children: ReactNode }) {
    const { auth, flash } = usePage<Shared>().props;
    const [open, setOpen] = useState(false);
    const can = (permission: string) => auth.user?.type === 'admin' || auth.permissions.includes(permission);
    const links = [
        { label: 'Dashboard', href: '/dashboard', icon: LayoutDashboard, visible: can('dashboard.view') },
        { label: 'Users', href: '/users', icon: Users, visible: auth.user?.type === 'admin' },
        { label: 'Workspaces', href: '/workspaces', icon: Building2, visible: can('workspaces.view') },
        { label: 'Profile', href: '/profile', icon: UserRound, visible: true },
        { label: 'Settings', href: '/settings', icon: Settings, visible: true },
    ];
    const currentUrl = usePage().url.split('?')[0];

    return <div className="min-h-screen md:flex">
        <Head title={title} />
        {open && <button aria-label="Close navigation" className="fixed inset-0 z-30 bg-black/50 md:hidden" onClick={() => setOpen(false)} />}
        <aside className={cn('fixed inset-y-0 left-0 z-40 flex w-64 flex-col border-r border-border bg-card p-5 transition-transform md:sticky md:top-0 md:h-screen md:translate-x-0', open ? 'translate-x-0' : '-translate-x-full')}>
            <div className="flex items-center justify-between gap-3 pb-8"><div className="flex items-center gap-2 font-bold"><span className="rounded-lg bg-primary p-2 text-primary-foreground"><MessageCircle size={19} /></span>Namaa</div><Button aria-label="Close menu" variant="ghost" size="icon" className="md:hidden" onClick={() => setOpen(false)}><X size={20} /></Button></div>
            <p className="px-3 pb-3 text-xs font-semibold uppercase tracking-wider text-muted-foreground">Workspace</p>
            <nav aria-label="Main navigation" className="flex flex-col gap-1">{links.filter(link => link.visible).map(({ label, href, icon: Icon }) => <Link key={href} href={href} aria-current={currentUrl === href ? 'page' : undefined} onClick={() => setOpen(false)} className={cn('flex items-center gap-3 rounded-lg px-3 py-2.5 text-sm font-medium text-muted-foreground hover:bg-muted hover:text-foreground', currentUrl === href && 'bg-muted text-primary')}><Icon size={18} />{label}</Link>)}</nav>
            <div className="mt-auto border-t border-border pt-4"><p className="truncate text-sm font-medium">{auth.user?.name}</p><p className="truncate text-xs text-muted-foreground">{auth.user?.email}</p></div>
        </aside>
        <div className="min-w-0 flex-1">
            <header className="flex h-16 items-center justify-between gap-4 border-b border-border bg-card px-4 md:px-8">
                <div className="flex items-center gap-2"><Button aria-label="Open menu" variant="ghost" size="icon" className="md:hidden" onClick={() => setOpen(true)}><Menu size={20} /></Button><span className="font-semibold">{title}</span></div>
                <div className="flex items-center gap-3"><span className="hidden text-sm text-muted-foreground sm:block">{auth.user?.name}</span><Button asChild variant="outline"><Link href="/logout" method="post" as="button"><LogOut size={16} />Sign out</Link></Button></div>
            </header>
            <main className="mx-auto flex max-w-6xl flex-col gap-6 p-4 md:p-8">
                {flash.success && <div role="status" className="rounded-lg border border-primary bg-card p-3 text-sm">{flash.success}</div>}
                {children}
            </main>
        </div>
    </div>;
}
