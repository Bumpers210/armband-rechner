<?php
declare(strict_types=1);

// Exact read-only source used by Android GvizPriceListSource. Never accepts a URL from a client.
const CARMAJA_PRODUCT_PRICE_LIST_URL = 'https://docs.google.com/spreadsheets/d/'
    . '1PsiIr5pjKYPQIP0WxP3JPMn5y_sdWaM_hRT6tzzOIrU/gviz/tq'
    . '?sheet=Preisliste&range=A1%3AC&headers=1'
    . '&tq=select%20A%2CB%2CC%20where%20C%20%3D%20true&tqx=out%3Ajson';

function carmaja_product_price_list_parse(string $text): array
{
    $fail=static function(): never { throw new RuntimeException('price_list_invalid'); };
    if(strlen($text)>1000000) $fail();
    $prefix='google.visualization.Query.setResponse('; $start=strpos($text,$prefix); $end=strrpos($text,');');
    if($start===false||$end===false||$end<=$start+strlen($prefix)) $fail();
    $root=json_decode(substr($text,$start+strlen($prefix),$end-$start-strlen($prefix)),true,64,JSON_THROW_ON_ERROR);
    if(($root['status']??'ok')!=='ok') $fail();
    $table=$root['table']??[]; $cols=$table['cols']??[];
    if(count($cols)!==3||array_column($cols,'label')!==['Name','Preis pro Stück','Aktiv']
        ||array_column($cols,'type')!==['string','number','boolean']||!is_array($table['rows']??null)) $fail();
    $seen=[]; $prices=[];
    foreach($table['rows']as$row){
        $cells=$row['c']??null; if(!is_array($cells)||count($cells)!==3) $fail();
        [$name,$price,$active]=array_map(static fn($c)=>is_array($c)?($c['v']??null):null,$cells);
        if($name===null&&$price===null&&$active===null) continue;
        if(!is_bool($active)) $fail(); if(!$active) continue;
        if(!is_string($name)||trim($name)===''||!(is_int($price)||is_float($price))||!is_finite((float)$price)||$price<0) $fail();
        $name=trim($name); $key=mb_strtolower($name,'UTF-8'); if(isset($seen[$key])) $fail(); $seen[$key]=true;
        $prices[]=['name'=>$name,'unitPrice'=>json_encode($price,JSON_PRESERVE_ZERO_FRACTION|JSON_THROW_ON_ERROR)];
    }
    return $prices;
}

function carmaja_product_price_list_load(): array
{
    $curl=curl_init(CARMAJA_PRODUCT_PRICE_LIST_URL); $body='';
    if($curl===false) throw new RuntimeException('price_list_unavailable');
    try{
        curl_setopt_array($curl,[CURLOPT_CONNECTTIMEOUT=>15,CURLOPT_TIMEOUT=>20,CURLOPT_FOLLOWLOCATION=>false,
            CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,
            CURLOPT_HTTPHEADER=>['Accept: application/json, text/javascript'],CURLOPT_USERAGENT=>'Armband-Rechner/1.0.0',
            CURLOPT_WRITEFUNCTION=>static function($ch,string $chunk)use(&$body):int{
                if(strlen($body)+strlen($chunk)>1000000) return 0; $body.=$chunk; return strlen($chunk);
            }]);
        if(curl_exec($curl)===false||(int)curl_getinfo($curl,CURLINFO_RESPONSE_CODE)!==200) throw new RuntimeException('price_list_unavailable');
        return ['prices'=>carmaja_product_price_list_parse($body),'loadedAt'=>gmdate('c')];
    }finally{curl_close($curl);}
}
