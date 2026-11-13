<?php
declare(strict_types=1);
ini_set('display_errors','0'); ini_set('log_errors','0'); ini_set('zend.exception_ignore_args','1'); umask(0077);
set_exception_handler(static function (): never { fwrite(STDERR,"INVENTORY_PACKAGE_TEST_FAILED\n"); exit(2); });
function icold_check(bool $ok): void { if (!$ok) throw new RuntimeException('package_check'); }

if (($argv[1] ?? '') === '--child') {
    $tool = str_replace('\\','/',realpath($argv[2]) ?: ''); $mode=$argv[3];
    icold_check(!function_exists('carmaja_api_canonicalize') && !class_exists('CarmajaCommercePdo',false));
    require $tool.'/inventory-count-production.php';
    $release=json_decode(file_get_contents($tool.'/release.json'),true,64,JSON_THROW_ON_ERROR);
    try { cmj_icp_load_dependencies($tool,$release); }
    catch (Throwable) {
        icold_check($mode!=='valid' && !class_exists('CarmajaCommercePdo',false));
        echo "REFUSED_BEFORE_RUNTIME\n"; exit(0);
    }
    icold_check($mode==='valid' && class_exists('CarmajaInventoryProductionCaller',false)
        && class_exists('CarmajaCartCutover',false) && class_exists('CarmajaCartCommerce',false)
        && class_exists('CarmajaProductionBackup',false));
    $loaded=[];
    foreach (get_included_files() as $file) {
        if(realpath($file)===realpath(__FILE__)) continue;
        $file=str_replace('\\','/',realpath($file)); icold_check(dirname($file)===$tool); $loaded[]=basename($file);
    }
    sort($loaded); $expected=CMJ_ICP_TOOL_FILES; sort($expected); icold_check($loaded===$expected);
    echo "COLD_START_OK\n"; exit(0);
}

function icold_run(array $command, string $cwd): array {
    $process=proc_open($command,[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,$cwd);
    icold_check(is_resource($process)); fclose($pipes[0]);
    $out=stream_get_contents($pipes[1]); $err=stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]);
    return [proc_close($process),$out,$err];
}
$root=sys_get_temp_dir().'/cmj-inventory-cold-'.bin2hex(random_bytes(8)); mkdir($root,0700);
$checks=0;
try {
    $script=dirname(__DIR__,2).'/scripts/prepare-inventory-count-tool.mjs';
    [$status]=icold_run(['node',$script,$root.'/source'],dirname(__DIR__,2)); icold_check($status===0);
    foreach (['valid','missing','tampered','unbound'] as $mode) {
        $tool=$root.'/'.$mode; mkdir($tool,0700);
        foreach(scandir($root.'/source') as $name) {
            if(in_array($name,['.','..'],true)) continue;
            icold_check(is_file($root.'/source/'.$name) && !is_link($root.'/source/'.$name));
            copy($root.'/source/'.$name,$tool.'/'.$name); chmod($tool.'/'.$name,0600);
        }
        if($mode==='missing') unlink($tool.'/commerce-core.php');
        if($mode==='tampered') file_put_contents($tool.'/cart-commerce.php',"\n/* artificial tamper */",FILE_APPEND);
        if($mode==='unbound') {
            $manifest=json_decode(file_get_contents($tool.'/release.json'),true); unset($manifest['toolFiles']['production-cart-cutover.php']);
            file_put_contents($tool.'/release.json',json_encode($manifest,JSON_THROW_ON_ERROR));
        }
        [$exit,$out,$err]=icold_run([PHP_BINARY,'-n','-d','extension_dir='.ini_get('extension_dir'),'-d','extension=mbstring',
            __FILE__,'--child',$tool,$mode],$tool);
        icold_check($exit===0 && $err==='' && $out===($mode==='valid'?"COLD_START_OK\n":"REFUSED_BEFORE_RUNTIME\n")); $checks++;
    }
    [$exit,$out,$err]=icold_run([PHP_BINARY,'-n',$root.'/valid/inventory-count-production.php','apply','inventory-artificial-cold',str_repeat('a',64)],$root.'/valid');
    icold_check($exit===2 && $out==='' && $err==="INVENTORY_REFUSED: bound prerequisites failed; inspect before retry; all sales gates remain closed.\n"); $checks++;
} finally {
    // Only freshly created code-only directories, never a source/runtime directory.
    foreach(scandir($root) as $name) {
        if(in_array($name,['.','..'],true)) continue;
        $dir=$root.'/'.$name; icold_check(is_dir($dir) && !is_link($dir));
        foreach(scandir($dir) as $file) {
            if(in_array($file,['.','..'],true)) continue;
            icold_check(is_file($dir.'/'.$file) && !is_link($dir.'/'.$file)); unlink($dir.'/'.$file);
        }
        rmdir($dir);
    }
    rmdir($root);
}
icold_check(!file_exists($root));
echo "Inventory package: $checks cold starts/refusals passed; cleanup complete.\n";
