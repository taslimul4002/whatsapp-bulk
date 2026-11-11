import { useForm, usePage } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { Field, Input, Panel } from '@/components/form';
import { Button } from '@/components/ui/button';
import { useTranslation } from '@/hooks/use-translation';
import AppLayout from '@/layouts/app-layout';
import type { Shared } from '@/types';

export default function Profile() {
    const t = useTranslation();
    const user = usePage<Shared>().props.auth.user!;
    const profile = useForm({ name: user.name, email: user.email });
    const password = useForm({ current_password: '', password: '', password_confirmation: '' });
    const updateProfile = (event: FormEvent) => { event.preventDefault(); profile.patch('/profile'); };
    const updatePassword = (event: FormEvent) => { event.preventDefault(); password.put('/profile/password', { onSuccess: () => password.reset() }); };
    return (
        <AppLayout title={t('profile.title')}>
            <div>
                <h1 className="text-2xl font-semibold">{t('profile.title')}</h1>
                <p className="mt-1 text-sm text-muted-foreground">{t('profile.description')}</p>
            </div>
            <div className="grid gap-6 lg:grid-cols-2">
                <Panel title={t('profile.account_details')}>
                    <form onSubmit={updateProfile} className="flex flex-col gap-4">
                        <Field label={t('profile.name')} error={profile.errors.name}>
                            <Input value={profile.data.name} onChange={event => profile.setData('name', event.target.value)} required />
                        </Field>
                        <Field label={t('profile.email')} error={profile.errors.email}>
                            <Input type="email" value={profile.data.email} onChange={event => profile.setData('email', event.target.value)} required />
                        </Field>
                        <div>
                            <Button className="cursor-pointer" disabled={profile.processing}>{t('profile.save')}</Button>
                        </div>
                    </form>
                </Panel>
                <Panel title={t('profile.change_password')}>
                    <form onSubmit={updatePassword} className="flex flex-col gap-4">
                        <Field label={t('profile.current_password')} error={password.errors.current_password}>
                            <Input type="password" autoComplete="current-password" value={password.data.current_password} onChange={event => password.setData('current_password', event.target.value)} required />
                        </Field>
                        <Field label={t('profile.new_password')} error={password.errors.password}>
                            <Input type="password" autoComplete="new-password" value={password.data.password} onChange={event => password.setData('password', event.target.value)} required />
                        </Field>
                        <Field label={t('profile.confirm_password')} error={password.errors.password_confirmation}>
                            <Input type="password" autoComplete="new-password" value={password.data.password_confirmation} onChange={event => password.setData('password_confirmation', event.target.value)} required />
                        </Field>
                        <div>
                            <Button className="cursor-pointer" disabled={password.processing}>{t('profile.update_password')}</Button>
                        </div>
                    </form>
                </Panel>
            </div>
        </AppLayout>
    );
}
