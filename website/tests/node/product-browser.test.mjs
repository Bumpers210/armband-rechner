import test from 'node:test';
import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import vm from 'node:vm';
import ts from 'typescript';

const source = file => readFileSync(new URL(`../../${file}`, import.meta.url), 'utf8');
function loadClient(fetch = async () => { throw new Error('Unexpected network'); }) {
  const testModule = {exports: {}};
  vm.runInNewContext(ts.transpileModule(source('lib/product-browser-client.ts'), {compilerOptions: {module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2022}}).outputText,
    {module: testModule, exports: testModule.exports, fetch, FormData, Blob, process: {env: {CARMAJA_SITE_TARGET: 'test'}}, crypto: {randomUUID: () => '99999999-9999-4999-8999-999999999992'}});
  return testModule.exports;
}
const plain = value => JSON.parse(JSON.stringify(value));
test('Produkt-Payload enthält ausschließlich V5-Schreibfelder, keine Serverfelder und keinen Bestand', () => {
  const c = loadClient(); const product = c.newProduct();
  product.stock = 88; product.sourceHash = 'server'; product.notices = ['server']; product.currency = 'usd';
  const body = plain(c.productPayload(product));
  assert.deepEqual(Object.keys(body).sort(), ['expectedProductVersion','name','descriptionDocument','materials','metalElements','braceletSizeCm','pearlSizeMm','careInstructions','images','priceMinor','currency','salesEnabled'].sort());
  assert.equal(body.currency, 'eur'); assert.equal(body.salesEnabled, false); assert.equal(body.expectedProductVersion, 0);
});
test('Formatierte Beschreibung und Maße werden unverändert übernommen', () => {
  const c = loadClient(); const p = c.newProduct();
  p.descriptionDocument.blocks[0].spans = [{text:'Naturstein',bold:true,italic:true,font:'elegant',size:'large'}];
  p.braceletSizeCm = 18.5; p.pearlSizeMm = 8;
  assert.deepEqual(plain(c.productPayload(c.editingProduct(p))).descriptionDocument, plain(p.descriptionDocument));
  assert.equal(c.productPayload(p).braceletSizeCm, 18.5); assert.equal(c.productPayload(p).pearlSizeMm, 8);
});
test('Bildmanifest normalisiert Reihenfolge ohne Blob, Uploadstatus oder private Pfade', () => {
  const c = loadClient(); const p = c.newProduct();
  p.images = [{imageId:'image-a',fileName:'03.jpg',alt:'Steine',width:3200,height:1800,isMain:false,blob:'private',path:'/private',uploaded:true}];
  assert.deepEqual(plain(c.productPayload(p).images), [{imageId:'image-a',fileName:'01.jpg',alt:'Steine',width:3200,height:1800,isMain:true}]);
});
test('Euro-Eingaben sind ganzzahlige Cent; unzulässige Preise werden zurückgewiesen', () => {
  const c = loadClient(); for (const [input, expected] of [['28',2800],['28,50',2850],['0.01',1],[' 1,2 ',120]]) assert.equal(c.priceFromInput(input),expected);
  for (const bad of ['', '0', '-1', '1.234', '1e3', 'Infinity', '28 EUR']) assert.throws(() => c.priceFromInput(bad));
});
test('Browser verwendet ausschließlich festen Testendpunkt und begrenzte Routen', () => {
  const c = loadClient(); assert.equal(c.productBrowserUrl('/products/abc/images/def'), 'https://test-api.carmaja-perlen.de/product-browser/index.php?route=%2Fproducts%2Fabc%2Fimages%2Fdef');
  for (const bad of ['https://evil.invalid','/../config','/products/abc/delete','/products?token=secret','/products/a%2Fb']) assert.throws(() => c.productBrowserUrl(bad));
});
test('Schreibaufruf sendet Cookie, CSRF und Idempotenz, niemals Geräte-Bearer', async () => {
  const calls = []; const c = loadClient(async (url, options) => { calls.push({url,...options}); return {ok:true,json:async()=>({ok:true,data:{saved:true}})}; });
  await c.productRequest('/products/abc','PUT',{name:'Test'},'csrf-example','operation-example');
  assert.equal(calls[0].cache,'no-store'); assert.equal(calls[0].credentials,'include');
  assert.equal(calls[0].headers['X-CSRF-Token'],'csrf-example'); assert.equal(calls[0].headers['Idempotency-Key'],'operation-example');
  assert.equal(calls[0].headers.Authorization,undefined);
});
test('Versionskonflikt und Serverfehler zeigen keine privaten Fehlerdetails', async () => {
  for (const status of [409,503]) {
    const c = loadClient(async()=>({ok:false,status,json:async()=>({error:{code:'conflict',message:'SECRET_PRIVATE'}})}));
    await assert.rejects(c.productRequest('/products'), e => e.status === status && !e.message.includes('SECRET_PRIVATE'));
  }
});
test('Unklarer Netzwerkausgang wird nicht als erfolgreich dargestellt', async () => {
  const c = loadClient(); await assert.rejects(c.productRequest('/products'), e => e.code === 'network_unknown' && /unklar/.test(e.message));
});
test('Server-Bildabruf benötigt Anmeldung und liefert keinen Token in der URL', async () => {
  const calls = []; const c = loadClient(async(url,opts)=>{calls.push({url,...opts});return {ok:true,headers:{get:()=> 'image/jpeg'},blob:async()=>new Blob(['test'])};});
  await c.productImageBlob('abc','def'); assert.equal(calls[0].credentials,'include'); assert.equal(calls[0].cache,'no-store'); assert.doesNotMatch(calls[0].url,/token|secret|auth/i);
});
test('Produktionsseite bleibt gesperrt und Admin-Link wird nur im Testbuild angezeigt', () => {
  assert.match(source('app/artikelverwaltung/page.tsx'), /if \(!siteTarget\.isTest\) notFound\(\)/);
  assert.match(source('app/admin/page.tsx'), /siteTarget\.isTest \?/);
});
test('Editor speichert keine Passwörter oder Geräte-Tokens in Browser-Speichern', () => {
  const code = source('components/product-manager.tsx') + source('lib/product-browser-client.ts');
  assert.doesNotMatch(code,/localStorage|sessionStorage|document\.cookie|dangerouslySetInnerHTML/);
  assert.match(code,/form\.reset\(\)/); assert.match(code,/URL\.revokeObjectURL/); assert.match(code,/beforeunload/);
});
test('Bildübertragung und Konflikte sperren Veröffentlichung; Bestand ist ein eigener Vorgang', () => {
  const code = source('components/product-manager.tsx');
  assert.match(code,/!dirty && !conflict && !imageRecovery\.current/);
  assert.match(code,/expectedInventoryVersion: inventory!\.inventoryVersion/);
  assert.match(code,/keyFor\(`save:/); assert.match(code,/keyFor\(`\$\{action\}:/);
  assert.match(code,/const images = imageChanged \? await Promise\.all/);
  assert.match(code,/prepareProductImage\(file/);
});

function uiHarness({rejectSave = false, confirmResult = true} = {}) {
  const c = loadClient(); const states = []; let cursor = 0; const calls = [];
  const product = {...c.newProduct(), name:'HANDY-TEST UI', productVersion:3, version:5, sourceHash:'a'.repeat(64),
    notices:['Natursteine können abweichen.'], descriptionDocument:{version:1,blocks:[{type:'paragraph',spans:[c.newSpan('Beschreibung')]}]}};
  states[0] = 'csrf-example'; states[1] = [product]; states[2] = product;
  const react = {useState(initial){const index=cursor++;if(!(index in states))states[index]=initial;return[states[index],value=>{states[index]=typeof value==='function'?value(states[index]):value;}];},
    useRef(initial){const index=cursor++;if(!(index in states))states[index]={current:initial};return states[index];},useEffect(){}};
  const request=async(route,method='GET',body,csrf,key)=>{
    calls.push({route,method,body,csrf,key});
    if(method==='PUT'){
      if(rejectSave)throw new c.ProductBrowserError(409,'product_version_conflict','Bitte Serverstand neu laden.');
      return {product:{...product,...body,productVersion:4,version:6}};
    }
    if(route==='/products')return {products:[product]};
    if(route.endsWith('/inventory'))return {inventory:{onHand:3,reserved:0,available:3,inventoryVersion:2,quantityConfirmed:true}};
    throw Error('Unexpected request');
  };
  const testModule={exports:{}}; const jsx=(type,props)=>({type,props});
  vm.runInNewContext(ts.transpileModule(source('components/product-manager.tsx'),{compilerOptions:{jsx:ts.JsxEmit.ReactJSX,module:ts.ModuleKind.CommonJS,target:ts.ScriptTarget.ES2022}}).outputText,
    {module:testModule,exports:testModule.exports,Intl,crypto:{randomUUID:()=> 'browser-ui-test-op'},window:{confirm:()=>confirmResult},
      require(name){if(name==='react')return react;if(name==='react/jsx-runtime')return {jsx,jsxs:jsx};if(name==='@/components/product-calculator')return {ProductCalculator:'Calculator'};if(name==='@/lib/product-browser-client')return {...c,productRequest:request};throw Error('Unexpected import');}});
  return {calls,states,render(){cursor=0;return testModule.exports.ProductManager();}};
}
function nodes(node,predicate){if(Array.isArray(node))return node.flatMap(n=>nodes(n,predicate));if(!node||typeof node!=='object')return [];return [...(predicate(node)?[node]:[]),...nodes(node.props?.children,predicate)];}
function text(node){if(Array.isArray(node))return node.map(text).join('');if(!node||typeof node!=='object')return String(node??'');return text(node.props?.children);}
const tick=()=>new Promise(resolve=>setImmediate(resolve));
test('Echter PC-Editor speichert V5-Felder und erzeugt keinen Bestands- oder Veröffentlichungsvorgang',async()=>{
  const h=uiHarness();const tree=h.render();
  const form=nodes(tree,n=>n.type==='form')[0];form.props.onSubmit({preventDefault(){}});await tick();await tick();
  const writes=h.calls.filter(c=>c.method!=='GET');assert.equal(writes.length,1);assert.equal(writes[0].method,'PUT');
  assert.equal(writes[0].body.expectedProductVersion,3);assert.equal(writes[0].body.salesEnabled,false);assert.equal(writes[0].body.pearlSizeMm,8);
  assert.equal(writes[0].csrf,'csrf-example');assert.ok(writes[0].key);assert.match(text(h.render()),/Artikel gespeichert/);
});
test('Echter PC-Editor bewahrt Eingaben bei App-Konflikt und sperrt Veröffentlichung',async()=>{
  const h=uiHarness({rejectSave:true});const tree=h.render();
  nodes(tree,n=>n.type==='form')[0].props.onSubmit({preventDefault(){}});await tick();await tick();
  const after=h.render();assert.match(text(after),/Änderungskonflikt/);
  assert.equal(nodes(after,n=>n.type==='button'&&text(n)==='Veröffentlichen')[0].props.disabled,true);
  assert.equal(h.states[2].name,'HANDY-TEST UI');assert.equal(h.states[2].productVersion,3);
});

test('Kalkulationsübernahme abgebrochen: Editor bleibt vollständig unverändert, kein Request',()=>{
  const h=uiHarness({confirmResult:false});const tree=h.render();const before=plain(h.states[2]);const oldPrice=h.states[3];
  const calculator=nodes(tree,n=>n.type==='Calculator')[0];
  assert.throws(()=>calculator.props.onApply({priceMinor:2250,materials:['Bergkristall'],metalElements:['Spacer Edelstahl']},false),/abgebrochen/);
  assert.deepEqual(plain(h.states[2]),before);assert.equal(h.states[3],oldPrice);assert.equal(h.states[4],false);assert.deepEqual(h.calls,[]);
});

test('Bestätigte Kalkulationsübernahme bleibt ungespeichert und erhält alle übrigen Artikelfelder',()=>{
  const h=uiHarness();const tree=h.render();const before=plain(h.states[2]);
  const fields={priceMinor:2250,materials:['Bergkristall'],metalElements:['Spacer Edelstahl']};
  nodes(tree,n=>n.type==='Calculator')[0].props.onApply(fields,false);
  assert.deepEqual(plain(h.states[2]),{...before,...fields});assert.equal(h.states[3],'22,50');assert.equal(h.states[4],true);
  assert.deepEqual(h.calls,[]);assert.equal(nodes(h.render(),n=>n.type==='button'&&text(n)==='Veröffentlichen')[0].props.disabled,true);
});
