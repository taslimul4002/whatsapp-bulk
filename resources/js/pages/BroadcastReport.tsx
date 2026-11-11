import { Link, router, usePage, usePoll } from '@inertiajs/react';
import { Panel } from '@/components/form';
import { Button } from '@/components/ui/button';
import { useTranslation } from '@/hooks/use-translation';
import AppLayout from '@/layouts/app-layout';
import type { Shared, Workspace } from '@/types';

type Recipient = { id: number; phone: string; status: string; attempts: number; error: string | null; message_id: string | null };
type Props = { workspace: Workspace; broadcast: { id: number; name: string; scheduled_at: string }; counts: { status: string; total: number }[]; recipients: { data: Recipient[]; prev_page_url: string | null; next_page_url: string | null } };

export default function BroadcastReport({ workspace, broadcast, counts, recipients }: Props) {
    const t = useTranslation();
    const { auth } = usePage<Shared>().props;
    usePoll(10000);
    const base = `/workspaces/${workspace.id}/broadcasts/${broadcast.id}`;
    return <AppLayout title={broadcast.name}>
        <h1 className="text-2xl font-semibold">{broadcast.name}</h1>
        <Link href={`/workspaces/${workspace.id}/broadcasts`} className="text-primary underline">{t('broadcasts.title')}</Link>
        <Panel title={t('broadcasts.report')}>
            <p>{t('broadcasts.schedule')}: {broadcast.scheduled_at}</p>
            <div className="flex flex-wrap gap-4">{counts.map(item => <span key={item.status}>{t(`broadcasts.status_${item.status}`)}: {item.total}</span>)}</div>
            <p className="text-sm text-muted-foreground">{t('broadcasts.review_help')}</p>
            <ul className="divide-y divide-border">{recipients.data.map(item => <li key={item.id} className="flex flex-wrap items-center justify-between gap-2 py-3">
                <div><p dir="ltr">{item.phone}</p><p>{t(`broadcasts.status_${item.status}`)} · {t('broadcasts.attempts')}: {item.attempts}</p>{item.error && <p className="text-sm text-red-600">{item.error}</p>}{item.message_id && <p className="text-xs">{item.message_id}</p>}</div>
                {item.status === 'failed' && <Button variant="outline" onClick={() => router.post(`${base}/recipients/${item.id}/retry`, {}, { preserveScroll: true, onError: errors => alert(Object.values(errors).join('\n')) })}>{t('broadcasts.retry')}</Button>}
                {item.status === 'review' && auth.user?.type === 'admin' && <div className="flex flex-wrap gap-2">
                    <Button variant="outline" onClick={() => { const messageId = prompt(t('broadcasts.enter_message_id')); if (messageId) router.post(`${base}/recipients/${item.id}/resolve`, { outcome: 'sent', message_id: messageId }, { preserveScroll: true, onError: errors => alert(Object.values(errors).join('\n')) }); }}>{t('broadcasts.confirm_sent')}</Button>
                    <Button variant="outline" onClick={() => { if (confirm(t('broadcasts.confirm_failure_warning'))) router.post(`${base}/recipients/${item.id}/resolve`, { outcome: 'failed' }, { preserveScroll: true, onError: errors => alert(Object.values(errors).join('\n')) }); }}>{t('broadcasts.confirm_failed')}</Button>
                </div>}
            </li>)}</ul>
            <div className="flex gap-2">{recipients.prev_page_url && <Link href={recipients.prev_page_url}>{t('billing.previous')}</Link>}{recipients.next_page_url && <Link href={recipients.next_page_url}>{t('billing.next')}</Link>}</div>
        </Panel>
    </AppLayout>;
}
