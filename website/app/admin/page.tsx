import { AdminConsole } from '../../components/admin-console';

export const metadata = {
  title: 'Shop-Verwaltung',
  robots: { index: false, follow: false, noimageindex: true },
};

export default function AdminPage() {
  return <AdminConsole />;
}
