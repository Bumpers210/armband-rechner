'use client';

import { FormEvent, useState } from 'react';

const apiOrigin =
  process.env.CARMAJA_SHOP_API_ORIGIN ??
  (process.env.CARMAJA_SITE_TARGET === 'test'
    ? 'https://test-api.carmaja-perlen.de'
    : 'https://api.carmaja-perlen.de');

type Order = {
  order_id: string;
  order_number: string;
  status: string;
  payment_status: string;
  payment_method_type: string | null;
  refund_status: string;
  shipment_status: string | null;
  tracking_number: string | null;
};

type Payment = {
  payment_id: string;
  checkout_id: string;
  order_id: string | null;
  payment_method_type: string | null;
  payment_status: string;
  verification_status: string;
  refund_status: string;
  dispute_status: string;
  checkout_state: string;
};

export function AdminConsole() {
  const [username, setUsername] = useState('');
  const [password, setPassword] = useState('');
  const [csrf, setCsrf] = useState('');
  const [orders, setOrders] = useState<Order[]>([]);
  const [payments, setPayments] = useState<Payment[]>([]);
  const [message, setMessage] = useState('');

  async function login(event: FormEvent) {
    event.preventDefault();
    setMessage('Anmeldung wird geprüft …');
    const response = await fetch(`${apiOrigin}/admin/v1/login`, {
      method: 'POST',
      credentials: 'include',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ username, password }),
    });
    const result = await response.json().catch(() => null);
    if (!response.ok || !result?.ok) {
      setMessage('Anmeldung nicht möglich.');
      return;
    }
    setCsrf(result.admin.csrfToken);
    setPassword('');
    const list = await fetch(`${apiOrigin}/admin/v1/orders`, { credentials: 'include' });
    const data = await list.json().catch(() => null);
    setOrders(Array.isArray(data?.orders) ? data.orders : []);
    const paymentList = await fetch(`${apiOrigin}/admin/v1/payments`, { credentials: 'include' });
    const paymentData = await paymentList.json().catch(() => null);
    setPayments(Array.isArray(paymentData?.payments) ? paymentData.payments : []);
    setMessage('Angemeldet.');
  }

  async function markShipped(orderId: string): Promise<void> {
    const trackingNumber = window.prompt('Trackingnummer (optional):', '') ?? '';
    const response = await fetch(`${apiOrigin}/admin/v1/orders/${encodeURIComponent(orderId)}/ship`, {
      method: 'POST',
      credentials: 'include',
      headers: {
        'Content-Type': 'application/json',
        'X-CSRF-Token': csrf,
      },
      body: JSON.stringify({ trackingNumber, correlationId: `admin-${crypto.randomUUID()}` }),
    });
    setMessage(response.ok ? 'Versandstatus gespeichert.' : 'Versandstatus konnte nicht gespeichert werden.');
  }

  return (
    <main className="content admin-console">
      <h1>Shop-Administration</h1>
      <p className="muted">Geschützter Betreiberbereich. Erstattungen werden hier nur angezeigt und synchronisiert.</p>
      <form onSubmit={login} className="admin-login" autoComplete="off">
        <label htmlFor="admin-username">Benutzername</label>
        <input id="admin-username" value={username} onChange={(event) => setUsername(event.target.value)} required />
        <label htmlFor="admin-password">Passwort</label>
        <input id="admin-password" type="password" value={password} onChange={(event) => setPassword(event.target.value)} required />
        <button type="submit">Anmelden</button>
      </form>
      <p role="status">{message}</p>
      {csrf !== '' && (
        <section aria-label="Bestellungen">
          <h2>Bestellungen</h2>
          {orders.length === 0 ? <p>Keine Bestellungen gefunden.</p> : (
            <ul>
              {orders.map((order) => (
                <li key={order.order_id}>
                  <strong>{order.order_number}</strong> – {order.payment_method_type ?? 'unbekannt'} / {order.payment_status} / {order.shipment_status ?? 'not_ready'}
                  {order.refund_status !== 'none' ? ` – Erstattung: ${order.refund_status}` : ''}
                  {order.shipment_status === 'ready' && (
                    <button type="button" onClick={() => void markShipped(order.order_id)}>Als versandt markieren</button>
                  )}
                </li>
              ))}
            </ul>
          )}
        </section>
      )}
      {csrf !== '' && (
        <section aria-label="Zahlungen">
          <h2>Zahlungen</h2>
          {payments.length === 0 ? <p>Keine Zahlungen gefunden.</p> : (
            <ul>
              {payments.map((payment) => (
                <li key={payment.payment_id}>
                  <strong>{payment.payment_method_type ?? 'noch nicht gewählt'}</strong>
                  {' – '}{payment.payment_status} / Prüfung: {payment.verification_status}
                  {' / Checkout: '}{payment.checkout_state}
                  {payment.order_id === null ? ' – noch keine Bestellung' : ''}
                </li>
              ))}
            </ul>
          )}
        </section>
      )}
    </main>
  );
}
