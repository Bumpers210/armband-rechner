import { AdminConsole } from '../../components/admin-console';
import Link from 'next/link';

export default function AdminPage() {
  return <div className="v2-page v2-admin-page">
    <p><Link href="/artikelverwaltung/">Artikel am PC verwalten →</Link></p>
    <AdminConsole /></div>;
}
