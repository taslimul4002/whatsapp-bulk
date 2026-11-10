export type User = { id: number; name: string; email: string; type: 'admin' | 'user' };
export type Workspace = { id: number; name: string };
export type Shared = {
    auth: { user: User | null; permissions: string[] };
    flash: { success: string | null };
    locale: 'en' | 'ar';
    translations: Record<string, string>;
    [key: string]: unknown;
};
