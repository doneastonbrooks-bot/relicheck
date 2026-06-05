<?php
// ════════════════════════════════════════════════════════════════════════
//  Survey Builder V2 — the approved 2026-06 redesign front end.
//  Built as a NEW file so the live develop.php stays untouched until this is
//  wired to the real backend and verified, then the survey-dev entry repoints
//  here (same rollout pattern as mmstudioV4 / qual-studio-workspaceV4).
//  STATUS: shell + approved design in place. Data layer is still SAMPLE data;
//  wiring to api/dev (projects, items, the Build Check strength ticker, AI
//  help, publish, RSSI) is the next increment.
// ════════════════════════════════════════════════════════════════════════
require_once __DIR__ . '/api/_db.php';
require_once __DIR__ . '/api/_session.php';
start_session_secure();
$_dv_user     = current_user();
$_dv_name     = $_dv_user ? ($_dv_user['name'] ?? $_dv_user['email'] ?? '') : '';
$_dv_initials = strtoupper(substr(preg_replace('/[^A-Za-z]/', '', $_dv_name) ?: 'U', 0, 2));
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Survey Builder · ReliCheck</title>
<!--
  Survey Builder V2 — approved redesign. Plain/neutral; status color only where it
  carries meaning (the strength signal). Top stepper (Build > Launch > Analyze),
  left outline + ReliCheck Intelligence rail, the live STRENGTH TICKER (continuous
  quality, click "Review" for the full review), Coach pull-tab. Sample data for now.
-->
<style>
:root{
  --ink:#16181d; --ink-2:#54585f; --ink-3:#888c94;
  --bg:#fbfbfc; --panel:#ffffff; --soft:#f4f5f6;
  --line:rgba(0,0,0,.10); --line-2:rgba(0,0,0,.055);
  --pri:#1b1e25; --pri-2:#000;
  --good:#1f9e44; --good-soft:#eef6f0;
  --warn:#b07203; --warn-soft:#f7f2e8;
  --bad:#c1271f; --bad-soft:#f8ecea;
  --sh:0 1px 2px rgba(16,24,40,.05);
  --sh-lg:0 12px 38px rgba(16,24,40,.12);
  --r:14px; --r-sm:10px;
  --topbar-h:76px; --rail-w:328px; --companion:340px;
}
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
html,body{height:100%}
body{font-family:-apple-system,BlinkMacSystemFont,"SF Pro Text",Inter,system-ui,sans-serif;font-size:16px;background:var(--bg);color:var(--ink);-webkit-font-smoothing:antialiased;line-height:1.6}
button{font-family:inherit;cursor:pointer}
a{color:inherit;text-decoration:none}
.muted{color:var(--ink-2)} .faint{color:var(--ink-3)}

/* shell: top bar + left outline rail + wide main */
.app{display:grid;grid-template-rows:var(--topbar-h) 1fr auto;grid-template-columns:var(--rail-w) 1fr;height:100vh}
#studioFooter{grid-column:1/-1;grid-row:3}
.topbar{grid-column:1/-1;grid-row:1;display:grid;grid-template-columns:1fr auto 1fr;align-items:center;gap:16px;padding:0 26px;height:var(--topbar-h);background:var(--panel);border-bottom:1px solid var(--line);z-index:30}
body.start .rail{display:none}
body.start .main{grid-column:1/-1}
/* left outline rail (plain) */
.rail{grid-row:2;grid-column:1;background:var(--panel);border-right:1px solid var(--line);overflow-y:auto;padding:22px 16px;display:flex;flex-direction:column;gap:22px}
.rail-h{font-size:12px;font-weight:700;letter-spacing:.07em;text-transform:uppercase;color:var(--ink-3);margin-bottom:12px;display:flex;align-items:center;gap:8px}
.rail-h .cnt{margin-left:auto;font-weight:700}
.ol-item{display:flex;align-items:flex-start;gap:11px;padding:11px 11px;border-radius:9px;border:1px solid transparent;cursor:pointer;width:100%;background:none;text-align:left}
.ol-item:hover{background:var(--soft)}
.ol-item.active{background:var(--soft);border-color:var(--line)}
.ol-num{font-size:12.5px;font-weight:700;color:var(--ink-3);width:15px;flex-shrink:0;padding-top:1px}
.ol-txt{flex:1;font-size:14.5px;line-height:1.5;color:var(--ink-2);display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden}
.ol-dot{width:9px;height:9px;border-radius:50%;flex-shrink:0;margin-top:6px}
.ol-dot.up{background:var(--good)} .ol-dot.flat{background:var(--ink-3)} .ol-dot.down{background:var(--warn)}
.rail-link{display:block;width:100%;text-align:left;background:none;border:none;font-size:14.5px;font-weight:600;color:var(--ink-2);padding:9px 11px;border-radius:8px}
.rail-link:hover{background:var(--soft);color:var(--ink)}
.rail-foot{margin-top:auto;font-size:12.5px;color:var(--ink-3);line-height:1.55;padding-top:12px;border-top:1px solid var(--line)}
.brand{display:flex;align-items:center;justify-self:start}
.brand img{height:70px;width:auto;display:block}
.tb-right{display:flex;align-items:center;justify-content:flex-end;gap:14px}

/* stepper — words, spread out (plain) */
.steps{display:flex;align-items:center;gap:0}
.tb-step{display:flex;align-items:center;gap:9px;cursor:pointer;background:none;border:none;padding:4px 2px}
.tb-ind{width:9px;height:9px;border-radius:50%;border:1.5px solid var(--line);background:var(--panel);flex-shrink:0;transition:.15s}
.tb-step:hover .tb-ind{border-color:var(--ink-3)}
.tb-step.done .tb-ind{background:var(--ink);border-color:transparent}
.tb-step.active .tb-ind{background:var(--ink);border-color:transparent;box-shadow:0 0 0 3px rgba(16,24,40,.07)}
.tb-word{font-size:15px;font-weight:600;color:var(--ink-3);transition:color .15s;white-space:nowrap}
.tb-step:hover .tb-word{color:var(--ink-2)}
.tb-step.done .tb-word{color:var(--ink-2)}
.tb-step.active .tb-word{color:var(--ink);font-weight:750}
.tb-connector{width:88px;height:1.5px;background:var(--line);flex-shrink:0;transition:background .15s}
.tb-connector.done{background:var(--ink);opacity:.3}

/* the strength ticker (plain; one small status color) */
.ticker{display:flex;align-items:center;gap:13px;border:1px solid var(--line);background:var(--panel);padding:6px 12px 6px 16px;border-radius:11px;transition:.12s;position:relative;box-shadow:var(--sh)}
.ticker:hover{background:var(--soft);border-color:var(--ink-3)}
.ticker .tk-l{display:flex;flex-direction:column;line-height:1.15;text-align:right}
.ticker .tk-k{font-size:10.5px;font-weight:700;letter-spacing:.05em;text-transform:uppercase;color:var(--ink-3)}
.ticker .tk-w{font-size:13px;font-weight:700;color:var(--ink-2)}
.ticker .tk-n{font-size:23px;font-weight:800;letter-spacing:-.02em;min-width:32px;text-align:center;color:var(--ink)}
.ticker .tk-dot{width:9px;height:9px;border-radius:50%}
.ticker .tk-go{display:flex;align-items:center;gap:3px;font-size:11.5px;font-weight:700;color:var(--ink-3);border-left:1px solid var(--line);padding-left:12px;text-transform:uppercase;letter-spacing:.03em}
.ticker:hover .tk-go{color:var(--ink-2)}
.ticker .tk-go .cv{font-size:15px;font-weight:600}
.tk-dot.green{background:var(--good)} .tk-dot.amber{background:var(--warn)} .tk-dot.red{background:var(--bad)}
.tk-delta{position:absolute;top:-9px;right:13px;font-size:11.5px;font-weight:800;padding:1px 7px;border-radius:999px;animation:pop 1.4s ease forwards}
.tk-delta.up{color:var(--good);background:var(--good-soft)}
.tk-delta.down{color:var(--warn);background:var(--warn-soft)}
.tk-delta.flat{color:var(--ink-3);background:var(--soft)}
@keyframes pop{0%{transform:translateY(5px);opacity:0}20%{transform:translateY(0);opacity:1}74%{opacity:1}100%{transform:translateY(-6px);opacity:0}}
.avatar{width:30px;height:30px;border-radius:50%;background:var(--ink);color:#fff;display:grid;place-items:center;font-size:11.5px;font-weight:700;border:none}
.topbtn{border:1px solid var(--line);background:var(--panel);border-radius:9px;padding:8px 13px;font-size:13.5px;font-weight:650;color:var(--ink-2);white-space:nowrap}
.topbtn:hover{background:var(--soft);color:var(--ink)}

/* main: wide, breathing */
.main{grid-row:2;grid-column:2;overflow-y:auto;padding:48px 52px 110px}
.wrap{max-width:1060px;margin:0 auto}
.screen{animation:fade .22s ease}
@keyframes fade{from{opacity:0;transform:translateY(5px)}to{opacity:1;transform:none}}
.eyebrow{font-size:12px;font-weight:700;letter-spacing:.13em;text-transform:uppercase;color:var(--ink-3)}
h1.title{font-size:36px;font-weight:800;letter-spacing:-0.035em;margin:9px 0 14px}
.title-input{display:block;width:100%;max-width:760px;border:none;background:none;font-family:inherit;font-size:36px;font-weight:800;letter-spacing:-0.035em;color:var(--ink);padding:2px 0;margin:9px 0 12px;border-bottom:2px solid transparent}
.title-input::placeholder{color:var(--ink-3)}
.title-input:hover{border-bottom-color:var(--line)}
.title-input:focus{outline:none;border-bottom-color:var(--ink-3)}
.lede{font-size:18px;color:var(--ink-2);max-width:700px;margin-bottom:36px;line-height:1.65}
.sec-row{display:flex;align-items:baseline;gap:14px;margin:36px 0 18px}
h2.sec{font-size:20px;font-weight:750;letter-spacing:-0.01em}
.tlink{background:none;border:none;font-size:14.5px;font-weight:650;color:var(--ink-2);padding:0;border-bottom:1px solid var(--line)}
.tlink:hover{color:var(--ink);border-color:var(--ink-3)}

/* buttons */
.btn{display:inline-flex;align-items:center;gap:8px;border:1px solid var(--line);background:var(--panel);color:var(--ink);font-weight:600;font-size:15px;padding:11px 19px;border-radius:10px;transition:.12s}
.btn:hover{background:var(--soft)}
.btn.primary{background:var(--pri);border-color:var(--pri);color:#fff}
.btn.primary:hover{background:var(--pri-2)}
.btn.lg{padding:14px 26px;font-size:16px}
.btn.sm{padding:8px 14px;font-size:14px;border-radius:8px}
.btn-row{display:flex;gap:12px;flex-wrap:wrap;align-items:center;margin-top:36px}
.btn-row .spacer{flex:1}
.ailink{font-size:14.5px;font-weight:600;color:var(--ink-2);background:none;border:none;padding:6px 2px;border-bottom:1px solid transparent}
.ailink:hover{color:var(--ink);border-color:var(--ink-3)}
.card{background:var(--panel);border:1px solid var(--line);border-radius:var(--r);box-shadow:var(--sh)}
.card.pad{padding:26px}

/* start */
.entry{display:grid;grid-template-columns:repeat(3,1fr);gap:18px;margin-bottom:16px;max-width:1000px}
.entry-card{background:var(--panel);border:1px solid var(--line);border-radius:var(--r);padding:24px;text-align:left;display:flex;flex-direction:column;gap:11px;transition:.14s;box-shadow:var(--sh)}
.entry-card:hover{border-color:var(--ink-3);box-shadow:var(--sh-lg)}
.entry-card .ico{width:40px;height:40px;border-radius:11px;display:grid;place-items:center;background:var(--soft);color:var(--ink);font-size:19px}
.entry-card h3{font-size:17.5px;font-weight:750}
.entry-card p{font-size:14.5px;color:var(--ink-2);flex:1;line-height:1.6}
.entry-card .go{font-size:14px;font-weight:700;color:var(--ink)}
.recent{display:flex;gap:12px;flex-wrap:wrap;margin-top:8px}
.recent-pill{display:flex;align-items:center;gap:9px;border:1px solid var(--line);background:var(--panel);border-radius:10px;padding:10px 15px;font-size:14.5px;font-weight:600;box-shadow:var(--sh)}
.recent-pill:hover{border-color:var(--ink-3)}
.recent-pill .rdot{width:7px;height:7px;border-radius:50%;background:var(--good)}
.drop{border:2px dashed var(--line);border-radius:var(--r);padding:50px 24px;text-align:center;background:var(--panel);width:100%;cursor:pointer;max-width:760px}
.drop:hover{border-color:var(--ink-3);background:var(--soft)}
.drop .di{font-size:30px}

/* composer + questions (wide) */
.composer{background:var(--panel);border:1px solid var(--line);border-radius:var(--r);box-shadow:var(--sh);padding:22px 24px;margin-bottom:22px}
.composer .clbl{font-size:12.5px;font-weight:700;letter-spacing:.04em;text-transform:uppercase;color:var(--ink-3);margin-bottom:10px}
.composer textarea{width:100%;border:1.5px solid var(--line);border-radius:10px;padding:14px 16px;font-family:inherit;font-size:17px;resize:vertical;min-height:56px;color:var(--ink)}
.composer textarea:focus{outline:none;border-color:var(--ink-3)}
.composer .crow{display:flex;gap:12px;align-items:center;margin-top:14px;flex-wrap:wrap}
.composer select{border:1.5px solid var(--line);border-radius:9px;padding:11px 14px;font-family:inherit;font-size:14.5px;color:var(--ink);background:var(--panel)}
.composer select:focus{outline:none;border-color:var(--ink-3)}
.qcard{padding:20px 22px;border:1px solid var(--line);border-radius:var(--r);background:var(--panel);margin-bottom:14px;box-shadow:var(--sh);scroll-margin-top:20px}
.qhead{display:flex;gap:15px;align-items:flex-start}
.qn{width:26px;height:26px;border-radius:7px;background:var(--soft);color:var(--ink-3);font-size:12px;font-weight:700;display:grid;place-items:center;flex-shrink:0;margin-top:1px}
.qb{flex:1;min-width:0}
.qt{font-size:17px;font-weight:600;line-height:1.5}
.qmeta{font-size:13.5px;color:var(--ink-3);margin-top:6px;display:flex;align-items:center;gap:10px}
.qmark{display:inline-flex;align-items:center;gap:5px;font-size:13px;font-weight:650}
.qmark .md{width:7px;height:7px;border-radius:50%}
.qmark.up{color:var(--ink-2)} .qmark.up .md{background:var(--good)}
.qmark.flat{color:var(--ink-3)} .qmark.flat .md{background:var(--ink-3)}
.qmark.down{color:var(--warn)} .qmark.down .md{background:var(--warn)}
.qacts{display:flex;gap:6px}
.iconbtn{width:31px;height:31px;border-radius:8px;border:1px solid var(--line);background:var(--panel);color:var(--ink-3);display:grid;place-items:center;font-size:13px}
.iconbtn:hover{background:var(--soft);color:var(--ink)}
.qprev{margin:14px 0 0 41px;padding-top:14px;border-top:1px solid var(--line-2);max-width:560px}
.opt{display:flex;align-items:center;gap:11px;font-size:15.5px;color:var(--ink-2);padding:5px 0}
.opt .dot{width:17px;height:17px;border-radius:50%;border:1.5px solid var(--ink-3);flex-shrink:0}
.opt .sq{width:17px;height:17px;border-radius:4px;border:1.5px solid var(--ink-3);flex-shrink:0}
.scale{display:flex;gap:8px;margin-top:2px}
.scale span{width:34px;height:34px;border-radius:8px;border:1.5px solid var(--line);display:grid;place-items:center;font-size:14px;color:var(--ink-2);font-weight:600}
.prevtext{border:1.5px solid var(--line);border-radius:9px;padding:10px 12px;color:var(--ink-3);font-size:14.5px;max-width:360px}
.qedit{margin:14px 0 0 41px;padding-top:15px;border-top:1px solid var(--line-2)}
.qedit textarea{width:100%;max-width:620px;border:1.5px solid var(--ink);border-radius:10px;padding:11px 13px;font-family:inherit;font-size:15px;resize:vertical;min-height:48px;color:var(--ink)}
.qedit textarea:focus{outline:none}
.erow{display:flex;gap:11px;align-items:center;margin-top:12px;flex-wrap:wrap}
.qedit select{border:1.5px solid var(--line);border-radius:9px;padding:8px 11px;font-family:inherit;font-size:13px;color:var(--ink);background:var(--panel)}
.aihelp{margin-top:14px;padding:14px 16px;border:1px solid var(--line);background:var(--soft);border-radius:11px;max-width:620px}
.aihelp .ahl{font-size:12px;font-weight:700;letter-spacing:.05em;text-transform:uppercase;color:var(--ink-2)}
.aihelp ul{margin:8px 0 0 18px}
.aihelp li{font-size:14.5px;color:var(--ink-2);margin-top:6px;line-height:1.5}
.aihelp .ahacts{display:flex;gap:8px;margin-top:11px}

/* review drawer */
.scrim{position:fixed;inset:0;background:rgba(16,24,40,.32);opacity:0;pointer-events:none;transition:.18s;z-index:70}
.scrim.open{opacity:1;pointer-events:auto}
.review{position:fixed;top:0;right:0;height:100%;width:460px;max-width:94vw;background:var(--panel);border-left:1px solid var(--line);box-shadow:-14px 0 46px rgba(16,24,40,.16);transform:translateX(100%);transition:transform .24s cubic-bezier(.32,.72,0,1);z-index:75;display:flex;flex-direction:column}
.review.open{transform:none}
.rv-head{display:flex;align-items:center;gap:13px;padding:20px 22px;border-bottom:1px solid var(--line)}
.rv-head .rv-n{font-size:30px;font-weight:800;letter-spacing:-.02em;line-height:1;color:var(--ink)}
.rv-head .rv-w{font-size:15px;font-weight:750}
.rv-head .rv-sub{font-size:12px;color:var(--ink-3)}
.rv-head .cx{margin-left:auto;width:30px;height:30px;border-radius:8px;border:1px solid var(--line);background:var(--panel);color:var(--ink-3);font-size:16px}
.rv-head .cx:hover{background:var(--soft)}
.rv-body{padding:22px;overflow-y:auto;flex:1}
.fixitem{display:flex;gap:13px;align-items:flex-start;padding:15px 17px;border:1px solid var(--line);border-radius:var(--r-sm);background:var(--panel);margin-bottom:11px}
.fixitem .fi{width:24px;height:24px;border-radius:7px;background:var(--warn-soft);color:var(--warn);display:grid;place-items:center;flex-shrink:0;font-size:13px;font-weight:800}
.fixitem .ft{font-size:14.5px;font-weight:700;line-height:1.45}
.fixitem .fs{font-size:13.5px;color:var(--ink-2);margin-top:4px;line-height:1.55}
.fixitem .fa{font-size:13.5px;font-weight:700;color:var(--ink);background:none;border:none;padding:7px 0 0;border-bottom:1px solid var(--line)}
.fixitem .fa:hover{border-color:var(--ink-3)}
.allgood{display:flex;gap:12px;align-items:center;padding:17px;border-radius:var(--r-sm);background:var(--good-soft);font-size:14.5px;color:var(--ink);margin-bottom:14px;line-height:1.55}
.allgood .ag{color:var(--good);font-weight:800;font-size:16px}
.tech{border:1px solid var(--line);border-radius:var(--r-sm);background:var(--panel);margin-top:8px;overflow:hidden}
.tech summary{padding:14px 16px;font-size:13px;font-weight:700;color:var(--ink-2);cursor:pointer;list-style:none}
.tech summary::-webkit-details-marker{display:none}
.tech[open] summary{border-bottom:1px solid var(--line)}
.tech .tbody{padding:18px}
.dom{margin-bottom:14px}.dom:last-child{margin-bottom:0}
.dom-head{display:flex;justify-content:space-between;font-size:12.5px;margin-bottom:6px}
.dom-head .nm{font-weight:600}.dom-head .pts{color:var(--ink-3);font-weight:600}
.meter{height:6px;border-radius:999px;background:var(--soft);overflow:hidden}
.meter>span{display:block;height:100%;border-radius:999px;background:var(--ink)}

/* launch / analyze */
.notice{display:flex;gap:15px;align-items:flex-start;padding:20px 22px;border:1px solid var(--line);border-radius:var(--r);background:var(--panel);box-shadow:var(--sh);margin-bottom:18px;max-width:760px}
.notice .ni{width:34px;height:34px;border-radius:9px;background:var(--soft);color:var(--ink);display:grid;place-items:center;flex-shrink:0;font-size:16px}
.linkbox{display:flex;gap:10px;align-items:center;border:1px solid var(--line);border-radius:10px;padding:12px 14px;background:var(--soft);font-size:13.5px;margin-top:9px}
.linkbox code{flex:1;font-size:13px;color:var(--ink)}
.share{display:grid;grid-template-columns:repeat(3,1fr);gap:13px;margin:16px 0;max-width:560px}
.share button{border:1px solid var(--line);background:var(--panel);border-radius:var(--r-sm);padding:17px;display:flex;flex-direction:column;gap:7px;align-items:center;box-shadow:var(--sh)}
.share button:hover{border-color:var(--ink-3)}
.share .si{font-size:21px}.share .st{font-size:13px;font-weight:650;color:var(--ink)}
.result-card{border:1px solid var(--line);border-radius:var(--r);background:var(--panel);box-shadow:var(--sh);padding:22px 24px;margin-bottom:14px;max-width:780px}
.result-card h4{font-size:16.5px;font-weight:700;margin-bottom:15px}
.bar-row{display:flex;align-items:center;gap:13px;margin-bottom:10px;font-size:14.5px}
.bar-row .bl{width:150px;color:var(--ink-2);flex-shrink:0}
.bar-row .bm{flex:1;height:18px;border-radius:6px;background:var(--soft);overflow:hidden}
.bar-row .bm span{display:block;height:100%;background:var(--ink);border-radius:6px;opacity:.78}
.bar-row .bv{width:40px;text-align:right;color:var(--ink-3);font-weight:600}
.trust{display:flex;gap:13px;align-items:flex-start;padding:16px 18px;border-radius:var(--r-sm);margin-bottom:11px;font-size:14.5px;max-width:800px;line-height:1.6}
.trust.ok{background:var(--good-soft)} .trust.hold{background:var(--warn-soft)}
.trust .ti{font-size:16px;flex-shrink:0;font-weight:800}
.trust.ok .ti{color:var(--good)} .trust.hold .ti{color:var(--warn)}
.trust b{font-weight:700}

/* Coach pull-tab + companion (plain) */
.coach-tab{position:fixed;right:0;top:50%;transform:translateY(-50%);z-index:63;display:flex;align-items:center;justify-content:center;width:26px;height:120px;background:var(--panel);border:1px solid var(--line);border-right:none;border-radius:9px 0 0 9px;box-shadow:-2px 0 8px rgba(16,24,40,.05);transition:width .15s,background .15s,right .26s cubic-bezier(.32,.72,0,1)}
.coach-tab:hover{width:30px;background:var(--soft)}
.coach-tab .lbl{writing-mode:vertical-rl;transform:rotate(180deg);font-size:10px;font-weight:700;letter-spacing:.11em;text-transform:uppercase;color:var(--ink-3)}
body.coach-open .coach-tab{right:var(--companion);background:var(--soft)}
body.coach-open .coach-tab .lbl{color:var(--ink)}
.companion{position:fixed;top:0;right:0;bottom:0;width:var(--companion);z-index:62;background:var(--panel);border-left:1px solid var(--line);box-shadow:-12px 0 40px rgba(16,24,40,.1);transform:translateX(100%);transition:transform .26s cubic-bezier(.32,.72,0,1);display:flex;flex-direction:column}
body.coach-open .companion{transform:translateX(0)}
.comp-head{display:flex;align-items:center;gap:11px;padding:17px 20px 14px;border-bottom:1px solid var(--line)}
.comp-head .ci{width:28px;height:28px;border-radius:8px;background:var(--soft);color:var(--ink);display:grid;place-items:center}
.comp-head .ci svg{width:15px;height:15px}
.comp-head h3{font-size:15px;font-weight:750}
.comp-head .csub{font-size:12.5px;color:var(--ink-3)}
.comp-toggle{margin-left:auto;width:26px;height:26px;border-radius:7px;border:none;background:transparent;color:var(--ink-3);font-size:16px}
.comp-toggle:hover{background:var(--soft)}
.comp-tabs{display:flex;gap:5px;padding:11px 16px 0}
.comp-tab{flex:1;text-align:center;padding:9px 6px;border-radius:8px;font-size:13.5px;font-weight:700;color:var(--ink-3);background:none;border:none}
.comp-tab.active{background:var(--soft);color:var(--ink)}
.comp-body{padding:18px;overflow-y:auto;flex:1}
.ctx-chip{display:inline-flex;align-items:center;gap:6px;font-size:12px;font-weight:700;color:var(--ink-2);background:var(--soft);padding:5px 12px;border-radius:999px;margin-bottom:16px}
.cb{margin-bottom:17px}
.cb-k{font-size:11px;font-weight:700;letter-spacing:.05em;text-transform:uppercase;margin-bottom:7px;color:var(--ink-3)}
.cb-t{font-size:14.5px;line-height:1.62;color:var(--ink-2)}.cb-t b{color:var(--ink);font-weight:650}
.cb-why{background:var(--soft);border:1px solid var(--line);border-radius:11px;padding:13px 15px}
.cb-why .cb-k{color:var(--ink-2)}
.ai-chip{text-align:left;width:100%;border:1px solid var(--line);background:var(--panel);border-radius:10px;padding:12px 14px;font-size:13.5px;font-weight:600;color:var(--ink);cursor:pointer;margin-bottom:8px}
.ai-chip:hover{border-color:var(--ink-3);background:var(--soft)}
.ai-answer{border:1px solid var(--line);background:var(--soft);border-radius:11px;padding:13px 15px;font-size:13.5px;line-height:1.6;color:var(--ink-2);margin:2px 0 12px}
.ask-row{display:flex;gap:8px;margin-top:8px}
.ask-row input{flex:1;font-family:inherit;font-size:13px;background:var(--panel);border:1px solid var(--line);border-radius:9px;padding:10px 12px;outline:none}
.ask-row input:focus{border-color:var(--ink-3)}

/* grouping (constructs) */
.grp-chips{display:flex;gap:8px;flex-wrap:wrap;margin-bottom:11px}
.grp-chip{font-size:13.5px;font-weight:700;color:var(--ink);background:var(--soft);padding:6px 14px;border-radius:999px;border:1px solid var(--line)}
.grp-add{display:flex;gap:9px;margin-top:4px}
.grp-add input{flex:1;max-width:360px;border:1.5px solid var(--line);border-radius:9px;padding:11px 14px;font-family:inherit;font-size:15px}
.grp-add input:focus{outline:none;border-color:var(--ink-3)}
.assign-row{display:flex;align-items:center;gap:14px;padding:12px 0;border-top:1px solid var(--line-2)}
.assign-row .aq{flex:1;font-size:15px;color:var(--ink-2);line-height:1.5}
.assign-row select{border:1.5px solid var(--line);border-radius:8px;padding:9px 12px;font-family:inherit;font-size:14px;color:var(--ink);background:var(--panel)}
/* handoffs (Analyze → RSSI / studios) */
.handoff{display:grid;grid-template-columns:repeat(3,1fr);gap:14px;max-width:920px;margin-bottom:8px}
.handoff-card{text-align:left;border:1px solid var(--line);background:var(--panel);border-radius:var(--r);padding:18px 20px;box-shadow:var(--sh);transition:.13s;display:flex;flex-direction:column;gap:6px}
.handoff-card:hover{border-color:var(--ink-3);box-shadow:var(--sh-lg);transform:translateY(-2px)}
.hc-t{font-size:16px;font-weight:750}
.hc-d{font-size:14px;color:var(--ink-2);flex:1;line-height:1.5}
.hc-go{font-size:14px;font-weight:700;color:var(--ink);margin-top:4px}
@media(max-width:820px){.handoff{grid-template-columns:1fr}}
.toast{position:fixed;bottom:26px;left:50%;transform:translateX(-50%) translateY(20px);background:var(--ink);color:#fff;font-size:13.5px;font-weight:600;padding:11px 18px;border-radius:10px;box-shadow:var(--sh-lg);opacity:0;pointer-events:none;transition:.2s;z-index:120}
.toast.show{opacity:1;transform:translateX(-50%) translateY(0)}
@media(max-width:820px){.entry,.share{grid-template-columns:1fr}}
</style>
</head>
<body>

<div class="app">
  <div class="topbar">
    <a class="brand" href="/app-2026v4.php" aria-label="ReliCheck home"><img src="/SS-App-long.png?v=<?= is_file(__DIR__.'/SS-App-long.png') ? filemtime(__DIR__.'/SS-App-long.png') : '1' ?>" alt="ReliCheck Survey Builder"></a>
    <div id="stepsWrap"></div>
    <div class="tb-right" id="tbRight"><button class="avatar"><?= htmlspecialchars($_dv_initials) ?></button></div>
  </div>
  <nav class="rail" id="rail"></nav>
  <main class="main"><div class="wrap" id="app"></div></main>
  <!-- Shared ReliCheck studio footer (studio-footer.js, grid row 3) -->
  <div id="studioFooter"></div>
</div>

<button class="coach-tab" onclick="toggleCoach()"><span class="lbl">Coach</span></button>
<aside class="companion" id="companion">
  <div class="comp-head">
    <span class="ci"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 18h6M10 21h4M12 2a7 7 0 0 0-4 12.7c.6.5 1 1.3 1 2.1V17h6v-.2c0-.8.4-1.6 1-2.1A7 7 0 0 0 12 2z"/></svg></span>
    <div><h3>Coach</h3><div class="csub" id="compSub">Guidance for this step</div></div>
    <button class="comp-toggle" onclick="toggleCoach()">&times;</button>
  </div>
  <div class="comp-tabs">
    <button class="comp-tab" id="ctGuide" onclick="setCoachTab('guide')">Guidance</button>
    <button class="comp-tab" id="ctAsk" onclick="setCoachTab('ask')">Ask</button>
  </div>
  <div class="comp-body" id="compBody"></div>
</aside>

<div class="scrim" id="scrim" onclick="closeReview()"></div>
<aside class="review" id="review"></aside>
<div class="toast" id="toast"></div>

<!-- Real Build Check (SDSI) engine — the strength ticker reads this client-side -->
<script src="/apps/sdsi/validity-lens-engine.js?v=<?= is_file(__DIR__.'/apps/sdsi/validity-lens-engine.js') ? filemtime(__DIR__.'/apps/sdsi/validity-lens-engine.js') : '1' ?>"></script>
<script src="/apps/sdsi/buildcheck-engine.js?v=<?= is_file(__DIR__.'/apps/sdsi/buildcheck-engine.js') ? filemtime(__DIR__.'/apps/sdsi/buildcheck-engine.js') : '1' ?>"></script>
<script src="/apps/studio/studio-footer.js?v=<?= is_file(__DIR__.'/apps/studio/studio-footer.js') ? filemtime(__DIR__.'/apps/studio/studio-footer.js') : '1' ?>"></script>
<script>
const state={
  screen:'start',startFlow:null,phase:'build',
  study:{name:'Freshman Enrollment',purpose:'Understand why admitted students chose to enroll, and what nearly sent them to another school',population:'Admitted first-year (freshman) students',mode:'',dataType:'',launchReadiness:{}},
  coachOpen:false,coachTab:'guide',askOpen:null,
  reviewOpen:false,editing:null,aiHelp:null,responses:0,lastDelta:null,prevStrength:null,grouping:false,groups:[],bc:null,
  questions:[
    {t:'What is your intended major?',type:'Multiple choice',options:['Biology','Business','Engineering','Undecided']},
    {t:'How did you first hear about us?',type:'Multiple choice',options:['Friend or family','Social media','College fair','Web search']},
    {t:'Was the enrollment process clear and did you feel supported the whole way through?',type:'Rating scale',options:null},
  ],
};
const PHASES=[{id:'build',t:'Build'},{id:'launch',t:'Launch'},{id:'analyze',t:'Analyze'}];
const QTYPES=['Multiple choice','Checkboxes','Rating scale','Yes / No','Short text','Long comment','Number'];

/* ── Quality model — driven by the real Build Check (SDSI) engine.
   The engine (apps/sdsi/buildcheck-engine.js) runs client-side. Heuristic
   functions below are a fallback used ONLY if the engine fails to load. ── */
function buildCheckProject(){
  const s=state.study||{},qs=state.questions||[];
  return {
    purpose:s.purpose||'',population:s.population||'',mode:s.mode||'',dataType:s.dataType||'',
    launchReadiness:s.launchReadiness||{},
    constructs:(state.groups||[]).map(g=>({name:g,definition:''})),
    items:qs.map((q,i)=>({item_ref:(q.id!=null?('q'+q.id):('i'+i)),item_no:i+1,type:q.type||'',prompt:q.t||'',options:q.options||[],settings:q.settings||{},construct:q.group||'',required:!!q.required})),
    sections:[]
  };
}
function assessNow(){ try{ if(window.BuildCheck&&window.BuildCheck.assess) return window.BuildCheck.assess(buildCheckProject()); }catch(e){} return null; }
function refOf(q,i){ return q.id!=null?('q'+q.id):('i'+i); }
function liveStrength(){ const r=assessNow(); return (r&&typeof r.pct==='number')?Math.round(r.pct):strengthHeuristic(state.questions); }
function strengthValue(){ return (state.bc&&typeof state.bc.pct==='number')?Math.round(state.bc.pct):strengthHeuristic(state.questions); }
function bandOf(s){if(s>=85)return{w:'Strong',c:'green'};if(s>=70)return{w:'Good',c:'green'};if(s>=55)return{w:'Fair',c:'amber'};return{w:'Needs work',c:'red'};}
function markOf(q,i){
  if(state.bc&&state.bc.flags){
    const ref=refOf(q,i),fs=state.bc.flags.filter(f=>f.item_ref===ref);
    if(fs.some(f=>f.severity==='critical'||f.severity==='major'))return 'down';
    if(fs.some(f=>f.severity==='moderate'))return 'flat';
    return 'up';
  }
  const v=qualityHeuristic(q);return v>=80?'up':(v>=65?'flat':'down');
}
function reviewItems(){
  if(state.bc&&state.bc.flags){
    const by={};
    state.bc.flags.forEach(f=>{ if(!f.item_ref)return; if(!['moderate','major','critical'].includes(f.severity))return; (by[f.item_ref]=by[f.item_ref]||[]).push(f); });
    return state.questions.map((q,i)=>({q,i,ref:refOf(q,i)})).filter(x=>by[x.ref]).map(x=>({q:x.q,i:x.i,msg:(by[x.ref][0].message||'Worth a look.'),fix:(by[x.ref][0].suggestion||'')}));
  }
  return state.questions.map((q,i)=>({q,i})).filter(x=>markHeuristic(x.q)==='down').map(x=>({q:x.q,i:x.i,msg:issueHeuristic(x.q),fix:''}));
}
function qualityHeuristic(q){
  let s=86;const text=(q.t||'').trim();const words=(text.match(/\b[\w']+\b/g)||[]).length;
  if(/\band\b/i.test(text)&&words>6)s-=26; if(/\bor\b/i.test(text)&&words>9)s-=8;
  if(words>22)s-=12; if(words<3)s-=18; if(!/[?]$/.test(text))s-=6;
  if(['Multiple choice','Checkboxes'].includes(q.type)){const n=(q.options||[]).length;if(n>=3)s+=4;else if(n<2)s-=10;}
  return Math.max(28,Math.min(98,s));
}
function strengthHeuristic(qs){if(!qs.length)return 0;let s=qs.reduce((a,q)=>a+qualityHeuristic(q),0)/qs.length;if(qs.length>=4)s+=3;if(qs.length>=8)s+=2;return Math.round(Math.max(0,Math.min(100,s)));}
function markHeuristic(q){const v=qualityHeuristic(q);return v>=80?'up':(v>=65?'flat':'down');}
function issueHeuristic(q){const text=(q.t||'').trim();const words=(text.match(/\b[\w']+\b/g)||[]).length;
  if(/\band\b/i.test(text)&&words>6)return'Asks two things at once (it has an "and"). Split it so the answers stay clean.';
  if(words>22)return'A bit long. Shorter questions are answered more honestly.';
  if(words<3)return'Very short. Add enough that a respondent knows exactly what you mean.';
  return'Could be a little clearer.';}

const $=s=>document.querySelector(s);
function toast(m){const t=$('#toast');t.textContent=m;t.classList.add('show');clearTimeout(window._t);window._t=setTimeout(()=>t.classList.remove('show'),1900);}
function esc(s){return(s||'').replace(/[&<>"]/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]));}
function cap(s){return s.charAt(0).toUpperCase()+s.slice(1);}
function defaultOptions(t){if(t==='Multiple choice'||t==='Checkboxes')return['Option 1','Option 2','Option 3'];if(t==='Yes / No')return['Yes','No'];return null;}
function go(phase){state.phase=phase;state.editing=null;state.aiHelp=null;render();}
function withTicker(fn){const before=liveStrength();fn();const after=liveStrength();state.lastDelta={amount:after-before,dir:after>before?'up':(after<before?'down':'flat')};state.prevStrength=after;}

/* ── Persistence — mirrors develop.php's proven api/dev DB layer.
   PERSIST.on when logged in; ?mock forces the offline demo (sample data). ── */
const PERSIST_REQUESTED = !new URLSearchParams(location.search).has('mock');
const PERSIST = { on:PERSIST_REQUESTED, degraded:false, reason:'' };
const LS_KEY = 'sds_v2_project_id';
function degrade(reason){ if(!PERSIST.degraded){ PERSIST.on=false; PERSIST.degraded=true; PERSIST.reason=reason||''; toast('Working offline — changes are not being saved'); } }
const DB={
  async call(path,opts={}){
    const res=await fetch('/api/dev/'+path,{method:opts.method||'GET',headers:opts.body?{'Content-Type':'application/json'}:undefined,body:opts.body?JSON.stringify(opts.body):undefined,credentials:'same-origin'});
    let data=null; try{ data=await res.json(); }catch(e){}
    if(!res.ok||!data||data.ok===false){ const msg=(data&&(data.message||data.error))||('HTTP '+res.status); const err=new Error(msg); err.status=res.status; throw err; }
    if(PERSIST_REQUESTED&&!PERSIST.on){ PERSIST.on=true; PERSIST.degraded=false; PERSIST.reason=''; }
    return data;
  },
  // q.group is the construct name; it rides in the item settings JSON (no construct column).
  itemSettingsOut(q){ const s=Object.assign({},q.settings||{}); delete s.construct; delete s.constructId; if(q.group)s.construct=q.group; return Object.keys(s).length?s:null; },
  itemsWire(){ return (state.questions||[]).map(q=>({ id:q.id, section_id:q.section_id||null, type:q.type, prompt:q.t, flag:q.flag||null, required:q.required?1:0, options:q.options||null, settings:DB.itemSettingsOut(q) })); },
  itemHydrate(it){ const s=it.settings||{}; const q={ id:it.id, t:it.prompt, type:it.type, flag:it.flag, section_id:it.section_id, required:!!it.required, options:it.options||null, settings:it.settings||null, group:'' }; if(s.construct)q.group=s.construct; return q; },
  constructsWire(){ return (state.groups||[]).map((g,i)=>({ id:null, name:g, definition:'', position:i })); },
  hydrate(payload){
    const p=payload.project;
    state.projectId=p.id;
    state.study.name=p.title||''; state.study.purpose=p.purpose||''; state.study.population=p.population||'';
    state.study.mode=p.response_mode||''; state.study.dataType=p.data_type||'';
    state.settings=p.settings||{}; state.study.launchReadiness=(p.settings&&p.settings.launchReadiness)||{};
    state.groups=(payload.constructs||[]).map(c=>c.name).filter(Boolean);
    state.questions=(payload.items||[]).map(it=>DB.itemHydrate(it));
    state.responses=payload.responses||0;
    state.deploymentSettings=payload.deployment||null;
    try{ localStorage.setItem(LS_KEY,String(p.id)); }catch(e){}
  },
};
async function createProject(source){
  const r=await DB.call('project-create.php',{method:'POST',body:{
    title:state.study.name||'Untitled survey', source:source||'scratch',
    purpose:state.study.purpose||'', population:state.study.population||'',
    response_mode:state.study.mode||'', data_type:state.study.dataType||'',
    sections:[{title:'Main'}],
    items:(state.questions||[]).map(q=>({ type:q.type, prompt:q.t, flag:q.flag||null, required:q.required?1:0, options:q.options||null, settings:DB.itemSettingsOut(q) })),
    constructs:(state.groups||[]).map(g=>({name:g,definition:''})),
  }});
  DB.hydrate(r);
}
function persistItems(){ if(PERSIST.on&&state.projectId) saveItemsNow(); }
async function saveItemsNow(){
  if(!(PERSIST.on&&state.projectId))return;
  try{
    const r=await DB.call('items-save.php',{method:'POST',body:{project_id:state.projectId,items:DB.itemsWire()}});
    state.questions=(r.items||[]).map(it=>DB.itemHydrate(it));
    await saveConstructsNow();
    if(state.screen==='workspace'&&state.editing==null)render();
  }catch(e){ degrade(e.message); }
}
async function saveConstructsNow(){
  if(!(PERSIST.on&&state.projectId))return;
  try{ const r=await DB.call('constructs-save.php',{method:'POST',body:{project_id:state.projectId,constructs:DB.constructsWire()}}); state.groups=(r.constructs||[]).map(c=>c.name).filter(Boolean); }catch(e){ degrade(e.message); }
}
let _titleTimer=null;
function setStudyName(v){ state.study.name=v; if(!(PERSIST.on&&state.projectId))return; clearTimeout(_titleTimer); _titleTimer=setTimeout(()=>{ DB.call('project-update.php',{method:'POST',body:{id:state.projectId,title:state.study.name}}).catch(e=>degrade(e.message)); },500); }
// Always land on Start (never trap the user inside the last project). Resume is
// offered on the Start screen when a saved project exists.
function boot(){ render(); if(typeof StudioFooter!=='undefined')StudioFooter.init(); }
function savedProjectId(){ try{ return Number(localStorage.getItem(LS_KEY))||null; }catch(e){ return null; } }
function goStart(){ state.screen='start'; state.startFlow=null; render(); }
async function resumeLast(){
  const id=savedProjectId(); if(!id)return;
  try{ const r=await DB.call('project-load.php?id='+encodeURIComponent(id)); DB.hydrate(r); state.screen='workspace'; state.phase='build'; state.prevStrength=liveStrength(); render(); }
  catch(e){ try{localStorage.removeItem(LS_KEY);}catch(_){} degrade(e.message); render(); }
}

function render(){
  document.body.classList.toggle('start',state.screen==='start');
  if(state.screen==='start'){$('#stepsWrap').innerHTML='';$('#tbRight').innerHTML='<button class="avatar"><?= htmlspecialchars($_dv_initials) ?></button>';$('#rail').innerHTML='';renderStart();paintCoach();paintReview();return;}
  state.bc=assessNow();
  renderSteps();renderTicker();renderRail();
  const fn={build:viewBuild,launch:viewLaunch,analyze:viewAnalyze}[state.phase];
  $('#app').innerHTML=`<div class="screen">${fn()}</div>`;
  paintCoach();paintReview();
}
function renderSteps(){
  const idx=PHASES.findIndex(p=>p.id===state.phase);
  $('#stepsWrap').innerHTML=`<div class="steps">`+PHASES.map((p,i)=>{
    const cls=i===idx?'active':(i<idx?'done':'');
    const conn=i<PHASES.length-1?`<span class="tb-connector ${i<idx?'done':''}"></span>`:'';
    return `<button class="tb-step ${cls}" onclick="go('${p.id}')"><span class="tb-ind"></span><span class="tb-word">${p.t}</span></button>${conn}`;
  }).join('')+`</div>`;
}
function renderRail(){
  const qs=state.questions;
  const outline=qs.length?qs.map((q,i)=>{const m=markOf(q,i);return `<button class="ol-item ${state.editing===i?'active':''}" onclick="jumpTo(${i})"><span class="ol-num">${i+1}</span><span class="ol-txt">${esc(q.t)}</span><span class="ol-dot ${m}"></span></button>`;}).join(''):`<div class="faint" style="font-size:12.5px;padding:4px 2px">No questions yet.</div>`;
  $('#rail').innerHTML=`
    <div><div class="rail-h">Survey outline <span class="cnt">${qs.length}</span></div>${outline}</div>
    <div><div class="rail-h">ReliCheck Intelligence</div>
      <button class="rail-link" onclick="suggestQ()">Suggest a question</button>
      <button class="rail-link" onclick="improveWeakest()">Improve the weakest</button>
      <button class="rail-link" onclick="openCoachAsk()">Ask about my survey</button>
    </div>
    <div><div class="rail-h">Advanced <span class="cnt" style="font-weight:600;text-transform:none;letter-spacing:0;color:var(--ink-3)">optional</span></div>
      <button class="rail-link" onclick="openGrouping()">Group questions by meaning</button>
      <div class="faint" style="font-size:12.5px;line-height:1.5;padding:3px 11px 0">For measuring one thing (a construct) across several questions. New to it? The Coach explains it.</div>
    </div>
    <div class="rail-foot">The strength reading updates as you build. A weak question shows a colored dot here so you can find it.</div>`;
}
function openCoachAsk(){state.coachOpen=true;state.coachTab='ask';document.body.classList.add('coach-open');paintCoach();}
function renderTicker(){
  const s=strengthValue(),b=bandOf(s),d=state.lastDelta;
  const delta=d?`<span class="tk-delta ${d.dir}">${d.dir==='flat'?'no change':(d.amount>0?'+'+d.amount:d.amount)}</span>`:'';
  $('#tbRight').innerHTML=`
    <button class="topbtn" onclick="goStart()" title="Start or open another survey">＋ New survey</button>
    <button class="ticker" onclick="openReview()" title="Click for the full review">${delta}
      <span class="tk-l"><span class="tk-k">Strength</span><span class="tk-w">${b.w}</span></span>
      <span class="tk-dot ${b.c}"></span><span class="tk-n">${s}</span>
      <span class="tk-go">Review<span class="cv">›</span></span>
    </button>
    <button class="avatar"><?= htmlspecialchars($_dv_initials) ?></button>`;
}

/* start */
function recentSection(){
  if(PERSIST.on){
    return savedProjectId()
      ? `<div class="sec-row"><h2 class="sec">Pick up where you left off</h2></div><div class="recent"><button class="recent-pill" onclick="resumeLast()"><span class="rdot"></span>Resume your last survey →</button></div>`
      : '';
  }
  return `<div class="sec-row"><h2 class="sec">Pick up where you left off</h2></div><div class="recent"><button class="recent-pill" onclick="enter()"><span class="rdot"></span>Freshman Enrollment <span class="faint">· draft</span></button><button class="recent-pill" onclick="enter()"><span class="rdot" style="background:var(--ink-3)"></span>Staff Pulse 2026 <span class="faint">· launched</span></button></div>`;
}
function renderStart(){
  if(state.startFlow==='ai')return renderAiGoal();
  if(state.startFlow==='upload')return renderUpload();
  $('#app').innerHTML=`<div class="screen">
    <div class="eyebrow">New survey</div>
    <h1 class="title">How would you like to start?</h1>
    <p class="lede">Pick a starting point. You can change course any time, and ReliCheck guides you the whole way.</p>
    <div class="entry">
      <button class="entry-card" onclick="enter()"><div class="ico">✎</div><h3>Build it myself</h3><p>A clean workspace. Add questions one at a time, with help on tap whenever you want it.</p><span class="go">Start building →</span></button>
      <button class="entry-card" onclick="state.startFlow='ai';render()"><div class="ico">✨</div><h3>Help me build it</h3><p>Tell ReliCheck your goal and it drafts the questions for you to review and adjust.</p><span class="go">Get a draft →</span></button>
      <button class="entry-card" onclick="state.startFlow='upload';render()"><div class="ico">⤓</div><h3>I already have one</h3><p>Upload from Google Forms, SurveyMonkey, Qualtrics, or a spreadsheet.</p><span class="go">Upload it →</span></button>
    </div>
    <a class="ailink" href="#" onclick="return false">Or browse ready-made templates →</a>
    ${recentSection()}
  </div>`;
}
function renderAiGoal(){
  $('#app').innerHTML=`<div class="screen">
    <div class="eyebrow">Help me build it</div>
    <h1 class="title">What do you want to learn?</h1>
    <p class="lede">Describe your goal in a sentence or two. ReliCheck drafts a first survey you can review and adjust. Nothing is locked in.</p>
    <div class="card pad" style="max-width:680px">
      <textarea id="aiGoal" style="width:100%;border:1.5px solid var(--line);border-radius:10px;padding:13px 15px;font-family:inherit;font-size:15.5px;min-height:96px;resize:vertical">I want to understand why admitted students chose to enroll, and what almost made them choose another school.</textarea>
      <div class="btn-row" style="margin-top:18px"><button class="btn" onclick="state.startFlow=null;render()">← Back</button><div class="spacer"></div><button class="btn primary lg" onclick="aiDraft()">Draft my survey →</button></div>
    </div></div>`;
}
function aiDraft(){
  const goal=(document.getElementById('aiGoal')||{}).value||'';
  $('#app').innerHTML=`<div class="screen"><div class="card pad" style="text-align:center;max-width:520px;margin:50px auto"><p class="muted" style="font-size:15px">Drafting your survey…</p></div></div>`;
  setTimeout(async()=>{
    state.questions=[
      {t:'How confident did you feel about your decision to enroll here?',type:'Rating scale',options:null},
      {t:'What was the single biggest reason you chose us?',type:'Long comment',options:null},
      {t:'Which of these did you weigh before deciding? (Choose all that apply)',type:'Checkboxes',options:['Cost','Location','Program reputation','Financial aid','Campus visit']},
      {t:'How clear was the application process?',type:'Rating scale',options:null},
      {t:'What is your intended major?',type:'Multiple choice',options:['Biology','Business','Engineering','Undecided']},
    ];
    state.startFlow=null; state.groups=[];
    if(PERSIST.on){ state.study={name:'Survey from your goal',purpose:goal,population:'',mode:'',dataType:'',launchReadiness:{}}; try{ await createProject('ai-build'); }catch(e){ degrade(e.message); } }
    state.screen='workspace';state.phase='build';state.prevStrength=liveStrength();
    render();toast('ReliCheck drafted 5 questions. Review and adjust.');
  },800);
}
function renderUpload(){
  $('#app').innerHTML=`<div class="screen">
    <div class="eyebrow">I already have one</div>
    <h1 class="title">Bring in your survey</h1>
    <p class="lede">Upload a CSV or Excel export. ReliCheck detects the format and turns it into editable questions.</p>
    <button class="drop" onclick="uploadDone()"><div class="di">⤓</div><div style="font-weight:700;font-size:15px;margin-top:10px">Drop a file here, or click to choose</div><div class="faint" style="font-size:13px;margin-top:5px">CSV, Excel, Google Forms, SurveyMonkey, Qualtrics</div></button>
    <div class="btn-row"><button class="btn" onclick="state.startFlow=null;render()">← Back</button></div></div>`;
}
async function uploadDone(){
  state.questions=[
    {t:'Overall, how satisfied are you with your experience?',type:'Rating scale',options:null},
    {t:'How likely are you to recommend us to a friend?',type:'Rating scale',options:null},
    {t:'What could we have done better?',type:'Long comment',options:null},
  ];
  state.startFlow=null; state.groups=[];
  if(PERSIST.on){ state.study={name:'Imported survey',purpose:'',population:'',mode:'',dataType:'',launchReadiness:{}}; try{ await createProject('existing'); }catch(e){ degrade(e.message); } }
  state.screen='workspace';state.phase='build';state.prevStrength=liveStrength();
  render();toast('Brought in 3 questions from your file.');
}
async function enter(){
  state.startFlow=null;
  if(PERSIST.on){
    state.questions=[]; state.groups=[];
    state.study={name:'Untitled survey',purpose:'',population:'',mode:'',dataType:'',launchReadiness:{}};
    try{ await createProject('scratch'); }catch(e){ degrade(e.message); }
  }
  state.screen='workspace';state.phase='build';state.prevStrength=liveStrength();render();
}

/* Build */
function answerPreview(q){
  const t=q.type;
  if(t==='Multiple choice')return(q.options||['Option 1','Option 2']).map(o=>`<div class="opt"><span class="dot"></span>${esc(o)}</div>`).join('');
  if(t==='Checkboxes')return(q.options||['Option 1','Option 2']).map(o=>`<div class="opt"><span class="sq"></span>${esc(o)}</div>`).join('');
  if(t==='Yes / No')return['Yes','No'].map(o=>`<div class="opt"><span class="dot"></span>${o}</div>`).join('');
  if(t==='Rating scale')return`<div class="scale">${[1,2,3,4,5].map(n=>`<span>${n}</span>`).join('')}</div><div class="faint" style="font-size:12px;margin-top:6px">Not at all → Completely</div>`;
  if(t==='Short text')return`<div class="prevtext">Short answer…</div>`;
  if(t==='Long comment')return`<div class="prevtext" style="min-height:44px">Longer answer…</div>`;
  if(t==='Number')return`<div class="prevtext" style="max-width:120px">0</div>`;
  return'';
}
function viewBuild(){
  if(state.grouping)return viewGrouping();
  const qs=state.questions;
  const weakCount=qs.filter((q,i)=>markOf(q,i)==='down').length;
  const list=qs.map((q,i)=>state.editing===i?editorCard(q,i):displayCard(q,i)).join('');
  return `
    <div class="eyebrow">Build</div>
    <input class="title title-input" value="${esc(state.study.name||'Untitled survey')}" placeholder="Untitled survey" oninput="setStudyName(this.value)" aria-label="Survey title">
    <p class="lede">Add your questions one at a time. Watch the strength reading at the top, it moves with each question so you can fix a weak one as you go, not at the end.</p>
    <div class="composer">
      <div class="clbl">Add a question</div>
      <textarea id="qtext" placeholder="Type your question here…"></textarea>
      <div class="crow">
        <select id="qtype">${QTYPES.map(t=>`<option>${t}</option>`).join('')}</select>
        <button class="btn primary sm" onclick="addQ()">Add question</button>
        <button class="ailink" onclick="suggestQ()">Let ReliCheck suggest one</button>
      </div>
    </div>
    <div class="sec-row">
      <h2 class="sec">${qs.length} question${qs.length===1?'':'s'} in your survey</h2>
      ${weakCount?`<button class="tlink" onclick="improveWeakest()">${weakCount} need${weakCount===1?'s':''} a look — improve ${weakCount===1?'it':'them'}</button>`:''}
    </div>
    ${list||'<p class="muted">No questions yet. Add your first one above.</p>'}
    <div class="btn-row"><div class="spacer"></div><button class="btn primary lg" onclick="go('launch')">Ready to launch →</button></div>`;
}
function displayCard(q,i){
  const m=markOf(q,i),lbl=m==='up'?'on track':(m==='down'?'pulling it down':'neutral');
  return `<div class="qcard" id="qc-${i}">
    <div class="qhead">
      <span class="qn">${i+1}</span>
      <div class="qb"><div class="qt">${esc(q.t)}</div><div class="qmeta">${esc(q.type)} <span class="qmark ${m}"><span class="md"></span>${lbl}</span>${q.group?` · <span style="font-weight:650;color:var(--ink-2)">${esc(q.group)}</span>`:''}</div></div>
      <div class="qacts"><button class="iconbtn" title="Edit" onclick="editQ(${i})">✎</button><button class="iconbtn" title="Remove" onclick="removeQ(${i})">✕</button></div>
    </div>
    <div class="qprev">${answerPreview(q)}</div>
  </div>`;
}
function editorCard(q,i){
  const optTypes=['Multiple choice','Checkboxes'];
  const optEditor=optTypes.includes(q.type)?`
    <div style="margin-top:12px"><div class="faint" style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.05em;margin-bottom:5px">Answer options</div>
    ${(q.options||[]).map((o,oi)=>`<div style="display:flex;gap:8px;align-items:center;margin-top:8px"><input style="flex:1;max-width:340px;border:1.5px solid var(--line);border-radius:8px;padding:8px 11px;font-family:inherit;font-size:14px" value="${esc(o)}" oninput="setOpt(${i},${oi},this.value)"><button class="iconbtn" onclick="delOpt(${i},${oi})">✕</button></div>`).join('')}
    <button class="ailink" onclick="addOpt(${i})" style="margin-top:8px">+ Add option</button></div>`:'';
  const help=(state.aiHelp&&state.aiHelp.i===i)?aiHelpBox(state.aiHelp):'';
  return `<div class="qcard" id="qc-${i}" style="border-color:var(--ink-3)">
    <div class="qhead"><span class="qn">${i+1}</span><div class="qb" style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:var(--ink-2);padding-top:5px">Editing question ${i+1}</div></div>
    <div class="qedit">
      <textarea id="editText" oninput="setEditText(${i},this.value)">${esc(q.t)}</textarea>
      <div class="erow">
        <select onchange="setType(${i},this.value)">${QTYPES.map(t=>`<option ${t===q.type?'selected':''}>${t}</option>`).join('')}</select>
        <button class="ailink" onclick="improveWording(${i})">Improve wording</button>
        <button class="ailink" onclick="checkClarity(${i})">Check clarity</button>
      </div>
      ${optEditor}${help}
      <div class="erow" style="margin-top:15px"><button class="btn primary sm" onclick="saveEdit(${i})">Done</button><button class="btn sm" onclick="cancelEdit()">Cancel</button></div>
    </div>
  </div>`;
}
function aiHelpBox(h){
  if(h.kind==='busy')return `<div class="aihelp"><div class="ahl">ReliCheck Intelligence is ${h.action==='rewrite'?'rewriting your question':'reading your question'}…</div></div>`;
  if(h.kind==='error')return `<div class="aihelp"><div class="ahl">ReliCheck Intelligence is unavailable right now (${esc(h.msg||'')}). Your question is unchanged.</div><div class="ahacts"><button class="btn sm" onclick="dismissHelp()">Dismiss</button></div></div>`;
  if(h.kind==='rewrite')return `<div class="aihelp"><div class="ahl">Question updated${typeof h.delta==='number'?' · strength '+(h.delta>=0?'+'+h.delta:h.delta):''}</div>${(h.notes&&h.notes.length)?`<ul>${h.notes.map(n=>`<li>${esc(n)}</li>`).join('')}</ul>`:''}<div class="ahacts"><button class="btn sm" onclick="undoRewrite(${h.i})">Undo</button><button class="btn sm" onclick="dismissHelp()">Dismiss</button></div></div>`;
  return `<div class="aihelp"><div class="ahl">Clarity notes</div><ul>${(h.notes||[]).map(n=>`<li>${esc(n)}</li>`).join('')}</ul><div class="ahacts"><button class="btn sm" onclick="dismissHelp()">Dismiss</button></div></div>`;
}
function addQ(){
  const t=$('#qtext').value.trim();if(!t)return;const type=$('#qtype').value;
  withTicker(()=>state.questions.push({t,type,options:defaultOptions(type)}));render();persistItems();
  const d=state.lastDelta;toast(d.dir==='down'?`Added. Strength ${d.amount} — see its mark.`:(d.dir==='up'?`Added. Strength +${d.amount}.`:'Added. Strength unchanged.'));
}
async function suggestQ(){
  if(state.phase!=='build')state.phase='build';
  const ideas=[{t:'How confident do you feel about your decision to enroll?',type:'Rating scale',options:null},{t:'What is the main reason you chose us over other schools?',type:'Long comment',options:null},{t:'Which of these influenced your decision? (Choose all that apply)',type:'Checkboxes',options:['Cost','Location','Reputation','Financial aid']}];
  if(!PERSIST.on){ const next=ideas[state.questions.length%ideas.length]; withTicker(()=>state.questions.push(Object.assign({},next)));render();persistItems();toast('Added a suggested question.'); return; }
  toast('ReliCheck is thinking…');
  try{
    const existing=(state.questions||[]).map(q=>q.t).filter(Boolean);
    const r=await DB.call('ai-suggest.php',{method:'POST',body:{purpose:state.study.purpose||'',population:state.study.population||'',existing}});
    const norm=t=>(t||'').toLowerCase().replace(/[^a-z0-9 ]/g,'').replace(/\s+/g,' ').trim();
    const have=new Set(existing.map(norm));
    const fresh=(r.questions||[]).filter(q=>q.prompt&&!have.has(norm(q.prompt)));
    if(!fresh.length){ toast('No new ideas right now — add a survey purpose for more.'); return; }
    const next=fresh[0],type=mapType(next.type);
    withTicker(()=>state.questions.push({t:next.prompt,type,options:defaultOptions(type)}));
    render();persistItems();toast('ReliCheck suggested a question.');
  }catch(e){ degrade(e.message); toast('Could not get a suggestion: '+e.message); }
}
function mapType(label){
  const t=String(label||'').toLowerCase();
  if(t.includes('checkbox'))return 'Checkboxes';
  if(t.includes('multiple')||t.includes('dropdown'))return 'Multiple choice';
  if(t.includes('likert')||t.includes('rating')||t.includes('scale')||t.includes('nps')||t.includes('slider'))return 'Rating scale';
  if(t.includes('yes')||t.includes('true')||t.includes('boolean'))return 'Yes / No';
  if(t.includes('long')||t.includes('comment')||t.includes('open')||t.includes('essay')||t.includes('paragraph'))return 'Long comment';
  if(t.includes('number')||t.includes('numeric')||t.includes('date'))return 'Number';
  return 'Short text';
}
function removeQ(i){withTicker(()=>{state.questions.splice(i,1);if(state.editing===i)state.editing=null;});render();persistItems();}
function editQ(i){state.editing=i;state.aiHelp=null;render();}
function cancelEdit(){state.editing=null;state.aiHelp=null;render();}
function saveEdit(i){state.editing=null;state.aiHelp=null;render();persistItems();toast('Question updated.');}
function setEditText(i,v){state.questions[i].t=v;}
function setType(i,v){const q=state.questions[i];q.type=v;if(['Multiple choice','Checkboxes','Yes / No'].includes(v)&&!q.options)q.options=defaultOptions(v);render();}
function setOpt(i,oi,v){state.questions[i].options[oi]=v;}
function addOpt(i){state.questions[i].options=state.questions[i].options||[];state.questions[i].options.push('Option '+(state.questions[i].options.length+1));render();}
function delOpt(i,oi){state.questions[i].options.splice(oi,1);render();}
function jumpTo(i){state.phase='build';state.editing=i;state.aiHelp=null;render();setTimeout(()=>{const el=document.getElementById('qc-'+i);if(el)el.scrollIntoView({behavior:'smooth',block:'center'});},40);}
function improveWeakest(){
  if(!state.questions.length)return;
  state.bc=assessNow();
  let wi=-1;
  state.questions.forEach((q,i)=>{ if(wi<0&&markOf(q,i)==='down')wi=i; });
  if(wi<0)state.questions.forEach((q,i)=>{ if(wi<0&&markOf(q,i)==='flat')wi=i; });
  if(wi<0){let wv=999;state.questions.forEach((q,i)=>{const v=qualityHeuristic(q);if(v<wv){wv=v;wi=i;}});}
  if(wi<0)wi=0;
  state.phase='build';state.editing=wi;state.aiHelp=null;render();setTimeout(()=>improveWording(wi),120);
}
async function improveWording(i){
  const q=state.questions[i],original=q.t;
  if(!PERSIST.on){ withTicker(()=>{q.t=improvedText(original);});state.aiHelp={i,kind:'rewrite',original,delta:state.lastDelta.amount};render();return; }
  state.aiHelp={i,kind:'busy',action:'rewrite'};render();
  try{
    const r=await DB.call('ai-refine.php',{method:'POST',body:{action:'rewrite',prompt:original,type:q.type||'',purpose:state.study.purpose||'',population:state.study.population||''}});
    const rw=(r.rewrite||'').trim();
    if(rw){ withTicker(()=>{q.t=rw;}); state.aiHelp={i,kind:'rewrite',original,delta:state.lastDelta.amount,notes:(r.notes||[])}; persistItems(); }
    else { state.aiHelp={i,kind:'clarity',notes:(r.notes&&r.notes.length?r.notes:['This question already reads well — no rewrite needed.'])}; }
  }catch(e){ state.aiHelp={i,kind:'error',msg:e.message}; }
  render();
}
function improvedText(t){
  const map={'Was the enrollment process clear and did you feel supported the whole way through?':'How clear was the enrollment process?','How did you first hear about our university?':'How did you first hear about us?'};
  if(map[t])return map[t];let s=t.trim();
  if(/\band\b/i.test(s))s=s.replace(/\s+and\s+.*$/i,'').replace(/[?,.]?\s*$/,'')+'?';
  if(!/[?]$/.test(s))s+='?';return s.charAt(0).toUpperCase()+s.slice(1);
}
async function checkClarity(i){
  const q=state.questions[i];
  if(!PERSIST.on){
    const notes=[],text=q.t,words=(text.match(/\b[\w']+\b/g)||[]).length;
    if(/\band\b/i.test(text)&&words>6)notes.push('This asks two things at once (it has an "and"). Splitting it into two keeps the answers clean.');
    if(words>22)notes.push('A little long. Shorter questions are answered more honestly.');
    if(!notes.length)notes.push('Reads clearly and asks one thing. Good as is.');
    state.aiHelp={i,kind:'clarity',notes};render();return;
  }
  state.aiHelp={i,kind:'busy',action:'clarity'};render();
  try{
    const r=await DB.call('ai-refine.php',{method:'POST',body:{action:'clarity',prompt:q.t,type:q.type||'',purpose:state.study.purpose||'',population:state.study.population||''}});
    state.aiHelp={i,kind:'clarity',notes:(r.notes&&r.notes.length?r.notes:['Reads clearly.'])};
  }catch(e){ state.aiHelp={i,kind:'error',msg:e.message}; }
  render();
}
function undoRewrite(i){if(state.aiHelp&&state.aiHelp.i===i){withTicker(()=>{state.questions[i].t=state.aiHelp.original;});state.aiHelp=null;render();}}
function dismissHelp(){state.aiHelp=null;render();}

/* Grouping (constructs) — optional, high-level. Reached from the left rail. */
function viewGrouping(){
  const rows=state.questions.map((q,i)=>`
    <div class="assign-row"><div class="aq">${i+1}. ${esc(q.t)}</div>
      <select onchange="assignGroup(${i},this.value)">${['<option value="">— not grouped —</option>'].concat(state.groups.map(g=>`<option ${g===q.group?'selected':''}>${esc(g)}</option>`)).join('')}</select></div>`).join('');
  return `
    <div class="eyebrow">Build · Grouping <span class="faint" style="text-transform:none;letter-spacing:0;font-weight:600">· optional</span></div>
    <h1 class="title">What is this survey measuring?</h1>
    <p class="lede">Some surveys measure one underlying thing (a "construct") with several questions. Name those things and point each question at one, and ReliCheck can check the questions agree once answers come in. Skip this if your questions each stand on their own. New to it? Open the <b>Coach</b>.</p>
    <div class="card pad" style="margin-bottom:18px;max-width:780px">
      <div style="font-weight:700;margin-bottom:9px">What you are measuring</div>
      <div class="grp-chips">${state.groups.length?state.groups.map(g=>`<span class="grp-chip">${esc(g)}</span>`).join(''):'<span class="faint" style="font-size:14.5px">None yet</span>'}</div>
      <div class="grp-add"><input id="grpName" placeholder="e.g. Belonging, Decision confidence"><button class="btn sm" onclick="addGroup()">Add</button></div>
    </div>
    <div class="card pad" style="max-width:780px">
      <div style="font-weight:700;margin-bottom:8px">Point each question at what it measures</div>
      ${rows}
    </div>
    <div class="btn-row"><button class="btn" onclick="closeGrouping()">← Done</button></div>`;
}
function openGrouping(){state.grouping=true;state.phase='build';state.editing=null;render();}
function closeGrouping(){state.grouping=false;render();persistItems();toast('Groups saved.');}
function addGroup(){const v=$('#grpName').value.trim();if(!v)return;if(!state.groups.includes(v))state.groups.push(v);render();}
function assignGroup(i,v){state.questions[i].group=v;}

/* Review */
function openReview(){state.reviewOpen=true;paintReview();}
function closeReview(){state.reviewOpen=false;paintReview();}
function paintReview(){
  const r=$('#review'),sc=$('#scrim');
  if(state.screen!=='start'){
    const s=strengthValue(),b=bandOf(s);
    const items=reviewItems();
    const fixes=items.length?items.map(x=>`<div class="fixitem"><div class="fi">!</div><div><div class="ft">Q${x.i+1}: ${esc(x.q.t)}</div><div class="fs">${esc(x.msg)}${x.fix?' '+esc(x.fix):''}</div><button class="fa" onclick="closeReview();jumpTo(${x.i})">Fix this one →</button></div></div>`).join('')
      :`<div class="allgood"><span class="ag">✓</span><div>Nothing is pulling your survey down right now. It is in good shape to launch.</div></div>`;
    r.innerHTML=`
      <div class="rv-head"><span class="rv-n">${s}</span><div><div class="rv-w">${b.w}</div><div class="rv-sub">Survey strength · updates as you build</div></div><button class="cx" onclick="closeReview()">&times;</button></div>
      <div class="rv-body">
        <div class="sec-row" style="margin-top:0"><h2 class="sec">${items.length?`${items.length} question${items.length===1?'':'s'} worth a look`:'Nothing flagged'}</h2></div>
        ${fixes}
        <details class="tech"><summary>Full technical breakdown</summary><div class="tbody">
          <p class="faint" style="font-size:12px;margin-bottom:14px">For the curious. You never have to read this to use the score above.</p>
          ${techBreakdown()}
        </div></details>
      </div>`;
  }
  r.classList.toggle('open',state.reviewOpen&&state.screen!=='start');
  sc.classList.toggle('open',state.reviewOpen&&state.screen!=='start');
}
function techBreakdown(){
  if(state.bc&&Array.isArray(state.bc.categories)&&state.bc.categories.length){
    return state.bc.categories.map(c=>{const label=c.label||c.name||c.key||'Category';const pts=(typeof c.points==='number')?c.points:0;const max=(typeof c.max==='number')?c.max:(typeof c.max_points==='number'?c.max_points:10);return dom(label,Math.round(pts*10)/10,max);}).join('');
  }
  return dom('Question clarity',clarityScore(),10)+dom('Answer options fit',9,10)+dom('Structure & length',8,10)+dom('Coverage of your goal',9,10)+dom('Ready to launch',8,10);
}
function clarityScore(){return Math.max(3,Math.min(10,Math.round(strengthValue()/10)));}
function dom(name,p,max){const pct=Math.round(p/max*100);return `<div class="dom"><div class="dom-head"><span class="nm">${name}</span><span class="pts">${p} / ${max}</span></div><div class="meter"><span style="width:${pct}%"></span></div></div>`;}

/* Launch / Analyze */
function viewLaunch(){
  return `
    <div class="eyebrow">Launch</div>
    <h1 class="title">Send it out</h1>
    <p class="lede">Your survey is ready. Share the link, and answers flow back into Analyze automatically.</p>
    <div class="notice"><div class="ni">🔗</div><div style="flex:1"><div style="font-weight:700;font-size:14.5px">Your survey link</div><div class="linkbox"><code>relichecksurvey.com/s/fresh-enroll-26</code><button class="btn sm" onclick="toast('Link copied.')">Copy</button></div></div></div>
    <div class="share">
      <button onclick="toast('Opens your email with the link.')"><span class="si">✉️</span><span class="st">Email it</span></button>
      <button onclick="toast('Shows a QR code to print or share.')"><span class="si">▦</span><span class="st">QR code</span></button>
      <button onclick="toast('Opens a respondent preview.')"><span class="si">👁</span><span class="st">Preview</span></button>
    </div>
    <div class="card pad" style="max-width:760px;margin-bottom:16px"><div style="font-weight:700;margin-bottom:6px">Before you send it wide</div><p class="muted" style="font-size:14px">Send it to 3 to 5 people first and watch where they pause. The Coach has more on this.</p></div>
    <div class="btn-row"><button class="btn" onclick="go('build')">← Back to Build</button><div class="spacer"></div><button class="btn" onclick="simulate()">▶ Simulate responses (demo)</button><button class="btn primary lg" onclick="go('analyze')">Go to Analyze →</button></div>`;
}
function simulate(){state.responses=142;go('analyze');toast('142 responses came in.');}
function viewAnalyze(){
  if(state.responses===0)return `
    <div class="eyebrow">Analyze</div>
    <h1 class="title">Understand the answers</h1>
    <p class="lede">When responses come in, ReliCheck shows what they mean and how much you can trust them, in plain language.</p>
    <div class="notice"><div class="ni">⏳</div><div><div style="font-weight:700;font-size:14.5px">Waiting for responses</div><p class="muted" style="font-size:14px;margin-top:3px">No answers yet. Once a handful arrive, your results appear here with a trust check on the questions that measure the same thing.</p></div></div>
    <div class="btn-row"><button class="btn" onclick="go('launch')">← Back to Launch</button><div class="spacer"></div><button class="btn" onclick="simulate()">▶ Simulate responses (demo)</button></div>`;
  return `
    <div class="eyebrow">Analyze</div>
    <h1 class="title">What your answers say</h1>
    <p class="lede"><b>${state.responses} responses</b> so far. Here is the plain-language read. ReliCheck handles the statistics underneath.</p>
    <div class="result-card"><h4>How did students first hear about us?</h4>${bar('Friend or family',46)}${bar('Social media',28)}${bar('College fair',16)}${bar('Web search',10)}</div>
    <div class="result-card"><h4>How confident were students in their decision?</h4>${bar('Very confident',52)}${bar('Somewhat',31)}${bar('Unsure',17)}</div>
    <div class="sec-row"><h2 class="sec">Can you trust these scores?</h2></div>
    <div class="trust ok"><span class="ti">✓</span><div>The questions measuring <b>decision confidence</b> agree well with each other. You can report that score with confidence.</div></div>
    <div class="trust hold"><span class="ti">!</span><div>There is not yet enough data on <b>belonging</b> to judge its reliability. ReliCheck is holding that score rather than guessing. Collect a few more responses.</div></div>
    <div class="sec-row"><h2 class="sec">Take this further</h2></div>
    <p class="muted" style="font-size:15px;max-width:740px;margin-bottom:16px">Hand your clean data to the right tool. Your questions and responses carry over, no re-upload.</p>
    <div class="handoff">
      ${handoffCard('Strength index (RSSI)','Full reliability and validity evidence on your responses.')}
      ${handoffCard('Descriptive Studio','Explore and summarize what your data shows.')}
      ${handoffCard('Qualitative Studio','Code and theme your open-ended answers.')}
      ${handoffCard('Inferential Studio','Test differences and relationships.')}
      ${handoffCard('Mixed Methods Studio','Integrate the numbers and the words together.')}
    </div>
    <div class="btn-row"><button class="btn" onclick="go('launch')">← Back to Launch</button></div>`;
}
function handoffCard(title,desc){return `<button class="handoff-card" onclick="toast('Hands off to ${esc(title)} with your data.')"><div class="hc-t">${esc(title)}</div><div class="hc-d">${esc(desc)}</div><div class="hc-go">Open →</div></button>`;}
function bar(l,p){return `<div class="bar-row"><span class="bl">${esc(l)}</span><span class="bm"><span style="width:${p}%"></span></span><span class="bv">${p}%</span></div>`;}

/* Coach */
const COACH={
  build:{sub:'Guidance · Build',what:'Write your survey one question at a time. The strength reading at the top moves with every question, so a weak one shows itself right away.',why:'Catching a vague or double-barreled question as you write it is far cheaper than discovering it after people have answered.',tip:'Keep each question to one idea. If it has an "and" in it, it might really be two questions.',
    prompts:[['Which question type should I use?','Multiple choice for distinct options (pick a major), a rating scale for degree (how satisfied, 1–5), short text for a word, long comment for open feedback.'],['Why did the strength drop?','A question scored low, usually because it is double-barreled, too long, or missing answer options. The one pulling it down is marked on its card.'],['What does grouping do?','Pointing several questions at the same thing lets ReliCheck check they agree once answers arrive. Optional, useful once you have a few related questions.']]},
  launch:{sub:'Guidance · Launch',what:'Turn your finished survey into a shareable link and send it to the people you want answers from.',why:'A short pilot with a few people catches confusing wording before it reaches everyone.',tip:'Send it to 3 to 5 people first and watch where they hesitate.',
    prompts:[['Can I change it after launch?','Small wording fixes are fine. Avoid changing answer options once responses are coming in, since it makes early and later answers hard to compare.']]},
  analyze:{sub:'Guidance · Analyze',what:'Once answers come in, ReliCheck shows what they mean and how much you can trust them.',why:'A result you can trust comes from questions that actually agree with each other. ReliCheck tells you when they do, and when there is not enough data yet.',tip:'Wait until you have a reasonable number of responses before reading too much into the numbers.',
    prompts:[['What is a reliability check?','It tells you whether questions meant to measure the same thing actually agree. High agreement means you can trust that score; low agreement means revisit those questions.']]},
};
function toggleCoach(){state.coachOpen=!state.coachOpen;document.body.classList.toggle('coach-open',state.coachOpen);}
function setCoachTab(t){state.coachTab=t;state.askOpen=null;paintCoach();}
function paintCoach(){
  const phase=state.screen==='start'?'build':state.phase;const c=COACH[phase]||COACH.build;
  $('#compSub').textContent=c.sub;
  $('#ctGuide').classList.toggle('active',state.coachTab==='guide');
  $('#ctAsk').classList.toggle('active',state.coachTab==='ask');
  let body;
  if(state.coachTab==='guide'){
    body=`<div class="ctx-chip">You are on · ${cap(phase)}</div>
      <div class="cb"><div class="cb-k">What this step is</div><div class="cb-t">${c.what}</div></div>
      <div class="cb cb-why"><div class="cb-k">Why it matters</div><div class="cb-t">${c.why}</div></div>
      <div class="cb"><div class="cb-k">Tip</div><div class="cb-t">${c.tip}</div></div>`;
  } else {
    body=`<div class="cb-k" style="margin-bottom:11px">Common questions</div>`+c.prompts.map(([q,a],i)=>`<button class="ai-chip" onclick="toggleAsk(${i})">${q}</button>${state.askOpen===i?`<div class="ai-answer">${a}</div>`:''}`).join('')+`<div class="ask-row"><input placeholder="Ask anything about your survey…" onkeydown="if(event.key==='Enter'){this.value='';}"></div>`;
  }
  $('#compBody').innerHTML=body;
}
function toggleAsk(i){state.askOpen=state.askOpen===i?null:i;paintCoach();}

boot();
</script>
</body>
</html>
