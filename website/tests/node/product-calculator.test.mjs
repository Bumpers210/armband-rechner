import test from 'node:test';
import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import vm from 'node:vm';
import ts from 'typescript';
const source = path => readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8');
const mod = {exports:{}};
vm.runInNewContext(ts.transpileModule(source('lib/product-calculator.ts'), {compilerOptions:{module:ts.ModuleKind.CommonJS,target:ts.ScriptTarget.ES2022}}).outputText, {module:mod,exports:mod.exports});
const c = mod.exports; const plain = value => JSON.parse(JSON.stringify(value));
const prices = [{name:'A',unitPrice:'0.15'},{name:'B',unitPrice:'1.25'}];
test('PC reproduziert Android PriceCalculatorTest mit exakten Dezimalzahlen', () => {
  const r = c.calculateProduct(prices,{quantities:{A:3,B:2},workMinutes:'90',hourlyRate:'24',otherCosts:'3,20',markupPercent:'25'});
  for (const [field,expected] of Object.entries({materialCosts:2.95,laborCosts:36,totalCosts:42.15,profit:10.5375,exactSalePrice:52.69,recommendedSalePrice:53})) assert.equal(Number(r[field]),expected);
  assert.equal(c.calculationMoney(r.recommendedSalePrice),'53,00 €');
});
test('HALF_UP für Verkaufspreis, HALF_EVEN für Anzeige und CEILING für 50-Cent-Empfehlung', () => {
  const run = otherCosts => c.calculateProduct([],{...c.emptyCalculation(),otherCosts});
  assert.equal(run('1.005').exactSalePrice,'1.01'); assert.equal(run('10.01').recommendedSalePrice,'10.50');
  assert.equal(run('10.50').recommendedSalePrice,'10.50'); assert.equal(c.calculationMoney('1.005'),'1,00 €');
  assert.equal(c.calculationMoney('1.015'),'1,02 €');
  assert.equal(c.calculateProduct([{name:'A',unitPrice:'0.1'}],{...c.emptyCalculation(),quantities:{A:3}}).materialCosts,'0.3');
});
test('Arbeitszeitdivision verwendet zehn Nachkommastellen wie Android', () => {
  const r = c.calculateProduct([],{...c.emptyCalculation(),workMinutes:'1',hourlyRate:'60'});
  assert.equal(r.laborCosts,'1.0000000020'); assert.equal(r.exactSalePrice,'1.00');
});
test('Eingaben, Mengen und unbekannte Perlen werden abgegrenzt', () => {
  for (const value of ['-1','1e3','1,2,3','NaN','1234567890123']) assert.equal(c.numericInputAllowed(value),false);
  for (const value of ['',',','.5','12,50']) assert.equal(c.numericInputAllowed(value),true);
  for (const quantity of [-1,0.5,10000,NaN]) assert.throws(()=>c.calculateProduct(prices,{...c.emptyCalculation(),quantities:{A:quantity}}));
  assert.throws(()=>c.calculateProduct(prices,{...c.emptyCalculation(),quantities:{deleted:1}}));
  assert.equal(c.calculateProduct(prices,{...c.emptyCalculation(),quantities:{A:9999}}).materialCosts,'1499.85');
});
test('Übernahme entspricht App: nur Materialien, Spacer und Centpreis; keine internen Kosten', () => {
  const list=[...prices,{name:' Spacer   gold ',unitPrice:'0.5'},{name:'Spacer Edelstahl',unitPrice:'0.5'}];
  const fields=plain(c.calculationProductFields(list,{...c.emptyCalculation(),quantities:{A:1,' Spacer   gold ':2,'Spacer Edelstahl':1}}));
  assert.deepEqual(fields,{priceMinor:200,materials:['A'],metalElements:['Spacer gold Edelstahl','Spacer Edelstahl']});
  assert.throws(()=>c.calculationProductFields(list,c.emptyCalculation()));
  assert.throws(()=>c.calculationProductFields(list,{...c.emptyCalculation(),quantities:{'Spacer Edelstahl':1}}));
});
test('Neuberechnung erhält Stundenlohn und Aufschlag, nicht alte Mengen', () => {
  assert.deepEqual(plain(c.resetCalculation({quantities:{A:5},workMinutes:'20',hourlyRate:'15',otherCosts:'3',markupPercent:'25'})),
    {quantities:{},workMinutes:'0',hourlyRate:'15',otherCosts:'0',markupPercent:'25'});
});
test('Externe Namen mit Object-Prototypnamen sind sichere Auswahlwerte', () => {
  const p=[{name:'constructor',unitPrice:'1'},{name:'__proto__',unitPrice:'1'}];
  assert.equal(c.calculateProduct(p,c.emptyCalculation()).exactSalePrice,'0.00');
  assert.equal(c.calculateProduct(p,{...c.emptyCalculation(),quantities:JSON.parse('{"__proto__":2}')}).exactSalePrice,'2.00');
});
test('Preisliste bleibt authentifiziert, read-only und identisch zur Android-Quelle', () => {
  const php=source('test-api-private/program/product-price-list.php');
  const android=readFileSync(new URL('../../../app/src/main/java/de/carmajaperlen/armbandrechner/PriceRepository.kt',import.meta.url),'utf8');
  const sheet=/1PsiIr5pjKYPQIP0WxP3JPMn5y_sdWaM_hRT6tzzOIrU/;
  assert.match(php,sheet); assert.match(android,sheet);
  assert.doesNotMatch(php,/file_put_contents|fwrite|CURLOPT_POST|CURLOPT_SSL_VERIFYPEER\s*=>\s*false/);
  assert.match(php,/CURLOPT_SSL_VERIFYHOST=>2/); assert.match(php,/CURLOPT_FOLLOWLOCATION=>false/);
  const route=source('scripts/product-browser-test.php'); assert.ok(route.indexOf('->authenticate(')<route.indexOf('->dispatch('));
});
function harness() {
  const states=[];let cursor=0;const requests=[];const applications=[];
  const react={useState(initial){const i=cursor++;if(!(i in states))states[i]=typeof initial==='function'?initial():initial;return[states[i],v=>{states[i]=typeof v==='function'?v(states[i]):v;}];},useEffect(){}};
  const jsx=(type,props)=>({type,props});const m={exports:{}};
  vm.runInNewContext(ts.transpileModule(source('components/product-calculator.tsx'),{compilerOptions:{module:ts.ModuleKind.CommonJS,jsx:ts.JsxEmit.ReactJSX,target:ts.ScriptTarget.ES2022}}).outputText,
    {module:m,exports:m.exports,window:{confirm:()=>true},require(name){if(name==='react')return react;if(name==='react/jsx-runtime')return{jsx,jsxs:jsx};if(name==='@/lib/product-calculator')return c;
      if(name==='@/lib/product-browser-client')return{productRequest:async(route,...rest)=>{requests.push({route,rest});return{prices,loadedAt:'2026-10-29T12:00:00Z'};}};throw Error(name);}});
  return{requests,applications,render(){cursor=0;return m.exports.ProductCalculator({disabled:false,canApplyToCurrent:true,onApply:(...a)=>applications.push(a)});}};
}
function nodes(n,p){if(Array.isArray(n))return n.flatMap(x=>nodes(x,p));if(!n||typeof n!=='object')return[];return[...(p(n)?[n]:[]),...nodes(n.props?.children,p)];}
function text(n){if(Array.isArray(n))return n.map(text).join('');if(!n||typeof n!=='object')return String(n??'');return text(n.props?.children);}
test('Bedienung lädt Preise, rechnet und übergibt nur ausdrücklich – ohne Servermutation',async()=>{
  const h=harness();const button=(label)=>nodes(h.render(),n=>n.type==='button'&&text(n)===label)[0];
  button('Perlenpreisliste laden').props.onClick();await new Promise(r=>setImmediate(r));
  nodes(h.render(),n=>n.props?.['aria-label']==='Menge A')[0].props.onChange({target:{value:'3'}});
  assert.match(text(h.render()),/0,50 €/);assert.equal(h.applications.length,0);
  button('Als neuen Artikel übernehmen').props.onClick();assert.equal(h.applications.length,1);
  assert.deepEqual(plain(h.applications[0]),[{priceMinor:50,materials:['A'],metalElements:[]},true]);
  assert.deepEqual(h.requests,[{route:'/price-list',rest:[]}]);
});
