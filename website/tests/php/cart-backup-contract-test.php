<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/scripts/commerce-backup.php';

$adapter = new CarmajaCommerceBackup();
$files = [];
try {
    $options = new ReflectionMethod($adapter, 'writeOptions');
    $target = ['host'=>'test.invalid','port'=>3306,'database'=>'artificial_test','user'=>'artificial',
        'password'=>'synthetic;quote"and\\slash','requireTls'=>true,'tlsCaPath'=>''];
    $files[] = $path = $options->invoke($adapter, $target);
    $contents = file_get_contents($path);
    if (!str_contains($contents, 'ssl-mode=REQUIRED') || !str_contains($contents, 'password="synthetic;quote\\"and\\\\slash"')) {
        throw new RuntimeException('tls_or_option_escaping_failed');
    }
    echo "PASS mandatory TLS and safe option escaping\n";
    $files[] = $ca = $options->invoke($adapter, [...$target, 'tlsCaPath'=>'/private/artificial-ca.pem']);
    if (!str_contains(file_get_contents($ca), 'ssl-mode=VERIFY_IDENTITY')) { throw new RuntimeException('ca_verification_failed'); }
    echo "PASS provided CA requires identity verification\n";
    $files[] = $dump = tempnam(sys_get_temp_dir(), 'cart-restore-test-');
    file_put_contents($dump, "SELECT 1;\n");
    $run = new ReflectionMethod($adapter, 'run');
    $run->invoke($adapter, [PHP_BINARY, '-r', 'exit(stream_get_contents(STDIN) === "SELECT 1;\n" ? 0 : 9);'], $dump);
    echo "PASS restore streams dump contents, not its filename\n";
} finally {
    foreach ($files as $file) { if (is_file($file)) unlink($file); }
}
