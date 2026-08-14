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

type Mail = {
  mail_id: number | string;
  message_type: string;
  order_id: string | null;
  status: string;
  attempt_count: number;
  last_error: string | null;
  sent_at: string | null;
  updated_at: string;
};

type ReviewCase = {
  review_case_id: string;
  subject_type: string;
  subject_id: string;
  reason: string;
  status: string;
  opened_at: string;
  resolved_at: string | null;
};

const resendableMailStatuses = new Set(['delivery_unknown', 'manual_review', 'failed']);

function formatDate(value: string | null): string {
  if (value === null || value === '') {
    return '–';
  }
  const normalized = value.includes('T') ? value : `${value.replace(' ', 'T')}Z`;
  const date = new Date(normalized);
  return Number.isNaN(date.valueOf())
    ? value
    : new Intl.DateTimeFormat('de-DE', { dateStyle: 'medium', timeStyle: 'short' }).format(date);
}

function mailLabel(type: string): string {
  const labels: Record<string, string> = {
    order_confirmation: 'Bestellbestätigung',
    operator_order_notification: 'Betreiberhinweis',
    shipping_confirmation: 'Versandbestätigung',
    withdrawal_receipt: 'Widerrufsbestätigung',
  };
  return labels[type] ?? type;
}

export function AdminConsole() {
  const [username, setUsername] = useState('');
  const [password, setPassword] = useState('');
  const [csrf, setCsrf] = useState('');
  const [orders, setOrders] = useState<Order[]>([]);
  const [payments, setPayments] = useState<Payment[]>([]);
  const [mails, setMails] = useState<Mail[]>([]);
  const [reviews, setReviews] = useState<ReviewCase[]>([]);
  const [message, setMessage] = useState('');

  async function loadDashboard(): Promise<boolean> {
    const endpoints = ['orders', 'payments', 'mails', 'reviews'] as const;
    const responses = await Promise.all(
      endpoints.map((endpoint) => fetch(`${apiOrigin}/admin/v1/${endpoint}`, { credentials: 'include' })),
    );
    if (responses.some((response) => !response.ok)) {
      return false;
    }
    const [orderData, paymentData, mailData, reviewData] = await Promise.all(
      responses.map((response) => response.json().catch(() => null)),
    );
    setOrders(Array.isArray(orderData?.orders) ? orderData.orders : []);
    setPayments(Array.isArray(paymentData?.payments) ? paymentData.payments : []);
    setMails(Array.isArray(mailData?.mails) ? mailData.mails : []);
    setReviews(Array.isArray(reviewData?.reviews) ? reviewData.reviews : []);
    return true;
  }

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
    const loaded = await loadDashboard();
    setMessage(loaded ? 'Angemeldet.' : 'Angemeldet, aber die Übersicht konnte nicht vollständig geladen werden.');
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
    if (response.ok) {
      await loadDashboard();
    }
    setMessage(response.ok ? 'Versandstatus gespeichert.' : 'Versandstatus konnte nicht gespeichert werden.');
  }

  async function resendMail(mail: Mail): Promise<void> {
    if (!resendableMailStatuses.has(mail.status)) {
      setMessage('Diese Nachricht ist nicht für einen manuellen Neuversand freigegeben.');
      return;
    }
    const confirmed = window.confirm(
      'Der Versandstatus ist nicht eindeutig oder endgültig fehlgeschlagen. Ein Neuversand kann eine doppelte E-Mail erzeugen. Wirklich neu senden?',
    );
    if (!confirmed) {
      return;
    }
    const response = await fetch(`${apiOrigin}/admin/v1/mail/${mail.mail_id}/resend`, {
      method: 'POST',
      credentials: 'include',
      headers: {
        'Content-Type': 'application/json',
        'X-CSRF-Token': csrf,
      },
      body: JSON.stringify({ correlationId: `admin-${crypto.randomUUID()}` }),
    });
    if (response.ok) {
      await loadDashboard();
    }
    setMessage(response.ok ? 'Neuversand wurde vorgemerkt.' : 'Neuversand konnte nicht vorgemerkt werden.');
  }

  const lastSuccessfulMail = mails
    .filter((mail) => mail.status === 'sent' && mail.sent_at !== null)
    .sort((left, right) => (right.sent_at ?? '').localeCompare(left.sent_at ?? ''))[0];

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
      {csrf !== '' && (
        <section aria-label="E-Mail-Verarbeitung">
          <h2>E-Mail-Verarbeitung</h2>
          <p>Letzter erfolgreicher Versand: {formatDate(lastSuccessfulMail?.sent_at ?? null)}</p>
          {mails.length === 0 ? <p>Keine E-Mail-Vorgänge gefunden.</p> : (
            <ul>
              {mails.map((mail) => (
                <li key={mail.mail_id}>
                  <strong>{mailLabel(mail.message_type)}</strong> – Status: {mail.status}
                  {' / Versuche: '}{mail.attempt_count}
                  {mail.order_id !== null ? ` / Bestellung: ${mail.order_id}` : ''}
                  {mail.last_error !== null ? ` / Letzter Fehler: ${mail.last_error}` : ''}
                  {' / Aktualisiert: '}{formatDate(mail.updated_at)}
                  {resendableMailStatuses.has(mail.status) && (
                    <button type="button" onClick={() => void resendMail(mail)}>Neuversand vormerken</button>
                  )}
                </li>
              ))}
            </ul>
          )}
        </section>
      )}
      {csrf !== '' && (
        <section aria-label="Prüffälle">
          <h2>Prüffälle</h2>
          {reviews.length === 0 ? <p>Keine offenen oder bisherigen Prüffälle gefunden.</p> : (
            <ul>
              {reviews.map((review) => (
                <li key={review.review_case_id}>
                  <strong>{review.subject_type}: {review.subject_id}</strong>
                  {' – '}{review.reason} / Status: {review.status}
                  {' / Eröffnet: '}{formatDate(review.opened_at)}
                  {review.resolved_at !== null ? ` / Abgeschlossen: ${formatDate(review.resolved_at)}` : ''}
                </li>
              ))}
            </ul>
          )}
        </section>
      )}
    </main>
  );
}
