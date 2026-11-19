<?php

declare(strict_types=1);
define('CARMAJA_STATISTICS_CONTEXT', true);
require dirname(__DIR__, 2) . '/hosting/statistik/report.php';
require dirname(__DIR__, 2) . '/hosting/_internal/tracking.php';
require dirname(__DIR__, 2) . '/scripts/export-statistics.php';
require dirname(__DIR__, 2) . '/hosting/statistik/live-commerce.php';

$assertions = 0;
function check(bool $condition, string $message): void {
    global $assertions;
    if (!$condition) { throw new RuntimeException($message); }
    ++$assertions;
}
function rejects(callable $fn, string $message): void {
    try { $fn(); } catch (Throwable) { check(true, $message); return; }
    throw new RuntimeException($message);
}
$now = new DateTimeImmutable('2026-11-19T12:00:00+01:00');
$period = cs_period([], $now);
check($period['start']->format('Y-m-d') === '2026-10-23', '28 Tage einschließlich heute');
check($period['previous']->format('Y-m-d') === '2026-09-25', 'Unmittelbarer Vergleich');
check($period['end']->format('Y-m-d') === '2026-11-20', 'Endgrenze ist morgen in Berlin');
check(count(cs_days($period['start'],$period['end'])) === 28, 'Kalendertage statt Sekunden');
check($period['start']->format('P') === '+02:00' && $period['end']->format('P') === '+01:00', 'Winterzeitgrenze');
$spring = cs_period(['period'=>'custom','from'=>'2026-03-28','to'=>'2026-03-30'],$now);
check(count(cs_days($spring['start'],$spring['end'])) === 3, 'Sommerzeitgrenze');
check(($spring['end']->getTimestamp()-$spring['start']->getTimestamp()) === 71*3600, '23-Stunden-Tag');
check(cs_period(['period'=>'7'],new DateTimeImmutable('2026-03-01T00:30:00Z'))['start']->format('Y-m-d') === '2026-02-23', 'Monatsgrenze');
$todayOnly = cs_period(['period' => 'custom', 'from' => '2026-11-19', 'to' => '2026-11-19'], $now);
check(cs_days($todayOnly['start'], $todayOnly['end']) === ['2026-11-19'], 'Heute ist allein auswählbar');
foreach ([['period'=>'custom','from'=>'2026-02-30','to'=>'2026-03-03'],['period'=>'custom','from'=>'2026-11-19','to'=>'2026-11-20'],['period'=>[]],['period'=>'custom','from'=>'2026-11-02','to'=>'2026-11-01']] as $query) {
    rejects(fn()=>cs_period($query,$now),'Ungültige Zeiträume ablehnen');
}
check(cs_views(['days'=>[]],['2026-11-01'])['value'] === null, 'Fehlender Tag ist keine Null');
$legacy=['days'=>['2026-11-01'=>['pageviews'=>['/'=>['views'=>3,'sources'=>['google'=>2]],'/armbaender/fl/'=>['views'=>4]]]]];
check(cs_views($legacy,['2026-11-01']) === ['value'=>7,'status'=>'partial'],'Seitenaufrufe nicht als Besuche umdeuten');
check(cs_views($legacy,['2026-11-01'],'instagram')['value'] === 0,'Bekannter Tageszähler ohne Instagram-Ereignis');
check(cs_views($legacy,['2026-11-01'],'views','/armbaender/fl/')['value'] === 4,'Direkter Produkteinstieg');
$productCounters = ['days' => ['2026-11-01' => ['pageviews' => [
    '/armbaender/cp-2026-0008-fluorit/' => ['views' => 4],
    '/armbaender/' => ['views' => 8],
    '/warenkorb/' => ['views' => 1],
]]]];
check(cs_all_product_views($productCounters, ['2026-11-01'])['value'] === 4, 'Nur Armband-Detailseiten zählen als Produktaufrufe');
check(cs_all_product_views($legacy, ['2026-11-01'])['value'] === 0 && cs_all_product_views($legacy, ['2026-11-02'])['value'] === null, 'Produktaufrufe unterscheiden beobachtete Null von fehlenden Tagen');
$operationalViews = [
    '/admin/' => ['views' => 3, 'sources' => ['direct-unknown' => 2]],
    '/artikelverwaltung/' => ['views' => 2, 'sources' => ['direct-unknown' => 1]],
    '/armbaender/cp-2026-0022-pruefkollektion/' => ['views' => 4, 'sources' => ['google' => 1]],
];
$publicViews = [
    '/admin-hilfe/' => ['views' => 1, 'sources' => ['direct-unknown' => 1]],
    '/armbaender/cp-2026-0023-wunscharmband/' => ['views' => 6, 'sources' => ['google' => 2]],
];
$unfiltered = [
    'days' => [
        '2026-11-01' => ['pageviews' => $operationalViews + $publicViews],
        '2026-11-02' => ['pageviews' => $operationalViews],
    ],
    'months' => ['2026-10' => ['pageviews' => $operationalViews + $publicViews]],
];
$filtered = cs_public_view_stats($unfiltered);
check($filtered['days']['2026-11-01']['pageviews'] === $publicViews, 'Betriebsseiten anhand ihrer Pfade ausfiltern');
check($filtered['months']['2026-10']['pageviews'] === $publicViews, 'Monatswerte aus denselben Pfaden filtern');
check(cs_views($filtered, ['2026-11-01'])['value'] === 7 && cs_all_product_views($filtered, ['2026-11-01'])['value'] === 6, 'Gesamtzahlen und Produktaufrufe verwenden dieselben bereinigten Daten');
check(cs_views($filtered, ['2026-11-01'], 'google')['value'] === 2 && cs_views($filtered, ['2026-11-01'], 'direct-unknown')['value'] === 1, 'Herkunft ausgeschlossener Aufrufe abziehen');
check(cs_views($filtered, ['2026-11-02'])['value'] === 0 && cs_views($filtered, ['2026-11-03'])['value'] === null, 'Nur betriebliche Aufrufe ergeben eine beobachtete Null, fehlende Tage bleiben offen');
check($unfiltered['days']['2026-11-01']['pageviews'] === $operationalViews + $publicViews, 'Rohdaten bleiben erhalten');
$renamed=['days'=>['2026-11-01'=>['pageviews'=>['/armbaender/cp-2026-0008-flourit/'=>['views'=>3],'/armbaender/cp-2026-0008-fluorit/'=>['views'=>4],'/armbaender/cp-2026-0012-karneol/'=>['views'=>9]]]]];
check(cs_product_views($renamed,['2026-11-01'],['path'=>'/armbaender/cp-2026-0008-fluorit/','sku'=>'CP-2026-0008'])['value']===7,'Geänderte Produktpfade anhand stabiler SKU zusammenfassen, ohne doppelte Route');
check(cs_product_views($renamed,['2026-11-01'],['path'=>null,'sku'=>'CP-2026-0012'])['value']===9,'Historische Produktpfade aus realen Zählern');
check(cs_product_views($renamed,['2026-11-01'],['path'=>null,'sku'=>''])['value']===null,'Ohne Kennung oder Pfad keine Zuordnung erfinden');
check(!str_contains(cs_comparison(['value'=>3,'status'=>'ok'],['value'=>0,'status'=>'ok']),'%'),'Keine Prozente bei null');
check(str_contains(cs_comparison(['value'=>3,'status'=>'partial'],['value'=>1,'status'=>'ok']),'nicht berechenbar'),'Lücken nicht vergleichen');

$options=['environment'=>'test','dataSince'=>'2026-09-01','excludedCheckoutIds'=>['own']];
$catalog=[['product_id'=>'p1','name'=>'Fluorit','public_snapshot'=>'{"sku":"CP-2026-0015","slug":"cp-2026-0015-fluorit"}',
    'sales_enabled'=>1,'on_hand'=>3,'quantity_confirmed'=>1,'reserved'=>1]];
$paid=['order_id'=>'order1','order_status'=>'confirmed','confirmed_at'=>'2026-11-16 23:30:00',
    'payment_id'=>'pay1','payment_status'=>'succeeded','verification_status'=>'verified','stripe_checkout_session_id'=>'cs_test_1',
    'amount_minor'=>2270,'currency'=>'eur','shipping_snapshot'=>'{"amountMinor":270}','checkout_id'=>'c1'];
$line=['order_id'=>'order1','position_no'=>1,'product_id'=>'p1','quantity'=>2,'price_minor'=>1000,'currency'=>'eur'];
$started=['checkout_id'=>'c1','created_at'=>'2026-11-12 13:00:00','status'=>'succeeded','stripe_checkout_session_id'=>'cs_test_1'];
$open=['checkout_id'=>'c2','created_at'=>'2026-11-13 13:00:00','status'=>'processing','stripe_checkout_session_id'=>'cs_test_2'];
$failed=['checkout_id'=>'c3','created_at'=>'2026-11-14 13:00:00','status'=>'failed','stripe_checkout_session_id'=>'cs_test_3'];
$own=[...$paid,'order_id'=>'ownorder','checkout_id'=>'own'];
$live=[...$paid,'order_id'=>'liveorder','stripe_checkout_session_id'=>'cs_live_1'];
$make=fn($refunds=[], $orders=null, $checkouts=null)=>carmaja_statistics_aggregate($orders??[$paid,$paid,$own,$live],[$line,$line],$refunds,$checkouts??[$started,$started,$open,$failed],$catalog,$options,$now);
$data=$make();
check($data['days']['2026-11-17']['orders']===1,'UTC-Bestätigung auf Berliner Verkaufstag; doppelte Events dedupliziert');
check($data['days']['2026-11-12']['checkouts']===1,'Spätere SEPA-Bestätigung bleibt beim ursprünglichen Checkout');
check($data['days']['2026-11-13']['open']===1,'Offene SEPA-Zahlung kein Kauf');
check($data['days']['2026-11-14']['failed']===1,'Fehlgeschlagene Zahlung getrennt');
check($data['days']['2026-11-17']['grossGoodsMinor']===2000,'Versand nicht im Warenwert');
check($data['days']['2026-11-17']['products']['p1']['units']===2,'Stückzahl statt Bestellzahl');
check($data['days']['2026-11-18']['orders']===0,'Echter Nullwert aus vollständigem Bestand');
$todayOrder = [...$paid, 'confirmed_at' => '2026-11-19 09:30:00'];
$todayCheckout = [...$started, 'created_at' => '2026-11-19 08:30:00'];
$todayData = $make([], [$todayOrder], [$todayCheckout]);
check($todayData['days']['2026-11-19']['orders'] === 1 && $todayData['days']['2026-11-19']['checkouts'] === 1, 'Heutige Bestellungen und Checkouts fließen in den Export ein');
$futureData = $make([], [[...$todayOrder, 'confirmed_at' => '2026-11-19 12:30:00']], [[...$todayCheckout, 'created_at' => '2026-11-19 12:30:00']]);
check($futureData['days']['2026-11-19']['orders'] === 0 && $futureData['days']['2026-11-19']['checkouts'] === 0 && !isset($futureData['days']['2026-11-20']), 'Export endet zum Abrufzeitpunkt, nicht am Ende des laufenden Tages');
check($data===$make(),'Wiederholte Projektion ist idempotent');
check($data['attribution']==='not-recorded','Bestellungen ohne Besuch vollständig, keine erfundene Zuordnung');
$refund=['refund_id'=>'r1','payment_id'=>'pay1','stripe_refund_id'=>'re_1','status'=>'succeeded','amount_minor'=>2270,'currency'=>'eur'];
$full=$make([$refund,$refund]);
check($full['days']['2026-11-17']['netGoodsMinor']===0,'Vollständige Erstattung nicht doppelt');
check($full['days']['2026-11-17']['refundShippingMinor']===270,'Versanderstattung getrennt');
check($full['days']['2026-11-17']['orders']===1,'Bestätigter Kauf bleibt historisch bestätigt');
$partial=$make([[...$refund,'amount_minor'=>500]]);
check($partial['days']['2026-11-17']['netGoodsMinor']===null,'Unklare Teilerstattung nicht zuordnen');
$pending=$make([[...$refund,'status'=>'pending']]);
check($pending['days']['2026-11-17']['netGoodsMinor']===null,'Offene Erstattung kennzeichnen');
$canceled=$make([],[[...$paid,'order_status'=>'canceled']]);
check($canceled['days']['2026-11-17']['canceled']===1 && $canceled['days']['2026-11-17']['netGoodsMinor']===2000,'Storno ist kein Erstattungsnachweis');
rejects(fn()=>$make([],[$paid,[...$paid,'amount_minor'=>3000]]),'Widersprüchliche Doppelbestellung');
rejects(fn()=>$make([[...$refund,'currency'=>'usd']]),'Fremdwährung');
$source=['status'=>'ok','data'=>$data];
check(cs_business($source,['2026-11-17'],'orders')['value']===1,'Gesamtzahl passt zur Detailzahl');
check(cs_business(['status'=>'ok','data'=>$partial],['2026-11-17','2026-11-18'],'netGoodsMinor')['value']===null,'Unklarer Netto-Wert wird nicht als Teilnull gezeigt');
check(cs_business($source,['2026-08-01'],'orders')['value']===null,'Vor Datenbeginn keine Null');
check(cs_business(['status'=>'error','data'=>null],['2026-11-01'],'orders')['status']==='error','Abruffehler sichtbar');
check(!preg_match('/customer|email|address|session_id|payment_id|order_id|cs_test_/i',json_encode($data)),'Privater Auszug enthält keine Personen- oder Transaktionskennungen');

$post=['platform'=>'pinterest','id'=>'123','publishedAt'=>'2026-10-25T10:00:00+01:00','readAt'=>'2026-11-18T10:00:00+01:00',
    'status'=>'published','url'=>'https://www.pinterest.com/pin/123/','productId'=>'p1','metrics'=>['window'=>'lifetime','impressions'=>100,'outboundClicks'=>0]];
$marketing=['data'=>['posts'=>[$post,[...$post,'readAt'=>'2026-11-17T10:00:00+01:00','metrics'=>['window'=>'lifetime','impressions'=>90]],$post,[...$post,'id'=>'draft','status'=>'planned']]]];
$posts=cs_posts($marketing,$period,'lifetime');
check(count($posts)===1 && $posts[0]['metrics']['impressions']===100,'Kumulative Stände ersetzen, nicht addieren; Pläne zählen nicht');
check(cs_posts($marketing,$period,'first14')[0]['metrics']===[],'Älterer Gesamtstand ist kein 14-Tage-Wert');
check(cs_count($posts[0]['metrics']['outboundClicks'])===0 && cs_count($posts[0]['metrics']['saves']??null)===null,'Null und nicht erfasste Postkennzahl');
$post['metrics']=['window'=>'first14','from'=>$post['publishedAt'],'to'=>'2026-11-08T10:00:00+01:00','complete'=>true,'impressions'=>80];
check(cs_posts(['data'=>['posts'=>[$post]]],$period,'first14')[0]['metrics']['impressions']===80,'Belegtes 14-Tage-Fenster');
$lifetime=[...$post,'readAt'=>'2026-11-19T10:00:00+01:00','metrics'=>['window'=>'lifetime','impressions'=>150]];
check(cs_posts(['data'=>['posts'=>[$post,$lifetime]]],$period,'first14')[0]['metrics']['impressions']===80,'14-Tage-Beleg neben späterem Gesamtstand erhalten');
$dstPost=[...$post,'publishedAt'=>'2026-10-24T10:00:00+02:00','metrics'=>['window'=>'first14','from'=>'2026-10-24T08:00:00Z','to'=>'2026-11-07T08:00:00Z','complete'=>true,'impressions'=>5]];
check(cs_posts(['data'=>['posts'=>[$dstPost]]],$period,'first14')[0]['metrics']['impressions']===5,'Gleiches Beitragsalter über Zeitumstellung und gleichwertige Zeitzonen');
check(!cs_social_url('javascript:alert(1)','pinterest') && !cs_social_url('https://pinterest.com.evil.test/','pinterest'),'Links begrenzen');
$g=['status'=>'ok','data'=>['days'=>['2026-11-01'=>['clicks'=>2,'impressions'=>20],'2026-11-02'=>['clicks'=>0,'impressions'=>10]]]];
check(cs_google_totals($g,['2026-11-01','2026-11-02'])['ctr']===100*2/30,'Google-CTR aus Summen');
check(cs_google_totals($g,['2026-11-03'])['clicks']['value']===null,'Google-Lücke bleibt fehlend');
$query=['day'=>'2026-11-01','query'=>'Carmaja','page'=>'/','clicks'=>2,'impressions'=>20];
check(cs_google_queries(['data'=>['queries'=>[$query,$query]]],['2026-11-01'])[0]['clicks']===2,'Wiederholter Google-Import zählt Tageswerte nur einmal');
check(count(cs_insights($source,['2026-11-17']))<=3,'Höchstens drei Hinweise');
check(!cs_origin_verified(['data'=>null]),'Keine Prüfung ohne Herkunftsnachweis behaupten');

// Real SQL projection against a minimal contract database; writes are rejected by SQLite.
check(in_array('sqlite',PDO::getAvailableDrivers(),true),'SQLite-Treiber für Integrationstest erforderlich');
$pdo=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$pdo->exec('CREATE TABLE orders(order_id,status,confirmed_at,payment_id,shipping_snapshot);
CREATE TABLE payments(payment_id,checkout_id,order_id,status,verification_status,stripe_checkout_session_id,amount_minor,currency);
CREATE TABLE order_items(order_id,position_no,product_id,quantity,price_minor,currency);
CREATE TABLE refunds(refund_id,payment_id,stripe_refund_id,status,amount_minor,currency);
CREATE TABLE checkout_sagas(checkout_id,created_at);
CREATE TABLE commerce_products(product_id,name,public_snapshot,sales_enabled);
CREATE TABLE commerce_inventory(product_id,on_hand,quantity_confirmed);
CREATE TABLE reservations(product_id,quantity,blocks_stock);');
$pdo->exec("INSERT INTO orders VALUES('o','confirmed','2026-11-17 00:00:00','p','{\"amountMinor\":270}');
INSERT INTO payments VALUES('p','c','o','succeeded','verified','cs_test_db',1270,'eur');
INSERT INTO order_items VALUES('o',1,'prod',1,1000,'eur');
INSERT INTO checkout_sagas VALUES('c','2026-11-15 00:00:00');
INSERT INTO commerce_products VALUES('prod','Fluorit','{\"sku\":\"CP-2026-0015\",\"slug\":\"fluorit\"}',1);
INSERT INTO commerce_inventory VALUES('prod',3,1);
INSERT INTO reservations VALUES('prod',1,1);");
$before=$pdo->query('SELECT * FROM commerce_inventory')->fetchAll();
$projection=carmaja_statistics_projection($pdo,$options,$now);
check($projection['days']['2026-11-17']['orders']===1,'SQL-Projektion liest echte Tabellenverträge');
check($projection['catalog'][0]['availability']==='available','Verfügbarkeit aus Commerce minus Reservierungen');
check($before===$pdo->query('SELECT * FROM commerce_inventory')->fetchAll(),'Export verändert keinen Bestand');
rejects(fn()=>$pdo->exec('UPDATE commerce_inventory SET on_hand=0'),'Leseschutz verhindert Mutationen');
rejects(fn()=>carmaja_statistics_projection($pdo,[...$options,'environment'=>'live'],$now),'Testdatenbank kann nicht Live-Quelle werden');

$temporary=sys_get_temp_dir().'/carmaja-statistics-'.bin2hex(random_bytes(6));
mkdir($temporary);mkdir($temporary.'/web');
try {
    carmaja_statistics_write($temporary.'/snapshot.json',$temporary.'/web',$projection);
    carmaja_statistics_write($temporary.'/snapshot.json',$temporary.'/web',$projection);
    check(json_decode(file_get_contents($temporary.'/snapshot.json'),true)===$projection,'Atomare Ausgabe verifiziert');
    rejects(fn()=>carmaja_statistics_write($temporary.'/web/leak.json',$temporary.'/web',$projection),'Webroot als Ausgabe ablehnen');
    putenv('CARMAJA_STATS_COMMERCE_FILE='.$temporary.'/snapshot.json');
    check(cs_snapshot('CARMAJA_STATS_COMMERCE_FILE','commerce')['status']==='error','Produktivansicht lehnt Testsnapshots ab');
    $liveProtocol=[...$projection,'environment'=>'live','operatorTestFilterConfirmed'=>true,'generatedAt'=>(new DateTimeImmutable('now',new DateTimeZone('UTC')))->format(DATE_ATOM)];
    file_put_contents($temporary.'/snapshot.json',json_encode($liveProtocol));
    check(cs_snapshot('CARMAJA_STATS_COMMERCE_FILE','commerce')['status']==='ok','Bestätigte Testfilter und aktueller Stand sind auswertbar');
    $cache = $temporary . '/commerce.json';
    $current = cs_refresh_commerce($cache, $temporary . '/web', static fn () => $liveProtocol);
    check($current === ['status' => 'ok', 'data' => $liveProtocol], 'Erster Abruf zeigt den echten aktuellen Stand');
    $updated = $liveProtocol;
    $updated['days']['2026-11-17']['orders'] = 2;
    $newer = cs_refresh_commerce($cache, $temporary . '/web', static fn () => $updated);
    check(cs_business($newer, ['2026-11-17'], 'orders')['value'] === 2, 'Neuladen fragt die Quelle erneut ab');
    $fallback = cs_refresh_commerce($cache, $temporary . '/web', static fn () => throw new RuntimeException());
    check(cs_business($fallback, ['2026-11-17'], 'orders') === ['value' => 2, 'status' => 'error'], 'Abruffehler erhält den letzten Stand');
    check(cs_snapshot_file($cache, 'commerce')['data'] === $updated, 'Fehlgeschlagener Abruf überschreibt private Daten nicht');
    file_put_contents($temporary.'/snapshot.json.status.json',json_encode(['status'=>'error','generatedAt'=>$liveProtocol['generatedAt']]));
    $failedRefresh=cs_snapshot('CARMAJA_STATS_COMMERCE_FILE','commerce');
    check($failedRefresh['status']==='error'&&$failedRefresh['data']!==null,'Fehlgeschlagene Aktualisierung bewahrt lesbaren Altstand');
    check(cs_business($failedRefresh,['2026-11-17'],'orders')===['value'=>1,'status'=>'error'],'Alter Wert bleibt bei Abruffehler gekennzeichnet erhalten');
    unlink($temporary.'/snapshot.json.status.json');
    $liveProtocol['operatorTestFilterConfirmed']=false;
    file_put_contents($temporary.'/snapshot.json',json_encode($liveProtocol));
    check(cs_snapshot('CARMAJA_STATS_COMMERCE_FILE','commerce')['status']==='partial','Ungeklärte Betreiber-Testkäufe machen die Zahlen vorläufig');
    file_put_contents($temporary.'/snapshot.json','{broken');
    check(cs_snapshot('CARMAJA_STATS_COMMERCE_FILE','commerce')['status']==='error','Defekter Auszug kein Nullwert');
    putenv('CARMAJA_STATS_COMMERCE_FILE');
    check(cs_snapshot('CARMAJA_STATS_COMMERCE_FILE','commerce')['status']==='disconnected','Nicht verbundene Quelle');
} finally {
    foreach (['snapshot.json', 'snapshot.json.status.json', 'commerce.json', 'commerce.json.lock'] as $name) {
        if (is_file($temporary . '/' . $name)) { unlink($temporary . '/' . $name); }
    }
    rmdir($temporary . '/web');
    rmdir($temporary);
}
echo "Statistik: $assertions Prüfungen erfolgreich.\n";
