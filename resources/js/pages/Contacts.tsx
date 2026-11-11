import { router, useForm } from '@inertiajs/react';
import { useState, type FormEvent } from 'react';
import { Field, Input, Panel } from '@/components/form';
import { Button } from '@/components/ui/button';
import { useTranslation } from '@/hooks/use-translation';
import AppLayout from '@/layouts/app-layout';
import type { Workspace } from '@/types';

type Classification = { id: number; name: string };
type Contact = { id: number; name: string; phone: string; email: string | null; opted_in: boolean; groups: Classification[]; tags: Classification[] };
type Props = { workspaces: Workspace[]; workspace: Workspace | null; contacts: Contact[]; groups: Classification[]; tags: Classification[] };

function ContactForm({ workspace, contact, groups, tags, onCancel }: { workspace: Workspace; contact: Contact | null; groups: Classification[]; tags: Classification[]; onCancel: () => void }) {
    const t = useTranslation();
    const form = useForm({
        name: contact?.name ?? '', phone: contact?.phone ?? '', email: contact?.email ?? '',
        opted_in: contact?.opted_in ?? false, groups: contact?.groups.map(item => item.id) ?? [],
        tags: contact?.tags.map(item => item.id) ?? [],
    });
    const base = `/workspaces/${workspace.id}/contacts`;
    function submit(event: FormEvent) {
        event.preventDefault();
        const options = { preserveScroll: true, onSuccess: () => { form.reset(); onCancel(); } };
        if (contact) form.put(`${base}/${contact.id}`, options);
        else form.post(base, options);
    }
    return <form onSubmit={submit} className="flex flex-col gap-4">
        <Field label={t('contacts.name')} error={form.errors.name}><Input value={form.data.name} onChange={event => form.setData('name', event.target.value)} required /></Field>
        <Field label={t('contacts.phone')} error={form.errors.phone}><Input value={form.data.phone} onChange={event => form.setData('phone', event.target.value)} placeholder="+966501234567" required /></Field>
        <Field label={t('contacts.email')} error={form.errors.email}><Input type="email" value={form.data.email} onChange={event => form.setData('email', event.target.value)} /></Field>
        <label className="flex items-center gap-2 text-sm"><input type="checkbox" checked={form.data.opted_in} onChange={event => form.setData('opted_in', event.target.checked)} />{t('contacts.opted_in')}</label>
        {(['groups', 'tags'] as const).map(kind => <fieldset key={kind} className="flex flex-wrap gap-3">
            <legend className="mb-2 text-sm font-medium">{t(`contacts.${kind}`)}</legend>
            {(kind === 'groups' ? groups : tags).map(item => <label key={item.id} className="flex items-center gap-2 text-sm">
                <input type="checkbox" checked={form.data[kind].includes(item.id)} onChange={event => form.setData(kind, event.target.checked ? [...form.data[kind], item.id] : form.data[kind].filter(id => id !== item.id))} />{item.name}
            </label>)}
            {Object.entries(form.errors).filter(([key]) => key === kind || key.startsWith(`${kind}.`)).map(([key, error]) => <span key={key} role="alert" className="text-sm text-red-600">{error}</span>)}
        </fieldset>)}
        <div className="flex gap-2"><Button disabled={form.processing}>{t('common.save')}</Button>{contact && <Button type="button" variant="outline" onClick={onCancel}>{t('contacts.cancel')}</Button>}</div>
    </form>;
}

function ClassificationForm({ base, kind, item }: { base: string; kind: 'groups' | 'tags'; item?: Classification }) {
    const t = useTranslation();
    const form = useForm({ name: item?.name ?? '' });
    const url = `${base}/classifications/${kind}${item ? `/${item.id}` : ''}`;
    function submit(event: FormEvent) {
        event.preventDefault();
        const options = { preserveScroll: true, onSuccess: () => { if (!item) form.reset(); } };
        if (item) form.put(url, options); else form.post(url, options);
    }
    return <form onSubmit={submit} className="flex flex-wrap items-end gap-2">
        <Field label={t('contacts.name')} error={form.errors.name}><Input value={form.data.name} onChange={event => form.setData('name', event.target.value)} required /></Field>
        <Button disabled={form.processing} variant="outline">{t(item ? 'common.save' : 'common.create')}</Button>
        {item && <Button type="button" variant="outline" onClick={() => { if (confirm(t('contacts.confirm_delete'))) router.delete(url, { preserveScroll: true }); }}>{t('contacts.delete')}</Button>}
    </form>;
}

function ImportForm({ base }: { base: string }) {
    const t = useTranslation();
    const [key, setKey] = useState(0);
    const form = useForm<{ file: File | null }>({ file: null });
    function submit(event: FormEvent) {
        event.preventDefault();
        form.post(`${base}/import`, { forceFormData: true, preserveScroll: true, onSuccess: () => { form.reset(); setKey(value => value + 1); } });
    }
    return <form onSubmit={submit} className="flex flex-col items-start gap-3">
        <p className="text-sm text-muted-foreground">{t('contacts.import_help')}</p>
        <Field label={t('contacts.file')} error={form.errors.file ? t(form.errors.file) : undefined}><Input key={key} type="file" accept=".csv,.xlsx" required onChange={event => form.setData('file', event.target.files?.[0] ?? null)} /></Field>
        <Button disabled={form.processing || !form.data.file}>{t('contacts.import')}</Button>
    </form>;
}

export default function Contacts({ workspaces, workspace, contacts, groups, tags }: Props) {
    const t = useTranslation();
    const [editing, setEditing] = useState<Contact | null>(null);
    const base = workspace ? `/workspaces/${workspace.id}/contacts` : '';
    return <AppLayout title={t('contacts.title')}>
        <h1 className="text-2xl font-semibold">{t('contacts.title')}</h1>
        <Field label={t('contacts.workspace')}><select className="h-10 rounded-lg border border-border bg-card px-3" value={workspace?.id ?? ''} onChange={event => { setEditing(null); router.visit(event.target.value ? `/workspaces/${event.target.value}/contacts` : '/contacts'); }}>
            <option value="">{t('contacts.select_workspace')}</option>{workspaces.map(item => <option key={item.id} value={item.id}>{item.name}</option>)}
        </select></Field>
        {workspace && <>
            <Panel title={t(editing ? 'contacts.edit' : 'contacts.create')}><ContactForm key={`${workspace.id}-${editing?.id ?? 'new'}`} workspace={workspace} contact={editing} groups={groups} tags={tags} onCancel={() => setEditing(null)} /></Panel>
            <Panel title={t('contacts.title')}>
                {!contacts.length && <p className="text-sm text-muted-foreground">{t('contacts.empty')}</p>}
                <ul className="divide-y divide-border">{contacts.map(contact => <li key={contact.id} className="flex flex-wrap items-center justify-between gap-3 py-3">
                    <div className="text-sm"><p className="font-medium">{contact.name}</p><p dir="ltr">{contact.phone} {contact.email && ` · ${contact.email}`}</p><p>{t(contact.opted_in ? 'contacts.opted_in' : 'contacts.opted_out')}</p><p>{[...contact.groups, ...contact.tags].map(item => item.name).join(', ')}</p></div>
                    <div className="flex gap-2"><Button variant="outline" onClick={() => setEditing(contact)}>{t('contacts.edit')}</Button><Button variant="outline" onClick={() => { if (confirm(t('contacts.confirm_delete'))) router.delete(`${base}/${contact.id}`, { preserveScroll: true, onSuccess: () => setEditing(null) }); }}>{t('contacts.delete')}</Button></div>
                </li>)}</ul>
            </Panel>
            {(['groups', 'tags'] as const).map(kind => <Panel key={kind} title={t(`contacts.${kind}`)}>
                <ClassificationForm key={`${workspace.id}-${kind}`} base={base} kind={kind} />
                {(kind === 'groups' ? groups : tags).map(item => <ClassificationForm key={item.id} base={base} kind={kind} item={item} />)}
            </Panel>)}
            <Panel title={t('contacts.transfer')}><ImportForm key={workspace.id} base={base} /><div className="flex gap-3 text-sm"><a className="underline" href={`${base}/export/csv`}>{t('contacts.export_csv')}</a><a className="underline" href={`${base}/export/xlsx`}>{t('contacts.export_xlsx')}</a></div></Panel>
        </>}
    </AppLayout>;
}
