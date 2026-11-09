import { Head, useForm } from '@inertiajs/react';
import { MessageCircle } from 'lucide-react';
import type { FormEvent } from 'react';
import { Field, Input } from '@/components/form';
import { Button } from '@/components/ui/button';

export default function Login() {
    const form = useForm({ email: '', password: '', remember: false });
    const submit = (event: FormEvent) => { event.preventDefault(); form.post('/login', { onFinish: () => form.reset('password') }); };
    return <main className="flex min-h-screen items-center justify-center p-4"><Head title="Sign in" /><div className="w-full max-w-md rounded-xl border border-border bg-card p-8 shadow-sm">
        <div className="flex items-center gap-2 text-xl font-bold"><span className="rounded-lg bg-primary p-2 text-primary-foreground"><MessageCircle size={20} /></span>Namaa</div>
        <h1 className="mt-8 text-2xl font-semibold">Welcome back</h1><p className="mt-2 text-sm text-muted-foreground">Sign in to your account to continue.</p>
        <form onSubmit={submit} className="mt-6 flex flex-col gap-4">
            <Field label="Email" error={form.errors.email}><Input type="email" autoComplete="email" value={form.data.email} onChange={event => form.setData('email', event.target.value)} required /></Field>
            <Field label="Password" error={form.errors.password}><Input type="password" autoComplete="current-password" value={form.data.password} onChange={event => form.setData('password', event.target.value)} required /></Field>
            <label className="flex items-center gap-2 text-sm"><input type="checkbox" checked={form.data.remember} onChange={event => form.setData('remember', event.target.checked)} />Remember me</label>
            <Button type="submit" disabled={form.processing}>Sign in</Button>
        </form>
    </div></main>;
}
