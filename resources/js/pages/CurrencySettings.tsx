import { Link, useForm, usePage } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { Field, Input, Panel } from '@/components/form';
import { Button } from '@/components/ui/button';
import { useTranslation } from '@/hooks/use-translation';
import SettingsLayout from '@/layouts/settings-layout';
import { formatMoney } from '@/lib/money';
import type { Shared } from '@/types';

export default function CurrencySettings() {
    const { auth, currency, locale } = usePage<Shared>().props;
    const t = useTranslation();
    const form = useForm({ code: '' });
    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.post('/settings/currency', { preserveScroll: true, onSuccess: () => form.reset() });
    };

    return (
        <SettingsLayout section="currency">
            <div>
                <h2 className="text-xl font-semibold">{t('currency.title')}</h2>
                <p className="mt-1 text-sm text-muted-foreground">{t('currency.description')}</p>
            </div>
            <Panel title={t('currency.preference')}>
                <p className="text-sm text-muted-foreground">{t('currency.header_help')}</p>
                <p className="text-sm">{t('currency.example')}: <span dir="auto">{formatMoney(1234.56, currency.selected, locale)}</span></p>
                <p className="text-sm text-muted-foreground">{t('currency.no_conversion')}</p>
            </Panel>
            {auth.user?.type === 'admin' && (
                <Panel title={t('currency.manage')}>
                    <p className="text-sm text-muted-foreground">{t('currency.manage_help')}</p>
                    <form onSubmit={submit} className="flex flex-col items-start gap-4 sm:flex-row sm:items-end">
                        <Field label={t('currency.code')} error={form.errors.code}>
                            <Input value={form.data.code} onChange={event => form.setData('code', event.target.value.toUpperCase())} maxLength={3} pattern="[A-Z]{3}" required />
                        </Field>
                        <Button className="cursor-pointer" disabled={form.processing}>{t('currency.add')}</Button>
                    </form>
                    <ul className="flex flex-col gap-2">
                        {currency.available.map(code => (
                            <li key={code} className="flex items-center justify-between gap-3 rounded-lg border border-border p-3 text-sm">
                                <span>{code}{code === 'SAR' && ` (${t('currency.default')})`}</span>
                                {code !== 'SAR' && (
                                    <Button className="cursor-pointer" asChild variant="outline">
                                        <Link href={`/settings/currency/${code}`} method="delete" as="button"
                                            aria-label={t('currency.remove_named', { code })}
                                            onClick={event => { if (!window.confirm(t('currency.confirm_remove', { code }))) event.preventDefault(); }}>
                                            {t('currency.remove')}
                                        </Link>
                                    </Button>
                                )}
                            </li>
                        ))}
                    </ul>
                </Panel>
            )}
        </SettingsLayout>
    );
}
