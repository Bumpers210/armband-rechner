<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/hosting/_internal/daily-visitors.php';

$checks = 0;
function dv_check(bool $ok, string $label): void {
    global $checks;
    if (!$ok) { throw new RuntimeException($label); }
    ++$checks;
}
function dv_reject(callable $call, string $label): void {
    try { $call(); } catch (Throwable) { dv_check(true, $label); return; }
    throw new RuntimeException($label);
}
function dv_child(string $code, array $input): array {
    $proc = proc_open([PHP_BINARY, '-d', 'display_errors=0', '-r', $code], [['pipe','r'],['pipe','w'],['pipe','w']], $pipes);
    fwrite($pipes[0], json_encode($input, JSON_THROW_ON_ERROR)); fclose($pipes[0]);
    $out = stream_get_contents($pipes[1]); fclose($pipes[1]);
    $err = stream_get_contents($pipes[2]); fclose($pipes[2]);
    $exit = proc_close($proc);
    dv_check($exit === 0 && $err === '', 'Child process must succeed without diagnostic leakage');
    return [$out, $exit];
}
$root = sys_get_temp_dir() . '/carmaja-daily-visitors-' . bin2hex(random_bytes(8));
mkdir($root, 0700);
mkdir($root . '/web', 0700);
$file = $root . '/daily-visitors.json';
$legacy = $root . '/clicks.json';
$legacyData = '{"version":3,"days":{"2026-01-01":{"pageviews":{"/":{"views":12}}}},"months":{}}';
file_put_contents($legacy, $legacyData);
file_put_contents($root . '/web/index.html', '<h1>Artificial fixture</h1>');
$now = new DateTimeImmutable('2026-11-28T12:00:00+01:00');
$token = str_repeat('a', 64);
try {
    dv_check(cdv_metric(cdv_empty(), ['2026-11-27'])['value'] === null, 'No historical backfill or invented zero');
    dv_reject(fn() => cdv_record($file, '2026-11-28', $token, $now), 'No maintenance: fail closed');
    cdv_maintain($file, $now);
    dv_check(cdv_record($file, '2026-11-28', $token, $now), 'First browser counted');
    dv_check(!cdv_record($file, '2026-11-28', $token, $now), 'Reload deduplicated');
    dv_check(!cdv_record($file, '2026-11-28', $token, $now), 'Route change deduplicated');
    dv_check(cdv_record($file, '2026-11-28', str_repeat('b', 64), $now), 'Second browser counted');
    dv_check(cdv_read($file)['days']['2026-11-28'] === 2, 'Exactly two daily visitors');
    dv_check(!str_contains(file_get_contents($file), $token), 'No raw browser identifier stored');
    dv_reject(fn() => cdv_record($file, '2026-11-27', $token, $now), 'Stale replay rejected');
    dv_reject(fn() => cdv_record($file, '2026-11-29', $token, $now), 'Future day rejected');
    dv_reject(fn() => cdv_record($file, '2026-11-28', '../bad', $now), 'Invalid token rejected');
    cdv_maintain($file, $now->modify('+1 day'));
    dv_check(cdv_record($file, '2026-11-29', str_repeat('c',64), $now->modify('+1 day')), 'Next day counted');
    dv_check(count(cdv_read($file)['seen']) === 1, 'Old deduplication keys purged at rollover');
    dv_check(cdv_metric(cdv_read($file), ['2026-11-28','2026-11-29'])['value'] === 3, 'Period sum is visitor days');
    dv_check(cdv_metric(cdv_read($file), ['2026-11-26','2026-11-28'])['status'] === 'partial', 'Unknown days remain partial');
    dv_check(cdv_day(new DateTimeImmutable('2026-03-29T22:30:00Z')) === '2026-03-30', 'Berlin DST spring midnight');
    dv_check(cdv_day(new DateTimeImmutable('2026-10-25T23:30:00Z')) === '2026-10-26', 'Berlin DST autumn midnight');
    foreach (['2026-03-29T00:00:00+01:00' => 23, '2026-10-25T00:00:00+02:00' => 25] as $date => $hours) {
        $time = new DateTimeImmutable($date);
        dv_check(cdv_expires_at($time) - $time->getTimestamp() === $hours * 3600, 'Cookie expiry follows DST, not a fixed 24 hours');
    }
    $allow = CDV_CONSENT . '.allow.' . $now->getTimestamp();
    dv_check(cdv_has_consent($allow, $now), 'Versioned consent accepted');
    foreach ([CDV_CONSENT, str_replace('.allow.', '.deny.', $allow), 'daily-visitors-v1', CDV_CONSENT.'.allow.'.($now->getTimestamp()+1)] as $choice) {
        dv_check(!cdv_has_consent($choice, $now), 'Denied, stale-version and future consent rejected');
    }
    dv_check(!cdv_has_consent($allow, $now->modify('+180 days')), 'Consent expires after 180 days');
    $retentionFile = $root . '/retention.json';
    cdv_maintain($retentionFile, $now);
    cdv_record($retentionFile, cdv_day($now), $token, $now);
    $counts = cdv_read($retentionFile)['days'];
    dv_reject(fn() => cdv_record($retentionFile, cdv_day($now), str_repeat('2',64), $now->modify('+31 minutes')), 'Overdue cleanup blocks new identifiers');
    cdv_maintain($retentionFile, $now->modify('tomorrow')->setTime(0,15));
    dv_check(cdv_read($retentionFile)['seen'] === [] && cdv_read($retentionFile)['activeDay'] === null, 'Independent cleanup purges identifiers without a new visitor');
    dv_check(cdv_read($retentionFile)['days'] === $counts, 'Cleanup preserves every aggregate count');
    cdv_maintain($retentionFile, $now->modify('tomorrow')->setTime(0,15));
    dv_check(cdv_read($retentionFile)['days'] === $counts, 'Repeated cleanup idempotent');
    dv_reject(fn() => cdv_private_path($root . '/web/../web/leak.json', $root . '/web'), 'Traversal into public root rejected');
    $lock = fopen($file . '.lock', 'c'); flock($lock, LOCK_EX);
    dv_reject(fn() => cdv_record($file, '2026-11-29', str_repeat('d',64), $now->modify('+1 day')), 'Busy lock fails without hanging');
    flock($lock, LOCK_UN); fclose($lock);
    $before = file_get_contents($file);
    file_put_contents($file, '{broken');
    dv_reject(fn() => cdv_record($file, '2026-11-29', $token, $now->modify('+1 day')), 'Corrupt counters never reset');
    dv_check(file_get_contents($file) === '{broken', 'Corruption preserved for recovery');
    file_put_contents($file, $before);

    // Ten concurrent clients sending the same synthetic identifier.
    $concurrent = $root . '/parallel.json';
    cdv_maintain($concurrent);
    $children = [];
    $library = realpath(dirname(__DIR__, 2) . '/hosting/_internal/daily-visitors.php');
    for ($i = 0; $i < 10; ++$i) {
        $code = 'require $argv[1]; for($i=0;$i<100;$i++){try{cdv_record($argv[2],cdv_day(),str_repeat("e",64));exit(0);}catch(RuntimeException){usleep(10000);}}exit(1);';
        $child = proc_open([PHP_BINARY,'-r',$code,$library,$concurrent], [['pipe','r'],['pipe','w'],['pipe','w']], $pipes);
        fclose($pipes[0]); $children[] = [$child,$pipes];
    }
    foreach ($children as [$child,$pipes]) {
        $out = stream_get_contents($pipes[1]); fclose($pipes[1]);
        $err = stream_get_contents($pipes[2]); fclose($pipes[2]);
        dv_check(proc_close($child) === 0 && $out === '' && $err === '', 'Parallel request safe');
    }
    dv_check(cdv_read($concurrent)['days'][cdv_day()] === 1, 'Parallel first requests count once');

    $configPath = $root . '/config.php';
    $httpFile = $root . '/http/daily-visitors.json'; mkdir(dirname($httpFile), 0700);
    $config = ['enabled'=>true,'privacyApproved'=>true,'consentVersion'=>CDV_CONSENT,'origin'=>'https://example.invalid','dataFile'=>$httpFile];
    $writeConfig = function(array $value) use($configPath) { file_put_contents($configPath, '<?php return ' . var_export($value,true) . ';'); chmod($configPath,0600); };
    $writeConfig($config);
    $handler = realpath(dirname(__DIR__,2) . '/hosting/daily-visitor.php');
    $request = function(string $method, string $consent, string $origin, array $post = []) use($root,$handler,$configPath): array {
        $code = '$r=json_decode(stream_get_contents(STDIN),true);putenv("CARMAJA_DAILY_VISITORS_CONFIG=".$r["config"]);putenv("CARMAJA_PAGE_ROOT=".$r["root"]);$_SERVER=["REQUEST_METHOD"=>$r["method"],"HTTP_ORIGIN"=>$r["origin"],"CONTENT_TYPE"=>"application/x-www-form-urlencoded","CONTENT_LENGTH"=>strlen(http_build_query($r["post"]))];$_COOKIE=["carmaja_analytics_consent"=>$r["consent"]];$_POST=$r["post"];register_shutdown_function(function(){echo "\nSTATUS=".(http_response_code()?:200);});require $r["handler"];';
        [$out] = dv_child($code,['config'=>$configPath,'root'=>$root.'/web','method'=>$method,'origin'=>$origin,'consent'=>$consent,'post'=>$post,'handler'=>$handler]);
        [$body,$status] = explode("\nSTATUS=",$out);
        return [$body,(int)$status];
    };
    $consent = CDV_CONSENT . '.allow.' . time();
    $post = ['day'=>cdv_day(),'token'=>str_repeat('f',64),'path'=>'/'];
    dv_check($request('POST','','https://example.invalid',$post)[1] === 204 && !file_exists($httpFile), 'No consent: no identifier storage');
    $writeConfig([...$config,'privacyApproved'=>false]);
    dv_check($request('POST',$consent,'https://example.invalid',$post)[1] === 204 && !file_exists($httpFile), 'Privacy gate false: no storage');
    $writeConfig([...$config,'enabled'=>false]);
    dv_check($request('POST',$consent,'https://example.invalid',$post)[1] === 204 && !file_exists($httpFile), 'Activation false: no storage');
    $writeConfig($config);
    dv_check($request('GET',$consent,'')[1] === 503 && !file_exists($httpFile), 'Missing maintenance blocks endpoint before cookies');
    cdv_maintain($httpFile);
    $unchanged = file_get_contents($httpFile);
    [$body,$status] = $request('GET',$consent,'');
    dv_check($status === 200 && json_decode($body,true)['day'] === cdv_day() && file_get_contents($httpFile) === $unchanged, 'GET gives day, never counts');
    dv_check($request('POST',$consent,'https://wrong.invalid',$post)[1] === 403, 'Foreign origin rejected');
    dv_check($request('POST',$consent,'https://example.invalid',[...$post,'path'=>'/missing/'])[1] === 400, 'Non-public route rejected');
    dv_check($request('POST',$consent,'https://example.invalid',[...$post,'email'=>'not-accepted'])[1] === 400, 'Extra data rejected');
    dv_check($request('POST',$consent,'https://example.invalid',$post)[1] === 204, 'Endpoint accepts daily browser');
    dv_check($request('POST',$consent,'https://example.invalid',$post)[1] === 204 && cdv_read($httpFile)['days'][cdv_day()] === 1, 'HTTP replay idempotent');
    dv_check($request('POST',$consent,'https://example.invalid',[...$post,'day'=>'2000-01-01'])[1] === 409, 'HTTP stale day rejected');
    dv_check($request('DELETE',$consent,'')[1] === 405, 'Method restricted');
    foreach ([CDV_CONSENT, CDV_CONSENT.'.deny.'.time(), CDV_CONSENT.'.allow.'.(time()-CDV_CONSENT_SECONDS)] as $choice) {
        $unchanged = file_get_contents($httpFile);
        dv_check($request('POST',$choice,'https://example.invalid',$post)[1] === 204 && file_get_contents($httpFile) === $unchanged, 'HTTP invalid consent cannot mutate data');
    }
    // The private CLI runs even with both activation gates disabled.
    mkdir($root . '/web/_internal',0700);
    copy($library,$root . '/web/_internal/daily-visitors.php');
    $writeConfig([...$config,'enabled'=>false,'privacyApproved'=>false]);
    $cli = realpath(dirname(__DIR__,2) . '/scripts/daily-visitors-maintenance.php');
    $cliCode = '$r=json_decode(stream_get_contents(STDIN),true);putenv("CARMAJA_DAILY_VISITORS_WEBROOT=".$r["webroot"]);putenv("CARMAJA_DAILY_VISITORS_CONFIG=".$r["config"]);$argv=[$r["cli"],$r["action"]];require $r["cli"];';
    foreach (['prune','status','export-aggregates'] as $action) {
        [$output] = dv_child($cliCode,['webroot'=>$root.'/web','config'=>$configPath,'cli'=>$cli,'action'=>$action]);
        if ($action === 'export-aggregates') {
            $export = json_decode($output,true);
            dv_check(array_keys($export) === ['version','kind','days'] && $export['days'] === cdv_read($httpFile)['days'], 'Backup export includes counts only');
            dv_check(!str_contains($output,'seen') && !str_contains($output,hash('sha256',$post['day'].':'.$post['token'])), 'No identifier in backup projection');
        } else { dv_check($output === "Daily visitor retention: OK\n", 'Private maintenance CLI succeeds without data output'); }
    }
    $writeConfig($config);
    // Real HTTP on loopback: response headers, consent gate and successful retry.
    $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
    if ($socket === false) throw new RuntimeException('Local HTTP port unavailable');
    $address = stream_socket_get_name($socket,false); fclose($socket);
    $router = $root . '/router.php';
    file_put_contents($router, '<?php putenv(' . var_export('CARMAJA_DAILY_VISITORS_CONFIG='.$configPath,true)
        . '); putenv(' . var_export('CARMAJA_PAGE_ROOT='.$root.'/web',true) . '); require ' . var_export($handler,true) . ';');
    $server = proc_open([PHP_BINARY,'-S',$address,'-t',$root.'/web',$router],
        [['pipe','r'],['file',$root.'/server.log','a'],['file',$root.'/server.log','a']],$serverPipes);
    if (!is_resource($server)) throw new RuntimeException('Local HTTP server unavailable');
    fclose($serverPipes[0]);
    try {
        for ($attempt=0;$attempt<50;++$attempt) {
            $ready = @stream_socket_client('tcp://'.$address,$errno,$error,0.1);
            if ($ready !== false) { fclose($ready); break; }
            usleep(100000);
        }
        $http = function(string $method, bool $consent, ?array $payload=null) use($address): array {
            $headers = ['Origin: https://example.invalid','Content-Type: application/x-www-form-urlencoded'];
            if($consent) $headers[]='Cookie: carmaja_analytics_consent='.CDV_CONSENT.'.allow.'.time();
            $context=stream_context_create(['http'=>['method'=>$method,'header'=>implode("\r\n",$headers),
                'content'=>$payload===null?'':http_build_query($payload),'ignore_errors'=>true,'timeout'=>5]]);
            $body=file_get_contents('http://'.$address.'/daily-visitor.php',false,$context);
            return [$body,$http_response_header];
        };
        [$body,$headers]=$http('GET',true);
        dv_check(str_contains($headers[0],'200') && json_decode($body,true)['day']===cdv_day(),'Real HTTP configuration');
        dv_check(in_array('Cache-Control: no-store, max-age=0',$headers,true),'HTTP no-store');
        dv_check(count(array_filter($headers,fn($h)=>str_starts_with(strtolower($h),'set-cookie:')))===0,'Server does not silently grant consent');
        [$body,$headers]=$http('POST',false,$post);
        dv_check(str_contains($headers[0],'204') && $body==='','HTTP without consent does not expose state');
        [$body,$headers]=$http('POST',true,$post);
        dv_check(str_contains($headers[0],'204') && cdv_read($httpFile)['days'][cdv_day()]===1,'Real HTTP retry remains once');
        dv_check(!str_contains(file_get_contents($root.'/server.log'),$post['token']),'HTTP log contains no visitor token');
    } finally {
        proc_terminate($server); proc_close($server);
    }
    // Render the actual dashboard with synthetic data, never a live Commerce source.
    $dashboard = realpath(dirname(__DIR__,2) . '/hosting/statistik/dashboard.php');
    $renderCode = '$r=json_decode(stream_get_contents(STDIN),true);putenv("CARMAJA_PAGE_ROOT=".$r["root"]);putenv("CARMAJA_DAILY_VISITORS_FILE=".$r["visitors"]);putenv("CARMAJA_STATS_FILE=".$r["legacy"]);foreach(["COMMERCE","MARKETING","GOOGLE","SOURCE_META","CATALOG"] as $name){putenv("CARMAJA_STATS_".$name."_FILE");}define("CARMAJA_STATISTICS_CONTEXT",true);require dirname($r["dashboard"])."/../_internal/tracking.php";require dirname($r["dashboard"])."/report.php";$_GET=["period"=>"custom","from"=>$r["day"],"to"=>$r["day"]];require $r["dashboard"];';
    [$html] = dv_child($renderCode,['root'=>$root.'/web','visitors'=>$httpFile,'legacy'=>$legacy,'dashboard'=>$dashboard,'day'=>cdv_day()]);
    dv_check(str_contains($html,'<span>Tagesbesucher</span>') && preg_match('/<span>Tagesbesucher<\/span>\s*<strong>1<\/strong>/', $html) === 1, 'Main dashboard uses daily visitors, not views');
    dv_check(str_contains($html,'Bisherige Seitenaufrufe') && str_contains($html,'keine Besucher'), 'Legacy views remain separately labelled');
    dv_check(!str_contains($html,str_repeat('f',64)) && !str_contains($html,hash('sha256',cdv_day().':'.str_repeat('f',64))), 'Dashboard never renders visitor identifiers');
    [$html] = dv_child($renderCode,['root'=>$root.'/web','visitors'=>$root.'/absent.json','legacy'=>$legacy,'dashboard'=>$dashboard,'day'=>'2026-01-01']);
    dv_check(str_contains($html,'Tagesbesucher: noch nicht erfasst.') && str_contains($html,'12 Seitenaufrufe'), 'Unknown visitor history is not backfilled from legacy views');
    dv_check(file_get_contents($legacy) === $legacyData, 'Legacy statistics byte-identical');
    dv_check(glob($root . '/*.tmp-*') === [], 'Atomic temporary files removed');
    echo "Daily visitors: $checks checks passed.\n";
} finally {
    $cleanup = function(string $path) use (&$cleanup): void {
        foreach (scandir($path) as $name) {
            if ($name === '.' || $name === '..') continue;
            $entry = $path . '/' . $name;
            if (is_dir($entry) && !is_link($entry)) $cleanup($entry); else unlink($entry);
        }
        rmdir($path);
    };
    $cleanup($root);
}
