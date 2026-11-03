<?php
declare(strict_types=1);
require_once dirname(__DIR__,2).'/scripts/cart-ui-acceptance.php';
foreach(['cart-e2e-ui-20261025-a4c8172d'] as $good){
    if(!cart_ui_stage_valid($good)){throw new RuntimeException('valid_stage_rejected');}
}
foreach(['production','cart-e2e-ui-20261025-a4c8172d/../other','../cart-e2e-ui-20261025-a4c8172d','cart-e2e-ui-20261025-SECRET'] as $bad){
    if(cart_ui_stage_valid($bad)){throw new RuntimeException('unsafe_stage_allowed');}
}
$source=file_get_contents(dirname(__DIR__,2).'/scripts/cart-ui-acceptance.php');
foreach(["cart_probe_empty(\$pdo)", "\$config['restore']['database'] === 'dbs15962566'", 'ui_restore_must_be_empty',
    'restoreWasEmpty', 'ui_cleanup_unknown_table', 'ui_protected_changed', 'SET SESSION TRANSACTION READ ONLY',
    'ui_route_not_allowed', 'No provider keys', "cart_probe_connect(\$c['restore'],\$c)"] as $guard){
    if(!str_contains($source,$guard)){throw new RuntimeException('guard_missing: '.$guard);}
}
foreach(['restocked_items','commerce_inventory','order_items','withdrawal_items'] as $table){
    if(!in_array($table,cart_ui_allowed_tables(),true)){throw new RuntimeException('cleanup_table_missing');}
}
echo "PASS isolated UI harness guards, restore-only target and owned-table cleanup\n";
