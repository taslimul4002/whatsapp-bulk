import type { ComponentProps, ReactNode } from 'react';
import { cn } from '@/lib/utils';

export function Field({ label, error, children }: { label: string; error?: string; children: ReactNode }) {
    return <label className="flex flex-col gap-1.5 text-sm font-medium">{label}{children}{error && <span role="alert" className="text-red-600">{error}</span>}</label>;
}

export function Input({ className, ...props }: ComponentProps<'input'>) {
    return <input className={cn('h-10 w-full rounded-lg border border-border bg-card px-3 text-sm', className)} {...props} />;
}

export function Panel({ title, children }: { title: string; children: ReactNode }) {
    return <section className="flex flex-col gap-5 rounded-xl border border-border bg-card p-6"><h2 className="text-lg font-semibold">{title}</h2>{children}</section>;
}
