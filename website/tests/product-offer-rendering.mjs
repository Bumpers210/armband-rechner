// Local integration only: synthetic API replies, no production requests or orders.
import assert from 'node:assert/strict';
import {readFileSync,writeFileSync} from 'node:fs';
import path from 'node:path';
import {spawnSync,execFileSync} from 'node:child_process';
import vm from 'node:vm';
import React from 'react';
import * as jsxRuntime from 'react/jsx-runtime';
import {renderToString} from 'react-dom/server';
import ts from 'typescript';
import * as liveHelpers from '../lib/live-product-offer.mjs';
import {withProductEditorial} from '../lib/product-editorial.mjs';

const root=path.resolve(import.meta.dirname,'..');
const php=process.env.CARMAJA_PHP_BINARY??'php';
assert.equal(execFileSync(php,['-r','echo PHP_MAJOR_VERSION . "." . PHP_MINOR_VERSION;'],{encoding:'utf8'}),'8.4');
const catalog=JSON.parse(readFileSync(path.join(root,'content/products.json'),'utf8')).products;
const ld=html=>[...html.matchAll(/<script\b(?=[^>]*\btype="application\/ld\+json")[^>]*>([\s\S]*?)<\/script>/g)].map(m=>JSON.parse(m[1]));
const phpRunner=String.raw`$c=json_decode(stream_get_contents(STDIN),false,32,JSON_THROW_ON_ERROR); require $c->library; $reply=\Carmaja\ProductOffers\render($c->root,$c->uri,'GET',static function($id) use($c){if($c->fail)throw new RuntimeException('fixture unavailable');return $c->product;}); echo json_encode($reply,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);`;
function phpReply(uri,product,fail=false){const result=spawnSync(php,['-r',phpRunner],{input:JSON.stringify({library:path.join(root,'product-offer-hosting/_internal/product-offers.php'),root:path.join(root,'out'),uri,product,fail}),encoding:'utf8'});assert.equal(result.status,0,result.stderr);return JSON.parse(result.stdout);}
let seed=null;
function load(relative){
 const code=ts.transpileModule(readFileSync(path.join(root,relative),'utf8'),{compilerOptions:{module:ts.ModuleKind.CommonJS,target:ts.ScriptTarget.ES2022,jsx:ts.JsxEmit.ReactJSX,esModuleInterop:true}}).outputText;
 const exports={};
 vm.runInNewContext(code,{exports,process:{env:{}},require(name){
  if(name==='react')return React;if(name==='react/jsx-runtime')return jsxRuntime;
  if(name==='next/link')return {__esModule:true,default:props=>React.createElement('a',props)};
  if(name==='@/components/json-ld')return load('components/json-ld.tsx');
  if(name==='@/lib/cart-client')return load('lib/cart-client.ts');
  if(name==='@/config/production-shop-deployment.json')return JSON.parse(readFileSync(path.join(root,'config/production-shop-deployment.json'),'utf8'));
  if(name==='@/lib/live-product-offer.mjs')return {...liveHelpers,readOfferBootstrap:()=>seed};
  throw Error('Unexpected dependency '+name);
 }},{filename:relative});return exports;
}
const {ShopBuyNow}=load('components/shop-buy-now.tsx');
const {ProductShippingSummary}=load('components/product-shipping-summary.tsx');
const checked=[];
let informationalPages=0;
for(const [index,p] of catalog.entries()){
 const uri='/armbaender/'+p.slug+'/';
 const baseline=readFileSync(path.join(root,'out',uri,'index.html'),'utf8');
 const docs=ld(baseline),base=docs.find(d=>d['@type']==='Product'),org=docs.find(d=>d['@type']==='Organization');
 const custom=withProductEditorial({...p,publicTitle:p.title}).custom;
 if(custom || !p.salesEnabled){
  // An inquiry is not a priced or out-of-stock offer, even with salesEnabled=true.
  // The existing PHP renderer must leave it intact, without any API dependency.
  const reply=phpReply(uri,null,true);
  assert.equal(reply.status,200,p.sku);
  assert.equal(reply.body,baseline);
  assert.equal(base.offers,undefined);
  assert.doesNotMatch(baseline,/cmj-offer-seed-|class="shop-buy-now"/);
  if(custom)assert.match(baseline,/Preis nach Absprache/);
  informationalPages++;
  continue;
 }
 const policies={seller:{'@id':org['@id']},hasMerchantReturnPolicy:org.hasMerchantReturnPolicy,shippingDetails:{'@type':'OfferShippingDetails',hasShippingService:{'@id':org.hasShippingService['@id']}}};
 const fixture={productId:p.productId,priceMinor:2701+index,currency:'eur',available:true,availableQuantity:4,privateField:'MUST_NOT_LEAK'};
 for(const mode of ['in-stock','out-of-stock','upstream-failure']){
  const current=mode==='out-of-stock'?{...fixture,available:false,availableQuantity:0}:fixture;
  const reply=phpReply(uri,current,mode==='upstream-failure');
  assert.equal(reply.status,mode==='upstream-failure'?503:200,p.sku);
  assert.equal(reply.headers['Cache-Control'],'no-store');
  const schema=ld(reply.body).find(d=>d['@type']==='Product');
  assert.equal(schema.description,base.description);assert.deepEqual(schema.image,base.image);
  if(mode==='upstream-failure')assert.equal(schema.offers,undefined);
  else {assert.equal(schema.offers.price,liveHelpers.minorPrice(current.priceMinor));assert.equal(schema.offers.availability,'https://schema.org/'+(current.available?'InStock':'OutOfStock'));assert.deepEqual(schema.offers.hasMerchantReturnPolicy,org.hasMerchantReturnPolicy);}
  const seedText=reply.body.match(new RegExp('<script[^>]*id="cmj-offer-seed-'+p.productId+'"[^>]*>(.*?)</script>'))[1];
  seed=liveHelpers.parseOfferBootstrap(seedText,p.productId);assert.ok(seed);
  const actual=reply.body.match(/<section class="shop-buy-now"[^>]*>[\s\S]*?<\/section>/)[0];
  const expected=renderToString(React.createElement(ShopBuyNow,{productId:p.productId,structuredProduct:base,offerPolicies:policies},React.createElement(ProductShippingSummary)));
  assert.equal(actual,expected,p.sku+' '+mode+' must hydrate without suppression or replacing HTML');
  assert.match(actual,/<button[^>]*disabled=""[^>]*>In den Warenkorb/);
  assert.doesNotMatch(actual,/MUST_NOT_LEAK/);
  checked.push({sku:p.sku,mode,status:reply.status,hydrationMarkupEqual:true});
 }
}
const result={products:catalog.length,informationalPages,responses:checked.length,exactHydrationMatches:checked.length};
if(process.env.CARMAJA_OFFER_PROOF)writeFileSync(process.env.CARMAJA_OFFER_PROOF,JSON.stringify({result,checked},null,2));
console.log('PRODUCT_OFFER_RENDERING_OK',JSON.stringify(result));
