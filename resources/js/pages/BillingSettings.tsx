import { Link, router, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { Field, Input, Panel } from '@/components/form';
import { Button } from '@/components/ui/button';
import { useTranslation } from '@/hooks/use-translation';
import SettingsLayout from '@/layouts/settings-layout';

type Plan = { id: number; name: string; price_cents: number; credits: number; expiry_days: number | null; active: boolean | number };
type Workspace = { id: number; name: string };
type Purchase = { id: number; workspace_name: string; plan_name: string; price_cents: number; credits: number; status: string; invoice_number: string | null; expires_at: string | null; review_required: boolean | number };
type Ledger = { id: number; workspace_name: string; purchase_id: number; type: string; credits: number; created_at: string };
type Transaction = { id: number; purchase_id: number; type: string; provider_id: string; amount_cents: number; created_at: string };
type Page<T> = { data: T[]; prev_page_url: string | null; next_page_url: string | null; current_page: number; last_page: number };
type Props = { plans: Plan[]; workspaces: Workspace[]; purchases: Page<Purchase>; ledger: Page<Ledger>; transactions: Page<Transaction>; balances: { workspace_id: number; credits: number }[] };

function Pagination({ page }: { page: Page<unknown> }) {
    const t = useTranslation();
    return <nav className="flex items-center gap-3 text-sm">
        {page.prev_page_url && <Link className="underline" href={page.prev_page_url} preserveScroll>{t('billing.previous')}</Link>}
        <span>{page.current_page} / {page.last_page}</span>
        {page.next_page_url && <Link className="underline" href={page.next_page_url} preserveScroll>{t('billing.next')}</Link>}
    </nav>;
}

function PlanForm({ plan }: { plan?: Plan }) {
    const t = useTranslation();
    const form = useForm({
        name: plan?.name ?? '', price_cents: plan?.price_cents?.toString() ?? '',
        credits: plan?.credits?.toString() ?? '', expiry_days: plan?.expiry_days?.toString() ?? '',
        active: plan ? Boolean(plan.active) : true,
    });
    function submit(event: FormEvent) {
        event.preventDefault();
        if (plan) form.put(`/settings/billing/plans/${plan.id}`, { preserveScroll: true });
        else form.post('/settings/billing/plans', { preserveScroll: true, onSuccess: () => form.reset() });
    }
    return <form onSubmit={submit} className="flex flex-col gap-3 rounded-lg border border-border p-4">
        <Field label={t('billing.name')} error={form.errors.name}><Input value={form.data.name} onChange={e => form.setData('name', e.target.value)} required maxLength={120} /></Field>
        <div className="grid gap-3 sm:grid-cols-3">
            <Field label={t('billing.price')} error={form.errors.price_cents}><Input type="number" min="1" value={form.data.price_cents} onChange={e => form.setData('price_cents', e.target.value)} required /></Field>
            <Field label={t('billing.credits')} error={form.errors.credits}><Input type="number" min="1" value={form.data.credits} onChange={e => form.setData('credits', e.target.value)} required /></Field>
            <Field label={t('billing.expiry')} error={form.errors.expiry_days}><Input type="number" min="1" value={form.data.expiry_days} onChange={e => form.setData('expiry_days', e.target.value)} /></Field>
        </div>
        <label className="flex items-center gap-2 text-sm"><input type="checkbox" checked={form.data.active} onChange={e => form.setData('active', e.target.checked)} />{t('billing.active')}</label>
        <Button className="self-start" disabled={form.processing}>{t(plan ? 'common.save' : 'billing.create')}</Button>
    </form>;
}

export default function BillingSettings({ plans, workspaces, purchases, ledger, transactions, balances }: Props) {
    const t = useTranslation();
    const purchase = useForm({ workspace_id: '', plan_id: '' });
    const money = (cents: number) => `USD ${(cents / 100).toFixed(2)}`;
    function buy(event: FormEvent) {
        event.preventDefault();
        purchase.post('/settings/billing/purchases');
    }
    return <SettingsLayout section="billing">
        <div><h2 className="text-xl font-semibold">{t('billing.title')}</h2><p className="mt-1 text-sm text-muted-foreground">{t('billing.description')}</p></div>
        <p className="text-sm text-muted-foreground">{t('billing.setup')}</p>
        <Panel title={t('billing.plans')}>
            {plans.map(plan => <PlanForm key={plan.id} plan={plan} />)}
            <PlanForm />
        </Panel>
        <Panel title={t('billing.purchase')}>
            <form onSubmit={buy} className="flex flex-col gap-4">
                <Field label={t('billing.workspace')} error={purchase.errors.workspace_id}>
                    <select className="h-10 rounded-lg border border-border bg-card px-3" required value={purchase.data.workspace_id} onChange={e => purchase.setData('workspace_id', e.target.value)}>
                        <option value="">{t('billing.select')}</option>{workspaces.map(w => <option key={w.id} value={w.id}>{w.name}</option>)}
                    </select>
                </Field>
                <Field label={t('billing.package')} error={purchase.errors.plan_id}>
                    <select className="h-10 rounded-lg border border-border bg-card px-3" required value={purchase.data.plan_id} onChange={e => purchase.setData('plan_id', e.target.value)}>
                        <option value="">{t('billing.select')}</option>{plans.filter(p => p.active).map(p => <option key={p.id} value={p.id}>{p.name} — {money(p.price_cents)} — {p.credits}</option>)}
                    </select>
                </Field>
                <Button className="self-start" disabled={purchase.processing}>{t('billing.pay')}</Button>
            </form>
        </Panel>
        <Panel title={t('billing.balances')}>
            {workspaces.length === 0 ? <p>{t('billing.none')}</p> : workspaces.map(w => <p key={w.id}>{w.name}: {balances.find(b => b.workspace_id === w.id)?.credits ?? 0}</p>)}
        </Panel>
        <Panel title={t('billing.purchases')}>
            {purchases.data.length === 0 && <p>{t('billing.none')}</p>}
            {purchases.data.map(p => <div key={p.id} className="flex flex-col gap-2 rounded-lg border border-border p-4 text-sm">
                <p>#{p.id} — {p.workspace_name} — {p.plan_name} — {money(p.price_cents)} — {p.credits} {t('billing.credits')}</p>
                <p>{t('billing.status')}: {p.status}{Boolean(p.review_required) && ` — ${t('billing.review')}`}</p>
                {p.invoice_number && <p>{t('billing.invoice')}: {p.invoice_number}</p>}
                {p.expires_at && <p>{t('billing.expired')}: {p.expires_at}</p>}
                <div className="flex flex-wrap gap-2">
                    {['creating', 'pending'].includes(p.status) && <Button variant="outline" type="button" onClick={() => router.post(`/settings/billing/purchases/${p.id}/checkout`)}>{t('billing.retry')}</Button>}
                    {p.status === 'pending' && <Button type="button" onClick={() => router.post(`/settings/billing/purchases/${p.id}/capture`)}>{t('billing.capture')}</Button>}
                    {['paid', 'refund_pending'].includes(p.status) && <Button variant="outline" type="button" onClick={() => { if (window.confirm(t('billing.refund_confirm'))) router.post(`/settings/billing/purchases/${p.id}/refund`); }}>{t('billing.refund')}</Button>}
                </div>
            </div>)}
            <Pagination page={purchases} />
        </Panel>
        <Panel title={t('billing.transactions')}>
            {transactions.data.length === 0 && <p>{t('billing.none')}</p>}
            {transactions.data.map(tx => <p key={tx.id} className="break-all text-sm">#{tx.purchase_id} — {tx.type} — {money(tx.amount_cents)} — {tx.provider_id} — {tx.created_at}</p>)}
            <Pagination page={transactions} />
        </Panel>
        <Panel title={t('billing.ledger')}>
            {ledger.data.length === 0 && <p>{t('billing.none')}</p>}
            {ledger.data.map(entry => <p key={entry.id} className="text-sm">{entry.workspace_name} — #{entry.purchase_id} — {entry.type} — {entry.credits} — {entry.created_at}</p>)}
            <Pagination page={ledger} />
        </Panel>
    </SettingsLayout>;
}
