import { useForm, usePage } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { Field, Input, Panel } from '@/components/form';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import type { Shared, Workspace } from '@/types';

export default function Workspaces({ workspaces }: { workspaces: Workspace[] }) {
    const isAdmin = usePage<Shared>().props.auth.user?.type === 'admin';
    const form = useForm({ name: '' });
    const submit = (event: FormEvent) => { event.preventDefault(); form.post('/workspaces', { onSuccess: () => form.reset() }); };
    return <AppLayout title="Workspaces"><div><h1 className="text-2xl font-semibold">Workspaces</h1><p className="mt-1 text-sm text-muted-foreground">Workspaces available to your account.</p></div>
        {isAdmin && <Panel title="Create workspace"><form onSubmit={submit} className="flex max-w-md flex-col gap-4"><Field label="Workspace name" error={form.errors.name}><Input value={form.data.name} onChange={event => form.setData('name', event.target.value)} required /></Field><div><Button disabled={form.processing}>Create workspace</Button></div></form></Panel>}
        <Panel title="Available workspaces">{workspaces.length ? <ul className="divide-y divide-border">{workspaces.map(workspace => <li className="py-3 text-sm" key={workspace.id}>{workspace.name}</li>)}</ul> : <p className="text-sm text-muted-foreground">No workspaces available.</p>}</Panel>
    </AppLayout>;
}
