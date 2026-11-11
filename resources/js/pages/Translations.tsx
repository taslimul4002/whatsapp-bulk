import { useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { Field, Input, Panel } from '@/components/form';
import { Button } from '@/components/ui/button';
import { useTranslation } from '@/hooks/use-translation';
import AppLayout from '@/layouts/app-layout';

type TranslationEntry = { key: string; en: string; ar: string };

function TranslationRow({ translation }: { translation: TranslationEntry }) {
    const t = useTranslation();
    const form = useForm({ en: translation.en, ar: translation.ar });
    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.put(`/translations/${encodeURIComponent(translation.key)}`);
    };

    return (
        <form onSubmit={submit} className="grid gap-3 rounded-lg border border-border p-4 md:grid-cols-[minmax(8rem,0.8fr)_minmax(0,1fr)_minmax(0,1fr)_auto] md:items-end">
            <p dir="ltr" className="break-all font-mono text-xs text-muted-foreground">{translation.key}</p>
            <Field label={t('translations.english')} error={form.errors.en}>
                <Input dir="ltr" value={form.data.en} onChange={event => form.setData('en', event.target.value)} required />
            </Field>
            <Field label={t('translations.arabic')} error={form.errors.ar}>
                <Input dir="rtl" value={form.data.ar} onChange={event => form.setData('ar', event.target.value)} required />
            </Field>
            <Button className="cursor-pointer" disabled={form.processing}>{t('translations.save')}</Button>
        </form>
    );
}

export default function Translations({ translationEntries }: { translationEntries: TranslationEntry[] }) {
    const t = useTranslation();
    const form = useForm({ key: '', en: '', ar: '' });
    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.post('/translations', { onSuccess: () => form.reset() });
    };

    return (
        <AppLayout title={t('translations.title')}>
            <div>
                <h1 className="text-2xl font-semibold">{t('translations.title')}</h1>
                <p className="mt-1 text-sm text-muted-foreground">{t('translations.description')}</p>
            </div>
            <Panel title={t('translations.create')}>
                <form onSubmit={submit} className="grid gap-4 md:grid-cols-2">
                    <Field label={t('translations.key')} error={form.errors.key}>
                        <Input dir="ltr" value={form.data.key} onChange={event => form.setData('key', event.target.value)} 
                        required />
                    </Field>
                    <div className="hidden md:block" />
                    <Field label={t('translations.english')} error={form.errors.en}>
                        <Input dir="ltr" value={form.data.en} onChange={event => form.setData('en', event.target.value)} required />
                    </Field>
                    <Field label={t('translations.arabic')} error={form.errors.ar}>
                        <Input dir="rtl" value={form.data.ar} onChange={event => form.setData('ar', event.target.value)} required />
                    </Field>
                    <div className="md:col-span-2">
                        <Button className="cursor-pointer" disabled={form.processing}>{t('translations.create')}</Button>
                    </div>
                </form>
            </Panel>
            <Panel title={t('translations.list')}>
                <div className="flex flex-col gap-3">
                    <p className="text-sm text-muted-foreground">{t('translations.export_help')}</p>
                    <div className="flex flex-wrap gap-2">
                        <Button asChild variant="outline">
                            <a href="/translations/export?locale=en">{t('translations.english')} · {t('common.export_json')}</a>
                        </Button>
                        <Button asChild variant="outline">
                            <a href="/translations/export?locale=ar">{t('translations.arabic')} · {t('common.export_json')}</a>
                        </Button>
                    </div>
                </div>
                {
                    translationEntries.length ? 
                        <div className="mt-5 flex flex-col gap-3">
                            {
                                translationEntries.map(translation => 
                                    <TranslationRow key={translation.key} translation={translation} />
                                )
                            }
                        </div> 
                    : 
                        <p className="mt-5 text-sm text-muted-foreground">
                            {t('translations.empty')}
                        </p>
                }
            </Panel>
        </AppLayout>
    );
}
