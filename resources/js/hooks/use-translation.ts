import { useEffect } from 'react';
import { usePage } from '@inertiajs/react';
import type { Shared } from '@/types';

type Replacements = Record<string, string | number>;

export function useTranslation() {
    const { locale, translations } = usePage<Shared>().props;

    useEffect(() => {
        document.documentElement.lang = locale;
        document.documentElement.dir = locale === 'ar' ? 'rtl' : 'ltr';
    }, [locale]);

    return (key: string, replacements: Replacements = {}): string => {
        return Object.entries(replacements).reduce(
            (value, [name, replacement]) => value.replaceAll(`:${name}`, String(replacement)),
            translations[key] ?? key,
        );
    };
}
