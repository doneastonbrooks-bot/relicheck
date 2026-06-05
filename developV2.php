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
  --ink:#15171a; --ink-2:#2d3240; --ink-3:#5a6070;
  --bg:#f7f5f2; --panel:#ffffff; --soft:#edeae6;
  --line:rgba(0,0,0,.10); --line-2:rgba(0,0,0,.055);
  --pri:#1b1e25; --pri-2:#000;
  --serif:ui-serif,Georgia,"Times New Roman",serif;
  /* Brand theme — rust orange (matches the public survey button, not a bright orange). */
  --accent:#bf4726; --accent-2:#a23a1d; --accent-soft:#fbede7; --accent-ink:#8f3318;
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
.tb-step.done .tb-ind{background:var(--accent);border-color:transparent}
.tb-step.active .tb-ind{background:var(--accent);border-color:transparent;box-shadow:0 0 0 3px var(--accent-soft)}
.tb-word{font-size:15px;font-weight:600;color:var(--ink-3);transition:color .15s;white-space:nowrap}
.tb-step:hover .tb-word{color:var(--ink-2)}
.tb-step.done .tb-word{color:var(--ink-2)}
.tb-step.active .tb-word{color:var(--accent-ink);font-weight:750}
.tb-connector{width:62px;height:1.5px;background:var(--line);flex-shrink:0;transition:background .15s}
.tb-connector.done{background:var(--accent);opacity:.45}

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
.savestat{font-size:12.5px;font-weight:650;color:var(--ink-3);white-space:nowrap}
.savestat:empty{display:none}
.savestat.saved{color:var(--good)} .savestat.saving{color:var(--ink-3)} .savestat.offline{color:var(--warn)}

/* main: wide, breathing */
.main{grid-row:2;grid-column:2;overflow-y:auto;padding:48px 52px 110px}
.wrap{max-width:1060px;margin:0 auto}
.screen{animation:fade .22s ease}
@keyframes fade{from{opacity:0;transform:translateY(5px)}to{opacity:1;transform:none}}
.eyebrow{font-size:13px;font-weight:700;letter-spacing:.14em;text-transform:uppercase;color:var(--accent-ink)}
h1.title{font-family:var(--serif);font-size:38px;font-weight:700;letter-spacing:-0.02em;margin:10px 0 16px;line-height:1.1}
.title-input{display:block;width:100%;max-width:760px;border:none;background:none;font-family:inherit;font-size:42px;font-weight:800;letter-spacing:-0.035em;color:var(--ink);padding:2px 0;margin:10px 0 14px;border-bottom:2px solid transparent;line-height:1.1}
.title-input::placeholder{color:var(--ink-3)}
.title-input:hover{border-bottom-color:var(--line)}
.title-input:focus{outline:none;border-bottom-color:var(--ink-3)}
.lede{font-size:17px;color:var(--ink);max-width:680px;margin-bottom:36px;line-height:1.65}
.sec-row{display:flex;align-items:baseline;gap:14px;margin:36px 0 18px}
h2.sec{font-family:var(--serif);font-size:22px;font-weight:600;letter-spacing:-0.01em}
.tlink{background:none;border:none;font-size:14.5px;font-weight:650;color:var(--ink-2);padding:0;border-bottom:1px solid var(--line)}
.tlink:hover{color:var(--ink);border-color:var(--ink-3)}

/* buttons */
.btn{display:inline-flex;align-items:center;gap:8px;border:1px solid var(--line);background:var(--panel);color:var(--ink);font-weight:600;font-size:15px;padding:11px 19px;border-radius:10px;transition:.12s}
.btn:hover{background:var(--soft)}
.btn.primary{background:var(--accent);border-color:var(--accent);color:#fff}
.btn.primary:hover{background:var(--accent-2);border-color:var(--accent-2)}
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
.entry-card .ico{width:40px;height:40px;border-radius:11px;display:grid;place-items:center;background:var(--accent-soft);color:var(--accent);font-size:19px}
.entry-card h3{font-size:17.5px;font-weight:750}
.entry-card p{font-size:14.5px;color:var(--ink-2);flex:1;line-height:1.6}
.entry-card .go{font-size:14px;font-weight:700;color:var(--ink)}
.proj-list{border:1px solid var(--line);border-radius:var(--r);background:var(--panel);box-shadow:var(--sh);overflow:hidden;max-height:420px;overflow-y:auto;margin-top:10px}
.proj-row{display:flex;align-items:center;gap:13px;padding:12px 16px;border-bottom:1px solid var(--line-2);transition:background .1s}
.proj-row:last-child{border-bottom:none}
.proj-row:hover{background:var(--soft)}
.proj-rdot{width:8px;height:8px;border-radius:50%;flex-shrink:0}
.proj-open{flex:1;text-align:left;background:none;border:none;font-family:inherit;font-size:15px;font-weight:650;color:var(--ink);cursor:pointer;padding:0;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;min-width:0}
.proj-open:hover{color:var(--accent-ink)}
.proj-status{font-size:12.5px;font-weight:650;white-space:nowrap;flex-shrink:0}
.proj-meta{font-size:12px;color:var(--ink-3);white-space:nowrap;flex-shrink:0}
.proj-del{width:26px;height:26px;border-radius:7px;border:1px solid transparent;background:none;color:var(--ink-3);font-size:15px;display:grid;place-items:center;flex-shrink:0;transition:.1s}
.proj-del:hover{background:var(--bad-soft);border-color:var(--bad-soft);color:var(--bad)}
.proj-confirm{display:flex;align-items:center;gap:8px;flex-shrink:0;font-size:13px;color:var(--bad)}
.proj-confirm button{font-size:13px;font-weight:700;border:none;background:none;cursor:pointer;padding:2px 6px;border-radius:6px}
.proj-confirm .yes{color:#fff;background:var(--bad);border-radius:6px;padding:3px 10px}
.proj-confirm .yes:hover{background:#a31f17}
.proj-confirm .no{color:var(--ink-3)}
.proj-confirm .no:hover{color:var(--ink)}
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
.qcard.mark-down{border-bottom:2px solid var(--warn)}
.qhead{display:flex;gap:15px;align-items:flex-start}
.qn{width:26px;height:26px;border-radius:7px;background:var(--soft);color:var(--ink-3);font-size:12px;font-weight:700;display:grid;place-items:center;flex-shrink:0;margin-top:1px;transition:background .2s,color .2s}
.qcard.mark-up .qn{background:var(--good-soft);color:var(--good)}
.qcard.mark-down .qn{background:var(--warn-soft);color:var(--warn)}
.qb{flex:1;min-width:0}
.qt{font-size:17px;font-weight:600;line-height:1.5}
.qmeta{font-size:13.5px;color:var(--ink-3);margin-top:6px;display:flex;align-items:center;gap:10px}
.qmark{display:inline-flex;align-items:center;gap:5px;font-size:13px;font-weight:650;border:none;background:none;cursor:pointer;padding:2px 4px;border-radius:6px}
.qmark:hover{background:var(--soft)}
.qmark .md{width:7px;height:7px;border-radius:50%}
.qmark .qwhy{font-size:11px;font-weight:700;opacity:.55}
.qmark:hover .qwhy{opacity:1}
.qmark.up{color:var(--ink-2)} .qmark.up .md{background:var(--good)}
.qmark.flat{color:var(--ink-3)} .qmark.flat .md{background:var(--ink-3)}
.qmark.down{color:var(--warn)} .qmark.down .md{background:var(--warn)}
/* "Why?" explanation for a question's mark */
.explain{margin:12px 0 0 41px;padding:14px 16px;border:1px solid var(--line);background:var(--soft);border-radius:11px;max-width:620px;animation:fade .18s ease}
.explain .ex-h{font-size:13px;font-weight:750;margin-bottom:8px}
.explain .ex-row{padding:9px 0;border-top:1px solid var(--line-2)}
.explain .ex-row:first-of-type{border-top:none;padding-top:0}
.explain .ex-t{font-size:14px;font-weight:700;color:var(--ink)}
.explain .ex-w{font-size:13.5px;color:var(--ink-2);margin-top:3px;line-height:1.5}
.explain .ex-fix{font-size:13.5px;color:var(--ink);margin-top:5px}
.explain .ex-fix b{font-weight:700}
.explain .ex-note{font-size:12.5px;color:var(--ink-3);margin-top:11px;padding-top:10px;border-top:1px solid var(--line-2);line-height:1.55}
/* Combined per-item verdict (rules + ReliCheck Intelligence) */
.verdict{margin-top:14px;border:1px solid var(--line);border-radius:12px;background:var(--panel);max-width:640px;overflow:hidden;animation:fade .18s ease}
.verdict .vh{padding:13px 16px;font-size:14.5px;font-weight:750;display:flex;align-items:center;gap:9px;border-bottom:1px solid var(--line)}
.verdict .vh .vdot{width:9px;height:9px;border-radius:50%}
.verdict .vh.ok .vdot{background:var(--good)} .verdict .vh.work .vdot{background:var(--warn)}
.verdict .vbody{padding:6px 16px 14px}
.verdict .vgrp{margin-top:12px}
.verdict .vgk{font-size:11px;font-weight:700;letter-spacing:.05em;text-transform:uppercase;color:var(--ink-3);margin-bottom:7px}
.verdict .vrow{display:flex;gap:9px;align-items:flex-start;padding:8px 0;border-top:1px solid var(--line-2)}
.verdict .vrow:first-of-type{border-top:none}
.verdict .vdim{flex-shrink:0;font-size:10.5px;font-weight:700;text-transform:uppercase;letter-spacing:.03em;color:var(--ink-2);background:var(--soft);border:1px solid var(--line);border-radius:999px;padding:2px 8px;margin-top:1px}
.verdict .vtx{flex:1;font-size:13.5px;color:var(--ink-2);line-height:1.5}
.verdict .vtx b{color:var(--ink);font-weight:700}
.verdict .vfix{color:var(--ink);margin-top:3px}
.verdict .vrw{margin-top:13px;padding:12px 14px;background:var(--accent-soft);border:1px solid var(--accent-soft);border-radius:10px}
.verdict .vrw .vrk{font-size:11px;font-weight:700;letter-spacing:.05em;text-transform:uppercase;color:var(--accent-ink);margin-bottom:5px}
.verdict .vrw .vrt{font-size:14.5px;color:var(--ink);line-height:1.5}
.verdict .vacts{display:flex;gap:8px;margin-top:11px}
.verdict .vfoot{padding:10px 16px;border-top:1px solid var(--line);background:var(--soft);font-size:12px;color:var(--ink-3);line-height:1.5}
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
.meter>span{display:block;height:100%;border-radius:999px;background:var(--accent)}

/* launch / analyze */
.notice{display:flex;gap:15px;align-items:flex-start;padding:20px 22px;border:1px solid var(--line);border-radius:var(--r);background:var(--panel);box-shadow:var(--sh);margin-bottom:18px;max-width:760px}
.notice .ni{width:34px;height:34px;border-radius:9px;background:var(--soft);color:var(--ink);display:grid;place-items:center;flex-shrink:0;font-size:16px}
.linkbox{display:flex;gap:10px;align-items:center;border:1px solid var(--line);border-radius:10px;padding:12px 14px;background:var(--soft);font-size:13.5px;margin-top:9px}
.linkbox code{flex:1;font-size:13px;color:var(--ink)}
.share{display:grid;grid-template-columns:repeat(3,1fr);gap:13px;margin:16px 0;max-width:560px}
.share button{border:1px solid var(--line);background:var(--panel);border-radius:var(--r-sm);padding:17px;display:flex;flex-direction:column;gap:7px;align-items:center;box-shadow:var(--sh)}
.share button:hover{border-color:var(--ink-3)}
.share .si{font-size:22px;font-weight:300;color:var(--ink-2);line-height:1}.share .st{font-size:13px;font-weight:650;color:var(--ink)}
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
/* QR modal (launch share) */
.qr-ov{position:fixed;inset:0;background:rgba(16,24,40,.42);display:flex;align-items:center;justify-content:center;z-index:130;padding:20px}
.qr-modal{background:var(--panel);border:1px solid var(--line);border-radius:var(--r);box-shadow:var(--sh-lg);width:340px;max-width:94vw;padding:20px 22px}
.qr-head{display:flex;align-items:center;justify-content:space-between;margin-bottom:10px}
.qr-modal .cx{width:30px;height:30px;border-radius:8px;border:1px solid var(--line);background:var(--panel);color:var(--ink-3);font-size:16px}
.qr-modal .cx:hover{background:var(--soft)}
.qr-link{font-size:12.5px;color:var(--ink-2);text-align:center;word-break:break-all;background:var(--soft);border:1px solid var(--line);border-radius:8px;padding:9px 11px;margin-bottom:13px}
.qr-btns{display:flex;gap:10px;justify-content:center}
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
<!-- Real SIRI Launch Check engine (100-pt readiness gate): SDSI design + 5 readiness domains -->
<script src="/apps/sdsi/siri-readiness.js?v=<?= is_file(__DIR__.'/apps/sdsi/siri-readiness.js') ? filemtime(__DIR__.'/apps/sdsi/siri-readiness.js') : '1' ?>"></script>
<script src="/apps/sdsi/launchcheck-engine.js?v=<?= is_file(__DIR__.'/apps/sdsi/launchcheck-engine.js') ? filemtime(__DIR__.'/apps/sdsi/launchcheck-engine.js') : '1' ?>"></script>
<!-- Shared ReliCheck upload widget (the ecosystem entry gate) -->
<script src="/apps/studio/dataset-upload.js?v=<?= is_file(__DIR__.'/apps/studio/dataset-upload.js') ? filemtime(__DIR__.'/apps/studio/dataset-upload.js') : '1' ?>"></script>
<script src="/apps/studio/studio-footer.js?v=<?= is_file(__DIR__.'/apps/studio/studio-footer.js') ? filemtime(__DIR__.'/apps/studio/studio-footer.js') : '1' ?>"></script>
<script>
const state={
  screen:'start',startFlow:null,phase:'build',
  study:{name:'Freshman Enrollment',purpose:'Understand why admitted students chose to enroll, and what nearly sent them to another school',population:'Admitted first-year (freshman) students',mode:'',dataType:'',launchReadiness:{}},
  coachOpen:false,coachTab:'guide',askOpen:null,
  reviewOpen:false,editing:null,aiHelp:null,responses:0,lastDelta:null,prevStrength:null,grouping:false,groups:[],bc:null,projects:null,saveStatus:'',
  siriResult:null,siriStale:false,helpPick:null,entry:'scratch',explainItem:null,itemVerdict:null,writeGaugeInfo:false,deleteConfirm:null,
  questions:[
    {t:'What is your intended major?',type:'Multiple Choice',options:['Biology','Business','Engineering','Undecided']},
    {t:'How did you first hear about us?',type:'Multiple Choice',options:['Friend or family','Social media','College fair','Web search']},
    {t:'Was the enrollment process clear and did you feel supported the whole way through?',type:'Rating Scale',options:null},
  ],
};
// Lifecycle: Build the survey → Analyze (pre-launch readiness checker) → Launch
// (deploy) → Results (post-response). Analyze and Launch are deliberately
// separate pages so neither is crowded.
const PHASES=[{id:'build',t:'Build'},{id:'analyze',t:'Analyze'},{id:'launch',t:'Launch'},{id:'results',t:'Results'}];
/* Full question-type catalog — ported from the mature builder so V2 exposes
   every type, not a subset. `editOpts` types get an answer-choices editor;
   `structural` types are content (no answer); settings carry per-type config
   (Likert points/anchors, rating stars, slider range) in the SAME keys the
   public survey renderer (api/public/survey-dev.php) reads, so they work end
   to end. */
const QTYPES={
  'Multiple Choice':   {label:'Multiple Choice',               group:'Choice Questions',      defOpts:['Option 1','Option 2','Option 3'], editOpts:true},
  'Checkboxes':        {label:'Checkboxes (multiple answers)', group:'Choice Questions',      defOpts:['Option 1','Option 2','Option 3'], editOpts:true},
  'Dropdown':          {label:'Dropdown',                      group:'Choice Questions',      defOpts:['Option 1','Option 2','Option 3'], editOpts:true},
  'Yes/No':            {label:'Yes / No',                      group:'Choice Questions',      defOpts:['Yes','No']},
  'True/False':        {label:'True / False',                  group:'Choice Questions',      defOpts:['True','False']},
  'Likert Scale':      {label:'Likert Scale',                  group:'Rating Questions'},
  'Rating Scale':      {label:'Rating Scale (stars)',          group:'Rating Questions'},
  'Matrix/Grid':       {label:'Matrix / Grid',                 group:'Rating Questions',      defOpts:['Row 1','Row 2','Row 3'], editOpts:true},
  'NPS':               {label:'NPS (0–10)',                    group:'Rating Questions'},
  'Short Answer':      {label:'Short Answer',                  group:'Open Response'},
  'Long Answer':       {label:'Long Answer',                   group:'Open Response'},
  'Comment Box':       {label:'Comment Box',                   group:'Open Response'},
  'Ranking':           {label:'Ranking',                       group:'Ordering and Priority', defOpts:['Item 1','Item 2','Item 3'], editOpts:true},
  'Slider':            {label:'Slider',                        group:'Ordering and Priority'},
  'Demographic':       {label:'Demographic Item',              group:'Demographic and Contact', defOpts:['Option 1','Option 2'], editOpts:true},
  'Email':             {label:'Email',                         group:'Demographic and Contact'},
  'Phone':             {label:'Phone',                         group:'Demographic and Contact'},
  'Date':              {label:'Date',                          group:'Demographic and Contact'},
  'Numeric':           {label:'Numeric',                       group:'Demographic and Contact'},
  'Section Text':      {label:'Section Text / Instructions',   group:'Survey Structure',      structural:true},
  'Consent':           {label:'Consent / Agreement',          group:'Survey Structure',      structural:true, defOpts:['I agree to participate.'], editOpts:true},
  'Page Break':        {label:'Page Break',                    group:'Survey Structure',      structural:true},
  'Thank-you Message': {label:'Thank-you Message',             group:'Survey Structure',      structural:true},
};
const QGROUPS=[
  {name:'Choice Questions',        types:['Multiple Choice','Checkboxes','Dropdown','Yes/No','True/False']},
  {name:'Rating Questions',        types:['Likert Scale','Rating Scale','Matrix/Grid','NPS']},
  {name:'Open Response',           types:['Short Answer','Long Answer','Comment Box']},
  {name:'Ordering and Priority',   types:['Ranking','Slider']},
  {name:'Demographic and Contact', types:['Demographic','Email','Phone','Date','Numeric']},
  {name:'Survey Structure',        types:['Section Text','Consent','Page Break','Thank-you Message']},
];
// "Help me choose" — plain-language answer need → best-fit type.
const QHELP=[
  {q:'One answer from a list',         type:'Multiple Choice'},
  {q:'More than one answer',           type:'Checkboxes'},
  {q:'A rating or level of agreement', type:'Likert Scale'},
  {q:'A written response',             type:'Long Answer'},
  {q:'A number',                       type:'Numeric'},
  {q:'A date',                         type:'Date'},
  {q:'A ranking',                      type:'Ranking'},
  {q:'Consent or acknowledgment',      type:'Consent'},
  {q:'Instructions only',              type:'Section Text'},
];
const QDEFAULT_PROMPT={'Section Text':'Add your instructions here.','Consent':'Please review and confirm before continuing.','Page Break':'Page break','Thank-you Message':'Thank you for completing this survey.'};
function typeLabel(t){ return (QTYPES[t]&&QTYPES[t].label)||t; }
function isStructural(t){ return !!(QTYPES[t]&&QTYPES[t].structural); }
// Map legacy/back-compat type names (from old projects + the AI builder) onto the
// catalog so previews, editors, and exports render them correctly.
const QTYPE_ALIAS={'Single Choice':'Multiple Choice','Likert (5-pt)':'Likert Scale','Likert (7-pt)':'Likert Scale','Rating':'Rating Scale','Long Text':'Long Answer','Open-Ended':'Long Answer','Instructions':'Section Text','Matrix':'Matrix/Grid'};
function normType(t){ return QTYPES[t]?t:(QTYPE_ALIAS[t]||t); }

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
function siriBandOf(s){if(s>=90)return{w:'Strong',c:'green'};if(s>=80)return{w:'Good',c:'green'};if(s>=70)return{w:'Caution',c:'amber'};if(s>=55)return{w:'Weak',c:'amber'};return{w:'Not ready',c:'red'};}
// Color for an engine SDSI band KEY (the capped band). Keeps the writing gauge in
// lockstep with the engine so it can never read "Good" once readiness is capped.
function sdsiBandColor(k){ if(k==='strong'||k==='good')return 'green'; if(k==='caution'||k==='weak')return 'amber'; return 'red'; }
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
/* ── "Why is this marked?" — the per-question explanation. Clicking a question's
   strength mark reveals the SPECIFIC engine flags behind it, so "pulling it
   down" is never a mystery and never contradicts the AI wording check. ── */
function itemFlags(q,i){
  if(!(state.bc&&state.bc.flags))return [];
  const ref=refOf(q,i);
  const order={critical:0,major:1,moderate:2,high:1,medium:2,low:3,minor:3};
  return state.bc.flags.filter(f=>f.item_ref===ref).slice().sort((a,b)=>(order[a.severity]??9)-(order[b.severity]??9));
}
function cleanMsg(m){ return String(m||'').replace(/^Question\s+\d+\s*/i,'').replace(/^[a-z]/,c=>c.toUpperCase()); }
function toggleExplain(i){ state.explainItem=(state.explainItem===i?null:i); render(); }
function explainPanel(q,i){
  const m=markOf(q,i);
  const rf=rfFor(q,i);
  // Response-fit flags get their own dedicated block, so keep them out of the generic rows.
  const flags=itemFlags(q,i).filter(f=>f.flag_key!=='response_format_mismatch'&&f.flag_key!=='response_fit_weak');
  const rfBlock=rf?rfExplainBlock(rf,i):'';
  const head = m==='down' ? 'Why this is lowering your survey strength'
            : (m==='flat' ? 'Worth a look on this question' : 'This question is on track');
  if(m==='up'&&!flags.length&&(!rf||rf.status==='strong_fit'||rf.status==='acceptable_fit')){
    return `<div class="explain"><div class="ex-h">${head}</div>${rfBlock}<div class="ex-w">Nothing is lowering your strength on this question. It reads well and fits your survey. <button class="ailink" onclick="toggleExplain(${i})">Close</button></div></div>`;
  }
  const rows=flags.map(f=>`<div class="ex-row"><div class="ex-t">${esc(f.flag_label||cleanMsg(f.message))}</div>${f.why_it_matters?`<div class="ex-w">${esc(f.why_it_matters)}</div>`:(f.message&&f.flag_label?`<div class="ex-w">${esc(cleanMsg(f.message))}</div>`:'')}${f.suggestion?`<div class="ex-fix"><b>Fix:</b> ${esc(f.suggestion)}</div>`:''}</div>`).join('');
  return `<div class="explain"><div class="ex-h">${head}</div>${rfBlock}${rows}
    <div class="ex-note">"Pulling it down" means your overall <b>survey strength</b> — not always the wording. If ReliCheck Intelligence said the wording is fine, the reason above is usually structural (for example, grouping the item into a construct) or a specific flagged word. <button class="ailink" onclick="toggleExplain(${i})">Close</button></div></div>`;
}
// ── Response Fit Check — per-item surfacing ──────────────────────────────────
const RF_LABELS={
  strong_fit:{short:'Strong fit',c:'green',plain:'The response format matches what the question is asking.'},
  acceptable_fit:{short:'Acceptable fit',c:'green',plain:'This response format can work, though another format may produce cleaner data.'},
  weak_fit:{short:'Weak fit',c:'amber',plain:'The response format is usable, but the question or answer structure may reduce precision.'},
  mismatch:{short:'Format mismatch',c:'red',plain:'The response format does not match what the question is asking.'},
  cannot_assess:{short:'Needs question text',c:'gray',plain:'ReliCheck cannot judge the response format until the question text is clear.'}
};
const RF_TASK_LABELS={
  categorical:'a category or fact',multi_select:'one or more selections',agreement:'a level of agreement',
  perception:'a perception or intensity',frequency:'how often something happens',satisfaction:'a level of satisfaction',
  quality:'a quality or effectiveness rating',numeric:'a number or quantity',date_time:'a date or time',
  ranking:'a ranking or order',open_ended:'an open explanation',knowledge:'a correct answer',
  matrix:'a matrix battery response',administrative:'an administrative value',non_interpretable:'something unclear'
};
function rfColor(c){ return c==='green'?'var(--good)':(c==='amber'?'var(--warn)':(c==='red'?'var(--bad)':'var(--ink-3)')); }
function rfFor(q,i){
  if(!(state.bc&&state.bc.itemScores))return null;
  const ref=refOf(q,i);
  const sc=state.bc.itemScores.filter(s=>s.item_ref===ref)[0];
  if(!sc||!sc.response_fit_status)return null;
  const fl=(state.bc.flags||[]).filter(f=>f.item_ref===ref&&(f.flag_key==='response_format_mismatch'||f.flag_key==='response_fit_weak'))[0]||null;
  return {status:sc.response_fit_status,task:sc.response_task,reason:sc.response_fit_reason,sensitive:!!sc.response_fit_sensitive,flag:fl};
}
function rfCannotMsg(reason){
  if(reason==='stem_demographic_label'||reason==='stem_construct_label'||reason==='stem_not_answerable')
    return 'The response format may be plausible, but the question is label-only. Rewrite it as a respondent-facing question (for example, "What is your department?") before ReliCheck can judge response fit.';
  if(reason==='stem_item_code') return 'This item is a code or column name, not a question. Add the respondent-facing question text before ReliCheck can judge response fit.';
  if(reason==='instruction') return 'This stem is an instruction with no subject to respond about. Add the actual question before ReliCheck can judge response fit.';
  if(reason==='admin') return 'This is an administrative or metadata field, not a respondent-facing question, so response fit is not assessed.';
  return RF_LABELS.cannot_assess.plain;
}
function rfChip(q,i){
  const rf=rfFor(q,i); if(!rf)return '';
  const L=RF_LABELS[rf.status]||RF_LABELS.cannot_assess, col=rfColor(L.c);
  return ` · <button class="qmark" onclick="toggleExplain(${i})" title="Response fit — click for detail" style="color:${col}"><span class="md" style="background:${col}"></span>Fit: ${esc(L.short)}</button>`;
}
function rfExplainBlock(rf,i){
  const L=RF_LABELS[rf.status]||RF_LABELS.cannot_assess, col=rfColor(L.c);
  const task=rf.task&&RF_TASK_LABELS[rf.task]?RF_TASK_LABELS[rf.task]:null;
  const body=rf.status==='cannot_assess'?esc(rfCannotMsg(rf.reason)):esc(L.plain);
  const taskLine=(task&&rf.status!=='cannot_assess')?`<div class="ex-w" style="font-size:12.5px;color:var(--ink-3);margin-top:4px">What this question asks for: ${esc(task)}.</div>`:'';
  let extra='';
  if(rf.flag){
    if(rf.flag.why_it_matters) extra+=`<div class="ex-w" style="margin-top:6px"><b>For researchers:</b> ${esc(rf.flag.why_it_matters)}</div>`;
    if(rf.flag.suggestion) extra+=`<div class="ex-fix"><b>How to fix:</b> ${esc(rf.flag.suggestion)}</div>`;
  }
  return `<div class="ex-row"><div class="ex-t" style="display:flex;align-items:center;gap:7px"><span style="width:8px;height:8px;border-radius:50%;background:${col};display:inline-block;flex-shrink:0"></span>Response fit: ${esc(L.short)}</div><div class="ex-w">${body}</div>${taskLine}${extra}</div>`;
}
function qualityHeuristic(q){
  let s=86;const text=(q.t||'').trim();const words=(text.match(/\b[\w']+\b/g)||[]).length;
  if(/\band\b/i.test(text)&&words>6)s-=26; if(/\bor\b/i.test(text)&&words>9)s-=8;
  if(words>22)s-=12; if(words<3)s-=18; if(!/[?]$/.test(text))s-=6;
  if(['Multiple Choice','Checkboxes'].includes(q.type)){const n=(q.options||[]).length;if(n>=3)s+=4;else if(n<2)s-=10;}
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
function defaultOptions(t){ const d=QTYPES[t]&&QTYPES[t].defOpts; return d?d.slice():null; }
// Per-type default settings, in the keys the public renderer reads.
function defaultSettings(t){
  if(t==='Likert Scale')return{likertPoints:5,likertLow:'Strongly Disagree',likertHigh:'Strongly Agree'};
  if(t==='Rating Scale')return{ratingStars:5};
  if(t==='Slider')return{sliderMin:0,sliderMax:100};
  return null;
}
function go(phase){state.phase=phase;state.editing=null;state.aiHelp=null;render(); if(phase==='analyze')maybeRunSiri();}
// SIRI Launch Check (100-pt readiness gate) — runs the real LaunchCheck engine
// against the survey + the current SDSI Build Check result.
function maybeRunSiri(){ if((state.questions||[]).length && window.LaunchCheck && window.LaunchCheck.assess && (!state.siriResult||state.siriStale)) runSiriCheck(); }
function runSiriCheck(){
  if(!(window.LaunchCheck&&window.LaunchCheck.assess)){ state.siriResult=null; toast('Launch Check engine unavailable'); render(); return; }
  state.bc=assessNow();                                   // current SDSI design result
  const r=window.LaunchCheck.assess(buildCheckProject(),{sdsiResult:state.bc});
  state.siriResult=r; state.siriStale=false;
  if(PERSIST.on&&state.projectId){
    const hasTotal=(r.total!=null);
    DB.call('siri-save.php',{method:'POST',body:{ project_id:state.projectId,
      total:hasTotal?r.total:r.siri, max:hasTotal?100:50, pct:hasTotal?Math.round(r.total):r.pct,
      band:hasTotal?r.total_band:'', blocked:(r.deployment_blocker_count||0)>=1, review:r }}).catch(e=>degrade(e.message));
  }
  render();
}
function withTicker(fn){const before=liveStrength();fn();const after=liveStrength();state.lastDelta={amount:after-before,dir:after>before?'up':(after<before?'down':'flat')};state.prevStrength=after;}

/* ── Persistence — mirrors develop.php's proven api/dev DB layer.
   PERSIST.on when logged in; ?mock forces the offline demo (sample data). ── */
const PERSIST_REQUESTED = !new URLSearchParams(location.search).has('mock');
const PERSIST = { on:PERSIST_REQUESTED, degraded:false, reason:'' };
const LS_KEY = 'sds_v2_project_id';
function degrade(reason){ if(!PERSIST.degraded){ PERSIST.on=false; PERSIST.degraded=true; PERSIST.reason=reason||''; setSaveStatus('offline'); toast('Working offline — changes are not being saved'); } }
function setSaveStatus(s){ state.saveStatus=s; const el=document.getElementById('saveStat'); if(el){ el.className='savestat '+s; el.textContent=saveStatusText(s); } }
function saveStatusText(s){ return s==='saving'?'Saving…':(s==='saved'?'Saved':(s==='offline'?'Offline':'')); }
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
  itemHydrate(it){ const s=it.settings||{}; const q={ id:it.id, t:it.prompt, type:normType(it.type), flag:it.flag, section_id:it.section_id, required:!!it.required, options:it.options||null, settings:it.settings||null, group:'' }; if(s.construct)q.group=s.construct; return q; },
  constructsWire(){ return (state.groups||[]).map((g,i)=>({ id:null, name:g, definition:'', position:i })); },
  hydrate(payload){
    const p=payload.project;
    state.projectId=p.id;
    state.study.name=p.title||''; state.study.purpose=p.purpose||''; state.study.population=p.population||'';
    state.study.mode=p.response_mode||''; state.study.dataType=p.data_type||'';
    state.entry=p.source||'scratch';
    state.settings=p.settings||{}; state.study.launchReadiness=(p.settings&&p.settings.launchReadiness)||{};
    state.groups=(payload.constructs||[]).map(c=>c.name).filter(Boolean);
    state.questions=(payload.items||[]).map(it=>DB.itemHydrate(it));
    state.responses=payload.responses||0;
    state.deploymentSettings=payload.deployment||null;
    // Restore the saved SIRI Launch Check (the full engine result rides in .review).
    state.siriResult=(payload.siri&&payload.siri.review&&(payload.siri.review.siri!=null))?payload.siri.review:null;
    state.siriStale=false;
    try{ localStorage.setItem(LS_KEY,String(p.id)); }catch(e){}
  },
};
async function createProject(source){
  setSaveStatus('saving');
  const r=await DB.call('project-create.php',{method:'POST',body:{
    title:state.study.name||'Untitled survey', source:source||'scratch',
    purpose:state.study.purpose||'', population:state.study.population||'',
    response_mode:state.study.mode||'', data_type:state.study.dataType||'',
    sections:[{title:'Main'}],
    items:(state.questions||[]).map(q=>({ type:q.type, prompt:q.t, flag:q.flag||null, required:q.required?1:0, options:q.options||null, settings:DB.itemSettingsOut(q) })),
    constructs:(state.groups||[]).map(g=>({name:g,definition:''})),
  }});
  DB.hydrate(r);
  setSaveStatus('saved');
}
function persistItems(){ state.siriStale=true; if(PERSIST.on&&state.projectId) saveItemsNow(); }
async function saveItemsNow(){
  if(!(PERSIST.on&&state.projectId))return;
  setSaveStatus('saving');
  try{
    const r=await DB.call('items-save.php',{method:'POST',body:{project_id:state.projectId,items:DB.itemsWire()}});
    // Rehydrate from the saved items (same as the working build) ONLY when the
    // count matches, so a stray response can never wipe an in-progress question.
    const saved=Array.isArray(r.items)?r.items:[];
    if(saved.length===state.questions.length){ state.questions=saved.map(it=>DB.itemHydrate(it)); }
    await saveConstructsNow();
    setSaveStatus('saved');
    if(state.screen==='workspace'&&state.editing==null)render();
  }catch(e){ degrade(e.message); toast('Could not save: '+e.message); }
}
async function saveConstructsNow(){
  if(!(PERSIST.on&&state.projectId))return;
  try{ const r=await DB.call('constructs-save.php',{method:'POST',body:{project_id:state.projectId,constructs:DB.constructsWire()}}); state.groups=(r.constructs||[]).map(c=>c.name).filter(Boolean); }catch(e){ degrade(e.message); }
}
let _titleTimer=null;
function setStudyName(v){ state.study.name=v; if(!(PERSIST.on&&state.projectId))return; clearTimeout(_titleTimer); _titleTimer=setTimeout(()=>{ DB.call('project-update.php',{method:'POST',body:{id:state.projectId,title:state.study.name}}).catch(e=>degrade(e.message)); },500); }
// Always land on Start (never trap the user inside the last project). Resume is
// offered on the Start screen when a saved project exists. EXCEPTION: a
// ?project_id=N deep-link (e.g. from ReliCheck Basic / studio handoffs) opens
// that project directly so the old develop.php links keep working after repoint.
function boot(){
  render();
  if(typeof StudioFooter!=='undefined')StudioFooter.init();
  const pid=new URLSearchParams(location.search).get('project_id');
  if(pid && /^\d+$/.test(pid) && PERSIST_REQUESTED){ openProject(+pid); }
}
function savedProjectId(){ try{ return Number(localStorage.getItem(LS_KEY))||null; }catch(e){ return null; } }
function goStart(){ state.screen='start'; state.startFlow=null; state.projects=null; render(); }
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
  const fn={build:viewBuild,analyze:viewAnalyzeCheck,launch:viewLaunch,results:viewResults}[state.phase]||viewBuild;
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
  const sr=state.siriResult, stale=state.siriStale;
  let inner;
  if(sr&&sr.total!=null){
    const score=Math.round(sr.total);
    const bandKey=sr.total_band_key||'';
    const gated=(bandKey==='codes'||bandKey==='limited');
    const c=bandKey?siriBandColor(bandKey):siriBandOf(score).c;
    const w=sr.total_band||siriBandOf(score).w;
    const staleTag=stale?`<span class="tk-k" style="color:var(--warn);font-size:9.5px">STALE</span>`:'';
    inner=`${staleTag}<span class="tk-l"><span class="tk-k">Readiness</span><span class="tk-w">${esc(w)}</span></span><span class="tk-dot ${c}"></span><span class="tk-n"${gated?' style="color:var(--bad)"':''}>${gated?'—':score}</span><span class="tk-go">${stale?'Re-run':'Analyze'}<span class="cv">›</span></span>`;
  } else {
    inner=`<span class="tk-l"><span class="tk-k">Readiness</span><span class="tk-w" style="color:var(--ink-3)">Not checked</span></span><span class="tk-dot" style="background:var(--ink-3);opacity:.4"></span><span class="tk-n" style="color:var(--ink-3);font-size:18px;min-width:24px">—</span><span class="tk-go">Run check<span class="cv">›</span></span>`;
  }
  $('#tbRight').innerHTML=`
    <span class="savestat ${state.saveStatus}" id="saveStat">${saveStatusText(state.saveStatus)}</span>
    <button class="topbtn" onclick="goStart()" title="Start or open another survey">＋ New survey</button>
    <button class="ticker" onclick="${state.phase==='launch'?'go(\'analyze\')':'openReview()'}" title="Launch readiness">${inner}</button>
    <button class="avatar"><?= htmlspecialchars($_dv_initials) ?></button>`;
}

/* start */
function relDate(s){
  if(!s)return '';
  const d=new Date(s),now=new Date(),days=Math.floor((now-d)/86400000);
  if(days===0)return 'today';if(days===1)return 'yesterday';
  if(days<7)return days+'d ago';if(days<30)return Math.floor(days/7)+'w ago';
  return Math.floor(days/30)+'mo ago';
}
function recentSection(){
  if(!PERSIST.on){
    return `<div class="sec-row"><h2 class="sec">Your surveys</h2></div><div class="proj-list">
      <div class="proj-row"><span class="proj-rdot" style="background:var(--good)"></span><button class="proj-open" onclick="enter()">Freshman Enrollment</button><span class="proj-status" style="color:var(--ink-3)">draft</span><span class="proj-meta">3 questions</span></div>
      <div class="proj-row"><span class="proj-rdot" style="background:var(--ink-3)"></span><button class="proj-open" onclick="enter()">Staff Pulse 2026</button><span class="proj-status" style="color:var(--good)">published</span><span class="proj-meta">12 questions</span></div>
    </div>`;
  }
  if(state.projects===null){ loadProjects(); return `<div class="sec-row"><h2 class="sec">Your surveys</h2></div><div class="faint" style="font-size:14px;padding:8px 0">Loading…</div>`; }
  if(!state.projects.length) return '';
  const rows=state.projects.map(p=>{
    const pub=p.status==='published';
    const dotColor=pub?'var(--good)':'var(--ink-3)';
    const statusColor=pub?'color:var(--good)':'color:var(--ink-3)';
    const parts=[p.item_count>0?p.item_count+' question'+(p.item_count===1?'':'s'):'',p.response_count>0?p.response_count+' response'+(p.response_count===1?'':'s'):'',p.updated_at?relDate(p.updated_at):''];
    const meta=parts.filter(Boolean).join(' · ');
    const metaEl=meta?'<span class="proj-meta">'+esc(meta)+'</span>':'';
    const action=state.deleteConfirm===p.id
      ?'<span class="proj-confirm">Remove? <button class="yes" onclick="confirmDelete('+p.id+')">Yes</button><button class="no" onclick="cancelDelete()">No</button></span>'
      :'<button class="proj-del" title="Remove" onclick="askDelete('+p.id+')">&times;</button>';
    return '<div class="proj-row">'
      +'<span class="proj-rdot" style="background:'+dotColor+'"></span>'
      +'<button class="proj-open" onclick="openProject('+p.id+')">'+esc(p.title||'Untitled survey')+'</button>'
      +'<span class="proj-status" style="'+statusColor+'">'+esc(p.status||'draft')+'</span>'
      +metaEl+' '+action
      +'</div>';
  });
  return `<div class="sec-row"><h2 class="sec">Your surveys</h2></div><div class="proj-list">${rows.join('')}</div>`;
}
async function loadProjects(){
  if(!PERSIST.on){ state.projects=[]; return; }
  try{ const r=await DB.call('project-list.php'); state.projects=r.projects||[]; }
  catch(e){ state.projects=[]; }
  if(state.screen==='start')render();
}
function askDelete(id){ state.deleteConfirm=id; render(); }
function cancelDelete(){ state.deleteConfirm=null; render(); }
async function confirmDelete(id){
  state.projects=(state.projects||[]).filter(p=>p.id!==id);
  state.deleteConfirm=null; render();
  if(PERSIST.on) await DB.call('project-archive.php',{method:'POST',body:{id}}).catch(e=>degrade(e.message));
  toast('Survey removed.');
}
async function openProject(id){
  try{ const r=await DB.call('project-load.php?id='+encodeURIComponent(id)); DB.hydrate(r); state.screen='workspace';state.phase='build';state.startFlow=null;state.prevStrength=liveStrength();render(); }
  catch(e){ degrade(e.message); toast('Could not open: '+e.message); }
}
function renderStart(){
  if(state.startFlow==='upload')return renderUpload();
  $('#app').innerHTML=`<div class="screen">
    <div class="eyebrow">New survey</div>
    <h1 class="title">How would you like to start?</h1>
    <p class="lede">Pick how much help you want from ReliCheck Intelligence. You can change course any time.</p>
    <div class="entry">
      <button class="entry-card" onclick="startSetup('scratch')"><div class="ico">✎</div><h3>Build it myself</h3><p>A clean workspace, no assistance. You write every question and choose every answer format yourself.</p><span class="go">Start building →</span></button>
      <button class="entry-card" onclick="startSetup('ai-assist')"><div class="ico">✨</div><h3>Build with an assistant</h3><p>You build it, with ReliCheck Intelligence suggesting question items and improving your wording as you go.</p><span class="go">Build together →</span></button>
      <button class="entry-card" onclick="startSetup('ai-build')"><div class="ico">⚡</div><h3>Have ReliCheck build it</h3><p>Describe your goal and ReliCheck Intelligence drafts the whole survey for you to review and adjust.</p><span class="go">Get a full draft →</span></button>
    </div>
    <button class="entry-card" style="width:100%;flex-direction:row;align-items:center;gap:16px;margin-top:16px" onclick="openUpload()"><div class="ico" style="margin:0">⤓</div><div style="flex:1"><h3>I already have a survey</h3><p style="margin-top:2px">Upload from Google Forms, SurveyMonkey, Qualtrics, or a spreadsheet. ReliCheck detects the format.</p></div><span class="go">Upload it →</span></button>
    ${recentSection()}
  </div>`;
}
// Two-question setup shown for EVERY build mode before the workspace. Title +
// purpose tailor ReliCheck Intelligence's help and feed the SIRI readiness check
// (they clear the "no purpose recorded" flag) — captured even before any question.
function startSetup(mode){
  state.setupMode=mode;
  state.study={name:'',purpose:'',population:'',mode:'',dataType:'',launchReadiness:{}};
  openSetupModal();
}
function openSetupModal(){
  const M={'scratch':{eyebrow:'Build it myself',cta:'Start building →'},'ai-assist':{eyebrow:'Build with an assistant',cta:'Start building →'},'ai-build':{eyebrow:'Have ReliCheck build it',cta:'Draft my survey →'}};
  const m=M[state.setupMode]||M.scratch;
  const ov=document.createElement('div'); ov.className='qr-ov'; ov.id='setupOv';
  ov.innerHTML=`<div class="qr-modal" style="max-width:540px;width:calc(100vw - 40px)">
    <div class="qr-head" style="flex-direction:column;align-items:flex-start;gap:4px;padding-bottom:16px">
      <div style="display:flex;align-items:center;justify-content:space-between;width:100%">
        <span style="font-size:11.5px;font-weight:700;letter-spacing:.1em;text-transform:uppercase;color:var(--accent-ink)">${m.eyebrow}</span>
        <button class="cx" onclick="closeSetupModal()">&times;</button>
      </div>
      <div style="font-family:var(--serif);font-size:24px;font-weight:700;color:var(--ink);line-height:1.2">Let's set up your survey</div>
      <p style="font-size:14px;color:var(--ink-3);margin:4px 0 0;line-height:1.5">Two quick things — they tailor ReliCheck Intelligence and the readiness check.</p>
    </div>
    <div style="padding:4px 0 0">
      <div style="margin-bottom:14px">
        <div style="font-size:11px;font-weight:700;letter-spacing:.07em;text-transform:uppercase;color:var(--ink-3);margin-bottom:7px">Survey title</div>
        <input id="setTitle" type="text" style="width:100%;border:1.5px solid var(--line);border-radius:10px;padding:11px 14px;font-family:inherit;font-size:15px;box-sizing:border-box" value="${esc(state.study.name||'')}" placeholder="e.g. Employee Engagement Pulse" onkeydown="if(event.key==='Enter')document.getElementById('setPurpose').focus()">
      </div>
      <div>
        <div style="font-size:11px;font-weight:700;letter-spacing:.07em;text-transform:uppercase;color:var(--ink-3);margin-bottom:7px">What are you looking to get from this survey?</div>
        <textarea id="setPurpose" style="width:100%;border:1.5px solid var(--line);border-radius:10px;padding:11px 14px;font-family:inherit;font-size:15px;min-height:88px;resize:vertical;box-sizing:border-box" placeholder="e.g. Understand what drives engagement and what makes people consider leaving, so we can prioritize the right changes.">${esc(state.study.purpose||'')}</textarea>
        <p style="font-size:12.5px;color:var(--ink-3);margin:5px 0 0">You can refine this later. It feeds ReliCheck Intelligence and the pre-launch readiness check.</p>
      </div>
    </div>
    <div class="qr-btns" style="margin-top:20px">
      <button class="btn primary lg" onclick="submitSetup()" style="flex:1">${m.cta}</button>
    </div>
  </div>`;
  ov.addEventListener('click',e=>{ if(e.target===ov)closeSetupModal(); });
  document.body.appendChild(ov);
  setTimeout(()=>{ const el=document.getElementById('setTitle'); if(el)el.focus(); },80);
}
function closeSetupModal(){ const o=document.getElementById('setupOv'); if(o)o.remove(); }
function submitSetup(){
  const title=(($('#setTitle')||{}).value||'').trim();
  const purpose=(($('#setPurpose')||{}).value||'').trim();
  if(!purpose){ toast('Tell ReliCheck what you want to get from this survey.'); const el=document.getElementById('setPurpose'); if(el)el.focus(); return; }
  state.study.name=title||'Untitled survey';
  state.study.purpose=purpose;
  closeSetupModal();
  if(state.setupMode==='ai-build') aiDraft();
  else enter(state.setupMode,true);
}
async function aiDraft(){
  // Reads title + purpose from the setup step (state.study), already captured.
  const goal=(state.study.purpose||'').trim();
  const pop=(state.study.population||'').trim();
  const titleHint=(state.study.name||'').trim();
  if(!goal){ toast('Tell ReliCheck what you want to get from this survey.'); return; }
  $('#app').innerHTML=`<div class="screen"><div class="card pad" style="text-align:center;max-width:520px;margin:50px auto"><p class="muted" style="font-size:15px">ReliCheck Intelligence is drafting your survey…</p></div></div>`;
  state.startFlow=null; state.groups=[]; state.entry='ai-build'; state.aiReason='';
  // Real path: ask api/dev/ai-build.php for a study tailored to the title + purpose.
  if(PERSIST.on){
    try{
      const r=await DB.call('ai-build.php',{method:'POST',body:{name:titleHint,purpose:goal,population:pop}});
      const study=r.study||{}, items=Array.isArray(study.items)?study.items:[];
      if(items.length){
        state.study={name:titleHint||study.title||'Survey from your goal',purpose:goal,population:pop,mode:'',dataType:'',launchReadiness:{}};
        state.groups=(study.constructs||[]).map(c=>c.name).filter(Boolean);
        state.questions=items.map(it=>{ const type=normType(mapType(it.type)); return {t:it.prompt,type,options:defaultOptions(type),settings:defaultSettings(type),group:it.construct||''}; });
        try{ await createProject('ai-build'); }catch(e){ degrade(e.message); }
        state.screen='workspace';state.phase='build';state.prevStrength=liveStrength();render();
        toast('ReliCheck drafted '+items.length+' tailored question'+(items.length===1?'':'s')+'. Review and adjust.');
        return;
      }
    }catch(e){ state.aiReason=e.message||''; }
  }
  // Fallback: AI unavailable or not signed in → a sample to edit, stated plainly.
  state.questions=[
    {t:'How confident did you feel about your decision?',type:'Rating Scale',options:null,settings:defaultSettings('Rating Scale')},
    {t:'What was the single biggest reason behind your choice?',type:'Long Answer',options:null},
    {t:'Which of these did you weigh before deciding? (Choose all that apply)',type:'Checkboxes',options:['Cost','Location','Reputation','Support','Other']},
    {t:'How clear was the process?',type:'Rating Scale',options:null,settings:defaultSettings('Rating Scale')},
  ];
  state.study={name:titleHint||'Survey from your goal',purpose:goal,population:pop,mode:'',dataType:'',launchReadiness:{}};
  if(PERSIST.on){ try{ await createProject('ai-build'); }catch(e){ degrade(e.message); } }
  state.screen='workspace';state.phase='build';state.prevStrength=liveStrength();render();
  toast(state.aiReason?('ReliCheck Intelligence is unavailable ('+state.aiReason+'). Loaded a sample to edit.'):'Loaded a sample survey to edit.');
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
    {t:'Overall, how satisfied are you with your experience?',type:'Rating Scale',options:null},
    {t:'How likely are you to recommend us to a friend?',type:'Rating Scale',options:null},
    {t:'What could we have done better?',type:'Long Answer',options:null},
  ];
  state.startFlow=null; state.groups=[];
  if(PERSIST.on){ state.study={name:'Imported survey',purpose:'',population:'',mode:'',dataType:'',launchReadiness:{}}; try{ await createProject('existing'); }catch(e){ degrade(e.message); } }
  state.screen='workspace';state.phase='build';state.prevStrength=liveStrength();
  render();toast('Brought in 3 questions from your file.');
}
// Real upload via the shared DatasetUpload widget (projectType 'survey'); mock falls back to the demo.
function openUpload(){
  if(!PERSIST.on){ uploadDone(); return; }
  if(!window.DatasetUpload){ toast('Upload widget is unavailable right now'); return; }
  DatasetUpload.open({
    projectType:'survey',
    projectId:null,
    onLoaded(_err,newProjectId){
      if(!newProjectId)return;
      if(!PERSIST.on){ PERSIST.on=true; PERSIST.degraded=false; PERSIST.reason=''; }
      (async()=>{
        try{
          const r=await DB.call('project-load.php?id='+encodeURIComponent(newProjectId));
          DB.hydrate(r);
          const n=(state.questions||[]).length;
          state.screen='workspace';state.phase='build';state.startFlow=null;state.prevStrength=liveStrength();render();
          toast(n?('Brought in '+n+' question'+(n===1?'':'s')):'Survey uploaded');
        }catch(e){
          state.projectId=+newProjectId; try{localStorage.setItem(LS_KEY,String(state.projectId));}catch(_){}
          degrade(e.message); state.screen='workspace';state.phase='build';state.startFlow=null;render();
        }
      })();
    }
  });
}
async function enter(mode,fromSetup){
  mode=(mode==='ai-assist')?'ai-assist':'scratch';
  state.startFlow=null; state.entry=mode;
  if(PERSIST.on){
    state.questions=[]; state.groups=[];
    // Keep the title + purpose captured in the setup step; only reset when entering directly.
    if(!fromSetup) state.study={name:'Untitled survey',purpose:'',population:'',mode:'',dataType:'',launchReadiness:{}};
    try{ await createProject(mode); }catch(e){ degrade(e.message); }
  }
  state.screen='workspace';state.phase='build';state.prevStrength=liveStrength();render();
  if(mode==='ai-assist') toast('Assistant on — use "Suggest a question" or the wording help on each card.');
}

/* Build */
// Read-only preview of how respondents see an item — covers every catalog type.
function answerPreview(q){
  const t=q.type, s=q.settings||{}, opts=(q.options&&q.options.length)?q.options:null;
  const list=(arr,sq)=>arr.map(o=>`<div class="opt"><span class="${sq?'sq':'dot'}"></span>${esc(o)}</div>`).join('');
  switch(t){
    case 'Multiple Choice': return list(opts||['Option 1','Option 2']);
    case 'Checkboxes':      return list(opts||['Option 1','Option 2'],true);
    case 'Dropdown': case 'Demographic':
      return `<div class="prevtext" style="max-width:300px;display:flex;justify-content:space-between;align-items:center">${esc((opts&&opts[0])||'Choose…')}<span class="faint">▾</span></div>`;
    case 'Yes/No':     return list(['Yes','No']);
    case 'True/False': return list(['True','False']);
    case 'Likert Scale':{ const n=s.likertPoints||5; return `<div class="scale">${Array.from({length:n},(_,k)=>`<span>${k+1}</span>`).join('')}</div><div class="faint" style="font-size:12px;margin-top:6px">${esc(s.likertLow||'Strongly Disagree')} → ${esc(s.likertHigh||'Strongly Agree')}</div>`; }
    case 'Rating Scale':{ const m=s.ratingStars||5; return `<div class="scale">${Array.from({length:m},()=>'<span>★</span>').join('')}</div>`; }
    case 'NPS':        return `<div class="scale">${Array.from({length:11},(_,k)=>`<span>${k}</span>`).join('')}</div><div class="faint" style="font-size:12px;margin-top:6px">Not likely → Extremely likely</div>`;
    case 'Matrix/Grid':return `<div class="faint" style="font-size:13.5px">Grid rows: ${(opts||['Row 1','Row 2']).map(esc).join(', ')}</div>`;
    case 'Ranking':    return `<ol style="margin:0 0 0 20px;color:var(--ink-2);font-size:14.5px">${(opts||['Item 1','Item 2']).map(o=>`<li style="padding:2px 0">${esc(o)}</li>`).join('')}</ol>`;
    case 'Slider':{ const mn=s.sliderMin??0,mx=s.sliderMax??100; return `<input type="range" disabled min="${mn}" max="${mx}" style="width:240px"> <span class="faint" style="font-size:12.5px">${mn} to ${mx}</span>`; }
    case 'Short Answer': return `<div class="prevtext">Short answer…</div>`;
    case 'Long Answer': case 'Comment Box': return `<div class="prevtext" style="min-height:44px">Longer answer…</div>`;
    case 'Email':   return `<div class="prevtext" style="max-width:260px">name@example.com</div>`;
    case 'Phone':   return `<div class="prevtext" style="max-width:200px">(555) 555-5555</div>`;
    case 'Date':    return `<div class="prevtext" style="max-width:200px">MM / DD / YYYY</div>`;
    case 'Numeric': return `<div class="prevtext" style="max-width:120px">0</div>`;
    case 'Consent': return `<div class="opt"><span class="sq"></span>${esc((opts&&opts[0])||'I agree to participate.')}</div>`;
    case 'Section Text':      return `<div class="faint" style="font-style:italic;font-size:14.5px">${esc(q.t||'Instructions')}</div>`;
    case 'Page Break':        return `<div class="faint" style="font-size:13px;letter-spacing:.04em;text-transform:uppercase">— Page break —</div>`;
    case 'Thank-you Message': return `<div class="faint" style="font-size:14.5px">${esc(q.t||'Thank you')}</div>`;
    default: return `<div class="prevtext">Response…</div>`;
  }
}
function toggleWriteGauge(){ state.writeGaugeInfo=!state.writeGaugeInfo; render(); }
function viewBuild(){
  if(state.grouping)return viewGrouping();
  const qs=state.questions;
  const weakCount=qs.filter((q,i)=>markOf(q,i)==='down').length;
  const list=qs.map((q,i)=>state.editing===i?editorCard(q,i):displayCard(q,i)).join('');
  // Writing quality — single-line label + level + info toggle.
  let writeGauge='';
  if(qs.length){
    const niCount=state.bc&&state.bc.stem_noninterp_count||0;
    const rfState=state.bc&&state.bc.response_fit_state;
    const rfMisPct=state.bc&&state.bc.response_fit_mismatch_pct||0;
    const rfSens=state.bc&&state.bc.response_fit_sensitive_count||0;
    // The engine's CAPPED band is the single source of truth — the gauge never
    // overrides it, so it can never read "Strong/Good" once readiness is capped.
    let b;
    if(state.bc&&state.bc.sdsi_display_band){ b={w:state.bc.sdsi_display_band,c:sdsiBandColor(state.bc.bandKey)}; }
    else { b=bandOf(strengthValue()); }
    const dotColor=b.c==='green'?'var(--good)':(b.c==='red'?'var(--bad)':'var(--warn)');
    // Warnings explain WHY the band is held down.
    const warns=[];
    if(niCount>0) warns.push('<b>'+niCount+' item'+(niCount===1?'':'s')+' '+(niCount===1?'is':'are')+' not a respondent-facing question.</b> Some stems are codes or labels (e.g. "Department", "Respondent ID", "Q1"), not questions. ReliCheck cannot fully assess these, and they hold your score down until you rewrite them as questions (e.g. "What is your department?").');
    if(rfState==='major_revision') warns.push('<b>Needs major revision.</b> '+rfMisPct+'% of items use a response format that does not match what the question is asking. Open each flagged item to change the format or rewrite the question.');
    if(rfSens>0) warns.push('<b>Identity question on an agreement scale.</b> '+(rfSens===1?'1 item asks':rfSens+' items ask')+' for a sensitive identity (e.g. race, gender) using an agreement scale, which is culturally inappropriate. Use respectful self-identification choices.');
    const warnHtml=warns.length?'<div style="margin-top:10px;padding:11px 13px;background:var(--bad-soft);border:1px solid var(--bad-soft);border-radius:8px;font-size:13.5px;color:var(--bad);line-height:1.55">'+warns.join('<br><br>')+'</div>':'';
    const clean=!warns.length;
    const infoPanel=state.writeGaugeInfo?`
      <div style="margin-top:10px;padding:13px 14px 14px;background:var(--soft);border:1px solid var(--line);border-radius:8px;font-size:13.5px;color:var(--ink-2);line-height:1.65">
        <p style="margin:0 0 9px"><b style="color:var(--ink)">What this measures:</b> how clearly and neutrally your questions are written, plus whether each answer format matches what the question asks (Response Fit). Updated on every save.</p>
        <p style="margin:0 0 9px"><b style="color:var(--ink)">Its relationship to SIRI Launch Readiness (the ticker):</b><br>
        Writing quality is the question-design component of SIRI. The Analyze step adds deployment setup — consent, defined audience, collection settings — to produce the full 100-point launch verdict.</p>
        <p style="margin:0"><button class="ailink" style="font-size:13px" onclick="toggleWriteGauge();go('build')">See flagged questions in Build →</button></p>
      </div>` : '';
    writeGauge=`<div style="margin-bottom:22px;max-width:760px">
      <div style="display:flex;align-items:center;gap:10px">
        <span style="font-size:11.5px;font-weight:700;letter-spacing:.1em;text-transform:uppercase;color:var(--ink-3)">Writing quality</span>
        <span style="width:8px;height:8px;border-radius:50%;background:${dotColor};flex-shrink:0"></span>
        <span style="font-family:var(--serif);font-size:20px;font-weight:700;color:${dotColor};letter-spacing:-.01em">${b.w}</span>
        ${clean?`<button onclick="toggleWriteGauge()" style="background:none;border:none;cursor:pointer;font-size:16px;color:var(--ink-3);padding:0 2px;line-height:1;margin-left:2px" title="About this score">ⓘ</button>`:''}
      </div>
      ${warnHtml}
      ${clean?infoPanel:''}
    </div>`;
  }
  return `
    <div class="eyebrow">Build</div>
    <input class="title title-input" value="${esc(state.study.name||'Untitled survey')}" placeholder="Untitled survey" oninput="setStudyName(this.value)" aria-label="Survey title">
    <p class="lede">Build your questions one at a time. Each opens on its own card, where you write the question, choose how people answer, and set the choices right there. Save it, then add the next.</p>
    ${writeGauge}
    ${qs.length?`<div class="sec-row">
      <h2 class="sec">${qs.length} question${qs.length===1?'':'s'} in your survey</h2>
      ${weakCount?`<button class="tlink" onclick="improveWeakest()">${weakCount} need${weakCount===1?'s':''} a look — improve ${weakCount===1?'it':'them'}</button>`:''}
    </div>`:''}
    ${list||(state.entry==='ai-assist'
      ? `<div class="card pad" style="text-align:center;max-width:640px;color:var(--ink-2)"><div style="font-size:30px">✨</div><p style="font-size:16px;font-weight:650;margin-top:8px;color:var(--ink)">Build with ReliCheck Intelligence</p><p class="faint" style="font-size:14.5px;margin:4px 0 14px">Start from a suggestion, or write your own. Wording and clarity help is on every card.</p><div class="btn-row" style="justify-content:center"><button class="btn primary" onclick="suggestQ()">✨ Suggest my first question</button><button class="btn" onclick="addBlankQ()">Write one myself</button></div></div>`
      : `<div class="card pad" style="text-align:center;max-width:640px;color:var(--ink-2)"><div style="font-size:30px">✎</div><p style="font-size:16px;font-weight:650;margin-top:8px;color:var(--ink)">No questions yet</p><p class="faint" style="font-size:14.5px;margin-top:4px">Click "Add question" below to write your first one.</p></div>`)}
    <div class="btn-row" style="margin-top:18px">
      <button class="btn primary lg" onclick="addBlankQ()">+ Add question</button>
      <button class="ailink" onclick="suggestQ()">${state.entry==='ai-assist'?'Suggest the next one':'Let ReliCheck suggest one'}</button>
      <div class="spacer"></div>
      ${qs.length?`<button class="btn primary lg" onclick="commitThenGo('analyze')">Check readiness →</button>`:''}
    </div>`;
}
function displayCard(q,i){
  const struct=isStructural(q.type);
  const m=markOf(q,i),lbl=m==='up'?'on track':(m==='down'?'pulling it down':'neutral');
  const cond=(q.settings&&q.settings.showIf)?` · <span class="faint" title="Shown only when an earlier answer matches">⤷ conditional</span>`:'';
  const meta=(struct
    ? `<span class="faint">Survey structure</span>`
    : `${esc(typeLabel(q.type))} <button class="qmark ${m}" onclick="toggleExplain(${i})" title="Why?"><span class="md"></span>${lbl}<span class="qwhy">ⓘ why</span></button>${rfChip(q,i)}`)+cond;
  return `<div class="qcard${(!struct&&m==='down')?' mark-down':(!struct&&m==='up')?' mark-up':''}" id="qc-${i}">
    <div class="qhead">
      <span class="qn">${i+1}</span>
      <div class="qb"><div class="qt">${esc(q.t)}</div><div class="qmeta">${meta}${q.group?` · <span style="font-weight:650;color:var(--ink-2)">${esc(q.group)}</span>`:''}</div></div>
      <div class="qacts"><button class="iconbtn" title="Edit" onclick="editQ(${i})">✎</button><button class="iconbtn" title="Remove" onclick="removeQ(${i})">✕</button></div>
    </div>
    <div class="qprev">${answerPreview(q)}</div>
    ${state.explainItem===i?explainPanel(q,i):''}
  </div>`;
}
function editorCard(q,i){
  const help=(state.aiHelp&&state.aiHelp.i===i)?aiHelpBox(state.aiHelp):'';
  const isNew=!!q._new, struct=isStructural(q.type);
  const typeSelect=`<select onchange="setType(${i},this.value)">${QGROUPS.map(g=>`<optgroup label="${esc(g.name)}">${g.types.map(t=>`<option value="${esc(t)}" ${t===q.type?'selected':''}>${esc(typeLabel(t))}</option>`).join('')}</optgroup>`).join('')}</select>`;
  const helpPick=(state.helpPick===i)?`<div class="aihelp" style="margin-top:12px"><div class="ahl">What kind of answer do you need?</div><div style="display:flex;flex-wrap:wrap;gap:7px;margin-top:9px">${QHELP.map(h=>`<button class="btn sm" onclick="pickHelpType(${i},'${esc(h.type)}')">${esc(h.q)}</button>`).join('')}</div></div>`:'';
  const promptLbl=struct?(q.type==='Section Text'?'Instructions text':(q.type==='Consent'?'Consent statement':(q.type==='Thank-you Message'?'Thank-you message':'Label (optional)'))):'Your question';
  const ai=struct?'':`<button class="ailink" onclick="checkItem(${i})">✦ Check this item</button><button class="ailink" onclick="improveWording(${i})">Improve wording</button>`;
  return `<div class="qcard" id="qc-${i}" style="border-color:var(--ink-3)">
    <div class="qhead"><span class="qn">${i+1}</span><div class="qb" style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:var(--ink-2);padding-top:5px">${isNew?'New question':'Editing question '+(i+1)}</div></div>
    <div class="qedit">
      <div class="faint" style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.05em;margin-bottom:7px">${promptLbl}</div>
      <textarea id="editText" placeholder="${struct?'Type the text respondents will see…':'Type your question here…'}" oninput="setEditText(${i},this.value)">${esc(q.t)}</textarea>
      <div class="erow" style="margin-top:12px">
        <span class="faint" style="font-size:13px;font-weight:650">Type:</span>
        ${typeSelect}
        <button class="ailink" onclick="toggleHelpPick(${i})">Help me choose</button>
        ${ai}
      </div>
      ${helpPick}
      ${responseEditor(q,i)}${help}
      ${itemVerdictBox(i)}
      ${displayLogicEditor(q,i)}
      <div class="erow" style="margin-top:18px;padding-top:15px;border-top:1px solid var(--line-2)"><button class="btn primary" onclick="saveEdit(${i})">Save question</button><button class="btn" onclick="removeQ(${i})">Remove</button></div>
    </div>
  </div>`;
}
/* The response editor lives ON the card, so the answer (and its choices) is set
   right where the question is written — no add-then-go-down-and-edit. Covers
   every catalog type, including per-type settings (Likert, Rating, Slider). */
function responseEditor(q,i){
  const t=q.type, s=q.settings||{}, def=QTYPES[t]||{};
  const lbl=x=>`<div class="faint" style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.05em;margin-bottom:8px">${x}</div>`;
  const inp='border:1.5px solid var(--line);border-radius:8px;padding:8px 11px;font-family:inherit;font-size:14px';
  // Editable option lists (choices / rows / ranking items / consent statement)
  if(def.editOpts){
    const isRow=(t==='Matrix/Grid'), isRank=(t==='Ranking'), isConsent=(t==='Consent');
    const round=(t!=='Checkboxes');
    const head=isRow?'Rows':(isRank?'Items to rank':(isConsent?'Agreement statement':'Answer choices — what people pick from'));
    const ph=isRow?'Row':(isRank?'Item':(isConsent?'Statement':'Choice'));
    const showMark=!isRow&&!isRank&&!isConsent;
    return `<div style="margin-top:15px">${lbl(head)}
      ${(q.options||[]).map((o,oi)=>`<div style="display:flex;gap:10px;align-items:center;margin-top:9px">${showMark?`<span style="width:18px;height:18px;flex-shrink:0;border:1.5px solid var(--ink-3);border-radius:${round?'50%':'5px'}"></span>`:(isConsent?'':`<span class="faint" style="width:16px;flex-shrink:0;font-size:13px">${oi+1}</span>`)}<input style="flex:1;max-width:420px;${inp}" value="${esc(o)}" oninput="setOpt(${i},${oi},this.value)" placeholder="${ph} ${oi+1}"><button class="iconbtn" title="Remove" onclick="delOpt(${i},${oi})">✕</button></div>`).join('')}
      ${isConsent?'':`<button class="ailink" onclick="addOpt(${i})" style="margin-top:11px">+ Add ${isRow?'row':(isRank?'item':'choice')}</button>`}</div>`;
  }
  if(t==='Yes/No')     return `<div style="margin-top:15px">${lbl('Answer choices')}<div class="opt"><span class="dot"></span>Yes</div><div class="opt"><span class="dot"></span>No</div></div>`;
  if(t==='True/False') return `<div style="margin-top:15px">${lbl('Answer choices')}<div class="opt"><span class="dot"></span>True</div><div class="opt"><span class="dot"></span>False</div></div>`;
  if(t==='Likert Scale'){
    const n=s.likertPoints||5;
    return `<div style="margin-top:15px">${lbl('Likert scale')}
      <div class="scale">${Array.from({length:n},(_,k)=>`<span>${k+1}</span>`).join('')}</div>
      <div class="erow" style="margin-top:12px">
        <span class="faint" style="font-size:12.5px">Points</span>
        <select onchange="setSetting(${i},'likertPoints',+this.value)">${[3,4,5,6,7].map(p=>`<option ${p===n?'selected':''}>${p}</option>`).join('')}</select>
        <input style="${inp};max-width:170px" value="${esc(s.likertLow||'Strongly Disagree')}" oninput="setSettingRaw(${i},'likertLow',this.value)" placeholder="Low anchor">
        <span class="faint">→</span>
        <input style="${inp};max-width:170px" value="${esc(s.likertHigh||'Strongly Agree')}" oninput="setSettingRaw(${i},'likertHigh',this.value)" placeholder="High anchor">
      </div></div>`;
  }
  if(t==='Rating Scale'){
    const m=s.ratingStars||5;
    return `<div style="margin-top:15px">${lbl('Star rating')}
      <div class="scale">${Array.from({length:m},()=>'<span>★</span>').join('')}</div>
      <div class="erow" style="margin-top:12px"><span class="faint" style="font-size:12.5px">Highest rating</span>
        <select onchange="setSetting(${i},'ratingStars',+this.value)">${[3,4,5,7,10].map(p=>`<option ${p===m?'selected':''}>${p}</option>`).join('')}</select></div></div>`;
  }
  if(t==='NPS') return `<div style="margin-top:15px">${lbl('How people answer')}<div class="scale">${Array.from({length:11},(_,k)=>`<span>${k}</span>`).join('')}</div><div class="faint" style="font-size:12.5px;margin-top:7px">0 (not likely) to 10 (extremely likely).</div></div>`;
  if(t==='Slider'){
    const mn=s.sliderMin??0,mx=s.sliderMax??100;
    return `<div style="margin-top:15px">${lbl('Slider range')}<div class="erow"><span class="faint" style="font-size:12.5px">From</span><input type="number" value="${mn}" oninput="setSettingRaw(${i},'sliderMin',+this.value)" style="width:90px;${inp}"><span class="faint">to</span><input type="number" value="${mx}" oninput="setSettingRaw(${i},'sliderMax',+this.value)" style="width:90px;${inp}"></div></div>`;
  }
  if(t==='Page Break') return `<div class="faint" style="margin-top:13px;font-size:13px">Inserts a page break for respondents. No answer is collected.</div>`;
  if(def.structural)   return `<div class="faint" style="margin-top:13px;font-size:12.5px">Shown to respondents as text. No answer is collected.</div>`;
  const open={'Short Answer':'a short typed answer','Long Answer':'a longer written answer','Comment Box':'a longer written comment','Email':'an email address','Phone':'a phone number','Date':'a date','Numeric':'a number'};
  if(open[t]) return `<div style="margin-top:15px">${lbl('People answer with '+open[t])}${answerPreview(q)}</div>`;
  return '';
}
/* ── Skip patterns / display logic — show a question only when an earlier
   choice/rating answer matches. The public renderer (take.html) already
   evaluates `showIf:{questionId,op,value}`; here we author it and store it in
   the item settings, and survey-dev.php passes it through. Triggers must be
   SAVED earlier single-choice or Likert questions (they need a server id and a
   discrete, numeric answer). ── */
function skipTriggerCandidates(i){
  const single=['Multiple Choice','Dropdown','Yes/No','True/False','Demographic','Likert Scale'];
  return state.questions.map((q,idx)=>({q,idx})).filter(x=>x.idx<i && x.q.id!=null && single.includes(x.q.type));
}
function triggerValues(q){
  if(q.type==='Likert Scale'){ const n=(q.settings&&q.settings.likertPoints)||5; return Array.from({length:n},(_,k)=>({value:k+1,label:'('+(k+1)+')'})); }
  let opts=q.options||[];
  if(q.type==='Yes/No')opts=['Yes','No']; if(q.type==='True/False')opts=['True','False'];
  return opts.map((o,idx)=>({value:idx,label:o}));
}
function displayLogicEditor(q,i){
  const cands=skipTriggerCandidates(i);
  const rule=(q.settings&&q.settings.showIf)||null;
  const wrap=inner=>`<div style="margin-top:16px;padding-top:14px;border-top:1px solid var(--line-2)">${inner}</div>`;
  if(!rule && !cands.length) return wrap(`<div class="faint" style="font-size:12.5px">Display logic (show this question only after a certain answer) becomes available once an earlier multiple-choice or rating question exists.</div>`);
  if(!rule) return wrap(`<button class="ailink" onclick="enableShowIf(${i})">+ Add display logic — show only if an earlier answer matches</button>`);
  const trig=state.questions.find(x=>x.id===rule.questionId)||(cands[0]&&cands[0].q);
  const vals=trig?triggerValues(trig):[];
  return wrap(`<div class="faint" style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.05em;margin-bottom:8px">Display logic</div>
    <div class="erow" style="flex-wrap:wrap">
      <span class="faint" style="font-size:13px">Show only if</span>
      <select onchange="setShowIf(${i},'questionId',this.value)">${cands.map(c=>`<option value="${c.q.id}" ${c.q.id===rule.questionId?'selected':''}>Q${c.idx+1}: ${esc((c.q.t||'').slice(0,38))}</option>`).join('')}</select>
      <select onchange="setShowIf(${i},'op',this.value)"><option value="equals" ${rule.op==='equals'?'selected':''}>is</option><option value="not_equals" ${rule.op==='not_equals'?'selected':''}>is not</option></select>
      <select onchange="setShowIf(${i},'value',this.value)">${vals.map(v=>`<option value="${v.value}" ${Number(v.value)===Number(rule.value)?'selected':''}>${esc(v.label)}</option>`).join('')}</select>
      <button class="ailink" onclick="clearShowIf(${i})">Remove</button>
    </div>`);
}
function enableShowIf(i){
  const cands=skipTriggerCandidates(i); if(!cands.length){ toast('Add an earlier choice or rating question first.'); return; }
  const trig=cands[0].q, vals=triggerValues(trig);
  const q=state.questions[i]; q.settings=q.settings||{};
  q.settings.showIf={questionId:trig.id,op:'equals',value:vals.length?vals[0].value:0};
  render();
}
function setShowIf(i,field,val){
  const q=state.questions[i]; if(!q.settings||!q.settings.showIf)return;
  if(field==='questionId'){ q.settings.showIf.questionId=Number(val); const trig=state.questions.find(x=>x.id===Number(val)); const vals=trig?triggerValues(trig):[]; q.settings.showIf.value=vals.length?vals[0].value:0; }
  else if(field==='value'){ q.settings.showIf.value=Number(val); }
  else { q.settings.showIf[field]=val; }
  render();
}
function clearShowIf(i){ const q=state.questions[i]; if(q.settings)delete q.settings.showIf; render(); }
function aiHelpBox(h){
  if(h.kind==='busy')return `<div class="aihelp"><div class="ahl">ReliCheck Intelligence is ${h.action==='rewrite'?'rewriting your question':'reading your question'}…</div></div>`;
  if(h.kind==='error')return `<div class="aihelp"><div class="ahl">ReliCheck Intelligence is unavailable right now (${esc(h.msg||'')}). Your question is unchanged.</div><div class="ahacts"><button class="btn sm" onclick="dismissHelp()">Dismiss</button></div></div>`;
  if(h.kind==='rewrite')return `<div class="aihelp"><div class="ahl">Question updated${typeof h.delta==='number'?' · strength '+(h.delta>=0?'+'+h.delta:h.delta):''}</div>${(h.notes&&h.notes.length)?`<ul>${h.notes.map(n=>`<li>${esc(n)}</li>`).join('')}</ul>`:''}<div class="ahacts"><button class="btn sm" onclick="undoRewrite(${h.i})">Undo</button><button class="btn sm" onclick="dismissHelp()">Dismiss</button></div></div>`;
  return `<div class="aihelp"><div class="ahl">Clarity notes</div><ul>${(h.notes||[]).map(n=>`<li>${esc(n)}</li>`).join('')}</ul><div class="ahacts"><button class="btn sm" onclick="dismissHelp()">Dismiss</button></div></div>`;
}
// Add a new question and open it for editing immediately — the card IS the
// composer. A brand-new card stays a local draft (not persisted) until Save.
async function addBlankQ(){
  if(state.phase!=='build')state.phase='build';
  if(state.editing!=null){
    const cur=state.questions[state.editing];
    if(cur&&!(cur.t||'').trim()){ focusEditor(); return; }   // already on a blank card
    // Commit the open one FIRST and wait for its server id to sync (items-save
    // is a full upsert; pushing the next blank before this resolves would leave
    // it id-less and risk a duplicate on the following save).
    if(cur){ delete cur._new; state.editing=null; if(PERSIST.on&&state.projectId) await saveItemsNow(); }
  }
  const type='Multiple Choice';
  state.prevStrength=liveStrength();
  state.questions.push({t:'',type,options:defaultOptions(type),settings:defaultSettings(type),_new:true});
  state.editing=state.questions.length-1; state.aiHelp=null; state.helpPick=null;
  render(); focusEditor();
}
function focusEditor(){ setTimeout(()=>{ const el=document.getElementById('editText'); if(el){ el.focus(); el.scrollIntoView({behavior:'smooth',block:'center'}); } },50); }
// A new card with an empty prompt never persists; drop it when we leave it.
function dropEmptyDraft(){
  if(state.editing==null)return;
  const q=state.questions[state.editing];
  if(q&&q._new&&!(q.t||'').trim()){ state.questions.splice(state.editing,1); state.editing=null; }
}
// Commit any open question edit, then move to the named phase. Used by the
// forward buttons so an in-progress question is never lost on navigation.
function commitThenGo(phase){
  if(state.editing!=null){
    const q=state.questions[state.editing];
    if((q.t||'').trim()){ delete q._new; state.editing=null; persistItems(); }
    else dropEmptyDraft();
  }
  go(phase);
}
async function suggestQ(){
  if(state.phase!=='build')state.phase='build';
  dropEmptyDraft();
  const ideas=[{t:'How confident do you feel about your decision to enroll?',type:'Rating Scale',options:null},{t:'What is the main reason you chose us over other schools?',type:'Long Answer',options:null},{t:'Which of these influenced your decision? (Choose all that apply)',type:'Checkboxes',options:['Cost','Location','Reputation','Financial aid']}];
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
  if(QTYPES[label])return label;                       // already a catalog key
  if(t.includes('checkbox'))return 'Checkboxes';
  if(t.includes('dropdown'))return 'Dropdown';
  if(t.includes('multiple')||t.includes('single choice'))return 'Multiple Choice';
  if(t.includes('likert'))return 'Likert Scale';
  if(t.includes('nps')||t.includes('net promoter'))return 'NPS';
  if(t.includes('rating')||t.includes('scale')||t.includes('slider'))return 'Rating Scale';
  if(t.includes('rank'))return 'Ranking';
  if(t.includes('matrix')||t.includes('grid'))return 'Matrix/Grid';
  if(t.includes('true')||t.includes('false'))return 'True/False';
  if(t.includes('yes')||t.includes('boolean'))return 'Yes/No';
  if(t.includes('long')||t.includes('comment')||t.includes('essay')||t.includes('paragraph'))return 'Long Answer';
  if(t.includes('date'))return 'Date';
  if(t.includes('email'))return 'Email';
  if(t.includes('phone'))return 'Phone';
  if(t.includes('number')||t.includes('numeric'))return 'Numeric';
  if(t.includes('demographic'))return 'Demographic';
  if(t.includes('open')||t.includes('short'))return 'Short Answer';
  return 'Short Answer';
}
function removeQ(i){withTicker(()=>{state.questions.splice(i,1);if(state.editing===i)state.editing=null;else if(state.editing!=null&&state.editing>i)state.editing--;});render();persistItems();toast('Question removed.');}
function editQ(i){ if(state.editing!=null&&state.editing!==i){ const c=state.questions[state.editing]; if(c&&c._new&&!(c.t||'').trim()){ state.questions.splice(state.editing,1); if(i>state.editing)i--; } } state.prevStrength=liveStrength(); state.editing=i;state.aiHelp=null;state.itemVerdict=null;render();focusEditor();}
function cancelEdit(){state.editing=null;state.aiHelp=null;state.itemVerdict=null;render();}
function saveEdit(i){
  const q=state.questions[i];
  if(!(q.t||'').trim()){ toast('Write the question first, then save.'); focusEditor(); return; }
  const wasNew=!!q._new; delete q._new;
  state.editing=null;state.aiHelp=null;state.itemVerdict=null;
  const before=state.prevStrength,after=liveStrength();
  if(typeof before==='number'){ state.lastDelta={amount:after-before,dir:after>before?'up':(after<before?'down':'flat')}; }
  state.prevStrength=after;
  render();persistItems();toast(wasNew?'Question added.':'Question saved.');
}
function setEditText(i,v){state.questions[i].t=v;}
function setType(i,v){
  const q=state.questions[i]; q.type=v; const def=QTYPES[v]||{};
  if(def.editOpts){ if(!q.options||!q.options.length)q.options=defaultOptions(v); }
  else { q.options=null; }   // scale/text/structural types carry no editable choices
  const ds=defaultSettings(v); q.settings = ds ? Object.assign({}, ds, q.settings||{}) : null;
  // Seed a default prompt for structural items if the box is empty.
  if(def.structural && !(q.t||'').trim() && QDEFAULT_PROMPT[v]) q.t=QDEFAULT_PROMPT[v];
  render();
}
function setSetting(i,key,val){ const q=state.questions[i]; q.settings=q.settings||{}; q.settings[key]=val; render(); }
function setSettingRaw(i,key,val){ const q=state.questions[i]; q.settings=q.settings||{}; q.settings[key]=val; }  // no re-render (keeps input focus)
function toggleHelpPick(i){ state.helpPick=(state.helpPick===i?null:i); render(); }
function pickHelpType(i,type){ state.helpPick=null; setType(i,type); }
function setOpt(i,oi,v){state.questions[i].options[oi]=v;}
function addOpt(i){state.questions[i].options=state.questions[i].options||[];const t=state.questions[i].type;const w=(t==='Matrix/Grid')?'Row':(t==='Ranking')?'Item':'Option';state.questions[i].options.push(w+' '+(state.questions[i].options.length+1));render();}
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

/* ── Combined per-item verdict ─────────────────────────────────────────────
   One judgement from two reinforcing sources: the deterministic Build Check
   engine (structure, wording, stem FUNCTION) + ReliCheck Intelligence reading
   the item in context (answerability, construct clarity, and cultural/
   contextual fairness for the stated population — what a word list cannot do).
   The AI is given the rule findings so it confirms/refines rather than
   contradicts them. Judged stem-first; response format is secondary. ── */
async function checkItem(i){
  const q=state.questions[i];
  if(!(q.t||'').trim()){ toast('Write the question first.'); focusEditor(); return; }
  state.bc=assessNow();
  const eng=itemFlags(q,i).filter(f=>['moderate','major','critical'].includes(f.severity));
  state.aiHelp=null;
  state.itemVerdict={i,busy:true,eng,ai:null,reason:'',applied:false};
  render();
  if(!PERSIST.on){ state.itemVerdict={i,busy:false,eng,ai:null,reason:'offline',applied:false}; render(); return; }
  try{
    const r=await DB.call('ai-refine.php',{method:'POST',body:{action:'review',prompt:q.t,type:q.type||'',purpose:state.study.purpose||'',population:state.study.population||'',flags:eng.map(f=>f.flag_label||cleanMsg(f.message))}});
    state.itemVerdict={i,busy:false,eng,ai:r,reason:'',applied:false};
  }catch(e){ state.itemVerdict={i,busy:false,eng,ai:null,reason:e.message||'',applied:false}; }
  render();
}
function applyVerdictRewrite(i){
  const v=state.itemVerdict; if(!v||v.i!==i||!v.ai||!(v.ai.rewrite||'').trim())return;
  v.original=state.questions[i].t; state.questions[i].t=v.ai.rewrite.trim(); v.applied=true;
  render(); persistItems(); toast('Item updated. Re-check to confirm.');
}
function undoVerdictRewrite(i){ const v=state.itemVerdict; if(!v||!v.applied)return; state.questions[i].t=v.original; v.applied=false; render(); persistItems(); }
function dismissVerdict(){ state.itemVerdict=null; render(); }
function itemVerdictBox(i){
  const v=state.itemVerdict; if(!v||v.i!==i)return '';
  const engRows=src=>(src||[]).map(f=>`<div class="vrow"><span class="vdim">checker</span><div class="vtx"><b>${esc(f.flag_label||cleanMsg(f.message))}.</b> ${esc(f.why_it_matters||'')}${f.suggestion?`<div class="vfix">Fix: ${esc(f.suggestion)}</div>`:''}</div></div>`).join('');
  if(v.busy){
    return `<div class="verdict"><div class="vh work"><span class="vdot"></span>Checking this item… <span class="faint" style="font-weight:600">rules + ReliCheck Intelligence</span></div><div class="vbody">${v.eng.length?`<div class="vgrp"><div class="vgk">What the checks found</div>${engRows(v.eng)}</div>`:''}<div class="faint" style="font-size:13px;margin-top:12px">Reading the item in the context of your purpose and population…</div></div></div>`;
  }
  const ai=v.ai, aiNotes=(ai&&ai.notes)||[];
  const solid=!v.eng.length&&ai&&ai.verdict==='solid'&&!aiNotes.length;
  const aiRows=aiNotes.map(n=>`<div class="vrow"><span class="vdim">${esc(n.dimension||'clarity')}</span><div class="vtx">${esc(n.note)}</div></div>`).join('');
  const aiBlock = ai
    ? (aiNotes.length
        ? `<div class="vgrp"><div class="vgk">Reading it in context · ReliCheck Intelligence</div>${aiRows}</div>`
        : (v.eng.length?'':`<div class="vgrp"><div class="vgk">Reading it in context · ReliCheck Intelligence</div><div class="vrow"><span class="vdim">solid</span><div class="vtx">Reads as a clear, answerable item for your population.</div></div></div>`))
    : `<div class="vgrp"><div class="vgk">ReliCheck Intelligence</div><div class="vrow"><div class="vtx faint">Unavailable right now${v.reason&&v.reason!=='offline'?' ('+esc(v.reason)+')':''} — showing the rule checks only.</div></div></div>`;
  const rw = (ai&&(ai.rewrite||'').trim())
    ? `<div class="vrw"><div class="vrk">Suggested rewrite</div><div class="vrt">${esc(ai.rewrite.trim())}</div><div class="vacts">${v.applied?`<button class="btn sm" onclick="undoVerdictRewrite(${i})">Undo</button>`:`<button class="btn primary sm" onclick="applyVerdictRewrite(${i})">Apply rewrite</button>`}<button class="btn sm" onclick="dismissVerdict()">Dismiss</button></div></div>`
    : `<div class="vacts" style="margin-top:12px"><button class="btn sm" onclick="checkItem(${i})">Re-check</button><button class="btn sm" onclick="dismissVerdict()">Dismiss</button></div>`;
  return `<div class="verdict"><div class="vh ${solid?'ok':'work'}"><span class="vdot"></span>${solid?'This item is solid':'This item needs work'}</div>
    <div class="vbody">
      ${v.eng.length?`<div class="vgrp"><div class="vgk">What the checks found</div>${engRows(v.eng)}</div>`:''}
      ${aiBlock}
      ${rw}
    </div>
    <div class="vfoot">Judged stem-first — the response format is secondary. The checker covers structure and wording; ReliCheck Intelligence reads meaning, construct, and cultural fit for your population.</div>
  </div>`;
}

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
    const sr=state.siriResult, stale=state.siriStale;
    let body;
    if(!sr){
      body=`<div style="padding:18px 0 6px">
        <p style="font-size:14.5px;color:var(--ink-2);line-height:1.6;margin-bottom:20px">Run the readiness check to see your launch score — question design plus deployment setup, 100 points total.</p>
        <button class="btn primary" onclick="closeReview();go('analyze');setTimeout(runSiriCheck,200)">Run readiness check →</button>
      </div>`;
    } else {
      const score=sr.total!=null?Math.round(sr.total):Math.round(sr.siri||0);
      const hasTotal=sr.total!=null;
      const band=hasTotal?(sr.total_band||''):'';
      const color=siriBandColor(hasTotal?(sr.total_band_key||''):'');
      const blockers=sr.deployment_blocker_count||0;
      const gated=(sr.total_band_key==='codes'||sr.total_band_key==='limited');
      const capped=!!sr.total_band_was_capped;
      const flags=(sr.flags||[]).filter(f=>['critical','high'].includes(f.severity)).slice(0,4);
      const flagHtml=flags.length?flags.map(f=>'<div class="fixitem" style="margin-bottom:8px"><div class="fi">!</div><div><div class="ft" style="font-size:13.5px">'+esc(f.message)+'</div>'+fixFlagBtn(f)+'</div></div>').join(''):'';
      const staleNote=stale?'<p style="font-size:12px;color:var(--warn);margin-bottom:10px">Results are out of date. Re-run for current score.</p>':'';
      const bandKey=hasTotal?(sr.total_band_key||''):'';
      const ready=(bandKey==='good'||bandKey==='strong')&&!sr.has_validity_problem&&!blockers;
      const statusLine=ready
        ?'<div class="trust ok" style="margin:12px 0 10px"><span class="ti">✓</span><div>No deployment blockers.</div></div>'
        :'<div class="trust hold" style="margin:12px 0 10px"><span class="ti">!</span><div>'+esc(sr.blocker_headline||sr.total_band_cap_reason||(blockers+' deployment blocker'+(blockers===1?'':'s')+' detected.')||'Resolve the flagged items before publishing.')+'</div></div>';
      body=staleNote+statusLine+flagHtml
        +'<div style="display:flex;gap:8px;margin-top:16px;flex-wrap:wrap">'
        +'<button class="btn sm" onclick="closeReview();go(\'analyze\')">Full report →</button>'
        +'<button class="btn sm" onclick="runSiriCheck()">'+(stale?'Re-run':'Re-run')+'</button>'
        +'</div>';
    }
    const gatedHdr=sr&&(sr.total_band_key==='codes'||sr.total_band_key==='limited');
    const hdrNum=sr&&sr.total!=null?(gatedHdr?'—':Math.round(sr.total)):'-';
    r.innerHTML='<div class="rv-head">'
      +(sr?'<span class="rv-n" style="font-family:var(--serif)'+(gatedHdr?';color:var(--bad)':'')+'">'+hdrNum+'</span><div><div class="rv-w">'+(sr.total!=null?(sr.total_band||''):'')+'</div><div class="rv-sub">SIRI Launch Readiness</div></div>'
          :'<div><div class="rv-w" style="font-size:15px;font-weight:750">Launch Readiness</div><div class="rv-sub">SIRI · not yet run</div></div>')
      +'<button class="cx" onclick="closeReview()">&times;</button></div>'
      +'<div class="rv-body">'+body+'</div>';
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

/* Analyze (pre-launch readiness checker) · Launch (deploy) · Results (post-response) */
// Analyze = the pre-launch readiness check. It runs the real SIRI Launch Check so
// you fix anything that would weaken your data BEFORE you send the survey out.
function viewAnalyzeCheck(){
  const hasQ=(state.questions||[]).length>0;
  return `
    <div class="eyebrow">Analyze · readiness check</div>
    <h1 class="title">Is your survey ready?</h1>
    <p class="lede">Before you launch, ReliCheck checks that your survey will produce clean, trustworthy data — question design plus deployment readiness. Resolve anything flagged, then continue to Launch.</p>
    ${hasQ?siriCard():`<div class="notice" style="max-width:760px"><div class="ni">✎</div><div><div style="font-weight:700;font-size:14.5px">No questions yet</div><p class="muted" style="font-size:14px;margin-top:3px">Add some questions in Build, then come back to check readiness.</p></div></div>`}
    <div class="btn-row" style="margin-top:24px"><button class="btn" onclick="go('build')">← Back to Build</button><div class="spacer"></div><button class="btn primary lg" onclick="go('launch')">Continue to Launch →</button></div>`;
}
function viewLaunch(){
  const ds=state.deploymentSettings, live=!!(ds&&ds.link_key);
  const link=live?('relichecksurvey.com/s/'+ds.link_key):'';
  const open=!!(ds&&ds.responses_open);
  const linkBlock=live
    ? `<div class="notice"><div class="ni">🔗</div><div style="flex:1"><div style="font-weight:700;font-size:14.5px">Your survey link</div>
        <div class="linkbox"><code>${esc(link)}</code><button class="btn sm" onclick="copyLink('${esc(link)}')">Copy</button></div>
        <div style="margin-top:11px;font-size:14px;color:var(--ink-2)">Responses are <b>${open?'open':'closed'}</b>. <button class="ailink" onclick="toggleOpen()">${open?'Close':'Open'} collection</button></div></div></div>`
    : `<div class="notice"><div class="ni">🚀</div><div style="flex:1"><div style="font-weight:700;font-size:14.5px">Ready to send it out?</div><p class="muted" style="font-size:14px;margin:3px 0 11px">Publishing creates a shareable link. You can open and close responses any time.</p><button class="btn primary" onclick="publishSurvey()">Publish &amp; get the link</button></div></div>`;
  const share=live?`<div class="sec-row" style="margin-top:24px"><h2 class="sec">Share &amp; collect</h2></div>
    <div class="share" style="grid-template-columns:repeat(5,1fr)">
      <button onclick="shareLinkModal('${esc(link)}')"><span class="si">↗</span><span class="st">Share link</span></button>
      <button onclick="shareEmail('${esc(link)}')"><span class="si">@</span><span class="st">Email it</span></button>
      <button onclick="showQR('${esc(link)}')"><span class="si">⊞</span><span class="st">QR code</span></button>
      <button onclick="invitePanel('${esc(link)}')"><span class="si">⊕</span><span class="st">Invite list</span></button>
      <button onclick="window.open('https://'+${JSON.stringify(link)},'_blank')"><span class="si">→</span><span class="st">Preview</span></button>
    </div>`:'';
  // Instrument exports are available anytime — you can hand the blueprint off before publishing.
  const exportsBlock=`<div class="sec-row" style="margin-top:24px"><h2 class="sec">Export the instrument</h2></div>
    <p class="muted" style="font-size:14px;max-width:740px;margin-bottom:14px">Take your survey out for offline use or another platform. Your questions and answer formats carry over.</p>
    <div class="share" style="grid-template-columns:repeat(3,1fr);max-width:560px">
      <button onclick="exportInstrumentDoc()"><span class="si">↓</span><span class="st">Word / PDF</span></button>
      <button onclick="exportInstrumentCsv()"><span class="si">⊟</span><span class="st">CSV / Excel</span></button>
      <button onclick="exportInstrumentJson()"><span class="si">{ }</span><span class="st">JSON / Qualtrics</span></button>
    </div>`;
  // Intro / consent / branding panel
  const lr=state.study.launchReadiness||{};
  const introEnabled=!!(lr.introEnabled);
  const consentType=lr.consentType||'none';
  const directions=(lr.instructions&&lr.instructions.text)||'';
  const consentStatement=(lr.consent&&lr.consent.statement)||'';
  const hideBrand=!!(state.settings&&state.settings.hideBrand);
  const consentRows=consentType!=='none'?`<textarea id="consentText" rows="4" style="width:100%;font-size:14px;padding:9px 11px;border:1px solid #d0d5e0;border-radius:7px;resize:vertical;font-family:inherit;box-sizing:border-box;${consentType!=='custom'?'background:#f5f6fa;color:#5a607a;':''}" ${consentType!=='custom'?'readonly':''} placeholder="Enter consent statement..." oninput="setConsentStatement(this.value)">${esc(consentStatement)}</textarea>${consentType!=='custom'?`<p class="faint" style="font-size:12.5px;margin-top:4px">This is a template. Switch to Custom to edit it.</p>`:''}`:''
  const introInner=introEnabled?`
    <div style="margin-top:16px;padding-top:14px;border-top:1px solid #e8e8ef">
      <label style="font-size:13.5px;font-weight:600;color:var(--ink-2);display:block;margin-bottom:5px">Directions <span style="font-weight:400">(optional)</span></label>
      <textarea id="introDirections" rows="3" style="width:100%;font-size:14px;padding:9px 11px;border:1px solid #d0d5e0;border-radius:7px;resize:vertical;font-family:inherit;box-sizing:border-box" placeholder="e.g. This survey takes about 5 minutes. Your responses are anonymous." oninput="setIntroDirections(this.value)">${esc(directions)}</textarea>
      <div style="margin-top:16px">
        <div style="font-size:13.5px;font-weight:600;color:var(--ink-2);margin-bottom:8px">Consent statement</div>
        <div style="display:flex;gap:18px;flex-wrap:wrap;margin-bottom:10px">
          <label style="display:flex;align-items:center;gap:6px;font-size:14px;cursor:pointer"><input type="radio" name="consentType" value="none" ${consentType==='none'?'checked':''} onchange="setConsentType('none')"> None</label>
          <label style="display:flex;align-items:center;gap:6px;font-size:14px;cursor:pointer"><input type="radio" name="consentType" value="irb" ${consentType==='irb'?'checked':''} onchange="setConsentType('irb')"> IRB / research</label>
          <label style="display:flex;align-items:center;gap:6px;font-size:14px;cursor:pointer"><input type="radio" name="consentType" value="hipaa" ${consentType==='hipaa'?'checked':''} onchange="setConsentType('hipaa')"> HIPAA notice</label>
          <label style="display:flex;align-items:center;gap:6px;font-size:14px;cursor:pointer"><input type="radio" name="consentType" value="custom" ${consentType==='custom'?'checked':''} onchange="setConsentType('custom')"> Custom</label>
        </div>
        ${consentRows}
      </div>
    </div>`:'';
  const introPanel=`<div class="sec-row" style="margin-top:24px"><h2 class="sec">Introduction &amp; consent</h2></div>
    <div class="card pad" style="max-width:760px">
      <label style="display:flex;align-items:center;gap:9px;cursor:pointer;font-size:14.5px;font-weight:700">
        <input type="checkbox" id="chkIntro" onchange="toggleIntro(this.checked)" ${introEnabled?'checked':''}>
        Show an introduction page before the first question
      </label>
      <p class="muted" style="font-size:13.5px;margin:4px 0 0 23px">Directions, study context, IRB disclosure, or a consent statement respondents read before answering.</p>
      ${introInner}
      <div style="margin-top:14px;border-top:1px solid #e8e8ef;padding-top:14px">
        <label style="display:flex;align-items:center;gap:9px;cursor:pointer;font-size:14px">
          <input type="checkbox" id="chkHideBrand" onchange="setHideBrand(this.checked)" ${hideBrand?'checked':''}>
          Hide the ReliCheck logo at the top of the survey
        </label>
        <p class="faint" style="font-size:12.5px;margin:3px 0 0 23px">The "Powered by ReliCheck" attribution at the bottom always appears.</p>
      </div>
    </div>`;
  const readyPct=state.siriResult&&state.siriResult.total!=null?Math.round(state.siriResult.total):null;
  const readyNote=readyPct!=null?`<button class="ailink" onclick="go('analyze')">Readiness ${readyPct}/100 — review</button>`:`<button class="ailink" onclick="go('analyze')">← Run the readiness check first</button>`;
  return `
    <div class="eyebrow">Launch</div>
    <h1 class="title">Send it out</h1>
    <p class="lede">Publish your survey to get a shareable link, then share it however you like. Answers flow into Results automatically. ${readyNote}</p>
    ${linkBlock}
    ${share}
    ${introPanel}
    ${live?`<div class="card pad" style="max-width:760px;margin:18px 0 0"><div style="font-weight:700;margin-bottom:6px">Before you send it wide</div><p class="muted" style="font-size:14px">Send it to 3 to 5 people first and watch where they pause. The Coach has more on this.</p></div>`:''}
    ${exportsBlock}
    <div class="btn-row" style="margin-top:24px"><button class="btn" onclick="go('analyze')">← Back to Analyze</button><div class="spacer"></div>${live?'':`<button class="btn" onclick="simulate()">▶ Simulate responses (demo)</button>`}<button class="btn primary lg" onclick="go('results')">Go to Results →</button></div>`;
}
// ── Intro / consent / branding helpers ──────────────────────────────────────
const CONSENT_PRESETS={
  irb:`This survey is part of a research study. Your participation is completely voluntary — you may decline to answer any question or stop at any time without penalty. Your responses will remain confidential and used only for research purposes. No identifying information will be linked to your responses. Contact the research team with any questions before continuing.`,
  hipaa:`Some responses you provide may constitute protected health information (PHI) under HIPAA. Your information will be handled in accordance with applicable federal and state privacy regulations. Participation is voluntary. Your responses will not be disclosed to unauthorized parties except as required by law.`
};
let _introTimer=null;
function _lr(){ state.study.launchReadiness=state.study.launchReadiness||{}; return state.study.launchReadiness; }
function toggleIntro(checked){ _lr().introEnabled=checked; render(); queueSaveIntro(); }
function setConsentType(type){
  const lr=_lr(); lr.consentType=type;
  if(type==='irb'||type==='hipaa'){ lr.consent=lr.consent||{}; lr.consent.statement=CONSENT_PRESETS[type]; }
  else if(type==='none'){ lr.consent=lr.consent||{}; lr.consent.statement=''; }
  render(); queueSaveIntro();
}
function setIntroDirections(text){ const lr=_lr(); lr.instructions=lr.instructions||{}; lr.instructions.text=text; queueSaveIntro(); }
function setConsentStatement(text){ const lr=_lr(); lr.consent=lr.consent||{}; lr.consent.statement=text; queueSaveIntro(); }
function setHideBrand(checked){ state.settings=state.settings||{}; state.settings.hideBrand=checked; render(); queueSaveIntro(); }
function queueSaveIntro(){ clearTimeout(_introTimer); _introTimer=setTimeout(saveIntroSettings,700); }
async function saveIntroSettings(){
  const lr=state.study.launchReadiness||{};
  const merged=Object.assign({},state.settings,{launchReadiness:Object.assign({},lr),hideBrand:!!(state.settings&&state.settings.hideBrand)});
  state.settings=merged; state.study.launchReadiness=merged.launchReadiness;
  if(!PERSIST.on||!state.projectId)return;
  await DB.call('project-update.php',{method:'POST',body:{id:state.projectId,settings:merged}}).catch(e=>degrade(e.message));
}
// SVG ring gauge for the SIRI score. r=52, viewBox 120×120, starts at 12 o'clock.
function siriRing(score,colorKey,gated){
  const r=52,cx=60,cy=60,circ=2*Math.PI*r;
  const filled=gated?0:Math.min(Math.max(score,0),100)/100*circ;
  const stroke=colorKey==='green'?'var(--good)':(colorKey==='amber'?'var(--warn)':'var(--bad)');
  const label=gated?'—':score;
  return `<svg viewBox="0 0 120 120" width="148" height="148" aria-hidden="true">
    <circle cx="${cx}" cy="${cy}" r="${r}" fill="none" stroke="var(--soft)" stroke-width="9"/>
    ${gated?'':`<circle cx="${cx}" cy="${cy}" r="${r}" fill="none" stroke="${stroke}" stroke-width="9"
      stroke-dasharray="${filled.toFixed(1)} ${circ.toFixed(1)}" stroke-linecap="round"
      transform="rotate(-90 ${cx} ${cy})"/>`}
    <text x="${cx}" y="${cy}" text-anchor="middle" dominant-baseline="central"
      style="font-family:var(--serif)" font-size="${gated?'40':'32'}" font-weight="700" fill="${gated?'var(--bad)':'var(--ink)'}">${label}</text>
  </svg>`;
}
// The SIRI Launch Check (100-pt readiness) card — runs the real engine. Shown on the Analyze (pre-launch readiness) page.
function siriBandColor(key){ return (key==='strong'||key==='good')?'green':(key==='caution'?'amber':'red'); }
function siriCard(){
  const r=state.siriResult;
  if(!(window.LaunchCheck&&window.LaunchCheck.assess)){
    return `<div class="notice" style="max-width:820px"><div class="ni">⚠️</div><div><div style="font-weight:700;font-size:14.5px">Launch readiness check unavailable</div><p class="muted" style="font-size:14px;margin-top:3px">The SIRI engine did not load. You can still publish.</p></div></div>`;
  }
  if(!r){
    return `<div class="card pad" style="max-width:820px;margin-bottom:18px"><div style="display:flex;align-items:center;gap:14px;flex-wrap:wrap">
      <div style="flex:1"><div style="font-weight:750;font-size:16px">Launch readiness check (SIRI)</div>
      <p class="muted" style="font-size:14px;margin-top:4px">A 100-point pre-launch review: question design plus deployment readiness, with any blockers to fix before you publish.</p></div>
      <button class="btn primary" onclick="runSiriCheck()">Run Launch Check →</button></div></div>`;
  }
  const hasTotal=(r.total!=null);
  const score=hasTotal?Math.round(r.total):Math.round(r.siri);
  const band=hasTotal?(r.total_band||''):'';
  const bandKey=hasTotal?(r.total_band_key||''):'';
  const gated=(bandKey==='codes'||bandKey==='limited'); // no valid score — show "—" not a number
  const color=siriBandColor(bandKey);
  const blockers=r.deployment_blocker_count||0;
  const domains=(r.domains||[]).map(d=>{const pct=Math.round((d.points/(d.max||10))*100);return `<div class="dom"><div class="dom-head"><span class="nm">${esc(d.name)}</span><span class="pts">${d.points} / ${d.max||10}</span></div><div class="meter"><span style="width:${pct}%"></span></div></div>`;}).join('');
  const flags=(r.flags||[]).filter(f=>['critical','high','medium'].includes(f.severity)).slice(0,8);
  const capped=!!r.total_band_was_capped;
  // "Ready to publish" only when the band is genuinely good/strong AND there are no
  // validity problems — never beside a held-down band.
  const ready=(bandKey==='good'||bandKey==='strong')&&!r.has_validity_problem&&!blockers;
  const body = ready
    ? `<div class="trust ok" style="margin-top:14px"><span class="ti">✓</span><div>No deployment blockers. Your survey is ready to publish.</div></div>`
    : `<div class="trust hold" style="margin-top:14px"><span class="ti">!</span><div>${esc(r.blocker_headline||r.total_band_cap_reason||(blockers+' deployment blocker'+(blockers===1?'':'s')+' detected.')||'Resolve the flagged items before publishing.')}</div></div>`;
  const flagList = flags.length?`<div style="margin-top:6px">${flags.map(f=>`<div class="fixitem"><div class="fi">!</div><div><div class="ft">${esc(f.domain)}: ${esc(f.message)}</div>${f.suggestion?`<div class="fs">${esc(f.suggestion)}</div>`:''} ${fixFlagBtn(f)}</div></div>`).join('')}</div>`:'';
  const stale=state.siriStale;
  return `<div class="card pad" style="max-width:820px;margin-bottom:18px">
    <div style="display:flex;align-items:flex-start;gap:32px;flex-wrap:wrap">
      <div style="display:flex;flex-direction:column;align-items:center;gap:10px;min-width:148px">
        ${siriRing(score,color,gated)}
        ${hasTotal&&band?`<span style="display:inline-flex;align-items:center;gap:7px;padding:5px 14px;border-radius:999px;background:${color==='green'?'var(--good-soft)':(color==='amber'?'var(--warn-soft)':'var(--bad-soft)')};font-size:13px;font-weight:700;color:${color==='green'?'var(--good)':(color==='amber'?'var(--warn)':'var(--bad)')}">${esc(band)}</span>`:''}
      </div>
      <div style="flex:1;min-width:220px;padding-top:8px">
        <div style="font-family:var(--serif);font-size:22px;font-weight:700;color:var(--ink);line-height:1.2">Launch readiness${stale?` <span style="font-size:13px;font-weight:600;color:var(--warn)">· out of date</span>`:''}</div>
        <div style="font-size:13px;color:var(--ink-3);margin:4px 0 16px">SIRI Launch Check · ${hasTotal?'survey design + deployment readiness':'readiness lenses'}</div>
        ${body}
        <button class="btn sm" style="margin-top:14px" onclick="runSiriCheck()">${stale?'Re-run check':'Re-run'}</button>
      </div>
    </div>
    ${flagList?`<div style="margin-top:20px;border-top:1px solid var(--line);padding-top:18px">${flagList}</div>`:''}
    <details class="tech" style="margin-top:12px"><summary>Readiness domains</summary><div class="tbody">${domains||'<p class="faint">No domain detail.</p>'}</div></details>
  </div>`;
}
// ── Analyze flag fix routing ─────────────────────────────────────────────────
function fixFlagBtn(f){
  const msg=f.message||'', key=f.domainKey||'';
  if(key==='purpose'&&(msg.includes('No survey purpose')||msg.includes('purpose statement is very brief')))
    return `<button class="fa" onclick="openFixPopup('purpose')">Fix here →</button>`;
  if(key==='purpose'&&msg.includes('No intended audience'))
    return `<button class="fa" onclick="openFixPopup('population')">Fix here →</button>`;
  if(key==='deployment'&&(msg.includes('No consent')||msg.includes('privacy statement')))
    return `<button class="fa" onclick="go('launch')">Fix in Launch →</button>`;
  if(key==='coverage'&&(msg.includes('not mapped to any construct')||msg.includes('three or more items')))
    return `<button class="fa" onclick="go('build');setTimeout(openGrouping,120)">Fix in Build →</button>`;
  return `<button class="fa" onclick="go('build')">Fix in Build →</button>`;
}
function openFixPopup(type){
  const isPurpose=type==='purpose';
  const title=isPurpose?'Survey purpose':'Intended audience';
  const label=isPurpose
    ?"What do you want to learn, and which decision will this survey inform?"
    :"Who will answer this survey? (e.g. K-12 teachers, hospital nursing staff, enrolled undergraduates)";
  const current=isPurpose?(state.study.purpose||''):(state.study.population||'');
  const field=isPurpose?'<textarea id="fpVal" rows="4" style="width:100%;font-size:14px;padding:9px 11px;border:1px solid #d0d5e0;border-radius:7px;resize:vertical;font-family:inherit;box-sizing:border-box;margin-top:8px" placeholder="e.g. Understand why students disengage mid-semester to inform advisor outreach.">'+(current.replace(/</g,'&lt;'))+'</textarea>'
    :'<input id="fpVal" type="text" style="width:100%;font-size:14px;padding:9px 11px;border:1px solid #d0d5e0;border-radius:7px;font-family:inherit;box-sizing:border-box;margin-top:8px" placeholder="e.g. Full-time nursing staff at mid-sized hospitals" value="'+(current.replace(/"/g,'&quot;'))+'">';
  const ov=document.createElement('div'); ov.className='qr-ov'; ov.id='fpOv';
  ov.innerHTML=`<div class="qr-modal" style="max-width:500px">
    <div class="qr-head"><div style="font-weight:750;font-size:16px">${title}</div><button class="cx" onclick="closeFixPopup()">&times;</button></div>
    <div style="padding:4px 0 16px">
      <p style="font-size:14px;color:var(--ink-2);margin:0">${label}</p>
      ${field}
    </div>
    <div class="qr-btns">
      <button class="btn primary" onclick="saveFixPopup('${type}')">Save &amp; re-run check</button>
      <button class="btn" onclick="closeFixPopup()">Cancel</button>
    </div>
  </div>`;
  ov.addEventListener('click',e=>{ if(e.target===ov)closeFixPopup(); });
  document.body.appendChild(ov);
  setTimeout(()=>{ const el=document.getElementById('fpVal'); if(el){ el.focus(); if(el.tagName==='TEXTAREA'){ el.setSelectionRange(el.value.length,el.value.length); } } },80);
}
function closeFixPopup(){ const o=document.getElementById('fpOv'); if(o)o.remove(); }
async function saveFixPopup(type){
  const el=document.getElementById('fpVal'); if(!el)return;
  const val=el.value.trim();
  if(type==='purpose') state.study.purpose=val;
  else state.study.population=val;
  closeFixPopup();
  if(PERSIST.on&&state.projectId){
    const body={id:state.projectId}; body[type]=val;
    await DB.call('project-update.php',{method:'POST',body}).catch(e=>degrade(e.message));
  }
  toast('Saved. Re-running readiness check…');
  runSiriCheck();
}
function publishSurvey(){
  if(!PERSIST.on){ state.deploymentSettings={link_key:'demo-'+((state.projectId)||'x'),responses_open:true}; render(); toast('Published (demo).'); return; }
  if(!state.projectId){ toast('Add a question first, then publish.'); return; }
  toast('Publishing…');
  DB.call('project-publish.php',{method:'POST',body:{project_id:state.projectId,override:true}}).then(r=>{
    const d=r.deployment||(r.link_key?{link_key:r.link_key,responses_open:!!r.responses_open}:r);
    state.deploymentSettings=d||{}; render(); toast('Published — your link is ready.');
  }).catch(e=>{ degrade(e.message); toast('Could not publish: '+e.message); });
}
function toggleOpen(){
  const ds=state.deploymentSettings; if(!ds||!ds.link_key)return;
  const open=!ds.responses_open;
  if(!PERSIST.on){ ds.responses_open=open; render(); return; }
  DB.call('project-open.php',{method:'POST',body:{project_id:state.projectId,open}}).then(()=>{ ds.responses_open=open; render(); toast(open?'Responses open':'Responses closed'); }).catch(e=>degrade(e.message));
}
function copyLink(l){ try{ navigator.clipboard.writeText('https://'+l); }catch(e){} toast('Link copied.'); }
// Email it — open the user's mail client with the link prefilled.
function shareEmail(link){
  const url='https://'+link;
  const subj=encodeURIComponent('You are invited to take a survey');
  const body=encodeURIComponent('Please take a moment to complete this survey:\n\n'+url+'\n\nThank you.');
  window.location.href='mailto:?subject='+subj+'&body='+body;
}
// QR code — generated client-side (qrcodejs); the link never leaves the browser.
function loadQR(){
  if(window.QRCode)return Promise.resolve(window.QRCode);
  if(window._qrP)return window._qrP;
  window._qrP=new Promise((res,rej)=>{ const s=document.createElement('script'); s.src='https://cdn.jsdelivr.net/npm/qrcodejs@1.0.0/qrcode.min.js'; s.onload=()=>res(window.QRCode); s.onerror=()=>rej(new Error('qr load failed')); document.head.appendChild(s); });
  return window._qrP;
}
function showQR(link){
  const url='https://'+link;
  const ov=document.createElement('div'); ov.className='qr-ov';
  ov.innerHTML=`<div class="qr-modal">
    <div class="qr-head"><div style="font-weight:750;font-size:16px">QR code</div><button class="cx" aria-label="Close">&times;</button></div>
    <div id="qrBox" style="display:flex;justify-content:center;align-items:center;min-height:220px;padding:6px 0;color:var(--ink-3)">Generating…</div>
    <div class="qr-link">${esc(link)}</div>
    <div class="qr-btns"><button class="btn primary sm" id="qrDl" disabled>Download PNG</button><a class="btn sm" href="${esc(url)}" target="_blank" rel="noopener">Open survey</a></div>
    <p class="faint" style="font-size:12.5px;margin-top:10px;text-align:center">Print or display this code for in-person fielding.</p>
  </div>`;
  ov.addEventListener('click',e=>{ if(e.target===ov)ov.remove(); });
  ov.querySelector('.cx').addEventListener('click',()=>ov.remove());
  document.body.appendChild(ov);
  loadQR().then(QR=>{
    const box=ov.querySelector('#qrBox'); if(!box)return; box.innerHTML='';
    const holder=document.createElement('div'); box.appendChild(holder);
    try{ new QR(holder,{text:url,width:220,height:220,correctLevel:QR.CorrectLevel.M}); }
    catch(e){ box.textContent='Could not generate the QR code.'; return; }
    setTimeout(()=>{ const canvas=holder.querySelector('canvas'),img=holder.querySelector('img'); const dataUrl=canvas?canvas.toDataURL('image/png'):(img?img.src:''); if(!dataUrl)return; const dl=ov.querySelector('#qrDl'); dl.disabled=false; dl.addEventListener('click',()=>{ const a=document.createElement('a'); a.href=dataUrl; a.download='survey-qr-'+link.split('/').pop()+'.png'; document.body.appendChild(a); a.click(); a.remove(); }); },250);
  }).catch(()=>{ const box=ov.querySelector('#qrBox'); if(box)box.textContent='Could not load the QR generator.'; });
}
/* ── Deploy + instrument exports — ported from the old system so every deploy
   path works: share/embed link, invite list, Word/PDF, CSV/Excel, JSON. ── */
function instrumentItems(){ return (state.questions||[]).map((q,i)=>({ n:i+1, prompt:q.t||'', type:q.type, required:!!q.required, construct:q.group||'', options:(q.options||[]).slice(), structural:isStructural(q.type), settings:q.settings||{} })); }
function surveySlug(){ return (((state.study.name||'survey').toLowerCase().replace(/[^a-z0-9]+/g,'-').replace(/^-+|-+$/g,''))||'survey'); }
function downloadFile(name,content,mime){ try{ const b=new Blob([content],{type:mime||'text/plain'}); const u=URL.createObjectURL(b); const a=document.createElement('a'); a.href=u; a.download=name; document.body.appendChild(a); a.click(); a.remove(); setTimeout(()=>URL.revokeObjectURL(u),1500); }catch(e){ toast('Could not download the file.'); } }
function exportInstrumentCsv(){
  const items=instrumentItems().filter(it=>!it.structural);
  if(!items.length){ toast('Add questions before exporting'); return; }
  const c=v=>{ v=String(v==null?'':v); return /[",\n]/.test(v)?'"'+v.replace(/"/g,'""')+'"':v; };
  const rows=[['No','Question','Type','Required','Construct','Options']];
  items.forEach(it=>rows.push([it.n,it.prompt,it.type,it.required?'Required':'Optional',it.construct,(it.options||[]).join(' | ')]));
  downloadFile('instrument-'+surveySlug()+'.csv','﻿'+rows.map(r=>r.map(c).join(',')).join('\r\n'),'text/csv');
  toast('Instrument CSV exported');
}
function exportInstrumentJson(){
  const items=instrumentItems(); if(!items.filter(it=>!it.structural).length){ toast('Add questions before exporting'); return; }
  const payload={ title:state.study.name||'Survey', purpose:state.study.purpose||'', population:state.study.population||'', item_count:items.filter(it=>!it.structural).length,
    items:items.map((it,i)=>({position:i+1,kind:it.structural?'section':'question',prompt:it.prompt,type:it.type,required:it.required,construct:it.construct||null,options:it.options||[],settings:it.settings||{}})) };
  downloadFile('instrument-'+surveySlug()+'.json',JSON.stringify(payload,null,2),'application/json');
  toast('Instrument exported for import into another platform');
}
function exportInstrumentDoc(){
  const items=instrumentItems(); if(!items.length){ toast('Add questions before exporting the instrument'); return; }
  const w=window.open('','_blank'); if(!w){ toast('Allow pop-ups to export the instrument'); return; }
  w.document.open(); w.document.write(buildInstrumentDoc(items)); w.document.close();
  const run=()=>{ try{ w.focus(); w.print(); }catch(e){} };
  if(w.document.readyState==='complete') setTimeout(run,200); else w.onload=()=>setTimeout(run,200);
}
function buildInstrumentDoc(items){
  const title=esc(state.study.name||'Survey');
  const purpose=state.study.purpose?'<p class="purpose">'+esc(state.study.purpose)+'</p>':'';
  const body=items.map(it=>{
    if(it.structural) return '<div class="sec">'+esc(it.prompt||'')+'</div>';
    const t=it.type, opts=it.options||[], s=it.settings||{}; let ans='<div class="line"></div>';
    if(t==='Likert Scale'){ const n=s.likertPoints||5; ans='<div class="scale">'+Array.from({length:n},(_,i)=>'<span>'+(i+1)+'</span>').join('')+'</div><div class="anchor">'+esc(s.likertLow||'')+' &rarr; '+esc(s.likertHigh||'')+'</div>'; }
    else if(t==='Rating Scale'){ const m=s.ratingStars||5; ans='<div class="scale">'+Array.from({length:m},()=>'<span>&#9733;</span>').join('')+'</div>'; }
    else if(t==='NPS'){ ans='<div class="scale">'+Array.from({length:11},(_,i)=>'<span>'+i+'</span>').join('')+'</div>'; }
    else if(['Multiple Choice','Dropdown','Yes/No','True/False','Demographic'].includes(t)){ ans='<ul class="opts">'+(opts.length?opts:['Option 1','Option 2']).map(o=>'<li>&#9711; '+esc(o)+'</li>').join('')+'</ul>'; }
    else if(t==='Checkboxes'){ ans='<ul class="opts">'+(opts.length?opts:['Option 1']).map(o=>'<li>&#9633; '+esc(o)+'</li>').join('')+'</ul>'; }
    else if(t==='Ranking'){ ans='<ol class="opts num">'+(opts.length?opts:['Item 1']).map(o=>'<li>'+esc(o)+'</li>').join('')+'</ol>'; }
    else if(t==='Consent'){ ans='<div class="opts">&#9633; '+esc(opts[0]||'I agree to participate.')+'</div>'; }
    return '<div class="q"><div class="qp">'+it.n+'. '+esc(it.prompt)+(it.required?' <span class="req">*</span>':'')+'</div>'+ans+'</div>';
  }).join('');
  return '<!doctype html><html><head><meta charset="utf-8"><title>'+title+'</title><style>'
    +'body{font-family:Georgia,serif;max-width:720px;margin:40px auto;padding:0 24px;color:#16181d;line-height:1.5}'
    +'h1{font-size:24px;margin:0 0 6px}.purpose{color:#54585f;font-style:italic;margin:0 0 20px}'
    +'.q{margin:0 0 20px;page-break-inside:avoid}.qp{font-weight:bold;margin-bottom:8px}.req{color:#c1271f}'
    +'.opts{list-style:none;margin:6px 0 0 8px;padding:0}.opts li{margin:4px 0}ol.opts.num{list-style:decimal;margin-left:26px}'
    +'.scale span{display:inline-block;width:30px;height:30px;line-height:30px;text-align:center;border:1px solid #888;border-radius:6px;margin-right:6px}'
    +'.anchor{color:#54585f;font-size:13px;margin-top:6px}.line{border-bottom:1px solid #888;height:28px;margin-top:6px}'
    +'.sec{font-weight:bold;border-top:2px solid #16181d;padding-top:12px;margin:24px 0 8px}@media print{body{margin:0}}'
    +'</style></head><body><h1>'+title+'</h1>'+purpose+body+'<p style="margin-top:30px;color:#888;font-size:12px">Generated by ReliCheck</p></body></html>';
}
// Share-link modal with copyable embed code.
function shareLinkModal(link){
  const url='https://'+link;
  const embed='<iframe src="'+url+'" width="100%" height="800" frameborder="0" style="border:1px solid #ddd;border-radius:8px"></iframe>';
  const ov=document.createElement('div'); ov.className='qr-ov';
  ov.innerHTML=`<div class="qr-modal" style="width:460px">
    <div class="qr-head"><div style="font-weight:750;font-size:16px">Share link</div><button class="cx" aria-label="Close">&times;</button></div>
    <div class="qr-link" style="text-align:left">${esc(url)}</div>
    <div class="qr-btns" style="justify-content:flex-start;flex-wrap:wrap"><button class="btn primary sm" id="slCopy">Copy link</button><button class="btn sm" onclick="shareEmail('${esc(link)}')">Email</button><a class="btn sm" href="${esc(url)}" target="_blank" rel="noopener">Open</a></div>
    <div class="faint" style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.05em;margin:16px 0 6px">Embed on a web page</div>
    <textarea readonly style="width:100%;height:84px;border:1.5px solid var(--line);border-radius:8px;padding:9px 11px;font-family:ui-monospace,Menlo,monospace;font-size:12px;resize:vertical" id="slEmbed">${esc(embed)}</textarea>
    <div class="qr-btns" style="justify-content:flex-start;margin-top:10px"><button class="btn sm" id="slEmbedCopy">Copy embed code</button></div>
  </div>`;
  ov.addEventListener('click',e=>{ if(e.target===ov)ov.remove(); });
  ov.querySelector('.cx').addEventListener('click',()=>ov.remove());
  document.body.appendChild(ov);
  ov.querySelector('#slCopy').addEventListener('click',()=>{ try{navigator.clipboard.writeText(url);}catch(e){} toast('Link copied.'); });
  ov.querySelector('#slEmbedCopy').addEventListener('click',()=>{ try{navigator.clipboard.writeText(embed);}catch(e){} toast('Embed code copied.'); });
  ov.querySelector('#slEmbed').addEventListener('focus',function(){ this.select(); });
}
// Invite a list — composes one email with the recipients on BCC.
function invitePanel(link){
  const url='https://'+link;
  const ov=document.createElement('div'); ov.className='qr-ov';
  ov.innerHTML=`<div class="qr-modal" style="width:460px">
    <div class="qr-head"><div style="font-weight:750;font-size:16px">Invite a respondent list</div><button class="cx" aria-label="Close">&times;</button></div>
    <div class="faint" style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.05em;margin-bottom:6px">Email addresses (one per line, or comma separated)</div>
    <textarea id="invEmails" style="width:100%;height:96px;border:1.5px solid var(--line);border-radius:8px;padding:9px 11px;font-family:inherit;font-size:14px;resize:vertical" placeholder="alex@example.com&#10;sam@example.com"></textarea>
    <div class="qr-btns" style="justify-content:flex-start;margin-top:12px"><button class="btn primary sm" id="invSend">Compose email</button></div>
    <p class="faint" style="font-size:12px;margin-top:10px">Opens your email app with the recipients on BCC and the link in the message.</p>
  </div>`;
  ov.addEventListener('click',e=>{ if(e.target===ov)ov.remove(); });
  ov.querySelector('.cx').addEventListener('click',()=>ov.remove());
  document.body.appendChild(ov);
  ov.querySelector('#invSend').addEventListener('click',()=>{
    const raw=ov.querySelector('#invEmails').value||'';
    const emails=raw.split(/[\s,;]+/).map(s=>s.trim()).filter(s=>/@/.test(s));
    if(!emails.length){ toast('Add at least one email address'); return; }
    const subj=encodeURIComponent('You are invited to take a survey');
    const body=encodeURIComponent('Please take a moment to complete this survey:\n\n'+url+'\n\nThank you.');
    window.location.href='mailto:?bcc='+encodeURIComponent(emails.join(','))+'&subject='+subj+'&body='+body;
    ov.remove();
  });
}
function simulate(){state.responses=142;go('results');toast('142 responses came in.');}
function viewResults(){
  if(state.responses===0)return `
    <div class="eyebrow">Results</div>
    <h1 class="title">Understand the answers</h1>
    <p class="lede">When responses come in, ReliCheck shows what they mean and how much you can trust them, in plain language.</p>
    <div class="notice"><div class="ni">⏳</div><div><div style="font-weight:700;font-size:14.5px">Waiting for responses</div><p class="muted" style="font-size:14px;margin-top:3px">No answers yet. Once a handful arrive, your results appear here with a trust check on the questions that measure the same thing.</p></div></div>
    <div class="btn-row"><button class="btn" onclick="go('launch')">← Back to Launch</button><div class="spacer"></div><button class="btn" onclick="simulate()">▶ Simulate responses (demo)</button></div>`;
  return `
    <div class="eyebrow">Results</div>
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
  analyze:{sub:'Guidance · Analyze',what:'A readiness check before you launch. ReliCheck reviews your survey design and deployment readiness and gives a 100-point score with anything worth fixing first.',why:'Fixing a confusing question or a missing consent statement now is far cheaper than discovering it after people have answered. A ready survey yields data you can actually trust.',tip:'Clear any deployment blockers before launching. Cautions are fine to launch with, but worth a look.',
    prompts:[['What is this score?','A pre-launch readiness index out of 100: your question design plus deployment readiness. It is not your data yet — it is whether your survey is ready to collect good data.'],['What is a deployment blocker?','Something that would make the responses hard to interpret — like placeholder answer options or a required sensitive question with no way to decline. Fix these before launch.']]},
  launch:{sub:'Guidance · Launch',what:'Turn your finished survey into a shareable link and send it to the people you want answers from. Share by link, email, QR, or an invite list, or export the instrument.',why:'A short pilot with a few people catches confusing wording before it reaches everyone.',tip:'Send it to 3 to 5 people first and watch where they hesitate.',
    prompts:[['Can I change it after launch?','Small wording fixes are fine. Avoid changing answer options once responses are coming in, since it makes early and later answers hard to compare.']]},
  results:{sub:'Guidance · Results',what:'Once answers come in, ReliCheck shows what they mean and how much you can trust them.',why:'A result you can trust comes from questions that actually agree with each other. ReliCheck tells you when they do, and when there is not enough data yet.',tip:'Wait until you have a reasonable number of responses before reading too much into the numbers.',
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
