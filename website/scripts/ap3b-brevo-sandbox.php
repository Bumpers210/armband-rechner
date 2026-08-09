<?php

declare(strict_types=1);

define('CARMAJA_BOOTSTRAP_NO_RUN', true);
define('CARMAJA_AP5_WORKER_NO_RUN', true);

require_once dirname(__DIR__) . '/test-api-private/program/ap5-worker.php';

const AP3B_BREVO_CONFIG = '/home/www/carmaja-private-test/ap3b-brevo-credentials.json';

function ap3b_brevo_fail(string $message): never
{
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

if (PHP_SAPI !== 'cli') {
    ap3b_brevo_fail('cli_required');
}
if (($argv[1] ?? '') !== 'send-live') {
    ap3b_brevo_fail('usage: send-live');
}
if (!is_file(AP3B_BREVO_CONFIG) || is_link(AP3B_BREVO_CONFIG)) {
    ap3b_brevo_fail('private_config_invalid');
}
$mode = substr(sprintf('%o', fileperms(AP3B_BREVO_CONFIG)), -4);
if ($mode !== '0600') {
    ap3b_brevo_fail('private_config_mode_invalid');
}

$private = json_decode((string) file_get_contents(AP3B_BREVO_CONFIG), true, 32, JSON_THROW_ON_ERROR);
foreach (['apiKey', 'senderEmail', 'senderName', 'recipientEmail'] as $field) {
    if (!is_string($private[$field] ?? null) || $private[$field] === '') {
        ap3b_brevo_fail('private_config_shape_invalid');
    }
}

$config = [
    'brevoApiKey' => $private['apiKey'],
    'brevoSenderEmail' => $private['senderEmail'],
    'brevoSenderName' => $private['senderName'],
];
$dedupeKey = 'ap6-ap3b-' . gmdate('Ymd-His') . '-' . bin2hex(random_bytes(8));
$row = [
    'dedupe_key' => $dedupeKey,
    'recipient' => $private['recipientEmail'],
    'payload' => json_encode([
        'subject' => 'Carmaja-Perlen AP6/AP3b Sandboxnachweis',
        'htmlContent' => '<p>Kuenstliche AP6/AP3b-Testnachricht. Keine Bestellung und keine Produktionsdaten.</p>',
    ], JSON_THROW_ON_ERROR),
];

$client = new CarmajaBrevoClient($config);
$first = $client->send($row);
$second = $client->send($row);

$firstMessageId = is_string($first['brevoMessageId'] ?? null) ? $first['brevoMessageId'] : null;
$secondMessageId = is_string($second['brevoMessageId'] ?? null) ? $second['brevoMessageId'] : null;
$result = [
    'firstOutcome' => $first['outcome'] ?? null,
    'firstHasMessageId' => $firstMessageId !== null,
    'secondOutcome' => $second['outcome'] ?? null,
    'secondHasMessageId' => $secondMessageId !== null,
    'sameMessageId' => $firstMessageId !== null && $secondMessageId !== null
        ? hash_equals($firstMessageId, $secondMessageId)
        : null,
    'secondError' => $second['error'] ?? null,
    'dedupeKeyHash' => hash('sha256', $dedupeKey),
    'testOnly' => true,
];

$private['apiKey'] = str_repeat("\0", strlen($private['apiKey']));
unset($private, $config, $row, $client, $firstMessageId, $secondMessageId);

fwrite(STDOUT, json_encode($result, JSON_THROW_ON_ERROR) . PHP_EOL);
exit(($result['firstOutcome'] ?? null) === 'sent'
    && $result['firstHasMessageId']
    && ($result['secondOutcome'] ?? null) === 'delivery_unknown'
    && ($result['secondError'] ?? null) === 'brevo_idempotency_duplicate'
    ? 0 : 1);
