<?php

declare(strict_types=1);
if (!defined('CARMAJA_STATISTICS_CONTEXT')) { http_response_code(404); exit; }
try { $period = cs_period($_GET); }
catch (InvalidArgumentException $error) {
    http_response_code(400);
    echo '<!doctype html><html lang="de"><meta charset="utf-8"><title>Zeitraum prüfen</title><p>'
        . cs_escape($error->getMessage()) . '</p><a href="./">Zur Statistik</a></html>';
    return;
}
$days = cs_days($period['start'], $period['end']);
$today = $period['today']->format('Y-m-d');
$includesToday = in_array($today, $days, true);
$previousDays = cs_days($period['previous'], $period['start']);
$range = $period['start']->format('d.m.Y') . ' bis ' . $period['end']->modify('-1 day')->format('d.m.Y');
$previousRange = $period['previous']->format('d.m.Y') . ' bis ' . $period['start']->modify('-1 day')->format('d.m.Y');
$allowTest = getenv('CARMAJA_STATS_PREVIEW') === 'test' && PHP_SAPI === 'cli-server';
$business = cs_snapshot('CARMAJA_STATS_COMMERCE_FILE', 'commerce', $allowTest);
if (PHP_SAPI !== 'cli' && PHP_SAPI !== 'cli-server' && ($_SERVER['REQUEST_METHOD'] ?? '') === 'GET') {
    require_once __DIR__ . '/live-commerce.php';
    $business = cs_refresh_commerce(CS_LIVE_COMMERCE_CACHE, CS_LIVE_WEBROOT, 'cs_read_live_commerce');
}
$marketing = cs_snapshot('CARMAJA_STATS_MARKETING_FILE', 'marketing', $allowTest);
$google = cs_snapshot('CARMAJA_STATS_GOOGLE_FILE', 'google', $allowTest);
$origin = cs_snapshot('CARMAJA_STATS_SOURCE_META_FILE', 'origin');
$trackingStatus = 'missing';
try {
    if (!is_file(carmaja_stats_file_path())) { $stats = carmaja_empty_stats(); }
    else { $stats = cs_public_view_stats(@carmaja_read_stats()); $trackingStatus = 'partial'; }
} catch (Throwable) { $stats = carmaja_empty_stats(); $stats['_status'] = 'error'; $trackingStatus = 'error'; }
if ($origin['status'] === 'error') { $stats['_status'] = 'error'; $trackingStatus = 'error'; }
$views = cs_views($stats, $days);
$previousViews = cs_views($stats, $previousDays);
$productHistory = ($_GET['productViews'] ?? '') === 'history';
$productBaseline = null;
$productBaselineError = false;
try {
    $productBaseline = cs_product_views_baseline(getenv('CARMAJA_STATS_PRODUCT_VIEWS_BASELINE')
        ?: '/home/www/carmaja-production-stats-maintenance/dashboard-cache/product-views-baseline.json');
} catch (RuntimeException) {
    $productBaselineError = true;
}
[$productStats, $productDays] = $productBaseline !== null && !$productHistory
    ? cs_product_views_since($stats, $days, $productBaseline) : [$stats, $days];
$productViews = cs_all_product_views($productStats, $productDays);
if ($trackingStatus === 'error') { $views['status'] = $previousViews['status'] = 'error'; }
$orders = cs_business($business, $days, 'orders');
$revenue = cs_business($business, $days, 'netGoodsMinor');
$checkouts = cs_business($business, $days, 'checkouts');
$window = ($_GET['postWindow'] ?? '') === 'lifetime' ? 'lifetime' : 'first14';
$posts = cs_posts($marketing, $period, $window);
$catalogStatus = 'ok';
try { $catalog = cs_catalog($business); }
catch (Throwable) { $catalog = []; $catalogStatus = 'error'; }
$productQuery = is_string($_GET['product'] ?? null) ? mb_substr($_GET['product'], 0, 120) : '';
$availabilityFilter = in_array($_GET['availability'] ?? '', ['available', 'unavailable', 'disabled', 'unknown'], true) ? $_GET['availability'] : '';
$sort = in_array($_GET['sort'] ?? '', ['name', 'views', 'units', 'revenue'], true) ? $_GET['sort'] : 'views';
$productRows = [];
foreach ($catalog as $id => $product) {
    $product['id'] = $id;
    $product['views'] = cs_product_views($productStats, $productDays, $product);
    $product['units'] = cs_business($business, $days, 'units', $id);
    $product['revenue'] = cs_business($business, $days, 'netGoodsMinor', $id);
    $product['posts'] = array_values(array_filter($posts, static fn ($post) => ($post['productId'] ?? null) === $id));
    if ($productQuery !== '' && !str_contains(mb_strtolower($product['name'] . ' ' . ($product['sku'] ?? '') . ' ' . ($product['searchTerms'] ?? '')), mb_strtolower($productQuery))) { continue; }
    if ($availabilityFilter !== '' && ($product['availability'] ?? 'unknown') !== $availabilityFilter) { continue; }
    $productRows[] = $product;
}
usort($productRows, static function ($a, $b) use ($sort) {
    if ($sort !== 'name') {
        $comparison = ($b[$sort]['value'] ?? -1) <=> ($a[$sort]['value'] ?? -1);
        if ($comparison !== 0) { return $comparison; }
    }
    return strnatcasecmp($a['name'], $b['name']);
});
$daily = [];
foreach ($days as $day) {
    $daily[] = [
        'day' => $day,
        'views' => cs_views($stats, [$day])['value'],
        'orders' => cs_business($business, [$day], 'orders')['value'],
    ];
}
$sources = ['google' => 'Google-Suche', 'instagram' => 'Instagram', 'other-social' => 'Weitere soziale Netzwerke',
    'other-search' => 'Andere Suchmaschinen', 'other-website' => 'Andere verweisende Websites', 'direct-unknown' => 'Ohne erkennbare Herkunft'];
$availabilityLabels = ['available' => 'Kaufbar', 'unavailable' => 'Aktuell nicht verfügbar', 'disabled' => 'Verkauf deaktiviert'];
$timelineChart = array_slice($daily, -90);
$chartPeak = max(1, ...array_column($timelineChart, 'views'));
$excluded = $business['data']['operatorTestExcludedCount'] ?? (
    ($origin['data']['commerce']['operatorTestFilterConfirmed'] ?? false) ? (int) ($origin['data']['commerce']['excludedCount'] ?? 0) : 0);
$businessDate = $business['data']['generatedAt'] ?? null;
$websiteDate = $origin['data']['website']['sourceUpdatedAt'] ?? null;
if ($websiteDate === null && $trackingStatus === 'partial') {
    $modified = @filemtime(carmaja_stats_file_path());
    $websiteDate = $modified === false ? null : date(DATE_ATOM, $modified);
}
$previewScenario = PHP_SAPI === 'cli-server' && is_string($_GET['scenario'] ?? null) ? $_GET['scenario'] : null;

function cs_value(array $metric, bool $money = false): string { return $metric['value'] === null ? CS_LABELS[$metric['status']] : cs_number($metric['value'], $money); }
function cs_display_value(array $metric, bool $money = false): string
{
    return $metric['value'] === null ? '—' : cs_number($metric['value'], $money);
}
function cs_missing_cell(): string
{
    return '<span aria-hidden="true">—</span><span class="sr-only">Keine Daten</span>';
}
function cs_preserve(array $names): void {
    global $previewScenario;
    if ($previewScenario !== null) {
        echo '<input type="hidden" name="scenario" value="' . cs_escape($previewScenario) . '">';
    }
    foreach ($names as $name) { if (is_string($_GET[$name] ?? null)) { echo '<input type="hidden" name="' . cs_escape($name) . '" value="' . cs_escape($_GET[$name]) . '">'; } }
}

function cs_period_href(int $length, ?string $scenario, bool $productHistory): string
{
    return '?' . http_build_query(array_filter([
        'period' => $length, 'scenario' => $scenario, 'productViews' => $productHistory ? 'history' : null,
    ], static fn ($value) => $value !== null));
}
?>
<!doctype html><html lang="de"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex,nofollow,noimageindex">
<title>Statistik | Carmaja-Perlen</title>
<style>
:root {
  color-scheme: light;
  font-family: system-ui, sans-serif;
  color: #252e28;
  background: #f5f3ed;
  --green: #314837;
  --quiet: #505c52;
  --line: #d9ded4;
  --paper: #fffefa;
}
* { box-sizing: border-box; }
body { margin: 0; line-height: 1.5; }
main { width: min(calc(100% - 2rem), 66rem); margin: auto; padding: 2.4rem 0 4rem; }
h1, h2 { margin: 0 0 .75rem; color: var(--green); font: 500 1.7rem/1.2 Georgia, serif; }
h1 { font-size: clamp(2.25rem, 5vw, 3.4rem); }
h3 { font-size: 1.05rem; }
section { margin-top: 2rem; scroll-margin-top: 1rem; }
h2 { scroll-margin-top: 1rem; }
a { color: var(--green); text-underline-offset: .2em; }
small, .note { display: block; color: var(--quiet); font-size: .875rem; }
.brand { font: 1rem Georgia, serif; margin: 0 0 .65rem; }
.period-bar, .period-bar nav, .section-nav { display: flex; flex-wrap: wrap; align-items: center; gap: .45rem; }
.period-bar { justify-content: space-between; column-gap: 1rem; }
.period-bar p { margin: .35rem 0; font-weight: 600; }
.period-bar a, .section-nav a { min-height: 44px; display: inline-flex; align-items: center; padding: .4rem .75rem; border-radius: .45rem; }
.period-bar a { border: 1px solid var(--line); background: var(--paper); text-decoration: none; }
.period-bar [aria-current] { background: var(--green); color: white; border-color: var(--green); }
.section-nav { margin-top: .75rem; }
.section-nav a { padding-inline: .45rem; }
header details { margin-top: .2rem; }
.summary { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: .85rem; }
.card, .traffic { background: var(--paper); padding: 1.1rem; border: 1px solid var(--line); border-radius: .6rem; }
.card strong { display: block; font-size: clamp(1.65rem, 4vw, 2.5rem); color: var(--green); line-height: 1.2; margin: .25rem 0; }
.notice { padding: .8rem 1rem; background: #e7eddf; border-radius: .4rem; }
form { display: flex; gap: .75rem; align-items: end; flex-wrap: wrap; margin: .3rem 0 .8rem; }
label { display: grid; gap: .25rem; font-size: .9rem; }
input, select, button { font: inherit; min-height: 44px; padding: .45rem .65rem; border: 1px solid #83917f; border-radius: .35rem; max-width: 100%; background: var(--paper); color: inherit; }
button { background: var(--green); color: white; cursor: pointer; }
:focus-visible { outline: 3px solid #785115; outline-offset: 2px; }
.table-wrap { position: relative; overflow: auto; border: 1px solid var(--line); border-radius: .4rem; max-width: 100%; }
.table-wrap.catalog, .table-wrap.source-table { max-height: 25rem; }
table { border-collapse: collapse; background: var(--paper); width: 100%; font-size: .9rem; }
th, td { padding: .65rem .8rem; text-align: right; border-bottom: 1px solid var(--line); vertical-align: top; }
th:first-child, td:first-child, .empty { text-align: left; }
thead { background: #e9eee5; }
.catalog thead th, .source-table thead th { position: sticky; top: 0; background: #e9eee5; }
.source-table table { min-width: 43rem; font-variant-numeric: tabular-nums; }
.source-table th { white-space: nowrap; }
.source-table th[scope="row"] { position: sticky; left: 0; background: var(--paper); }
.source-table thead th:first-child { left: 0; z-index: 1; }
tr:last-child td { border-bottom: 0; }
.stats-extra { display: grid; grid-template-columns: repeat(auto-fit, minmax(9rem, 1fr)); gap: .6rem; }
.stats-extra p { margin: 0; padding: .8rem; background: var(--paper); }
.number-list { margin: .5rem 0; }
.number-list > div { display: grid; grid-template-columns: minmax(0, 1fr) auto; gap: .75rem; padding: .6rem 0; border-bottom: 1px solid var(--line); }
.number-list dd { margin: 0; text-align: right; font-weight: 700; font-variant-numeric: tabular-nums; }
.traffic { margin: 0; }
.traffic figcaption { font-weight: 600; }
.traffic-plot { display: flex; gap: clamp(1px, .4vw, 5px); height: 9rem; border-bottom: 1px solid var(--line); margin-top: .7rem; }
.traffic-day { flex: 1 1 0; display: flex; align-items: end; min-width: 0; }
.traffic-bar, .traffic-gap { width: 100%; border-radius: .15rem .15rem 0 0; background: #56725a; }
.traffic-gap { height: .28rem; background: #adb5ab; }
.traffic-dates { display: flex; justify-content: space-between; color: var(--quiet); font-size: .8rem; }
.traffic-key { margin-top: .45rem; color: var(--quiet); font-size: .8rem; }
.traffic-key .traffic-gap { display: inline-block; width: 1rem; vertical-align: .15em; }
details { margin-top: .65rem; }
summary { cursor: pointer; min-height: 44px; padding-block: .6rem; font-weight: 600; color: var(--green); }
.thumb { width: 64px; height: 64px; object-fit: cover; }
.sr-only { position: absolute; width: 1px; height: 1px; overflow: hidden; clip-path: inset(50%); white-space: nowrap; }
@media (max-width: 600px) {
  main { padding-top: 1.4rem; }
  .summary { grid-template-columns: 1fr; gap: .5rem; }
  .summary .card { display: grid; grid-template-columns: minmax(0, 1fr) auto; align-items: center; gap: .1rem .7rem; padding: .75rem; }
  .summary .card small { grid-column: 1 / -1; }
  .summary .card strong { margin: 0; }
  .traffic-plot { height: 6rem; }
  .catalog table { min-width: 29rem; }
}
</style></head><body><main>
<header>
  <p class="brand">Carmaja Perlen</p>
  <h1>Website-Statistik</h1>
  <?php if ($allowTest): ?><p class="notice">Vorschau mit Testdaten.</p><?php endif; ?>
  <div class="period-bar">
    <p><?= cs_escape($range) ?><?= $includesToday ? ' · Heute (laufend)' : '' ?></p>
    <nav aria-label="Zeitraum">
      <?php foreach ([7, 28, 90] as $length): ?>
      <a href="<?= cs_escape(cs_period_href($length, $previewScenario, $productHistory)) ?>" <?= $period['preset'] === (string) $length ? 'aria-current="true"' : '' ?>><?= $length ?> Tage</a>
      <?php endforeach; ?>
    </nav>
  </div>
  <details <?= $period['preset'] === 'custom' ? 'open' : '' ?>>
    <summary>Eigenen Zeitraum wählen</summary>
    <form method="get">
      <?php cs_preserve(['productViews']); ?>
      <input type="hidden" name="period" value="custom">
      <label>Von<input type="date" name="from" value="<?= $period['start']->format('Y-m-d') ?>" max="<?= $today ?>" required></label>
      <label>Bis<input type="date" name="to" value="<?= $period['end']->modify('-1 day')->format('Y-m-d') ?>" max="<?= $today ?>" required></label>
      <button>Anzeigen</button>
    </form>
  </details>
</header>

<section aria-labelledby="overview">
  <h2 id="overview" class="sr-only">Auf einen Blick</h2>
  <?php if (($localPreviewRefreshFailed ?? false) || $origin['status'] === 'error' || $business['status'] === 'error' || $trackingStatus === 'error'): ?>
  <p class="notice" role="status">Aktualisierung fehlgeschlagen</p>
  <?php endif; ?>
  <div class="summary">
    <?php foreach ([
        ['Seitenaufrufe', $views, false],
        ['Bestätigte Bestellungen', $orders, false],
        ['Warenumsatz', $revenue, true],
    ] as [$label, $metric, $money]): ?>
    <article class="card">
      <span><?= cs_escape($label) ?></span>
      <strong><?= $metric['value'] === null ? cs_missing_cell() : cs_escape(cs_display_value($metric, $money)) ?></strong>
    </article>
    <?php endforeach; ?>
  </div>
  <?php if (($business['data']['operatorTestFilterConfirmed'] ?? true) !== true): ?>
  <p class="note" role="status">Bestellungen und Umsatz vorläufig</p>
  <?php elseif ($business['status'] === 'partial'): ?>
  <p class="note" role="status">Bestellungen und Umsatz: älterer Datenstand</p>
  <?php endif; ?>
  <?php if ($includesToday && !isset($business['data']['days'][$today])): ?>
  <p class="note">Bestellungen und Umsatz für heute: keine Daten</p>
  <?php endif; ?>
</section>

<nav class="section-nav" aria-label="Bereiche">
  <a href="#timeline">Verlauf</a><a href="#funnel">Kaufweg</a><a href="#sources">Herkunft</a><a href="#products">Armbänder</a><a href="#measurement">Datenstand</a>
</nav>

<section aria-labelledby="timeline">
  <h2 id="timeline">Verlauf</h2>
  <?php if ($views['value'] !== null): ?>
  <figure class="traffic">
    <figcaption>Seitenaufrufe pro Tag<?= count($daily) > count($timelineChart) ? ' · letzte 90 Tage' : '' ?></figcaption>
    <div class="traffic-plot" aria-hidden="true">
      <?php foreach ($timelineChart as $row): ?>
      <?php
      $label = cs_date($row['day'])->format('d.m.') . ' · '
          . ($row['views'] === null ? 'Keine Daten' : cs_number($row['views']) . ' Aufrufe');
      ?>
      <div class="traffic-day" title="<?= cs_escape($label) ?>">
        <?php if ($row['views'] === null): ?><span class="traffic-gap"></span>
        <?php else: ?><span class="traffic-bar" style="height:<?= round(100 * $row['views'] / $chartPeak, 2) ?>%"></span><?php endif; ?>
      </div>
      <?php endforeach; ?>
    </div>
    <div class="traffic-dates" aria-hidden="true">
      <span><?= cs_date($timelineChart[0]['day'])->format('d.m.') ?></span>
      <span><?= cs_date($timelineChart[array_key_last($timelineChart)]['day'])->format('d.m.') ?></span>
    </div>
    <?php if (in_array(null, array_column($timelineChart, 'views'), true)): ?><div class="traffic-key"><span class="traffic-gap" aria-hidden="true"></span> Keine Daten</div><?php endif; ?>
  </figure>
  <?php else: ?><p class="note">Keine Aufrufdaten</p><?php endif; ?>
  <details>
    <summary>Tageszahlen ansehen</summary>
    <div class="table-wrap" tabindex="0" role="region" aria-label="Tagesverlauf, seitlich scrollbar">
      <table>
        <thead><tr><th scope="col">Tag</th><th scope="col">Seitenaufrufe</th><th scope="col">Bestellungen</th></tr></thead>
        <tbody>
          <?php foreach ($daily as $row): ?>
          <tr>
            <th scope="row"><time datetime="<?= cs_escape($row['day']) ?>"><?= cs_date($row['day'])->format('d.m.Y') ?></time><?= $row['day'] === $today ? ' · Heute' : '' ?></th>
            <?php foreach (['views', 'orders'] as $field): ?><td><?= $row[$field] === null ? 'Keine Daten' : cs_number($row[$field]) ?></td><?php endforeach; ?>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </details>
</section>

<section aria-labelledby="funnel">
  <h2 id="funnel">Kaufweg</h2>
  <dl class="number-list">
    <?php foreach ([
        [$productBaseline !== null && !$productHistory ? 'Produktseiten seit Marketing-Neustart' : 'Produktseiten aufgerufen', $productViews],
        ['In den Warenkorb gelegt', null],
        ['Zahlungsseite bereitgestellt', $checkouts],
        ['Bestellung bezahlt', $orders],
    ] as [$label, $metric]): ?>
    <div>
      <dt><?= $label ?></dt>
      <dd><?= ($metric['value'] ?? null) === null ? cs_missing_cell() : cs_escape(cs_display_value($metric)) ?></dd>
    </div>
    <?php endforeach; ?>
  </dl>
  <details>
    <summary>Zahlungsstatus ansehen</summary>
    <dl class="number-list">
      <?php foreach (['open' => 'Zahlung offen', 'failed' => 'Fehlgeschlagen oder beendet', 'review' => 'Prüfung nötig', 'canceled' => 'Später storniert'] as $field => $label): ?>
      <div><dt><?= $label ?></dt><dd><?= cs_escape(cs_value(cs_business($business, $days, $field))) ?></dd></div>
      <?php endforeach; ?>
    </dl>
  </details>
</section>

<section aria-labelledby="sources">
  <h2 id="sources">Herkunft pro Tag</h2>
  <div class="table-wrap source-table" tabindex="0" role="region" aria-label="Herkunft beim Einstieg">
    <table>
      <thead>
        <tr><th scope="col">Datum</th><?php foreach ($sources as $label): ?><th scope="col"><?= cs_escape($label) ?></th><?php endforeach; ?></tr>
      </thead>
      <tbody>
        <?php foreach (array_reverse($daily) as $row): ?>
        <tr>
          <th scope="row"><time datetime="<?= cs_escape($row['day']) ?>"><?= cs_date($row['day'])->format('d.m.Y') ?></time><?= $row['day'] === $today ? ' · Heute' : '' ?></th>
          <?php foreach (array_keys($sources) as $key): ?>
          <?php $count = $row['views'] === null ? null : carmaja_bucket_pageview_source_total($stats['days'][$row['day']], $key); ?>
          <td><?= $count === null ? cs_missing_cell() : cs_number($count) ?></td>
          <?php endforeach; ?>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php if (in_array(null, array_column($daily, 'views'), true)): ?><div class="traffic-key">— Keine Daten</div><?php endif; ?>
</section>

<section aria-labelledby="products">
  <h2 id="products">Armbänder</h2>
  <?php if ($productBaselineError): ?>
  <p class="notice" role="status">Ausgangsstand für Armband-Aufrufe konnte nicht geladen werden.</p>
  <?php elseif ($productBaseline !== null): ?>
  <?php
    $productQueryKeys = ['period', 'from', 'to', 'product', 'availability', 'sort', 'postWindow', 'scenario'];
    $productViewQuery = array_filter(array_intersect_key($_GET, array_flip($productQueryKeys)), 'is_string');
    if (!$productHistory) { $productViewQuery['productViews'] = 'history'; }
  ?>
  <p class="note">
    <?= $productHistory ? 'Aufrufe im gewählten Zeitraum' : 'Aufrufe seit ' . cs_escape(cs_time($productBaseline['generatedAt'])) ?>
    · <a href="<?= cs_escape('?' . http_build_query($productViewQuery) . '#products') ?>"><?= $productHistory ? 'Seit Neustart' : 'Mit früheren Aufrufen' ?></a>
  </p>
  <?php endif; ?>
  <details <?= $productQuery !== '' || $availabilityFilter !== '' || isset($_GET['sort']) ? 'open' : '' ?>>
    <summary>Filtern und sortieren</summary>
    <form method="get" action="#products">
      <?php cs_preserve(['period', 'from', 'to', 'postWindow', 'productViews']); ?>
      <label>Armband suchen<input name="product" value="<?= cs_escape($productQuery) ?>" placeholder="Name oder Artikelnummer"></label>
      <label>Verfügbarkeit<select name="availability">
        <?php foreach (['' => 'Alle', 'available' => 'Kaufbar', 'unavailable' => 'Nicht verfügbar', 'disabled' => 'Deaktiviert', 'unknown' => 'Unbekannt'] as $key => $label): ?>
        <option value="<?= $key ?>" <?= $availabilityFilter === $key ? 'selected' : '' ?>><?= $label ?></option>
        <?php endforeach; ?>
      </select></label>
      <label>Sortieren nach<select name="sort">
        <?php foreach (['views' => 'Aufrufen', 'name' => 'Name', 'units' => 'Verkauften Stücken', 'revenue' => 'Warenumsatz'] as $key => $label): ?>
        <option value="<?= $key ?>" <?= $sort === $key ? 'selected' : '' ?>><?= $label ?></option>
        <?php endforeach; ?>
      </select></label>
      <button>Anwenden</button>
    </form>
  </details>
  <div class="table-wrap catalog" tabindex="0" role="region" aria-label="Armbandauswertung, seitlich scrollbar">
    <table>
      <thead><tr><th scope="col">Armband</th><th scope="col">Aufrufe</th><th scope="col">Verkauft</th><th scope="col">Umsatz</th><th scope="col">Heute</th></tr></thead>
      <tbody>
        <?php if ($productRows === []): ?>
        <tr><td colspan="5" class="empty"><?= $catalogStatus === 'error' ? 'Katalog konnte nicht geladen werden.' : ($catalog === [] ? 'Es sind keine Armbanddaten verfügbar.' : 'Kein Armband passt zum Filter.') ?></td></tr>
        <?php endif; ?>
        <?php foreach ($productRows as $product): ?>
        <tr>
          <th scope="row"><?= cs_escape($product['name']) ?><?php if ($product['posts'] !== []): ?><small><?= count($product['posts']) ?> <?= count($product['posts']) === 1 ? 'Beitrag' : 'Beiträge' ?></small><?php endif; ?></th>
          <td><?= cs_escape(cs_display_value($product['views'])) ?></td>
          <td><?= cs_escape(cs_display_value($product['units'])) ?></td>
          <td><?= cs_escape(cs_display_value($product['revenue'], true)) ?></td>
          <td><?= cs_escape($availabilityLabels[$product['availability'] ?? ''] ?? '—') ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</section>

<section aria-labelledby="more">
  <h2 id="more">Weitere Auswertungen</h2>
  <details <?= isset($_GET['postWindow']) ? 'open' : '' ?>>
    <summary id="posts">Beiträge und Bildvarianten</summary>
    <form method="get" action="#posts">
      <?php cs_preserve(['period', 'from', 'to', 'product', 'availability', 'sort', 'productViews']); ?>
      <label>Vergleichsfenster<select name="postWindow">
        <option value="first14" <?= $window === 'first14' ? 'selected' : '' ?>>Erste 14 Tage</option>
        <option value="lifetime" <?= $window === 'lifetime' ? 'selected' : '' ?>>Gesamtstand</option>
      </select></label>
      <button>Anzeigen</button>
    </form>
    <dl class="number-list">
      <?php foreach (['pinterest' => 'Pinterest', 'instagram' => 'Instagram'] as $platform => $label): ?>
      <?php $status = $marketing['data']['platforms'][$platform]['status'] ?? $marketing['status']; ?>
      <div><dt><?= $label ?></dt><dd><?= CS_LABELS[$status] ?? CS_LABELS['partial'] ?></dd></div>
      <?php endforeach; ?>
    </dl>
    <div class="table-wrap" tabindex="0" role="region" aria-label="Beitragsvergleich">
      <table>
        <thead>
          <tr><th scope="col">Beitrag</th><th scope="col">Armband / Bildvariante</th><th scope="col">Veröffentlicht</th><th scope="col">Einblendungen</th><th scope="col">Gespeichert</th><th scope="col">Pin geöffnet</th><th scope="col">Ausgehende Klicks</th><th scope="col">Shopbesuche / Käufe</th></tr>
        </thead>
        <tbody>
          <?php if ($posts === []): ?><tr><td colspan="8" class="empty">Keine Beitragsdaten</td></tr><?php endif; ?>
          <?php foreach ($posts as $post): ?>
          <tr>
            <th scope="row">
              <a href="<?= cs_escape($post['url']) ?>" rel="noreferrer noopener"><?= cs_escape(ucfirst($post['platform']) . ' · ' . $post['id']) ?></a>
              <?php if (is_string($post['thumbnail'] ?? null) && preg_match('#^/images/products/[A-Z0-9-]+/\d{2}\.jpg$#D', $post['thumbnail'])): ?>
              <img class="thumb" loading="lazy" alt="Beitragsvorschau" src="<?= cs_escape($post['thumbnail']) ?>">
              <?php endif; ?>
            </th>
            <td><?= cs_escape($catalog[$post['productId'] ?? '']['name'] ?? '—') ?><?php if (isset($post['variant'])): ?><small><?= cs_escape($post['variant']) ?></small><?php endif; ?></td>
            <td><?= cs_escape(cs_time($post['publishedAt'])) ?></td>
            <?php foreach (['impressions', 'saves', 'pinClicks', 'outboundClicks'] as $field): ?>
            <?php $count = cs_count($post['metrics'][$field] ?? null); ?>
            <td><?= $count === null ? cs_missing_cell() : cs_number($count) ?></td>
            <?php endforeach; ?>
            <td><?= cs_missing_cell() ?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <details>
      <summary>Veröffentlichungsstatus</summary>
      <dl class="number-list">
        <?php foreach (['planned' => 'Geplant', 'attempted' => 'Veröffentlichung versucht', 'failed' => 'Fehlgeschlagen'] as $state => $label): ?>
        <?php $count = cs_count($marketing['data']['workflowCounts'][$state] ?? null); ?>
        <div><dt><?= $label ?></dt><dd><?= $count === null ? cs_missing_cell() : cs_number($count) ?></dd></div>
        <?php endforeach; ?>
      </dl>
    </details>
  </details>
  <details><summary id="google">Google-Suche</summary><?php require __DIR__ . '/google.php'; ?></details>
  <details>
    <summary>Häufige Seiten</summary>
    <div class="table-wrap" tabindex="0" role="region" aria-label="Häufige Seiten im Zeitraum">
      <table>
        <thead><tr><th scope="col">Seite</th><th scope="col">Seitenaufrufe</th></tr></thead>
        <tbody>
          <?php
          $routes = [];
          foreach ($days as $day) {
              foreach ($stats['days'][$day]['pageviews'] ?? [] as $path => $row) {
                  $routes[$path] = ($routes[$path] ?? 0) + (cs_count($row['views'] ?? null) ?? 0);
              }
          }
          arsort($routes);
          ?>
          <?php if ($routes === []): ?><tr><td colspan="2" class="empty">Keine Aufrufdaten</td></tr><?php endif; ?>
          <?php foreach ($routes as $path => $count): ?><tr><th scope="row"><?= cs_escape($path) ?></th><td><?= cs_number($count) ?></td></tr><?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </details>
  <details>
    <summary>Externe Linkklicks</summary>
    <?php
    $linkTotal = null;
    foreach ($days as $day) {
        if (isset($stats['days'][$day]['instagram'])) {
            $linkTotal = ($linkTotal ?? 0) + carmaja_bucket_total($stats['days'][$day], 'instagram');
        }
    }
    ?>
    <dl class="number-list"><div><dt>Instagram</dt><dd><?= $linkTotal === null ? cs_missing_cell() : cs_number($linkTotal) ?></dd></div></dl>
  </details>
  <details>
    <summary>Versand und Erstattungen</summary>
    <dl class="number-list">
      <?php foreach (['grossGoodsMinor' => 'Warenwert vor Erstattungen', 'refundGoodsMinor' => 'Erstatteter Warenwert', 'shippingMinor' => 'Versandkosten', 'refundShippingMinor' => 'Erstatteter Versand', 'unresolvedRefunds' => 'Ungeklärte Erstattungen'] as $field => $label): ?>
      <div><dt><?= $label ?></dt><dd><?= cs_escape(cs_value(cs_business($business, $days, $field), $field !== 'unresolvedRefunds')) ?></dd></div>
      <?php endforeach; ?>
    </dl>
  </details>
  <details id="measurement">
    <summary>Datenstand und Vorperiode</summary>
    <dl class="number-list">
      <div><dt>Website · Stand</dt><dd><?= cs_escape(cs_time($websiteDate)) ?></dd></div>
      <div><dt>Bestellungen · Stand</dt><dd><?= cs_escape(cs_time($businessDate)) ?></dd></div>
      <?php if ($excluded > 0): ?><div><dt>Ausgeschlossene Testkäufe · insgesamt</dt><dd><?= $excluded ?></dd></div><?php endif; ?>
      <div><dt>Vorperiode</dt><dd><?= cs_escape($previousRange) ?></dd></div>
      <div><dt>Seitenaufrufe · Vorperiode</dt><dd><?= cs_escape(cs_value($previousViews)) ?></dd></div>
      <div><dt>Bestellungen · Vorperiode</dt><dd><?= cs_escape(cs_value(cs_business($business, $previousDays, 'orders'))) ?></dd></div>
      <div><dt>Warenumsatz · Vorperiode</dt><dd><?= cs_escape(cs_value(cs_business($business, $previousDays, 'netGoodsMinor'), true)) ?></dd></div>
    </dl>
  </details>
</section>
</main>
</body></html>
