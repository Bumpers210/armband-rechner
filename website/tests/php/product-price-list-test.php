<?php
declare(strict_types=1);
require dirname(__DIR__,2).'/test-api-private/program/product-price-list.php';
$checks=0;
function gviz(array $rows, ?array $cols=null): string {
    return '/*O_o*/ google.visualization.Query.setResponse('.json_encode(['status'=>'ok','table'=>[
        'cols'=>$cols??[['label'=>'Name','type'=>'string'],['label'=>'Preis pro Stück','type'=>'number'],['label'=>'Aktiv','type'=>'boolean']],
        'rows'=>array_map(static fn($r)=>['c'=>array_map(static fn($v)=>['v'=>$v],$r)],$rows)]],JSON_THROW_ON_ERROR).');';
}
$assert=static function(bool $ok)use(&$checks){if(!$ok)throw new RuntimeException('price_contract_failed');$checks++;};
$reject=static function(string $raw)use(&$checks){try{carmaja_product_price_list_parse($raw);}catch(Throwable){$checks++;return;}throw new RuntimeException('expected_invalid_price_list');};
$prices=carmaja_product_price_list_parse(gviz([[' Amethyst ',0.15,true],['Spacer',1.25,true],['hidden',null,false],[null,null,null]]));
$assert($prices===[['name'=>'Amethyst','unitPrice'=>'0.15'],['name'=>'Spacer','unitPrice'=>'1.25']]);
$assert(carmaja_product_price_list_parse(gviz([['A',0,true]]))[0]['unitPrice']==='0');
foreach([[['A',-1,true]],[['A','1.25',true]],[['A',1,'true']],[[' ',1,true]],[['A',1,true],[' a ',2,true]],[['Ä',1,true],['ä',2,true]],[['A',null,true]],[['A',1]]]as$rows)$reject(gviz($rows));
$reject(gviz([],[]));$reject('{}');$reject(str_repeat('x',1000001));$reject('google.visualization.Query.setResponse(invalid);');
$assert(str_starts_with(CARMAJA_PRODUCT_PRICE_LIST_URL,'https://docs.google.com/spreadsheets/'));
echo "PASS shared app price catalog: $checks checks\n";
