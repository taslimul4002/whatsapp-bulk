import { useForm } from '@inertiajs/react';
import { useState, type FormEvent } from 'react';
import { Field, Input, Panel } from '@/components/form';
import { Button } from '@/components/ui/button';
import { useTranslation } from '@/hooks/use-translation';
import AppLayout from '@/layouts/app-layout';
import type { User, Workspace } from '@/types';

type ManagedUser = User & { 
    permissions: { permission: string }[]; 
    workspaces: Workspace[] 
};

type UserForm = { 
    name: string; 
    email: string; 
    password: string; 
    password_confirmation: string; 
    type: 'admin' | 'user'; 
    permissions: string[]; 
    workspaces: number[] 
};

function EditUser({ 
    user, 
    workspaces, 
    availablePermissions, 
    onCreated 
}: { 
    user?: ManagedUser; 
    workspaces: Workspace[]; 
    availablePermissions: string[]; 
    onCreated: () => void 
}) {

    const t = useTranslation();
    const form = useForm<UserForm>({
        name: user?.name ?? '',
        email: user?.email ?? '',
        password: '',
        password_confirmation: '',
        type: user?.type ?? 'user',
        permissions: user?.permissions.map(item => item.permission) ?? [],
        workspaces: user?.workspaces.map(item => item.id) ?? [],
    });
    const submit = (event: FormEvent) => {
        event.preventDefault();
        if (user) {
            form.put(`/users/${user.id}`, { onSuccess: () => form.reset('password', 'password_confirmation') });
        } else {
            form.post('/users', { onSuccess: () => { form.reset(); onCreated(); } });
        }
    };
    const togglePermission = (permission: string) => form.setData('permissions', form.data.permissions.includes(permission) ? form.data.permissions.filter(item => item !== permission) : [...form.data.permissions, permission]);
    const toggleWorkspace = (id: number) => form.setData('workspaces', form.data.workspaces.includes(id) ? form.data.workspaces.filter(item => item !== id) : [...form.data.workspaces, id]);

    return (
    <form onSubmit={submit} className="flex flex-col gap-4">
        <div className="grid gap-4 sm:grid-cols-2">
            <Field label={t('profile.name')} error={form.errors.name}>
                <Input value={form.data.name} onChange={event => form.setData('name', event.target.value)} required />
            </Field>
            <Field label={t('profile.email')} error={form.errors.email}>
                <Input type="email" value={form.data.email} onChange={event => form.setData('email', event.target.value)} required />
            </Field>
        </div>
        <Field label={t('users.account_type')} error={form.errors.type}>
            <select className="h-10 rounded-lg border border-border bg-card px-3" value={form.data.type} 
            onChange={event => form.setData('type', event.target.value === 'admin' ? 'admin' : 'user')}>
                <option value="user">{t('users.user')}</option>
                <option value="admin">{t('users.administrator')}</option>
            </select>
        </Field>
        <div className="grid gap-4 sm:grid-cols-2">
            <Field label={user ? t('users.optional_password') : t('users.password')} error={form.errors.password}>
                <Input type="password" autoComplete="new-password" value={form.data.password} onChange={event => form.setData('password', event.target.value)} required={!user} />
            </Field>
            <Field label={t('users.confirm_password')} error={form.errors.password_confirmation}>
                <Input type="password" autoComplete="new-password" value={form.data.password_confirmation} onChange={event => form.setData('password_confirmation', event.target.value)} required={!user} />
            </Field>
        </div>
        <fieldset className="flex flex-col gap-2">
            <legend className="mb-2 text-sm font-medium">{t('users.permissions')}</legend>
            <p className="text-xs text-muted-foreground">{t('users.permissions_help')}</p>
            {availablePermissions.map(permission => (
                <label key={permission} className="flex items-center gap-2 text-sm">
                    <input type="checkbox" checked={form.data.permissions.includes(permission)} onChange={() => togglePermission(permission)} />
                    {permission === 'dashboard.view' ? t('users.view_dashboard') : permission === 'workspaces.view' ? t('users.view_workspaces') : permission}
                </label>
            ))}
            {form.errors.permissions && <span role="alert" className="text-sm text-red-600">{form.errors.permissions}</span>}
        </fieldset>
        <fieldset className="flex flex-col gap-2">
            <legend className="mb-2 text-sm font-medium">{t('users.membership')}</legend>
            {workspaces.length ? workspaces.map(workspace => (
                <label key={workspace.id} className="flex items-center gap-2 text-sm">
                    <input type="checkbox" checked={form.data.workspaces.includes(workspace.id)} onChange={() => toggleWorkspace(workspace.id)} />
                    {workspace.name}
                </label>
            )) : <p className="text-sm text-muted-foreground">{t('users.create_workspace_first')}</p>}
            {form.errors.workspaces && <span role="alert" className="text-sm text-red-600">{form.errors.workspaces}</span>}
        </fieldset>
        <div>
            <Button disabled={form.processing}>{user ? t('users.save') : t('users.create')}</Button>
        </div>
    </form>
    );
    
}

export default function Users({ 
    users, 
    workspaces, 
    availablePermissions 
}: {
     users: ManagedUser[]; 
     workspaces: Workspace[]; 
     availablePermissions: string[] 
}) {
    const t = useTranslation();
    const [selectedId, setSelectedId] = useState<number | null>(null);
    const selected = users.find(user => user.id === selectedId);
    return (
        <AppLayout title={t('users.title')}>
            <div>
                <h1 className="text-2xl font-semibold">{t('users.title')}</h1>
                <p className="mt-1 text-sm text-muted-foreground">{t('users.description')}</p>
            </div>
            <div className="grid items-start gap-6 lg:grid-cols-[minmax(15rem,1fr)_2fr]">
                <Panel title={t('users.accounts')}>
                    <Button variant="outline" onClick={() => setSelectedId(null)}>{t('users.new')}</Button>
                    <ul className="flex flex-col gap-1">{users.map(user => 
                        <li key={user.id}>
                            <button type="button" onClick={() => setSelectedId(user.id)} aria-pressed={selectedId === user.id} 
                            className="w-full rounded-lg px-3 py-2 text-start text-sm hover:bg-muted aria-pressed:bg-muted">
                                <span className="block font-medium">{user.name}</span>
                                <span className="block truncate text-xs text-muted-foreground">{user.email}</span>
                            </button>
                        </li>)}
                    </ul>
                </Panel>
                <Panel title={selected ? t('users.edit', { name: selected.name }) : t('users.create')}>
                    <EditUser key={selected?.id ?? 'new'} user={selected} workspaces={workspaces} 
                    availablePermissions={availablePermissions} onCreated={() => setSelectedId(null)} />
                </Panel>
            </div>
        </AppLayout>
    );
}
