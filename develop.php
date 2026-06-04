<?php
// ════════════════════════════════════════════════════════════════════════
//  ReliCheck Survey Development System.
//
//  Naming (locked):
//   • SDSI, the Survey Design Strength Index: 50-pt design-quality review run
//     DURING build/revision (the "SDSI Design Review" step).
//   • SIRI, the Survey Instrument Readiness Index: 100-pt pre-launch launch gate
//     run AFTER preview, before publish (the "SIRI Readiness Review" step).
//   • RSSI, the ReliCheck Survey Strength Index: the POST-response analysis index
//     (reliability, validity evidence, response/item/scale performance,
//     missing data, reporting). Reached, with the Studios, from the final
//     "RSSI / Studios" handoff. Distinct review moments. SIRI is NOT inside SDSI.
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
<title>Survey Development System · ReliCheck</title>
<style>
:root{
  --accent:#E07820; --accent-soft:#fef3e8; --accent-ink:#b85f10;
  --blue:#0A6FE8; --blue-soft:#EEF3FA;
  --green:#1f9e44; --green-soft:#e9f7ee;
  --amber:#c47700; --amber-soft:#fdf3e3;
  --red:#c4271f; --red-soft:#fbeae9;
  --ink:#15171a; --ink-2:#5f6368; --ink-3:#8a8f98;
  --bg:#f5f6f8; --panel:#ffffff;
  --line:rgba(15,23,42,0.08); --line-2:rgba(15,23,42,0.04);
  --sh:0 4px 12px rgba(15,23,42,0.08);
  --sh-lg:0 10px 30px rgba(15,23,42,0.13);
  --r:16px; --r-sm:11px; --r-lg:22px;
}
*{box-sizing:border-box;margin:0;padding:0}
html,body{height:100%}
body{
  font-family:-apple-system,BlinkMacSystemFont,"SF Pro Text",Inter,system-ui,sans-serif;
  background:var(--bg); color:var(--ink);
  -webkit-font-smoothing:antialiased; line-height:1.5;
}
button{font-family:inherit;cursor:pointer}
a{color:inherit;text-decoration:none}
.muted{color:var(--ink-2)} .faint{color:var(--ink-3)}

/* ── Top bar ─────────────────────────────────────────────── */
.topbar{
  position:sticky;top:0;z-index:50;height:60px;
  display:flex;align-items:center;gap:18px;padding:0 24px;
  background:rgba(255,255,255,0.82);backdrop-filter:saturate(180%) blur(18px);
  border-bottom:1px solid var(--line);
}
.brand{display:flex;align-items:center}
.topbar-spacer{flex:1}
.topbar-ctx{display:flex;align-items:center;gap:8px;font-size:12.5px;color:var(--ink-2);font-weight:600}
.topbar-ctx .dot{width:7px;height:7px;border-radius:50%;background:var(--green)}
.avatar-wrap{position:relative}
.avatar{width:32px;height:32px;border-radius:50%;background:var(--ink);color:#fff;display:grid;place-items:center;font-size:12px;font-weight:700;cursor:pointer;appearance:none;-webkit-appearance:none;border:none;padding:0;line-height:1}
.user-menu{
  position:absolute;top:calc(100% + 10px);right:0;min-width:196px;
  background:#fff;border:1px solid rgba(15,23,42,.10);border-radius:14px;
  box-shadow:0 8px 28px rgba(15,23,42,.12);padding:4px 0;
  opacity:0;pointer-events:none;transform:translateY(-6px) scale(.97);
  transition:opacity .13s,transform .13s;transform-origin:top right;z-index:200;
}
.user-menu.open{opacity:1;pointer-events:auto;transform:none}
.user-menu-profile{display:flex;align-items:center;gap:10px;padding:12px 14px 10px}
.user-menu-avatar{width:32px;height:32px;border-radius:50%;background:#EEF3FA;color:#0A6FE8;font-size:12px;font-weight:700;display:grid;place-items:center;flex:none}
.user-menu-name{font-size:13px;font-weight:600;color:var(--ink);line-height:1.3}
.user-menu-div{height:1px;background:rgba(15,23,42,.08);margin:3px 0}
.user-menu-item{display:block;width:100%;padding:9px 14px;font-size:14px;color:var(--ink);font-weight:500;text-decoration:none;background:none;border:none;text-align:left;cursor:pointer;transition:background .1s;box-sizing:border-box}
.user-menu-item:hover{background:var(--bg)}

/* ── Layout ──────────────────────────────────────────────── */
.shell{display:flex;min-height:calc(100vh - 60px)}
.rail{
  width:300px;flex-shrink:0;background:var(--panel);border-right:1px solid var(--line);
  padding:24px 16px;display:flex;flex-direction:column;gap:4px;
  /* Pin the pipeline nav below the header so it stays visible while the middle
     column scrolls. align-self keeps it from stretching (sticky needs that). */
  position:sticky;top:60px;align-self:flex-start;height:calc(100vh - 60px);overflow-y:auto;
}
.rail-h{font-size:10.5px;font-weight:800;letter-spacing:0.12em;text-transform:uppercase;color:var(--ink-3);padding:6px 12px 10px}
.step{
  display:flex;align-items:center;gap:12px;padding:11px 12px;border-radius:var(--r-sm);
  color:var(--ink-2);font-size:14px;font-weight:600;border:1px solid transparent;
  transition:background .12s,color .12s;text-align:left;width:100%;background:none;
}
.step:hover{background:var(--bg);color:var(--ink)}
.step .num{
  width:24px;height:24px;border-radius:50%;flex-shrink:0;display:grid;place-items:center;
  font-size:12px;font-weight:700;background:var(--bg);color:var(--ink-3);border:1px solid var(--line);
}
.step .lbl{flex:1}
.step .tick{display:none;color:var(--green)}
.step[data-done="1"] .num{background:var(--green-soft);color:var(--green);border-color:transparent}
.step[data-done="1"] .tick{display:block}
.step[data-active="1"]{background:var(--accent-soft);color:var(--accent-ink);border-color:rgba(15,158,123,.18)}
.step[data-active="1"] .num{background:var(--accent);color:#fff;border-color:transparent}
.rail-foot{margin-top:auto;padding:14px 12px 4px;font-size:11.5px;color:var(--ink-3);border-top:1px solid var(--line)}

/* overflow-x:clip (not hidden) prevents horizontal spill without establishing a
   scroll container, so position:sticky still works for the right types panel. */
.main{flex:1;min-width:0;padding:36px 44px 80px;overflow-x:clip}
.wrap{max-width:1080px;margin:0 auto}
.screen{animation:fade .22s ease}
@keyframes fade{from{opacity:0;transform:translateY(6px)}to{opacity:1;transform:none}}

/* ── Headings ────────────────────────────────────────────── */
.eyebrow{font-size:11px;font-weight:800;letter-spacing:0.12em;text-transform:uppercase;color:var(--blue)}
h1.title{font-size:34px;font-weight:800;letter-spacing:-0.03em;margin:6px 0 8px}
.lede{font-size:16px;color:var(--ink-2);max-width:680px;margin-bottom:28px}
h2.sec{font-size:19px;font-weight:700;letter-spacing:-0.01em;margin:30px 0 14px;display:flex;align-items:center;gap:10px}

/* ── Buttons / pills / badges ────────────────────────────── */
.btn{display:inline-flex;align-items:center;gap:8px;border:1px solid var(--line);background:var(--panel);
  color:var(--ink-2);font-weight:650;font-size:14px;padding:10px 18px;border-radius:11px;transition:.12s}
.btn:hover{background:var(--bg);color:var(--ink)}
.btn.primary{background:var(--accent);border-color:var(--accent);color:#fff}
.btn.primary:hover{background:#d94e2d;color:#fff}
.btn.blue{background:var(--blue);border-color:var(--blue);color:#fff}
.btn.blue:hover{background:#085fcc;color:#fff}
.btn.lg{padding:13px 26px;font-size:15px;border-radius:13px}
.btn.sm{padding:7px 13px;font-size:13px;border-radius:9px}
.btn[disabled]{opacity:.45;pointer-events:none}
.btn-row{display:flex;gap:12px;flex-wrap:wrap;align-items:center;margin-top:30px}
.btn-row .spacer{flex:1}
.badge{display:inline-flex;align-items:center;gap:5px;font-size:11px;font-weight:700;padding:3px 9px;border-radius:999px;letter-spacing:.02em}
.badge.blue{background:var(--blue-soft);color:var(--blue)}
.badge.green{background:var(--green-soft);color:var(--green)}
.badge.amber{background:var(--amber-soft);color:var(--amber)}
.badge.red{background:var(--red-soft);color:var(--red)}
.badge.gray{background:var(--bg);color:var(--ink-2)}
.pill{font-size:11.5px;font-weight:600;color:var(--ink-2);background:var(--bg);padding:4px 11px;border-radius:999px;border:1px solid var(--line)}

/* ── Cards / grids ───────────────────────────────────────── */
.card{background:var(--panel);border:1px solid var(--line);border-radius:var(--r);box-shadow:var(--sh)}
.card.pad{padding:24px}
.grid{display:grid;gap:18px}
.g2{grid-template-columns:repeat(2,1fr)} .g3{grid-template-columns:repeat(3,1fr)} .g4{grid-template-columns:repeat(4,1fr)}
@media(max-width:900px){.g2,.g3,.g4{grid-template-columns:1fr}}

/* entry cards */
.entry{display:grid;grid-template-columns:repeat(2,1fr);gap:18px}
.entry-card{
  background:var(--panel);border:1px solid var(--line);border-radius:var(--r);padding:24px;
  text-align:left;display:flex;flex-direction:column;gap:12px;transition:.15s;box-shadow:var(--sh);position:relative;overflow:hidden;
}
.entry-card:hover{transform:translateY(-3px);box-shadow:var(--sh-lg);border-color:rgba(15,158,123,.28)}
.entry-card .ico{width:46px;height:46px;border-radius:13px;display:grid;place-items:center;background:var(--accent-soft);color:var(--accent)}
.entry-card.blue .ico{background:var(--blue-soft);color:var(--blue)}
.entry-card h3{font-size:17px;font-weight:750;letter-spacing:-0.01em}
.entry-card p{font-size:13.5px;color:var(--ink-2)}
.entry-card .go{margin-top:auto;font-size:13px;font-weight:700;color:var(--accent)}
.entry-card.blue .go{color:var(--blue)}
.entry-card .tag{position:absolute;top:16px;right:16px}

/* template cards */
.tmpl{display:flex;flex-direction:column;gap:10px;padding:20px;cursor:pointer}
.tmpl:hover{border-color:var(--blue);box-shadow:var(--sh-lg)}
.tmpl .cat{font-size:11px;font-weight:700;letter-spacing:.06em;text-transform:uppercase;color:var(--blue)}
.tmpl h3{font-size:16px;font-weight:700}
.tmpl .meta{display:flex;gap:8px;flex-wrap:wrap;margin-top:auto;padding-top:8px}

/* survey list rows */
.row{display:flex;align-items:center;gap:16px;padding:16px 20px;border-bottom:1px solid var(--line-2)}
.row:last-child{border-bottom:none}
.row .grow{flex:1;min-width:0}
.row h4{font-size:15px;font-weight:650}
.row .sub{font-size:12.5px;color:var(--ink-3)}

/* form fields */
.field{margin-bottom:18px}
.field label{display:block;font-size:12.5px;font-weight:700;color:var(--ink-2);margin-bottom:7px;letter-spacing:.01em}
.field input,.field select,.field textarea{
  width:100%;padding:11px 14px;border:1.5px solid var(--line);border-radius:11px;font-family:inherit;font-size:14px;
  color:var(--ink);background:var(--panel);transition:border-color .12s}
.field input:focus,.field select:focus,.field textarea:focus{outline:none;border-color:var(--accent)}
.field textarea{min-height:84px;resize:vertical}
.field .hint{font-size:12px;color:var(--ink-3);margin-top:6px}
.file-drop{display:block;cursor:pointer}
.file-drop-inner{border:2px dashed var(--line);border-radius:var(--r);padding:48px 24px;text-align:center;background:var(--bg);transition:border-color .15s,background .15s}
.file-drop:hover .file-drop-inner,.file-drop:focus-within .file-drop-inner{border-color:var(--accent);background:var(--accent-soft)}
.chips{display:flex;gap:8px;flex-wrap:wrap}
.chip{padding:8px 14px;border:1.5px solid var(--line);border-radius:999px;font-size:13px;font-weight:600;color:var(--ink-2);background:var(--panel)}
.chip[data-on="1"]{border-color:var(--accent);background:var(--accent-soft);color:var(--accent-ink)}

/* question editor */
.qitem{display:flex;gap:14px;padding:16px 18px;border:1px solid var(--line);border-radius:var(--r-sm);background:var(--panel);margin-bottom:10px}
.qitem .qn{width:26px;height:26px;border-radius:8px;background:var(--bg);color:var(--ink-3);font-size:12px;font-weight:700;display:grid;place-items:center;flex-shrink:0}
.qitem .qtext{flex:1}
.qitem .qtype{font-size:11.5px;color:var(--ink-3);font-weight:600;margin-top:3px}
.qitem .flagdot{width:8px;height:8px;border-radius:50%;flex-shrink:0;margin-top:9px}

/* meters & ring */
.meter{height:8px;border-radius:999px;background:var(--bg);overflow:hidden}
.meter > span{display:block;height:100%;border-radius:999px;background:var(--accent)}
.ring-wrap{display:flex;align-items:center;gap:24px}
.ring{position:relative;width:132px;height:132px;flex-shrink:0}
.ring svg{transform:rotate(-90deg)}
.ring .val{position:absolute;inset:0;display:flex;flex-direction:column;align-items:center;justify-content:center}
.ring .val b{font-size:30px;font-weight:800;letter-spacing:-0.03em;line-height:1}
.ring .val small{font-size:11px;color:var(--ink-3);font-weight:600;margin-top:2px}

/* domain breakdown */
.dom{padding:18px 20px;border:1px solid var(--line);border-radius:var(--r);background:var(--panel)}
.dom-head{display:flex;justify-content:space-between;align-items:baseline;margin-bottom:4px}
.dom-head .nm{font-size:13px;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:var(--ink-2)}
.dom-head .pts{font-size:24px;font-weight:800;letter-spacing:-0.02em}
.dom-head .pts small{font-size:13px;color:var(--ink-3);font-weight:600}
.dom .lenses{margin-top:14px;display:flex;flex-direction:column;gap:8px}
.lens{display:flex;align-items:center;gap:10px;font-size:13px}
.lens .nm{flex:1;color:var(--ink-2)}
.lens .pt{font-variant-numeric:tabular-nums;font-weight:650;color:var(--ink)}

/* stats */
.stat{padding:20px;text-align:left}
.stat .n{font-size:30px;font-weight:800;letter-spacing:-0.03em}
.stat .l{font-size:12.5px;color:var(--ink-3);font-weight:600;margin-top:2px}

/* table */
.tbl{width:100%;border-collapse:collapse;font-size:13.5px}
.tbl th{text-align:left;font-size:11px;font-weight:700;letter-spacing:.05em;text-transform:uppercase;color:var(--ink-3);padding:10px 14px;border-bottom:1px solid var(--line)}
.tbl td{padding:12px 14px;border-bottom:1px solid var(--line-2);color:var(--ink-2)}
.tbl tr:last-child td{border-bottom:none}

/* bars (response viz) */
.bars{display:flex;align-items:flex-end;gap:6px;height:120px;padding-top:8px}
.bars .b{flex:1;background:var(--blue-soft);border-radius:6px 6px 0 0;position:relative}
.bars .b > i{position:absolute;bottom:0;left:0;right:0;background:var(--blue);border-radius:6px 6px 0 0}

/* callout */
.callout{display:flex;gap:14px;padding:18px 20px;border-radius:var(--r);background:var(--blue-soft);border:1px solid rgba(10,111,232,.16)}
.callout.amber{background:var(--amber-soft);border-color:rgba(196,119,0,.2)}
.callout.green{background:var(--green-soft);border-color:rgba(31,158,68,.2)}
.callout .ci{flex-shrink:0;color:var(--blue)}
.callout.amber .ci{color:var(--amber)} .callout.green .ci{color:var(--green)}
.callout h4{font-size:14px;font-weight:700;margin-bottom:2px}
.callout p{font-size:13px;color:var(--ink-2)}

/* destination cards */
.dest{padding:22px;display:flex;flex-direction:column;gap:10px;cursor:pointer}
.dest:hover{box-shadow:var(--sh-lg);border-color:var(--blue)}
.dest .ico{width:44px;height:44px;border-radius:12px;display:grid;place-items:center;background:var(--blue-soft);color:var(--blue)}
.dest.locked{opacity:.6;cursor:not-allowed}

/* expander */
.exp{border:1px solid var(--line);border-radius:var(--r-sm);overflow:hidden;margin-top:12px}
.exp summary{padding:14px 18px;font-size:13.5px;font-weight:650;color:var(--ink-2);cursor:pointer;list-style:none;display:flex;justify-content:space-between;align-items:center}
.exp summary::-webkit-details-marker{display:none}
.exp summary:hover{background:var(--bg)}
.exp[open] summary{border-bottom:1px solid var(--line)}
.exp .body{padding:16px 18px;font-size:13.5px;color:var(--ink-2)}

.divider{height:1px;background:var(--line);margin:28px 0}
.toast{position:fixed;bottom:26px;left:50%;transform:translateX(-50%) translateY(20px);background:var(--ink);color:#fff;
  padding:12px 22px;border-radius:12px;font-size:14px;font-weight:600;box-shadow:var(--sh-lg);opacity:0;pointer-events:none;transition:.25s;z-index:100}
.toast.show{opacity:1;transform:translateX(-50%) translateY(0)}
.phase-note{font-size:11.5px;color:var(--ink-3);font-style:italic;margin-top:14px}

/* persistence mode indicator (topbar) */
.modebadge{display:inline-flex;align-items:center;gap:6px;font-size:11px;font-weight:700;
  padding:4px 11px;border-radius:999px;letter-spacing:.02em;border:1px solid transparent}
.modebadge::before{content:"";width:7px;height:7px;border-radius:50%;background:currentColor}
.modebadge.mock{background:var(--amber-soft);color:var(--amber);border-color:rgba(196,119,0,.22)}
.modebadge.db{background:var(--green-soft);color:var(--green);border-color:rgba(31,158,68,.22)}
.modebadge.saving{background:var(--blue-soft);color:var(--blue);border-color:rgba(10,111,232,.22)}
/* degraded banner shown when DB mode falls back to mock */
.degraded{display:flex;gap:12px;align-items:center;padding:12px 18px;border-radius:var(--r-sm);
  background:var(--amber-soft);border:1px solid rgba(196,119,0,.22);color:var(--amber);
  font-size:13px;font-weight:600;margin-bottom:22px}
.degraded svg{flex-shrink:0}
/* ── question builder (three-column composer) ───────────────── */
.qclose{border:none;background:none;font-size:24px;line-height:1;cursor:pointer;color:var(--ink-3);padding:0 2px}
.qclose:hover{color:var(--ink)}
/* right column: clickable question-type list */
.pgroup{margin-bottom:14px}
.pgroup h4{font-size:11.5px;text-transform:uppercase;letter-spacing:.05em;color:var(--ink-3);margin:0 0 8px}
.ptypes{display:grid;grid-template-columns:1fr;gap:7px}
.ptype{text-align:left;border:1px solid var(--line);background:var(--panel);border-radius:var(--r-sm);padding:9px 12px;font:inherit;font-size:13.5px;cursor:pointer;color:var(--ink);transition:border-color .12s,background .12s}
.ptype:hover{border-color:var(--accent);background:var(--accent-soft)}
.help-line{font-size:12.5px;color:var(--ink-3);margin:6px 0 0}
/* three columns: settings | composer | types */
/* build screen fills the content width (flush-left, like Studios) instead of the centered 1080 cap.
   padding-left 116px + .main's own 44px = 160px gap from the sidebar. */
.wrap-build{max-width:none;padding-left:116px;padding-right:216px}
.composer-grid{display:grid;grid-template-columns:minmax(0,1fr) 290px;gap:18px;align-items:start}
.build-main{min-width:0}
@media (max-width:1080px){.composer-grid{grid-template-columns:1fr}.wrap-build{padding-left:0;padding-right:0}}
/* live SDSI heuristics for the question being developed */
.composer-checks{margin-top:14px;padding:14px 16px;border:1px solid var(--line);border-radius:var(--r-sm);background:var(--bg)}
.composer-checks .lens{margin-top:9px}
.composer-checks .lens:first-of-type{margin-top:10px}
.ckdot{width:9px;height:9px;border-radius:50%;flex-shrink:0}
.ckdot.ok{background:#1f9e44}
.ckdot.warn{background:var(--accent)}
/* ai-assist: per-question rewrite / clarity help */
.composer-assist{margin-top:14px;padding:14px 16px;border:1px solid var(--accent-soft);border-radius:var(--r-sm);background:var(--accent-soft)}
.composer-assist .assist-btns{display:flex;gap:8px;margin-top:10px;flex-wrap:wrap}
.assist-result{margin-top:12px}
.assist-rewrite .assist-text{margin:6px 0 10px;padding:10px 12px;background:#fff;border:1px solid var(--line);border-radius:var(--r-sm);font-size:14px;line-height:1.5}
.assist-acts{display:flex;gap:8px;flex-wrap:wrap}
.assist-notes{margin-top:12px}
.assist-notes ul{margin:6px 0 0;padding-left:18px}
.assist-notes li{margin-top:5px;font-size:13.5px;line-height:1.5;color:var(--ink-2)}
/* center composer */
.composer{background:var(--panel);border:1px solid var(--line);border-radius:var(--r);box-shadow:var(--sh);padding:18px 20px}
.composer-head{display:flex;align-items:center;justify-content:space-between;gap:10px;margin-bottom:12px}
.composer-head .eyebrow{color:var(--accent-ink)}
.composer-q{width:100%;border:1px solid var(--line);border-radius:var(--r-sm);padding:12px 14px;font:inherit;font-size:16px;line-height:1.4;color:var(--ink);resize:vertical;min-height:64px}
.composer-q:focus{outline:none;border-color:var(--accent)}
.composer-prev{margin-top:14px;padding-top:14px;border-top:1px solid var(--line)}
.composer-prev .lbl{font-size:11.5px;text-transform:uppercase;letter-spacing:.05em;color:var(--ink-3);margin-bottom:8px}
.composer-empty{background:var(--panel);border:2px dashed var(--line);border-radius:var(--r);padding:40px 24px;text-align:center;color:var(--ink-2)}
.composer-empty h3{margin:0 0 6px;font-size:17px;color:var(--ink)}
.composer-empty p{margin:0;font-size:13.5px}
.composer-foot{margin-top:16px;display:flex;gap:10px;align-items:center}
/* settings that flow under the question text inside the composer */
.csettings{margin-top:16px;padding-top:14px;border-top:1px solid var(--line)}
.csettings .field{margin-bottom:14px}
.csettings .field:last-child{margin-bottom:0}
.csettings .field>label{display:block;font-size:11.5px;text-transform:uppercase;letter-spacing:.05em;color:var(--ink-3);margin-bottom:6px}
.csettings .opt-row{display:flex;gap:6px;align-items:center;margin-bottom:6px}
.csettings .opt-row input{flex:1;border:1px solid var(--line);border-radius:7px;padding:6px 9px;font:inherit;font-size:13px}
.csettings .opt-del{border:none;background:none;color:var(--ink-3);cursor:pointer;font-size:16px;padding:0 4px}
.csettings .opt-del:hover{color:var(--red)}
.csettings .hint{margin:6px 0 0;font-size:12px;color:var(--ink-3)}
/* right types panel: sticks below the header so the type picker stays reachable
   while the middle composer column scrolls. It gets its own scroll if the list is
   taller than the viewport. The middle column (.build-main) stays static. */
.types-col{position:sticky;top:76px;max-height:calc((100vh - 92px) / 2);overflow-y:auto}
@media (max-width:1080px){.types-col{position:static;max-height:none;overflow:visible}}
/* saved-questions list */
.saved-head{margin:26px 0 12px;display:flex;align-items:baseline;gap:10px}
/* question cards */
.qcard{background:var(--panel);border:1px solid var(--line);border-radius:var(--r-sm);padding:12px 14px;margin-bottom:10px;display:flex;gap:12px;align-items:flex-start;cursor:pointer;transition:border-color .12s,box-shadow .12s}
.qcard:hover{border-color:var(--accent);background:var(--accent-soft)}
.qcard.active{border-color:var(--accent);box-shadow:0 0 0 2px var(--accent-soft)}
.qcard .qn{flex:0 0 24px;height:24px;border-radius:50%;background:var(--bg);color:var(--ink-2);font-size:12px;font-weight:600;display:flex;align-items:center;justify-content:center;margin-top:2px}
.qcard-body{flex:1;min-width:0}
.qcard-top{display:flex;gap:6px 10px;align-items:baseline;flex-wrap:wrap}
.qcard-text{flex:1 1 60%;min-width:0;font-size:14px;font-weight:500;color:var(--ink);overflow-wrap:break-word}
.qbadge{flex:0 0 auto;white-space:nowrap}
.qprev{margin-top:8px;color:var(--ink-2);font-size:12.5px}
.pvopt{display:inline-flex;align-items:center;gap:5px;margin:0 12px 4px 0;font-size:12.5px;color:var(--ink-2)}
.pvscale{display:flex;gap:8px;flex-wrap:wrap;color:var(--ink-3);font-size:12.5px}
.pvscale span{min-width:18px;text-align:center}
.pvsel,.pvinput{border:1px solid var(--line);border-radius:7px;padding:5px 8px;font:inherit;font-size:12.5px;color:var(--ink-3);background:var(--bg);max-width:240px}
.pvinput[type=range]{padding:0}
.pvrank{margin:0;padding-left:18px;color:var(--ink-2);font-size:12.5px}
.pvmeta{color:var(--ink-3);font-size:12.5px}
/* Respondent preview: the whole survey as a single-page form (matches the live form). */
.pv-form{max-width:680px;margin:0 auto;background:var(--panel);border:1px solid var(--line);border-radius:var(--r-sm);overflow:hidden;box-shadow:0 1px 3px rgba(15,23,42,.05)}
.pv-form-head{padding:20px 24px;border-bottom:1px solid var(--line);background:var(--bg)}
.pv-form-body{max-height:62vh;overflow-y:auto;padding:8px 24px}
.pv-q{padding:18px 0;border-bottom:1px solid var(--line)}
.pv-q:last-child{border-bottom:none}
.pv-q-stem{font-size:15px;font-weight:600;color:var(--ink);line-height:1.5;margin-bottom:12px}
.pv-q-n{color:var(--ink-3);font-weight:700;margin-right:4px}
.pv-q-body{padding-left:2px}
.pv-section{padding:16px 0 6px}
.pv-section-t{font-size:15px;font-weight:800;color:var(--ink);letter-spacing:-.01em}
.pv-break{margin:10px 0;padding:8px 0;text-align:center;color:var(--ink-3);font-size:11.5px;letter-spacing:.1em;text-transform:uppercase;border-top:1px dashed var(--line)}
.pv-form-foot{padding:18px 24px;border-top:1px solid var(--line);display:flex;align-items:center;background:var(--bg)}
/* Lightweight modal for Deploy/Export actions (share, QR, invite). */
.dlg-overlay{position:fixed;inset:0;background:rgba(15,23,42,.42);z-index:9999;display:flex;align-items:center;justify-content:center;padding:24px}
.dlg{background:var(--panel,#fff);border-radius:14px;max-width:480px;width:100%;max-height:86vh;overflow:auto;padding:22px 24px;position:relative;box-shadow:0 24px 70px rgba(15,23,42,.32)}
.dlg-x{position:absolute;top:12px;right:14px;background:none;border:none;font-size:24px;line-height:1;color:var(--ink-3);cursor:pointer}
.dlg-h{margin:0 0 14px;font-size:18px;font-weight:800;letter-spacing:-.02em}
.dlg-body{font-size:14px}
.dlg-input{width:100%;box-sizing:border-box;border:1px solid var(--line);border-radius:9px;padding:9px 11px;font:inherit;font-size:13.5px;margin-bottom:10px;background:var(--bg);color:var(--ink)}
.dlg-label{display:block;font-size:12.5px;font-weight:700;color:var(--ink-2);margin:6px 0 5px}
.dlg-btns{display:flex;gap:8px;flex-wrap:wrap;margin:4px 0 10px;align-items:center}
.dlg-note{font-size:12.5px;color:var(--ink-3);margin:6px 0 0;line-height:1.5}
.qcard-actions{flex:0 0 auto;display:flex;flex-direction:column;gap:8px;align-items:flex-end}
.qbtns{display:flex;gap:4px}
.reqtoggle{border:1px solid var(--line);background:var(--panel);border-radius:999px;padding:3px 11px;font:inherit;font-size:11.5px;color:var(--ink-2);cursor:pointer}
.reqtoggle.on{border-color:var(--accent);background:var(--accent-soft);color:var(--accent-ink);font-weight:600}
.fixflash{animation:fixflash 2.2s ease-out}@keyframes fixflash{0%,40%{box-shadow:0 0 0 3px var(--accent),0 0 0 6px var(--accent-soft)}100%{box-shadow:0 0 0 0 transparent}}

/* ── Question Strength Lab: Revise overview + single-question popup (namespaced) ── */
.qsl-scorecard{display:flex;align-items:center;gap:22px;flex-wrap:wrap;padding:20px 22px}
.qsl-ring{width:92px;height:92px;border-radius:50%;flex:0 0 auto;display:grid;place-items:center;position:relative;background:conic-gradient(var(--accent) calc(var(--p,40)*1%),var(--line) 0)}
.qsl-ring:before{content:"";position:absolute;inset:9px;background:var(--panel);border-radius:50%}
.qsl-ring b{position:relative;font-size:23px;font-weight:840}
.qsl-sx{flex:1;min-width:220px}
.qsl-sx h2{margin:0 0 3px;font-size:18px;font-weight:800}
.qsl-sx p{margin:0;color:var(--ink-2);font-size:14px}
.qsl-qrow{display:flex;gap:13px;align-items:center;padding:13px 18px;border-bottom:1px solid var(--line-2);cursor:pointer;transition:.12s}
.qsl-qrow:last-child{border-bottom:0}
.qsl-qrow:hover{background:var(--bg)}
.qsl-qrow.clean{cursor:default}.qsl-qrow.clean:hover{background:none}
.qsl-st{flex:0 0 auto;width:24px;height:24px;border-radius:50%;display:grid;place-items:center;font-size:12px;font-weight:800}
.qsl-st.flag{background:var(--amber-soft);color:var(--amber)}
.qsl-st.block{background:var(--red-soft);color:var(--red)}
.qsl-st.done,.qsl-st.strong{background:var(--green-soft);color:var(--green)}
.qsl-st.kept{background:rgba(15,23,42,.06);color:var(--ink-2)}
.qsl-qx{flex:1;min-width:0}
.qsl-ql{font-size:14.5px;font-weight:650;line-height:1.3}
.qsl-qc{font-size:13px;color:var(--ink-3);margin-top:1px}
.qsl-go{font-size:13px;font-weight:700;color:var(--accent-ink);white-space:nowrap}
.qsl-go.muted{color:var(--ink-3);font-weight:600}
.qsl-scrim{position:fixed;inset:0;background:rgba(15,23,42,.46);z-index:9999;display:flex;align-items:flex-start;justify-content:center;padding:34px 16px;overflow:auto}
.qsl-modal{background:var(--panel);border-radius:18px;box-shadow:0 18px 50px rgba(15,23,42,.28);width:100%;max-width:680px;display:flex;flex-direction:column;max-height:calc(100vh - 68px)}
.qsl-mhead{padding:14px 18px 11px;border-bottom:1px solid var(--line);flex:0 0 auto}
.qsl-mr1{display:flex;align-items:center;gap:10px}
.qsl-qn{font-size:13px;font-weight:800;color:var(--accent-ink)}
.qsl-sc{margin-left:auto;font-size:13px;font-weight:700;color:var(--ink-2);white-space:nowrap}
.qsl-x{border:0;background:none;font-size:22px;color:var(--ink-3);cursor:pointer;line-height:1;padding:2px 6px;border-radius:8px}
.qsl-x:hover{background:var(--bg)}
.qsl-dots{display:flex;gap:6px;margin-top:10px;flex-wrap:wrap}
.qsl-dot{width:9px;height:9px;border-radius:50%;background:var(--line);cursor:pointer;transition:.15s}
.qsl-dot.cur{background:var(--accent);transform:scale(1.3)}
.qsl-dot.done{background:var(--green)}.qsl-dot.kept{background:var(--ink-3)}
.qsl-mbody{flex:1 1 auto;overflow:auto;padding:18px}
.qsl-mfoot{flex:0 0 auto;border-top:1px solid var(--line);padding:12px 16px;display:flex;align-items:center;gap:8px;flex-wrap:wrap;background:var(--panel);border-radius:0 0 18px 18px}
.qsl-mfoot .sp{flex:1}
.qsl-qtext{font-size:19px;font-weight:680;line-height:1.35;margin:0 0 14px;letter-spacing:-.01em}
.qsl-hl{background:#ffe2c2;color:#7a3d00;border-radius:4px;padding:0 4px;font-weight:680}
.qsl-opts{display:flex;flex-direction:column;gap:7px}
.qsl-opt{display:flex;align-items:center;gap:10px;border:1px solid var(--line);border-radius:10px;padding:9px 12px;font-size:14px}
.qsl-opt .rk{width:14px;height:14px;border-radius:50%;border:2px solid var(--line);flex:0 0 auto}
.qsl-opt.bad{border-style:dashed;border-color:rgba(196,39,31,.4);background:var(--red-soft);color:var(--red)}
.qsl-opt.good{border-color:rgba(31,158,68,.35);background:var(--green-soft)}
.qsl-scale{display:flex;gap:6px;flex-wrap:wrap}
.qsl-pt{font-size:12.5px;border:1px solid var(--line);border-radius:8px;padding:7px 10px;color:var(--ink-2);background:#fff;flex:1;text-align:center;min-width:58px}
.qsl-strong{display:flex;gap:10px;align-items:center;background:var(--green-soft);border:1px solid rgba(31,158,68,.25);border-radius:11px;padding:11px 14px;margin-top:12px}
.qsl-strong .ic{width:20px;height:20px;border-radius:50%;background:var(--green);color:#fff;display:grid;place-items:center;font-weight:800;flex:0 0 auto}
.qsl-strong p{margin:0;font-size:13.5px;color:#14532b}
.qsl-sec{padding:15px 0;border-top:1px solid var(--line-2)}
.qsl-sech{display:flex;align-items:center;gap:8px;margin-bottom:7px}
.qsl-secn{width:18px;height:18px;border-radius:50%;background:var(--ink);color:#fff;font-size:11px;font-weight:800;display:grid;place-items:center;flex:0 0 auto}
.qsl-sect{font-size:12px;font-weight:800;letter-spacing:.03em;text-transform:uppercase;color:var(--ink-2)}
.qsl-sec p{margin:0;font-size:14px}.qsl-sec p.muted{color:var(--ink-2)}
.qsl-learnlink{background:none;border:0;color:var(--blue);font-weight:700;font-size:13px;cursor:pointer;padding:7px 0 0}
.qsl-learn{margin-top:8px;font-size:13px;color:#23415f;background:var(--blue-soft);border-radius:10px;padding:10px 12px}
.qsl-move{border:1.5px solid var(--line);border-radius:11px;padding:11px 12px;margin-bottom:8px}
.qsl-move.sel{border-color:var(--accent);background:var(--accent-soft)}
.qsl-mt{display:flex;align-items:center;gap:9px;font-size:14px;font-weight:700}
.qsl-prev{margin-top:8px}
.qsl-prev .chip{display:inline-block;background:#fff;border:1px solid var(--line);border-radius:999px;padding:2px 9px;margin:0 5px 5px 0;font-size:12px}
.qsl-prev .q2{display:block;margin:3px 0;padding:5px 9px;background:#fff;border:1px solid var(--line);border-radius:8px;font-size:13px;white-space:pre-line}
.qsl-impact .ln{display:flex;justify-content:space-between;padding:3px 0;font-size:14px}
.qsl-impact .up{color:var(--green);font-weight:800}
.qsl-ed{margin-top:10px}
.qsl-ed textarea,.qsl-ed input{width:100%;box-sizing:border-box;font:inherit;font-size:14.5px;border:1.5px solid var(--line);border-radius:10px;padding:9px 11px;margin-bottom:7px;background:#fff;color:var(--ink)}
.qsl-ed .orow{display:flex;gap:6px;align-items:center}
.qsl-ed .xx{border:0;background:none;color:var(--ink-3);cursor:pointer;font-size:17px;padding:2px 7px}
</style>
</head>
<body>

<div class="topbar">
  <div class="brand">
    <img src="SS-Studio-long.png?v=<?= filemtime(__DIR__ . '/SS-Studio-long.png') ?>" alt="Survey Studio" style="height:70px;width:auto;display:block">
  </div>
  <div class="topbar-spacer"></div>
  <div class="topbar-ctx" id="ctx" style="display:none"><span class="dot"></span><span id="ctxName"></span></div>
  <div class="modebadge" id="modeBadge" title=""></div>
  <div class="avatar-wrap">
    <button class="avatar" id="devUserBtn" aria-haspopup="menu" aria-expanded="false" title="<?= htmlspecialchars($_dv_name ?: 'Account') ?>">
      <?= htmlspecialchars($_dv_initials) ?>
    </button>
    <div class="user-menu" id="devUserMenu" role="menu">
      <?php if ($_dv_name !== ''): ?>
      <div class="user-menu-profile">
        <div class="user-menu-avatar"><?= htmlspecialchars($_dv_initials) ?></div>
        <span class="user-menu-name"><?= htmlspecialchars($_dv_name) ?></span>
      </div>
      <div class="user-menu-div"></div>
      <?php endif; ?>
      <a class="user-menu-item" href="/account.php" role="menuitem">My account</a>
      <a class="user-menu-item" href="/projects.php" role="menuitem">Projects</a>
      <div class="user-menu-div"></div>
      <a class="user-menu-item" href="#" role="menuitem" id="devSignOut">Sign out</a>
    </div>
  </div>
</div>

<div class="shell">
  <nav class="rail" id="rail"></nav>
  <main class="main"><div class="wrap" id="app"></div></main>
</div>

<div class="toast" id="toast"></div>

<!-- Shared upload widget (universal entry gate for all studios). Each engine
     script is version-stamped with its file mtime so a deploy always busts the
     browser cache — otherwise the page reloads fresh but keeps a stale engine. -->
<?php $jsv = function($p){ $f=__DIR__.'/'.$p; return $p.'?v='.(is_file($f)?filemtime($f):'1'); }; ?>
<script src="<?= $jsv('apps/studio/dataset-upload.js') ?>"></script>

<!-- SDSI scoring spine + Build Check engine (deterministic 50-pt design score) -->
<script src="<?= $jsv('apps/sdsi/validity-lens-engine.js') ?>"></script>
<script src="<?= $jsv('apps/sdsi/buildcheck-engine.js') ?>"></script>
<script src="<?= $jsv('apps/sdsi/siri-readiness.js') ?>"></script>
<script src="<?= $jsv('apps/sdsi/launchcheck-engine.js') ?>"></script>
<script src="<?= $jsv('apps/rssi/rssi-engine.js') ?>"></script>

<script>
/* ════════════════════════════════════════════════════════════════════
   MOCK DATA. Everything below is illustrative. Phase 2 replaces these
   objects with real API payloads, real scoring, real responses.
   ════════════════════════════════════════════════════════════════════ */
const MOCK = {
  templates: [
    { id:'t-eng',  cat:'Workforce',    name:'Employee Engagement Pulse', items:18, scale:'5-pt agreement', domains:['Engagement','Manager Support','Growth'], note:'Validated 3-factor structure; α ≈ .89 in field use.' },
    { id:'t-cust', cat:'Customer',     name:'Customer Satisfaction (CSAT)', items:12, scale:'Mixed', domains:['Satisfaction','Effort','Loyalty'], note:'Includes NPS anchor item and open-ended driver.' },
    { id:'t-clim', cat:'Education',    name:'School Climate Survey (6–8)', items:24, scale:'4-pt frequency', domains:['Safety','Belonging','Engagement','Relationships'], note:'K-12 reading-level anchored; bias-reviewed wording.' },
    { id:'t-pat',  cat:'Healthcare',  name:'Patient Experience', items:20, scale:'5-pt + open', domains:['Access','Communication','Trust'], note:'Aligned to CAHPS-style domains.' },
    { id:'t-360',  cat:'360 Feedback',name:'Leadership 360', items:32, scale:'5-pt + behavior', domains:['Vision','Execution','People','Integrity'], note:'Self + rater forms; rater-group ready.' },
    { id:'t-test', cat:'Assessment',  name:'Grade-8 Knowledge Check', items:25, scale:'Multiple choice', domains:['Reading','Math','Science'], note:'Answer-key + distractor analysis ready.' },
  ],
  surveys: [
    { id:106, name:'Employee Survey', status:'Collecting', items:15, responses:250, updated:'2 days ago', siri:80.6 },
    { id:104, name:'Q2 Customer Pulse', status:'Draft', items:11, responses:0, updated:'last week', siri:null },
    { id: 98, name:'New Hire 30-Day Check', status:'Closed', items:9, responses:412, updated:'Mar 2026', siri:91.2 },
  ],
  qtypes:['Open-Ended','Single Choice','Multiple Choice','Likert (5-pt)','Likert (7-pt)','Rating','Yes/No','Ranking','Numeric'],
  builtSurvey: {
    title:'Employee Engagement Pulse',
    questions:[
      { t:'How satisfied are you with your role overall?', type:'Likert (5-pt)', flag:null },
      { t:'My manager gives me useful feedback.', type:'Likert (5-pt)', flag:null },
      { t:'I have the resources I need to do my job well.', type:'Likert (5-pt)', flag:null },
      { t:'I would recommend this organization as a place to work.', type:'Likert (5-pt)', flag:null },
      { t:'I rarely feel stressed and I am never overwhelmed at work.', type:'Likert (5-pt)', flag:'warn' },
      { t:'How long is your commute?', type:'Open-Ended', flag:'warn' },
      { t:'I feel a sense of belonging on my team.', type:'Likert (5-pt)', flag:null },
      { t:'My contributions are valued.', type:'Likert (5-pt)', flag:null },
      { t:'I see a clear path for growth here.', type:'Likert (5-pt)', flag:null },
      { t:'What is one thing we could do to improve your experience?', type:'Open-Ended', flag:null },
    ]
  },
  // SDSI 50-pt design-quality review (Survey Design Strength Index), used
  // DURING build/revision to strengthen the design itself.
  sdsi: {
    total:41.2, max:50, pct:82, band:'Strong design, minor refinements',
    lenses:[
      { nm:'Construct definition & coverage', pt:8.3, max:10 },
      { nm:'Item / prompt quality',           pt:7.4, max:10, warn:true },
      { nm:'Response-scale design',           pt:8.0, max:10 },
      { nm:'Survey flow & length',            pt:4.3, max:5 },
      { nm:'Bias & accessibility',            pt:8.2, max:10, warn:true },
      { nm:'Reliability readiness',           pt:5.0, max:5 },
    ],
    flags:[
      { sev:'warn', lens:'Item / prompt quality', msg:'Item 5 is double-barreled ("rarely stressed" + "never overwhelmed"). Split into two items.' },
      { sev:'warn', lens:'Bias & accessibility',  msg:'Item 6 ("commute") may not map to a defined construct. Confirm it belongs, or remove it.' },
      { sev:'info', lens:'Survey flow & length',  msg:'10 items on a single page, reads in about 4 min. Good length for a pulse survey.' },
    ]
  },
  // SIRI 100-pt readiness (Survey Instrument Readiness Index), the final
  // pre-launch gate. Matches the real engine sample of 80.6.
  siri: {
    total:80.6, max:100, pct:81, band:'Ready with cautions', blocked:false,
    domains:[
      { key:'validity', nm:'Validity · SDSI', pts:41.2, max:50, lenses:[
        { nm:'Construct definition', pt:8.5, max:10 },
        { nm:'Dimension coverage', pt:8.0, max:10 },
        { nm:'Item–construct alignment', pt:7.4, max:10 },
        { nm:'Dignity / framing', pt:9.0, max:10, warn:true },
        { nm:'Access & inclusion', pt:8.3, max:10 },
      ]},
      { key:'reliability', nm:'Reliability', pts:27.4, max:35, lenses:[
        { nm:'Scale structure', pt:5.8, max:7 },
        { nm:'Item clarity / wording', pt:5.0, max:7, warn:true },
        { nm:'Response-scale consistency', pt:5.8, max:7 },
        { nm:'Redundancy balance', pt:5.4, max:7 },
        { nm:'Internal-consistency setup', pt:5.4, max:7 },
      ]},
      { key:'administration', nm:'Administration', pts:12.0, max:15, lenses:[
        { nm:'Respondent instructions', pt:2.6, max:3 },
        { nm:'Consent & privacy', pt:2.6, max:3 },
        { nm:'Fielding plan & timing', pt:2.2, max:3 },
        { nm:'Sensitive-topic safety', pt:2.4, max:3 },
        { nm:'Completion burden', pt:2.2, max:3 },
      ]},
    ],
    flags:[
      { sev:'warn', lens:'Item clarity / wording', msg:'Item 5 is double-barreled ("rarely stressed" + "never overwhelmed"). Split into two items.' },
      { sev:'warn', lens:'Dignity / framing', msg:'Item 6 ("commute") may not map to a defined construct. Confirm it belongs.' },
      { sev:'info', lens:'Completion burden', msg:'Estimated completion about 4 min, within tolerance for a pulse survey.' },
    ],
    checklist:[
      { t:'Consent & privacy notice attached',        ok:true },
      { t:'Deployment channel configured',            ok:true },
      { t:'Estimated completion ≈ 4 min',             ok:true },
      { t:'2 design cautions carried forward',        ok:false },
      { t:'Data-retention / compliance acknowledged', ok:true },
    ]
  },
  responses: {
    collected:250, target:400, completionRate:78, medianMin:3.9,
    byDay:[12,28,41,55,38,30,22,14,10], // last 9 days
    recent:[
      { id:'#250', when:'4 min ago',  dur:'3:42', dept:'Engineering' },
      { id:'#249', when:'11 min ago', dur:'5:08', dept:'Sales' },
      { id:'#248', when:'22 min ago', dur:'2:55', dept:'Support' },
      { id:'#247', when:'38 min ago', dur:'4:31', dept:'Operations' },
      { id:'#246', when:'51 min ago', dur:'3:09', dept:'Engineering' },
    ]
  }
};

/* ════════════════════════════════════════════════════════════════════
   QUESTION TYPE CATALOG. Drives the one-click picker, card previews,
   and the settings panel. `key` is the stored type (must match the
   server whitelist in api/dev/_dev_common.php → sds_item_type). `label`
   is what the user sees when it differs from the key. `opts` = editable
   answer options; `settings` = type-specific defaults; `structural`
   items are survey scaffolding, not questions.
   ════════════════════════════════════════════════════════════════════ */
const QTYPES = {
  'Multiple Choice':   { label:'Multiple Choice',              defOpts:['Option 1','Option 2','Option 3'], editOpts:true },
  'Checkboxes':        { label:'Multiple Answers / Checkboxes',defOpts:['Option 1','Option 2','Option 3'], editOpts:true },
  'Dropdown':          { label:'Dropdown',                     defOpts:['Option 1','Option 2','Option 3'], editOpts:true },
  'Yes/No':            { label:'Yes / No',                     defOpts:['Yes','No'] },
  'True/False':        { label:'True / False',                 defOpts:['True','False'] },
  'Likert Scale':      { label:'Likert Scale',   settings:{points:5} },
  'Rating Scale':      { label:'Rating Scale',   settings:{max:5} },
  'Matrix/Grid':       { label:'Matrix / Grid',  defOpts:['Row 1','Row 2','Row 3'], editOpts:true },
  'NPS':               { label:'NPS' },
  'Short Answer':      { label:'Short Answer' },
  'Long Answer':       { label:'Long Answer' },
  'Comment Box':       { label:'Comment Box' },
  'Ranking':           { label:'Ranking',        defOpts:['Item 1','Item 2','Item 3'], editOpts:true },
  'Slider':            { label:'Slider',         settings:{min:0,max:100} },
  'Demographic':       { label:'Demographic Item', defOpts:['Option 1','Option 2'], editOpts:true },
  'Email':             { label:'Email' },
  'Phone':             { label:'Phone' },
  'Date':              { label:'Date' },
  'Numeric':           { label:'Numeric' },
  'Section Text':      { label:'Section Text / Instructions', structural:true },
  'Consent':           { label:'Consent / Agreement', structural:true, defOpts:['I agree to participate.'], editOpts:true },
  'Page Break':        { label:'Page Break',          structural:true },
  'Thank-you Message': { label:'Thank-you Message',    structural:true },
};
const QGROUPS = [
  { name:'Choice Questions',        types:['Multiple Choice','Checkboxes','Dropdown','Yes/No','True/False'] },
  { name:'Rating Questions',        types:['Likert Scale','Rating Scale','Matrix/Grid','NPS'] },
  { name:'Open Response',           types:['Short Answer','Long Answer','Comment Box'] },
  { name:'Ordering and Priority',   types:['Ranking','Slider'] },
  { name:'Demographic and Contact', types:['Demographic','Email','Phone','Date','Numeric'] },
  { name:'Survey Structure',        types:['Section Text','Consent','Page Break','Thank-you Message'] },
];
// "Help Me Choose": each plain-language answer need maps to a best-fit type.
const HELP_OPTIONS = [
  { q:'One answer from a list',        type:'Multiple Choice' },
  { q:'More than one answer',          type:'Checkboxes' },
  { q:'A rating or level of agreement',type:'Likert Scale' },
  { q:'A written response',            type:'Long Answer' },
  { q:'A number',                      type:'Numeric' },
  { q:'A date',                        type:'Date' },
  { q:'A ranking',                     type:'Ranking' },
  { q:'Consent or acknowledgment',     type:'Consent' },
  { q:'Instructions only',             type:'Section Text' },
];
const DEFAULT_PROMPT = {
  'Section Text':'Add your instructions here.',
  'Consent':'Please review and confirm before continuing.',
  'Page Break':'Page break',
  'Thank-you Message':'Thank you for completing this survey.',
};
function typeLabel(t){ return (QTYPES[t] && QTYPES[t].label) || t; }
// Opt-in revision/SDSI tracing for verification. Add ?debug=1 to the URL to see
// item-id, old/new text, save payload, and before/after SDSI scores in the console.
const DEBUG_REVISE = /[?&]debug=1\b/.test(location.search);
function dlog(){ if(DEBUG_REVISE) console.log.apply(console, ['[revise]'].concat([].slice.call(arguments))); }
function newQuestion(type){
  const def = QTYPES[type] || {};
  const q = { type, t: DEFAULT_PROMPT[type] || 'New question', flag:null, required:false, options:null, settings:null };
  if(def.defOpts)  q.options  = def.defOpts.slice();
  if(def.settings) q.settings = Object.assign({}, def.settings);
  return q;
}

/* ── Bring-in parsing (Phase 2A.6) ──────────────────────────────────────────
   Convert a pasted survey or a typed item list into editable builder items.
   `mode` = 'manual' treats each non-blank line as one item (no option grouping);
   'paste' groups option lines under their question stem. Type is detected when
   the text gives a clear signal, otherwise we fall back to a safe default so the
   item still lands and the user can change it in the workspace.            */
function stripItemNumber(s){ return String(s||'').replace(/^\s*(?:Q\s*\d+|\d+|[A-Za-z])[\.\):\-]\s+/,'').replace(/^\s*[\-\*•]\s+/,'').trim(); }
function isOptionLine(s){ return /^\s*(?:[A-Za-z][\.\)]|\d+[\.\)]|[\-\*•]|\[\s*\]|\(\s*\))\s+\S/.test(s); }
function stripOptionMarker(s){ return String(s||'').replace(/^\s*(?:[A-Za-z][\.\)]|\d+[\.\)]|[\-\*•]|\[\s*\]|\(\s*\))\s+/,'').trim(); }

function parenHint(stem){ const m=String(stem||'').match(/\(([^()]*)\)\s*$/); return m?m[1].toLowerCase():''; }
function detectType(stem, options){
  const raw=stem||'', t=raw.toLowerCase();
  const body=raw.replace(/\([^()]*\)\s*$/,'').trim();   // stem without a trailing (hint)
  // Explicit answer options are the clearest signal. Two-option sets that read
  // as Yes/No, Y/N, True/False, T/F, or a binary Agree/Disagree are valid
  // two-answer items, not "incomplete" multiple choice.
  if(options && options.length){
    const norm=options.map(o=>o.toLowerCase().trim());
    if(norm.length===2){
      if(norm.includes('yes') && norm.includes('no')) return 'Yes/No';
      if(norm.includes('y') && norm.includes('n')) return 'Yes/No';
      if(norm.includes('true') && norm.includes('false')) return 'True/False';
      if(norm.includes('t') && norm.includes('f')) return 'True/False';
      if(norm.includes('agree') && norm.includes('disagree')) return 'Yes/No';
    }
    return /select all|check all|all that apply/.test(t) ? 'Checkboxes' : 'Multiple Choice';
  }
  // A trailing parenthetical hint, e.g. "(Yes/No)" or "(open response)", wins next.
  const h=parenHint(raw);
  if(h){
    if(/\byes\s*\/\s*no\b|\byes or no\b|\by\s*\/\s*n\b/.test(h)) return 'Yes/No';
    if(/\btrue\s*\/\s*false\b|\bt\s*\/\s*f\b/.test(h)) return 'True/False';
    if(/open|comment|free.?text|in your own words|essay|\btext\b/.test(h)) return 'Long Answer';
    if(/strongly agree|agree.{0,6}disagree|likert/.test(h)) return 'Likert Scale';
    if(/\bnps\b|0\s*(?:to|\-|–)\s*10/.test(h)) return 'NPS';
    if(/scale|\d+\s*(?:to|\-|–)\s*\d+|\d+\s*(?:pt|point)/.test(h)) return 'Rating Scale';
  }
  // Otherwise read signals from the question body.
  if(/strongly agree|strongly disagree|agree.{0,6}disagree|likert/.test(t)) return 'Likert Scale';
  if(/\bnps\b|how likely .*recommend|0\s*(?:to|\-|–)\s*10/.test(t)) return 'NPS';
  if(/\brate\b|rating|scale of|on a scale|1\s*(?:to|\-|–)\s*(?:5|7|10)/.test(t)) return 'Rating Scale';
  if(/\byes\s*\/\s*no\b|\byes or no\b|\by\s*\/\s*n\b/.test(t)) return 'Yes/No';
  if(/\btrue\s*\/\s*false\b|\bt\s*\/\s*f\b/.test(t)) return 'True/False';
  if(/email address|your email/.test(t)) return 'Email';
  if(/phone number/.test(t)) return 'Phone';
  if(/\bdate of\b|\bdate\b/.test(t)) return 'Date';
  if(/explain|describe|tell us|in your own words|why |what.*think|comments?|elaborate|open[- ]ended|suggestions?/.test(t)) return 'Long Answer';
  if(/\?\s*$/.test(body)) return 'Short Answer';
  return 'Likert Scale';
}

function scalePoints(scaleNote){
  const m=String(scaleNote||'').match(/(\d+)\s*(?:-|–)?\s*(?:pt|point)/i) || String(scaleNote||'').match(/\b(4|5|6|7|10|11)\b/);
  const n=m?parseInt(m[1],10):5;
  return (n>=2 && n<=11)?n:5;
}

function makeItem(stem, options, scaleNote){
  const type=detectType(stem, options);
  const q=newQuestion(type);
  q.t=stem || (DEFAULT_PROMPT[type]||'Untitled question');
  // Binary types always carry exactly their two canonical answers, so they are
  // never treated as an incomplete option list downstream.
  if(type==='Yes/No'){ q.options=['Yes','No']; }
  else if(type==='True/False'){ q.options=['True','False']; }
  else if(options && options.length){ q.options=options.slice(); }
  if(type==='Likert Scale'){ q.settings=Object.assign({}, q.settings||{}, {points:scalePoints(scaleNote)}); }
  if(type==='Rating Scale'){ const p=scalePoints(scaleNote); q.settings=Object.assign({}, q.settings||{}, {max:p}); }
  return q;
}

// A line that introduces a shared response scale for the stems that follow it,
// e.g. "Rate the following from 1 to 5" or "Strongly disagree to strongly agree".
function isScaleInstruction(s){
  const t=String(s||'').toLowerCase();
  return /rate the following|please rate|rate each|on a scale (?:from|of)\s*\d+\s*(?:to|\-|–)\s*\d+|strongly disagree to strongly agree|strongly agree to strongly disagree|very dissatisfied to very satisfied|very satisfied to very dissatisfied|poor to excellent|excellent to poor|never to always|always to never|not at all to extremely|indicate how much you agree/.test(t);
}
// Agreement-worded scales become Likert items; everything else is a Rating Scale.
function scaleIsAgreement(s){
  return /agree|disagree|likert/.test(String(s||'').toLowerCase());
}
// A bare line (no a)/-/1. marker) that is still clearly an answer option, not a
// new question stem: the binary answer words. This lets a stem followed by plain
// "Yes" / "No" or "True" / "False" lines group into one binary item.
function isBareAnswerToken(s){
  const t=String(s||'').trim().toLowerCase().replace(/[.\)]\s*$/,'');
  return t==='yes'||t==='no'||t==='y'||t==='n'||t==='true'||t==='false'||t==='agree'||t==='disagree';
}
// A line numbered like a question stem ("1." "2)" "Q3:"). These always start a
// new item, never join the previous item as an option, even though the bare
// number also matches the generic option-marker pattern. (This survey style uses
// numbers for stems and letters/bullets/bare words for answer options.)
function isNumberedStem(s){ return /^\s*(?:Q\s*\d+|\d+)[\.\)\:\-]\s+\S/.test(s); }
function makeScaleItem(stem, instruction){
  const type = scaleIsAgreement(instruction) ? 'Likert Scale' : 'Rating Scale';
  const q=newQuestion(type);
  q.t=stem || 'Untitled question';
  const p=scalePoints(instruction);
  if(type==='Likert Scale'){ q.settings=Object.assign({}, q.settings||{}, {points:p}); }
  else { q.settings=Object.assign({}, q.settings||{}, {max:p}); }
  return q;
}

function parseSurveyText(text, mode, scaleNote){
  const lines=String(text||'').split(/\r?\n/);
  const out=[];
  if(mode==='manual'){
    lines.forEach(function(ln){ const s=stripItemNumber(ln); if(s) out.push(makeItem(s, null, scaleNote)); });
    return out;
  }
  // paste: group option lines under the most recent question stem, and split a
  // rating block (a scale instruction followed by several stems) into one rated
  // item per stem with the shared scale attached.
  let stem=null, opts=[];
  let scaleHead=null, subs=[];
  function flushItem(){ if(stem!=null){ out.push(makeItem(stem, opts.length?opts:null, scaleNote)); } stem=null; opts=[]; }
  function flushBlock(){
    if(scaleHead==null) return;
    if(subs.length){
      subs.forEach(function(sub){ if(sub) out.push(makeScaleItem(sub, scaleHead)); });
    } else {
      // A lone scale instruction with no stems is just one rating question.
      out.push(makeScaleItem(scaleHead, scaleHead));
    }
    scaleHead=null; subs=[];
  }
  lines.forEach(function(ln){
    if(!ln.trim()){ flushItem(); flushBlock(); return; }
    // Inside an active rating block every following line is another rated stem,
    // until a numbered stem or a fresh scale instruction closes the block.
    if(scaleHead!=null){
      if(!isNumberedStem(ln) && !isScaleInstruction(ln)){ subs.push(stripOptionMarker(stripItemNumber(ln))); return; }
      flushBlock();
    }
    // A scale instruction opens a rating block; its stems arrive on later lines.
    if(isScaleInstruction(ln)){ flushItem(); scaleHead=stripItemNumber(ln); return; }
    if(stem!=null && !isNumberedStem(ln) && (isOptionLine(ln) || isBareAnswerToken(ln))){ opts.push(stripOptionMarker(ln)); return; }
    // A new non-option line starts a new item.
    flushItem();
    stem=stripItemNumber(ln);
  });
  flushItem(); flushBlock();
  return out;
}

function parseConstructs(text){
  return String(text||'').split(/\r?\n/).map(function(ln){
    const s=ln.trim(); if(!s) return null;
    const m=s.match(/^(.*?)\s*[:|–\-]\s*(.+)$/);
    return m ? { name:m[1].trim(), definition:m[2].trim() } : { name:s, definition:'' };
  }).filter(Boolean);
}

/* ════════════════════════════════════════════════════════════════════
   STATE. Single source of truth. Screens read it; actions mutate it
   then call render(). The stepper unlocks steps as the user progresses.
   ════════════════════════════════════════════════════════════════════ */
// One development pipeline, shown for every project. The project `mode` controls
// routing (where an upload lands, where a reopened project opens), not which steps
// are visible — the full pipeline is always present so nothing ever disappears.
const STEPS_BUILD = [
  { id:'start',     lbl:'Start' },
  { id:'setup',     lbl:'Study Setup' },
  { id:'build',     lbl:'Build / Upload / Template' },
  { id:'sdsi',      lbl:'SDSI Build Check' },
  { id:'revise',    lbl:'Revise' },
  { id:'preview',   lbl:'Preview' },
  { id:'siri',      lbl:'SIRI Launch Check' },
  { id:'publish',   lbl:'Publish' },
  { id:'deploy',    lbl:'Deploy / Export' },
  { id:'retrieve',  lbl:'Retrieve Data' },
  { id:'analysis',  lbl:'RSSI / Studios' },
];

const state = {
  route:'start',
  entry:null,            // ai-build | ai-assist | scratch | existing | template
  reached:{ start:true },// which steps have been visited (drives stepper unlock + ticks)
  study:{ name:'', purpose:'', population:'', mode:'', dataType:'', launchReadiness:{} },
  survey:null,           // {id?, title, questions:[{id?,t,type,flag,section_id?,required,options,settings}]}
  projectId:null,        // DB id once persisted (db mode only)
  settings:{},           // full project settings JSON (server replaces it, so we keep + resend the whole object)
  publishOverride:false, // user acknowledged publishing past an unresolved SIRI blocker
  remoteTemplates:null,  // cached /api/dev/templates-list result (db mode)
  remoteProjects:null,   // cached /api/dev/project-list result (db mode)
  composer:null,         // the question currently being written {type,t,flag,required,options,settings}
  composerRef:null,      // index of the saved question being edited, or null for a new one
  helpMode:false,        // right column shows "Help Me Choose" plain-language map
  aiBusy:false,          // ai-build is drafting a study
  aiReason:'',           // why the last AI call fell back (shown in toast)
  composerAI:null,       // ai-assist help for the current question {busy, action, rewrite, notes:[str], reason}
  sdsiResult:null,       // last computed Build Check result from BuildCheck.assess()
  sdsiStale:false,       // true when items changed since the last Build Check run
  siriResult:null,       // last computed Launch Check result from LaunchCheck.assess()
  siriStale:false,       // true when the survey changed since the last Launch Check run
  fixFocus:null,         // element id to scroll/flash after a "Fix this" navigation (consumed once)
  publishReady:false,    // user confirmed readiness on the Publish gate (no link/collection yet)
  deploymentSettings:null, // Phase 3B: {link_key, published_at, responses_open} from deployment_settings
  responseCount:0,         // Phase 3D: completed public submissions for this project
  responseList:null,       // Phase 3E: loaded sessions+answers (null = not loaded yet)
  responsesLoading:false,  // Phase 3E: guard so retrieve() loads exactly once
  responsesError:null,     // Phase 3E: error message if the load failed
  dataset:null,            // Phase 4A: loaded RSSI dataset object (null = not loaded yet)
  datasetLoading:false,    // Phase 4A: guard so the dataset preview loads exactly once
  datasetError:null,       // Phase 4A: error message if the dataset load failed
  rssiResult:null,         // Phase 4C: last RSSIEngine.score() result (live or reloaded)
  rssiSaved:null,          // Phase 4C: saved record metadata from rssi-run.php / payload.rssi
  rssiStale:false,         // Phase 4C: saved RSSI predates the latest responses
  rssiRunning:false,       // Phase 4C: guard while a run is in flight
  // Bring In an Existing Survey (Phase 2A.6) intake fields.
  importMode:'paste',    // paste | manual
  importText:'',         // pasted survey text or one-item-per-line list
  importConstructs:'',   // optional: one construct per line, "Name: definition"
  importScale:'',        // optional: response scale note (e.g. "5-pt agreement")
// Revise step (Question Review Cards). Keyed by item_ref.
  reviseEditing:{},      // per ref: {mode:'self'|'assist'} when an inline edit panel is open
  reviseDrafts:{},       // per ref: current edit-panel textarea value
  // Question Strength Lab popup (opens from the Revise list, one question at a time).
  labOverlay:null,       // the .qsl-scrim overlay element, or null when closed
  labPos:0,              // index into the current active-revise list
  labEdit:null,          // null | 'self' | 'assist' | 'options' when the in-popup editor is open
  labDraftVal:null,      // current editor textarea value (string) or option array
  labDoneIdx:null,       // question index just saved, while showing the "Saved" confirmation
};

/* ════════════════════════════════════════════════════════════════════
   PERSISTENCE. Phase 2A. Opt-in with ?db=1. When OFF (default), every
   screen runs on the in-browser MOCK exactly as Phase 1 did. When ON, the
   app talks to /api/dev/* and falls back to mock (with a visible banner) on
   any failure: a missing session, a DB error, or an offline server.
   ════════════════════════════════════════════════════════════════════ */
// DB mode is now the default. Pass ?mock=1 to force mock mode (for testing only).
const PERSIST_REQUESTED = !new URLSearchParams(location.search).has('mock');
const PERSIST = { on:PERSIST_REQUESTED, degraded:false, reason:'' };
const LS_KEY = 'sds_project_id';

const DB = {
  async call(path, opts={}){
    const res = await fetch('/api/dev/'+path, {
      method: opts.method || 'GET',
      headers: opts.body ? {'Content-Type':'application/json'} : undefined,
      body: opts.body ? JSON.stringify(opts.body) : undefined,
      credentials: 'same-origin',
    });
    let data = null;
    try { data = await res.json(); } catch(e){ /* non-JSON */ }
    if(!res.ok || !data || data.ok===false){
      const msg = (data && (data.message||data.error)) || ('HTTP '+res.status);
      const err = new Error(msg); err.status = res.status; throw err;
    }
    // Self-heal: a successful server call proves the session + DB are live. If the
    // app had latched into degraded/mock mode from an earlier transient failure,
    // restore database mode so saves stop being silently dropped. (Genuine ?mock
    // mode — PERSIST_REQUESTED false — is left alone.)
    if(PERSIST_REQUESTED && !PERSIST.on){ PERSIST.on=true; PERSIST.degraded=false; PERSIST.reason=''; try{updateModeBadge();}catch(e){} }
    return data;
  },
  // Map a DB project payload → the in-app state shape (display field is `t`).
  hydrate(payload){
    const p = payload.project;
    state.projectId = p.id;
    state.study.name = p.title || '';
    state.study.purpose = p.purpose || '';
    state.study.population = p.population || '';
    state.study.mode = p.response_mode || state.study.mode;
    state.study.dataType = p.data_type || state.study.dataType;
    // Launch-readiness fields (Phase 2D) ride in the project settings JSON.
    state.settings = p.settings || {};
    state.study.launchReadiness = (p.settings && p.settings.launchReadiness) || {};
    state.entry = p.source || state.entry;
    state.survey = {
      id: p.id,
      title: p.title,
      sections: payload.sections || [],
      constructs: (payload.constructs||[]).map(c=>({ id:c.id, name:c.name||'', definition:c.definition||'' })),
      questions: (payload.items||[]).map(it=>DB.itemHydrate(it)),
    };
    // Restore the previously computed Build Check (SDSI) so a reloaded project
    // shows its saved score. The full engine result was stored as `review`.
    state.sdsiResult = (payload.sdsi && payload.sdsi.review) ? payload.sdsi.review : null;
    // Restore the previously computed Launch Check (SIRI). Only accept a review
    // that matches the current 50-point, five-domain shape (it has a numeric
    // `siri`). Reviews saved by the old 100-point engine are ignored so the user
    // simply re-runs SIRI rather than rendering a stale, incompatible result.
    state.siriResult = (payload.siri && payload.siri.review && payload.siri.review.siri != null) ? payload.siri.review : null;
    state.siriStale = false;
    // Phase 3B: restore deployment settings (link_key, published_at, responses_open).
    state.deploymentSettings = payload.deployment || null;
    state.publishReady = !!(state.deploymentSettings && state.deploymentSettings.link_key);
    // Phase 3D: restore the count of collected public responses.
    state.responseCount = payload.responses || 0;
    // Phase 3E: drop any previously loaded response list so the retrieve screen
    // re-fetches for the project just opened.
    state.responseList = null; state.responsesLoading = false; state.responsesError = null;
    // Phase 4A: drop any previously loaded RSSI dataset so it re-loads per project.
    state.dataset = null; state.datasetLoading = false; state.datasetError = null;
    // Phase 4C: restore the saved RSSI review separately from SDSI/SIRI. `stale`
    // is computed server-side (saved fingerprint vs current responses), so a
    // reopened project shows honestly whether the score predates newer data.
    state.rssiResult = (payload.rssi && payload.rssi.review) ? payload.rssi.review : null;
    state.rssiSaved  = payload.rssi || null;
    state.rssiStale  = !!(payload.rssi && payload.rssi.stale);
    state.rssiRunning = false;
    try { localStorage.setItem(LS_KEY, String(p.id)); } catch(e){}
  },
  // Items in DB wire-format (display `t` → `prompt`).
  itemsWire(){
    const qs = (state.survey && state.survey.questions) || [];
    return qs.map(q=>({ id:q.id, section_id:q.section_id||null, type:q.type, prompt:q.t, flag:q.flag||null, required:q.required?1:0, options:q.options||null, settings:DB.itemSettingsOut(q) }));
  },
  // survey_items has no construct column, so the item→construct mapping rides
  // inside the existing settings JSON. Authoritative source is q.construct;
  // we strip any prior copy and re-embed so the stored value never goes stale.
  itemSettingsOut(q){
    const s = Object.assign({}, q.settings||{});
    delete s.construct; delete s.constructId;
    if(q.construct) s.construct = q.construct;
    if(q.constructId!=null) s.constructId = q.constructId;
    return Object.keys(s).length ? s : null;
  },
  // Reverse of itemSettingsOut: lift the item→construct mapping back out of the
  // settings JSON into top-level q.construct/q.constructId so a recomputed SDSI
  // sees the same construct coverage it had before the reload.
  itemHydrate(it){
    const s = it.settings || {};
    const q = { id:it.id, t:it.prompt, type:it.type, flag:it.flag, section_id:it.section_id, required:!!it.required, options:it.options||null, settings:it.settings||null };
    if(s.construct) q.construct = s.construct;
    if(s.constructId!=null) q.constructId = s.constructId;
    return q;
  },
  // Construct definitions in DB wire-format. Array order is the saved sort order.
  constructsWire(){
    const cs = (state.survey && state.survey.constructs) || [];
    return cs.map((c,i)=>({ id:c.id||null, name:c.name||c.nm||'', definition:c.definition||c.def||'', position:i }));
  },
  // After constructs are (re)saved with their db ids, realign each item's stored
  // constructId to the construct of the same name, so the mapping and the
  // definitions stay in lockstep. Mapping is name-anchored, so this never loses
  // a link; it only fills in the numeric id.
  realignItemConstructs(){
    const byName = {};
    ((state.survey&&state.survey.constructs)||[]).forEach(c=>{ if(c.name) byName[c.name]=c.id; });
    ((state.survey&&state.survey.questions)||[]).forEach(q=>{
      if(q.construct && byName[q.construct]!=null) q.constructId = byName[q.construct];
    });
  },
};

// Drop to mock mode after a DB failure; show the banner once.
function degrade(reason){
  if(!PERSIST.on && PERSIST.degraded) return;
  PERSIST.on = false; PERSIST.degraded = true; PERSIST.reason = reason || 'database unavailable';
  toast('Database unavailable, running in mock mode');
  updateModeBadge();
}

function updateModeBadge(){
  const b = $('#modeBadge'); if(!b) return;
  if(PERSIST.degraded){ b.style.display='inline-flex'; b.className='modebadge mock'; b.textContent='Mock mode'; b.title='Database unavailable: '+PERSIST.reason; }
  else { b.style.display='none'; } // DB is the default — no badge needed
}

/* ── tiny helpers ──────────────────────────────────────────── */
const $ = sel => document.querySelector(sel);
const SVG = {
  check:'<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>',
  arrow:'<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><line x1="5" y1="12" x2="19" y2="12"/><polyline points="12 5 19 12 12 19"/></svg>',
  info:'<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="12" cy="12" r="9"/><line x1="12" y1="11" x2="12" y2="16"/><circle cx="12" cy="7.5" r="0.6" fill="currentColor"/></svg>',
};
function icon(p){return '<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">'+p+'</svg>';}
const ICONS = {
  aiBuild:'<path d="M12 3v3M5 7l2 2M19 7l-2 2"/><rect x="6" y="9" width="12" height="11" rx="2"/><circle cx="9.5" cy="14" r="1"/><circle cx="14.5" cy="14" r="1"/>',
  aiAssist:'<path d="M9 18l-1 3 3-1M4 14a8 8 0 1 1 6 3H7l-3 1z"/><path d="M9 11h6M9 8h4"/>',
  scratch:'<path d="M12 19l7-7 3 3-7 7-3 0 0-3z"/><path d="M11 4H6a2 2 0 0 0-2 2v14"/><path d="M14 7l3 3"/>',
  existing:'<path d="M3 7l9-4 9 4-9 4-9-4z"/><path d="M3 7v10l9 4 9-4V7"/><path d="M12 11v10"/>',
  template:'<rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/>',
  import:'<path d="M12 3v12"/><path d="M8 11l4 4 4-4"/><path d="M4 17v2a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-2"/>',
  rssi:'<path d="M3 17l5-6 4 3 5-7 4 4"/><path d="M3 21h18"/>',
  studio:'<rect x="3" y="4" width="18" height="14" rx="2"/><path d="M8 21h8M12 18v3M8 9l3 3 4-5"/>',
};

function toast(msg){
  const t=$('#toast'); t.textContent=msg; t.classList.add('show');
  clearTimeout(t._h); t._h=setTimeout(()=>t.classList.remove('show'),2200);
}

/* ════════════════════════════════════════════════════════════════════
   APP. Navigation + actions
   ════════════════════════════════════════════════════════════════════ */
const App = {
  go(route, focus){ state.route=route; state.reached[route]=true; state.fixFocus=(typeof focus==='string'?focus:null); window.scrollTo(0,0); render(); },

  async setEntry(mode){
    state.entry=mode; state.reached.start=true;
    if(mode==='existing'){
      if(PERSIST.on){
        try { const r=await DB.call('project-list.php'); state.remoteProjects=r.projects; }
        catch(e){ degrade(e.message); }
      }
      state.route='pick-existing'; render(); return;
    }
    // Any other entry begins a NEW survey, so detach from whatever project was
    // loaded (e.g. one boot() restored from localStorage). Without this, a fresh
    // ai-assist/scratch build would inherit the last survey's questions. The
    // existing project stays in the database; we just stop pointing at it.
    App._resetStudy();
    if(mode==='import'){
      state.importMode='paste'; state.importText=''; state.importConstructs=''; state.importScale='';
      state.route='import'; render(); return;
    }
    if(mode==='template'){
      if(PERSIST.on){
        try { const r=await DB.call('templates-list.php'); state.remoteTemplates=r.templates; }
        catch(e){ degrade(e.message); }
      }
      state.route='templates'; render(); return;
    }
    App.go('setup');
  },

  async chooseTemplate(id){
    if(PERSIST.on){
      try {
        const r=await DB.call('project-from-template.php',{method:'POST',body:{slug:id}});
        DB.hydrate(r); toast('Template loaded: '+state.survey.title); App.go('build'); return;
      } catch(e){ degrade(e.message); }
    }
    const t=(state.remoteTemplates||MOCK.templates).find(x=>(x.id===id||x.slug===id)) || MOCK.templates.find(x=>x.id===id);
    state.study.name=t.name; state.study.mode=t.scale; state.study.population='General';
    state.survey={ title:t.name, questions:MOCK.builtSurvey.questions.slice() };
    toast('Template loaded: '+t.name);
    App.go('build');
  },

  async openExisting(id){
    if(PERSIST.on){
      try {
        const r=await DB.call('project-load.php?id='+encodeURIComponent(id)); DB.hydrate(r);
        toast('Opened: '+state.survey.title);
        App.go('build');
        return;
      }
      catch(e){ degrade(e.message); }
    }
    const s=MOCK.surveys.find(x=>x.id===id);
    state.study.name=s.name;
    state.survey={ title:s.name, questions:MOCK.builtSurvey.questions.slice() };
    toast('Opened: '+s.name);
    App.go('build');
  },

  async generate(){
    if(state.aiBusy) return;
    if(!(state.study.purpose||'').trim()){ toast('Add a purpose so ReliCheck Intelligence can tailor the study'); return; }
    state.aiBusy=true; render();

    // Try the real ReliCheck Intelligence draft first.
    const ai = await App._aiBuild();
    state.aiBusy=false;

    if(ai && ai.items.length){
      if(PERSIST.on){
        try { await App._createProject(ai.title, ai.items, 'ai-build', ai.constructs);
          toast('ReliCheck Intelligence drafted '+ai.items.length+' tailored items'); App.go('build'); return; }
        catch(e){ degrade(e.message); }
      }
      state.survey={ title:ai.title, questions:ai.items, constructs:ai.constructs };
      toast('ReliCheck Intelligence drafted '+ai.items.length+' tailored items');
      App.go('build'); return;
    }

    // Fallback: AI not configured, not signed in, or unreachable.
    const why = state.aiReason ? ' ('+state.aiReason+')' : '';
    const qs=MOCK.builtSurvey.questions.slice();
    if(PERSIST.on){
      try { await App._createProject(state.study.name||'ReliCheck Intelligence Study', qs, 'ai-build'); toast('ReliCheck Intelligence is unavailable'+why+'. Drafted a sample to edit.'); App.go('build'); return; }
      catch(e){ degrade(e.message); }
    }
    state.survey={ title:state.study.name||'ReliCheck Intelligence Study', questions:qs };
    toast('ReliCheck Intelligence is unavailable'+why+'. Drafted a sample to edit.');
    App.go('build');
  },

  // Call the purpose-aware study generator. Returns {title, items:[{t,type}],
  // constructs:[{name,definition}]} on success, or null on any failure.
  async _aiBuild(){
    try {
      const r = await DB.call('ai-build.php',{method:'POST',body:{
        name:state.study.name||'', purpose:state.study.purpose||'',
        population:state.study.population||'', response_mode:state.study.mode||'',
      }});
      const st = r.study||{};
      const items = (st.items||[]).map(it=>({ t:it.prompt, type:it.type }));
      state.aiReason='';
      return { title: st.title || state.study.name || 'ReliCheck Intelligence Study',
               items, constructs: st.constructs||[] };
    } catch(e){ state.aiReason=e.message; return null; }
  },

  // ai-assist: help with the ONE question the user is writing. `action` is
  // 'rewrite' (return a clearer version they can accept) or 'clarity' (return
  // notes on clarity, bias, and double-barreled wording, no rewrite).
  async composerAssist(action){
    if(!state.composer) return;
    const text=(state.composer.t||'').trim();
    if(!text){ toast('Write your question first, then ask for help'); return; }
    if(state.composerAI && state.composerAI.busy) return;
    state.composerAI={ busy:true, action, rewrite:'', notes:[], reason:'' }; render();
    try {
      const r = await DB.call('ai-refine.php',{method:'POST',body:{
        action, prompt:text, type:state.composer.type||'',
        purpose:state.study.purpose||'', population:state.study.population||'',
      }});
      state.composerAI={ busy:false, action,
        rewrite:(r.rewrite||'').trim(),
        notes:(r.notes||[]).filter(Boolean), reason:'' };
    } catch(e){
      state.composerAI={ busy:false, action, rewrite:'', notes:[], reason:e.message };
    }
    render();
  },

  // Accept the rewrite ReliCheck Intelligence offered into the composer text.
  useRewrite(){
    const a=state.composerAI;
    if(!a || !a.rewrite || !state.composer) return;
    state.composer.t=a.rewrite;
    state.composerAI=null;
    toast('Question updated');
    render();
  },

  dismissAssist(){ state.composerAI=null; render(); },

  async startBuild(){
    // Scratch and ai-assist both start as a blank workspace the user fills in.
    // ai-assist differs only in offering live rewrite / clarity help per item.
    const empty = state.entry==='scratch' || state.entry==='ai-assist';
    if(PERSIST.on && !state.projectId){
      const qs = empty ? [] : MOCK.builtSurvey.questions.slice();
      try { await App._createProject(state.study.name||'Untitled Survey', qs, state.entry||'scratch'); App.go('build'); return; }
      catch(e){ degrade(e.message); }
    }
    if(!state.survey){
      state.survey={ title:state.study.name||'Untitled Survey',
        questions: empty ? [] : MOCK.builtSurvey.questions.slice() };
    }
    App.go('build');
  },

  /* ── Bring In an Existing Survey (Phase 2A.6) ── */
  setImportMode(m){ state.importMode=m; state.importText=''; render(); },
  setImportField(k,v){ state[k]=v; },

  // Turn the pasted/typed survey into editable builder items, create the same
  // internal project the normal builder uses, then land in the workspace.
  async importSurvey(){
    if(!(state.study.name||'').trim()){ toast('Add a project title'); return; }
    if(!(state.study.purpose||'').trim()){ toast('Add the survey purpose or intended use'); return; }
    if(!(state.study.population||'').trim()){ toast('Add the target audience'); return; }
    const questions = parseSurveyText(state.importText||'', state.importMode, state.importScale||'');
    if(!questions.length){ toast('Add at least one survey item to bring in'); return; }

    const constructs = parseConstructs(state.importConstructs||'');
    if(state.importScale) state.study.mode = state.importScale;

    if(PERSIST.on && !state.projectId){
      try {
        await App._createProject(state.study.name, questions, 'existing', constructs);
        toast('Brought in '+questions.length+' items'); App.go('build'); return;
      } catch(e){ degrade(e.message); }
    }
    state.survey={ title:state.study.name, questions, constructs };
    toast('Brought in '+questions.length+' items'); App.go('build');
  },

  // Create a project from the current study + a starting item set (db mode).
  // `constructs` (optional) seeds the project's construct definitions so they are
  // written in the same transaction and come back hydrated with their db ids.
  async _createProject(title, questions, source, constructs){
    const r=await DB.call('project-create.php',{method:'POST',body:{
      title, source,
      purpose: state.study.purpose, population: state.study.population,
      response_mode: state.study.mode, data_type: state.study.dataType,
      sections:[{title:'Main'}],
      items: questions.map(q=>({ type:q.type, prompt:q.t, flag:q.flag||null, required:q.required?1:0, options:q.options||null, settings:DB.itemSettingsOut(q) })),
      constructs: (constructs||[]).map(c=>({ name:c.name||c.nm||'', definition:c.definition||c.def||'' })),
    }});
    DB.hydrate(r);
  },

  setStudy(k,v){
    state.study[k]=v;
    if(PERSIST_REQUESTED && state.projectId){
      const map={ name:'title', purpose:'purpose', population:'population' };
      const field=map[k]; if(!field) return;
      clearTimeout(App._studyTimer);
      App._studyTimer=setTimeout(async()=>{
        try { const body={id:state.projectId}; body[field]=v; if(field==='title') state.survey&&(state.survey.title=v);
          await DB.call('project-update.php',{method:'POST',body}); }
        catch(e){ degrade(e.message); render(); }
      },500);
    }
  },

  // Phase 2D — set a nested launch-readiness field (dotted path, e.g. 'consent.statement').
  // Toggles pass a real boolean (and re-render so the pill flips); text inputs pass a
  // string and must NOT re-render (would drop focus). All of it feeds SIRI, never SDSI.
  setLaunch(path, value){
    const lr = state.study.launchReadiness = state.study.launchReadiness || {};
    const parts = String(path).split('.'), top = parts[0], leaf = parts[1];
    lr[top] = lr[top] || {};
    lr[top][leaf] = value;
    if(state.siriResult) state.siriStale = true;
    if(typeof value === 'boolean') render();
    App._persistLaunch();
  },
  // The server REPLACES the settings column, so we resend the full settings object
  // (existing keys + launchReadiness) and refresh our cache from the echoed project.
  _persistLaunch(){
    if(!(PERSIST_REQUESTED && state.projectId)) return;
    clearTimeout(App._launchTimer);
    App._launchTimer=setTimeout(async()=>{
      const merged=Object.assign({}, state.settings||{}, { launchReadiness: state.study.launchReadiness||{} });
      try { const r=await DB.call('project-update.php',{method:'POST',body:{id:state.projectId, settings:merged}});
        state.settings=(r&&r.project&&r.project.settings)||merged; }
      catch(e){ degrade(e.message); render(); }
    },500);
  },

  // Phase 2E — map a SIRI lens (by key) to where the user resolves it. The router
  // is not gated, so App.go(route) works freely. Used by the SIRI resolution UI.
  _fixFor(key){
    const L='launch', B='build', R='revise', S='setup';
    const map={
      consent_privacy:{route:L,focus:'lr-consent',label:'Document consent in Launch Readiness'},
      respondent_instructions:{route:L,focus:'lr-instructions',label:'Add instructions in Launch Readiness'},
      access:{route:L,focus:'lr-access',label:'Document access in Launch Readiness'},
      fielding_plan:{route:L,focus:'lr-fielding',label:'Add fielding details in Launch Readiness'},
      dignity_framing:{route:L,focus:'lr-dignity',label:'Confirm the dignity review in Launch Readiness'},
      sensitive_safety:{route:L,focus:'lr-sensitive',label:'Set a decline path in Launch Readiness'},
      construct_definition:{route:B,focus:'build-constructs',label:'Define constructs in the workspace'},
      dimension_coverage:{route:B,focus:'build-constructs',label:'Add constructs or map items'},
      item_construct_alignment:{route:B,focus:'build-constructs',label:'Map items to constructs'},
      item_clarity:{route:R,label:'Revise flagged items'},
      response_option_validity:{route:R,label:'Fix response options in Revise'},
      response_scale_consistency:{route:R,label:'Fix scales in Revise'},
      purpose_alignment:{route:S,label:'Add a purpose in Study Setup'},
      administration_consistency:{route:S,label:'Set the response mode in Study Setup'},
      redundancy_balance:{route:B,label:'Balance items in the workspace'},
      scale_structure_readiness:{route:B,label:'Group scaled items in the workspace'},
      completion_burden:{route:B,label:'Adjust survey length in the workspace'},
      // SIRI whole-survey domain keys (new 5-domain model) → coarse routing.
      purpose:{route:S,label:'Add survey purpose and audience in Study Setup'},
      coverage:{route:B,focus:'build-constructs',label:'Add constructs or map items'},
      structure:{route:B,label:'Adjust structure and length in the workspace'},
      scale:{route:R,label:'Fix scale consistency in Revise'},
      deployment:{route:L,focus:'lr-consent',label:'Document deployment readiness in Launch Readiness'}
    };
    return map[key]||{route:B,label:'Open the workspace'};
  },
  // Index of the first answerable item not yet mapped to a construct (or -1).
  _firstUnmappedItem(){
    const qs=(state.survey&&state.survey.questions)||[];
    for(let i=0;i<qs.length;i++){
      const st=QTYPES[qs[i].type]&&QTYPES[qs[i].type].structural;
      if(!st && !((qs[i].construct||'').trim())) return i;
    }
    return -1;
  },
  // Reusable form of the SIRI fix-routing: resolves a lens key to a deep-linked
  // App.go(...) argument list (route + optional focus anchor). Shared by the SIRI
  // resolution UI and the Publish Readiness gate.
  _fixArg(key){
    const fx=App._fixFor(key); let focus=fx.focus||'';
    if(key==='item_construct_alignment'){ const u=App._firstUnmappedItem(); if(u>=0) focus='qcard-'+u; }
    return { route:fx.route, label:fx.label, focus:focus, arg: focus?`'${fx.route}','${focus}'`:`'${fx.route}'` };
  },
  // Phase 3A — Publish Readiness gate. SIRI is the final launch gate; SDSI is
  // advisory only. Returns { status:'blocked'|'review'|'ready', headline,
  // blockers:[{key,name,domain}], reasons:[{msg,route,focus,fixLabel}], sdsiWarn }.
  // Pure read over state; collects nothing and generates no public link.
  _publishGate(){
    const r=state.siriResult;
    // SDSI advisory (never blocks): not run, stale, or weak (< Solid, i.e. < 40/50).
    let sdsiWarn=null;
    if(!state.sdsiResult) sdsiWarn='The Build Check (SDSI) has not been run. It is advisory, but worth running before launch.';
    else if(state.sdsiStale) sdsiWarn='The Build Check (SDSI) is out of date. It is advisory, but re-running keeps your design score current.';
    else if(state.sdsiResult.total < 38) sdsiWarn='The Build Check (SDSI) score is '+state.sdsiResult.total.toFixed(1)+' / 50 ('+(state.sdsiResult.display_band||state.sdsiResult.band||'Caution')+'). This does not block launch, but strengthening the design is recommended.';

    if(!r) return { status:'blocked', headline:'Run the Launch Check first', sdsiWarn:sdsiWarn,
      reasons:[{ msg:'SIRI has not run for this survey yet. Publishing needs a current Launch Check.', route:'siri', focus:'', fixLabel:'Run SIRI Launch Check' }] };
    if(state.siriStale) return { status:'review', headline:'Re-run the Launch Check', sdsiWarn:sdsiWarn,
      reasons:[{ msg:'The survey changed since SIRI last ran. Re-run SIRI to confirm readiness before publishing.', route:'siri', focus:'', fixLabel:'Re-run SIRI' }] };
    // New model: deployment blockers (SDSI critical question blockers + SIRI critical
    // findings) cap the readiness band. They do not change the number, but they hold
    // the publish gate until resolved or explicitly overridden.
    const blk=(r.deployment_blocker_count||0);
    if(blk>=1){
      const crit=(r.flags||[]).filter(f=>f.severity==='critical').map(f=>({key:f.domainKey,name:f.message,domain:f.domain}));
      const out={ status:'blocked', headline:(r.blocker_headline||'Resolve required launch issues'), sdsiWarn:sdsiWarn, capNote:(r.total_band_cap_reason||'') };
      if(crit.length) out.blockers=crit;
      else out.reasons=[{ msg:'Deployment blockers were found at the question level (Build Check). Resolve them, then re-run SIRI.', route:'sdsi', focus:'', fixLabel:'Open Build Check' }];
      return out;
    }
    return { status:'ready', headline:'Launch Check passed', sdsiWarn:sdsiWarn };
  },
  // After a "Fix this" navigation, scroll the target into view and flash it so the
  // user sees exactly where to act. Consumed once (state.fixFocus is cleared).
  _focusAfterRender(){
    const id=state.fixFocus; if(!id) return; state.fixFocus=null;
    setTimeout(()=>{ const el=document.getElementById(id); if(!el) return;
      try { el.scrollIntoView({behavior:'smooth',block:'center'}); } catch(e){ el.scrollIntoView(); }
      el.classList.add('fixflash'); setTimeout(()=>{ el.classList.remove('fixflash'); }, 2200);
    }, 60);
  },

  // Phase 2E — minimal construct add + item-mapping controls (the 'constructs'
  // fix screen). Reuse existing persistence: saveConstructs + saveItems. Any
  // change marks SIRI stale (via _markSdsiStale) so the user re-runs the check.
  addConstruct(){ App._ensureSurvey(); state.survey.constructs=(state.survey.constructs||[]); state.survey.constructs.push({name:'',definition:''}); App._markSdsiStale(); render(); },
  setConstructField(i,k,v){ const cs=(state.survey&&state.survey.constructs)||[]; if(!cs[i]) return; cs[i][k]=v; App._markSdsiStale(); clearTimeout(App._consTimer); App._consTimer=setTimeout(()=>App._persistConstructs(),500); },
  removeConstruct(i){ const cs=(state.survey&&state.survey.constructs)||[]; const c=cs[i]; if(!c) return; const nm=c.name; cs.splice(i,1);
    (((state.survey&&state.survey.questions))||[]).forEach(q=>{ if(nm && q.construct===nm){ q.construct=''; delete q.constructId; } });
    App._markSdsiStale(); App._persistConstructs(); App._persistItems(); render(); },
  setItemConstruct(i,v){ const q=((state.survey&&state.survey.questions)||[])[i]; if(!q) return; q.construct=v||''; if(!v) delete q.constructId; App._markSdsiStale(); App._persistItems(); render(); },
  _persistConstructs(){ if(PERSIST_REQUESTED && state.projectId) App.saveConstructs(); },

  // Drop the working project/survey so the next entry starts clean. Does NOT
  // delete anything in the database; it only clears the in-app pointer + the
  // localStorage id boot() uses to restore.
  _resetStudy(){
    state.projectId=null; state.survey=null; state.settings={};
    state.dataset=null; state.datasetError=null; state.rssiResult=null; state.rssiSaved=null;
    state.study={ name:'', purpose:'', population:'', mode:'', dataType:'', launchReadiness:{} };
    state.composer=null; state.composerRef=null; state.composerAI=null;
    state.aiBusy=false; state.aiReason=''; state.sdsiResult=null; state.sdsiStale=false;
    state.siriResult=null; state.siriStale=false; state.deploymentSettings=null; state.publishReady=false; state.publishOverride=false;
    try { localStorage.removeItem(LS_KEY); } catch(e){}
  },

  /* ── three-column question composer ── */
  _ensureSurvey(){ if(!state.survey) state.survey={title:state.study.name||'Untitled Survey',questions:[]}; },
  _persistItems(){ if(PERSIST_REQUESTED && state.projectId) App.saveItems(); },
  // An actual item changed. The previously shown Build Check no longer reflects
  // the survey, so flag it stale (only meaningful once a review has been run).
  // We do NOT recompute or overwrite the old review here; the user re-runs it.
  _markSdsiStale(){ if(state.sdsiResult){ state.sdsiStale=true; dlog('SDSI marked stale'); } if(state.siriResult){ state.siriStale=true; dlog('SIRI marked stale'); } },

  // Start a new question of `type` in the center composer, or — when a
  // question is already being written — switch its type, keeping the text.
  startType(type){
    const def=QTYPES[type]||{};
    if(state.composer){
      const c=state.composer; c.type=type;
      c.options  = def.defOpts  ? def.defOpts.slice() : null;
      c.settings = def.settings ? Object.assign({},def.settings) : null;
      if(!c.t || c.t==='New question') c.t = DEFAULT_PROMPT[type] || c.t || '';
    } else {
      state.composer = newQuestion(type);
      state.composerRef = null;
    }
    state.helpMode=false; state.composerAI=null;
    render();
  },
  helpToggle(){ state.helpMode=!state.helpMode; render(); },

  // Commit the composer to the saved list (new) or back to its slot (editing).
  saveComposer(){
    const c=state.composer; if(!c) return;
    c.t=(c.t||'').trim() || (DEFAULT_PROMPT[c.type]||'Untitled question');
    App._ensureSurvey();
    if(state.composerRef!=null && state.survey.questions[state.composerRef]){
      const id=state.survey.questions[state.composerRef].id;
      const merged=Object.assign({}, c); if(id) merged.id=id;
      state.survey.questions[state.composerRef]=merged;
    } else {
      state.survey.questions.push(c);
    }
    state.composer=null; state.composerRef=null; state.composerAI=null;
    App._markSdsiStale();
    render(); App._persistItems();
  },
  cancelComposer(){ state.composer=null; state.composerRef=null; state.composerAI=null; render(); },
  // Clear the composer to a fresh, empty state ready for the next type pick.
  newComposer(){
    state.composer=null; state.composerRef=null; state.composerAI=null; render();
    const el=$('#composer'); if(el&&el.scrollIntoView) el.scrollIntoView({behavior:'smooth',block:'center'});
  },

  // Load a saved question back into the composer for editing.
  editSaved(i){
    const q=state.survey&&state.survey.questions[i]; if(!q) return;
    state.composer=JSON.parse(JSON.stringify(q));
    state.composerRef=i; state.composerAI=null;
    render();
    const el=$('#composer'); if(el&&el.scrollIntoView) el.scrollIntoView({behavior:'smooth',block:'start'});
  },

  // Composer field setters (operate on the in-progress question).
  setCText(v){ if(state.composer) state.composer.t=v; },
  setCRequired(v){ if(state.composer){ state.composer.required=!!v; render(); } },
  setCOption(oi,v){ const c=state.composer; if(c&&c.options&&oi<c.options.length) c.options[oi]=v; },
  addCOption(){ const c=state.composer; if(!c) return; c.options=c.options||[]; c.options.push('Option '+(c.options.length+1)); render(); },
  removeCOption(oi){ const c=state.composer; if(c&&c.options){ c.options.splice(oi,1); render(); } },
  setCSetting(key,v){ const c=state.composer; if(!c) return; c.settings=c.settings||{}; c.settings[key]=isNaN(+v)?v:+v; render(); },

  // Saved-list row actions.
  setRequired(i,v){ if(state.survey&&state.survey.questions[i]){ state.survey.questions[i].required=!!v; App._markSdsiStale(); render(); App._persistItems(); } },
  duplicateItem(i){
    const q=state.survey&&state.survey.questions[i]; if(!q) return;
    const clone=JSON.parse(JSON.stringify(q)); delete clone.id;
    state.survey.questions.splice(i+1,0,clone);
    if(state.composerRef!=null && state.composerRef>i) state.composerRef++;
    App._markSdsiStale();
    render(); App._persistItems();
  },
  deleteItem(i){
    if(!state.survey) return;
    state.survey.questions.splice(i,1);
    if(state.composerRef===i){ state.composer=null; state.composerRef=null; }
    else if(state.composerRef!=null && state.composerRef>i) state.composerRef--;
    App._markSdsiStale();
    render(); App._persistItems();
  },
  moveItem(i,dir){
    if(!state.survey) return;
    const qs=state.survey.questions, j=i+dir;
    if(j<0||j>=qs.length) return;
    [qs[i],qs[j]]=[qs[j],qs[i]];
    if(state.composerRef===i) state.composerRef=j; else if(state.composerRef===j) state.composerRef=i;
    render();
    if(PERSIST_REQUESTED && state.projectId) App.reorderItems();
  },
  async saveItems(){
    if(!(PERSIST_REQUESTED && state.projectId)){ toast('Saved (mock)'); return; }
    setModeBadgeSaving(true);
    try {
      const r=await DB.call('items-save.php',{method:'POST',body:{project_id:state.projectId, items:DB.itemsWire()}});
      state.survey.questions=r.items.map(it=>DB.itemHydrate(it));
      await App.saveConstructs();   // keep construct definitions persisted alongside items
      toast('Items saved'); render();
    } catch(e){ degrade(e.message); render(); }
    finally { setModeBadgeSaving(false); }
  },
  // Persist the project's construct definitions. Silent (no toast): it rides
  // with item saves. Upserts by id/name so repeated saves never duplicate, and
  // syncs deletions. Rehydrates ids back so later saves stay updates, then
  // realigns item->construct mappings to the saved ids.
  async saveConstructs(){
    if(!(PERSIST_REQUESTED && state.projectId && state.survey)) return;
    try {
      const r=await DB.call('constructs-save.php',{method:'POST',body:{project_id:state.projectId, constructs:DB.constructsWire()}});
      state.survey.constructs=(r.constructs||[]).map(c=>({ id:c.id, name:c.name||'', definition:c.definition||'' }));
      DB.realignItemConstructs();
      dlog('constructs saved:', state.survey.constructs);
    } catch(e){ degrade(e.message); }
  },
  async reorderItems(){
    if(!(PERSIST_REQUESTED && state.projectId)) return;
    try { await DB.call('reorder.php',{method:'POST',body:{project_id:state.projectId, item_order:state.survey.questions.map(q=>q.id).filter(Boolean)}}); }
    catch(e){ degrade(e.message); render(); }
  },

  async duplicateProject(){
    if(!(PERSIST_REQUESTED && state.projectId)){ toast('Duplicate is available in database mode only'); return; }
    try { const r=await DB.call('project-duplicate.php',{method:'POST',body:{id:state.projectId}}); DB.hydrate(r); toast('Project duplicated'); App.go('build'); }
    catch(e){ degrade(e.message); render(); }
  },
  async archiveProject(){
    if(!(PERSIST_REQUESTED && state.projectId)){ toast('Archive is available in database mode only'); return; }
    try { await DB.call('project-archive.php',{method:'POST',body:{id:state.projectId,archived:true}}); toast('Project archived'); try{localStorage.removeItem(LS_KEY);}catch(e){} state.projectId=null; state.survey=null; App.go('start'); }
    catch(e){ degrade(e.message); render(); }
  },
  // Remove a project from the projects list. This ARCHIVES it (a soft, recoverable
  // state change via project-archive.php; the list already hides archived projects).
  // Nothing is permanently deleted. Always confirmed first.
  deleteProjectFromList(id){
    if(!PERSIST.on){ toast('Available in database mode only'); return; }
    const p=(state.remoteProjects||[]).filter(x=>x.id===id)[0];
    const name=p?(p.title||p.name||('Project '+id)):('Project '+id);
    const m=App._modal('Remove project',
      `<p style="font-size:14px;margin:0 0 8px">Remove <b>${escapeHtml(name)}</b> from your list?</p>`
      +`<p class="dlg-note" style="margin:0 0 14px">It is archived, not permanently deleted. Nothing is lost, and it can be restored later.</p>`
      +`<div class="dlg-btns"><button class="btn" id="rmCancel">Cancel</button>`
      +`<button class="btn primary" id="rmGo" style="background:var(--red);border-color:var(--red)">Remove from list</button></div>`);
    m.el.querySelector('#rmCancel').addEventListener('click', m.close);
    m.el.querySelector('#rmGo').addEventListener('click', async ()=>{
      const go=m.el.querySelector('#rmGo'); go.disabled=true; go.textContent='Removing…';
      try {
        await DB.call('project-archive.php',{method:'POST',body:{id:id,archived:true}});
        if(state.projectId===id){ try{localStorage.removeItem(LS_KEY);}catch(e){} state.projectId=null; state.survey=null; }
        try { const r=await DB.call('project-list.php'); state.remoteProjects=r.projects; } catch(e){}
        m.close(); toast('Removed from your list'); render();
      } catch(e){ m.close(); degrade(e.message); }
    });
  },

  // Build the survey-project payload the Build Check engine reads from current state.
  _buildCheckProject(){
    const s=state.study||{}, sv=state.survey||{};
    const qs=(sv.questions||[]);
    const sectionIds={};
    qs.forEach(q=>{ if(q.section_id!=null) sectionIds[q.section_id]=true; });
    return {
      purpose: s.purpose||'',
      population: s.population||'',
      mode: s.mode||'',
      dataType: s.dataType||'',
      launchReadiness: s.launchReadiness||{},   // Phase 2D — SIRI reads this; BuildCheck (SDSI) ignores it
      constructs: (sv.constructs||[]).map(c=>({ name:c.name||c.nm||'', definition:c.definition||c.def||'' })),
      items: qs.map((q,i)=>({
        item_ref: q.id!=null ? ('q'+q.id) : ('i'+i),
        item_no: i+1,
        type: q.type||'',
        prompt: q.t||'',
        options: q.options||[],
        settings: q.settings||{},
        construct: q.construct||'',
        required: !!q.required
      })),
      sections: Object.keys(sectionIds).map(id=>({ id }))
    };
  },
  runSdsi(){
    if(!(window.BuildCheck && window.BuildCheck.assess)){
      // Engine script failed to load. In mock mode show the illustrative sample;
      // in db mode leave it unscored rather than showing a fake number.
      if(!PERSIST.on){ state.sdsiResult=MOCK.sdsi; }
      toast('Build Check engine unavailable'); App.go('sdsi'); return;
    }
    if(!(state.survey && (state.survey.questions||[]).length)){
      toast('Add questions before running the Build Check'); App.go('build'); return;
    }
    const prev=state.sdsiResult;
    const proj=App._buildCheckProject();
    if(DEBUG_REVISE){
      dlog('Run SDSI · recomputing from '+proj.items.length+' live items');
      dlog('first item prompts seen by engine:', proj.items.slice(0,6).map(it=>it.item_ref+': '+it.prompt));
      if(prev) dlog('previous score:', prev.total, prev.categories&&prev.categories.map(c=>c.key+' '+c.points));
    }
    let r;
    try { r=window.BuildCheck.assess(proj); }
    catch(err){ toast('Could not score the survey: '+(err&&err.message||'engine error')); return; }
    state.sdsiResult=r;
    state.sdsiStale=false;
    if(DEBUG_REVISE) dlog('new score:', r.total, r.categories&&r.categories.map(c=>c.key+' '+c.points));
    toast('Build Check scored your design ('+r.total.toFixed(1)+' / 50)');
    if(PERSIST_REQUESTED && state.projectId){
      DB.call('sdsi-save.php',{method:'POST',body:{project_id:state.projectId,total:r.total,max:r.max,pct:r.pct,band:r.band,blocked:r.blocked,review:r}}).catch(e=>degrade(e.message));
    }
    App.go('sdsi');
  },
  /* ── Revise: Question Review Cards (driven by live SDSI flags) ── */
  _reviseRefOf(q,i){ return q.id!=null ? ('q'+q.id) : ('i'+i); },
  // Recompute the assessment live (not the saved review) and group moderate+
  // question-level concerns by item. Items the user has already decided on
  // (kept or marked for later) drop out of the active list.
  // Live moderate+ concerns for the whole survey, keyed by item_ref. Cached per
  // render pass so each card does not re-run the engine.
  _reviseScan(){
    const byRef={};
    let r=null;
    if(window.BuildCheck && window.BuildCheck.assess){ try { r=window.BuildCheck.assess(App._buildCheckProject()); } catch(e){} }
    const wanted={item:1,scale:1,bias:1};
    if(r && r.flags){
      r.flags.forEach(f=>{
        if(!f.item_ref) return;
        if(!(f.severity==='moderate'||f.severity==='major'||f.severity==='critical')) return;
        if(!wanted[f.category]) return;
        (byRef[f.item_ref]=byRef[f.item_ref]||[]).push(f);
      });
    }
    return byRef;
  },
  // Concerns for a single item: engine flags (moderate+) plus the synthetic
  // construct-mismatch concern when constructs are defined and this item is unmapped.
  _concernsFor(q,i,byRef){
    byRef=byRef||App._reviseScan();
    const ref=App._reviseRefOf(q,i);
    const hasCons=((state.survey&&state.survey.constructs)||[]).length>0;
    const structural=!!(QTYPES[q.type]&&QTYPES[q.type].structural);
    let concerns=(byRef[ref]||[]).map(f=>({msg:f.message, how:f.suggestion, check:f.check, sev:f.severity, why:f.why_it_matters, opts:f.suggested_options, revision:f.suggested_revision}));
    if(hasCons && !structural && !(q.construct||'').trim()){
      concerns.push({msg:'This item is not matched to any defined construct, so it is unclear what it measures.', how:'Map it to the construct it measures, or remove it if it does not belong.', check:'unmatched_construct', sev:'moderate'});
    }
    return concerns;
  },
  // Heuristic: is this a metadata / identifier column (respondent id, timestamp,
  // start date, duration) rather than a real survey question? Uploaded data often
  // carries these as plain Numeric/Date columns, so we match by name AND keep it
  // conservative (low-signal types only). Used to keep the Revise LIST focused;
  // it does NOT change scoring (the engine still scores every column it is given).
  _isMetaColumn(q){
    if(!q) return false;
    const nm=(q.t||'').trim().toLowerCase();
    if(!nm) return false;
    if(!/numeric|date|short answer|open|text|number/i.test(q.type||'')) return false;
    return /^(respondent|response|participant|user|record|case)?\s*id$/.test(nm)
      || nm==='identifier'
      || /^(start|end|submission|completion|recorded|response|begin|finish)?\s*(date|time|date\s*\/?\s*time|datetime|timestamp)$/.test(nm)
      || nm==='timestamp' || nm==='duration' || /^duration\b/.test(nm)
      || /^submitted/.test(nm) || /\bip address\b/.test(nm);
  },
  _reviseCards(){
    const qs=(state.survey&&state.survey.questions)||[];
    const byRef=App._reviseScan();
    const active=[], later=[], kept=[];
    qs.forEach((q,i)=>{
      const concerns=App._concernsFor(q,i,byRef);
      if(!concerns.length) return;
      const status=(q.settings&&q.settings.review_status)||'';
      const card={ref:App._reviseRefOf(q,i), index:i, q, concerns};
      if(status==='later') later.push(card);
      else if(status==='kept') kept.push(card);
      else active.push(card);
    });
    return {active, later, kept};
  },
  // Deterministic, rule-based "suggested revision" for one card. No AI, no
  // external model. Source priority (per spec):
  //   1. A safe rule-based rewrite (only where the wording can be transformed
  //      faithfully, e.g. splitting a double-barreled item into its real halves).
  //   2. Tailored deterministic guidance for the flag type (no invented wording).
  //   3. A manual-review prompt when nothing safe can be generated.
  // Returns { kind:'rewrite'|'guidance'|'manual', text, note? }.
  _reviseSuggestion(card){
    const q=card.q, prompt=(q.t||'').trim();
    const primary=card.concerns[0]||{};
    const check=primary.check;
    const endsQ=/\?\s*$/.test(prompt);
    const tidy=(s)=>{
      s=String(s||'').trim().replace(/^[\s,;:.-]+/,'').replace(/[\s,;:.]+$/,'');
      if(!s) return '';
      s=s.charAt(0).toUpperCase()+s.slice(1);
      return s+(endsQ?'?':'.');
    };
    if(check==='double_barreled'){
      const parts=prompt.replace(/[?.]\s*$/,'').split(/\b(?:and|or)\b/i).map(p=>tidy(p)).filter(Boolean);
      if(parts.length===2 && parts[0].length>1 && parts[1].length>1){
        return { kind:'rewrite', text:'1. '+parts[0]+'\n2. '+parts[1],
                 note:'ReliCheck split this into two single-idea questions using your own wording. Edit if needed, then accept.' };
      }
      return { kind:'guidance', text:'Split this into two separate questions so each one measures a single idea.' };
    }
    if(check==='leading')        return { kind:'guidance', text:'Rephrase neutrally so the wording does not signal a preferred answer. State the topic plainly and let respondents judge it.' };
    if(check==='loaded_language')return { kind:'guidance', text:'Replace the emotionally loaded words with neutral, respectful language so respondents can answer honestly.' };
    if(check==='too_few_options')return { kind:'guidance', text:'Add at least two distinct response options so respondents have a real choice.' };
    if(check==='unmatched_construct'){
      const names=((state.survey&&state.survey.constructs)||[]).map(c=>c.name).filter(Boolean);
      const list=names.length?(' (your constructs: '+names.join(', ')+')'):'';
      return { kind:'guidance', text:'Map this item to the construct it measures'+list+', or revise the wording so it clearly reflects one of your defined constructs.' };
    }
    if(check==='item_empty')     return { kind:'guidance', text:'Write the question text, or remove the empty item.' };
    if(primary.how)             return { kind:'guidance', text:primary.how };
    return { kind:'manual', text:'ReliCheck identified an issue, but this item needs manual review. Use Fix Myself to revise the wording.' };
  },
  reviseDraft(ref,v){ state.reviseDrafts[ref]=v; },
  // Accept the deterministic suggested rewrite (only offered when kind==='rewrite').
  reviseAcceptSuggestion(index,ref){
    const q=state.survey&&state.survey.questions[index]; if(!q) return;
    const sug=App._reviseSuggestion({q, concerns:App._concernsFor(q,index)});
    if(sug.kind!=='rewrite'){ toast('No automatic rewrite for this item'); return; }
    const oldText=q.t;
    q.t=sug.text;                       // overwrite the actual item the builder + SDSI use
    if(q.settings&&q.settings.review_status) delete q.settings.review_status;
    delete state.reviseDrafts[ref]; delete state.reviseEditing[ref];
    if(DEBUG_REVISE){
      dlog('Accept suggestion · item id', q.id, '· index', index);
      dlog('  old text:', oldText);
      dlog('  new text:', q.t);
      dlog('  state.survey.questions[index].t now:', state.survey.questions[index].t);
      dlog('  state.survey item === edited q:', state.survey.questions[index]===q);
    }
    App._markSdsiStale();
    toast('ReliCheck suggestion applied'); render(); App._persistItems();
  },
  // Open the inline edit panel. mode 'self' seeds with the original wording;
  // mode 'assist' (Fix with ReliCheck Intelligence) seeds with the deterministic
  // suggestion. No AI is involved in either path for this phase.
  reviseFixOpen(index,ref,mode){
    const q=state.survey&&state.survey.questions[index]; if(!q) return;
    let seed=q.t||'';
    if(mode==='assist'){
      const sug=App._reviseSuggestion({q, concerns:App._concernsFor(q,index)});
      if(sug.kind==='rewrite') seed=sug.text;
    }
    state.reviseEditing[ref]={mode:mode}; state.reviseDrafts[ref]=seed;
    render();
  },
  reviseFixCancel(ref){ delete state.reviseEditing[ref]; delete state.reviseDrafts[ref]; render(); },
  reviseFixApply(index,ref){
    const q=state.survey&&state.survey.questions[index]; if(!q) return;
    const v=((state.reviseDrafts[ref]!=null?state.reviseDrafts[ref]:q.t)||'').trim();
    if(!v){ toast('Add the revised question text first'); return; }
    const oldText=q.t, mode=(state.reviseEditing[ref]||{}).mode;
    q.t=v;                              // overwrite the actual item in place: id,
                                        // construct, constructId, options, settings all preserved
    if(q.settings&&q.settings.review_status) delete q.settings.review_status;
    delete state.reviseDrafts[ref]; delete state.reviseEditing[ref];
    if(DEBUG_REVISE){
      dlog('Save revision · item id', q.id, '· index', index, '· mode', mode);
      dlog('  old text:', oldText);
      dlog('  new text:', q.t);
      dlog('  preserved id/construct/constructId/settings:', q.id, q.construct, q.constructId, q.settings);
      dlog('  state.survey.questions[index].t now:', state.survey.questions[index].t);
    }
    App._markSdsiStale();
    toast('Revision applied'); render(); App._persistItems();
  },
  reviseLater(index,ref){
    const q=state.survey&&state.survey.questions[index]; if(!q) return;
    q.settings=q.settings||{}; q.settings.review_status='later';
    delete state.reviseEditing[ref];
    toast('Marked for later'); render(); App._persistItems();
  },
  // Keep Original: accept the wording as-is (the concern is acknowledged, not changed).
  // Marks the item reviewed so it drops off the active list; no wording overwrite.
  reviseKeep(index,ref){
    const q=state.survey&&state.survey.questions[index]; if(!q) return;
    q.settings=q.settings||{}; q.settings.review_status='kept';
    delete state.reviseEditing[ref]; delete state.reviseDrafts[ref];
    toast('Kept original'); render(); App._persistItems();
  },
  reviseReopen(index,ref){
    const q=state.survey&&state.survey.questions[index]; if(!q) return;
    if(q.settings) delete q.settings.review_status;
    toast('Back in review'); render(); App._persistItems();
  },

  /* ── Question Strength Lab popup ──────────────────────────────────────────
     One question at a time, in a focused modal opened from the Revise list.
     It is a presentation layer over data the engine already produces
     (_concernsFor + _reviseSuggestion) and it applies changes through the
     SAME tested handlers as the Revise page (reviseAcceptSuggestion /
     reviseFixApply / reviseKeep). No new scoring, no new persistence.        */
  _labSevRank(s){ return ({critical:0,major:1,high:1,moderate:2,medium:2,minor:3,low:3})[s] ?? 9; },
  openStrengthLab(index){
    const a=App._reviseCards().active;
    if(!a.length){ toast('Nothing to strengthen'); return; }
    let pos=a.findIndex(c=>c.index===index); if(pos<0) pos=0;
    state.labPos=pos; state.labEdit=null; state.labDraftVal=null; state.labDoneIdx=null;
    App.labClose();
    const ov=document.createElement('div'); ov.className='qsl-scrim';
    ov.addEventListener('click',ev=>{ if(ev.target===ov) App.labClose(); });
    document.body.appendChild(ov); state.labOverlay=ov;
    document.body.style.overflow='hidden';
    App._renderLab();
  },
  labClose(){ if(state.labOverlay){ state.labOverlay.remove(); state.labOverlay=null; } document.body.style.overflow=''; state.labEdit=null; state.labDraftVal=null; state.labDoneIdx=null; },
  // The acted item drops off the active list (resolved or kept); labPos then
  // points at the next question. Re-derive and re-render, or finish.
  _afterLabAction(){ state.labEdit=null; state.labDraftVal=null; state.labDoneIdx=null; App._renderLab(); },
  // After a change is SAVED, show a clear "Saved" confirmation for that question
  // (instead of jumping straight to the next), so the user knows it took.
  _labConfirm(idx){ state.labEdit=null; state.labDraftVal=null; state.labDoneIdx=idx; App._renderLab(); },
  labContinue(){ state.labDoneIdx=null; App._renderLab(); },
  labGoto(k){ state.labPos=k; state.labEdit=null; state.labDraftVal=null; state.labDoneIdx=null; App._renderLab(); },
  labBack(){ if(state.labPos>0) state.labPos--; state.labEdit=null; state.labDraftVal=null; state.labDoneIdx=null; App._renderLab(); },
  labNext(){ const len=App._reviseCards().active.length; if(state.labPos<len-1) state.labPos++; state.labEdit=null; state.labDraftVal=null; state.labDoneIdx=null; App._renderLab(); },
  // Is this a choice-type question whose flag is about its response options?
  // (If so, the in-popup editor edits OPTIONS rather than wording.)
  _labOptionChecks:{missing_response_options:1,placeholder_options:1,too_few_options:1,analysis_readiness_concern:1,overlapping_categories:1,category_gaps:1,non_exhaustive_categories:1,inconsistent_option_structure:1,informal_category_label:1,missing_other_option:1,missing_prefer_not_to_answer:1},
  _labOptionsIssue(c){
    if(!c) return false;
    if(!/single|multi|choice|dropdown|select|rank/i.test(c.q.type||'')) return false;
    return (c.concerns||[]).some(x=>App._labOptionChecks[x.check]);
  },
  labEditOpen(mode){
    const c=App._reviseCards().active[state.labPos]; if(!c) return;
    if(mode==='options'){
      state.labEdit='options';
      const sug=(c.concerns||[]).map(x=>x.opts).filter(o=>Array.isArray(o)&&o.length)[0];
      let seed=sug?sug.slice():(c.q.options||[]).map(o=>String(o)).filter(o=>o.trim()&&!/^option\s*\d+$/i.test(o.trim()));
      while(seed.length<2) seed.push('');
      state.labDraftVal=seed;
    } else {
      state.labEdit=mode;
      let seed=c.q.t||'';
      if(mode==='assist'){ const sug=App._reviseSuggestion(c); if(sug.kind==='rewrite') seed=sug.text; }
      state.labDraftVal=seed;
    }
    App._renderLab();
  },
  labEditCancel(){ state.labEdit=null; state.labDraftVal=null; App._renderLab(); },
  labDraft(v){ state.labDraftVal=v; },
  labAddOpt(){ const d=document.getElementById('qslOpts'); if(!d) return; const div=document.createElement('div'); div.className='orow'; div.innerHTML='<input type="text" value=""><button class="xx" onclick="this.parentNode.remove()">&times;</button>'; d.appendChild(div); const inp=div.querySelector('input'); if(inp) inp.focus(); },
  labUse(idx,ref){ App.reviseAcceptSuggestion(idx,ref); App._labConfirm(idx); },
  labApplyEdit(idx,ref){
    if(state.labEdit==='options'){
      const opts=Array.prototype.map.call(document.querySelectorAll('#qslOpts input'),el=>el.value).filter(v=>v.trim());
      if(opts.length<2){ toast('Add at least two real options'); return; }
      App._labSaveOptions(idx,ref,opts); App._labConfirm(idx); return;
    }
    const v=(state.labDraftVal!=null?state.labDraftVal:'').trim(); if(!v){ toast('Add the revised question text first'); return; }
    state.reviseDrafts[ref]=v; App.reviseFixApply(idx,ref); App._labConfirm(idx);
  },
  labKeep(idx,ref){ App.reviseKeep(idx,ref); App._afterLabAction(); },
  // Write a response-option set onto the item and save it the standard way
  // (_persistItems saves the whole survey, like reviseAcceptSuggestion does for text).
  _labSaveOptions(idx,ref,opts){
    const q=state.survey&&state.survey.questions[idx]; if(!q) return;
    q.options=opts.slice();
    if(q.settings&&q.settings.review_status) delete q.settings.review_status;
    App._markSdsiStale(); toast('Response options applied'); render(); App._persistItems();
  },
  // Apply the engine's suggested option set in one click (when a flag carries one).
  labUseOptions(idx,ref){
    const c=App._reviseCards().active.filter(x=>x.index===idx)[0];
    const opts=c&&(c.concerns||[]).map(x=>x.opts).filter(o=>Array.isArray(o)&&o.length)[0];
    if(!opts){ toast('No suggested options for this item'); return; }
    App._labSaveOptions(idx,ref,opts); App._labConfirm(idx);
  },
  _labOptsHtml(q){
    const e=escapeHtml;
    const opts=(q.options||[]).map(o=>String(o)).filter(o=>o.trim()!=='');
    if(opts.length) return '<div class="qsl-opts">'+opts.map(o=>`<div class="qsl-opt"><span class="rk"></span>${e(o)}</div>`).join('')+'</div>';
    const st=q.settings||{};
    if(st.likertLow||st.likertHigh) return `<div class="qsl-scale"><span class="qsl-pt">${e(st.likertLow||'low')}</span><span class="qsl-pt" style="flex:1.6;color:var(--ink-3)">·····</span><span class="qsl-pt">${e(st.likertHigh||'high')}</span></div>`;
    if(/single|multi|choice|dropdown|select/i.test(q.type||'')) return `<div class="qsl-opt bad"><span class="rk"></span>No response options yet</div>`;
    return '';
  },
  _renderLab(){
    if(!state.labOverlay) return;
    const e=escapeHtml;
    const a=App._reviseCards().active;
    // Live SDSI for the header score (re-assessed so the number moves as you fix).
    const sd=(window.BuildCheck&&window.BuildCheck.assess)?window.BuildCheck.assess(App._buildCheckProject()):state.sdsiResult;
    const total=sd&&sd.total!=null?sd.total:null;

    // ── "Saved" confirmation (shown right after a change is applied) ──────────
    if(state.labDoneIdx!=null){
      const q=state.survey&&state.survey.questions[state.labDoneIdx];
      const moreLeft=a.length;
      let scoreNote='';
      if(sd&&sd.itemScores&&q){ const s=sd.itemScores.filter(x=>x.question_text===(q.t||''))[0]; if(s) scoreNote=`It now scores <b>${s.score}</b> / 50. `; }
      const opts=q?(q.options||[]).map(o=>String(o)).filter(o=>o.trim()):[];
      state.labOverlay.innerHTML=`<div class="qsl-modal">
        <div class="qsl-mhead"><div class="qsl-mr1">
          <span class="qsl-qn">Saved</span>
          <span class="qsl-sc">SDSI ${total!=null?total.toFixed(1):'—'} / 50</span>
          <button class="qsl-x" title="Done for now" onclick="App.labClose()">&times;</button>
        </div></div>
        <div class="qsl-mbody">
          <div class="qsl-strong"><span class="ic">✓</span><p>Saved. Your change is applied to this question.</p></div>
          <p class="qsl-qtext" style="margin-top:14px">${e(q?(q.t||'(no text)'):'')}</p>
          ${opts.length?`<div class="qsl-opts">${opts.map(o=>`<div class="qsl-opt good"><span class="rk"></span>${e(o)}</div>`).join('')}</div>`:''}
          <p style="margin:14px 0 0;color:var(--ink-2);font-size:13.5px">${scoreNote}${moreLeft?`${moreLeft} question${moreLeft===1?'':'s'} left to strengthen.`:'Every flagged question has been reviewed.'}</p>
        </div>
        <div class="qsl-mfoot">
          <button class="btn sm" onclick="App.labClose()">Done for now</button>
          <div class="sp"></div>
          ${moreLeft?`<button class="btn sm primary" onclick="App.labContinue()">Next question →</button>`:`<button class="btn sm primary" onclick="App.labClose();App.runSdsi()">Finish and re-run Build Check →</button>`}
        </div>
      </div>`;
      return;
    }

    if(!a.length){
      state.labOverlay.innerHTML=`<div class="qsl-modal"><div class="qsl-mbody" style="text-align:center;padding:34px 24px">
        <div class="qsl-strong" style="display:inline-flex;margin:0 0 14px"><span class="ic">✓</span><p>Every question reviewed</p></div>
        <h2 style="margin:0 0 6px;font-size:21px;font-weight:820">Nice work</h2>
        <p class="muted" style="margin:0 auto 18px;max-width:380px;color:var(--ink-2)">Your survey SDSI is now ${total!=null?total.toFixed(1):'updated'} / 50. Re-run the Build Check to save the new score, then move on to preview.</p>
        <button class="btn primary" onclick="App.labClose();App.runSdsi()">Re-run Build Check ${SVG.arrow}</button>
        <button class="btn" style="margin-left:8px" onclick="App.labClose()">Back to my survey</button>
      </div></div>`;
      return;
    }
    if(state.labPos>=a.length) state.labPos=a.length-1;
    const c=a[state.labPos], q=c.q, idx=c.index, ref=c.ref;
    const concerns=(c.concerns||[]).slice().sort((x,y)=>App._labSevRank(x.sev)-App._labSevRank(y.sev));
    const top=concerns[0]||{};
    const headline=top.msg||'This question can be stronger.';
    const others=concerns.slice(1);
    const worst=concerns.some(x=>x.sev==='critical'||x.sev==='major');
    const sug=App._reviseSuggestion(c);
    const optMove=(concerns.map(x=>x.opts).filter(o=>Array.isArray(o)&&o.length)[0])||null;
    const isOptsIssue=App._labOptionsIssue(c);

    // Why this matters (section 2): the flag's own reason, with a sane fallback.
    const why=top.why||'A clearer question gives you data you can actually trust and compare across people.';

    // Score impact (section 4): live per-item score + cap.
    let scoreImpact='';
    if(sd&&sd.itemScores){ const s=sd.itemScores.filter(x=>x.question_text===(q.t||''))[0];
      if(s) scoreImpact=`<div class="qsl-sec"><div class="qsl-sech"><span class="qsl-secn">4</span><span class="qsl-sect">Score impact</span></div>
        <div class="qsl-impact"><div class="ln"><span>This question</span><span><b>${s.score}</b> / 50${s.most_restrictive_cap!=null?` (capped at ${s.most_restrictive_cap})`:''}</span></div>
        <div class="ln"><span>Your survey SDSI</span><span><b>${total!=null?total.toFixed(1):'—'}</b> / 50</span></div></div></div>`; }

    // Ways to strengthen (section 3): a concrete move when the engine has one,
    // otherwise the plain-language guidance. Editor opens on Adapt / Edit myself.
    let strengthen='';
    if(sug.kind==='rewrite'){
      strengthen=`<div class="qsl-move sel"><div class="qsl-mt"><span>Use this revision</span></div>
        <div class="qsl-prev"><span class="q2">${e(sug.text)}</span></div></div>
        ${sug.note?`<p class="qsl-sec p muted" style="font-size:12.5px;color:var(--ink-3);margin:2px 0 0">${e(sug.note)}</p>`:''}`;
    } else if(optMove){
      strengthen=`<div class="qsl-move sel"><div class="qsl-mt"><span>Use these response options</span></div>
        <div class="qsl-prev">${optMove.map(o=>`<span class="chip">${e(o)}</span>`).join('')}</div></div>`;
    } else {
      strengthen=`<p style="margin:0;font-size:14px">${e(sug.text)}</p>`;
    }
    let editor='';
    if(state.labEdit==='options'){
      const arr=Array.isArray(state.labDraftVal)?state.labDraftVal:['',''];
      editor=`<div class="qsl-ed"><div id="qslOpts">${arr.map(o=>`<div class="orow"><input type="text" value="${e(o)}" placeholder="Answer choice"><button class="xx" title="Remove" onclick="this.parentNode.remove()">&times;</button></div>`).join('')}</div>
        <button class="btn sm ghost" onclick="App.labAddOpt()">+ Add option</button></div>`;
    } else if(state.labEdit){
      const draft=state.labDraftVal!=null?state.labDraftVal:(q.t||'');
      editor=`<div class="qsl-ed"><textarea rows="3" oninput="App.labDraft(this.value)">${e(draft)}</textarea></div>`;
    }

    // Footer: editor mode shows Save/Cancel; otherwise the action ladder, shaped
    // by whether this is an options issue (edit options) or a wording issue.
    let foot;
    if(state.labEdit){
      const saveLabel=state.labEdit==='options'?'Save options':'Save my version';
      foot=`<button class="btn sm" onclick="App.labEditCancel()">Cancel</button><div class="sp"></div>
        <button class="btn sm primary" onclick="App.labApplyEdit(${idx},'${ref}')">${saveLabel}</button>`;
    } else {
      let primary;
      if(isOptsIssue){
        primary=(optMove?`<button class="btn sm primary" onclick="App.labUseOptions(${idx},'${ref}')">Use these options</button>`:'')
          +`<button class="btn sm ${optMove?'':'primary'}" onclick="App.labEditOpen('options')">Edit options</button>`;
      } else if(sug.kind==='rewrite'){
        primary=`<button class="btn sm primary" onclick="App.labUse(${idx},'${ref}')">Use this revision</button>
          <button class="btn sm" onclick="App.labEditOpen('assist')">Adapt</button>
          <button class="btn sm" onclick="App.labEditOpen('self')">Edit myself</button>`;
      } else {
        primary=`<button class="btn sm primary" onclick="App.labEditOpen('self')">Edit myself</button>`;
      }
      foot=`<button class="btn sm" onclick="App.labBack()" ${state.labPos===0?'disabled style="opacity:.45"':''}>← Back</button>
        <button class="btn sm" onclick="App.labKeep(${idx},'${ref}')">Keep original</button>
        <button class="btn sm" onclick="App.labNext()">Skip</button>
        <div class="sp"></div>
        ${primary}`;
    }

    const dots=a.map((x,k)=>`<span class="qsl-dot ${k===state.labPos?'cur':''}" title="Question ${k+1}" onclick="App.labGoto(${k})"></span>`).join('');
    state.labOverlay.innerHTML=`<div class="qsl-modal">
      <div class="qsl-mhead">
        <div class="qsl-mr1">
          <span class="qsl-qn">Question ${state.labPos+1} of ${a.length}</span>
          <span class="badge ${worst?'red':'amber'}">${worst?'Needs revision':'Caution'}</span>
          <span class="qsl-sc">SDSI ${total!=null?total.toFixed(1):'—'} / 50</span>
          <button class="qsl-x" title="Done for now" onclick="App.labClose()">&times;</button>
        </div>
        <div class="qsl-dots">${dots}</div>
      </div>
      <div class="qsl-mbody">
        <div style="display:flex;align-items:center;gap:8px;margin-bottom:9px">
          <span class="badge gray">${e(typeLabel(q.type))}</span>
        </div>
        <p class="qsl-qtext">${e(q.t||'(no text)')}</p>
        ${App._labOptsHtml(q)}
        <div class="qsl-sec"><div class="qsl-sech"><span class="qsl-secn">1</span><span class="qsl-sect">What ReliCheck noticed</span></div>
          <p>${e(headline)}</p>
          ${others.length?`<ul style="margin:7px 0 0;padding-left:18px;font-size:13px;color:var(--ink-2)">${others.map(o=>`<li>${e(o.msg)}</li>`).join('')}</ul>`:''}</div>
        <div class="qsl-sec"><div class="qsl-sech"><span class="qsl-secn">2</span><span class="qsl-sect">Why this matters</span></div>
          <p class="muted">${e(why)}</p></div>
        <div class="qsl-sec"><div class="qsl-sech"><span class="qsl-secn">3</span><span class="qsl-sect">Ways to strengthen this question</span></div>
          ${strengthen}${editor}</div>
        ${scoreImpact}
      </div>
      <div class="qsl-mfoot">${foot}</div>
    </div>`;
  },

  runSiri(){
    if(!(window.LaunchCheck && window.LaunchCheck.assess)){
      // Engine script failed to load; show the empty state rather than a fake score.
      state.siriResult=null; toast('Launch Check engine unavailable'); App.go('siri'); return;
    }
    const proj=App._buildCheckProject();
    const r=window.LaunchCheck.assess(proj, { sdsiResult: state.sdsiResult });
    state.siriResult=r;
    state.siriStale=false;
    const hasTotal=(r.total!=null);
    toast(hasTotal
      ? ('SIRI '+r.siri.toFixed(1)+' / 50 · Total '+r.total.toFixed(1)+' / 100 ('+r.total_band+')')
      : ('SIRI scored '+r.siri.toFixed(1)+' / 50'));
    if(PERSIST_REQUESTED && state.projectId){
      // Persist the meaningful headline (Total /100 + band when SDSI is available,
      // else SIRI /50). The full review object carries the rest for rehydration.
      DB.call('siri-save.php',{method:'POST',body:{
        project_id:state.projectId,
        total: hasTotal ? r.total : r.siri,
        max: hasTotal ? 100 : 50,
        pct: hasTotal ? Math.round(r.total) : r.pct,
        band: hasTotal ? r.total_band : '',
        blocked: (r.deployment_blocker_count||0) >= 1,
        review:r
      }}).catch(e=>degrade(e.message));
    }
    App.go('siri');
  },
  setPublishOverride(v){ state.publishOverride=!!v; render(); },
  async publishNow(force){
    // SIRI is the launch gate. Normally publishing requires status 'ready'. A user
    // may deliberately publish past a HARD blocker only after explicitly
    // acknowledging it (force + state.publishOverride); cautions never block.
    const g=App._publishGate();
    if(g.status!=='ready'){
      const acknowledged = force && g.status==='blocked' && state.publishOverride;
      if(!acknowledged){ toast('Resolve the Launch Check before publishing, or acknowledge to publish anyway'); App.go('publish'); return; }
    }
    // A real project id means the project exists on the server (an uploaded or saved
    // survey), so do NOT hard-block on a possibly-stale PERSIST.on flag. Attempt the
    // publish and let the server be the source of truth; only true demo mode with no
    // server project at all (no id) cannot publish.
    if(!state.projectId){ toast('Publishing needs a saved survey. Open or upload one with ?db=1 while signed in — demo mode cannot generate a real survey link.'); return; }
    const overrode = g.status==='blocked' && state.publishOverride;
    // Phase 3B: call the publish endpoint — generates the link key, sets status = published.
    // Does NOT yet open responses; that is Phase 3C.
    if(state.deploymentSettings && state.deploymentSettings.link_key){
      // Already published; idempotent — just show the deploy screen.
      state.publishReady=true; App.go('deploy'); return;
    }
    try {
      const r=await DB.call('project-publish.php',{method:'POST',body:{project_id:state.projectId, override:overrode}});
      // Success proves the session + DB are live — clear any stale degraded flag so
      // the rest of the app stops thinking it is in demo mode.
      if(!PERSIST.on){ PERSIST.on=true; PERSIST.degraded=false; PERSIST.reason=''; }
      state.deploymentSettings=r.deployment;
      state.publishReady=true;
      toast(overrode ? 'Published with unresolved issues acknowledged. Survey link generated.' : 'Survey link generated. Responses open in a later phase.');
      App.go('deploy');
    } catch(e){
      // Genuine failure now (e.g. not signed in). Be specific instead of silently
      // dropping to mock mode.
      toast('Could not publish: '+(e&&e.message||'the server rejected the request')+'. If you are not signed in, reopen with ?db=1 while logged in.');
      render();
    }
  },
  async toggleResponsesOpen(){
    const ds=state.deploymentSettings;
    if(!ds||!ds.link_key){ toast('Generate a survey link first'); return; }
    const opening=!ds.responses_open;
    try {
      const r=await DB.call('project-open.php',{method:'POST',body:{project_id:state.projectId,open:opening}});
      state.deploymentSettings=r.deployment;
      toast(opening ? 'Survey is now open for responses.' : 'Survey is now closed.');
      render();
    } catch(e){ degrade(e.message); }
  },
  // Phase 3E: load stored responses for the current project, then re-render.
  // Called once by the retrieve() screen when its list has not been fetched yet.
  async _loadResponses(){
    if(!(PERSIST_REQUESTED && state.projectId)) return;
    if(state.responsesLoading || state.responseList!==null) return;
    state.responsesLoading=true; state.responsesError=null;
    try {
      const r=await DB.call('project-responses.php?project_id='+encodeURIComponent(state.projectId));
      state.responseList=r.sessions||[];
      state.responseCount=(typeof r.count==='number')?r.count:state.responseList.length;
    } catch(e){
      state.responsesError=e.message||'Could not load responses.';
      state.responseList=[];
    } finally {
      state.responsesLoading=false; render();
    }
  },
  // Reload the response list (clears cache then re-fetches).
  refreshResponses(){ state.responseList=null; state.responsesError=null; App._loadResponses(); render(); },
  // Phase 3F: download a CSV of stored responses for the current project. The
  // export endpoint is authenticated (same-origin cookie) and owner-gated; we
  // navigate to it so the browser handles the file download. No-op with a toast
  // when there is nothing to export.
  exportResponsesCsv(){
    if(!(PERSIST_REQUESTED && state.projectId)){ toast('Database mode required to export'); return; }
    if(!state.responseList || state.responseList.length===0){ toast('No responses to export yet'); return; }
    const url='/api/dev/project-export.php?project_id='+encodeURIComponent(state.projectId);
    window.location.href=url;
  },
  // ── Phase 4A: RSSI Dataset Loader ──────────────────────────────────────────
  // Load the stored responses into a normalized, analysis-ready dataset the RSSI
  // engine can score in a later phase. This only loads/shapes data; it computes
  // no RSSI score and no reliability statistic. Called once by the dataset screen.
  async _loadDataset(){
    if(!(PERSIST_REQUESTED && state.projectId)) return;
    if(state.datasetLoading || state.dataset!==null) return;
    state.datasetLoading=true; state.datasetError=null;
    try {
      const r=await DB.call('rssi-dataset.php?project_id='+encodeURIComponent(state.projectId));
      state.dataset=r;
    } catch(e){
      state.datasetError=e.message||'Could not load the RSSI dataset.';
      state.dataset=false;
    } finally {
      state.datasetLoading=false; render();
    }
  },
  refreshDataset(){ state.dataset=null; state.datasetError=null; App._loadDataset(); render(); },
  // "Bring In an Existing Survey" (the Upload in Build / Upload / Template).
  // Uploads the file, whose columns are materialized into the survey's questions
  // server-side (link-dataset.php), then loads the populated project and enters
  // the pipeline at Study Setup. The response rows stay linked for RSSI later.
  openFreshUpload(){
    if(!window.DatasetUpload){ toast('Upload widget unavailable'); return; }
    App._resetStudy();
    state.entry='upload';
    DatasetUpload.open({
      projectType:'survey',
      projectId: null,
      onLoaded(_err, newProjectId){
        if(!newProjectId){ return; }
        if(!PERSIST.on){ PERSIST.on = true; PERSIST.degraded = false; PERSIST.reason = ''; }
        // Load the project so its freshly materialized questions hydrate, then
        // start at Study Setup (the top of the pipeline), NOT at RSSI.
        (async()=>{
          try {
            const r = await DB.call('project-load.php?id='+encodeURIComponent(newProjectId));
            DB.hydrate(r);
            const n = (state.survey && state.survey.questions) ? state.survey.questions.length : 0;
            toast(n ? ('Brought in '+n+' question'+(n===1?'':'s')) : 'Survey uploaded');
          } catch(e){
            state.projectId = +newProjectId;
            try{ localStorage.setItem(LS_KEY, String(state.projectId)); }catch(_){}
            degrade(e.message);
          }
          App.go('setup');
        })();
      }
    });
  },
  // Called from the dataset screen — links to the current project if one is loaded.
  openUploadDataset(){
    if(!window.DatasetUpload){ toast('Upload widget unavailable'); return; }
    DatasetUpload.open({
      projectType:'survey',
      projectId: state.projectId || null,
      onLoaded(_err, newProjectId){
        if(newProjectId){
          state.projectId = +newProjectId;
          if(!PERSIST.on){ PERSIST.on = true; PERSIST.degraded = false; PERSIST.reason = ''; }
          try{ localStorage.setItem(LS_KEY, String(state.projectId)); }catch(_){}
        }
        App.go('dataset');
        App.refreshDataset();
      }
    });
  },
  // ── Phase 4C: run + persist RSSI ───────────────────────────────────────────
  // Runs the deterministic RSSI engine (apps/rssi/rssi-engine.js) over the
  // Phase 4A dataset in the browser, then POSTs the structured result to
  // api/dev/rssi-run.php which persists it to rssi_reviews (separate from SDSI
  // and SIRI) and stamps the authoritative response-data fingerprint. The
  // engine honestly withholds the score when the N fence trips.
  async runRssi(){
    if(!(PERSIST_REQUESTED && state.projectId)){ toast('Database mode required to run RSSI'); return; }
    if(!(window.RSSIEngine && window.RSSIEngine.score)){ toast('RSSI engine unavailable'); return; }
    // Make sure we have the dataset (load it if the preview has not yet).
    let ds=state.dataset;
    if(!ds || typeof ds!=='object'){
      try { ds=await DB.call('rssi-dataset.php?project_id='+encodeURIComponent(state.projectId)); state.dataset=ds; }
      catch(e){ degrade(e.message); toast('Could not load the dataset'); return; }
    }
    state.rssiRunning=true; render();
    let result;
    try { result=window.RSSIEngine.score(ds); }
    catch(e){ state.rssiRunning=false; toast('RSSI engine error: '+e.message); render(); return; }
    state.rssiResult=result;
    try {
      const r=await DB.call('rssi-run.php',{method:'POST',body:{project_id:state.projectId, result}});
      state.rssiSaved=r.rssi||null;
      state.rssiStale=!!(r.rssi && r.rssi.stale);
      toast(result.score===null ? 'RSSI: Insufficient data to judge' : ('RSSI scored '+result.score.toFixed(1)+' / 100'));
    } catch(e){ degrade(e.message); }
    finally { state.rssiRunning=false; render(); }
  },

  // ── Phase 4F: export the SAVED RSSI report ─────────────────────────────────
  // Both exports read straight from state.rssiResult; they never call the engine,
  // so the export can never disagree with what is on screen. printRssi opens a
  // self-contained, print-ready window (Save as PDF from the browser dialog);
  // exportRssiJson downloads the raw result object for debugging.
  printRssi(){
    const rr=state.rssiResult;
    if(!rr){ toast('Run RSSI first'); return; }
    const w=window.open('', '_blank');
    if(!w){ toast('Allow pop-ups to print the report'); return; }
    w.document.open();
    w.document.write(buildRssiPrintDoc(rr, state.rssiSaved));
    w.document.close();
    // Print once the new document has laid out; guard for browsers that fire load early.
    const go=()=>{ try{ w.focus(); w.print(); }catch(e){} };
    if(w.document.readyState==='complete') setTimeout(go,150); else w.onload=()=>setTimeout(go,150);
  },
  exportRssiJson(){
    const rr=state.rssiResult;
    if(!rr){ toast('Run RSSI first'); return; }
    const title=(state.survey&&state.survey.title)||state.study.name||'survey';
    const payload={
      project:title,
      response_count: state.rssiSaved && state.rssiSaved.response_count!=null ? state.rssiSaved.response_count : null,
      current_count: state.rssiSaved && state.rssiSaved.current_count!=null ? state.rssiSaved.current_count : null,
      stale: !!state.rssiStale,
      exported_at: new Date().toISOString(),
      result: rr   // the saved RSSIEngine.score() object, verbatim — not recomputed
    };
    const slug=String(title).toLowerCase().replace(/[^a-z0-9]+/g,'-').replace(/^-+|-+$/g,'')||'survey';
    const blob=new Blob([JSON.stringify(payload,null,2)],{type:'application/json'});
    const url=URL.createObjectURL(blob);
    const a=document.createElement('a');
    a.href=url; a.download='rssi-'+slug+'.json';
    document.body.appendChild(a); a.click(); a.remove();
    setTimeout(()=>URL.revokeObjectURL(url), 1000);
    toast('RSSI JSON exported');
  },
  stub(label){ toast(label+' connects in a later phase'); },

  // ── Step 9: Deploy + Export (all client-side; no server endpoints added) ─────
  _publicUrl(){
    const ds=state.deploymentSettings;
    return (ds && ds.link_key) ? ('https://relichecksurvey.com/s/'+ds.link_key) : null;
  },
  _needLink(){
    const u=App._publicUrl();
    if(!u){ toast('Publish the survey first to get a shareable link'); App.go('publish'); return null; }
    return u;
  },
  _surveySlug(){
    const t=(state.survey&&state.survey.title)||state.study.name||'survey';
    return String(t).toLowerCase().replace(/[^a-z0-9]+/g,'-').replace(/^-+|-+$/g,'')||'survey';
  },
  // Normalized instrument items for export: numbered questions + section markers.
  _instrumentItems(){
    const qs=((state.survey&&state.survey.questions)||[]);
    const structural=['Section Text','Page Break','Thank-you Message'];
    let n=0; const out=[];
    qs.forEach(q=>{
      const isStruct=structural.includes(q.type);
      if(!isStruct) n++;
      out.push({ n:isStruct?null:n, structural:isStruct, prompt:q.t||'', type:q.type||'',
        required:!!q.required, construct:q.construct||'', options:(q.options&&q.options.length)?q.options.slice():[] });
    });
    return out;
  },
  _download(filename, text, mime){
    const blob=new Blob([text],{type:mime||'text/plain'});
    const url=URL.createObjectURL(blob);
    const a=document.createElement('a'); a.href=url; a.download=filename;
    document.body.appendChild(a); a.click(); a.remove();
    setTimeout(()=>URL.revokeObjectURL(url),1000);
  },
  _copy(text){
    const ok=()=>toast('Copied to clipboard');
    if(navigator.clipboard && navigator.clipboard.writeText){ navigator.clipboard.writeText(text).then(ok).catch(()=>App._copyFallback(text)); }
    else App._copyFallback(text);
  },
  _copyFallback(text){
    const ta=document.createElement('textarea'); ta.value=text; ta.style.position='fixed'; ta.style.opacity='0';
    document.body.appendChild(ta); ta.select();
    try{ document.execCommand('copy'); toast('Copied to clipboard'); }catch(e){ toast('Select the text and copy manually'); }
    ta.remove();
  },
  _modal(title, bodyHtml){
    const ov=document.createElement('div'); ov.className='dlg-overlay';
    ov.innerHTML='<div class="dlg"><button class="dlg-x" aria-label="Close">&times;</button>'
      +'<h3 class="dlg-h">'+escapeHtml(title)+'</h3><div class="dlg-body">'+bodyHtml+'</div></div>';
    const close=()=>ov.remove();
    ov.addEventListener('click',e=>{ if(e.target===ov) close(); });
    ov.querySelector('.dlg-x').addEventListener('click',close);
    document.body.appendChild(ov);
    return { el:ov, close:close };
  },
  // 1 — Share link
  shareLink(){
    const url=App._needLink(); if(!url) return;
    const e=escapeHtml;
    const subj=encodeURIComponent('You are invited to take a survey');
    const body=encodeURIComponent('Please take a moment to complete this survey:\n\n'+url+'\n\nThank you.');
    const m=App._modal('Share survey link',
      '<input class="dlg-input" id="shUrl" readonly value="'+e(url)+'">'
      +'<div class="dlg-btns">'
        +'<button class="btn primary" id="shCopy">Copy link</button>'
        +'<a class="btn" href="mailto:?subject='+subj+'&body='+body+'">Email</a>'
        +'<a class="btn" href="'+e(url)+'" target="_blank" rel="noopener">Open survey</a>'
      +'</div>'
      +'<p class="dlg-note">Anyone with this link can respond once the survey is open for responses.</p>');
    m.el.querySelector('#shCopy').addEventListener('click',()=>App._copy(url));
    m.el.querySelector('#shUrl').addEventListener('focus',function(){ this.select(); });
  },
  // 2 — QR / mobile
  showQR(){
    const url=App._needLink(); if(!url) return;
    const m=App._modal('QR code',
      '<div id="qrBox" style="display:flex;justify-content:center;align-items:center;min-height:200px;padding:10px 0;color:var(--muted)">Generating…</div>'
      +'<div class="dlg-btns"><button class="btn primary" id="qrDl" disabled>Download PNG</button>'
      +'<a class="btn" href="'+escapeHtml(url)+'" target="_blank" rel="noopener">Open survey</a></div>'
      +'<p class="dlg-note">Print or display this code for in-person or on-site fielding.</p>');
    App._loadQR().then(QR=>{
      const box=m.el.querySelector('#qrBox'); if(!box) return;
      box.innerHTML='';
      const holder=document.createElement('div'); box.appendChild(holder);
      // qrcodejs renders a <canvas> (and <img> fallback) into the holder element.
      try { new QR(holder, { text:url, width:220, height:220, correctLevel: QR.CorrectLevel.M }); }
      catch(e){ box.textContent='Could not generate the QR code.'; return; }
      setTimeout(()=>{
        const canvas=holder.querySelector('canvas'), img=holder.querySelector('img');
        const dataUrl = canvas ? canvas.toDataURL('image/png') : (img ? img.src : '');
        if(!dataUrl) return;
        const dl=m.el.querySelector('#qrDl'); dl.disabled=false;
        dl.addEventListener('click',()=>{ const a=document.createElement('a'); a.href=dataUrl;
          a.download='survey-qr-'+App._surveySlug()+'.png'; document.body.appendChild(a); a.click(); a.remove(); });
      }, 250);
    }).catch(()=>{ const box=m.el.querySelector('#qrBox'); if(box) box.textContent='Could not load the QR generator.'; });
  },
  _loadQR(){
    if(window.QRCode) return Promise.resolve(window.QRCode);
    if(App._qrP) return App._qrP;
    App._qrP=new Promise((res,rej)=>{
      const s=document.createElement('script');
      s.src='https://cdn.jsdelivr.net/npm/qrcodejs@1.0.0/qrcode.min.js';
      s.onload=()=>res(window.QRCode); s.onerror=()=>rej(new Error('qr load failed'));
      document.head.appendChild(s);
    });
    return App._qrP;
  },
  // 3 — Invite panel (composes an email to the user's mail client; managed
  //     sending + automatic reminders are a later backend phase, stated plainly).
  invitePanel(){
    const url=App._needLink(); if(!url) return;
    const e=escapeHtml;
    const m=App._modal('Invite a respondent list',
      '<label class="dlg-label">Email addresses (one per line, or comma-separated)</label>'
      +'<textarea class="dlg-input" id="invEmails" rows="5" placeholder="alex@example.com&#10;sam@example.com"></textarea>'
      +'<label class="dlg-label">Message</label>'
      +'<textarea class="dlg-input" id="invMsg" rows="3">Please take a moment to complete this survey:\n\n'+e(url)+'\n\nThank you.</textarea>'
      +'<div class="dlg-btns"><button class="btn primary" id="invSend">Compose email</button></div>'
      +'<p class="dlg-note">This opens your email client with the recipients on BCC. Managed sending and automatic reminders are not yet available.</p>');
    m.el.querySelector('#invSend').addEventListener('click',()=>{
      const raw=m.el.querySelector('#invEmails').value||'';
      const emails=raw.split(/[\s,;]+/).map(s=>s.trim()).filter(s=>/@/.test(s));
      if(!emails.length){ toast('Add at least one email address'); return; }
      const subj=encodeURIComponent('You are invited to take a survey');
      const body=encodeURIComponent(m.el.querySelector('#invMsg').value||url);
      window.location.href='mailto:?bcc='+encodeURIComponent(emails.join(','))+'&subject='+subj+'&body='+body;
    });
  },
  // 4 — Word / PDF: print-ready instrument (Save as PDF from the browser dialog).
  exportInstrumentDoc(){
    const items=App._instrumentItems();
    if(!items.length){ toast('Add questions before exporting the instrument'); return; }
    const w=window.open('','_blank');
    if(!w){ toast('Allow pop-ups to export the instrument'); return; }
    w.document.open(); w.document.write(buildInstrumentDoc(items)); w.document.close();
    const go=()=>{ try{ w.focus(); w.print(); }catch(e){} };
    if(w.document.readyState==='complete') setTimeout(go,150); else w.onload=()=>setTimeout(go,150);
  },
  // 5 — CSV / Excel: item-bank export.
  exportInstrumentCsv(){
    const items=App._instrumentItems().filter(it=>!it.structural);
    if(!items.length){ toast('Add questions before exporting'); return; }
    const esc=v=>{ v=String(v==null?'':v); return /[",\n]/.test(v)?'"'+v.replace(/"/g,'""')+'"':v; };
    const rows=[['No','Question','Type','Required','Construct','Options']];
    items.forEach(it=>rows.push([it.n, it.prompt, it.type, it.required?'Required':'Optional', it.construct, (it.options||[]).join(' | ')]));
    const csv='﻿'+rows.map(r=>r.map(esc).join(',')).join('\r\n');
    App._download('instrument-'+App._surveySlug()+'.csv', csv, 'text/csv');
    toast('Instrument CSV exported');
  },
  // 6 — API / Qualtrics: portable JSON to import into another platform.
  exportInstrumentJson(){
    const items=App._instrumentItems();
    if(!items.filter(it=>!it.structural).length){ toast('Add questions before exporting'); return; }
    const payload={
      title:(state.survey&&state.survey.title)||state.study.name||'Survey',
      purpose:state.study.purpose||'', population:state.study.population||'',
      exported_at:new Date().toISOString(),
      item_count:items.filter(it=>!it.structural).length,
      items:items.map((it,i)=>({ position:i+1, kind:it.structural?'section':'question', prompt:it.prompt,
        type:it.type, required:it.required, construct:it.construct||null, options:it.options||[] }))
    };
    App._download('instrument-'+App._surveySlug()+'.json', JSON.stringify(payload,null,2), 'application/json');
    toast('Instrument exported for import into another platform');
  },
};
window.App = App;

function setModeBadgeSaving(on){
  const b=$('#modeBadge'); if(!b||!PERSIST_REQUESTED) return;
  if(on){ b.className='modebadge saving'; b.textContent='Saving…'; }
  else { updateModeBadge(); }
}

/* ════════════════════════════════════════════════════════════════════
   RING (SVG donut) for the SIRI score
   ════════════════════════════════════════════════════════════════════ */
function ring(pct, color, valueText, labelText){
  const r=58, c=2*Math.PI*r, off=c*(1-pct/100);
  return `<div class="ring"><svg width="132" height="132">
    <circle cx="66" cy="66" r="${r}" stroke="var(--bg)" stroke-width="11" fill="none"/>
    <circle cx="66" cy="66" r="${r}" stroke="${color}" stroke-width="11" fill="none"
      stroke-linecap="round" stroke-dasharray="${c}" stroke-dashoffset="${off}"/>
  </svg><div class="val"><b>${valueText}</b><small>${labelText}</small></div></div>`;
}

/* ════════════════════════════════════════════════════════════════════
   QUESTION BUILDER RENDERING. Picker overlay, response previews, and
   the per-question settings panel.
   ════════════════════════════════════════════════════════════════════ */
function escapeHtml(s){ return String(s==null?'':s).replace(/[&<>"]/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c])); }

// Step 9 export: a self-contained, print-ready instrument document (Word/PDF via
// the browser's print dialog). Renders every question with its answer format from
// the live survey — no server call, no recompute.
function buildInstrumentDoc(items){
  const e=escapeHtml;
  const title=e((state.survey&&state.survey.title)||state.study.name||'Survey');
  const purpose=state.study.purpose?('<p class="purpose">'+e(state.study.purpose)+'</p>'):'';
  const body=items.map(it=>{
    if(it.structural){ return '<div class="sec">'+e(it.prompt||'')+'</div>'; }
    const t=it.type, opts=it.options||[]; let ans;
    if(/Likert|Rating|Scale|NPS/i.test(t)){
      const n=/NPS/i.test(t)?11:5;
      ans='<div class="scale">'+Array.from({length:n},(_,i)=>'<span>'+(/NPS/i.test(t)?i:i+1)+'</span>').join('')+'</div>';
    } else if(opts.length){ ans='<ul class="opts">'+opts.map(o=>'<li>&#9633; '+e(o)+'</li>').join('')+'</ul>'; }
    else if(/Yes\/No/i.test(t)){ ans='<ul class="opts"><li>&#9633; Yes</li><li>&#9633; No</li></ul>'; }
    else if(/True\/False/i.test(t)){ ans='<ul class="opts"><li>&#9633; True</li><li>&#9633; False</li></ul>'; }
    else { ans='<div class="line"></div>'; }
    return '<div class="q"><div class="stem"><b>'+it.n+'.</b> '+e(it.prompt||'(untitled)')+(it.required?' <span class="req">*</span>':'')+'</div>'+ans+'</div>';
  }).join('');
  return '<!doctype html><html><head><meta charset="utf-8"><title>'+title+'</title><style>'
    +'body{font-family:-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif;color:#1c2238;max-width:680px;margin:32px auto;padding:0 24px;line-height:1.5}'
    +'h1{font-size:24px;margin:0 0 4px;letter-spacing:-.02em} .purpose{color:#555;margin:0 0 20px}'
    +'.q{margin:0 0 18px;page-break-inside:avoid} .stem{font-size:15px;margin-bottom:8px} .req{color:#c2271b}'
    +'.opts{list-style:none;padding:0;margin:6px 0 0} .opts li{margin:3px 0;color:#333}'
    +'.scale{display:flex;gap:12px;color:#555;flex-wrap:wrap} .scale span{border:1px solid #ccc;border-radius:50%;width:26px;height:26px;display:inline-flex;align-items:center;justify-content:center;font-size:12.5px}'
    +'.line{border-bottom:1px solid #bbb;height:26px;margin-top:6px} .sec{font-weight:800;font-size:16px;margin:24px 0 10px;border-top:1px solid #eee;padding-top:14px}'
    +'@media print{body{margin:0}}'
    +'</style></head><body><h1>'+title+'</h1>'+purpose+body+'</body></html>';
}

// ── Phase 4F: assemble a stand-alone printable RSSI document ────────────────
// Wraps the SAME pure renderer used on screen (Screens.rssiReport, forPrint=true)
// in a self-contained HTML page with an inlined stylesheet subset, so the printed
// PDF matches the on-screen report exactly. No scoring happens here — `rr` is the
// already-saved engine result handed in by App.printRssi().
function buildRssiPrintDoc(rr, saved){
  const e=escapeHtml;
  const title=(state.survey&&state.survey.title)||state.study.name||'Survey';
  const withheld=(rr.score===null);
  const fc=rr.fence||{};
  const headline=withheld
    ? `${e(rr.verdict||'Insufficient data to judge')} · score withheld`
    : `RSSI ${e(Number(rr.score).toFixed(1))} / 100 · ${e(rr.band||rr.verdict||'')}`;
  const nTxt=`${e(String(fc.analyzableN||0))} analyzable of ${e(String(fc.totalN||0))} collected (min ${e(String(fc.minN||30))})`;
  // Minimal stylesheet — the design tokens plus the classes the report markup uses.
  const css=`
    :root{--accent:#0F9E7B;--blue:#0A6FE8;--blue-soft:#EEF3FA;--green:#1f9e44;--green-soft:#e9f7ee;
      --amber:#c47700;--amber-soft:#fdf3e3;--red:#c4271f;--red-soft:#fbeae9;
      --ink:#15171a;--ink-2:#5f6368;--ink-3:#8a8f98;--bg:#f5f6f8;--panel:#fff;
      --line:rgba(15,23,42,0.12);--line-2:rgba(15,23,42,0.06);--r:14px;}
    *{box-sizing:border-box;margin:0;padding:0}
    body{font-family:-apple-system,BlinkMacSystemFont,Inter,system-ui,sans-serif;color:var(--ink);
      background:#fff;padding:28px 32px;font-size:14px;line-height:1.45;max-width:860px;margin:0 auto}
    h1{font-size:21px;font-weight:800;letter-spacing:-.01em}
    h3.sec{font-size:14px;font-weight:700;margin:18px 0 10px}
    .faint{color:var(--ink-3)} .muted{color:var(--ink-2)}
    .doc-head{border-bottom:2px solid var(--ink);padding-bottom:14px;margin-bottom:18px}
    .doc-head .meta{font-size:12.5px;color:var(--ink-2);margin-top:5px}
    .card{background:var(--panel);border:1px solid var(--line);border-radius:var(--r);margin-bottom:14px}
    .card.pad{padding:18px}
    .badge{display:inline-flex;align-items:center;gap:5px;font-size:11px;font-weight:700;padding:3px 9px;border-radius:999px}
    .badge.green{background:var(--green-soft);color:var(--green)} .badge.amber{background:var(--amber-soft);color:var(--amber)}
    .badge.red{background:var(--red-soft);color:var(--red)} .badge.gray,.badge.blue{background:var(--bg);color:var(--ink-2)}
    .pill{display:inline-block;font-size:11.5px;font-weight:600;color:var(--ink-2);background:var(--bg);padding:3px 10px;border-radius:999px;border:1px solid var(--line)}
    .meter{height:8px;border-radius:999px;background:var(--bg);overflow:hidden}
    .meter>span{display:block;height:100%;border-radius:999px;background:var(--accent)}
    .callout{display:flex;gap:12px;padding:14px 16px;border-radius:var(--r);background:var(--blue-soft);border:1px solid rgba(10,111,232,.18);margin-bottom:12px}
    .callout.amber{background:var(--amber-soft);border-color:rgba(196,119,0,.25)}
    .callout.green{background:var(--green-soft);border-color:rgba(31,158,68,.25)}
    .callout .ci{flex-shrink:0;color:var(--blue)} .callout.amber .ci{color:var(--amber)} .callout.green .ci{color:var(--green)}
    .callout h4{font-size:13.5px;font-weight:700} .callout p{font-size:13px;color:var(--ink-2)}
    .tbl{width:100%;border-collapse:collapse;font-size:13px}
    .tbl th{text-align:left;font-size:10.5px;font-weight:700;letter-spacing:.04em;text-transform:uppercase;color:var(--ink-3);padding:9px 14px;border-bottom:1px solid var(--line)}
    .tbl td{padding:10px 14px;border-bottom:1px solid var(--line-2);color:var(--ink-2)}
    .grid{display:grid;gap:12px} .g4{grid-template-columns:repeat(2,1fr)}
    .exp{border:1px solid var(--line);border-radius:10px;margin-bottom:12px;overflow:hidden}
    .exp summary{padding:12px 16px;font-weight:600;font-size:13.5px;color:var(--text);cursor:pointer;list-style:none}
    .exp summary::-webkit-details-marker{display:none}
    .exp[open] summary{border-bottom:1px solid var(--line)}
    .exp .body{padding:14px 16px}
    .faint{color:var(--text-3)}
    svg{vertical-align:middle}
    @media print{body{padding:0;max-width:none}.card,.callout,.exp{break-inside:avoid}}
  `;
  return `<!doctype html><html><head><meta charset="utf-8">
    <title>RSSI report · ${e(title)}</title><style>${css}</style></head>
    <body>
      <div class="doc-head">
        <h1>${e(title)}</h1>
        <div class="meta"><b>ReliCheck Survey Strength Index</b> · ${e(headline)}<br>${nTxt}</div>
      </div>
      ${Screens.rssiReport(rr, saved, true)}
    </body></html>`;
}

// A small, non-interactive preview of how the question will look to a respondent.
function qPreview(q){
  const t=q.type, e=escapeHtml, opts=(q.options&&q.options.length)?q.options:null;
  const list=(arr,kind)=>arr.map(x=>`<label class="pvopt"><input type="${kind}" disabled> ${e(x)}</label>`).join('');
  switch(t){
    case 'Multiple Choice': case 'Single Choice': return list(opts||['Option 1','Option 2'],'radio');
    case 'Checkboxes':      return list(opts||['Option 1','Option 2'],'checkbox');
    case 'Dropdown': case 'Demographic':
      return `<select class="pvsel" disabled>${(opts||['Choose…']).map(x=>`<option>${e(x)}</option>`).join('')}</select>`;
    case 'Yes/No':     return list(['Yes','No'],'radio');
    case 'True/False': return list(['True','False'],'radio');
    case 'Likert Scale': case 'Likert (5-pt)': case 'Likert (7-pt)': {
      const n=(q.settings&&q.settings.points) || (t==='Likert (7-pt)'?7:5);
      return `<div class="pvscale">${Array.from({length:n},(_,i)=>`<span>${i+1}</span>`).join('')}</div>`;
    }
    case 'Rating Scale': case 'Rating': {
      const m=(q.settings&&q.settings.max)||5; return `<div class="pvscale">${'★ '.repeat(m).trim()}</div>`;
    }
    case 'Matrix/Grid': return `<div class="pvmeta">Grid rows: ${(opts||['Row 1','Row 2']).map(e).join(', ')}</div>`;
    case 'NPS':         return `<div class="pvscale">${Array.from({length:11},(_,i)=>`<span>${i}</span>`).join('')}</div>`;
    case 'Short Answer':return `<input class="pvinput" disabled placeholder="Short answer">`;
    case 'Long Answer': case 'Comment Box': return `<textarea class="pvinput" rows="2" disabled placeholder="Long answer"></textarea>`;
    case 'Ranking':     return `<ol class="pvrank">${(opts||['Item 1','Item 2']).map(x=>`<li>${e(x)}</li>`).join('')}</ol>`;
    case 'Slider': {
      const mn=(q.settings&&q.settings.min)??0, mx=(q.settings&&q.settings.max)??100;
      return `<input type="range" class="pvinput" disabled min="${mn}" max="${mx}"> <span class="pvmeta">${mn} to ${mx}</span>`;
    }
    case 'Email':   return `<input class="pvinput" disabled placeholder="name@example.com">`;
    case 'Phone':   return `<input class="pvinput" disabled placeholder="(555) 555-5555">`;
    case 'Date':    return `<input class="pvinput" disabled placeholder="MM / DD / YYYY">`;
    case 'Numeric': return `<input class="pvinput" disabled placeholder="0">`;
    case 'Section Text':      return `<div class="pvmeta" style="font-style:italic">${e(q.t||'Instructions')}</div>`;
    case 'Consent':           return `<label class="pvopt"><input type="checkbox" disabled> ${e((opts&&opts[0])||'I agree')}</label>`;
    case 'Page Break':        return `<div class="pvmeta">Page break</div>`;
    case 'Thank-you Message': return `<div class="pvmeta">${e(q.t||'Thank you')}</div>`;
    default: return `<input class="pvinput" disabled placeholder="Response">`;
  }
}

// Settings rows that flow UNDER the question text inside the composer.
function composerSettings(){
  const c=state.composer, e=escapeHtml;
  if(!c) return '';
  const def=QTYPES[c.type]||{};
  let body='';
  if(!def.structural){
    body+=`<div class="field">
      <label>Response</label>
      <button class="reqtoggle ${c.required?'on':''}" onclick="App.setCRequired(${c.required?0:1})">${c.required?'Required':'Optional'}</button>
    </div>`;
  }
  if(def.editOpts){
    const opts=c.options||[];
    body+=`<div class="field"><label>Answer options</label>
      ${opts.map((op,oi)=>`<div class="opt-row">
        <input value="${e(op)}" oninput="App.setCOption(${oi},this.value)">
        <button class="opt-del" title="Remove" onclick="App.removeCOption(${oi})">&times;</button>
      </div>`).join('')}
      <button class="btn sm" onclick="App.addCOption()">+ Add option</button>
    </div>`;
  }
  if(c.type==='Likert Scale'){
    const pts=(c.settings&&c.settings.points)||5;
    body+=`<div class="field"><label>Scale points</label>
      <select onchange="App.setCSetting('points',this.value)">${[3,4,5,6,7].map(n=>`<option ${n===pts?'selected':''}>${n}</option>`).join('')}</select></div>`;
  }
  if(c.type==='Rating Scale'){
    const mx=(c.settings&&c.settings.max)||5;
    body+=`<div class="field"><label>Highest rating</label>
      <select onchange="App.setCSetting('max',this.value)">${[3,4,5,7,10].map(n=>`<option ${n===mx?'selected':''}>${n}</option>`).join('')}</select></div>`;
  }
  if(c.type==='Slider'){
    const mn=(c.settings&&c.settings.min)??0, mx=(c.settings&&c.settings.max)??100;
    body+=`<div class="field"><label>Range</label>
      <div style="display:flex;gap:8px;align-items:center">
        <input type="number" value="${mn}" oninput="App.setCSetting('min',this.value)" style="width:90px">
        <span class="pvmeta">to</span>
        <input type="number" value="${mx}" oninput="App.setCSetting('max',this.value)" style="width:90px">
      </div></div>`;
  }
  if(!body) return '';
  return `<div class="csettings">${body}</div>`;
}

// Live SDSI heuristics for the question being developed (mock in Phase 1).
// These read the current composer text/type so the feedback tracks the item
// you are writing, not the whole survey.
function composerChecks(c){
  const t=(c.t||'').trim(), words=t?t.split(/\s+/).length:0;
  const dbl=/\b(and|or)\b|\/|,/.test(t) && words>3;          // two asks in one item
  const lng=words>=20;                                       // long / hard to read
  const vag=t!=='' && words>0 && words<4;                    // too thin to interpret
  const checks=[
    { nm:'Single idea (not double-barreled)', ok:!dbl, hint:'Asks one thing at a time.' },
    { nm:'Readable length',                   ok:!lng, hint:'Short enough to read once.' },
    { nm:'Specific enough to interpret',      ok:!vag, hint:'Gives respondents something concrete.' },
  ];
  const rows=checks.map(c=>`<div class="lens">
      <span class="ckdot ${c.ok?'ok':'warn'}"></span>
      <span class="nm">${c.nm}</span>
      <span class="badge ${c.ok?'green':'amber'}">${c.ok?'on track':'check'}</span>
    </div>`).join('');
  return `<div class="composer-checks">
    <div class="eyebrow" style="color:var(--ink-3)">SDSI checks · this question</div>
    ${rows}
    <p class="phase-note">Live heuristics on the question you are writing. Mock in Phase 1; the full SDSI Build Check scores all of them.</p>
  </div>`;
}

// CENTER column: the question block where the user writes the question.
// Question text on top; type, required, options and scale settings flow underneath.
function composerCenter(){
  const c=state.composer, e=escapeHtml;
  if(!c){
    return `<div class="composer-empty" id="composer">
      <h3>Write your first question here</h3>
      <p>Click a question type on the right. It opens here so you can write your question, then save. Saved questions stack below so you always see the whole survey.</p>
    </div>`;
  }
  const editing=state.composerRef!=null;
  const num=editing ? state.composerRef+1 : ((state.survey?state.survey.questions.length:0)+1);
  return `<div class="composer" id="composer">
    <div class="composer-head">
      <div class="eyebrow">${editing?`Editing question ${num}`:`Question ${num}`} · ${typeLabel(c.type)}</div>
      <button class="qclose" title="Cancel" onclick="App.cancelComposer()">&times;</button>
    </div>
    <textarea class="composer-q" placeholder="Type your question here…" oninput="App.setCText(this.value)" onchange="render()">${e(c.t||'')}</textarea>
    ${composerSettings()}
    <div class="composer-prev">
      <div class="lbl">How respondents see it</div>
      ${qPreview(c)}
    </div>
    ${composerChecks(c)}
    ${state.entry==='ai-assist' ? composerAssist() : ''}
    <div class="composer-foot">
      <button class="btn primary" onclick="App.saveComposer()">${editing?'Update question':'Save question'}</button>
      <button class="btn" onclick="App.cancelComposer()">Cancel</button>
    </div>
  </div>`;
}

// ai-assist only: ReliCheck Intelligence helps with the question being written.
// "Improve wording" offers a clearer rewrite to accept; "Check clarity" returns
// notes (bias, double-barreled, reading level) with no rewrite.
function composerAssist(){
  const a=state.composerAI, e=escapeHtml;
  let result='';
  if(a && a.busy){
    result=`<p class="hint" style="margin:10px 0 0">ReliCheck Intelligence is ${a.action==='rewrite'?'rewriting your question':'reading your question for clarity'}…</p>`;
  } else if(a && a.reason){
    result=`<p class="hint" style="margin:10px 0 0">ReliCheck Intelligence is unavailable right now (${e(a.reason)}). Your question is unchanged.</p>`;
  } else if(a && (a.rewrite || a.notes.length)){
    const rw = a.rewrite
      ? `<div class="assist-rewrite">
          <div class="eyebrow" style="color:var(--ink-3)">Suggested rewrite</div>
          <p class="assist-text">${e(a.rewrite)}</p>
          <div class="assist-acts">
            <button class="btn sm primary" onclick="App.useRewrite()">Use this wording</button>
            <button class="btn sm" onclick="App.dismissAssist()">Keep mine</button>
          </div>
        </div>`
      : '';
    const notes = a.notes.length
      ? `<div class="assist-notes"><div class="eyebrow" style="color:var(--ink-3)">${a.rewrite?'What changed':'Clarity notes'}</div>
          <ul>${a.notes.map(n=>`<li>${e(n)}</li>`).join('')}</ul></div>`
      : '';
    result=`<div class="assist-result">${rw}${notes}${a.rewrite?'':`<div class="assist-acts"><button class="btn sm" onclick="App.dismissAssist()">Dismiss</button></div>`}</div>`;
  }
  const busy = a && a.busy;
  return `<div class="composer-assist">
    <div class="eyebrow" style="color:var(--ink-3)">ReliCheck Intelligence assist · this question</div>
    <div class="assist-btns">
      <button class="btn sm" onclick="App.composerAssist('rewrite')" ${busy?'disabled':''}>Improve wording</button>
      <button class="btn sm" onclick="App.composerAssist('clarity')" ${busy?'disabled':''}>Check clarity</button>
    </div>
    ${result}
  </div>`;
}

// RIGHT column: click a type to open it in the center composer.
function typesColumn(){
  if(state.helpMode){
    return `<div class="card pad types-col">
      <div class="composer-head"><div class="eyebrow" style="color:var(--ink-3)">Help Me Choose</div>
        <button class="btn sm" onclick="App.helpToggle()">← Types</button></div>
      <p class="hint" style="margin:0 0 12px">What kind of answer do you need?</p>
      <div class="ptypes">
        ${HELP_OPTIONS.map(h=>`<button class="ptype" onclick="App.startType('${h.type}')">${h.q}</button>`).join('')}
      </div>
    </div>`;
  }
  return `<div class="card pad types-col">
    <div class="eyebrow" style="color:var(--ink-3);margin-bottom:4px">Question types</div>
    <p class="hint" style="margin:0 0 12px">Click a type to open it in the center, then write your question.</p>
    ${QGROUPS.map(g=>`<div class="pgroup"><h4>${g.name}</h4>
      <div class="ptypes">${g.types.map(t=>`<button class="ptype" onclick="App.startType('${t}')">${typeLabel(t)}</button>`).join('')}</div>
    </div>`).join('')}
    <button class="btn sm" onclick="App.helpToggle()">Help Me Choose</button>
    <p class="help-line">Not sure which to use? ReliCheck Intelligence will map it for you.</p>
  </div>`;
}

/* ════════════════════════════════════════════════════════════════════
   SCREENS
   ════════════════════════════════════════════════════════════════════ */
const Screens = {

  start(){
    const card=(mode,cls,ico,h,p)=>`
      <button class="entry-card ${cls}" onclick="App.setEntry('${mode}')">
        <div class="ico">${icon(ico)}</div>
        <h3>${h}</h3><p>${p}</p>
        <span class="go">Begin ${SVG.arrow}</span>
      </button>`;
    return `<div class="screen">
      <div class="eyebrow">New survey</div>
      <h1 class="title">How would you like to begin?</h1>
      <p class="lede">Build, upload, strengthen, publish, deploy and retrieve a survey, then hand clean data to analysis. Pick a starting point; you can change course at any step.</p>
      <div style="margin-bottom:22px">
        <button class="entry-card blue" style="width:100%" onclick="App.openFreshUpload()">
          <div class="ico">${icon(ICONS.import)}</div>
          <h3>Bring In an Existing Survey</h3>
          <p>Upload a CSV or Excel file from Google Forms, SurveyMonkey, or Qualtrics. ReliCheck detects the format automatically.</p>
          <span class="go">Upload file ${SVG.arrow}</span>
        </button>
      </div>
      <div class="eyebrow" style="margin-bottom:10px">Or start a new survey</div>
      <div class="entry">
        ${card('scratch','',ICONS.scratch,'Create Survey from Scratch','A blank workspace with full control over every construct, item, and response option.')}
        ${card('ai-build','blue',ICONS.aiBuild,'Have ReliCheck Intelligence Build My Study','Describe your goal and ReliCheck Intelligence drafts the full study (constructs, items, and scale) ready for your review.')}
        ${card('ai-assist','',ICONS.aiAssist,'Create Survey with ReliCheck Intelligence','Build it yourself with live suggestions for items, wording, and scale choices as you go.')}
        ${card('existing','blue',ICONS.existing,'Work on a Saved ReliCheck Survey','Open a draft or a fielded survey already in ReliCheck to revise, re-check, or re-deploy.')}
      </div>
      <div style="margin-top:18px">
        <button class="entry-card template" style="width:100%" onclick="App.setEntry('template')">
          <div class="ico">${icon(ICONS.template)}</div>
          <h3>Pull from ReliCheck Template Suite</h3>
          <p>Start from a validated, bias-reviewed instrument and adapt it to your population.</p>
          <span class="go">Browse templates ${SVG.arrow}</span>
        </button>
      </div>
    </div>`;
  },

  templates(){
    const list = (PERSIST.on && state.remoteTemplates) ? state.remoteTemplates : MOCK.templates;
    const cards=list.map(t=>{
      const key=t.slug||t.id, doms=(t.domains||[]).length;
      return `
      <div class="card tmpl" onclick="App.chooseTemplate('${key}')">
        <div class="cat">${t.cat}</div>
        <h3>${t.name}</h3>
        <p class="muted" style="font-size:13px">${t.note||''}</p>
        <div class="meta">
          <span class="pill">${t.items} items</span>
          <span class="pill">${t.scale}</span>
          <span class="pill">${doms} domains</span>
        </div>
      </div>`;}).join('');
    return `<div class="screen">
      <div class="eyebrow">Template suite</div>
      <h1 class="title">Start from a validated instrument</h1>
      <p class="lede">Each template ships with a defined construct map and bias-reviewed wording. You can adapt every item after loading.</p>
      <div class="grid g3">${cards}</div>
      <div class="btn-row"><button class="btn" onclick="App.go('start')">← Back to start</button></div>
    </div>`;
  },

  'pick-existing'(){
    const useDb = PERSIST.on && state.remoteProjects;
    const list = useDb ? state.remoteProjects : MOCK.surveys;
    const empty = useDb && list.length===0;
    const rows = empty
      ? `<div class="row"><div class="grow muted" style="padding:8px 0">No saved projects yet. Start a new survey to create one.</div></div>`
      : list.map(s=>{
          const name=s.title||s.name;
          const isUpload = useDb && s.dataset_id!=null && (s.items||0)===0;
          const status = isUpload ? 'Uploaded data' : (s.status||'Draft');
          const b = isUpload?'green':(status==='Collecting'||status==='active')?'green':(status==='Closed'||status==='archived')?'gray':'amber';
          const sub = isUpload
            ? `Uploaded response data${s.response_count?` · ${s.response_count} rows`:''}`
            : (useDb
                ? `${s.items} items · ${s.mode||''}`.trim()
                : `${s.items} items · ${s.responses} responses · updated ${s.updated}`);
          return `<div class="row">
            <div class="grow"><h4>${name}</h4>
              <div class="sub">${sub}</div></div>
            <span class="badge ${b}">${status}</span>
            ${s.siri!=null?`<span class="pill">SIRI ${s.siri}</span>`:''}
            <button class="btn sm" onclick="App.openExisting(${s.id})">Open ${SVG.arrow}</button>
            ${useDb?`<button class="btn sm" title="Remove this project from your list" style="color:var(--red)" onclick="App.deleteProjectFromList(${s.id})">Delete</button>`:''}
          </div>`;}).join('');
    return `<div class="screen">
      <div class="eyebrow">Existing surveys</div>
      <h1 class="title">Pick a survey to work on</h1>
      <p class="lede">Open a draft to keep building, or a fielded survey to revise, re-check, or re-deploy.</p>
      <div class="card">${rows}</div>
      <div class="btn-row"><button class="btn" onclick="App.go('start')">← Back to start</button></div>
    </div>`;
  },

  import(){
    const e=escapeHtml;
    const mode=state.importMode||'paste';
    const tab=(key,label)=>`<button class="chip" data-on="${mode===key?1:0}" onclick="App.setImportMode('${key}')">${label}</button>`;
    const placeholder = mode==='manual'
      ? 'One item per line, for example:\nI feel supported by my manager.\nHow satisfied are you with onboarding?\nWould you recommend us to a friend?'
      : 'Paste your full survey. Questions and their answer options are detected automatically, for example:\n\n1. How satisfied are you with onboarding?\n  a) Very satisfied\n  b) Satisfied\n  c) Dissatisfied\n\n2. I feel supported by my manager. (Strongly agree to strongly disagree)\n\n3. What would you improve? (open response)';
    const textBlock=`<div class="field"><label>${mode==='manual'?'Item list (one per line)':'Survey text'}</label>
        <textarea rows="12" placeholder="${e(placeholder)}" oninput="App.setImportField('importText',this.value)">${e(state.importText||'')}</textarea></div>`;
    return `<div class="screen">
      <div class="eyebrow">Bring in an existing survey</div>
      <h1 class="title">Bring your survey into ReliCheck</h1>
      <p class="lede">Paste or import a survey you already have so ReliCheck can assess its structure, constructs, items, and build readiness. Everything you bring in is fully editable in the workspace.</p>
      <div class="card pad" style="max-width:760px">
        <div class="field"><label>Project title</label>
          <input value="${e(state.study.name||'')}" placeholder="e.g. 2026 Onboarding Experience Survey" oninput="App.setStudy('name',this.value)"></div>
        <div class="field"><label>Survey purpose / intended use</label>
          <textarea rows="2" placeholder="What decision will this survey inform?" oninput="App.setStudy('purpose',this.value)">${e(state.study.purpose||'')}</textarea></div>
        <div class="field"><label>Target audience</label>
          <input value="${e(state.study.population||'')}" placeholder="e.g. New hires in their first 90 days" oninput="App.setStudy('population',this.value)"></div>

        <div class="field"><label>How would you like to bring it in?</label>
          <div class="chips">${tab('paste','Paste survey text')}${tab('manual','Manual quick import')}</div></div>
        ${textBlock}

        <div class="field"><label>Constructs <span class="muted" style="font-weight:500">(optional)</span></label>
          <textarea rows="3" placeholder="One construct per line. Add a definition after a colon, for example:\nBelonging: feeling accepted and valued by the team\nManager Support" oninput="App.setImportField('importConstructs',this.value)">${e(state.importConstructs||'')}</textarea></div>
        <div class="field"><label>Response scale <span class="muted" style="font-weight:500">(optional)</span></label>
          <input value="${e(state.importScale||'')}" placeholder="e.g. 5-pt agreement (Strongly disagree to strongly agree)" oninput="App.setImportField('importScale',this.value)"></div>
      </div>
      <div class="btn-row">
        <button class="btn" onclick="App.go('start')">← Back</button>
        <div class="spacer"></div>
        <button class="btn primary lg" onclick="App.importSurvey()">Bring into ReliCheck ${SVG.arrow}</button>
      </div>
    </div>`;
  },

  setup(){
    const aiNote = state.entry==='ai-build'
      ? `<div class="callout" style="margin-bottom:24px"><div class="ci">${SVG.info}</div>
          <div><h4>ReliCheck Intelligence will draft your study from this</h4><p>The more specific your purpose and population, the better the generated constructs and items.</p></div></div>` : '';
    return `<div class="screen">
      <div class="eyebrow">Step 1 · Study setup</div>
      <h1 class="title">Define the study</h1>
      <p class="lede">A few essentials now keep every later step (design review, deployment, analysis) aligned to the same goal.</p>
      ${aiNote}
      <div class="card pad" style="max-width:720px">
        <div class="field"><label>Study name</label>
          <input value="${state.study.name||''}" placeholder="e.g. Spring Employee Engagement Pulse" oninput="App.setStudy('name',this.value)"></div>
        <div class="field"><label>Purpose / research question</label>
          <textarea placeholder="What decision will this survey inform?" oninput="App.setStudy('purpose',this.value)">${state.study.purpose||''}</textarea></div>
        <div class="field"><label>Target population</label>
          <input value="${state.study.population||''}" placeholder="e.g. Full-time staff, all departments" oninput="App.setStudy('population',this.value)"></div>
      </div>
      <div class="btn-row">
        <button class="btn" onclick="App.go('start')">← Back</button>
        <div class="spacer"></div>
        ${state.entry==='ai-build'
          ? `<button class="btn primary lg" onclick="App.generate()" ${state.aiBusy?'disabled':''}>${state.aiBusy?'ReliCheck Intelligence is drafting…':`Generate study ${SVG.arrow}`}</button>`
          : `<button class="btn primary lg" onclick="App.startBuild()">Open workspace ${SVG.arrow}</button>`}
      </div>
    </div>`;
  },

  build(){
    const s=state.survey||{title:'Untitled',questions:[]};
    const e=escapeHtml;
    const editable = PERSIST_REQUESTED && state.projectId;
    const n=s.questions.length;
    const cs=(s.constructs)||[];
    const isStruct=(t)=>!!(QTYPES[t]&&QTYPES[t].structural);
    const consOpts=(sel)=>['<option value="">— unmapped —</option>'].concat(cs.filter(c=>c.name).map(c=>`<option ${c.name===(sel||'')?'selected':''}>${e(c.name)}</option>`)).join('');
    // Per-item construct selector (Phase 2E). Lets the user map any item without
    // the composer or console. Structural items (consent, page break, etc.) are skipped.
    const consSelect=(q,i)=>{
      if(isStruct(q.type)) return '';
      const unmapped = cs.filter(c=>c.name).length>0 && !((q.construct||'').trim());
      return `<div class="qcard-construct" onclick="event.stopPropagation()" style="display:flex;gap:8px;align-items:center;margin-top:8px;flex-wrap:wrap">
          <label class="faint" style="font-size:11px;letter-spacing:.04em;text-transform:uppercase">Construct</label>
          ${cs.filter(c=>c.name).length
            ? `<select onchange="App.setItemConstruct(${i},this.value)">${consOpts(q.construct||'')}</select>${unmapped?' <span class="badge amber" style="padding:1px 7px">unmapped</span>':''}`
            : `<span class="faint" style="font-size:11.5px">No constructs yet. Add them in the Constructs panel above.</span>`}
        </div>`;
    };
    const savedCards = n
      ? s.questions.map((q,i)=>{
          const active = state.composerRef===i;
          return `<div class="qcard ${active?'active':''}" id="qcard-${i}" onclick="App.editSaved(${i})">
            <div class="qn">${i+1}</div>
            <div class="qcard-body">
              <div class="qcard-top">
                <span class="qcard-text">${e(q.t||'Untitled question')}</span>
                <span class="badge gray qbadge">${typeLabel(q.type)}</span>
              </div>
              <div class="qprev">${qPreview(q)}</div>
              ${consSelect(q,i)}
            </div>
            <div class="qcard-actions" onclick="event.stopPropagation()">
              <button class="reqtoggle ${q.required?'on':''}" title="Toggle required" onclick="App.setRequired(${i},${q.required?0:1})">${q.required?'Required':'Optional'}</button>
              <div class="qbtns">
                <button class="btn sm" title="Edit"      onclick="App.editSaved(${i})">✎</button>
                <button class="btn sm" title="Move up"   onclick="App.moveItem(${i},-1)" ${i===0?'disabled':''}>↑</button>
                <button class="btn sm" title="Move down" onclick="App.moveItem(${i},1)" ${i===n-1?'disabled':''}>↓</button>
                <button class="btn sm" title="Duplicate" onclick="App.duplicateItem(${i})">⧉</button>
                <button class="btn sm" title="Delete"    onclick="App.deleteItem(${i})">✕</button>
              </div>
            </div>
          </div>`;
        }).join('')
      : '';
    const srcLabel={'ai-build':'ReliCheck Intelligence','ai-assist':'ReliCheck Intelligence assisted','scratch':'From scratch','existing':'Existing survey','import':'Brought-in survey','template':'From template'}[state.entry]||'Draft';
    return `<div class="screen">
      <div class="eyebrow">Step 2 · Build</div>
      <h1 class="title">${e(s.title||'Survey workspace')}</h1>
      <p class="lede">Write one question at a time. It drops into your survey below as you save, so you always see the whole picture. <span class="badge gray">${srcLabel}</span>${editable?` <span class="badge green">saved · #${state.projectId}</span>`:` <span class="badge amber">not saved</span>`}</p>
      <div class="composer-grid">
        <div class="build-main">
          ${composerCenter()}
          <div class="card pad" id="build-constructs" style="margin-bottom:16px">
            <div class="saved-head" style="margin-bottom:6px">
              <h2 class="sec" style="margin:0">Constructs</h2>
              <div class="spacer"></div>
              <button class="btn sm" onclick="App.addConstruct()">+ Add construct</button>
            </div>
            <p class="muted" style="font-size:13px;margin:0 0 10px">Name what your survey measures, then map each question to a construct using the selector on its card below.</p>
            ${cs.length
              ? cs.map((c,ci)=>`<div style="display:flex;gap:8px;margin-bottom:8px;align-items:center;flex-wrap:wrap">
                  <input style="flex:0 1 200px" value="${e(c.name||'')}" placeholder="Construct name" oninput="App.setConstructField(${ci},'name',this.value)">
                  <input style="flex:1 1 260px" value="${e(c.definition||'')}" placeholder="Definition (optional)" oninput="App.setConstructField(${ci},'definition',this.value)">
                  <button class="btn sm" title="Remove" onclick="App.removeConstruct(${ci})">✕</button>
                </div>`).join('')
              : `<p class="muted" style="font-size:13px;margin:0">No constructs defined yet. Add at least one so each item can be mapped to what it measures.</p>`}
          </div>
          <div class="saved-head">
            <h2 class="sec" style="margin:0">${n} question${n===1?'':'s'} in your survey</h2>
            <div class="spacer"></div>
            <button class="btn sm primary" onclick="App.newComposer()">+ New question</button>
            ${editable?`<button class="btn sm" onclick="App.duplicateProject()">Duplicate survey</button>
              <button class="btn sm" onclick="App.archiveProject()">Archive</button>`:''}
          </div>
          ${ n ? savedCards : `<div class="callout"><div class="ci">${SVG.info}</div><div><h4>No questions yet</h4>
              <p>Pick a type on the right, write your question in the center, and save. It appears here. ${state.entry==='ai-assist'?'ReliCheck Intelligence suggestions will appear as you type.':''}</p></div></div>` }
          <div class="btn-row" style="margin-top:20px">
            <button class="btn" onclick="App.go('setup')">← Setup</button>
            <div class="spacer"></div>
            <button class="btn primary lg" onclick="App.runSdsi()">Run SDSI Build Check ${SVG.arrow}</button>
          </div>
        </div>
        ${typesColumn()}
      </div>
    </div>`;
  },

  sdsi(){
    // No real Build Check yet → show a run prompt, NEVER a sample score. SDSI must
    // always reflect the actual survey (built or uploaded), the same way SIRI does.
    if(!state.sdsiResult){
      const hasItems = !!(state.survey && (state.survey.questions||[]).length);
      return `<div class="screen">
        <div class="eyebrow">Step 3 · SDSI · The Build Check</div>
        <h1 class="title">SDSI: the Build Check</h1>
        <p class="lede"><b>SDSI helps you improve the survey while you are building it.</b> It looks at the parts (your questions, scales, and flow) so you can catch weak spots and fix them before anyone takes it.</p>
        <div class="callout" style="margin-bottom:18px"><div class="ci">${SVG.info}</div><div><p style="margin:0">SDSI looks at the parts while you are building. SIRI checks the whole survey before you launch.</p></div></div>
        <div class="card pad" style="text-align:center;margin-bottom:22px">
          <h2 class="sec" style="margin-top:0">Run the Build Check</h2>
          <p class="muted" style="font-size:14px;max-width:540px;margin:0 auto 16px">${hasItems
            ? 'SDSI reviews every question for validity and scores each item across five 10-point domains. A serious item-level flaw caps that question’s score, so one bad question cannot hide behind four good domains. Your survey SDSI is the average of the final item scores, out of 50.'
            : 'Add or import your questions in Build first, then run the Build Check here.'}</p>
          ${hasItems
            ? `<button class="btn primary lg" onclick="App.runSdsi()">Run SDSI Build Check ${SVG.arrow}</button>`
            : `<button class="btn primary lg" onclick="App.go('build')">Go to Build ${SVG.arrow}</button>`}
        </div>
        <div class="btn-row"><button class="btn" onclick="App.go('build')">← Build</button></div>
      </div>`;
    }
    const sd=state.sdsiResult;
    // Normalise: the real engine returns `categories`; the sample fallback uses `lenses`.
    const cats=(sd.categories||sd.lenses||[]).map(c=>({
      nm:c.name||c.nm, pt:(c.points!=null?c.points:c.pt), max:(c.weight!=null?c.weight:c.max), warn:!!c.warn
    }));
    const lenses=cats.map(l=>{
      const pct=l.max?Math.round(l.pt/l.max*100):0;
      return `<div class="dom">
        <div class="dom-head"><span class="nm">${escapeHtml(l.nm)}</span>
          <span class="pts">${l.pt.toFixed(1)}<small>/${l.max}</small></span></div>
        <div class="meter"><span style="width:${pct}%;background:var(--blue)"></span></div>
        <div style="margin-top:10px"><span class="badge ${l.warn?'amber':'green'}">${l.warn?'caution':'on track'}</span></div>
      </div>`;
    }).join('');
    // Build the "what to strengthen" list from the engine flags (moderate+),
    // or from the sample's pre-built flags when the engine is unavailable.
    const catName={};
    (window.BuildCheck&&window.BuildCheck.CATEGORIES||[]).forEach(c=>{catName[c.key]=c.name;});
    let flagItems;
    if(sd.flags && sd.flags.length && sd.flags[0].category){
      flagItems=sd.flags.filter(f=>f.severity==='moderate'||f.severity==='major'||f.severity==='critical')
        .map(f=>({ sev:'warn', lens:catName[f.category]||f.category,
          msg:f.message+(f.suggestion?(' '+f.suggestion):'') }));
    } else {
      flagItems=(sd.flags||[]).map(f=>({ sev:f.sev, lens:f.lens, msg:f.msg }));
    }
    const flags=flagItems.length
      ? flagItems.map(f=>`<div class="callout ${f.sev==='warn'?'amber':''}" style="margin-bottom:10px">
          <div class="ci">${SVG.info}</div><div><h4>${escapeHtml(f.lens)}</h4><p>${escapeHtml(f.msg)}</p></div></div>`).join('')
      : `<div class="callout"><div class="ci">${SVG.info}</div><div><h4>No issues flagged</h4><p>Nothing needs strengthening right now. Keep building, or run the Build Check again after edits.</p></div></div>`;
    const strengths=(sd.strengths||[]).length
      ? `<h2 class="sec">Strengths</h2>`+(sd.strengths.map(s=>`<div class="callout"><div class="ci">${SVG.info}</div><div><p style="margin:0">${escapeHtml(s)}</p></div></div>`).join(''))
      : '';
    const bandKey=sd.bandKey||'';
    const bandClass=(bandKey==='weak'||bandKey==='notready')?'red':((bandKey==='caution')?'amber':'green');
    const cautionN=cats.filter(c=>c.warn).length;
    return `<div class="screen">
      <div class="eyebrow">Step 3 · SDSI · The Build Check</div>
      <h1 class="title">SDSI: the Build Check</h1>
      <p class="lede"><b>SDSI helps you improve the survey while you are building it.</b> It looks at the parts (your questions, scales, and flow) so you can catch weak spots and fix them before anyone takes it. Run it as often as you like as the survey takes shape.</p>
      <div class="callout" style="margin-bottom:18px"><div class="ci">${SVG.info}</div><div><p style="margin:0">SDSI looks at the parts while you are building. SIRI checks the whole survey before you launch.</p></div></div>
      ${state.sdsiStale?`<div class="callout amber" style="margin-bottom:18px"><div class="ci">${SVG.info}</div><div><h4>Survey changed</h4><p style="margin:0">Re-run SDSI to update your Build Check. The score below is from before your latest edit.</p></div>
        <div style="margin-left:auto"><button class="btn sm primary" onclick="App.runSdsi()">Re-run SDSI</button></div></div>`:''}
      <div class="card pad" style="margin-bottom:22px">
        <div class="ring-wrap">
          ${ring(sd.pct,'var(--blue)', Number(sd.total).toFixed(1), 'of 50')}
          <div>
            <div class="badge ${bandClass}">${escapeHtml(sd.band)}</div>
            <h2 style="font-size:22px;font-weight:800;letter-spacing:-0.02em;margin:8px 0 4px">SDSI ${Number(sd.total).toFixed(1)} / 50</h2>
            <p class="muted" style="font-size:14px">Item validity across ${cats.length} domains · average of ${sd.scoredItems!=null?sd.scoredItems:cats.length} question${sd.scoredItems===1?'':'s'}.</p>
          </div>
        </div>
      </div>
      ${sd.deployment_blocker_count>0?`<div class="callout red" style="margin-bottom:18px"><div class="ci">${SVG.info}</div>
        <div><h4>${escapeHtml(sd.blocker_headline||(sd.deployment_blocker_count+' questions are not deployment-ready.'))}</h4>
          <p style="margin:0">${escapeHtml(sd.sdsi_band_cap_reason||'Critical item-level problems were detected.')} The numeric SDSI score reflects the average quality of all questions; the readiness band is capped because one or more questions contain critical issues that should be fixed before deployment.</p></div></div>`:''}
      <div class="grid g3">${lenses}</div>
      ${strengths}
      <h2 class="sec">What to strengthen</h2>
      ${flags}
      <details class="exp"><summary>Show design-scoring methodology <span class="faint">▾</span></summary>
        <div class="body">SDSI (the Survey Design Strength Index) reviews how the survey is built, before anyone takes it, by scoring every question for validity. Each question is scored across five 10-point domains (Completeness; Clarity; Neutrality and Single Construct; Response Quality; and Dignity, Privacy and Analysis Readiness). The domain scores measure quality. On top of that, a serious item-level flaw caps the question’s score (for example, missing or placeholder options cap it low, a double-barreled or leading question caps it in the mid 30s), and several serious flaws on one question lower it further, so a fundamentally weak question cannot score well just because only one domain was affected. Your survey SDSI is the average of these final question scores, out of 50. SDSI stays at the question level: whole-survey readiness (purpose, coverage, flow) is the SIRI Launch Check, and result reliability is RSSI, after responses come in.</div></details>
      <div class="btn-row">
        <button class="btn" onclick="App.go('build')">← Workspace</button>
        <div class="spacer"></div>
        <button class="btn primary lg" onclick="App.go('revise')">Revise flagged items ${SVG.arrow}</button>
      </div>
    </div>`;
  },

  launch(){
    const e=escapeHtml;
    const lr=(state.study.launchReadiness)||{};
    const c=lr.consent||{}, ins=lr.instructions||{}, ac=lr.access||{}, fl=lr.fielding||{}, dg=lr.dignity||{}, se=lr.sensitive||{};
    // Attestation toggle: passes a real boolean so setLaunch re-renders the pill.
    const tog=(on,path,onLbl,offLbl)=>`<button class="reqtoggle ${on?'on':''}" onclick="App.setLaunch('${path}',${on?'false':'true'})">${on?('✓ '+onLbl):offLbl}</button>`;
    return `<div class="screen">
      <div class="eyebrow">Step 6 · Launch Readiness</div>
      <h1 class="title">Launch readiness</h1>
      <p class="lede">Document the launch details SIRI cannot read from the questions alone. These feed the <b>Launch Check (SIRI)</b> only; they do not change your Build Check.</p>

      <div class="card pad" style="margin-bottom:16px" id="lr-consent">
        <h2 class="sec" style="margin-top:0">Consent &amp; privacy <span class="badge red" style="padding:1px 8px;margin-left:6px">required</span></h2>
        <p class="muted" style="font-size:13px;margin:0 0 10px">Required before launch readiness can be granted.</p>
        <div class="field"><label>Consent / privacy statement</label>
          <textarea rows="4" placeholder="What you collect, why, how it is stored, and that participation is voluntary." oninput="App.setLaunch('consent.statement',this.value)">${e(c.statement||'')}</textarea></div>
        <div class="field"><label>Attestation</label> ${tog(c.documented===true,'consent.documented','Documented','Mark as documented')}</div>
      </div>

      <div class="card pad" style="margin-bottom:16px" id="lr-instructions">
        <h2 class="sec" style="margin-top:0">Respondent instructions</h2>
        <div class="field"><label>Instructions shown to respondents</label>
          <textarea rows="3" placeholder="How to answer, how long it takes, how to skip a question." oninput="App.setLaunch('instructions.text',this.value)">${e(ins.text||'')}</textarea></div>
        <div class="field">${tog(ins.provided===true,'instructions.provided','Provided','Mark as provided')}</div>
      </div>

      <div class="card pad" style="margin-bottom:16px" id="lr-access">
        <h2 class="sec" style="margin-top:0">Access &amp; accommodations</h2>
        <div class="field"><label>Languages offered</label>
          <input value="${e(ac.languages||'')}" placeholder="e.g. English, Spanish" oninput="App.setLaunch('access.languages',this.value)"></div>
        <div class="field"><label>Accommodation contact</label>
          <input value="${e(ac.accommodationContact||'')}" placeholder="Who to contact for accommodations" oninput="App.setLaunch('access.accommodationContact',this.value)"></div>
        <div class="field" style="display:flex;gap:8px;flex-wrap:wrap">
          ${tog(ac.plainLanguageAlt===true,'access.plainLanguageAlt','Plain-language version available','No plain-language version')}
          ${tog(ac.reviewed===true,'access.reviewed','Accessibility reviewed','Mark accessibility reviewed')}</div>
      </div>

      <div class="card pad" style="margin-bottom:16px" id="lr-fielding">
        <h2 class="sec" style="margin-top:0">Fielding &amp; timing</h2>
        <div class="field"><label>Fielding window / dates</label>
          <input value="${e(fl.window||'')}" placeholder="e.g. Jun 1 to Jun 14, 2026" oninput="App.setLaunch('fielding.window',this.value)"></div>
        <div class="field"><label>Distribution channel</label>
          <input value="${e(fl.channel||'')}" placeholder="e.g. Email link, QR in-person" oninput="App.setLaunch('fielding.channel',this.value)"></div>
        <div class="field"><label>Estimated completion (minutes)</label>
          <input type="number" min="1" value="${e(fl.estMinutes||'')}" oninput="App.setLaunch('fielding.estMinutes',this.value)"></div>
      </div>

      <div class="card pad" style="margin-bottom:16px" id="lr-dignity">
        <h2 class="sec" style="margin-top:0">Dignity &amp; framing</h2>
        <div class="field"><label>Framing notes (optional)</label>
          <textarea rows="2" placeholder="How wording was reviewed for respect and neutrality." oninput="App.setLaunch('dignity.notes',this.value)">${e(dg.notes||'')}</textarea></div>
        <div class="field">${tog(dg.reviewed===true,'dignity.reviewed','Dignity reviewed','Mark dignity reviewed')}</div>
      </div>

      <div class="card pad" style="margin-bottom:16px" id="lr-sensitive">
        <h2 class="sec" style="margin-top:0">Sensitive topics &amp; decline path</h2>
        <div class="field" style="display:flex;gap:8px;flex-wrap:wrap">
          ${tog(se.hasSensitive===true,'sensitive.hasSensitive','Touches sensitive topics','No sensitive topics')}
          ${tog(se.declineProvided===true,'sensitive.declineProvided','Decline path provided','No decline path')}</div>
        <p class="muted" style="font-size:13px;margin:6px 0 0">If a survey touches sensitive topics, SIRI holds launch until a decline path (for example a "Prefer not to answer" option) is provided.</p>
        <div class="field"><label>Notes (optional)</label>
          <textarea rows="2" oninput="App.setLaunch('sensitive.notes',this.value)">${e(se.notes||'')}</textarea></div>
      </div>

      <div class="btn-row">
        <button class="btn" onclick="App.go('preview')">← Preview</button>
        <div class="spacer"></div>
        <button class="btn primary lg" onclick="App.runSiri()">Run SIRI Launch Check ${SVG.arrow}</button>
      </div>
    </div>`;
  },

  siri(){
    const e=escapeHtml;
    const r=state.siriResult;
    const head=`<div class="eyebrow">Step 7 · SIRI · The Launch Check</div>
      <h1 class="title">SIRI: the Launch Check</h1>
      <p class="lede"><b>SIRI checks the whole finished survey before you send it out.</b> It scores the survey out of 50 across five whole-survey areas, then combines with your Build Check (SDSI) for a Total Survey Strength out of 100. SIRI does not measure reliability; that comes later, from responses (RSSI).</p>`;
    // Empty state: SIRI has not been run for this survey yet. Show no fake score.
    if(!r){
      return `<div class="screen">${head}
        <div class="callout amber" style="margin-bottom:18px"><div class="ci">${SVG.info}</div><div><p style="margin:0">The Launch Check has not run yet for this survey. It scores the finished instrument out of 50 across five whole-survey areas: Survey Purpose &amp; Alignment, Construct Coverage, Structure &amp; Flow, Scale &amp; Measurement Design, and Deployment Readiness.</p></div></div>
        <div class="card pad" style="text-align:center;margin-bottom:22px">
          <h2 class="sec" style="margin-top:0">Run the Launch Check</h2>
          <p class="muted" style="font-size:14px;max-width:520px;margin:0 auto 16px">SIRI reads the survey exactly as you have built it. Run the Build Check (SDSI) first to see your full Total Survey Strength out of 100. Launch information that is not documented yet, such as a consent notice, lowers the score until you add it.</p>
          <button class="btn primary lg" onclick="App.runSiri()">Run SIRI Launch Check ${SVG.arrow}</button>
        </div>
        <div class="btn-row"><button class="btn" onclick="App.go('launch')">← Launch Readiness</button></div>
      </div>`;
    }
    const hasTotal=(r.total!=null);
    const siriPct=Math.round(r.pct);
    // Whole-survey domain bars (each /10).
    const doms=(r.domains||[]).map(d=>{
      const dpct=Math.round((d.points/d.max)*100);
      const badge = d.points>=10 ? ' <span class="badge green" style="padding:1px 7px">strong</span>'
                  : (d.points>=6 ? ' <span class="badge amber" style="padding:1px 7px">review</span>'
                  : ' <span class="badge red" style="padding:1px 7px">weak</span>');
      return `<div class="dom">
        <div class="dom-head"><span class="nm">${e(d.name)}${badge}</span>
          <span class="pts">${d.points.toFixed(1)}<small>/${d.max}</small></span></div>
        <div class="meter"><span style="width:${dpct}%;background:var(--accent)"></span></div></div>`;
    }).join('');

    // Findings (whole-survey), ordered by severity. Each routes to the relevant step.
    const SEV_RANK={critical:0,high:1,medium:2,low:3};
    const SEV_LABEL={critical:'Critical',high:'High',medium:'Medium',low:'Low'};
    const SEV_CLS={critical:'red',high:'red',medium:'amber',low:''};
    const flags=(r.flags||[]).slice().sort((a,b)=>(SEV_RANK[a.severity]-SEV_RANK[b.severity]));
    const critCount=flags.filter(f=>f.severity==='critical').length;
    const fixArg=(key)=>{ const fx=App._fixFor(key); let focus=fx.focus||'';
      return { route:fx.route, label:fx.label, arg: focus?`'${fx.route}','${focus}'`:`'${fx.route}'` }; };
    const flagRows = flags.length
      ? flags.map(f=>{ const fx=fixArg(f.domainKey);
          return `<div class="callout ${SEV_CLS[f.severity]||''}" style="margin-bottom:10px"><div class="ci">${SVG.info}</div>
            <div style="flex:1"><h4 style="margin:0 0 2px">${e(f.message)} <span class="faint" style="font-weight:500">· ${e(f.domain)} · ${SEV_LABEL[f.severity]||f.severity}</span></h4>
              ${f.suggestion?`<p style="margin:0 0 8px;font-size:14px">${e(f.suggestion)}</p>`:''}
              <button class="btn sm" onclick="App.go(${fx.arg})">${e(fx.label)} ${SVG.arrow}</button></div></div>`;
        }).join('')
      : `<div class="callout green"><div class="ci">${SVG.check}</div><div><p style="margin:0">No issues found across the five whole-survey areas.</p></div></div>`;

    // Total Survey Strength hero (SDSI + SIRI = 100) when SDSI is available; else SIRI-only.
    const bandKeyCls={strong:'green',good:'green',caution:'amber',weak:'red',notready:'red'};
    const totalCls = hasTotal ? (bandKeyCls[r.total_band_key]||'amber') : 'amber';
    const capCallout = (hasTotal && r.total_band_was_capped)
      ? `<div class="callout red" style="margin-bottom:18px"><div class="ci">${SVG.info}</div><div><h4 style="margin:0 0 2px">Readiness capped at &ldquo;${e(r.total_band)}&rdquo;</h4><p style="margin:0;font-size:14px">${e(r.total_band_cap_reason)}</p></div></div>`
      : '';
    const hero = hasTotal
      ? `<div class="card pad" style="margin-bottom:18px">
          <div class="ring-wrap">
            ${ring(Math.round(r.total),'var(--accent)', r.total.toFixed(1), 'of 100')}
            <div>
              <div class="badge ${totalCls}">${e(r.total_band)}${r.total_band_was_capped?' · capped':''}</div>
              <h2 style="font-size:22px;font-weight:800;letter-spacing:-0.02em;margin:8px 0 4px">Total Survey Strength ${r.total.toFixed(1)} / 100</h2>
              <p class="muted" style="font-size:14px">SDSI ${r.sdsi.toFixed(1)} / 50 (questions) + SIRI ${r.siri.toFixed(1)} / 50 (whole survey).${r.deployment_blocker_count?(' '+r.deployment_blocker_count+' deployment blocker'+(r.deployment_blocker_count===1?'':'s')+'.'):''}</p>
            </div>
          </div>
        </div>`
      : `<div class="card pad" style="margin-bottom:18px">
          <div class="ring-wrap">
            ${ring(siriPct,'var(--accent)', r.siri.toFixed(1), 'of 50')}
            <div>
              <div class="badge amber">SIRI only</div>
              <h2 style="font-size:22px;font-weight:800;letter-spacing:-0.02em;margin:8px 0 4px">SIRI ${r.siri.toFixed(1)} / 50</h2>
              <p class="muted" style="font-size:14px">Run the Build Check (SDSI) to see your Total Survey Strength out of 100. <button class="btn sm" onclick="App.go('sdsi')">Open Build Check ${SVG.arrow}</button></p>
            </div>
          </div>
        </div>`;
    const stale=state.siriStale ? `<div class="callout amber" style="margin-bottom:14px"><div class="ci">${SVG.info}</div><div style="flex:1"><h4 style="margin:0 0 2px">This Launch Check is out of date</h4><p style="margin:0 0 8px">You changed the survey since SIRI last ran. Re-run it to see the current readiness. <button class="btn sm" onclick="App.runSiri()">Re-run SIRI ${SVG.arrow}</button></p></div></div>` : '';
    return `<div class="screen">${head}
      ${stale}
      ${capCallout}
      ${hero}
      <h2 class="sec" style="margin-top:6px">Findings <span class="badge ${flags.length?(critCount?'red':'amber'):'green'}">${flags.length}</span></h2>
      <p class="muted" style="font-size:13.5px;margin:0 0 12px">SIRI looks at the whole survey, not individual questions. Critical findings cap your readiness band until they are resolved; the number itself does not change.</p>
      ${flagRows}
      <div class="card pad" style="margin-top:18px"><h2 class="sec" style="margin-top:0">Whole-survey readiness domains <span class="faint" style="font-weight:500">· SIRI ${r.siri.toFixed(1)} / 50</span></h2><div class="grid">${doms}</div></div>
      <details class="exp"><summary>Show readiness-scoring methodology <span class="faint">▾</span></summary>
        <div class="body">SIRI (the Survey Instrument Readiness Index) scores the whole finished survey out of 50 across five areas: Survey Purpose &amp; Alignment, Construct Coverage, Survey Structure &amp; Flow, Scale &amp; Measurement Design, and Deployment Readiness &amp; Evidence Use. Each area starts at 10 points; findings subtract by severity (Critical -10, High -6, Medium -3, Low -1) and never drop below 0. SIRI does not measure reliability; that is RSSI, computed after responses are collected. Your SIRI combines with the Build Check (SDSI, the question-level score out of 50) for a Total Survey Strength out of 100. When critical deployment blockers are present, the readiness band is capped even though the number is unchanged.</div></details>
      <div class="btn-row">
        <button class="btn" onclick="App.go('launch')">← Launch Readiness</button>
        <div class="spacer"></div>
        <button class="btn" onclick="App.runSiri()">Re-run SIRI</button>
        <button class="btn primary lg" onclick="App.go('publish')">Continue to publish ${SVG.arrow}</button>
      </div>
    </div>`;
  },

  revise(){
    const e=escapeHtml;
    const {active, later, kept}=App._reviseCards();
    const qs=(state.survey&&state.survey.questions)||[];
    const answerable=qs.filter(q=>!(QTYPES[q.type]&&QTYPES[q.type].structural));
    const meaningful=answerable.filter(q=>!App._isMetaColumn(q));
    if(!answerable.length){
      return `<div class="screen"><div class="eyebrow">Step 4 · Revise</div><h1 class="title">Strengthen your questions</h1>
        <div class="callout amber"><div class="ci">${SVG.info}</div><div><h4>No questions yet</h4><p style="margin:0">Add or import your questions in Build first, then come back to strengthen them.</p></div>
          <div style="margin-left:auto"><button class="btn sm primary" onclick="App.go('build')">Go to Build ${SVG.arrow}</button></div></div></div>`;
    }
    // Live SDSI for the score ring (fresh assess matches the live concern list).
    const sd=(window.BuildCheck&&window.BuildCheck.assess)?window.BuildCheck.assess(App._buildCheckProject()):state.sdsiResult;
    const total=sd&&sd.total!=null?sd.total:null;
    const band=sd?(sd.band||''):'';
    const bandCls=/strong|good/i.test(band)?'green':(/caution/i.test(band)?'amber':'red');

    const SEVRANK={critical:0,major:1,high:1,moderate:2,medium:2,minor:3,low:3};
    const oneConcern=(c)=>{ const l=(c.concerns||[]).slice().sort((a,b)=>((SEVRANK[a.sev]??9)-(SEVRANK[b.sev]??9))); return (l[0]&&l[0].msg)||'This question can be stronger.'; };
    const byIndex={};
    active.forEach(c=>byIndex[c.index]={kind:'active',c});
    later.forEach(c=>byIndex[c.index]={kind:'later',c});
    kept.forEach(c=>byIndex[c.index]={kind:'kept',c});

    // Full survey list: every answerable question, flagged or already strong.
    const rows=qs.map((q,i)=>{
      if(QTYPES[q.type]&&QTYPES[q.type].structural) return '';
      if(App._isMetaColumn(q) && !byIndex[i]) return ''; // hide unflagged identifier/date columns
      const entry=byIndex[i];
      let dot,icon,sub,go,attr='',cls='';
      if(entry&&(entry.kind==='active'||entry.kind==='later')){
        const c=entry.c, worst=c.concerns.some(x=>x.sev==='critical'||x.sev==='major');
        dot=worst?'block':'flag'; icon='!';
        sub=(entry.kind==='later'?'Set aside · ':'')+oneConcern(c);
        go='<span class="qsl-go">Strengthen →</span>'; attr=`onclick="App.openStrengthLab(${i})"`;
      } else if(entry&&entry.kind==='kept'){
        dot='kept'; icon='='; sub='Kept as written'; go='<span class="qsl-go muted">Kept</span>'; cls='clean'; attr=`onclick="App.reviseReopen(${i},'${entry.c.ref}')"`;
      } else {
        dot='strong'; icon='✓'; sub='Looks strong'; go='<span class="qsl-go muted">Looks strong</span>'; cls='clean';
      }
      return `<div class="qsl-qrow ${cls}" ${attr}>
        <span class="qsl-st ${dot}">${icon}</span>
        <div class="qsl-qx"><div class="qsl-ql">${e(q.t||'(no text)')}</div><div class="qsl-qc">${e(sub)}</div></div>
        ${go}</div>`;
    }).join('');

    const firstActive=active.length?active[0].index:-1;
    const ringP=total!=null?Math.round(total/50*100):0;
    const sub=active.length
      ? `${active.length} of your ${meaningful.length} questions can be stronger. Work through them one at a time.`
      : 'Every question has been reviewed. Nice work.';

    return `<div class="screen">
      <div class="eyebrow">Step 4 · Revise</div>
      <h1 class="title">Strengthen your questions</h1>
      <p class="lede">Open any flagged question to strengthen it, one at a time. ReliCheck shows what it noticed, why it matters, and a way to fix it. Your score updates as you go.</p>
      ${state.sdsiStale?`<div class="callout amber" style="margin-bottom:16px"><div class="ci">${SVG.info}</div><div><h4>Survey changed</h4><p style="margin:0">Re-run the Build Check to refresh your saved score.</p></div>
        <div style="margin-left:auto"><button class="btn sm primary" onclick="App.runSdsi()">Re-run Build Check</button></div></div>`:''}
      <div class="card" style="margin-bottom:16px"><div class="qsl-scorecard">
        <div class="qsl-ring" style="--p:${ringP}"><b>${total!=null?total.toFixed(1):'—'}</b></div>
        <div class="qsl-sx">
          <h2>SDSI ${total!=null?total.toFixed(1):'—'} / 50${band?` · <span class="badge ${bandCls}">${e(band)}</span>`:''}</h2>
          <p>${sub}</p>
        </div>
        ${active.length?`<button class="btn primary" onclick="App.openStrengthLab(${firstActive})">Strengthen my questions ${SVG.arrow}</button>`:''}
      </div></div>
      <div class="card">${rows}</div>
      <div class="btn-row" style="margin-top:18px">
        <button class="btn" onclick="App.go('sdsi')">← Build Check</button>
        <div class="spacer"></div>
        <button class="btn" onclick="App.runSdsi()">Re-run Build Check</button>
        <button class="btn primary lg" onclick="App.go('preview')">Preview survey ${SVG.arrow}</button>
      </div>
    </div>`;
  },

  preview(){
    const e=escapeHtml;
    const s=state.survey;
    const qs=(s&&s.questions)||[];
    if(!qs.length){
      return `<div class="screen">
        <div class="eyebrow">Step 5 · Preview</div>
        <h1 class="title">Respondent preview</h1>
        <p class="lede">This is exactly what respondents will see, as a single page.</p>
        <div class="callout amber" style="margin-bottom:18px"><div class="ci">${SVG.info}</div><div><h4>No questions yet</h4>
          <p style="margin:0">Add or import your questions in Build, then preview the whole survey here.</p>
          <div style="margin-top:10px"><button class="btn sm" onclick="App.go('build')">← Go to Build</button></div></div></div>
        <div class="btn-row"><button class="btn" onclick="App.go('revise')">← Revise</button></div>
      </div>`;
    }
    const structuralTypes=['Section Text','Page Break','Thank-you Message'];
    const title=e((s&&s.title)||state.study.name||'Untitled survey');
    // Render the ENTIRE survey as one scrollable page — the real respondent form
    // is single-page, so the preview shows every question at once (no pagination).
    let n=0;
    const rows=qs.map(q=>{
      if(q.type==='Page Break') return `<div class="pv-break">Page break</div>`;
      if(structuralTypes.includes(q.type)){
        return `<div class="pv-section"><div class="pv-section-t">${e(q.t||'')}</div></div>`;
      }
      n++;
      const req=q.required?` <span style="color:var(--accent)" title="Required">*</span>`:'';
      return `<div class="pv-q">
        <div class="pv-q-stem"><span class="pv-q-n">${n}.</span> ${e(q.t||'(untitled question)')}${req}</div>
        <div class="pv-q-body">${qPreview(q)}</div>
      </div>`;
    }).join('');
    const nQ=qs.filter(q=>!structuralTypes.includes(q.type)).length;
    return `<div class="screen">
      <div class="eyebrow">Step 5 · Preview</div>
      <h1 class="title">Respondent preview</h1>
      <p class="lede">The whole survey on one page, exactly as respondents will see it. ${nQ} question${nQ===1?'':'s'}.</p>
      <div class="pv-form">
        <div class="pv-form-head">
          <h2 style="margin:0;font-size:20px;font-weight:800;letter-spacing:-0.02em">${title}</h2>
          ${state.study.purpose?`<p class="muted" style="margin:6px 0 0;font-size:13.5px">${e(state.study.purpose)}</p>`:''}
        </div>
        <div class="pv-form-body">${rows}</div>
        <div class="pv-form-foot">
          <button class="btn primary" disabled style="opacity:.7;cursor:default">Submit response</button>
          <span class="muted" style="font-size:12.5px;margin-left:12px">Preview only. Responses are not recorded here.</span>
        </div>
      </div>
      <div class="btn-row" style="margin-top:18px">
        <button class="btn" onclick="App.go('revise')">← Revise</button>
        <div class="spacer"></div>
        <button class="btn primary lg" onclick="App.runSiri()">Continue to readiness review ${SVG.arrow}</button>
      </div>
    </div>`;
  },

  publish(){
    const e=escapeHtml;
    const g=App._publishGate();
    const r=state.siriResult;
    // Status banner styling + plain-language line per the three gate states.
    const meta={
      blocked:{cls:'red',  badge:'Blocked',         line:'Resolve the required launch issues below, then re-run SIRI.'},
      review: {cls:'amber',badge:'Needs review',     line:'The Launch Check is out of date. Re-run SIRI to confirm readiness.'},
      ready:  {cls:'green',badge:'Ready to publish', line:'The Launch Check passed with no unresolved blockers.'}
    }[g.status];
    const score = r ? `<span class="muted" style="font-size:13.5px">${ r.total!=null ? ('Total '+r.total.toFixed(1)+' / 100 · '+e(r.total_band)) : ('SIRI '+r.siri.toFixed(1)+' / 50') }</span>` : '';

    // Blocker / reason rows, each routed back through the Phase 2E fix routing.
    let reasonRows='';
    if(g.status==='blocked' && g.blockers){
      reasonRows=g.blockers.map(b=>{ const fx=App._fixArg(b.key);
        return `<div class="callout red" style="margin-bottom:10px"><div class="ci">${SVG.info}</div>
          <div style="flex:1"><h4 style="margin:0 0 2px">${e(b.name)} <span class="faint" style="font-weight:500">· ${e(b.domain)}</span></h4>
            <p style="margin:0 0 8px;font-size:14px">This is a launch blocker. It must be resolved before publishing.</p>
            <button class="btn sm" onclick="App.go(${fx.arg})">${e(fx.label)} ${SVG.arrow}</button></div></div>`;
      }).join('');
    } else if(g.reasons){
      reasonRows=g.reasons.map(rs=>`<div class="callout ${meta.cls==='red'?'red':'amber'}" style="margin-bottom:10px"><div class="ci">${SVG.info}</div>
          <div style="flex:1"><p style="margin:0 0 8px;font-size:14px">${e(rs.msg)}</p>
            <button class="btn sm" onclick="App.go('${rs.route}'${rs.focus?",'"+rs.focus+"'":''})">${e(rs.fixLabel)} ${SVG.arrow}</button></div></div>`).join('');
    }

    const sdsiWarn = g.sdsiWarn
      ? `<div class="callout amber" style="margin-bottom:14px"><div class="ci">${SVG.info}</div><div style="flex:1"><h4 style="margin:0 0 2px">Build Check advisory</h4><p style="margin:0 0 8px">${e(g.sdsiWarn)}</p><button class="btn sm" onclick="App.go('sdsi')">Open Build Check ${SVG.arrow}</button></div></div>`
      : '';

    const ready = g.status==='ready';
    if(!ready) state.publishReady=false;   // readiness confirmation only holds while the gate is open
    const ds=state.deploymentSettings;
    const linkLine = ds && ds.link_key
      ? `<div class="lens" style="margin-bottom:8px;gap:10px;flex-wrap:wrap">
           <span class="nm">Survey link</span>
           <code style="font-size:12.5px;flex:1;min-width:0;overflow:hidden;text-overflow:ellipsis">relichecksurvey.com/s/${e(ds.link_key)}</code>
           <span class="badge amber" style="white-space:nowrap">not yet live</span>
         </div>
         <div class="lens"><span class="nm">Response collection</span><span class="badge gray">closed</span></div>`
      : `<div class="lens" style="margin-bottom:8px"><span class="nm">Public survey link</span><span class="badge gray">generated on publish</span></div>
         <div class="lens"><span class="nm">Response collection</span><span class="badge gray">closed</span></div>`;
    const readyBlock = ready
      ? `${(state.publishReady && ds && ds.link_key)?`<div class="callout green" style="margin-bottom:14px"><div class="ci">${SVG.check}</div><div><h4>Survey link generated</h4><p style="margin:0;font-size:13.5px">Link reserved: <code>relichecksurvey.com/s/${e(ds.link_key)}</code>. Open for responses from the deploy screen.</p></div></div>`:
          (state.publishReady?`<div class="callout green" style="margin-bottom:14px"><div class="ci">${SVG.check}</div><div><h4>Marked ready to publish</h4><p style="margin:0">The Launch Check passed. Confirm below to generate a survey link.</p></div></div>`:'')}
        <div class="card pad" style="margin-bottom:18px">
          <h2 class="sec" style="margin-top:0">Survey link</h2>
          <p class="muted" style="font-size:14px;margin:0 0 10px">Publishing generates a unique survey link. Open or close response collection from the deploy screen.</p>
          ${linkLine}
        </div>`
      : '';

    // Acknowledged override: a user may publish past a HARD blocker, but only
    // after explicitly checking that they understand the risk. Cautions never
    // reach here (they are not blockers); this shows only for status 'blocked'.
    const overrideBlock = (g.status==='blocked')
      ? `<div class="card pad" style="margin-bottom:18px;border-color:rgba(194,73,47,.3)">
          <h2 class="sec" style="margin-top:0">Publish without resolving</h2>
          <p class="muted" style="font-size:14px;margin:0 0 12px">You can publish with the issues above unresolved. The warnings stay on record, and you are responsible for anything missing, such as a documented consent or privacy notice. Resolving them first is strongly recommended.</p>
          <label style="display:flex;gap:9px;align-items:flex-start;font-size:14px;cursor:pointer;margin-bottom:14px">
            <input type="checkbox" ${state.publishOverride?'checked':''} onchange="App.setPublishOverride(this.checked)" style="margin-top:3px;width:16px;height:16px">
            <span>I understand this survey has unresolved launch issues and I choose to publish anyway.</span>
          </label>
          <button class="btn primary lg" onclick="App.publishNow(true)" ${state.publishOverride?'':'disabled style="opacity:.5;cursor:not-allowed"'} title="${state.publishOverride?'':'Check the box above to publish anyway'}">Publish anyway ${SVG.arrow}</button>
        </div>`
      : '';

    return `<div class="screen">
      <div class="eyebrow">Step 8 · Publish Readiness</div>
      <h1 class="title">Publish readiness</h1>
      <p class="lede">The Launch Check (SIRI) is the final gate before publishing. This step confirms the survey is ready; it does not yet generate a public link or collect responses.</p>

      <div class="card pad" style="margin-bottom:18px">
        <div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap">
          <span class="badge ${meta.cls}" style="font-size:13px;padding:3px 12px">${meta.badge}</span>
          <h2 class="sec" style="margin:0">${e(g.headline)}</h2>
          <div class="spacer" style="flex:1"></div>
          ${score}
        </div>
        <p class="muted" style="font-size:14px;margin:10px 0 0">${meta.line}</p>
      </div>

      ${sdsiWarn}
      ${reasonRows ? `<h2 class="sec">${g.status==='blocked'&&g.blockers?'Required before publishing':'What to do'}</h2>${reasonRows}` : ''}
      ${readyBlock}
      ${overrideBlock}

      <div class="btn-row">
        <button class="btn" onclick="App.go('siri')">← SIRI Launch Check</button>
        <div class="spacer"></div>
        ${ready
          ? (ds && ds.link_key
              ? `<button class="btn primary lg" onclick="App.go('deploy')">View deploy screen ${SVG.arrow}</button>`
              : `<button class="btn primary lg" onclick="App.publishNow()">Generate survey link ${SVG.arrow}</button>`)
          : (g.status==='blocked'
              ? `<button class="btn" onclick="App.runSiri()">Re-run SIRI</button>`
              : `<button class="btn lg" disabled title="Re-run the Launch Check first" style="opacity:.5;cursor:not-allowed">Publish ${SVG.arrow}</button>`)}
      </div>
    </div>`;
  },

  deploy(){
    const e=escapeHtml;
    const ds=state.deploymentSettings;
    const ch=(ico,h,p,fn)=>`<div class="card dest" onclick="${fn}" style="cursor:pointer">
      <div class="ico">${icon(ico)}</div><h3 style="font-size:16px;font-weight:700">${h}</h3>
      <p class="muted" style="font-size:13px">${p}</p></div>`;
    const isOpen = !!(ds && ds.responses_open);
    const linkBanner = ds && ds.link_key
      ? `<div class="callout ${isOpen?'green':'amber'}" style="margin-bottom:22px">
           <div class="ci">${isOpen?SVG.check:SVG.info}</div>
           <div style="flex:1">
             <h4 style="margin:0 0 4px">
               Survey link
               <span class="badge ${isOpen?'green':'amber'}" style="padding:1px 8px;margin-left:6px">${isOpen?'Open for responses':'Not yet live'}</span>
             </h4>
             <p style="font-family:monospace;font-size:13px;margin:0 0 8px">relichecksurvey.com/s/${e(ds.link_key)}</p>
             <p style="font-size:13px;margin:0 0 8px"><b>${state.responseCount||0}</b> response${(state.responseCount||0)===1?'':'s'} collected so far.</p>
             <button class="btn sm${isOpen?'':' primary'}" onclick="App.toggleResponsesOpen()">
               ${isOpen?'Close survey':'Open for responses'}
             </button>
           </div>
         </div>`
      : `<div class="callout amber" style="margin-bottom:22px"><div class="ci">${SVG.info}</div>
           <div><h4>Public survey link</h4><p style="margin:0">Complete the Publish Readiness step to generate a survey link.</p>
             <button class="btn sm" style="margin-top:8px" onclick="App.go('publish')">Go to Publish Readiness ${SVG.arrow}</button></div>
         </div>`;
    return `<div class="screen">
      <div class="eyebrow">Step 9 · Deploy / Export</div>
      <h1 class="title">Deploy and export</h1>
      <p class="lede">Control whether your public link accepts responses. Use the toggle below to open or close the survey.</p>
      ${linkBanner}
      <h2 class="sec">Deploy</h2>
      <div class="grid g3">
        ${ch('<path d="M4 4h16v12H5.2L4 17.2z"/><path d="M8 9h8M8 12h5"/>','Share link','Email or embed the survey link anywhere.','App.shareLink()')}
        ${ch('<rect x="6" y="3" width="12" height="18" rx="2"/><line x1="11" y1="18" x2="13" y2="18"/>','QR / mobile','Generate a QR code for in-person or on-site fielding.','App.showQR()')}
        ${ch('<path d="M3 7l9-4 9 4-9 4-9-4z"/><path d="M3 7v10l9 4 9-4V7"/>','Invite panel','Compose an email invite to a respondent list.','App.invitePanel()')}
      </div>
      <h2 class="sec">Export instrument</h2>
      <div class="grid g3">
        ${ch('<path d="M14 3v5h5"/><path d="M7 3h7l5 5v13H7z"/><path d="M9 13h6M9 17h6"/>','Word / PDF','Print-ready instrument for offline use.','App.exportInstrumentDoc()')}
        ${ch('<rect x="4" y="4" width="16" height="16" rx="2"/><path d="M9 4v16M4 9h16"/>','CSV / Excel','Item bank export for other tools.','App.exportInstrumentCsv()')}
        ${ch('<path d="M8 3H5a2 2 0 0 0-2 2v3M16 3h3a2 2 0 0 1 2 2v3M8 21H5a2 2 0 0 1-2-2v-3M16 21h3a2 2 0 0 1 2-2v-3"/>','API / Qualtrics','Export a portable file to import elsewhere.','App.exportInstrumentJson()')}
      </div>
      <div class="btn-row">
        <button class="btn" onclick="App.go('publish')">← Publish</button>
        <div class="spacer"></div>
        <button class="btn primary lg" onclick="App.go('retrieve')">View incoming responses ${SVG.arrow}</button>
      </div>
    </div>`;
  },

  retrieve(){
    const e=escapeHtml;
    const header = `
      <div class="eyebrow">Step 9 · Retrieve data</div>
      <h1 class="title">Responses</h1>
      <p class="lede">Every completed submission to your public survey link. Responses are kept whether the survey is open or closed.</p>`;
    const navRow = `
      <div class="btn-row">
        <button class="btn" onclick="App.go('deploy')">← Deploy</button>
        <div class="spacer"></div>
        <button class="btn primary lg" onclick="App.go('analysis')">Send to RSSI / Studios ${SVG.arrow}</button>
      </div>`;

    // Database mode is required to read stored responses.
    if(!(PERSIST_REQUESTED && state.projectId)){
      return `<div class="screen">${header}
        <div class="callout amber" style="margin-bottom:22px"><div class="ci">${SVG.info}</div>
          <div><h4>Database mode required</h4><p style="margin:0">Open this project in database mode to view collected responses.</p></div></div>
        ${navRow}</div>`;
    }

    // Kick off a one-time load when the list has not been fetched yet.
    if(state.responseList===null && !state.responsesLoading){
      setTimeout(()=>App._loadResponses(),0);
    }

    if(state.responseList===null || state.responsesLoading){
      return `<div class="screen">${header}
        <div class="card pad" style="text-align:center;color:var(--muted)">Loading responses…</div>
        ${navRow}</div>`;
    }

    if(state.responsesError){
      return `<div class="screen">${header}
        <div class="callout amber" style="margin-bottom:22px"><div class="ci">${SVG.info}</div>
          <div><h4>Could not load responses</h4><p style="margin:0 0 8px">${e(state.responsesError)}</p>
            <button class="btn sm" onclick="App.refreshResponses()">Try again</button></div></div>
        ${navRow}</div>`;
    }

    const list=state.responseList;
    const count=list.length;

    // Empty state.
    if(count===0){
      return `<div class="screen">${header}
        <div class="card pad" style="text-align:center">
          <div style="font-size:34px;line-height:1;margin-bottom:8px">📭</div>
          <h2 class="sec" style="margin:0 0 6px">No responses yet</h2>
          <p class="muted" style="margin:0">When someone completes your survey at the public link, their answers will appear here. Open the survey for responses on the Deploy screen to start collecting.</p>
          <div style="margin-top:14px"><button class="btn sm" onclick="App.go('deploy')">Go to Deploy</button></div>
        </div>
        ${navRow}</div>`;
    }

    // Each session → a card with submitted timestamp and a question/answer table.
    const fmt=(ts)=>{ if(!ts) return ''; const d=new Date(ts.replace(' ','T')+'Z'); return isNaN(d)?e(ts):d.toLocaleString(); };
    const cards=list.map((s,i)=>{
      const num=count-i; // newest is highest number
      const rows=(s.answers||[]).map(a=>`<tr>
        <td style="vertical-align:top;width:55%">${e(a.label||'(untitled item)')}</td>
        <td style="vertical-align:top;white-space:pre-wrap">${a.value!==''?e(a.value):'<span class="faint">(no answer)</span>'}</td>
      </tr>`).join('');
      const body=(s.answers&&s.answers.length)
        ? `<table class="tbl"><thead><tr><th>Question</th><th>Answer</th></tr></thead><tbody>${rows}</tbody></table>`
        : `<div style="padding:12px 20px;color:var(--muted)">No stored answers for this submission.</div>`;
      return `<div class="card" style="margin-bottom:16px">
        <div style="padding:14px 20px;border-bottom:1px solid var(--line);display:flex;justify-content:space-between;align-items:center">
          <h3 style="margin:0;font-size:15px;font-weight:700">Response ${num}</h3>
          <span class="faint" style="font-size:12.5px">Submitted ${fmt(s.submitted_at)}</span>
        </div>${body}</div>`;
    }).join('');

    return `<div class="screen">${header}
      <div class="grid g4" style="margin-bottom:22px">
        <div class="card stat"><div class="n">${count}</div><div class="l">response${count===1?'':'s'} collected</div></div>
      </div>
      <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:10px">
        <h2 class="sec" style="margin:0">Collected responses</h2>
        <div style="display:flex;gap:8px">
          <button class="btn sm" onclick="App.exportResponsesCsv()">Export CSV</button>
          <button class="btn sm" onclick="App.refreshResponses()">Refresh</button>
        </div>
      </div>
      ${cards}
      ${navRow}</div>`;
  },

  analysis(){
    const dest=(ico,h,p,locked,action,cta)=>`<div class="card dest ${locked?'locked':''}" onclick="${locked?'':(action||`App.stub('Open ${h}')`)}">
      <div class="ico">${icon(ico)}</div>
      <h3 style="font-size:17px;font-weight:700">${h}${locked?' <span class="badge gray">soon</span>':''}</h3>
      <p class="muted" style="font-size:13.5px">${p}</p>
      ${locked?'':`<span class="go" style="color:var(--blue);font-weight:700;font-size:13px;margin-top:auto">${cta||'Hand off'} ${SVG.arrow}</span>`}</div>`;
    return `<div class="screen">
      <div class="eyebrow">Step 10 · RSSI / Studios</div>
      <h1 class="title">Hand off to analysis</h1>
      <p class="lede">Your ${state.responseCount||0} response${(state.responseCount||0)===1?'':'s'} are ready. Send them downstream. Design and readiness review are done; this is where collected results get analyzed.</p>
      <div class="callout" style="margin-bottom:22px"><div class="ci">${SVG.info}</div>
        <div><h4>SDSI &amp; SIRI are pre-launch only</h4><p>Everything up to here judged whether the instrument was ready to collect interpretable data. RSSI and the Studios analyze the data itself, after deployment or once imported response data is added.</p></div></div>
      <div class="grid g2">
        ${dest(ICONS.rssi,'Run RSSI','Analyze your collected data through the ReliCheck Survey Strength Index. RSSI reviews reliability, validity evidence, response quality, item performance, and reporting readiness.',false,
          state.projectId ? `window.location.href='/rssi-app.php?project_id=${encodeURIComponent(state.projectId)}'` : `window.location.href='/rssi-app.php'`,
          'Open RSSI')}
        ${dest(ICONS.studio,'Go to a Studio','Take your data directly into a studio for deeper analysis — mixed methods or qualitative themes.',false,
          `window.location.href='/mmstudioV4.php${state.projectId?'?project_id='+encodeURIComponent(state.projectId):''}'`,
          'Open MM Studio')}
      </div>
      <div class="grid g2" style="margin-top:18px">
        ${dest('<path d="M4 6h16M4 12h10M4 18h7"/>','MM Studio','Mixed methods — qualitative themes + quantitative analysis together.',false,
          `window.location.href='/mmstudioV4.php${state.projectId?'?project_id='+encodeURIComponent(state.projectId):''}'`,'Open')}
        ${dest('<path d="M4 6h16M4 12h10M4 18h7"/>','Qualitative Studio','Code responses, build themes, and develop evidence-supported qualitative interpretations.',false,
          `window.location.href='/qual-studio-workspaceV3.php'`,'Open')}
      </div>
      <div class="btn-row">
        <button class="btn" onclick="App.go('retrieve')">← Responses</button>
        <div class="spacer"></div>
        <button class="btn" onclick="App.go('start')">Start another survey</button>
      </div>
      <p class="phase-note">Real response data is loaded from your project. Select RSSI or a Studio to continue your analysis.</p>
    </div>`;
  },

  // Phase 4A: RSSI Dataset Loader preview. Shows the normalized, analysis-ready
  // dataset built live from stored responses. No mock data, no RSSI score.
  dataset(){
    const e=escapeHtml;
    const header = `
      <div class="eyebrow">RSSI · Dataset loader</div>
      <h1 class="title">RSSI dataset</h1>
      <p class="lede">Load response data for the ReliCheck Survey Strength Index. Use your collected survey responses or upload a CSV / Excel file. This step prepares the data only — no reliability score is calculated yet.</p>`;
    const navRow = `
      <div class="btn-row">
        <button class="btn" onclick="App.go('analysis')">← Hand off</button>
        <div class="spacer"></div>
        <button class="btn sm" onclick="App.openUploadDataset()">${SVG.import} Upload data</button>
        <button class="btn sm" onclick="App.refreshDataset()">Refresh</button>
      </div>`;

    if(!(PERSIST_REQUESTED && state.projectId)){
      return `<div class="screen">${header}
        <div class="callout amber" style="margin-bottom:22px"><div class="ci">${SVG.info}</div>
          <div><h4>Database mode required</h4><p style="margin:0">Open this project in database mode to load its response dataset.</p></div></div>
        ${navRow}</div>`;
    }
    if(state.dataset===null && !state.datasetLoading){ setTimeout(()=>App._loadDataset(),0); }
    if(state.dataset===null || state.datasetLoading){
      return `<div class="screen">${header}
        <div class="card pad" style="text-align:center;color:var(--muted)">Loading dataset…</div>
        ${navRow}</div>`;
    }
    if(state.dataset===false || state.datasetError){
      return `<div class="screen">${header}
        <div class="callout amber" style="margin-bottom:22px"><div class="ci">${SVG.info}</div>
          <div><h4>Could not load dataset</h4><p style="margin:0 0 8px">${e(state.datasetError||'Unknown error')}</p>
            <button class="btn sm" onclick="App.refreshDataset()">Try again</button></div></div>
        ${navRow}</div>`;
    }

    const d=state.dataset;
    const c=d.counts||{}, resp=d.responses||{}, ft=d.fieldTypeSummary||{};

    // Source banner: show when using an uploaded dataset vs collected responses.
    const sourceBanner = d.linkedDatasetId
      ? `<div class="callout" style="margin-bottom:18px;background:var(--accent-soft,#fff8f6);border-color:rgba(15,158,123,.18)"><div class="ci">${SVG.import}</div>
          <div style="flex:1"><b>Using uploaded dataset:</b> ${e(d.datasetTitle||'Uploaded data')}
            <span style="margin-left:10px"><button class="btn sm" onclick="App.openUploadDataset()" style="font-size:12px">Change</button></span></div></div>`
      : (resp.total_n===0
          ? `<div class="callout amber" style="margin-bottom:18px"><div class="ci">${SVG.info}</div>
              <div><p style="margin:0 0 8px">No collected responses yet. You can upload a CSV or Excel file of existing response data to score with RSSI.</p>
                <button class="btn sm" onclick="App.openUploadDataset()">${SVG.import} Upload data file</button></div></div>`
          : '');

    // N-adequacy fence panel (the alpha-fence stance, shown plainly).
    const fenceClass = resp.too_few_responses ? 'amber' : '';
    const fenceNotes=(resp.fence_notes||[]).map(n=>`<li>${e(n)}</li>`).join('');
    const fenceLabel = d.linkedDatasetId ? 'rows' : 'responses';
    const fence = `<div class="callout ${fenceClass}" style="margin-bottom:22px"><div class="ci">${SVG.info}</div>
      <div style="flex:1"><h4 style="margin:0 0 4px">Sample size check</h4>
        <p style="margin:0 0 6px">Analyzable ${fenceLabel}: <b>${e(String(resp.analyzable_n))}</b> of ${e(String(resp.total_n))} total. Minimum for reliability claims: ${e(String(resp.min_n))}.</p>
        <ul style="margin:0;padding-left:18px">${fenceNotes}</ul></div></div>`;

    // Top-line counts.
    const rowLabel = d.linkedDatasetId ? 'row' : 'response';
    const stats = `<div class="grid g4" style="margin-bottom:8px">
      <div class="card stat"><div class="n">${e(String(resp.total_n))}</div><div class="l">${rowLabel}${resp.total_n===1?'':'s'}</div></div>
      <div class="card stat"><div class="n">${e(String(c.items_input||0))}</div><div class="l">input items</div></div>
      <div class="card stat"><div class="n">${e(String(c.constructs_mapped||0))}</div><div class="l">construct group${(c.constructs_mapped||0)===1?'':'s'}</div></div>
      <div class="card stat"><div class="n">${e(String(c.items_scorable||0))}</div><div class="l">scorable items</div></div>
    </div>`;

    // Field-type summary.
    const ftLabel={numeric_scale:'Numeric scale',categorical:'Categorical',binary:'Binary',open_text:'Open text',structural:'Structural'};
    const ftRows=Object.keys(ftLabel).map(k=>`<tr><td>${e(ftLabel[k])}</td><td style="text-align:right">${e(String(ft[k]||0))}</td></tr>`).join('');
    const fieldTypes=`<div class="card" style="margin-bottom:18px">
      <div style="padding:12px 20px;border-bottom:1px solid var(--line)"><h3 style="margin:0;font-size:15px;font-weight:700">Field types</h3></div>
      <table class="tbl"><tbody>${ftRows}</tbody></table></div>`;

    // Construct groups (construct-first), each with its items and thin-evidence note.
    const itemsById={}; (d.items||[]).forEach(it=>itemsById[it.id]=it);
    const constructCards=(d.constructs||[]).map(con=>{
      const itemRows=(con.itemIds||[]).map(id=>{
        const it=itemsById[id]; if(!it) return '';
        return `<tr><td>${e(it.label||'(untitled)')}</td><td>${e(it.fieldType)}</td>
          <td style="text-align:right">${e(String(it.answered))}/${e(String(it.answered+it.missing))}</td></tr>`;
      }).join('');
      const note=con.note?`<div class="callout amber" style="margin:0 20px 14px"><div class="ci">${SVG.info}</div><div><p style="margin:0">${e(con.note)}</p></div></div>`:'';
      return `<div class="card" style="margin-bottom:14px">
        <div style="padding:12px 20px;border-bottom:1px solid var(--line);display:flex;justify-content:space-between;align-items:center">
          <h3 style="margin:0;font-size:15px;font-weight:700">${e(con.name||'(unnamed construct)')}</h3>
          <span class="faint" style="font-size:12.5px">${e(String(con.itemCount))} item${con.itemCount===1?'':'s'} · ${e(String(con.scorableCount))} scorable</span>
        </div>
        ${con.itemCount?`<table class="tbl"><thead><tr><th>Item</th><th>Field type</th><th style="text-align:right">Answered</th></tr></thead><tbody>${itemRows}</tbody></table>`:`<div style="padding:12px 20px;color:var(--muted)">No items mapped to this construct.</div>`}
        ${note}</div>`;
    }).join('');

    // Items not mapped to any construct.
    const unmapped=(d.unmappedItemIds||[]);
    const unmappedRows=unmapped.map(id=>{
      const it=itemsById[id]; if(!it) return '';
      return `<tr><td>${e(it.label||'(untitled)')}</td><td>${e(it.fieldType)}</td>
        <td style="text-align:right">${e(String(it.answered))}/${e(String(it.answered+it.missing))}</td></tr>`;
    }).join('');
    const unmappedCard=unmapped.length?`<div class="card" style="margin-bottom:14px">
      <div style="padding:12px 20px;border-bottom:1px solid var(--line)"><h3 style="margin:0;font-size:15px;font-weight:700">Unmapped items</h3></div>
      <table class="tbl"><thead><tr><th>Item</th><th>Field type</th><th style="text-align:right">Answered</th></tr></thead><tbody>${unmappedRows}</tbody></table></div>`:'';

    // Missingness summary across all input items.
    const inputItems=(d.items||[]).filter(it=>!it.structural);
    const missRows=inputItems.map(it=>`<tr>
      <td>${e(it.label||'(untitled)')}</td>
      <td style="text-align:right">${e(String(it.answered))}</td>
      <td style="text-align:right">${e(String(it.missing))}</td></tr>`).join('');
    const missCard=`<div class="card" style="margin-bottom:18px">
      <div style="padding:12px 20px;border-bottom:1px solid var(--line)"><h3 style="margin:0;font-size:15px;font-weight:700">Missingness by item</h3></div>
      ${inputItems.length?`<table class="tbl"><thead><tr><th>Item</th><th style="text-align:right">Answered</th><th style="text-align:right">Missing</th></tr></thead><tbody>${missRows}</tbody></table>`:`<div style="padding:12px 20px;color:var(--muted)">No input items.</div>`}</div>`;

    // ── Phase 4C: RSSI run + status panel. The full report (4D) lives in
    // Screens.rssiReport(); when no run exists yet we show a run prompt.
    const rr=state.rssiResult, saved=state.rssiSaved;
    let rssiPanel;
    if(!rr){
      const runBtn=`<button class="btn" onclick="App.runRssi()"${state.rssiRunning?' disabled':''}>${state.rssiRunning?'Running…':'Run RSSI'} ${SVG.arrow}</button>`;
      rssiPanel=`<div class="card pad" style="margin-bottom:18px">
        <h3 style="margin:0 0 4px;font-size:15px;font-weight:700">ReliCheck Survey Strength Index</h3>
        <p style="margin:0 0 12px;color:var(--muted)">Run RSSI to score internal consistency, item performance, response quality, and score interpretability over the loaded dataset. It withholds a score when there is not enough data to judge.</p>
        ${runBtn}</div>`;
    } else {
      rssiPanel=Screens.rssiReport(rr, saved);
    }

    // RE Item 5: link to the full RSSI journey app for this project.
    const rssiAppLink=state.projectId
      ?`<div style="margin:6px 0 18px"><a class="btn" href="/rssi-app.php?survey_project_id=${encodeURIComponent(state.projectId)}" target="_blank">Explore in RSSI App ↗</a></div>`
      :'';

    return `<div class="screen">${header}
      ${sourceBanner}
      ${fence}
      ${stats}
      <h2 class="sec" style="margin:18px 0 10px">RSSI report</h2>
      ${rssiPanel}
      ${rssiAppLink}
      <div style="display:flex;justify-content:space-between;align-items:center;margin:16px 0 10px">
        <h2 class="sec" style="margin:0">Construct groups</h2></div>
      ${(d.constructs&&d.constructs.length)?constructCards:`<div class="card pad" style="color:var(--muted)">No constructs defined. RSSI will fall back to whole-survey evidence.</div>`}
      ${unmappedCard}
      <h2 class="sec" style="margin:18px 0 10px">Field types</h2>
      ${fieldTypes}
      <h2 class="sec" style="margin:18px 0 10px">Missingness</h2>
      ${missCard}
      ${navRow}</div>`;
  },

  // ── Phase 4D: full construct-first RSSI report ─────────────────────────────
  // Pure presentation of a saved/rerun RSSIEngine.score() result (state.rssiResult).
  // It renders, in order: overall score or withheld verdict, interpretation band,
  // the N-adequacy fence, four domain cards, per-construct reliability evidence,
  // grouped item warnings, items that are deliberately not reliability-scored,
  // and the summary + fence notes. It NEVER recomputes a number; every value comes
  // straight from the engine result so the UI cannot drift from the tested engine.
  rssiReport(rr, saved, forPrint){
    const e=escapeHtml;
    const withheld=(rr.score===null);
    const fc=rr.fence||{};

    // Interactive controls (re-run + export) are dropped from the print view so the
    // exported document is a clean, static snapshot of the saved result.
    const runBtn=`<button class="btn sm" onclick="App.runRssi()"${state.rssiRunning?' disabled':''}>${state.rssiRunning?'Running…':'Re-run RSSI'} ${SVG.arrow}</button>`;
    const headerBtns=forPrint ? '' : `<div style="display:flex;gap:8px;flex-wrap:wrap">${runBtn}
      <button class="btn sm" onclick="App.printRssi()">Print / Save as PDF</button>
      <button class="btn sm" onclick="App.exportRssiJson()">Export JSON</button></div>`;

    // saved/stale provenance line
    let savedTxt;
    if(saved){
      const cnt=saved.response_count!=null?saved.response_count:0;
      savedTxt=`Saved · scored from ${e(String(cnt))} response${cnt===1?'':'s'} at run time`;
    } else { savedTxt='Computed (not yet saved)'; }
    const staleBanner=state.rssiStale ? `<div class="callout amber" style="margin:0 0 14px"><div class="ci">${SVG.info}</div>
      <div style="flex:1"><h4 style="margin:0 0 2px">This RSSI is out of date</h4>
      <p style="margin:0">New responses have been collected since RSSI last ran (saved from ${e(String(saved&&saved.response_count||0))}, now ${e(String(saved&&saved.current_count!=null?saved.current_count:''))}). Re-run RSSI to reflect the latest data.</p></div></div>` : '';

    // band → colour, used for the verdict badge
    const bandCls={confident:'green',minor:'green',caution:'amber',not_yet:'red',insufficient:'gray'}[rr.bandKey]||'gray';

    // Accordion helper (reuses the existing pure-CSS .exp expander). In the print
    // view the accordions render OPEN so the exported PDF still contains everything.
    const exp=(title,body,open)=>`<details class="exp"${open?' open':''} style="margin-bottom:12px"><summary>${e(title)} <span class="faint">▾</span></summary><div class="body">${body}</div></details>`;

    // ── SCORE → MEANING: hero shows the number/verdict, the band, and a one-line meaning ──
    const meaningLine=Screens.rssiGuidance(rr,'meaning');
    let hero;
    if(withheld){
      hero=`<div style="padding:18px 20px;display:flex;align-items:flex-start;gap:18px;flex-wrap:wrap">
        <div style="font-size:30px;font-weight:800;line-height:1;color:var(--muted)">—</div>
        <div style="flex:1;min-width:240px">
          <div style="margin-bottom:6px"><span class="badge ${bandCls}" style="font-size:13px;padding:3px 12px">${e(rr.verdict||'Insufficient data to judge')}</span></div>
          <div style="color:var(--ink-2);font-size:13.5px">${e(meaningLine)}</div></div></div>`;
    } else {
      hero=`<div style="padding:18px 20px;display:flex;align-items:center;gap:20px;flex-wrap:wrap">
        <div style="font-size:38px;font-weight:800;line-height:1">${e(Number(rr.score).toFixed(1))}<span style="font-size:17px;font-weight:600;color:var(--muted)"> / 100</span></div>
        <div style="flex:1;min-width:240px">
          <div style="margin-bottom:5px"><span class="badge ${bandCls}" style="font-size:13px;padding:3px 12px">${e(rr.band||rr.verdict||'')}</span></div>
          <div style="color:var(--ink-2);font-size:13.5px">${e(meaningLine)}</div></div></div>`;
    }

    // ── N-adequacy fence (gate) ────────────────────────────────────────────
    const fenceNotes=(rr.fenceNotes||[]);
    const fenceClearTxt=`Sample-size fence clear: ${e(String(fc.analyzableN||0))} of ${e(String(fc.totalN||0))} analyzable responses (minimum ${e(String(fc.minN||30))}).`;
    const fencePanel=(withheld||fenceNotes.length)
      ? `<div class="callout ${withheld?'amber':''}" style="margin:0 0 14px"><div class="ci">${SVG.info}</div>
          <div style="flex:1"><h4 style="margin:0 0 4px">Sample-size fence</h4>
            ${fenceNotes.length?`<ul style="margin:0;padding-left:18px;font-size:13px;color:var(--ink-2)">${fenceNotes.map(n=>`<li>${e(n)}</li>`).join('')}</ul>`
              :`<p style="margin:0">${withheld?`Not enough analyzable data to judge reliability yet (${e(String(fc.analyzableN||0))} of a minimum ${e(String(fc.minN||30))}).`:'Enough analyzable data to judge reliability.'}</p>`}</div></div>`
      : `<div class="callout green" style="margin:0 0 14px"><div class="ci">${SVG.check}</div>
          <div><p style="margin:0">${fenceClearTxt}</p></div></div>`;

    // ── EVIDENCE 1: four domain cards (compact on the main screen; evidence bullets → accordion) ──
    const domainCardsCompact=(rr.domains||[]).map(dm=>{
      const w=(dm.withheld||dm.points===null);
      const pct=w?0:Math.round((dm.fraction||0)*100);
      const ptsTxt=w?'—':`${e(Number(dm.points).toFixed(1))}`;
      return `<div class="card pad">
        <div style="display:flex;justify-content:space-between;align-items:baseline;gap:8px">
          <h4 style="margin:0;font-size:13.5px;font-weight:700">${e(dm.label)}</h4>
          <div style="font-size:13px;font-weight:700;white-space:nowrap">${ptsTxt} <span style="color:var(--muted);font-weight:600">/ ${e(String(dm.max))}</span></div></div>
        <div class="meter" style="margin:10px 0 0"><span style="width:${pct}%${w?';background:var(--line)':''}"></span></div>
        ${w?`<div class="pill" style="display:inline-block;margin-top:10px">Withheld</div>`:''}</div>`;
    }).join('');
    const domainEvidence=(rr.domains||[]).map(dm=>{
      const ev=(dm.evidence||[]).map(x=>`<li>${e(x)}</li>`).join('');
      if(!ev) return '';
      return `<div style="margin-bottom:10px"><div style="font-size:12.5px;font-weight:700;margin-bottom:2px">${e(dm.label)}</div><ul style="margin:0;padding-left:16px;font-size:12.5px;color:var(--ink-2)">${ev}</ul></div>`;
    }).join('');

    // ── EVIDENCE 2: construct-level reliability (compact: name + α; deep stats → accordion) ──
    const alphaBandCls={excellent:'green',good:'green',acceptable:'green',questionable:'amber',poor:'amber',unacceptable:'red'};
    const aBadgeFor=c=>{
      const alphaTxt=(c.alpha!=null)?Number(c.alpha).toFixed(3):'—';
      return (c.scored&&c.alphaBand)?`<span class="badge ${alphaBandCls[c.alphaBand]||'gray'}" style="font-size:11px">α ${alphaTxt} · ${e(c.alphaBand)}</span>`
        :`<span class="pill">Not enough evidence</span>`;
    };
    const constructCompact=(rr.constructs||[]).map(c=>`<div class="card" style="margin-bottom:8px"><div style="padding:12px 18px;display:flex;justify-content:space-between;align-items:center;gap:12px">
        <h4 style="margin:0;font-size:14px;font-weight:700">${e(c.name||'(unnamed construct)')}</h4>${aBadgeFor(c)}</div></div>`).join('');
    const constructBlock=(rr.constructs&&rr.constructs.length)
      ? constructCompact
      : `<div class="card pad" style="color:var(--muted)">No constructs were available to score for internal consistency.</div>`;
    const constructDeep=(rr.constructs||[]).map(c=>{
      const note=c.note?`<div class="callout amber" style="margin:10px 0 0"><div class="ci">${SVG.info}</div><div><p style="margin:0">${e(c.note)}</p></div></div>`:'';
      const nTxt=(c.n!=null)?`${e(String(c.n))} complete-case`:'—';
      return `<div class="card" style="margin-bottom:10px"><div style="padding:12px 18px">
        <div style="display:flex;justify-content:space-between;align-items:center;gap:12px">
          <div><h4 style="margin:0;font-size:14px;font-weight:700">${e(c.name||'(unnamed construct)')}</h4>
            <div class="faint" style="font-size:12px;margin-top:2px">${e(String(c.scorableCount!=null?c.scorableCount:'?'))} scorable item${c.scorableCount===1?'':'s'}${c.itemCount!=null?` of ${e(String(c.itemCount))}`:''} · n = ${nTxt}</div></div>
          ${aBadgeFor(c)}</div>${note}</div></div>`;
    }).join('');

    // ── CAUTION: item warnings — top issues only on screen, full set → accordion ──
    const warns=(rr.itemWarnings||[]);
    const typeLbl={dead_item:'Dead item',negative_discrimination:'Reverse / off-construct',low_discrimination:'Weak item-total',floor:'Floor effect',ceiling:'Ceiling effect',redundant:'Redundant'};
    const warnRow=(w,cls)=>`<div class="callout ${cls}" style="margin-bottom:8px"><div class="ci">${SVG.info}</div>
      <div style="flex:1"><h4 style="margin:0 0 2px">${e(w.label||'(untitled item)')} <span class="pill" style="margin-left:6px">${e(typeLbl[w.type]||w.type)}</span></h4>
        <p style="margin:0">${e(w.detail||'')}${w.construct?` <span class="faint">· ${e(w.construct)}</span>`:''}</p></div></div>`;
    const errList=warns.filter(w=>w.severity==='err');
    const otherCount=warns.length-errList.length;
    let topIssues;
    if(!warns.length){
      topIssues=`<div class="callout green" style="margin-bottom:14px"><div class="ci">${SVG.check}</div><div><p style="margin:0">No item issues. Every scorable item is behaving acceptably within its construct.</p></div></div>`;
    } else if(errList.length){
      const shown=errList.slice(0,3);
      const moreErr=errList.length-shown.length;
      const moreBits=[];
      if(moreErr>0) moreBits.push(`${moreErr} more item${moreErr===1?'':'s'} needing attention`);
      if(otherCount>0) moreBits.push(`${otherCount} lower-severity note${otherCount===1?'':'s'}`);
      const moreTxt=moreBits.length?`<p style="margin:8px 0 0;font-size:12.5px;color:var(--ink-3)">${moreBits.join(' · ')} in Technical details below.</p>`:'';
      topIssues=shown.map(w=>warnRow(w,'red')).join('')+moreTxt;
    } else {
      topIssues=`<div class="callout amber" style="margin-bottom:14px"><div class="ci">${SVG.info}</div><div><p style="margin:0">${otherCount} item${otherCount===1?'':'s'} flagged for minor review — no critical issues. See Technical details below.</p></div></div>`;
    }
    // Full warnings (all severities) for the accordion.
    const sevMeta={err:{cls:'red',lbl:'Needs attention'},warn:{cls:'amber',lbl:'Caution'},info:{cls:'gray',lbl:'Note'}};
    const warnBlockFull=!warns.length
      ? `<p style="margin:0;color:var(--ink-2);font-size:13px">No item warnings.</p>`
      : ['err','warn','info'].map(sev=>{
          const list=warns.filter(w=>w.severity===sev); if(!list.length) return '';
          const m=sevMeta[sev];
          const rows=list.map(w=>warnRow(w,m.cls==='gray'?'':m.cls)).join('');
          return `<div style="margin-bottom:6px"><div class="faint" style="font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:.04em;margin:0 0 6px">${m.lbl} (${list.length})</div>${rows}</div>`;
        }).join('');

    // Items deliberately not reliability-scored (accordion).
    const excluded=(rr.excludedItems||[]);
    const excludedBlock=excluded.length
      ? `<div style="margin-top:14px"><div style="font-size:12.5px;font-weight:700;margin-bottom:6px">Not reliability-scored <span class="faint" style="font-weight:500">· open-text and unordered-choice items, reported not as problems</span></div>
          <table class="tbl"><thead><tr><th>Item</th><th>Field type</th><th>Why</th></tr></thead><tbody>${
            excluded.map(x=>`<tr><td>${e(x.label||'(untitled)')}</td><td>${e(x.fieldType||'')}</td><td style="color:var(--ink-3)">${e(x.reason||'')}</td></tr>`).join('')
          }</tbody></table></div>`
      : '';

    // Engine summary sentence (kept in the accordion so nothing is lost).
    const summaryBlock=rr.summary
      ? `<p style="margin:0 0 12px;font-size:13px;color:var(--ink-2)"><b>Engine summary:</b> ${e(rr.summary)}</p>`
      : '';

    // Static "How RSSI is scored" methods note (no recompute — describes the engine).
    const methodsBody=`<p style="margin:0 0 8px;font-size:13px;color:var(--ink-2)">RSSI sums four domains over your analyzable responses into a 100-point index: <b>Internal Consistency</b> (35), <b>Item Performance</b> (25), <b>Response Quality</b> (20), and <b>Score Interpretability</b> (20).</p>
      <p style="margin:0 0 8px;font-size:13px;color:var(--ink-2)">Internal consistency uses Cronbach's alpha computed per construct over complete-case responses. A sample-size fence withholds the whole score below ${e(String(fc.minN||30))} analyzable responses, and a construct is left unscored when it has fewer than 3 scorable items or fewer than 10 complete cases.</p>
      <p style="margin:0;font-size:13px;color:var(--ink-2)">Open-text and unordered-choice items are reported separately, not scored for reliability. RSSI measures reliability, which is not the same as validity.</p>`;

    // Full diagnostics accordion body.
    const diagParts=[];
    if(summaryBlock) diagParts.push(summaryBlock);
    if(!withheld && domainEvidence) diagParts.push(`<h4 style="margin:0 0 6px;font-size:13px;font-weight:700">Domain evidence</h4>${domainEvidence}`);
    diagParts.push(`<h4 style="margin:14px 0 6px;font-size:13px;font-weight:700">Construct detail</h4>${constructDeep||'<p style="margin:0;color:var(--ink-2);font-size:13px">No constructs.</p>'}`);
    if(!withheld) diagParts.push(`<h4 style="margin:14px 0 6px;font-size:13px;font-weight:700">All item warnings</h4>${warnBlockFull}`);
    if(excludedBlock) diagParts.push(excludedBlock);
    const detailGuidance=Screens.rssiGuidance(rr,'detail');
    if(detailGuidance) diagParts.push(`<div style="margin-top:14px"><h4 style="margin:0 0 6px;font-size:13px;font-weight:700">Detailed guidance</h4>${detailGuidance}</div>`);

    // ── assemble: Score → Meaning → Fence → Evidence → Caution → Next → Technical ──
    return `<div class="card" style="margin-bottom:18px;overflow:hidden">
      <div style="padding:14px 20px;border-bottom:1px solid var(--line);display:flex;justify-content:space-between;align-items:center;gap:14px">
        <div><h3 style="margin:0;font-size:15px;font-weight:700">ReliCheck Survey Strength Index</h3>
          <div class="faint" style="font-size:12.5px;margin-top:2px">${e(savedTxt)}</div></div>
        ${headerBtns}</div>
      <div style="padding:14px 20px 0">${staleBanner}</div>
      ${hero}
    </div>
    ${fencePanel}
    ${withheld?'':`<h3 class="sec" style="font-size:14px;margin:0 0 10px">Domain scores</h3>
      <div class="grid g4" style="margin-bottom:18px">${domainCardsCompact}</div>`}
    <h3 class="sec" style="font-size:14px;margin:0 0 10px">Construct reliability</h3>
    ${constructBlock}
    ${withheld?'':`<h3 class="sec" style="font-size:14px;margin:18px 0 10px">Top issues</h3>${topIssues}`}
    <h3 class="sec" style="font-size:14px;margin:18px 0 10px">Interpreting this result</h3>
    ${Screens.rssiGuidance(rr,'trio')}
    <h3 class="sec" style="font-size:14px;margin:18px 0 10px">Technical details</h3>
    ${exp('Full diagnostics', diagParts.join(''), forPrint)}
    ${exp('How RSSI is scored', methodsBody, forPrint)}`;
  },

  // ── Phase 4E: plain-language interpretation guidance ───────────────────────
  // Turns the existing RSSIEngine result into "what this means / what you can
  // responsibly say / what not to overclaim / what to do next". It READS ONLY the
  // result object (no recompute, no new model, no AI) so every sentence is backed
  // by a value the engine already produced. It keeps reliability evidence separate
  // from descriptive/categorical evidence and withholds claims when the score was
  // withheld.
  // mode: 'meaning' → one-line band sentence (for the hero); 'trio' → the three
  // visible guidance cards (what you can say / not overclaim / what to do next);
  // 'detail' → the deeper domain/construct/item guidance (rendered in the accordion).
  // READS ONLY the result object; no recompute, no new model, no AI.
  rssiGuidance(rr, mode){
    const e=escapeHtml;
    const withheld=(rr.score===null);
    const fc=rr.fence||{};
    const li=arr=>`<ul style="margin:6px 0 0;padding-left:18px;font-size:13px;color:var(--ink-2)">${arr.map(x=>`<li style="margin:0 0 4px">${x}</li>`).join('')}</ul>`;
    const card=(title,body)=>`<div class="card pad" style="margin-bottom:12px"><h4 style="margin:0 0 4px;font-size:13.5px;font-weight:700">${e(title)}</h4>${body}</div>`;

    const excluded=(rr.excludedItems||[]);
    const descriptiveNote=excluded.length
      ? `Open-text and single-choice (categorical) items are <b>not</b> part of this reliability evidence. Report those descriptively (counts, percentages, common themes); do not present them as reliable scale scores.`
      : ``;

    // ── one-line meaning (used by the hero) ────────────────────────────────
    let meaning;
    if(withheld){
      meaning = (fc.level==='no_structure')
        ? `Not enough scorable scale structure (items grouped into constructs) to judge reliability yet, even though responses came in.`
        : `Not enough analyzable data to judge reliability yet (${e(String(fc.analyzableN||0))} of a minimum ${e(String(fc.minN||30))}).`;
    } else {
      meaning = {
        confident:`Your collected data behaved reliably enough to interpret the construct scores with confidence in this sample.`,
        minor:`Your data is reliable enough to interpret, with a few cautions worth keeping in mind.`,
        caution:`There is real reliability evidence, but the scores should be interpreted cautiously.`,
        not_yet:`The data is not yet reliable enough to interpret the construct scores.`
      }[rr.bandKey] || `See the band for how confidently these scores can be interpreted.`;
    }
    if(mode==='meaning') return meaning;

    const constructs=(rr.constructs||[]);
    const strong=constructs.filter(c=>c.scored && (c.alphaBand==='excellent'||c.alphaBand==='good'||c.alphaBand==='acceptable'));
    const weak=constructs.filter(c=>c.scored && !(c.alphaBand==='excellent'||c.alphaBand==='good'||c.alphaBand==='acceptable'));
    const unscored=constructs.filter(c=>!c.scored);
    const warns=(rr.itemWarnings||[]);
    const errs=warns.filter(w=>w.severity==='err').length;
    const warnsN=warns.filter(w=>w.severity==='warn').length;
    const infos=warns.filter(w=>w.severity==='info').length;
    const domainAdvice={
      internal_consistency:`Internal consistency is the core. Make sure each construct has several items that clearly tap the same idea, and revise or drop items that pull in different directions.`,
      item_performance:`Some items track their construct weakly. Reword or replace items with low item-total correlation, and consider dropping dead or redundant items.`,
      response_quality:`Completion or straight-lining is affecting quality. Consider shortening the survey, clarifying confusing items, or screening inattentive responses.`,
      score_interpretability:`Scores pile up near the top or bottom of the scale, limiting how well they separate respondents. Consider rewording items or widening the response scale.`
    };
    const lowDomains=(rr.domains||[]).filter(d=>!d.withheld && d.fraction!=null && d.fraction<0.85 && domainAdvice[d.key]);

    // ── TRIO (visible): what you can say / not overclaim / what to do next ──
    if(mode==='trio'){
      if(withheld){
        const nextTxt=(fc.level==='no_structure')
          ? `Add scale items grouped into constructs, collect responses, then re-run RSSI.`
          : `Collect at least ${e(String(fc.minN||30))} analyzable responses (currently ${e(String(fc.analyzableN||0))}), then re-run RSSI.`;
        return card('What you can say', li([
            `You can report simple descriptive results (counts, percentages, averages) for the responses you have, clearly labeled as preliminary.`,
            descriptiveNote || `Describe what respondents said; keep claims tied to this small sample.`
          ]))
          +card('What not to overclaim', li([
            `Do not make any reliability, internal-consistency, or scale-quality claim from this data yet.`,
            `Do not treat construct scores as trustworthy until RSSI can be computed.`
          ]))
          +card('What to do next', `<p style="margin:0;font-size:13px;color:var(--ink-2)">${nextTxt}</p>`);
      }
      const canSay=[];
      if(strong.length) canSay.push(`${strong.map(c=>`<b>${e(c.name)}</b>`).join(', ')} showed acceptable-or-better internal consistency (Cronbach's alpha) in this sample, so ${strong.length===1?'its':'their'} items hang together and the construct score${strong.length===1?'':'s'} can be averaged or summed with reasonable confidence.`);
      canSay.push(`You can report the RSSI score of <b>${e(Number(rr.score).toFixed(1))} / 100</b> and its band, "${e(rr.band||rr.verdict||'')}", as a summary of how reliably this data performed.`);
      if(descriptiveNote) canSay.push(descriptiveNote);
      const dontSay=[
        `Reliability is not validity: a consistent score is not proof the items measure the intended idea correctly.`,
        `These results describe this sample of ${e(String(fc.analyzableN||0))} response${(fc.analyzableN===1)?'':'s'}; they do not guarantee the same reliability in a different group.`
      ];
      if(weak.length) dontSay.push(`Do not report ${weak.map(c=>`<b>${e(c.name)}</b>`).join(', ')} as ${weak.length===1?'a reliable scale':'reliable scales'} yet; ${weak.length===1?'its':'their'} internal consistency is below the acceptable line.`);
      const next=[];
      if(weak.length) next.push(`Review and strengthen the items in ${weak.map(c=>`<b>${e(c.name)}</b>`).join(', ')} before relying on ${weak.length===1?'it':'them'}.`);
      if(errs) next.push(`Fix or remove the ${errs} item${errs===1?'':'s'} flagged under Top issues.`);
      if(lowDomains.length) next.push(`Strengthen ${lowDomains.map(d=>`<b>${e(d.label)}</b>`).join(', ')} (see Technical details for specifics).`);
      if(!next.length) next.push(`Your data is in good shape. No changes are needed to rely on these construct scores in this sample.`);
      return card('What you can say', li(canSay))+card('What not to overclaim', li(dontSay))+card('What to do next', li(next));
    }

    // ── DETAIL (accordion): deeper domain / construct / item guidance ──────
    if(mode==='detail'){
      if(withheld){
        return `<p style="margin:0;font-size:13px;color:var(--ink-2)">${descriptiveNote||`No additional reliability detail is available until RSSI can be computed.`}</p>`;
      }
      const domainActions=lowDomains.map(d=>`<b>${e(d.label)} (${e(Number(d.points).toFixed(1))}/${e(String(d.max))}):</b> ${domainAdvice[d.key]}`);
      const constructGuide=[];
      strong.forEach(c=>{
        const lvl=(c.alphaBand==='acceptable')
          ? `acceptable internal consistency (α ${e(String(c.alpha))}). Reportable; minor item refinement could strengthen it.`
          : `strong internal consistency (α ${e(String(c.alpha))}). Safe to report as a reliable scale.`;
        constructGuide.push(`<b>${e(c.name)}:</b> ${lvl}`);
      });
      weak.forEach(c=>constructGuide.push(`<b>${e(c.name)}:</b> internal consistency is ${e(c.alphaBand||'below acceptable')} (α ${e(String(c.alpha))}). Interpret its scores cautiously and review its items before relying on them.`));
      unscored.forEach(c=>constructGuide.push(`<b>${e(c.name)}:</b> not enough evidence to judge. ${e(c.note||'Reliability was withheld for this construct.')}`));
      const itemGuide=[];
      if(errs) itemGuide.push(`${errs} item${errs===1?'':'s'} need attention (dead items, or items that run counter to their construct). Fix or remove ${errs===1?'it':'them'} before relying on the scores.`);
      if(warnsN) itemGuide.push(`${warnsN} item${warnsN===1?'':'s'} align weakly with ${warnsN===1?'its':'their'} construct. Consider rewording ${warnsN===1?'it':'them'}.`);
      if(infos) itemGuide.push(`${infos} note${infos===1?'':'s'} about floor/ceiling clustering or redundancy. Minor, but worth a look if you revise.`);
      if(!itemGuide.length) itemGuide.push(`No item-level problems were flagged. The scorable items behaved acceptably.`);
      return card('Domain-specific next actions', domainActions.length?li(domainActions):`<p style="margin:0;font-size:13px;color:var(--ink-2)">Every domain is performing well. No domain-level changes are needed.</p>`)
        +card('Construct-specific guidance', li(constructGuide.length?constructGuide:[`No constructs were available to evaluate.`]))
        +card('Item-warning guidance', li(itemGuide));
    }

    return '';
  },
};

/* ════════════════════════════════════════════════════════════════════
   RENDER. Stepper rail + active screen
   ════════════════════════════════════════════════════════════════════ */
function railView(){
  // One rail, always the full development pipeline. The project mode controls
  // routing (where an upload lands, where a reopened project opens), NOT which
  // steps are visible — every project shows the complete pipeline.
  const STEPS = STEPS_BUILD;
  // Map sub-routes back onto a stepper step. The dataset screen lives under the
  // final 'analysis' (RSSI / Studios) step.
  const routeStep = { templates:'start', 'pick-existing':'start', setup:'setup', build:'build',
        sdsi:'sdsi', revise:'revise', preview:'preview', siri:'siri', publish:'publish', deploy:'deploy',
        retrieve:'retrieve', analysis:'analysis', dataset:'analysis', start:'start' };
  const active = routeStep[state.route]||'start';
  const order = STEPS.map(s=>s.id);
  const activeIdx = order.indexOf(active);
  return STEPS.map((s,i)=>{
    const done = state.reached[s.id] && i<activeIdx;
    const isActive = s.id===active;
    return `<button class="step" data-active="${isActive?1:0}" data-done="${done?1:0}" onclick="App.go('${s.id}')">
      <span class="num">${done?SVG.check:i+1}</span>
      <span class="lbl">${s.lbl}</span>
      <span class="tick">${SVG.check}</span>
    </button>`;
  }).join('');
}

function render(){
  const r=state.route;
  // context chip in top bar once a study exists
  const ctx=$('#ctx');
  if(state.survey||state.study.name){ ctx.style.display='flex'; $('#ctxName').textContent = state.survey?.title || state.study.name || 'New study'; }
  else { ctx.style.display='none'; }
  updateModeBadge();
  // rail
  const railFoot = PERSIST.degraded
    ? 'Mock mode (database unavailable). Changes are not being saved.'
    : 'SDSI Build Check (50) → SIRI Launch Check (100) → RSSI &amp; Studios downstream.';
  $('#rail').innerHTML = `<div class="rail-h">Development pipeline</div>${railView()}
    <div class="rail-foot">${railFoot}</div>`;
  // degraded-mode banner (db requested but unavailable → mock fallback)
  const banner = PERSIST.degraded
    ? `<div class="degraded">${SVG.info}<span>Database mode was requested but is unavailable (${PERSIST.reason}). Running on mock data. Changes are not being saved.</span></div>`
    : '';
  // screen
  const fn = Screens[r] || Screens.start;
  const appEl = $('#app');
  appEl.className = 'wrap' + (r === 'build' ? ' wrap-build' : '');
  appEl.innerHTML = banner + fn();
  App._focusAfterRender();
}

// Boot: probe the server for auth + templates. Always lands on the start screen
// so a page reload never drops the user into a stale project workspace.
// Projects are re-opened intentionally via "Work on a Saved Survey" on the start screen.
async function boot(){
  const _start = new URLSearchParams(location.search).get('start');
  // Consume the ?start hint ONCE, then strip it from the address bar so a later
  // hard-reload returns to the Start screen instead of re-firing the same entry.
  if(_start){ try { history.replaceState(null, '', location.pathname); } catch(e){} }
  if(PERSIST.on){
    try {
      const t=await DB.call('templates-list.php');
      state.remoteTemplates=t.templates;
    } catch(e){ degrade(e.message); }
  }
  // Entry hint from the Survey Development System landing (survey-dev.php):
  // ?start=scratch|existing|template|ai-build|ai-assist routes the user straight
  // to that entry. 'import' is intentionally NOT here: bringing in an existing
  // survey is now the upload widget on the Start screen, not a paste screen.
  const _validStart = ['scratch','existing','template','ai-build','ai-assist'];
  if(_start && _validStart.includes(_start)){ App.setEntry(_start); return; }
  render();
}

boot();
</script>

<script>
(function(){
  var btn  = document.getElementById('devUserBtn');
  var menu = document.getElementById('devUserMenu');
  if (!btn || !menu) return;
  btn.addEventListener('click', function(e) {
    e.stopPropagation();
    var open = menu.classList.toggle('open');
    btn.setAttribute('aria-expanded', String(open));
  });
  document.addEventListener('click', function() {
    menu.classList.remove('open');
    btn.setAttribute('aria-expanded', 'false');
  });
  document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape' && menu.classList.contains('open')) {
      menu.classList.remove('open');
      btn.setAttribute('aria-expanded', 'false');
      btn.focus();
    }
  });
  var so = document.getElementById('devSignOut');
  if (so) so.addEventListener('click', function(e) {
    e.preventDefault();
    fetch('/api/auth/logout.php', {
      method: 'POST', credentials: 'same-origin',
      headers: { 'X-Requested-With': 'XMLHttpRequest' }
    }).finally(function() { window.location.href = '/login.html'; });
  });
})();
</script>
</body>
</html>
