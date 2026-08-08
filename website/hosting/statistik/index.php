<?php

declare(strict_types=1);

header('Cache-Control: no-store, max-age=0');
header('X-Robots-Tag: noindex, nofollow, noimageindex', true);

$authenticatedUser = $_SERVER['REMOTE_USER']
    ?? $_SERVER['PHP_AUTH_USER']
    ?? '';

if (!is_string($authenticatedUser) || $authenticatedUser === '') {
    http_response_code(503);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Der Statistikbereich ist noch nicht freigeschaltet.';
    exit;
}

require_once dirname(__DIR__) . '/_internal/tracking.php';

date_default_timezone_set('Europe/Berlin');

try {
    $stats = carmaja_read_stats();
} catch (Throwable $error) {
    $stats = carmaja_empty_stats();
}

$today = new DateTimeImmutable('today');
$todayKey = $today->format('Y-m-d');
$lastThirtyDaysStart = $today->modify('-29 days')->format('Y-m-d');
$todayTotal = carmaja_bucket_total($stats['days'][$todayKey] ?? []);
$lastThirtyDaysTotal = 0;
$overallTotal = 0;
$instagramTotal = 0;
$positionTotals = array_fill_keys(CARMAJA_POSITIONS, 0);

foreach ([$stats['days'], $stats['months']] as $periods) {
    foreach ($periods as $bucket) {
        if (!is_array($bucket)) {
            continue;
        }

        $overallTotal += carmaja_bucket_total($bucket);
        $instagramTotal += carmaja_bucket_total($bucket, 'instagram');

        foreach (CARMAJA_POSITIONS as $position) {
            $positionTotals[$position] += carmaja_bucket_total(
                $bucket,
                null,
                $position
            );
        }

    }
}

foreach ($stats['days'] as $date => $bucket) {
    if (!is_string($date)
        || $date < $lastThirtyDaysStart
        || $date > $todayKey
        || !is_array($bucket)) {
        continue;
    }

    $lastThirtyDaysTotal += carmaja_bucket_total($bucket);
}

$dailyRows = $stats['days'];
krsort($dailyRows);

function carmaja_format_number(int $number): string
{
    return number_format($number, 0, ',', '.');
}

function carmaja_escape(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

?>
<!doctype html>
<html lang="de">
  <head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex,nofollow,noimageindex">
    <title>Statistik | Carmaja-Perlen</title>
    <style>
      :root {
        color-scheme: light;
        font-family: system-ui, sans-serif;
        color: #282b27;
        background: #f3f0e9;
      }

      * {
        box-sizing: border-box;
      }

      body {
        margin: 0;
        line-height: 1.5;
      }

      main {
        width: min(100% - 2rem, 72rem);
        margin-inline: auto;
        padding-block: 3rem;
      }

      h1,
      h2 {
        margin-top: 0;
        font-family: Georgia, serif;
        font-weight: 500;
      }

      .summary {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(10rem, 1fr));
        gap: 1px;
        margin-block: 2rem 3rem;
        border: 1px solid #d5d5cc;
        background: #d5d5cc;
      }

      .metric {
        padding: 1.1rem;
        background: #fbfaf6;
      }

      .metric span {
        display: block;
        color: #60675f;
        font-size: 0.8rem;
      }

      .metric strong {
        display: block;
        margin-top: 0.25rem;
        color: #405545;
        font-size: 1.55rem;
      }

      .positions {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(9rem, 1fr));
        gap: 1rem;
        margin-bottom: 3rem;
      }

      .position {
        padding-bottom: 0.75rem;
        border-bottom: 2px solid #aeb8a7;
      }

      .table-wrap {
        overflow-x: auto;
      }

      table {
        width: 100%;
        border-collapse: collapse;
        background: #fbfaf6;
      }

      th,
      td {
        padding: 0.7rem 0.8rem;
        border-bottom: 1px solid #d5d5cc;
        text-align: right;
        white-space: nowrap;
      }

      th:first-child,
      td:first-child {
        text-align: left;
      }

      th {
        color: #405545;
        font-size: 0.78rem;
      }

      .empty {
        text-align: left;
      }
    </style>
  </head>
  <body>
    <main>
      <h1>Klickstatistik</h1>

      <section aria-labelledby="summary-heading">
        <h2 id="summary-heading">Übersicht</h2>
        <div class="summary">
          <div class="metric">
            <span>Klicks heute</span>
            <strong><?= carmaja_format_number($todayTotal) ?></strong>
          </div>
          <div class="metric">
            <span>Letzte 30 Tage</span>
            <strong><?= carmaja_format_number($lastThirtyDaysTotal) ?></strong>
          </div>
          <div class="metric">
            <span>Klicks insgesamt</span>
            <strong><?= carmaja_format_number($overallTotal) ?></strong>
          </div>
          <div class="metric">
            <span>Instagram-Klicks</span>
            <strong><?= carmaja_format_number($instagramTotal) ?></strong>
          </div>
        </div>
      </section>

      <section aria-labelledby="positions-heading">
        <h2 id="positions-heading">Positionen</h2>
        <div class="positions">
          <?php foreach (CARMAJA_POSITIONS as $position): ?>
            <div class="position">
              <strong><?= carmaja_escape($position) ?></strong>
              <div><?= carmaja_format_number($positionTotals[$position]) ?></div>
            </div>
          <?php endforeach; ?>
        </div>
      </section>

      <section aria-labelledby="daily-heading">
        <h2 id="daily-heading">Tageswerte</h2>
        <div class="table-wrap">
          <table>
            <thead>
              <tr>
                <th>Datum</th>
                <th>Instagram</th>
                <th>Hero</th>
                <th>Galerie</th>
                <th>Kontakt</th>
                <th>Footer</th>
                <th>Gesamt</th>
              </tr>
            </thead>
            <tbody>
              <?php if ($dailyRows === []): ?>
                <tr>
                  <td class="empty" colspan="7">Noch keine Klicks erfasst.</td>
                </tr>
              <?php else: ?>
                <?php foreach ($dailyRows as $date => $bucket): ?>
                  <?php if (is_array($bucket)): ?>
                    <tr>
                      <td><?= carmaja_escape((string) $date) ?></td>
                      <td><?= carmaja_format_number(
                          carmaja_bucket_total($bucket, 'instagram')
                      ) ?></td>
                      <td><?= carmaja_format_number(
                          carmaja_bucket_total($bucket, null, 'hero')
                      ) ?></td>
                      <td><?= carmaja_format_number(
                          carmaja_bucket_total($bucket, null, 'gallery')
                      ) ?></td>
                      <td><?= carmaja_format_number(
                          carmaja_bucket_total($bucket, null, 'contact')
                      ) ?></td>
                      <td><?= carmaja_format_number(
                          carmaja_bucket_total($bucket, null, 'footer')
                      ) ?></td>
                      <td><?= carmaja_format_number(
                          carmaja_bucket_total($bucket)
                      ) ?></td>
                    </tr>
                  <?php endif; ?>
                <?php endforeach; ?>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </section>
    </main>
  </body>
</html>
