<?php

declare(strict_types=1);

const CARMAJA_API_STATUSES = ['draft', 'ready', 'published', 'sold', 'disabled'];
const CARMAJA_DRAFT_PATTERN = '/^[0-9a-fA-F-]{36}$|^[0-9A-HJKMNP-TV-Z]{26}$/';
const CARMAJA_OPERATION_PATTERN = '/^[A-Za-z0-9._:-]{8,100}$/';
const CARMAJA_SLUG_PATTERN = '/^[a-z0-9]+(?:-[a-z0-9]+)*$/';
const CARMAJA_MAX_IMAGES = 5;
const CARMAJA_MAX_IMAGE_BYTES = 1048576;
const CARMAJA_BACKUP_KEEP = 30;

class CarmajaApiException extends RuntimeException
{
    public function __construct(
        public readonly int $statusCode,
        string $message,
        public readonly array $details = []
    ) {
        parent::__construct($message);
    }
}

function carmaja_api_now(): string
{
    return gmdate('c');
}

function carmaja_api_private_dir(): string
{
    $configuredPath = getenv('CARMAJA_PRIVATE_DIR');

    if (!is_string($configuredPath) || trim($configuredPath) === '') {
        throw new CarmajaApiException(
            503,
            'CARMAJA_PRIVATE_DIR ist nicht konfiguriert.'
        );
    }

    $path = rtrim(trim($configuredPath), DIRECTORY_SEPARATOR);

    if (!is_dir($path)
        && !mkdir($path, 0750, true)
        && !is_dir($path)) {
        throw new CarmajaApiException(503, 'Privater Datenpfad ist nicht schreibbar.');
    }

    $privateRealPath = realpath($path);
    $documentRoot = $_SERVER['DOCUMENT_ROOT'] ?? '';
    $webrootRealPath = is_string($documentRoot) && $documentRoot !== ''
        ? realpath($documentRoot)
        : false;

    if ($privateRealPath === false) {
        throw new CarmajaApiException(503, 'Privater Datenpfad ist nicht erreichbar.');
    }

    if (is_string($webrootRealPath)
        && str_starts_with($privateRealPath, rtrim($webrootRealPath, DIRECTORY_SEPARATOR))) {
        throw new CarmajaApiException(
            503,
            'Privater Datenpfad darf nicht im oeffentlichen Webroot liegen.'
        );
    }

    return $privateRealPath;
}

function carmaja_api_path(string $relativePath): string
{
    $normalized = str_replace('\\', '/', $relativePath);

    if ($normalized === ''
        || str_contains($normalized, '..')
        || str_starts_with($normalized, '/')) {
        throw new CarmajaApiException(500, 'Ungueltiger interner Pfad.');
    }

    return carmaja_api_private_dir() . DIRECTORY_SEPARATOR
        . str_replace('/', DIRECTORY_SEPARATOR, $normalized);
}

function carmaja_api_ensure_directory(string $path): void
{
    if (!is_dir($path)
        && !mkdir($path, 0750, true)
        && !is_dir($path)) {
        throw new CarmajaApiException(500, 'Verzeichnis konnte nicht erstellt werden.');
    }
}

function carmaja_api_read_json(string $path, array $fallback = []): array
{
    if (!is_file($path)) {
        return $fallback;
    }

    $handle = fopen($path, 'r');

    if ($handle === false) {
        throw new CarmajaApiException(500, 'Datei ist nicht lesbar.');
    }

    try {
        if (!flock($handle, LOCK_SH)) {
            throw new CarmajaApiException(500, 'Datei konnte nicht gesperrt werden.');
        }

        $contents = stream_get_contents($handle);
        flock($handle, LOCK_UN);
    } finally {
        fclose($handle);
    }

    if (!is_string($contents) || trim($contents) === '') {
        return $fallback;
    }

    $decoded = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);

    if (!is_array($decoded)) {
        throw new CarmajaApiException(500, 'JSON-Datei enthaelt kein Objekt.');
    }

    return $decoded;
}

function carmaja_api_write_json_atomic(string $path, array $data): void
{
    carmaja_api_ensure_directory(dirname($path));

    $encoded = json_encode(
        $data,
        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
    ) . PHP_EOL;
    $temporaryPath = $path . '.tmp.' . bin2hex(random_bytes(6));

    if (file_put_contents($temporaryPath, $encoded, LOCK_EX) === false) {
        throw new CarmajaApiException(500, 'Datei konnte nicht geschrieben werden.');
    }

    chmod($temporaryPath, 0640);

    if (!rename($temporaryPath, $path)) {
        @unlink($temporaryPath);
        throw new CarmajaApiException(500, 'Datei konnte nicht atomar uebernommen werden.');
    }
}

function carmaja_api_lock_path(string $name): string
{
    return carmaja_api_path('locks/' . preg_replace('/[^A-Za-z0-9._-]/', '_', $name) . '.lock');
}

function carmaja_api_with_lock(string $name, callable $callback): mixed
{
    $path = carmaja_api_lock_path($name);
    carmaja_api_ensure_directory(dirname($path));
    $handle = fopen($path, 'c');

    if ($handle === false) {
        throw new CarmajaApiException(500, 'Lock konnte nicht geoeffnet werden.');
    }

    try {
        if (!flock($handle, LOCK_EX)) {
            throw new CarmajaApiException(500, 'Lock konnte nicht gesetzt werden.');
        }

        return $callback();
    } finally {
        flock($handle, LOCK_UN);
        fclose($handle);
    }
}

function carmaja_api_json_body(): array
{
    $raw = file_get_contents('php://input');

    if (!is_string($raw) || trim($raw) === '') {
        return [];
    }

    $decoded = json_decode($raw, true);

    if (!is_array($decoded)) {
        throw new CarmajaApiException(400, 'Ungueltiger JSON-Body.');
    }

    return $decoded;
}

function carmaja_api_validate_draft_id(string $draftId): void
{
    if (preg_match(CARMAJA_DRAFT_PATTERN, $draftId) !== 1) {
        throw new CarmajaApiException(400, 'Ungueltige draftId.');
    }
}

function carmaja_api_validate_operation_id(string $operationId): void
{
    if (preg_match(CARMAJA_OPERATION_PATTERN, $operationId) !== 1) {
        throw new CarmajaApiException(400, 'Ungueltige operationId.');
    }
}

function carmaja_api_draft_path(string $draftId): string
{
    carmaja_api_validate_draft_id($draftId);
    return carmaja_api_path('products/drafts/' . $draftId . '.json');
}

function carmaja_api_load_draft(string $draftId): ?array
{
    $path = carmaja_api_draft_path($draftId);

    if (!is_file($path)) {
        return null;
    }

    return carmaja_api_read_json($path);
}

function carmaja_api_save_draft(array $draft): array
{
    carmaja_api_write_json_atomic(carmaja_api_draft_path((string) $draft['draftId']), $draft);
    return $draft;
}

function carmaja_api_tokens_path(): string
{
    return carmaja_api_path('auth/device-tokens.json');
}

function carmaja_api_users_file(): string
{
    $path = getenv('CARMAJA_API_USERS_FILE');

    if (!is_string($path) || trim($path) === '') {
        throw new CarmajaApiException(503, 'CARMAJA_API_USERS_FILE ist nicht konfiguriert.');
    }

    $realPath = realpath(trim($path));

    if ($realPath === false || !is_file($realPath)) {
        throw new CarmajaApiException(503, 'Benutzerdatei ist nicht erreichbar.');
    }

    return $realPath;
}

function carmaja_api_token_pepper(): string
{
    $pepper = getenv('CARMAJA_TOKEN_PEPPER');

    if (!is_string($pepper) || strlen($pepper) < 32) {
        throw new CarmajaApiException(503, 'CARMAJA_TOKEN_PEPPER ist nicht sicher konfiguriert.');
    }

    return $pepper;
}

function carmaja_api_hash_token_secret(string $secret): string
{
    return hash_hmac('sha256', $secret, carmaja_api_token_pepper());
}

function carmaja_api_load_tokens(): array
{
    $data = carmaja_api_read_json(carmaja_api_tokens_path(), ['tokens' => []]);
    $data['tokens'] = is_array($data['tokens'] ?? null) ? $data['tokens'] : [];
    return $data;
}

function carmaja_api_store_tokens(array $tokens): void
{
    carmaja_api_write_json_atomic(carmaja_api_tokens_path(), $tokens);
}

function carmaja_api_login(array $body): array
{
    $username = $body['username'] ?? null;
    $password = $body['password'] ?? null;
    $deviceName = $body['deviceName'] ?? 'Android';

    if (!is_string($username)
        || !is_string($password)
        || !is_string($deviceName)
        || trim($username) === ''
        || trim($password) === '') {
        throw new CarmajaApiException(400, 'Benutzername, Passwort und Geraet sind erforderlich.');
    }

    $users = carmaja_api_read_json(carmaja_api_users_file(), ['users' => []]);
    $matchedUser = null;

    foreach (($users['users'] ?? []) as $user) {
        if (is_array($user)
            && ($user['username'] ?? null) === $username
            && ($user['active'] ?? true) === true) {
            $matchedUser = $user;
            break;
        }
    }

    if (!is_array($matchedUser)
        || !is_string($matchedUser['passwordHash'] ?? null)
        || !password_verify($password, $matchedUser['passwordHash'])) {
        carmaja_api_audit('login_failed', ['username' => $username]);
        throw new CarmajaApiException(401, 'Anmeldung fehlgeschlagen.');
    }

    $tokenId = bin2hex(random_bytes(16));
    $secret = bin2hex(random_bytes(32));
    $token = 'ct_' . $tokenId . '_' . $secret;

    carmaja_api_with_lock('device-tokens', function () use (
        $tokenId,
        $secret,
        $username,
        $deviceName
    ): void {
        $tokens = carmaja_api_load_tokens();
        $tokens['tokens'][$tokenId] = [
            'tokenId' => $tokenId,
            'secretHash' => carmaja_api_hash_token_secret($secret),
            'username' => $username,
            'deviceName' => mb_substr(trim($deviceName), 0, 80),
            'createdAt' => carmaja_api_now(),
            'lastUsedAt' => null,
            'revokedAt' => null,
        ];
        carmaja_api_store_tokens($tokens);
    });

    carmaja_api_audit('login_success', [
        'username' => $username,
        'tokenId' => $tokenId,
        'deviceName' => $deviceName,
    ]);

    return [
        'token' => $token,
        'tokenId' => $tokenId,
        'username' => $username,
    ];
}

function carmaja_api_authorize(): array
{
    $header = $_SERVER['HTTP_AUTHORIZATION'] ?? '';

    if (!is_string($header)
        || !preg_match('/^Bearer\s+ct_([0-9a-f]{32})_([0-9a-f]{64})$/', $header, $matches)) {
        throw new CarmajaApiException(401, 'Authentifizierung erforderlich.');
    }

    $tokenId = $matches[1];
    $secret = $matches[2];

    return carmaja_api_with_lock('device-tokens', function () use ($tokenId, $secret): array {
        $tokens = carmaja_api_load_tokens();
        $token = $tokens['tokens'][$tokenId] ?? null;

        if (!is_array($token)
            || !is_string($token['secretHash'] ?? null)
            || !hash_equals($token['secretHash'], carmaja_api_hash_token_secret($secret))
            || ($token['revokedAt'] ?? null) !== null) {
            throw new CarmajaApiException(403, 'Geraete-Token ist ungueltig oder widerrufen.');
        }

        $token['lastUsedAt'] = carmaja_api_now();
        $tokens['tokens'][$tokenId] = $token;
        carmaja_api_store_tokens($tokens);

        return [
            'tokenId' => $tokenId,
            'username' => (string) ($token['username'] ?? 'admin'),
            'deviceName' => (string) ($token['deviceName'] ?? 'Android'),
        ];
    });
}

function carmaja_api_audit(string $action, array $context = []): void
{
    try {
        $path = carmaja_api_path('audit/actions-' . gmdate('Y-m') . '.jsonl');
        carmaja_api_ensure_directory(dirname($path));
        $entry = [
            'at' => carmaja_api_now(),
            'action' => $action,
            'context' => $context,
        ];
        file_put_contents(
            $path,
            json_encode($entry, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL,
            FILE_APPEND | LOCK_EX
        );
    } catch (Throwable $ignored) {
        // API actions must not expose audit write failures to public callers.
    }
}

function carmaja_api_slugify(string $value): string
{
    $normalized = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
    $slug = strtolower((string) $normalized);
    $slug = preg_replace('/[^a-z0-9]+/', '-', $slug) ?? '';
    $slug = trim($slug, '-');

    return $slug !== '' ? $slug : 'armband';
}

function carmaja_api_validate_vinted_url(string $url): void
{
    if (preg_match('/^https:\/\/(?:www\.)?vinted\.de\//', $url) !== 1) {
        throw new CarmajaApiException(400, 'Vinted-URL muss eine HTTPS-URL von vinted.de sein.');
    }
}

function carmaja_api_normalize_string_list(mixed $value, string $field): array
{
    if (!is_array($value)) {
        throw new CarmajaApiException(400, $field . ' muss eine Liste sein.');
    }

    $items = [];

    foreach ($value as $item) {
        if (!is_string($item)) {
            throw new CarmajaApiException(400, $field . ' enthaelt ungueltige Werte.');
        }

        $trimmed = trim($item);

        if ($trimmed !== '') {
            $items[] = mb_substr($trimmed, 0, 120);
        }
    }

    return array_values(array_unique($items));
}

function carmaja_api_validate_draft_payload(
    array $payload,
    string $draftId,
    ?array $existing
): array {
    $name = trim((string) ($payload['name'] ?? ''));
    $status = (string) ($payload['status'] ?? 'draft');

    if (!in_array($status, ['draft', 'ready'], true)) {
        throw new CarmajaApiException(400, 'Speichern erlaubt nur draft oder ready.');
    }

    if ($name === '' && $status === 'ready') {
        throw new CarmajaApiException(400, 'Produktname ist erforderlich.');
    }

    $stock = (int) ($payload['stock'] ?? 1);

    if ($stock < 0 || $stock > 99) {
        throw new CarmajaApiException(400, 'Bestand ist ungueltig.');
    }

    $draft = $existing ?? [
        'draftId' => $draftId,
        'sku' => null,
        'slug' => null,
        'version' => 0,
        'createdAt' => carmaja_api_now(),
        'images' => [],
    ];

    $vintedUrl = trim((string) ($payload['vintedUrl'] ?? ''));

    if ($vintedUrl !== '') {
        carmaja_api_validate_vinted_url($vintedUrl);
    }

    $draft['draftId'] = $draftId;
    $draft['status'] = $status;
    $draft['name'] = mb_substr($name, 0, 120);
    $draft['materials'] = carmaja_api_normalize_string_list($payload['materials'] ?? [], 'Materialien');
    $draft['metalElements'] = carmaja_api_normalize_string_list($payload['metalElements'] ?? [], 'Metallelemente');
    $draft['braceletSize'] = mb_substr(trim((string) ($payload['braceletSize'] ?? '')), 0, 60);
    $draft['stock'] = $stock;
    $draft['shortDescription'] = mb_substr(trim((string) ($payload['shortDescription'] ?? '')), 0, 500);
    $draft['careInstructions'] = carmaja_api_normalize_string_list($payload['careInstructions'] ?? [], 'Pflegehinweise');
    $draft['vintedUrl'] = $vintedUrl;
    $draft['internalCalculation'] = is_array($payload['internalCalculation'] ?? null)
        ? $payload['internalCalculation']
        : [];
    $draft['updatedAt'] = carmaja_api_now();

    return $draft;
}

function carmaja_api_save_product(string $draftId, array $body, array $actor): array
{
    carmaja_api_validate_draft_id($draftId);
    $expectedVersion = $body['expectedVersion'] ?? null;

    if (!is_int($expectedVersion)) {
        throw new CarmajaApiException(400, 'expectedVersion ist erforderlich.');
    }

    return carmaja_api_with_lock('draft-' . $draftId, function () use (
        $draftId,
        $body,
        $expectedVersion,
        $actor
    ): array {
        $existing = carmaja_api_load_draft($draftId);
        $currentVersion = is_array($existing) ? (int) ($existing['version'] ?? 0) : 0;

        if ($currentVersion !== $expectedVersion) {
            throw new CarmajaApiException(409, 'Der Entwurf wurde bereits geaendert.', [
                'current' => $existing,
            ]);
        }

        $draft = carmaja_api_validate_draft_payload($body, $draftId, $existing);
        $draft['version'] = $currentVersion + 1;
        carmaja_api_save_draft($draft);
        carmaja_api_audit('product_saved', [
            'draftId' => $draftId,
            'version' => $draft['version'],
            'tokenId' => $actor['tokenId'],
        ]);

        return $draft;
    });
}

function carmaja_api_require_publishable(array $draft): void
{
    foreach (['name', 'braceletSize', 'shortDescription', 'vintedUrl'] as $field) {
        if (!is_string($draft[$field] ?? null) || trim($draft[$field]) === '') {
            throw new CarmajaApiException(400, $field . ' ist fuer Publish erforderlich.');
        }
    }

    if (($draft['materials'] ?? []) === []) {
        throw new CarmajaApiException(400, 'Mindestens ein Material ist erforderlich.');
    }

    if (!is_array($draft['images'] ?? null) || count($draft['images']) < 1) {
        throw new CarmajaApiException(400, 'Mindestens ein Hauptfoto ist erforderlich.');
    }

    carmaja_api_validate_vinted_url((string) $draft['vintedUrl']);
}

function carmaja_api_allocate_sku(): string
{
    return carmaja_api_with_lock('sku-counter', function (): string {
        $year = gmdate('Y');
        $path = carmaja_api_path('products/sku-counter.json');
        $counter = carmaja_api_read_json($path, ['years' => []]);
        $years = is_array($counter['years'] ?? null) ? $counter['years'] : [];
        $next = ((int) ($years[$year] ?? 0)) + 1;
        $years[$year] = $next;
        $counter['years'] = $years;
        carmaja_api_write_json_atomic($path, $counter);

        return sprintf('CP-%s-%04d', $year, $next);
    });
}

function carmaja_api_public_product_from_draft(array $draft): array
{
    $sku = (string) $draft['sku'];
    $slug = (string) $draft['slug'];
    $publicImages = [];
    $index = 1;

    foreach (($draft['images'] ?? []) as $image) {
        if (!is_array($image) || !is_file((string) ($image['path'] ?? ''))) {
            continue;
        }

        $fileName = sprintf('%02d.jpg', $index);
        $publicImages[] = [
            'src' => '/images/products/' . $sku . '/' . $fileName,
            'alt' => (string) ($image['alt'] ?? $draft['name']),
            'width' => (int) ($image['width'] ?? 1600),
            'height' => (int) ($image['height'] ?? 1200),
            'isMain' => $index === 1,
            '_sourcePath' => (string) $image['path'],
            '_repoPath' => 'website/public/images/products/' . $sku . '/' . $fileName,
        ];
        $index++;
    }

    return [
        'draftId' => (string) $draft['draftId'],
        'sku' => $sku,
        'slug' => $slug,
        'status' => (string) $draft['status'],
        'name' => (string) $draft['name'],
        'materials' => array_values($draft['materials'] ?? []),
        'metalElements' => array_values($draft['metalElements'] ?? []),
        'braceletSize' => (string) $draft['braceletSize'],
        'stock' => (int) ($draft['stock'] ?? 1),
        'shortDescription' => (string) $draft['shortDescription'],
        'careInstructions' => array_values($draft['careInstructions'] ?? []),
        'images' => array_map(
            static fn (array $image): array => array_diff_key($image, [
                '_sourcePath' => true,
                '_repoPath' => true,
            ]),
            $publicImages
        ),
        'vintedUrl' => (string) $draft['vintedUrl'],
        'createdAt' => (string) $draft['createdAt'],
        'updatedAt' => (string) $draft['updatedAt'],
        'publishedAt' => (string) ($draft['publishedAt'] ?? carmaja_api_now()),
        'soldAt' => $draft['soldAt'] ?? null,
        '_imageBlobs' => $publicImages,
    ];
}

function carmaja_api_idempotency_path(string $operationId): string
{
    carmaja_api_validate_operation_id($operationId);
    return carmaja_api_path('idempotency/' . hash('sha256', $operationId) . '.json');
}

function carmaja_api_request_hash(array $body): string
{
    return hash('sha256', json_encode($body, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
}

function carmaja_api_publish(string $draftId, array $body, array $actor, string $newStatus): array
{
    carmaja_api_validate_draft_id($draftId);
    $expectedVersion = $body['expectedVersion'] ?? null;
    $operationId = $body['operationId'] ?? null;

    if (!is_int($expectedVersion) || !is_string($operationId)) {
        throw new CarmajaApiException(400, 'expectedVersion und operationId sind erforderlich.');
    }

    carmaja_api_validate_operation_id($operationId);
    $requestHash = carmaja_api_request_hash([
        'draftId' => $draftId,
        'expectedVersion' => $expectedVersion,
        'operationId' => $operationId,
        'status' => $newStatus,
    ]);

    return carmaja_api_with_lock('operation-' . hash('sha256', $operationId), function () use (
        $draftId,
        $expectedVersion,
        $operationId,
        $requestHash,
        $actor,
        $newStatus
    ): array {
        $idempotencyPath = carmaja_api_idempotency_path($operationId);
        $existingOperation = carmaja_api_read_json($idempotencyPath, []);

        if ($existingOperation !== []) {
            if (($existingOperation['requestHash'] ?? null) !== $requestHash) {
                throw new CarmajaApiException(409, 'operationId wurde mit anderem Inhalt verwendet.');
            }

            if (($existingOperation['state'] ?? null) === 'completed') {
                return $existingOperation['response'];
            }

            throw new CarmajaApiException(409, 'Operation wird bereits verarbeitet.', [
                'operation' => $existingOperation,
            ]);
        }

        carmaja_api_write_json_atomic($idempotencyPath, [
            'operationId' => $operationId,
            'requestHash' => $requestHash,
            'state' => 'in_progress',
            'startedAt' => carmaja_api_now(),
            'draftId' => $draftId,
            'status' => $newStatus,
        ]);

        $draft = carmaja_api_with_lock('draft-' . $draftId, function () use (
            $draftId,
            $expectedVersion,
            $newStatus
        ): array {
            $draft = carmaja_api_load_draft($draftId);

            if (!is_array($draft)) {
                throw new CarmajaApiException(404, 'Entwurf wurde nicht gefunden.');
            }

            $currentVersion = (int) ($draft['version'] ?? 0);

            if ($currentVersion !== $expectedVersion) {
                throw new CarmajaApiException(409, 'Der Entwurf wurde bereits geaendert.', [
                    'current' => $draft,
                ]);
            }

            if ($newStatus === 'published') {
                carmaja_api_require_publishable($draft);

                if (!is_string($draft['sku'] ?? null) || $draft['sku'] === '') {
                    $draft['sku'] = carmaja_api_allocate_sku();
                }

                if (!is_string($draft['slug'] ?? null) || $draft['slug'] === '') {
                    $draft['slug'] = strtolower((string) $draft['sku'])
                        . '-' . carmaja_api_slugify((string) $draft['name']);
                }

                $draft['publishedAt'] = $draft['publishedAt'] ?? carmaja_api_now();
            } elseif ($newStatus === 'sold') {
                if (!is_string($draft['sku'] ?? null) || $draft['sku'] === '') {
                    throw new CarmajaApiException(400, 'Nur veroeffentlichte Produkte koennen verkauft werden.');
                }

                $draft['soldAt'] = carmaja_api_now();
            } elseif ($newStatus === 'disabled') {
                if (!is_string($draft['sku'] ?? null) || $draft['sku'] === '') {
                    throw new CarmajaApiException(400, 'Nur veroeffentlichte Produkte koennen deaktiviert werden.');
                }
            }

            $draft['status'] = $newStatus;
            $draft['version'] = $currentVersion + 1;
            $draft['updatedAt'] = carmaja_api_now();
            carmaja_api_save_draft($draft);

            return $draft;
        });

        $publicProduct = carmaja_api_public_product_from_draft($draft);
        $commitSha = carmaja_api_commit_public_product($publicProduct);
        $response = [
            'draftId' => $draftId,
            'sku' => $draft['sku'],
            'slug' => $draft['slug'],
            'version' => $draft['version'],
            'operationId' => $operationId,
            'commitSha' => $commitSha,
            'deploymentStatus' => 'queued',
            'status' => $newStatus,
        ];

        carmaja_api_write_json_atomic($idempotencyPath, [
            'operationId' => $operationId,
            'requestHash' => $requestHash,
            'state' => 'completed',
            'completedAt' => carmaja_api_now(),
            'draftId' => $draftId,
            'status' => $newStatus,
            'response' => $response,
        ]);
        carmaja_api_audit('product_' . $newStatus, [
            'draftId' => $draftId,
            'sku' => $draft['sku'],
            'operationId' => $operationId,
            'commitSha' => $commitSha,
            'tokenId' => $actor['tokenId'],
        ]);

        return $response;
    });
}

function carmaja_api_github_token(): string
{
    $tokenFile = getenv('CARMAJA_GITHUB_TOKEN_FILE');

    if (!is_string($tokenFile) || trim($tokenFile) === '') {
        throw new CarmajaApiException(503, 'CARMAJA_GITHUB_TOKEN_FILE ist nicht konfiguriert.');
    }

    $realPath = realpath(trim($tokenFile));

    if ($realPath === false || !is_file($realPath)) {
        throw new CarmajaApiException(503, 'GitHub-Token-Datei ist nicht erreichbar.');
    }

    $token = trim((string) file_get_contents($realPath));

    if ($token === '') {
        throw new CarmajaApiException(503, 'GitHub-Token ist leer.');
    }

    return $token;
}

function carmaja_api_github_repository(): string
{
    $repository = getenv('CARMAJA_GITHUB_REPOSITORY');

    if (!is_string($repository)
        || preg_match('/^[A-Za-z0-9_.-]+\/[A-Za-z0-9_.-]+$/', trim($repository)) !== 1) {
        throw new CarmajaApiException(503, 'CARMAJA_GITHUB_REPOSITORY ist nicht korrekt konfiguriert.');
    }

    return trim($repository);
}

function carmaja_api_github_request(string $method, string $path, ?array $body = null): array
{
    $url = 'https://api.github.com' . $path;
    $headers = [
        'Accept: application/vnd.github+json',
        'Authorization: Bearer ' . carmaja_api_github_token(),
        'User-Agent: Carmaja-Perlen-Product-API',
        'X-GitHub-Api-Version: 2022-11-28',
    ];

    $payload = null;

    if ($body !== null) {
        $payload = json_encode($body, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $headers[] = 'Content-Type: application/json';
    }

    $context = stream_context_create([
        'http' => [
            'method' => $method,
            'header' => implode("\r\n", $headers),
            'content' => $payload ?? '',
            'ignore_errors' => true,
            'timeout' => 20,
        ],
    ]);
    $response = file_get_contents($url, false, $context);
    $statusLine = $http_response_header[0] ?? 'HTTP/1.1 500';
    preg_match('/\s(\d{3})\s/', $statusLine, $matches);
    $statusCode = (int) ($matches[1] ?? 500);
    $decoded = is_string($response) && $response !== ''
        ? json_decode($response, true)
        : [];

    if ($statusCode < 200 || $statusCode >= 300 || !is_array($decoded)) {
        throw new CarmajaApiException(502, 'GitHub-Anfrage ist fehlgeschlagen.', [
            'statusCode' => $statusCode,
            'path' => $path,
        ]);
    }

    return $decoded;
}

function carmaja_api_assert_repo_path_allowed(string $path): void
{
    $normalized = str_replace('\\', '/', $path);
    $isAllowed = $normalized === 'website/content/products.json'
        || preg_match('/^website\/public\/images\/products\/CP-\d{4}-\d{4}\/\d{2}\.jpg$/', $normalized) === 1;

    if (!$isAllowed
        || str_contains($normalized, '..')
        || str_starts_with($normalized, '.github/')
        || str_contains($normalized, '/.github/')) {
        throw new CarmajaApiException(500, 'GitHub-Pfad ist nicht erlaubt.');
    }
}

function carmaja_api_commit_public_product(array $publicProduct): string
{
    $repository = carmaja_api_github_repository();
    $branch = getenv('CARMAJA_GITHUB_BRANCH');
    $branch = is_string($branch) && trim($branch) !== '' ? trim($branch) : 'main';
    $repoPathPrefix = '/repos/' . $repository;

    $ref = carmaja_api_github_request(
        'GET',
        $repoPathPrefix . '/git/ref/heads/' . rawurlencode($branch)
    );
    $headSha = (string) ($ref['object']['sha'] ?? '');
    $headCommit = carmaja_api_github_request('GET', $repoPathPrefix . '/git/commits/' . $headSha);
    $baseTree = (string) ($headCommit['tree']['sha'] ?? '');
    $content = carmaja_api_github_request(
        'GET',
        $repoPathPrefix . '/contents/website/content/products.json?ref=' . rawurlencode($branch)
    );
    $currentProducts = [];

    if (is_string($content['content'] ?? null)) {
        $decoded = json_decode(base64_decode((string) $content['content']), true);
        $currentProducts = is_array($decoded) ? $decoded : ['version' => 1, 'products' => []];
    }

    $products = is_array($currentProducts['products'] ?? null)
        ? $currentProducts['products']
        : [];
    $publicProductForJson = array_diff_key($publicProduct, ['_imageBlobs' => true]);
    $replaced = false;
    $existingPublicProduct = null;

    foreach ($products as $index => $product) {
        if (is_array($product) && ($product['draftId'] ?? null) === $publicProduct['draftId']) {
            $existingPublicProduct = $product;
            $products[$index] = $publicProductForJson;
            $replaced = true;
            break;
        }
    }

    if (!$replaced) {
        $products[] = $publicProductForJson;
    }

    $publicProductsFile = [
        'version' => 1,
        'products' => array_values($products),
    ];
    $tree = [];
    $productsJson = json_encode(
        $publicProductsFile,
        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
    ) . PHP_EOL;
    $productsPath = 'website/content/products.json';
    carmaja_api_assert_repo_path_allowed($productsPath);
    $tree[] = [
        'path' => $productsPath,
        'mode' => '100644',
        'type' => 'blob',
        'content' => $productsJson,
    ];

    if (($publicProduct['status'] ?? null) === 'disabled') {
        foreach (($existingPublicProduct['images'] ?? []) as $existingImage) {
            if (!is_array($existingImage) || !is_string($existingImage['src'] ?? null)) {
                continue;
            }

            $repoPath = 'website/public' . $existingImage['src'];
            carmaja_api_assert_repo_path_allowed($repoPath);
            $tree[] = [
                'path' => $repoPath,
                'mode' => '100644',
                'type' => 'blob',
                'sha' => null,
            ];
        }
    } else {
        foreach (($publicProduct['_imageBlobs'] ?? []) as $imageBlob) {
            if (!is_array($imageBlob)) {
                continue;
            }

            $repoPath = (string) $imageBlob['_repoPath'];
            $sourcePath = (string) $imageBlob['_sourcePath'];
            carmaja_api_assert_repo_path_allowed($repoPath);

            if (!is_file($sourcePath)) {
                throw new CarmajaApiException(500, 'Produktbild fehlt fuer GitHub-Commit.');
            }

            $blob = carmaja_api_github_request('POST', $repoPathPrefix . '/git/blobs', [
                'content' => base64_encode((string) file_get_contents($sourcePath)),
                'encoding' => 'base64',
            ]);
            $tree[] = [
                'path' => $repoPath,
                'mode' => '100644',
                'type' => 'blob',
                'sha' => (string) $blob['sha'],
            ];
        }
    }

    $newTree = carmaja_api_github_request('POST', $repoPathPrefix . '/git/trees', [
        'base_tree' => $baseTree,
        'tree' => $tree,
    ]);
    $commit = carmaja_api_github_request('POST', $repoPathPrefix . '/git/commits', [
        'message' => 'Publish product ' . $publicProduct['sku'],
        'tree' => (string) $newTree['sha'],
        'parents' => [$headSha],
    ]);
    $commitSha = (string) $commit['sha'];
    carmaja_api_github_request('PATCH', $repoPathPrefix . '/git/refs/heads/' . rawurlencode($branch), [
        'sha' => $commitSha,
        'force' => false,
    ]);

    return $commitSha;
}

function carmaja_api_image_orientation(string $path): int
{
    if (!function_exists('exif_read_data')) {
        return 1;
    }

    $exif = @exif_read_data($path);

    if (!is_array($exif)) {
        return 1;
    }

    return (int) ($exif['Orientation'] ?? 1);
}

function carmaja_api_apply_orientation(GdImage $image, int $orientation): GdImage
{
    return match ($orientation) {
        3 => imagerotate($image, 180, 0),
        6 => imagerotate($image, -90, 0),
        8 => imagerotate($image, 90, 0),
        default => $image,
    };
}

function carmaja_api_upload_images(string $draftId, array $body, array $actor): array
{
    carmaja_api_validate_draft_id($draftId);
    $expectedVersion = isset($_POST['expectedVersion']) ? (int) $_POST['expectedVersion'] : null;

    if (!is_int($expectedVersion)) {
        throw new CarmajaApiException(400, 'expectedVersion ist erforderlich.');
    }

    if (!extension_loaded('gd')) {
        throw new CarmajaApiException(500, 'PHP-GD ist fuer Bildverarbeitung erforderlich.');
    }

    $files = $_FILES['images'] ?? null;

    if (!is_array($files) || !is_array($files['tmp_name'] ?? null)) {
        throw new CarmajaApiException(400, 'Keine Bilder uebertragen.');
    }

    $count = count($files['tmp_name']);

    if ($count < 1 || $count > CARMAJA_MAX_IMAGES) {
        throw new CarmajaApiException(400, 'Es sind ein bis fuenf Bilder erlaubt.');
    }

    $processed = [];
    $temporaryDirectory = carmaja_api_path('products/tmp/' . $draftId . '-' . bin2hex(random_bytes(6)));
    carmaja_api_ensure_directory($temporaryDirectory);

    try {
        for ($index = 0; $index < $count; $index++) {
            $tmpName = $files['tmp_name'][$index] ?? null;
            $error = (int) ($files['error'][$index] ?? UPLOAD_ERR_NO_FILE);
            $size = (int) ($files['size'][$index] ?? 0);

            if ($error !== UPLOAD_ERR_OK
                || !is_string($tmpName)
                || !is_uploaded_file($tmpName)
                || $size <= 0
                || $size > CARMAJA_MAX_IMAGE_BYTES) {
                throw new CarmajaApiException(400, 'Bild-Upload ist ungueltig.');
            }

            $info = getimagesize($tmpName);

            if ($info === false || ($info[2] ?? null) !== IMAGETYPE_JPEG) {
                throw new CarmajaApiException(400, 'Nur echte JPEG-Dateien sind erlaubt.');
            }

            $raw = file_get_contents($tmpName);
            $image = is_string($raw) ? imagecreatefromstring($raw) : false;

            if (!$image instanceof GdImage) {
                throw new CarmajaApiException(400, 'Bild konnte nicht decodiert werden.');
            }

            $image = carmaja_api_apply_orientation($image, carmaja_api_image_orientation($tmpName));
            $width = imagesx($image);
            $height = imagesy($image);
            $temporaryFile = $temporaryDirectory . DIRECTORY_SEPARATOR . sprintf('%02d.jpg', $index + 1);

            if (!imagejpeg($image, $temporaryFile, 86)) {
                throw new CarmajaApiException(500, 'Bild konnte nicht neu gespeichert werden.');
            }

            imagedestroy($image);
            chmod($temporaryFile, 0640);
            $processed[] = [
                'path' => $temporaryFile,
                'width' => $width,
                'height' => $height,
                'alt' => is_array($_POST['alt'] ?? null) && is_string($_POST['alt'][$index] ?? null)
                    ? mb_substr(trim($_POST['alt'][$index]), 0, 160)
                    : '',
                'isMain' => $index === 0,
            ];
        }

        return carmaja_api_with_lock('draft-' . $draftId, function () use (
            $draftId,
            $expectedVersion,
            $processed,
            $actor
        ): array {
            $draft = carmaja_api_load_draft($draftId);

            if (!is_array($draft)) {
                throw new CarmajaApiException(404, 'Entwurf wurde nicht gefunden.');
            }

            $currentVersion = (int) ($draft['version'] ?? 0);

            if ($currentVersion !== $expectedVersion) {
                throw new CarmajaApiException(409, 'Der Entwurf wurde bereits geaendert.', [
                    'current' => $draft,
                ]);
            }

            $imageDirectory = carmaja_api_path('products/images/' . $draftId);
            carmaja_api_ensure_directory($imageDirectory);
            $finalImages = [];

            foreach ($processed as $index => $image) {
                $finalPath = $imageDirectory . DIRECTORY_SEPARATOR . sprintf('%02d.jpg', $index + 1);

                if (!rename($image['path'], $finalPath)) {
                    throw new CarmajaApiException(500, 'Bild konnte nicht atomar uebernommen werden.');
                }

                $image['path'] = $finalPath;
                $image['alt'] = $image['alt'] !== ''
                    ? $image['alt']
                    : (string) ($draft['name'] ?? 'Carmaja-Perlen Armband');
                $finalImages[] = $image;
            }

            $draft['images'] = $finalImages;
            $draft['version'] = $currentVersion + 1;
            $draft['updatedAt'] = carmaja_api_now();
            carmaja_api_save_draft($draft);
            carmaja_api_audit('product_images_uploaded', [
                'draftId' => $draftId,
                'version' => $draft['version'],
                'count' => count($finalImages),
                'tokenId' => $actor['tokenId'],
            ]);

            return $draft;
        });
    } finally {
        foreach (glob($temporaryDirectory . DIRECTORY_SEPARATOR . '*') ?: [] as $file) {
            @unlink($file);
        }

        @rmdir($temporaryDirectory);
    }
}

function carmaja_api_list_products(): array
{
    $directory = carmaja_api_path('products/drafts');

    if (!is_dir($directory)) {
        return ['products' => []];
    }

    $products = [];

    foreach (glob($directory . DIRECTORY_SEPARATOR . '*.json') ?: [] as $path) {
        $draft = carmaja_api_read_json($path);
        $products[] = [
            'draftId' => $draft['draftId'] ?? null,
            'sku' => $draft['sku'] ?? null,
            'slug' => $draft['slug'] ?? null,
            'version' => $draft['version'] ?? 0,
            'status' => $draft['status'] ?? 'draft',
            'name' => $draft['name'] ?? '',
            'updatedAt' => $draft['updatedAt'] ?? null,
        ];
    }

    usort(
        $products,
        static fn (array $left, array $right): int =>
            (string) ($right['updatedAt'] ?? '') <=> (string) ($left['updatedAt'] ?? '')
    );

    return ['products' => $products];
}

function carmaja_api_create_backup(): array
{
    return carmaja_api_with_lock('backup', function (): array {
        $source = carmaja_api_private_dir();
        $backupRoot = carmaja_api_path('backups');
        carmaja_api_ensure_directory($backupRoot);
        $name = gmdate('Ymd-His');
        $target = $backupRoot . DIRECTORY_SEPARATOR . $name;
        carmaja_api_ensure_directory($target);
        carmaja_api_copy_tree($source . DIRECTORY_SEPARATOR . 'products', $target . DIRECTORY_SEPARATOR . 'products');
        carmaja_api_copy_tree($source . DIRECTORY_SEPARATOR . 'auth', $target . DIRECTORY_SEPARATOR . 'auth');
        carmaja_api_copy_tree($source . DIRECTORY_SEPARATOR . 'audit', $target . DIRECTORY_SEPARATOR . 'audit');
        carmaja_api_prune_backups($backupRoot);
        carmaja_api_audit('backup_created', ['backup' => $name]);

        return ['backup' => $name, 'status' => 'created'];
    });
}

function carmaja_api_copy_tree(string $source, string $target): void
{
    if (!is_dir($source)) {
        return;
    }

    carmaja_api_ensure_directory($target);

    foreach (new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    ) as $entry) {
        $relative = substr($entry->getPathname(), strlen($source) + 1);
        $destination = $target . DIRECTORY_SEPARATOR . $relative;

        if ($entry->isDir()) {
            carmaja_api_ensure_directory($destination);
        } else {
            carmaja_api_ensure_directory(dirname($destination));
            copy($entry->getPathname(), $destination);
            chmod($destination, 0640);
        }
    }
}

function carmaja_api_prune_backups(string $backupRoot): void
{
    $directories = array_filter(
        glob($backupRoot . DIRECTORY_SEPARATOR . '*') ?: [],
        'is_dir'
    );
    rsort($directories);

    foreach (array_slice($directories, CARMAJA_BACKUP_KEEP) as $directory) {
        carmaja_api_remove_tree($directory);
    }
}

function carmaja_api_remove_tree(string $directory): void
{
    if (!is_dir($directory)) {
        return;
    }

    foreach (new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    ) as $entry) {
        $entry->isDir() ? @rmdir($entry->getPathname()) : @unlink($entry->getPathname());
    }

    @rmdir($directory);
}
