import { useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { Field, Input, Panel } from '@/components/form';
import { Button } from '@/components/ui/button';
import { useTranslation } from '@/hooks/use-translation';
import SettingsLayout from '@/layouts/settings-layout';

type Account = { business_account_id: string; phone_number_id: string; api_version: string };

export default function WhatsAppSettings({ account, webhookUrl }: { account: Account | null; webhookUrl: string }) {
    const t = useTranslation();
    const form = useForm({
        business_account_id: account?.business_account_id ?? '',
        phone_number_id: account?.phone_number_id ?? '',
        api_version: account?.api_version ?? '',
        access_token: '',
        app_secret: '',
        verify_token: '',
    });
    const connection = useForm({ connection: '' });

    function submit(event: FormEvent) {
        event.preventDefault();
        form.put('/settings/whatsapp', {
            preserveScroll: true,
            onSuccess: () => form.reset('access_token', 'app_secret', 'verify_token'),
        });
    }

    return (
        <SettingsLayout section="whatsapp">
            <div>
                <h2 className="text-xl font-semibold">{t('whatsapp.title')}</h2>
                <p className="mt-1 text-sm text-muted-foreground">{t('whatsapp.description')}</p>
            </div>
            <Panel title={t('whatsapp.credentials')}>
                <p className="text-sm text-muted-foreground">{t(account ? 'whatsapp.keep_secrets' : 'whatsapp.secret_help')}</p>
                <form onSubmit={submit} className="flex flex-col gap-4">
                    <Field label={t('whatsapp.business_account_id')} error={form.errors.business_account_id}>
                        <Input value={form.data.business_account_id} onChange={event => form.setData('business_account_id', event.target.value)} required inputMode="numeric" />
                    </Field>
                    <Field label={t('whatsapp.phone_number_id')} error={form.errors.phone_number_id}>
                        <Input value={form.data.phone_number_id} onChange={event => form.setData('phone_number_id', event.target.value)} required inputMode="numeric" />
                    </Field>
                    <Field label={t('whatsapp.api_version')} error={form.errors.api_version}>
                        <Input value={form.data.api_version} onChange={event => form.setData('api_version', event.target.value)} placeholder="vXX.X" required />
                    </Field>
                    <Field label={t('whatsapp.access_token')} error={form.errors.access_token}>
                        <Input type="password" autoComplete="new-password" value={form.data.access_token} onChange={event => form.setData('access_token', event.target.value)} required={!account} />
                    </Field>
                    <Field label={t('whatsapp.app_secret')} error={form.errors.app_secret}>
                        <Input type="password" autoComplete="new-password" value={form.data.app_secret} onChange={event => form.setData('app_secret', event.target.value)} required={!account} />
                    </Field>
                    <Field label={t('whatsapp.verify_token')} error={form.errors.verify_token}>
                        <Input type="password" autoComplete="new-password" value={form.data.verify_token} onChange={event => form.setData('verify_token', event.target.value)} required={!account} />
                    </Field>
                    <Button className="self-start cursor-pointer" disabled={form.processing}>{t('common.save')}</Button>
                </form>
            </Panel>
            <Panel title={t('whatsapp.webhook')}>
                <p className="text-sm text-muted-foreground">{t('whatsapp.webhook_help')}</p>
                <p className="break-all text-sm" dir="ltr">{webhookUrl}</p>
            </Panel>
            <Panel title={t('whatsapp.test_connection')}>
                <p className="text-sm text-muted-foreground">{t('whatsapp.test_help')}</p>
                {connection.errors.connection && <p role="alert" className="text-sm text-red-600">{t(connection.errors.connection)}</p>}
                <Button type="button" className="cursor-pointer self-start" variant="outline" disabled={connection.processing}
                    onClick={() => connection.post('/settings/whatsapp/test', { preserveScroll: true })}>
                    {t('whatsapp.test_connection')}
                </Button>
            </Panel>
        </SettingsLayout>
    );
}
