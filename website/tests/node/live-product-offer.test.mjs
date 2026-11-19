import assert from 'node:assert/strict';
import {readFile} from 'node:fs/promises';
import test from 'node:test';
import {existsSync} from 'node:fs';
import {spawnSync} from 'node:child_process';
import {validatedLiveProduct, parseOfferBootstrap, withLiveOffer, minorPrice} from '../../lib/live-product-offer.mjs';
const id='11111111-1111-4111-8111-111111111111';
const live={productId:id,priceMinor:1234,currency:'eur',available:true,availableQuantity:4};

test('Live-Angebote verwenden eine strikte gemeinsame Projektion ohne interne Felder',()=>{
  assert.deepEqual(validatedLiveProduct({...live,privatePrice:9999},id),live);
  for(const change of [{productId:'wrong'},{currency:'usd'},{priceMinor:0},{priceMinor:12.34},
    {priceMinor:Infinity},{available:'true'},{availableQuantity:-1},{availableQuantity:NaN},
    {available:true,availableQuantity:0}]) assert.equal(validatedLiveProduct({...live,...change},id),null);
  assert.deepEqual(validatedLiveProduct({...live,available:false},id),{...live,available:false});
});
test('Der Server-Startzustand bindet Produktidentität und Fehlerzustand, niemals einen Kauf',()=>{
  const seed={version:1,product:live,failed:false};
  assert.deepEqual(parseOfferBootstrap(JSON.stringify(seed),id),seed);
  assert.deepEqual(parseOfferBootstrap('{"version":1,"product":null,"failed":true}',id),{version:1,product:null,failed:true});
  for(const raw of ['null','{}','bad JSON',JSON.stringify({...seed,version:2}),JSON.stringify({...seed,failed:true}),JSON.stringify(seed)+' '.repeat(1024)])assert.equal(parseOfferBootstrap(raw,id),null);
  assert.equal(parseOfferBootstrap(JSON.stringify(seed),'another-product'),null);
});
test('Offer behält echte Richtlinien und stellt Centbeträge und Ausverkauf korrekt dar',()=>{
  const base={'@type':'Product',name:'Testfixture',url:'https://example.invalid/bracelet/'};
  const policies={seller:{'@id':'https://example.invalid/#organization'},hasMerchantReturnPolicy:{merchantReturnDays:14}};
  const data=withLiveOffer(base,policies,live);
  assert.equal(data.offers.price,'12.34');assert.equal(data.offers.priceCurrency,'EUR');
  assert.equal(data.offers.availability,'https://schema.org/InStock');
  assert.equal(data.offers.hasMerchantReturnPolicy,policies.hasMerchantReturnPolicy);
  assert.equal(withLiveOffer(base,policies,{...live,available:false,availableQuantity:0}).offers.availability,'https://schema.org/OutOfStock');
  assert.equal(withLiveOffer(base,policies,null),base);assert.equal('offers' in base,false);
  assert.equal('aggregateRating' in data,false);assert.equal('review' in data,false);
  assert.equal(minorPrice(1),'0.01');assert.equal(minorPrice(3050),'30.50');
});
test('Apache-Integration bleibt auf bestehende Produktpfade und separate Runtime begrenzt',async()=>{
  const root=new URL('../../',import.meta.url);
  const part=s=>s.match(/# BEGIN CARMAJA SERVER OFFERS[\s\S]*?# END CARMAJA SERVER OFFERS/)?.[0];
  const block=part(await readFile(new URL('product-offer-hosting/apache-rules.conf',root),'utf8'));
  assert.ok(block);
  for(const p of ['hosting/.htaccess','hosting/.htaccess.publish.example'])assert.equal(part(await readFile(new URL(p,root),'utf8')),block);
  assert.match(block,/product-offer\.php -f/);assert.match(block,/_internal\/product-offers\.php -f/);
  assert.match(block,/RewriteRule \^armbaender\/\(cp-/);assert.doesNotMatch(block,/User-Agent|Googlebot|proxy|https?:/i);
});

const apache=process.env.CARMAJA_APACHE_BINARY??(existsSync('/usr/sbin/apache2')?'/usr/sbin/apache2':null);
test('Apache routet nur vorhandene Produktseiten und bewahrt die statische Rückfallebene',{skip:!apache},()=>{
  const result=spawnSync('bash',['tests/shell/product-offer-routing-test.sh',apache,process.env.CARMAJA_APACHE_MODULES??'/usr/lib/apache2/modules','hosting/.htaccess'],{encoding:'utf8',timeout:45000});
  assert.equal(result.status,0,result.stdout+result.stderr);assert.match(result.stdout,/PRODUCT_OFFER_ROUTING_OK/);
});
