import { useForm, usePage } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { Field, Input, Panel } from '@/components/form';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import type { Shared } from '@/types';

export default function Profile() {
    const user = usePage<Shared>().props.auth.user!;
    const profile = useForm({ name: user.name, email: user.email });
    const password = useForm({ current_password: '', password: '', password_confirmation: '' });
    const updateProfile = (event: FormEvent) => { event.preventDefault(); profile.patch('/profile'); };
    const updatePassword = (event: FormEvent) => { event.preventDefault(); password.put('/profile/password', { onSuccess: () => password.reset() }); };
    return <AppLayout title="Profile"><div><h1 className="text-2xl font-semibold">Profile</h1><p className="mt-1 text-sm text-muted-foreground">Manage your account details and password.</p></div>
        <div className="grid gap-6 lg:grid-cols-2"><Panel title="Account details"><form onSubmit={updateProfile} className="flex flex-col gap-4"><Field label="Name" error={profile.errors.name}><Input value={profile.data.name} onChange={event => profile.setData('name', event.target.value)} required /></Field><Field label="Email" error={profile.errors.email}><Input type="email" value={profile.data.email} onChange={event => profile.setData('email', event.target.value)} required /></Field><div><Button disabled={profile.processing}>Save profile</Button></div></form></Panel>
        <Panel title="Change password"><form onSubmit={updatePassword} className="flex flex-col gap-4"><Field label="Current password" error={password.errors.current_password}><Input type="password" autoComplete="current-password" value={password.data.current_password} onChange={event => password.setData('current_password', event.target.value)} required /></Field><Field label="New password" error={password.errors.password}><Input type="password" autoComplete="new-password" value={password.data.password} onChange={event => password.setData('password', event.target.value)} required /></Field><Field label="Confirm new password" error={password.errors.password_confirmation}><Input type="password" autoComplete="new-password" value={password.data.password_confirmation} onChange={event => password.setData('password_confirmation', event.target.value)} required /></Field><div><Button disabled={password.processing}>Update password</Button></div></form></Panel></div>
    </AppLayout>;
}
