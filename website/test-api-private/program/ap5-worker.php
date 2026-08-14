<?php

declare(strict_types=1);

require_once __DIR__ . '/commerce-worker.php';

final class CarmajaBrevoClient
{
    /** @var null|callable(array,string):array{status:int,body:string} */
    private $transport;

    public function __construct(
        private readonly array $config,
        ?callable $transport = null
    ) {
        $this->transport = $transport;
    }

    public function send(array $row): array
    {
        $apiKey = $this->config['brevoApiKey'] ?? null;
        $senderEmail = $this->config['brevoSenderEmail'] ?? null;
        if (!is_string($apiKey) || $apiKey === '' || !is_string($senderEmail) || $senderEmail === '') {
            return ['outcome' => 'failed', 'error' => 'brevo_configuration_invalid'];
        }
        $payload = json_decode((string) $row['payload'], true, 32);
        if (!is_array($payload)) {
            return ['outcome' => 'failed', 'error' => 'mail_payload_invalid'];
        }
        $content = $this->renderContent($row, $payload);
        if ($content === null) {
            return ['outcome' => 'failed', 'error' => 'mail_content_missing'];
        }
        $request = [
            'sender' => [
                'email' => $senderEmail,
                'name' => (string) ($this->config['brevoSenderName'] ?? 'Carmaja-Perlen Shop'),
            ],
            'to' => [['email' => (string) $row['recipient']]],
            'subject' => $content['subject'],
            'htmlContent' => $content['htmlContent'],
        ];
        $request['headers'] = [
            'idempotencyKey' => $this->providerIdempotencyKey((string) $row['dedupe_key']),
        ];
        $headers = [
            'Accept: application/json',
            'Content-Type: application/json',
            'api-key: ' . $apiKey,
        ];
        try {
            $response = is_callable($this->transport)
                ? ($this->transport)($request, (string) $row['dedupe_key'])
                : $this->request($request, $headers);
        } catch (Throwable $error) {
            return ['outcome' => 'delivery_unknown', 'error' => 'transport_unknown'];
        }
        $status = (int) ($response['status'] ?? 0);
        $body = (string) ($response['body'] ?? '');
        $decoded = json_decode($body, true, 16);
        if ($status >= 200 && $status < 300) {
            $messageId = is_array($decoded) && is_string($decoded['messageId'] ?? null)
                ? $decoded['messageId'] : null;
            return $messageId !== null
                ? ['outcome' => 'sent', 'brevoMessageId' => $messageId]
                : ['outcome' => 'delivery_unknown', 'error' => 'brevo_message_id_missing'];
        }
        if (is_array($decoded) && ($decoded['code'] ?? null) === 'duplicate_parameter') {
            return ['outcome' => 'delivery_unknown', 'error' => 'brevo_idempotency_duplicate'];
        }
        if ($status === 0 || $status === 429 || $status >= 500) {
            return ['outcome' => 'retry', 'error' => 'brevo_temporary_failure'];
        }
        return ['outcome' => 'retry', 'error' => 'brevo_http_' . $status];
    }

    private function providerIdempotencyKey(string $dedupeKey): string
    {
        $bytes = substr(hash('sha256', $dedupeKey, true), 0, 16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        $hex = bin2hex($bytes);
        return substr($hex, 0, 8) . '-'
            . substr($hex, 8, 4) . '-'
            . substr($hex, 12, 4) . '-'
            . substr($hex, 16, 4) . '-'
            . substr($hex, 20, 12);
    }

    private function request(array $payload, array $headers): array
    {
        if (!function_exists('curl_init')) {
            throw new RuntimeException('curl_unavailable');
        }
        $handle = curl_init('https://api.brevo.com/v3/smtp/email');
        if ($handle === false) {
            throw new RuntimeException('curl_init_failed');
        }
        curl_setopt_array($handle, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_THROW_ON_ERROR),
        ]);
        $body = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_HTTP_CODE);
        $error = curl_error($handle);
        curl_close($handle);
        if ($body === false || $error !== '') {
            throw new RuntimeException('brevo_transport_failed');
        }
        return ['status' => $status, 'body' => (string) $body];
    }

    /** @return null|array{subject:string,htmlContent:string} */
    private function renderContent(array $row, array $payload): ?array
    {
        $subject = trim((string) ($payload['subject'] ?? ''));
        $htmlContent = trim((string) ($payload['htmlContent'] ?? $payload['textContent'] ?? ''));
        if ($subject !== '' && $htmlContent !== '') {
            return ['subject' => $subject, 'htmlContent' => $htmlContent];
        }

        return match ((string) ($row['message_type'] ?? '')) {
            'order_confirmation' => $this->renderOrderConfirmation($payload),
            'operator_order_notification' => $this->renderOperatorOrderNotification($payload),
            'shipping_confirmation' => $this->renderShippingConfirmation($payload),
            'withdrawal_receipt' => $this->renderWithdrawalReceipt($payload),
            default => null,
        };
    }

    /** @return null|array{subject:string,htmlContent:string} */
    private function renderOrderConfirmation(array $payload): ?array
    {
        $orderNumber = $this->escape($payload['orderNumber'] ?? null);
        $customerName = $this->escape($payload['customerName'] ?? null);
        $product = is_array($payload['product'] ?? null) ? $payload['product'] : [];
        $shipping = is_array($payload['shipping'] ?? null) ? $payload['shipping'] : [];
        $legal = is_array($payload['legal'] ?? null) ? $payload['legal'] : [];
        $productName = $this->escape($product['name'] ?? null);
        $productId = $this->escape($product['productId'] ?? null);
        $shippingName = $this->escape($shipping['publicName'] ?? null);
        $price = $this->money($product['priceMinor'] ?? null, $product['currency'] ?? null);
        $shippingPrice = $this->money($shipping['amountMinor'] ?? null, $shipping['currency'] ?? null);
        $total = $this->money($payload['totalMinor'] ?? null, $payload['currency'] ?? null);
        $legalUrl = $this->legalArchiveUrl($legal);
        if ($orderNumber === '' || $productName === '' || $productId === ''
            || $shippingName === '' || $price === null || $shippingPrice === null
            || $total === null || $legalUrl === null) {
            return null;
        }

        $greeting = $customerName !== '' ? '<p>Hallo ' . $customerName . ',</p>' : '';
        return [
            'subject' => 'Bestellbestätigung ' . $orderNumber,
            'htmlContent' => $greeting
                . '<p>vielen Dank für Ihre Bestellung. Ihre Zahlung wurde bestätigt und wir haben Ihre Bestellung angenommen.</p>'
                . '<h2>Bestellübersicht</h2>'
                . '<p>Bestellnummer: <strong>' . $orderNumber . '</strong></p>'
                . '<ul><li>Produkt: <strong>' . $productName . '</strong> (' . $productId . ')</li>'
                . '<li>Menge: 1</li><li>Produktpreis: ' . $price . '</li>'
                . '<li>Versand: ' . $shippingName . ' – ' . $shippingPrice . '</li>'
                . '<li>Gesamtbetrag: <strong>' . $total . '</strong></li></ul>'
                . '<h2>Vertragsunterlagen</h2>'
                . '<p>Die für diese Bestellung geltenden Shopbedingungen, Datenschutz-, Widerrufs- sowie Versand- und Zahlungsinformationen finden Sie in der dauerhaft zugeordneten, unveränderlichen Fassung:</p>'
                . '<p><a href="' . $this->escape($legalUrl) . '">Vertragsunterlagen dieser Bestellung öffnen</a></p>'
                . '<p>Bitte bewahren Sie diese E-Mail für Ihre Unterlagen auf.</p>',
        ];
    }

    /** @return null|array{subject:string,htmlContent:string} */
    private function renderOperatorOrderNotification(array $payload): ?array
    {
        $orderNumber = $this->escape($payload['orderNumber'] ?? null);
        $product = is_array($payload['product'] ?? null) ? $payload['product'] : [];
        $productName = $this->escape($product['name'] ?? null);
        $productId = $this->escape($product['productId'] ?? null);
        $total = $this->money($payload['totalMinor'] ?? null, $payload['currency'] ?? null);
        if ($orderNumber === '' || $productName === '' || $productId === '' || $total === null) {
            return null;
        }

        return [
            'subject' => 'Neue Bestellung ' . $orderNumber,
            'htmlContent' => '<p>Eine neue Bestellung wurde bestätigt.</p>'
                . '<ul><li>Bestellnummer: <strong>' . $orderNumber . '</strong></li>'
                . '<li>Produkt: ' . $productName . ' (' . $productId . ')</li>'
                . '<li>Gesamtbetrag: <strong>' . $total . '</strong></li></ul>'
                . '<p>Kundendaten und Zahlungsdetails sind aus Datenschutzgründen nicht Bestandteil dieser Nachricht. Bitte verwenden Sie für die Bearbeitung die geschützte Shop-Administration.</p>',
        ];
    }

    /** @return null|array{subject:string,htmlContent:string} */
    private function renderShippingConfirmation(array $payload): ?array
    {
        $orderNumber = $this->escape($payload['orderNumber'] ?? null);
        $trackingNumber = $this->escape($payload['trackingNumber'] ?? null);
        $productName = $this->escape($payload['productName'] ?? null);
        $shippingName = $this->escape($payload['shippingName'] ?? null);
        if ($orderNumber === '') {
            return null;
        }
        $product = $productName !== '' ? '<p>Produkt: <strong>' . $productName . '</strong></p>' : '';
        $shipping = $shippingName !== '' ? '<p>Versandart: ' . $shippingName . '</p>' : '';
        $tracking = $trackingNumber !== ''
            ? '<p>Sendungsreferenz: <strong>' . $trackingNumber . '</strong></p>'
            : '<p>Für diese Sendung wurde keine Sendungsreferenz hinterlegt.</p>';

        return [
            'subject' => 'Versandbestätigung ' . $orderNumber,
            'htmlContent' => '<p>Ihre Bestellung <strong>' . $orderNumber . '</strong> wurde versendet.</p>'
                . $product . $shipping . $tracking
                . '<p>Die voraussichtliche Lieferzeit richtet sich nach der in Ihrer Bestellbestätigung zugeordneten Versandinformation.</p>',
        ];
    }

    /** @return null|array{subject:string,htmlContent:string} */
    private function renderWithdrawalReceipt(array $payload): ?array
    {
        $withdrawalId = $this->escape($payload['withdrawalId'] ?? null);
        $receivedAt = $this->escape($payload['receivedAt'] ?? null);
        $content = is_array($payload['content'] ?? null) ? $payload['content'] : [];
        $orderNumber = $this->escape($content['orderNumber'] ?? null);
        $name = $this->escape($content['name'] ?? null);
        $email = $this->escape($content['email'] ?? null);
        if ($withdrawalId === '' || $receivedAt === '' || $orderNumber === ''
            || $name === '' || $email === '') {
            return null;
        }

        return [
            'subject' => 'Eingangsbestätigung Ihres Widerrufs',
            'htmlContent' => '<p>Ihr Widerruf ist bei uns eingegangen.</p>'
                . '<h2>Inhalt Ihrer Erklärung</h2>'
                . '<p>Sie haben den Vertrag zur Bestellung <strong>' . $orderNumber . '</strong> widerrufen.</p>'
                . '<ul><li>Name: ' . $name . '</li><li>E-Mail: ' . $email . '</li></ul>'
                . '<p>Vorgang: <strong>' . $withdrawalId . '</strong><br>Datum und Uhrzeit des Eingangs: ' . $receivedAt . '</p>'
                . '<p>Diese Bestätigung löst nicht automatisch eine Erstattung, Wiedereinlagerung oder Versandänderung aus.</p>',
        ];
    }

    private function legalArchiveUrl(array $legal): ?string
    {
        $environment = $this->config['environment'] ?? null;
        $origin = $this->config['shopWebsiteOrigin'] ?? null;
        $bundleId = $legal['legalBundleId'] ?? null;
        $expectedOrigin = $environment === 'test'
            ? 'https://test.carmaja-perlen.de'
            : ($environment === 'production' ? 'https://www.carmaja-perlen.de' : null);
        if (!is_string($bundleId) || !is_string($origin) || $origin !== $expectedOrigin
            || preg_match('/^cmj-' . preg_quote((string) $environment, '/')
                . '-legal-[0-9]{4}-[0-9]{2}-[0-9]{2}-v[0-9]+$/', $bundleId) !== 1) {
            return null;
        }

        return $origin . '/legal-archive/' . $environment . '/' . rawurlencode($bundleId) . '/';
    }

    private function money(mixed $minor, mixed $currency): ?string
    {
        if (!is_int($minor) || $minor < 0 || $currency !== 'eur') {
            return null;
        }

        return number_format($minor / 100, 2, ',', '.') . ' €';
    }

    private function escape(mixed $value): string
    {
        return htmlspecialchars(
            trim(is_string($value) ? $value : ''),
            ENT_QUOTES | ENT_SUBSTITUTE,
            'UTF-8'
        );
    }
}

final class CarmajaAp5Worker
{
    private const BATCH_SIZE = 10;
    private const LEASE_SECONDS = 600;

    public function __construct(
        private readonly CarmajaCommercePdo $commerce,
        private readonly array $config,
        ?callable $transport = null
    ) {
        $this->brevo = new CarmajaBrevoClient($config, $transport);
    }

    private CarmajaBrevoClient $brevo;

    public function run(): array
    {
        $worker = new CarmajaCommerceWorker($this->commerce, 'commerce-v1-brevo');
        return $worker->run(function (): int {
            $count = 0;
            foreach ($this->commerce->claimMailOutbox(self::BATCH_SIZE, self::LEASE_SECONDS) as $row) {
                $result = $this->brevo->send($row);
                $this->commerce->completeMailOutbox(
                    (int) $row['mail_id'],
                    (string) ($result['outcome'] ?? 'retry'),
                    $result['brevoMessageId'] ?? null,
                    $result['error'] ?? null
                );
                if (($result['outcome'] ?? '') === 'sent') {
                    $count++;
                }
            }
            return $count;
        }, self::BATCH_SIZE, self::LEASE_SECONDS);
    }
}

if (PHP_SAPI === 'cli'
    && isset($_SERVER['SCRIPT_FILENAME'])
    && realpath(__FILE__) === realpath((string) $_SERVER['SCRIPT_FILENAME'])) {
    fwrite(STDERR, "AP5 worker requires application wiring; no external action was performed.\n");
    exit(0);
}
