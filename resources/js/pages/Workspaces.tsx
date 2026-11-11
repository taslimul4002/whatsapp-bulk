import { useForm, usePage } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { Field, Input, Panel } from '@/components/form';
import { Button } from '@/components/ui/button';
import { useTranslation } from '@/hooks/use-translation';
import AppLayout from '@/layouts/app-layout';
import type { Shared, Workspace } from '@/types';

export default function Workspaces({ workspaces }: { workspaces: Workspace[] }) {
    const t = useTranslation();
    const isAdmin = usePage<Shared>().props.auth.user?.type === 'admin';
    const form = useForm({ name: '' });
    const submit = (event: FormEvent) => { 
        event.preventDefault(); 
        form.post('/workspaces', { 
            onSuccess: () => form.reset() 
        }); 
    };
    return (
        <AppLayout title={t('workspaces.title')}>
            <div>
                <h1 className="text-2xl font-semibold">{t('workspaces.title')}</h1>
                <p className="mt-1 text-sm text-muted-foreground">{t('workspaces.description')}</p>
            </div>

            {isAdmin && 
                <Panel title={t('workspaces.create')}>
                    <form onSubmit={submit} className="flex max-w-md flex-col gap-4">
                        <Field label={t('workspaces.name')} error={form.errors.name}>
                            <Input value={form.data.name} onChange={event => form.setData('name', event.target.value)} required />
                        </Field>
                        <div>
                            <Button className="cursor-pointer" disabled={form.processing}>{t('common.create')}</Button>
                        </div>
                    </form>
                </Panel>
            }
            <Panel title={t('workspaces.available')}>
                {
                    workspaces.length ? 
                    <ul className="divide-y divide-border">
                        {
                            workspaces.map(
                                workspace =>  <li className="py-3 text-sm" key={workspace.id}>{workspace.name}</li>
                            )
                        }
                    </ul> 
                    : 
                    <p className="text-sm text-muted-foreground">{t('workspaces.none')}</p>
                }
            </Panel>
        </AppLayout>
    );
}
