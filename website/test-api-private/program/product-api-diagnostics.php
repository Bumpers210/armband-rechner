<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

define('CARMAJA_BOOTSTRAP_NO_RUN', true);
require_once __DIR__ . '/bootstrap.php';

try {
    $arguments = array_slice($_SERVER['argv'] ?? [], 1);
    $githubReadonly = $arguments === ['--github-readonly'];
    $githubReadonlyTokenStdin =
        $arguments === ['--github-readonly-token-stdin'];

    if ($arguments !== []
        && !$githubReadonly
        && !$githubReadonlyTokenStdin) {
        throw new CarmajaBootstrapException(
            'diagnostic_arguments_invalid',
            'Unbekannte Diagnoseoption.'
        );
    }

    $configFile = getenv('CARMAJA_CONFIG_FILE');

    if (!is_string($configFile) || trim($configFile) === '') {
        throw new CarmajaBootstrapException(
            'config_path_missing',
            'CARMAJA_CONFIG_FILE ist nicht konfiguriert.'
        );
    }

    $config = carmaja_bootstrap_prepare(trim($configFile));
    $result = carmaja_api_diagnose_environment();

    if ($githubReadonlyTokenStdin) {
        if ($config['githubAdapterEnabled']
            || $config['publishTarget'] !== 'test'
            || $config['productionPublishEnabled']) {
            throw new CarmajaBootstrapException(
                'github_readonly_configuration_invalid',
                'GitHub-Nur-Lese-Diagnose ist nicht sicher konfiguriert.'
            );
        }

        $input = stream_get_contents(STDIN, 514);

        if (!is_string($input) || strlen($input) > 513) {
            throw new CarmajaApiException(
                400,
                'GitHub-Token ist ungÃ¼ltig.',
                [],
                'github_token_invalid'
            );
        }

        $token = rtrim($input, "\r\n");

        if ($token === ''
            || str_contains($token, "\r")
            || str_contains($token, "\n")) {
            throw new CarmajaApiException(
                400,
                'GitHub-Token ist ungÃ¼ltig.',
                [],
                'github_token_invalid'
            );
        }

        $GLOBALS['CARMAJA_API_GITHUB_READONLY_TOKEN'] =
            carmaja_api_validate_github_token($token);
        $token = '';

        try {
            $result['github'] = carmaja_api_github_readonly_diagnostic();
        } finally {
            unset($GLOBALS['CARMAJA_API_GITHUB_READONLY_TOKEN']);
        }
    } elseif ($githubReadonly) {
        $result['github'] = carmaja_api_github_readonly_diagnostic();
    }

    echo json_encode(
        $result,
        JSON_PRETTY_PRINT
            | JSON_UNESCAPED_SLASHES
            | JSON_UNESCAPED_UNICODE
            | JSON_THROW_ON_ERROR
    ) . PHP_EOL;
    exit(0);
} catch (CarmajaApiException|CarmajaBootstrapException $error) {
    $errorCode = $error->errorCode;
    $fields = $error instanceof CarmajaApiException ? $error->fields : [];
    fwrite(
        STDERR,
        json_encode([
            'ok' => false,
            'error' => [
                'code' => $errorCode,
                'message' => $error->getMessage(),
                'fields' => (object) $fields,
            ],
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL
    );
    exit(5);
} catch (Throwable) {
    fwrite(
        STDERR,
        '{"ok":false,"error":{"code":"diagnostic_failed","message":"Diagnose fehlgeschlagen.","fields":{}}}'
            . PHP_EOL
    );
    exit(5);
}
