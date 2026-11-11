import { Link, router, useForm } from '@inertiajs/react';
import { useState, type FormEvent } from 'react';
import { Field, Input, Panel } from '@/components/form';
import { Button } from '@/components/ui/button';
import { useTranslation } from '@/hooks/use-translation';
import AppLayout from '@/layouts/app-layout';
import type { Workspace } from '@/types';

type Classification = { id: number; name: string };
type Component = { type: string; format?: string; text?: string };
type Template = { id: number; name: string; language: string; components: Component[] };
type Broadcast = { id: number; name: string; scheduled_at: string };
type EligibleContact = { id: number; groups: { id: number }[]; tags: { id: number }[] };
type Props = { workspaces: Workspace[]; workspace: Workspace | null; groups: Classification[]; tags: Classification[]; templates: Template[]; broadcasts: { data: Broadcast[]; prev_page_url: string | null; next_page_url: string | null } | null; eligibleContacts: EligibleContact[] };

function Wizard({ workspace, groups, tags, templates, eligibleContacts }: { workspace: Workspace; groups: Classification[]; tags: Classification[]; templates: Template[]; eligibleContacts: EligibleContact[] }) {
    const t = useTranslation();
    const [step, setStep] = useState(0);
    const form = useForm({ request_id: crypto.randomUUID(), name: '', template_id: '', audience: 'all', classification_ids: [] as number[], mapping: [] as string[], scheduled_at: '' });
    const template = templates.find(item => item.id === Number(form.data.template_id));
    const variables = (template?.components ?? []).flatMap(component => {
        if (!['HEADER', 'BODY'].includes(component.type)) return [];
        const values = [...(component.text ?? '').matchAll(/\{\{(\d+)\}\}/g)].map(match => Number(match[1]));
        return [...new Set(values)].sort((a, b) => a - b).map(number => `${component.type} {{${number}}}`);
    });
    const options = form.data.audience === 'groups' ? groups : tags;
    const recipientCount = eligibleContacts.filter(contact => form.data.audience === 'all' || (form.data.audience === 'groups' ? contact.groups : contact.tags).some(item => form.data.classification_ids.includes(item.id))).length;
    function submit(event: FormEvent) {
        event.preventDefault();
        form.transform(data => ({ ...data, scheduled_at: data.scheduled_at ? new Date(data.scheduled_at).toISOString() : null }));
        form.post(`/workspaces/${workspace.id}/broadcasts`);
    }
    return <Panel title={t('broadcasts.create')}>
        <form onSubmit={submit} className="flex flex-col gap-4">
            <p>{t('broadcasts.step')} {step + 1} / 3</p>
            {step === 0 && <>
                <Field label={t('broadcasts.name')} error={form.errors.name}><Input value={form.data.name} onChange={event => form.setData('name', event.target.value)} /></Field>
                <Field label={t('broadcasts.template')} error={form.errors.template_id}><select className="h-10 rounded-lg border border-border bg-card px-3" value={form.data.template_id} onChange={event => { form.setData(data => ({ ...data, template_id: event.target.value, mapping: [] })); }}>
                    <option value="">{t('billing.select')}</option>{templates.map(item => <option key={item.id} value={item.id}>{item.name} ({item.language})</option>)}
                </select></Field>
            </>}
            {step === 1 && <>
                <Field label={t('broadcasts.audience')} error={form.errors.audience}><select className="h-10 rounded-lg border border-border bg-card px-3" value={form.data.audience} onChange={event => form.setData(data => ({ ...data, audience: event.target.value, classification_ids: [] }))}>
                    <option value="all">{t('broadcasts.all')}</option><option value="groups">{t('contacts.groups')}</option><option value="tags">{t('contacts.tags')}</option>
                </select></Field>
                {form.data.audience !== 'all' && <fieldset className="flex flex-wrap gap-3">{options.map(item => <label key={item.id} className="flex items-center gap-2"><input type="checkbox" checked={form.data.classification_ids.includes(item.id)} onChange={event => form.setData('classification_ids', event.target.checked ? [...form.data.classification_ids, item.id] : form.data.classification_ids.filter(id => id !== item.id))} />{item.name}</label>)}</fieldset>}
                {form.errors.classification_ids && <p role="alert" className="text-red-600">{form.errors.classification_ids}</p>}
                <p>{t('broadcasts.estimate', { count: recipientCount })}</p>
                {variables.map((variable, index) => <Field key={`${variable}-${index}`} label={variable}><select className="h-10 rounded-lg border border-border bg-card px-3" value={form.data.mapping[index] ?? ''} onChange={event => form.setData('mapping', variables.map((_, i) => i === index ? event.target.value : form.data.mapping[i] ?? ''))}>
                    <option value="">{t('billing.select')}</option><option value="name">{t('contacts.name')}</option><option value="phone">{t('contacts.phone')}</option><option value="email">{t('contacts.email')}</option>
                </select></Field>)}
                {form.errors.mapping && <p role="alert" className="text-red-600">{form.errors.mapping}</p>}
            </>}
            {step === 2 && <>
                <Field label={t('broadcasts.schedule')} error={form.errors.scheduled_at}><Input type="datetime-local" value={form.data.scheduled_at} onChange={event => form.setData('scheduled_at', event.target.value)} /></Field>
                <p className="text-sm text-muted-foreground">{t('broadcasts.reserve_help')}</p>
                {Object.entries(form.errors).map(([key, error]) => <p role="alert" key={key} className="text-sm text-red-600">{error}</p>)}
            </>}
            <div className="flex gap-2">
                {step > 0 && <Button type="button" variant="outline" onClick={() => setStep(step - 1)}>{t('billing.previous')}</Button>}
                {step < 2 ? <Button type="button" onClick={() => setStep(step + 1)}>{t('billing.next')}</Button> : <Button disabled={form.processing}>{t('broadcasts.submit')}</Button>}
            </div>
        </form>
    </Panel>;
}

export default function Broadcasts({ workspaces, workspace, groups, tags, templates, broadcasts, eligibleContacts }: Props) {
    const t = useTranslation();
    return <AppLayout title={t('broadcasts.title')}>
        <h1 className="text-2xl font-semibold">{t('broadcasts.title')}</h1>
        <Field label={t('contacts.workspace')}><select className="h-10 rounded-lg border border-border bg-card px-3" value={workspace?.id ?? ''} onChange={event => router.visit(event.target.value ? `/workspaces/${event.target.value}/broadcasts` : '/broadcasts')}>
            <option value="">{t('contacts.select_workspace')}</option>{workspaces.map(item => <option key={item.id} value={item.id}>{item.name}</option>)}
        </select></Field>
        {workspace && <><Wizard key={workspace.id} workspace={workspace} groups={groups} tags={tags} templates={templates} eligibleContacts={eligibleContacts} /><Panel title={t('broadcasts.title')}>
            {broadcasts?.data.map(item => <p key={item.id}><Link className="text-primary underline" href={`/workspaces/${workspace.id}/broadcasts/${item.id}`}>{item.name}</Link> — {item.scheduled_at}</p>)}
            <div className="flex gap-2">{broadcasts?.prev_page_url && <Link href={broadcasts.prev_page_url}>{t('billing.previous')}</Link>}{broadcasts?.next_page_url && <Link href={broadcasts.next_page_url}>{t('billing.next')}</Link>}</div>
        </Panel></>}
    </AppLayout>;
}
