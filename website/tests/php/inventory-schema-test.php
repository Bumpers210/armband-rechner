<?php
declare(strict_types=1);
require_once dirname(__DIR__,2).'/scripts/inventory-count-production.php';
$checks=0;
function is_check(bool $ok):void {global $checks; if(!$ok)throw new RuntimeException('schema_comparison_regression'); $checks++;}
$original="CREATE TABLE `fixture` (\n  `kind` enum('single','cart') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'single'\n) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
$restored=str_replace("enum('single','cart') COLLATE", "enum('single','cart') CHARACTER SET utf8mb4 COLLATE", $original);
is_check(cmj_icp_schema_canonical($original)===cmj_icp_schema_canonical($restored));
is_check(cmj_icp_schema_canonical($original)===cmj_icp_schema_canonical(str_replace('AUTO_INCREMENT=4','AUTO_INCREMENT=99',$original)));
foreach(['utf8mb4_unicode_ci'=>'utf8mb4_bin',"'single','cart'"=>"'single','cart','extra'",'NOT NULL'=>'NULL',
    "DEFAULT 'single'"=>"DEFAULT 'cart'",'ENGINE=InnoDB'=>'ENGINE=MyISAM'] as $from=>$to)
    is_check(cmj_icp_schema_canonical($original)!==cmj_icp_schema_canonical(str_replace($from,$to,$original)));
is_check(cmj_icp_schema_canonical($restored)!==cmj_icp_schema_canonical(str_replace('CHARACTER SET utf8mb4','CHARACTER SET latin1',$restored)));
foreach(["' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci AUTO_INCREMENT=4'",
    "'escaped\\' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci AUTO_INCREMENT=4'",
    "'doubled'' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci AUTO_INCREMENT=4'",
    '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci AUTO_INCREMENT=4`',
    '" CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci AUTO_INCREMENT=4"'] as $quoted)
    is_check(cmj_icp_schema_canonical($quoted)===$quoted);
is_check(cmj_icp_schema_canonical('COLLATE=utf8mb4_unicode_ci')==='COLLATE=utf8mb4_unicode_ci');
echo "Inventory schema: $checks canonical structure checks passed.\n";
