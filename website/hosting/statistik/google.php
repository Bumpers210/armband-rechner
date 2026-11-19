<?php
declare(strict_types=1);
if (!defined('CARMAJA_STATISTICS_CONTEXT')) { http_response_code(404); exit; }
if ($google['data'] === null) {
    echo '<p class="note" role="status">' . cs_escape(CS_LABELS[$google['status']]) . '</p>';
    return;
}
if (($google['data']['timezone'] ?? '') !== 'America/Los_Angeles') {
    echo '<p class="note" role="status">Ungültige Zeitzone</p>';
    return;
}
$googleTotals = cs_google_totals($google, $days);
$googleBefore = cs_google_totals($google, $previousDays);
?>
<dl class="number-list">
  <div><dt>Google-Zeitzone</dt><dd>America/Los_Angeles</dd></div>
  <div><dt>Daten bis</dt><dd><?= cs_escape($google['data']['dataThrough'] ?? '—') ?></dd></div>
  <?php foreach (['clicks' => 'Suchklicks', 'impressions' => 'Einblendungen'] as $key => $label): ?>
  <div><dt><?= $label ?></dt><dd><?= cs_escape(cs_value($googleTotals[$key])) ?></dd></div>
  <div><dt><?= $label ?> · Vorperiode</dt><dd><?= cs_escape(cs_value($googleBefore[$key])) ?></dd></div>
  <?php endforeach; ?>
  <div><dt>Klickrate</dt><dd><?= $googleTotals['ctr'] === null ? cs_missing_cell() : number_format($googleTotals['ctr'], 2, ',', '.') . ' %' ?></dd></div>
</dl>
<div class="table-wrap" tabindex="0" role="region" aria-label="Google-Suchanfragen und Zielseiten"><table><thead><tr><th scope="col">Suchanfrage</th><th scope="col">Gruppe</th><th scope="col">Zielseite</th><th scope="col">Klicks</th><th scope="col">Einblendungen</th><th scope="col">Klickrate</th></tr></thead><tbody>
<?php
$queryRows=cs_google_queries($google,$days);
if($queryRows===[]){echo '<tr><td colspan="6" class="empty">Keine Suchanfragen</td></tr>';}
foreach($queryRows as $row): ?>
<tr><th scope="row"><?= cs_escape($row['query']) ?></th><td><?= str_contains(mb_strtolower($row['query']),'carmaja')?'Markensuche':'Allgemeine Suche' ?></td><td><?= cs_escape($row['page']) ?></td><td><?= cs_number($row['clicks']) ?></td><td><?= cs_number($row['impressions']) ?></td><td><?= $row['impressions']?number_format(100*$row['clicks']/$row['impressions'],2,',','.').' %':'Nicht berechenbar' ?></td></tr>
<?php endforeach; ?></tbody></table></div>
