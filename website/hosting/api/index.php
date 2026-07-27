<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/_internal/product-api.php';

header('Cache-Control: no-store, max-age=0');
header('Content-Type: application/json; charset=utf-8');
header('X-Robots-Tag: noindex, nofollow, noimageindex', true);

function carmaja_api_send(int $statusCode, array $payload): never
{
    http_response_code($statusCode);
    echo json_encode(
        $payload,
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
    );
    exit;
}

function carmaja_api_success_payload(array $data): array
{
    return carmaja_api_success_response($data);
}

function carmaja_api_error_payload(CarmajaApiException $error): array
{
    return carmaja_api_error_response($error);
}

try {
    carmaja_api_private_dir();
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $path = trim((string) parse_url($_SERVER['REQUEST_URI'] ?? '/api/', PHP_URL_PATH), '/');
    $segments = explode('/', $path);

    if (($segments[0] ?? null) === 'api') {
        array_shift($segments);
    }

    if ($method === 'POST'
        && ($segments[0] ?? null) === 'login'
        && count($segments) === 1) {
        carmaja_api_send(
            200,
            carmaja_api_success_payload(carmaja_api_login(carmaja_api_json_body()))
        );
    }

    $actor = carmaja_api_authorize();

    if ($method === 'GET' && ($segments[0] ?? null) === 'products' && count($segments) === 1) {
        carmaja_api_send(
            200,
            carmaja_api_success_payload(carmaja_api_list_products())
        );
    }

    if (($segments[0] ?? null) === 'products' && isset($segments[1])) {
        $draftId = (string) $segments[1];

        if ($method === 'GET' && count($segments) === 2) {
            $draft = carmaja_api_load_draft($draftId);

            if (!is_array($draft)) {
                throw new CarmajaApiException(404, 'Entwurf wurde nicht gefunden.');
            }

            carmaja_api_send(
                200,
                carmaja_api_success_payload(['product' => $draft])
            );
        }

        if ($method === 'PUT' && count($segments) === 2) {
            carmaja_api_send(
                200,
                carmaja_api_success_payload([
                    'product' => carmaja_api_save_product(
                        $draftId,
                        carmaja_api_json_body(),
                        $actor
                    ),
                ])
            );
        }

        if ($method === 'POST'
            && ($segments[2] ?? null) === 'images'
            && count($segments) === 3) {
            carmaja_api_send(
                200,
                carmaja_api_success_payload([
                    'product' => carmaja_api_upload_images($draftId, $_POST, $actor),
                ])
            );
        }

        if ($method === 'POST'
            && ($segments[2] ?? null) === 'publish'
            && count($segments) === 3) {
            carmaja_api_send(
                200,
                carmaja_api_success_payload(
                    carmaja_api_publish(
                        $draftId,
                        carmaja_api_json_body(),
                        $actor,
                        'published'
                    )
                )
            );
        }

        if ($method === 'POST'
            && ($segments[2] ?? null) === 'sold'
            && count($segments) === 3) {
            carmaja_api_send(
                200,
                carmaja_api_success_payload(
                    carmaja_api_publish(
                        $draftId,
                        carmaja_api_json_body(),
                        $actor,
                        'sold'
                    )
                )
            );
        }

        if ($method === 'POST'
            && ($segments[2] ?? null) === 'disable'
            && count($segments) === 3) {
            carmaja_api_send(
                200,
                carmaja_api_success_payload(
                    carmaja_api_publish(
                        $draftId,
                        carmaja_api_json_body(),
                        $actor,
                        'disabled'
                    )
                )
            );
        }
    }

    if ($method === 'GET'
        && ($segments[0] ?? null) === 'operations'
        && isset($segments[1])
        && count($segments) === 2) {
        carmaja_api_send(
            200,
            carmaja_api_success_payload([
                'operation' => carmaja_api_operation_status((string) $segments[1]),
            ])
        );
    }

    if ($method === 'POST'
        && ($segments[0] ?? null) === 'backups'
        && count($segments) === 1) {
        carmaja_api_send(
            200,
            carmaja_api_success_payload(carmaja_api_create_backup())
        );
    }

    throw new CarmajaApiException(
        404,
        'API-Endpunkt wurde nicht gefunden.',
        [],
        'endpoint_not_found'
    );
} catch (CarmajaApiException $error) {
    if ($error->statusCode === 429) {
        header('Retry-After: ' . CARMAJA_LOGIN_WINDOW_SECONDS);
    }

    carmaja_api_send($error->statusCode, carmaja_api_error_payload($error));
} catch (Throwable) {
    carmaja_api_audit_best_effort('api_error', ['result' => 'internal_error']);
    carmaja_api_send(
        500,
        carmaja_api_error_payload(
            new CarmajaApiException(
                500,
                'Interner API-Fehler.',
                [],
                'internal_error'
            )
        )
    );
}
