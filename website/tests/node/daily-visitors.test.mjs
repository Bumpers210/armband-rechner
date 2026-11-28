import assert from "node:assert/strict";
import test from "node:test";
import { readFileSync } from "node:fs";
import { webcrypto } from "node:crypto";
import { recordDailyVisitor, dailyVisitorChoice, hasDailyVisitorConsent, setDailyVisitorChoice, consentLifetimeSeconds } from "../../lib/daily-visitors.mjs";
import { assertDailyVisitorsPublication } from "../../lib/daily-visitors-publication.mjs";

function fixture() {
  const cookies = new Map();
  let queue = Promise.resolve();
  const f = { day: "2026-11-28", now: Date.parse("2026-11-28T12:00:00+01:00"),
    expiresAt: Date.parse("2026-11-29T00:00:00+01:00") / 1000, calls: [], writes: [], seen: new Set(), lostResponse: false };
  f.env = {
    enabled: true, now: () => f.now, cookie: () => {
      for (const [name, {expiry}] of cookies) if (expiry <= f.now) cookies.delete(name);
      return [...cookies].map(([name,{value}]) => name + "=" + value).join("; ");
    },
    setCookie: (text) => {
      f.writes.push(text);
      if (f.cookiesBlocked) return;
      const [pair,...attrs] = text.split(";").map(v=>v.trim());
      const eq = pair.indexOf("=");
      const name=pair.slice(0,eq), value=pair.slice(eq+1);
      const maxAge=attrs.find(v=>v.startsWith("Max-Age="))?.slice(8);
      const expires=attrs.find(v=>v.startsWith("Expires="))?.slice(8);
      const expiry=maxAge!==undefined ? f.now+Number(maxAge)*1000 : expires ? Date.parse(expires) : Infinity;
      assert.ok(attrs.includes("Secure")); assert.ok(attrs.includes("SameSite=Lax")); assert.ok(attrs.includes("Path=/"));
      assert.ok(!attrs.some(v=>v.startsWith("Domain=")));
      if (expiry <= f.now) cookies.delete(name); else cookies.set(name,{value,expiry});
    }, crypto: webcrypto,
    locks: { request: (_name, fn) => { const next = queue.then(fn); queue = next.catch(() => {}); return next; } },
    fetch: async (url, options) => {
      f.calls.push([url, options]);
      assert.equal(url, "/daily-visitor.php"); assert.equal(options.cache,"no-store");
      if (options.method !== "POST") return { status: 200, json: async () => ({ day: f.day, expiresAt:f.expiresAt, consentVersion: "daily-visitors-v2" }) };
      assert.deepEqual([...options.body.keys()].sort(), ["day","path","token"]);
      f.seen.add(options.body.get("day")+":"+options.body.get("token"));
      if (f.lostResponse) { f.lostResponse = false; throw new Error("Simulated connection loss after commit"); }
      return { status: 204 };
    },
  };
  f.cookies=cookies;
  setDailyVisitorChoice(f.env,"allow",f.now);
  return f;
}

test("same browser once per day across routes and ten simultaneous tabs", async () => {
  const f=fixture();
  await Promise.all(Array.from({length:10},(_,i)=>recordDailyVisitor(f.env,i%2?"/armbaender/":"/")));
  assert.equal(f.seen.size,1); assert.equal(f.calls.filter(([,o])=>o.method==="POST").length,1);
  await recordDailyVisitor(f.env,"/warenkorb/"); assert.equal(f.seen.size,1);
  f.now+=86400000; f.expiresAt+=86400; f.day="2026-11-29";
  await recordDailyVisitor(f.env,"/"); assert.equal(f.seen.size,2);
  assert.doesNotMatch(f.env.cookie(),/2026-11-28/);
});
test("daily cookie expires without a return visit; consent choice remains", async () => {
  const f=fixture(); await recordDailyVisitor(f.env,"/");
  assert.ok(f.cookies.has("carmaja_daily_visitor"));
  f.now=f.expiresAt*1000;
  assert.doesNotMatch(f.env.cookie(),/carmaja_daily_visitor/);
  assert.equal(dailyVisitorChoice(f.env.cookie(),f.now),"allow");
});
test("separate browsers have different identifiers; lost response reuses identifier", async () => {
  const a=fixture(),b=fixture();a.lostResponse=true;
  await recordDailyVisitor(a.env,"/"); await recordDailyVisitor(a.env,"/armbaender/"); await recordDailyVisitor(b.env,"/");
  assert.equal(a.seen.size,1); assert.notEqual([...a.seen][0],[...b.seen][0]);
  assert.equal(a.calls.filter(([,o])=>o.method==="POST").length,2);
});
test("no choice, rejection, disabled feature, blocked cookies and missing locks fail closed", async () => {
  for (const mode of ["none","deny","disabled","cookies","locks"]) {
    const f=fixture();
    if(mode==="none") f.cookies.clear();
    if(mode==="deny") setDailyVisitorChoice(f.env,"deny",f.now);
    if(mode==="disabled") f.env.enabled=false;
    if(mode==="cookies") f.cookiesBlocked=true;
    if(mode==="locks") f.env.locks=undefined;
    await recordDailyVisitor(f.env,"/"); assert.equal(f.seen.size,0); assert.ok(!f.cookies.has("carmaja_daily_visitor"));
    if(mode==="none"||mode==="deny"||mode==="disabled") assert.equal(f.calls.length,0);
  }
});
test("withdrawal clears daily cookie immediately and prevents another request", async () => {
  const f=fixture(); await recordDailyVisitor(f.env,"/");
  setDailyVisitorChoice(f.env,"deny",f.now);
  assert.ok(!f.cookies.has("carmaja_daily_visitor"));
  const count=f.calls.length; await recordDailyVisitor(f.env,"/");
  assert.equal(f.calls.length,count);
});
test("withdrawal during configuration or response prevents creation/restoration of daily cookie", async () => {
  for(const stage of ["GET","POST"]) {
    const f=fixture(),fetch=f.env.fetch;
    f.env.fetch=async(...args)=>{const r=await fetch(...args);if((args[1].method??"GET")===stage)setDailyVisitorChoice(f.env,"deny",f.now);return r;};
    await recordDailyVisitor(f.env,"/");
    assert.ok(!f.cookies.has("carmaja_daily_visitor")); assert.equal(f.seen.size,stage==="GET"?0:1);
  }
});

test("failed withdrawal storage blocks this page instead of continuing with an old cookie", async () => {
  const f=fixture(); await recordDailyVisitor(f.env,"/");
  f.cookiesBlocked=true;
  assert.equal(setDailyVisitorChoice(f.env,"deny",f.now),false);
  const before=f.calls.length;
  await recordDailyVisitor(f.env,"/armbaender/"); assert.equal(f.calls.length,before);
});
test("allow and deny have equal 180-day expiry; stale, future and old-version choices invalid", () => {
  for(const choice of ["allow","deny"]) {
    const f=fixture();setDailyVisitorChoice(f.env,choice,f.now);
    const cookie=f.env.cookie();
    assert.equal(dailyVisitorChoice(cookie,f.now),choice);
    assert.equal(dailyVisitorChoice(cookie,f.now-1000),null);
    assert.equal(dailyVisitorChoice(cookie,f.now+consentLifetimeSeconds*1000),null);
    assert.equal(dailyVisitorChoice(cookie.replace("v2","v1"),f.now),null);
    assert.equal(dailyVisitorChoice("other"+cookie,f.now),null);
  }
  assert.equal(hasDailyVisitorConsent("carmaja_analytics_consent=daily-visitors-v1"),false);
});
test("unsupported/malformed expiry and failed retention endpoint never count", async () => {
  for(const expiry of [0, Infinity, "2026-11-28", null, 9999999999]) {
    const f=fixture();f.expiresAt=expiry;await recordDailyVisitor(f.env,"/");assert.equal(f.seen.size,0);
  }
  const f=fixture();f.env.fetch=async()=>({status:503});await recordDailyVisitor(f.env,"/");assert.ok(!f.cookies.has("carmaja_daily_visitor"));
});
test("technical pages, query data and extra identifiers never counted", async () => {
  const f=fixture();
  for(const path of ["/admin/","/artikelverwaltung/","/statistik/","/?email=x","/../"])await recordDailyVisitor(f.env,path);
  assert.equal(f.calls.length,0);
  const source=readFileSync(new URL("../../lib/daily-visitors.mjs",import.meta.url),"utf8");
  assert.doesNotMatch(source,/localStorage|userAgent|fingerprint|screen\.|canvas|shopSession/i);
});
test("UI offers equal choices, direct footer withdrawal and no default-on activation", () => {
  const ui=readFileSync(new URL("../../components/statistics-consent.tsx",import.meta.url),"utf8");
  assert.match(ui,/onClick=\{\(\) => choose\("allow"\)\}>Statistik erlauben/);
  assert.match(ui,/onClick=\{\(\) => choose\("deny"\)\}>Statistik ablehnen/);
  assert.match(ui,/onClick=\{withdraw\}>Statistik-Zustimmung widerrufen/);
  assert.doesNotMatch(ui,/defaultChecked|checked=\{true\}|sendBeacon|onScroll/);
  const css=readFileSync(new URL("../../app/statistics-consent.css",import.meta.url),"utf8");
  assert.doesNotMatch(css,/position:\s*(fixed|absolute)|nth-child/);
  const config=readFileSync(new URL("../../next.config.ts",import.meta.url),"utf8");
  assert.match(config,/siteTarget === "production" && process.env.NEXT_PUBLIC_DAILY_VISITORS_ENABLED === "true"/);
  assert.match(config,/statisticsPolicy.status !== "approved"/);
  const policy=JSON.parse(readFileSync(new URL("../../content/daily-visitors-policy.json",import.meta.url)));
  assert.equal(policy.status,"approved_pending_operations");assert.equal(policy.version,"daily-visitors-v2");
  assert.equal(policy.consentDays,consentLifetimeSeconds/86400);
  const tracker=readFileSync(new URL("../../components/pageview-tracker.tsx",import.meta.url),"utf8");
  assert.match(tracker,/sendPageView\(pathname, source\)/);
});

test("production needs approved policy fully integrated in approved active privacy text", () => {
  const policy=JSON.parse(readFileSync(new URL("../../content/daily-visitors-policy.json",import.meta.url)));
  const bundle={status:"approved",texts:{privacy:policy.sections}};
  const options={enabled:true,target:"production",policy,bundle};
  assert.throws(()=>assertDailyVisitorsPublication(options),/Datenschutzfassung/);
  assert.doesNotThrow(()=>assertDailyVisitorsPublication({...options,enabled:false}));
  assert.doesNotThrow(()=>assertDailyVisitorsPublication({...options,target:"test"}));
  const approved={...options,policy:{...policy,status:"approved"}};
  assert.doesNotThrow(()=>assertDailyVisitorsPublication(approved));
  assert.throws(()=>assertDailyVisitorsPublication({...approved,bundle:{...bundle,texts:{privacy:[]}}}),/Datenschutzfassung/);
  const obsolete={heading:"Old",paragraphs:["Eigene Analyse-, Werbe- oder Newsletter-Cookies setzen wir nicht ein."]};
  assert.throws(()=>assertDailyVisitorsPublication({...approved,bundle:{...bundle,texts:{privacy:[...policy.sections,obsolete]}}}),/Datenschutzfassung/);
});
