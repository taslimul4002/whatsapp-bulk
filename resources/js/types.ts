export type User = { id: number; name: string; email: string; type: 'admin' | 'user' };
export type Workspace = { id: number; name: string };
export type Shared = {
    auth: { user: User | null; permissions: string[] };
    flash: { success: string | null };
    [key: string]: unknown;
};
