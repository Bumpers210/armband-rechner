import {ProductManager} from '@/components/product-manager';
import './product-manager.css';

export const metadata = {title: 'Artikelverwaltung', robots: {index: false, follow: false, noimageindex: true}};
export default function ProductManagementPage() {
  return <div className="v2-page"><ProductManager /></div>;
}
