import { router, useForm, usePage } from '@inertiajs/react';
import { useState, type FormEvent } from 'react';
import { Field, Input, Panel } from '@/components/form';
import { Button } from '@/components/ui/button';
import { useTranslation } from '@/hooks/use-translation';
import AppLayout from '@/layouts/app-layout';

type ButtonData = { type: string; text: string; value: string };
type Component = { type: string; format?: string; text?: string; example?: { header_text?: string[]; header_handle?: string[]; body_text?: string[][] }; buttons?: { type: string; text: string; url?: string; phone_number?: string }[] };
type Template = { id: number; name: string; language: string; category: string; status: string; reason: string | null; components: Component[] };
type Props = { configured: boolean; templates: Template[] };

function Preview({ components }: { components: Component[] }) {
    const t = useTranslation();
    return <div className="flex flex-col gap-2 rounded-lg bg-muted p-4 text-sm">
        <p className="font-semibold">{t('templates.preview')}</p>
        {components.map((component, index) => {
            if (component.type === 'BUTTONS') return <div key={index} className="flex flex-col gap-1">{component.buttons?.map((button, i) => <span key={i} className="rounded border border-border p-2 text-center">{button.text}{button.url ? ` · ${button.url}` : button.phone_number ? ` · ${button.phone_number}` : ''}</span>)}</div>;
            if (component.type === 'HEADER' && component.format && component.format !== 'TEXT') return <div key={index} className="rounded border border-border p-4 text-center">{component.format}</div>;
            const examples = component.type === 'BODY' ? component.example?.body_text?.[0] : component.example?.header_text;
            const text = component.text?.replace(/\{\{(\d+)\}\}/g, (variable, number: string) => examples?.[Number(number) - 1] || variable);
            return <p key={index} className={`whitespace-pre-wrap break-words ${component.type === 'HEADER' ? 'font-bold' : component.type === 'FOOTER' ? 'text-muted-foreground' : ''}`}>{text}</p>;
        })}
    </div>;
}

function TemplateForm({ template, onCancel }: { template: Template | null; onCancel: () => void }) {
    const t = useTranslation();
    const header = template?.components.find(item => item.type === 'HEADER');
    const body = template?.components.find(item => item.type === 'BODY');
    const footer = template?.components.find(item => item.type === 'FOOTER');
    const buttons = template?.components.find(item => item.type === 'BUTTONS');
    const form = useForm({
        name: template?.name ?? '', language: template?.language ?? 'en_US', category: template?.category ?? 'UTILITY',
        header_format: header?.format ?? 'NONE', header_text: header?.text ?? '',
        header_example: header?.example?.header_text?.[0] ?? header?.example?.header_handle?.[0] ?? '',
        body: body?.text ?? '', body_examples: body?.example?.body_text?.[0] ?? [] as string[],
        footer: footer?.text ?? '',
        buttons: buttons?.buttons?.map(item => ({ type: item.type, text: item.text, value: item.url ?? item.phone_number ?? '' })) ?? [] as ButtonData[],
    });
    const data = form.data;
    const preview: Component[] = [
        ...(data.header_format === 'NONE' ? [] : [{ type: 'HEADER', format: data.header_format, text: data.header_text, example: { header_text: [data.header_example] } }]),
        { type: 'BODY', text: data.body, example: { body_text: [data.body_examples] } },
        ...(data.footer ? [{ type: 'FOOTER', text: data.footer }] : []),
        ...(data.buttons.length ? [{ type: 'BUTTONS', buttons: data.buttons.map(item => ({ type: item.type, text: item.text, url: item.type === 'URL' ? item.value : undefined, phone_number: item.type === 'PHONE_NUMBER' ? item.value : undefined })) }] : []),
    ];
    function submit(event: FormEvent) {
        event.preventDefault();
        const options = { preserveScroll: true, onSuccess: () => { form.reset(); onCancel(); } };
        if (template) form.put(`/templates/${template.id}`, options); else form.post('/templates', options);
    }
    function changeButton(index: number, patch: Partial<ButtonData>) {
        form.setData('buttons', data.buttons.map((button, i) => i === index ? { ...button, ...patch } : button));
    }
    return <form onSubmit={submit} className="grid gap-5 lg:grid-cols-2">
        <div className="flex flex-col gap-4">
            <Field label={t('templates.name')} error={form.errors.name}><Input value={data.name} onChange={e => form.setData('name', e.target.value)} required /></Field>
            <Field label={t('templates.language')} error={form.errors.language}><Input value={data.language} onChange={e => form.setData('language', e.target.value)} required /></Field>
            <Field label={t('templates.category')} error={form.errors.category}><select className="h-10 rounded-lg border border-border bg-card px-3" value={data.category} onChange={e => form.setData('category', e.target.value)}><option value="UTILITY">UTILITY</option><option value="MARKETING">MARKETING</option></select></Field>
            <Field label={t('templates.header')} error={form.errors.header_format}><select className="h-10 rounded-lg border border-border bg-card px-3" value={data.header_format} onChange={e => form.setData('header_format', e.target.value)}>{['NONE', 'TEXT', 'IMAGE', 'VIDEO', 'DOCUMENT'].map(value => <option key={value} value={value}>{value}</option>)}</select></Field>
            {data.header_format === 'TEXT' && <Field label={t('templates.header_text')} error={form.errors.header_text}><Input value={data.header_text} onChange={e => form.setData('header_text', e.target.value)} required /></Field>}
            {data.header_format !== 'NONE' && <Field label={t(data.header_format === 'TEXT' ? 'templates.header_example' : 'templates.media_handle')} error={form.errors.header_example ? t(form.errors.header_example) : undefined}><Input value={data.header_example} onChange={e => form.setData('header_example', e.target.value)} /></Field>}
            <Field label={t('templates.body')} error={form.errors.body ? t(form.errors.body) : undefined}><textarea className="min-h-28 rounded-lg border border-border bg-card p-3" value={data.body} onChange={e => form.setData('body', e.target.value)} required /></Field>
            <Field label={t('templates.body_examples')} error={form.errors.body_examples ? t(form.errors.body_examples) : undefined}><textarea className="min-h-20 rounded-lg border border-border bg-card p-3" value={data.body_examples.join('\n')} onChange={e => form.setData('body_examples', e.target.value ? e.target.value.split('\n') : [])} /></Field>
            <Field label={t('templates.footer')} error={form.errors.footer}><Input value={data.footer} onChange={e => form.setData('footer', e.target.value)} /></Field>
            <fieldset className="flex flex-col gap-3"><legend className="font-medium">{t('templates.buttons')}</legend>
                {data.buttons.map((button, index) => <div key={index} className="flex flex-col gap-2 rounded-lg border border-border p-3">
                    <Field label={t('templates.button_type')}><select className="h-10 rounded-lg border border-border bg-card px-3" value={button.type} onChange={e => changeButton(index, { type: e.target.value, value: '' })}>{['QUICK_REPLY', 'URL', 'PHONE_NUMBER'].map(value => <option key={value} value={value}>{value}</option>)}</select></Field>
                    <Field label={t('templates.button_text')}><Input value={button.text} onChange={e => changeButton(index, { text: e.target.value })} required /></Field>
                    {button.type !== 'QUICK_REPLY' && <Field label={t(button.type === 'URL' ? 'templates.button_url' : 'templates.button_phone')}><Input value={button.value} onChange={e => changeButton(index, { value: e.target.value })} required /></Field>}
                    <Button type="button" variant="outline" onClick={() => form.setData('buttons', data.buttons.filter((_, i) => i !== index))}>{t('templates.remove')}</Button>
                </div>)}
                <Button type="button" variant="outline" disabled={data.buttons.length >= 10} onClick={() => form.setData('buttons', [...data.buttons, { type: 'QUICK_REPLY', text: '', value: '' }])}>{t('templates.add_button')}</Button>
            </fieldset>
            {Object.entries(form.errors).filter(([key]) => key.startsWith('buttons.') || key.startsWith('body_examples.')).map(([key, value]) => <p role="alert" key={key} className="text-sm text-red-600">{key}: {t(value)}</p>)}
            <div className="flex gap-2"><Button disabled={form.processing}>{t('templates.save_draft')}</Button>{template && <Button type="button" variant="outline" onClick={onCancel}>{t('templates.cancel')}</Button>}</div>
        </div>
        <Preview components={preview} />
    </form>;
}

export default function Templates({ configured, templates }: Props) {
    const t = useTranslation();
    const [editing, setEditing] = useState<Template | null>(null);
    const [busy, setBusy] = useState(false);
    const errors = usePage().props.errors;
    function action(url: string, method: 'post' | 'delete') {
        const options = { preserveScroll: true, onStart: () => setBusy(true), onFinish: () => setBusy(false) };
        if (method === 'post') router.post(url, {}, options); else router.delete(url, options);
    }
    return <AppLayout title={t('templates.title')}>
        <h1 className="text-2xl font-semibold">{t('templates.title')}</h1>
        <p className="text-sm text-muted-foreground">{t('templates.description')}</p>
        {!configured && <p role="alert">{t('templates.configure')}</p>}
        {errors.meta && <p role="alert" className="text-sm text-red-600">{t(errors.meta)}</p>}
        {configured && <>
            <Panel title={t(editing ? 'templates.edit' : 'templates.create')}><TemplateForm key={editing?.id ?? 'new'} template={editing} onCancel={() => setEditing(null)} /></Panel>
            <Panel title={t('templates.list')}>
                <Button type="button" variant="outline" className="self-start" disabled={busy} onClick={() => action('/templates/sync', 'post')}>{t('templates.sync')}</Button>
                <p className="text-sm text-muted-foreground">{t('templates.sync_help')}</p>
                {!templates.length && <p>{t('templates.empty')}</p>}
                {templates.map(template => <article key={template.id} className="flex flex-col gap-3 border-t border-border pt-4">
                    <div><h3 className="font-semibold">{template.name} · {template.language}</h3><p className="text-sm">{template.category} · {template.status}</p>{template.reason && <p className="text-sm">{template.reason}</p>}</div>
                    <Preview components={template.components} />
                    {template.status === 'DRAFT' && <div className="flex flex-wrap gap-2">
                        <Button type="button" variant="outline" disabled={busy} onClick={() => setEditing(template)}>{t('templates.edit')}</Button>
                        <Button type="button" variant="outline" disabled={busy} onClick={() => { if (confirm(t('templates.confirm_submit'))) action(`/templates/${template.id}/submit`, 'post'); }}>{t('templates.submit')}</Button>
                        <Button type="button" variant="outline" disabled={busy} onClick={() => { if (confirm(t('templates.confirm_delete'))) action(`/templates/${template.id}`, 'delete'); }}>{t('templates.remove')}</Button>
                    </div>}
                    {(template.status === 'SUBMISSION_UNKNOWN' || template.status === 'SUBMITTING') && <Button type="button" variant="outline" className="self-start" disabled={busy} onClick={() => { if (confirm(t('templates.confirm_recover'))) action(`/templates/${template.id}/recover`, 'post'); }}>{t('templates.recover')}</Button>}
                </article>)}
            </Panel>
        </>}
    </AppLayout>;
}
