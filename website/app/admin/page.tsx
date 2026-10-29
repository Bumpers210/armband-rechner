import { AdminConsole } from '../../components/admin-console';
import Link from 'next/link';
import {siteTarget} from '@/config/site-target';

export default function AdminPage() {
  return <div className="v2-page v2-admin-page">
    {siteTarget.isTest ? <p><Link href="/artikelverwaltung/">Artikel am PC verwalten →</Link></p> : null}
    <AdminConsole /></div>;
}
