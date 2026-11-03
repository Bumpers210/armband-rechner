import {notFound} from 'next/navigation';
import {siteTarget} from '@/config/site-target';
import {ProductManager} from '@/components/product-manager';
import './product-manager.css';

export const metadata = {title: 'Artikelverwaltung · Test', robots: {index: false, follow: false, noimageindex: true}};
export default function ProductManagementPage() {
  if (!siteTarget.isTest) notFound();
  return <div className="v2-page"><ProductManager /></div>;
}
