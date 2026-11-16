import test from 'node:test';
import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import vm from 'node:vm';
import ts from 'typescript';
import {orderCatalog, validateCatalogOrder} from '../../lib/product-catalog-order.mjs';
import {validatePublisherPush} from '../../scripts/validate-production-publisher-push.mjs';

const ids = [1,2,3].map(n => `99999999-9999-4999-8999-99999999999${n}`);
const products = ids.map((productId, i) => ({productId, title:`Test ${i}`, updatedAt:`2026-11-0${i+1}T00:00:00Z`, salesEnabled:false, priceMinor:2800, images:[], sourceHash:'a'.repeat(64)}));
const source = file => readFileSync(new URL(`../../${file}`, import.meta.url), 'utf8');

test('Vor erster Sortierung bleibt bisherige Anzeige exakt erhalten; manuelle Auswahl verändert keine Produkte', () => {
  const before = structuredClone(products);
  assert.deepEqual(orderCatalog(products,{version:1,productIds:null}), [...products].reverse());
  const selected=[ids[1],ids[0]];
  assert.deepEqual(orderCatalog(products,{version:1,productIds:selected}).map(p=>p.productId),[ids[1],ids[0],ids[2]]);
  assert.deepEqual(products,before); assert.deepEqual(selected,[ids[1],ids[0]]);
});
test('Bearbeitung und Wiederveröffentlichung verschieben nicht; neue Artikel hinten, archivierte unsichtbar', () => {
  const order={version:1,productIds:[ids[1],ids[0]]};
  const changed=products.map(p=>({...p,updatedAt:'2030-01-01T00:00:00Z'}));
  assert.deepEqual(orderCatalog(changed,order).map(p=>p.productId),[ids[1],ids[0],ids[2]]);
  assert.deepEqual(orderCatalog([products[0],products[2]],order).map(p=>p.productId),[ids[0],ids[2]]);
  assert.deepEqual(orderCatalog([],order),[]);
});
test('Strikte Reihenfolge erlaubt keine Produktfelder, Duplikate, Pfade oder unbekannten Versionen', () => {
  for(const invalid of [null,{}, {version:2,productIds:[]},{version:1,productIds:[ids[0],ids[0]]},
    {version:1,productIds:['../auth']},{version:1,productIds:[1]},{version:1,productIds:[],stock:1},{version:1,productIds:undefined}]) assert.throws(()=>validateCatalogOrder(invalid));
  assert.deepEqual(validateCatalogOrder({version:1,productIds:[]}),{version:1,productIds:[]});
});

function publisher(overrides={}) {
  return {repository:'Bumpers210/armband-rechner', eventName:'push', ref:'refs/heads/main',branch:'main',actor:'Bumpers210',forced:'false',deleted:'false',
    beforeSha:'a'.repeat(40), headSha:'b'.repeat(40), parentShas:['a'.repeat(40)],
    commitMessage:`Carmaja collection reorder\n\nCarmaja-Publisher: catalog-order-v1\nCarmaja-Operation: catalog-${'c'.repeat(64)}\nCarmaja-Request-SHA256: ${'d'.repeat(64)}`,
    changedFiles:[{status:'M',path:'website/content/product-order.json'}], productsDocument:{version:4,products},orderDocument:{version:1,productIds:ids}, ...overrides};
}
test('Publisher-Reihenfolge erlaubt exakt eine Metadatendatei und keine Produkt-/Bild-/Codeänderung', () => {
  assert.equal(validatePublisherPush(publisher()).action,'reorder');
  for(const path of ['website/content/products.json','website/public/images/products/CP-2026-0001/01.jpg','.github/workflows/deploy-website.yml']) {
    assert.throws(()=>validatePublisherPush(publisher({changedFiles:[{status:'M',path}]})));
    assert.throws(()=>validatePublisherPush(publisher({changedFiles:[...publisher().changedFiles,{status:'M',path}]})));
  }
  for(const status of ['A','D','R']) assert.throws(()=>validatePublisherPush(publisher({changedFiles:[{status,path:'website/content/product-order.json'}]})));
  for(const productIds of [null,ids.slice(1),[...ids,ids[0]],['99999999-9999-4999-8999-999999999994',...ids]]) {
    assert.throws(()=>validatePublisherPush(publisher({orderDocument:{version:1,productIds}})));
  }
  for(const bad of [{actor:'other'},{forced:'true'},{ref:'refs/heads/other'},{parentShas:['c'.repeat(40)]},{eventName:'pull_request'}]) assert.throws(()=>validatePublisherPush(publisher(bad)));
});
test('Website nutzt getrennte Reihenfolge; Paket enthält Dienst; Bildlayout unverändert', () => {
  assert.match(source('content/products.ts'),/orderCatalog\(publicProducts/);
  assert.match(source('scripts/prepare-production-cart-package.mjs'),/'product-catalog-order.php'/);
  assert.match(source('test-api-private/program/product-api-v3.php'),/'catalog-projection'/);
  const css=source('app/site.css'); assert.match(css,/aspect-ratio: 3 \/ 4/); assert.match(css,/object-fit: contain/);
  for(const file of ['lib/product-browser-client.ts','scripts/product-browser-test.php','test-api-private/program/product-browser-http.php']) assert.match(source(file),/price-list\|catalog-order\|products/);
});

class BrowserError extends Error { constructor(status,code) {super('Katalog wurde inzwischen geändert.');this.status=status;this.code=code;} }
function ui({failures=[],confirm=true}={}) {
  const states=[]; let cursor=0; let key=0; const calls=[]; const dirty=[]; const busy=[]; let nextError=0;
  let catalog={revision:'a'.repeat(64),products:ids.map((productId,i)=>({productId,sku:`CP-2026-000${i+1}`,name:`Test ${i+1}`}))};
  const react={useState(initial){const i=cursor++;if(!(i in states))states[i]=initial;return[states[i],v=>{states[i]=typeof v==='function'?v(states[i]):v;}];},
    useRef(initial){const i=cursor++;if(!(i in states))states[i]={current:initial};return states[i];}};
  const request=async(route,method='GET',body,csrf,idempotencyKey)=>{
    calls.push({route,method,body,csrf,idempotencyKey});
    assert.equal(route,'/catalog-order');
    if(method==='GET')return structuredClone(catalog);
    const error=failures[nextError++]; if(error)throw error;
    catalog={revision:'b'.repeat(64),products:body.productIds.map(id=>catalog.products.find(p=>p.productId===id))};
    return {saved:true,publication:{deploymentStatus:'pending'}};
  };
  const mod={exports:{}};const jsx=(type,props)=>({type,props});
  vm.runInNewContext(ts.transpileModule(source('components/product-catalog-order.tsx'),{compilerOptions:{jsx:ts.JsxEmit.ReactJSX,module:ts.ModuleKind.CommonJS,target:ts.ScriptTarget.ES2022}}).outputText,
    {module:mod,exports:mod.exports,Error,crypto:{randomUUID:()=>`order-key-${++key}`},window:{confirm:()=>confirm},
      require(name){if(name==='react')return react;if(name==='react/jsx-runtime')return{jsx,jsxs:jsx};if(name==='@/lib/product-browser-client')return{ProductBrowserError:BrowserError,productRequest:request};throw Error(name);}});
  const render=()=>{cursor=0;return mod.exports.ProductCatalogOrder({csrf:'artificial-csrf',disabled:false,onDirtyChange:v=>dirty.push(v),onBusyChange:v=>busy.push(v)});};
  const find=(node,predicate)=>{if(Array.isArray(node))return node.flatMap(n=>find(n,predicate));if(!node||typeof node!=='object')return[];return[...(predicate(node)?[node]:[]),...find(node.props?.children,predicate)];};
  const text=node=>Array.isArray(node)?node.map(text).join(''):node&&typeof node==='object'?text(node.props?.children):String(node??'');
  return {calls,states,dirty,busy,render,text,find, async click(label) {
    const button=find(render(),n=>n.type==='button'&&(n.props['aria-label']===label||text(n)===label))[0];
    assert.ok(button,`button missing: ${label}`);assert.equal(!!button.props.disabled,false,`button disabled: ${label}`);
    button.props.onClick(); for(let i=0;i<12;i++)await Promise.resolve();
  }};
}
test('PC: Laden, Grenzen, Verschieben und explizites Speichern; kein Produkt-/Bestandsaufruf', async()=>{
  const h=ui();await h.click('Reihenfolge bearbeiten');
  const up=h.find(h.render(),n=>n.props?.['aria-label']==='Test 1 nach oben')[0];assert.equal(up.props.disabled,true);
  await h.click('Test 3 nach oben');assert.equal(h.calls.filter(c=>c.method==='PUT').length,0);assert.equal(h.dirty.at(-1),true);
  await h.click('Reihenfolge speichern');
  const put=h.calls.find(c=>c.method==='PUT');assert.deepEqual(JSON.parse(JSON.stringify(put.body)),{expectedRevision:'a'.repeat(64),productIds:[ids[0],ids[2],ids[1]]});
  assert.equal(put.csrf,'artificial-csrf');assert.ok(put.idempotencyKey);assert.equal(h.dirty.at(-1),false);
  assert.match(h.text(h.render()),/Website-Aktualisierung wird noch geprüft/);
});
test('PC: Netzfehler behält Auswahl und Idempotenzschlüssel, Wiederholung bestätigt erst nach Antwort',async()=>{
  const h=ui({failures:[new Error('Verbindung unterbrochen. Ausgang unklar.')]});
  await h.click('Reihenfolge bearbeiten');await h.click('Test 2 nach oben');await h.click('Reihenfolge speichern');
  assert.equal(h.dirty.at(-1),true);assert.match(h.text(h.render()),/Ausgang unklar/);
  await h.click('Speichern erneut prüfen');
  const writes=h.calls.filter(c=>c.method==='PUT');assert.equal(writes.length,2);assert.equal(writes[0].idempotencyKey,writes[1].idempotencyKey);assert.deepEqual(writes[0].body,writes[1].body);
});
test('PC: Zwei-Browser-Konflikt erhält Auswahl; Abbrechen beim Neuladen verwirft nichts',async()=>{
  const h=ui({failures:[new BrowserError(409,'catalog_order_conflict')],confirm:false});
  await h.click('Reihenfolge bearbeiten');await h.click('Test 2 nach oben');await h.click('Reihenfolge speichern');
  assert.equal(h.states[1][0].productId,ids[1]);assert.equal(h.dirty.at(-1),true);
  const before=h.calls.length;await h.click('Reihenfolge neu laden');assert.equal(h.calls.length,before);assert.equal(h.states[1][0].productId,ids[1]);
  assert.match(h.text(h.render()),/nichts wurde überschrieben/);
});
