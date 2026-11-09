import { Panel } from '@/components/form';
import AppLayout from '@/layouts/app-layout';

export default function Dashboard() {
    return <AppLayout title="Dashboard"><div><h1 className="text-2xl font-semibold">Dashboard</h1><p className="mt-1 text-sm text-muted-foreground">Your workspace overview.</p></div><Panel title="Getting started"><p className="text-sm text-muted-foreground">Your application foundation is ready. More features will appear here as they are added.</p></Panel></AppLayout>;
}
