<?php
// rsci.php — ReliCheck Survey Checker Index (RSCI).
// Pre-deployment mirror of RSSI: scores a survey's INSTRUMENT DESIGN
// (validity + reliability) before any response data exists.
// Deterministic engine: /apps/rsci/rsci-engine.js. Mirrors rssi.php styling.

require_once __DIR__ . '/api/_db.php';
require_once __DIR__ . '/api/_session.php';

start_session_secure();
$uid = current_user_id();
if (!$uid) {
    header('Location: /login.html?return=' . urlencode('/rsci.php'));
    exit;
}
$rsci_user = current_user();
if (!$rsci_user) { $_SESSION = []; session_destroy(); header('Location: /login.html'); exit; }

$rsci_user_full = $rsci_user['name'] ?? $rsci_user['email'] ?? 'You';
$rsci_initials  = strtoupper(substr(preg_replace('/[^A-Za-z]/', '', $rsci_user_full) ?: 'U', 0, 2));
$preselect_id   = isset($_GET['id']) ? max(0, (int)$_GET['id']) : 0;
?><!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>ReliCheck Survey Checker Index</title>
  <link rel="icon" type="image/png" href="/RSSI-logo.png">
  <style>
    * { box-sizing: border-box; }
    :root {
      --bg:#F6F7F9; --surface:#FFFFFF;
      --hairline:rgba(15,23,42,0.06); --hairline-2:rgba(15,23,42,0.10);
      --text:#15171a; --text-2:#5f6368; --text-3:#8E8E93;
      --blue:#2D8DFF; --blue-hi:#4FA3FF; --blue-deep:#0A6FE8; --blue-soft-bg:#EEF3FA;
      --good:#34C759; --purple:#7856DC; --warn:#FF9F0A; --bad:#FF3B30;
      --radius-pill:999px;
      --shadow-sm:0 1px 3px rgba(15,23,42,0.05);
      --shadow-md:0 4px 16px rgba(15,23,42,0.06);
    }
    html,body{margin:0;padding:0;min-height:100vh;background:var(--bg);color:var(--text);
      font-family:-apple-system,BlinkMacSystemFont,"SF Pro Text","SF Pro Display","Helvetica Neue",Inter,system-ui,sans-serif;
      letter-spacing:-0.005em;-webkit-font-smoothing:antialiased;}

    .rsci-head{display:flex;align-items:center;justify-content:space-between;padding:16px 36px;
      border-bottom:1px solid var(--hairline);background:rgba(255,255,255,0.85);
      backdrop-filter:blur(14px);-webkit-backdrop-filter:blur(14px);position:sticky;top:0;z-index:50;}
    .rsci-brand{display:flex;align-items:center;gap:12px;text-decoration:none;color:inherit;}
    .rsci-brand-logo{height:120px;width:auto;display:block;}
    .rsci-head-right{display:flex;align-items:center;gap:14px;}
    .rsci-app-pill{padding:7px 14px;border-radius:var(--radius-pill);background:#EEF3FA;
      color:var(--blue-deep);font-size:13px;font-weight:600;}
    .rsci-user{width:34px;height:34px;border-radius:50%;background:#EEF0F3;border:1px solid var(--hairline-2);
      display:grid;place-items:center;color:var(--text);font-size:12.5px;font-weight:600;text-decoration:none;}
    .rsci-user:hover{background:#E4E6EA;}

    .rsci-page{max-width:1180px;margin:0 auto;padding:56px 32px 80px;}

    .rsci-hero{text-align:center;max-width:1040px;margin:0 auto 44px;}
    .rsci-hero h1{font-size:72px;font-weight:800;letter-spacing:-0.035em;line-height:1.05;margin:0 0 28px;}
    .rsci-hero h1 .accent{color:var(--blue);display:block;}
    .rsci-hero p.lede{font-size:18px;color:var(--text-2);line-height:1.55;margin:0 auto;max-width:62ch;}

    .cta-row{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:14px;max-width:900px;margin:0 auto 56px;}
    .cta-tile{display:flex;align-items:center;gap:14px;padding:18px 22px;border-radius:16px;background:var(--surface);
      border:1px solid var(--hairline);box-shadow:var(--shadow-sm);text-decoration:none;color:var(--text);
      transition:transform .15s,box-shadow .15s,border-color .15s;cursor:pointer;}
    .cta-tile:hover{transform:translateY(-2px);box-shadow:var(--shadow-md);border-color:rgba(45,141,255,.30);}
    .cta-tile.primary{background:linear-gradient(160deg,#2D8DFF 0%,#1075E0 100%);border-color:transparent;color:#fff;
      box-shadow:0 6px 20px rgba(0,80,180,.28);}
    .cta-tile-icon{width:38px;height:38px;border-radius:11px;background:#EEF3FA;color:var(--blue);
      display:grid;place-items:center;flex-shrink:0;}
    .cta-tile.primary .cta-tile-icon{background:rgba(255,255,255,.22);color:#fff;}
    .cta-tile-icon svg{width:18px;height:18px;}
    .cta-tile-title{font-size:15px;font-weight:700;letter-spacing:-0.01em;line-height:1.2;}
    .cta-tile-sub{font-size:12.5px;color:var(--text-2);margin-top:2px;}
    .cta-tile.primary .cta-tile-sub{color:rgba(255,255,255,.85);}

    /* ── Report card ── */
    .report-card{background:var(--surface);border:1px solid var(--hairline);border-radius:22px;
      padding:42px 48px 44px;box-shadow:var(--shadow-md);max-width:940px;margin:0 auto;}
    .report-eyebrow{text-align:center;font-size:11.5px;font-weight:700;letter-spacing:.14em;
      text-transform:uppercase;color:var(--blue-deep);margin-bottom:8px;}
    .report-title{text-align:center;font-size:24px;font-weight:700;letter-spacing:-0.015em;margin:0 0 4px;}
    .report-sub{text-align:center;font-size:13.5px;color:var(--text-2);margin:0 0 30px;}

    /* ── Two dimensions, each with a ring + its two check-group cards ── */
    .dim-grid{display:grid;grid-template-columns:1fr 1fr;gap:20px;}
    .dim-block{background:#FAFBFC;border:1px solid var(--hairline);border-radius:18px;padding:26px 22px 24px;}
    .dim-name{text-align:center;font-size:13px;font-weight:700;letter-spacing:.10em;text-transform:uppercase;
      color:var(--text-2);margin-bottom:14px;}
    .ring-wrap{display:flex;justify-content:center;margin:0 auto 12px;}
    .ring{position:relative;width:150px;height:150px;}
    .ring svg{display:block;width:100%;height:100%;transform:rotate(-90deg);}
    .ring-text{position:absolute;inset:0;display:grid;place-items:center;text-align:center;}
    .ring-text .num{font-size:42px;font-weight:700;letter-spacing:-0.04em;line-height:1;}
    .ring-text .out{font-size:11px;color:var(--text-2);margin-top:4px;}
    .tier-pill{display:block;text-align:center;margin:0 auto 20px;width:max-content;
      padding:5px 14px;border-radius:var(--radius-pill);font-size:12.5px;font-weight:700;}
    .tier-ready{background:rgba(52,199,89,.14);color:#1f9e44;}
    .tier-flag{background:rgba(255,159,10,.16);color:#c47700;}
    .tier-not_ready{background:rgba(255,59,48,.12);color:#c4271f;}

    .group-cards{display:grid;grid-template-columns:1fr 1fr;gap:12px;}
    .metric-card{background:var(--surface);border:1px solid var(--hairline);border-radius:14px;padding:14px 13px 16px;}
    .metric-icon{width:32px;height:32px;border-radius:9px;display:grid;place-items:center;margin-bottom:10px;}
    .metric-icon.green{background:rgba(52,199,89,.12);color:#2BAE51;}
    .metric-icon.purple{background:rgba(120,86,220,.12);color:#7856DC;}
    .metric-icon.blue{background:rgba(0,122,255,.12);color:#0A6FE8;}
    .metric-icon.orange{background:rgba(255,159,10,.14);color:#E08800;}
    .metric-icon svg{width:16px;height:16px;}
    .metric-label{font-size:12.5px;font-weight:600;line-height:1.3;margin-bottom:8px;min-height:32px;}
    .metric-score{display:flex;align-items:baseline;gap:4px;margin-bottom:9px;}
    .metric-score .pct{font-size:24px;font-weight:700;letter-spacing:-0.025em;line-height:1;}
    .metric-score .pct-sym{font-size:12px;color:var(--text-3);font-weight:500;}
    .metric-bar{height:4px;border-radius:999px;background:rgba(15,23,42,.06);overflow:hidden;}
    .metric-bar>i{display:block;height:100%;border-radius:inherit;}
    .metric-bar.green>i{background:linear-gradient(90deg,#34C759,#2BAE51);}
    .metric-bar.purple>i{background:linear-gradient(90deg,#9476ED,#7856DC);}
    .metric-bar.blue>i{background:linear-gradient(90deg,#4FA3FF,#007AFF);}
    .metric-bar.orange>i{background:linear-gradient(90deg,#FFB23B,#FF9F0A);}
    .fence-note{max-width:940px;margin:14px auto 0;text-align:center;font-size:12px;color:var(--text-3);line-height:1.5;}

    /* ── Findings ── */
    .findings{max-width:940px;margin:28px auto 0;}
    .findings-head{display:flex;align-items:baseline;justify-content:space-between;margin:0 0 14px;padding:0 4px;}
    .findings-head h3{font-size:18px;font-weight:700;letter-spacing:-0.015em;margin:0;}
    .findings-head .counts{font-size:13px;color:var(--text-2);}
    .finding{background:var(--surface);border:1px solid var(--hairline);border-radius:14px;
      padding:16px 18px;margin-bottom:10px;box-shadow:var(--shadow-sm);}
    .finding-top{display:flex;align-items:center;gap:10px;margin-bottom:6px;}
    .sev{font-size:10.5px;font-weight:700;letter-spacing:.06em;text-transform:uppercase;
      padding:3px 9px;border-radius:999px;flex-shrink:0;}
    .sev-critical{background:rgba(255,59,48,.12);color:#c4271f;}
    .sev-major{background:rgba(255,159,10,.16);color:#c47700;}
    .sev-minor{background:rgba(45,141,255,.12);color:var(--blue-deep);}
    .sev-info{background:rgba(120,86,220,.12);color:#6a4fc0;}
    .finding-dim{font-size:11px;font-weight:600;color:var(--text-3);text-transform:uppercase;letter-spacing:.05em;}
    .finding-q{font-size:12.5px;color:var(--text-3);font-weight:600;}
    .finding-label{font-size:14.5px;font-weight:600;line-height:1.4;margin:0 0 4px;}
    .finding-fix{font-size:13.5px;color:var(--text-2);line-height:1.45;}
    .finding-fix b{color:var(--text);font-weight:600;}
    .findings-clear{text-align:center;padding:32px 20px;color:var(--text-2);font-size:14px;}
    .findings-clear strong{display:block;color:#1f9e44;font-size:16px;margin-bottom:4px;}

    .report-actions{max-width:940px;margin:24px auto 0;display:flex;gap:12px;justify-content:center;}
    .btn{padding:11px 20px;border-radius:12px;font-size:14px;font-weight:600;text-decoration:none;cursor:pointer;
      border:1px solid var(--hairline-2);background:var(--surface);color:var(--text);transition:background .15s;}
    .btn:hover{background:#F2F4F7;}
    .btn.primary{background:linear-gradient(160deg,#2D8DFF,#1075E0);border-color:transparent;color:#fff;}
    .btn.primary:hover{filter:brightness(1.05);}

    .rsci-foot{max-width:1180px;margin:0 auto;padding:24px 32px 28px;display:flex;align-items:center;
      justify-content:space-between;font-size:12.5px;color:var(--text-3);border-top:1px solid var(--hairline);}
    .rsci-foot a{color:var(--text-2);text-decoration:none;margin-left:28px;}
    .rsci-foot a:hover{color:var(--text);}

    /* ── Picker modal ── */
    .rsci-modal-backdrop{position:fixed;inset:0;background:rgba(15,23,42,.45);backdrop-filter:blur(6px);
      -webkit-backdrop-filter:blur(6px);z-index:200;display:flex;align-items:center;justify-content:center;padding:24px;}
    .rsci-modal-backdrop[hidden]{display:none;}
    .rsci-modal{width:100%;max-width:540px;background:#fff;border-radius:22px;padding:32px 32px 28px;
      box-shadow:0 22px 60px rgba(15,23,42,.28);position:relative;}
    .rsci-modal-close{position:absolute;top:16px;right:16px;width:32px;height:32px;border-radius:50%;
      background:#F0F1F3;border:none;cursor:pointer;color:var(--text-2);display:grid;place-items:center;}
    .rsci-modal-close:hover{background:#E4E6EA;color:var(--text);}
    .rsci-modal-close svg{width:14px;height:14px;}
    .rsci-modal h2{font-size:22px;font-weight:700;letter-spacing:-0.015em;margin:0 0 6px;}
    .rsci-modal p.modal-lede{font-size:14px;color:var(--text-2);margin:0 0 22px;line-height:1.5;}
    .picker-list{display:flex;flex-direction:column;gap:8px;max-height:52vh;overflow-y:auto;margin:0 -8px;padding:0 8px;}
    .picker-row{display:grid;grid-template-columns:1fr auto;align-items:center;gap:14px;padding:14px;
      background:#FAFBFC;border:1px solid var(--hairline);border-radius:12px;text-align:left;cursor:pointer;
      transition:background .15s,border-color .15s,transform .15s;width:100%;font:inherit;color:inherit;}
    .picker-row:hover{background:#F2F4F7;border-color:var(--hairline-2);transform:translateX(2px);}
    .picker-name{font-size:14px;font-weight:600;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}
    .picker-meta{font-size:12px;color:var(--text-2);margin-top:2px;}
    .picker-arrow{width:30px;height:30px;border-radius:50%;background:#EEF0F3;color:var(--text-2);
      display:grid;place-items:center;}
    .picker-arrow svg{width:12px;height:12px;}
    .picker-empty{text-align:center;padding:36px 20px;color:var(--text-2);font-size:13.5px;}
    .picker-empty strong{display:block;color:var(--text);margin-bottom:4px;font-size:15px;}

    @media (max-width:1100px){.rsci-hero h1{font-size:56px;}}
    @media (max-width:880px){.cta-row{grid-template-columns:1fr;}.dim-grid{grid-template-columns:1fr;}
      .rsci-hero h1{font-size:42px;}.report-card{padding:32px 24px;}}
    @media (max-width:560px){.rsci-hero h1{font-size:32px;}.group-cards{grid-template-columns:1fr;}
      .rsci-app-pill{display:none;}.rsci-head{padding:14px 18px;}.rsci-page{padding:36px 18px 56px;}}
  </style>
</head>
<body data-preselect="<?= (int)$preselect_id ?>">

<header class="rsci-head">
  <a class="rsci-brand" href="/app-2026v4.php" title="Back to ReliCheck">
    <img src="/RSSI-logo.png" alt="ReliCheck" class="rsci-brand-logo">
  </a>
  <div class="rsci-head-right">
    <span class="rsci-app-pill">Survey Checker Index</span>
    <a class="rsci-user" href="#" title="<?= htmlspecialchars($rsci_user_full) ?>"><?= htmlspecialchars($rsci_initials) ?></a>
  </div>
</header>

<main class="rsci-page">

  <!-- Intro (hidden once a report renders) -->
  <section class="rsci-hero" id="introHero">
    <h1>Catch the flaws<span class="accent">before respondents do.</span></h1>
    <p class="lede">The ReliCheck Survey Checker Index reviews every question, scale, and the survey's overall flow &mdash;
      then scores its predicted <strong>validity</strong> and <strong>reliability</strong> so you fix problems while you
      can still change them.</p>
  </section>

  <div class="cta-row" id="introCtas">
    <button class="cta-tile primary" type="button" id="ctaAssess">
      <span class="cta-tile-icon">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 11l3 3 8-8"/><path d="M20 12v6a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h9"/></svg>
      </span>
      <span class="cta-tile-text"><div class="cta-tile-title">Check a Survey</div>
        <div class="cta-tile-sub">Score one you're building</div></span>
    </button>
    <a class="cta-tile" href="/survey-builder.php">
      <span class="cta-tile-icon">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4z"/></svg>
      </span>
      <span class="cta-tile-text"><div class="cta-tile-title">Open the Builder</div>
        <div class="cta-tile-sub">Edit your survey</div></span>
    </a>
    <button class="cta-tile" type="button" id="ctaDemo">
      <span class="cta-tile-icon">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
      </span>
      <span class="cta-tile-text"><div class="cta-tile-title">See a Sample</div>
        <div class="cta-tile-sub">Example with flagged items</div></span>
    </button>
  </div>

  <!-- Report (filled by JS) -->
  <section class="report-card" id="reportCard" hidden>
    <div class="report-eyebrow">ReliCheck Survey Checker Index</div>
    <h2 class="report-title" id="reportTitle">Survey Checker Index</h2>
    <p class="report-sub" id="reportSub"></p>

    <div class="dim-grid">
      <!-- VALIDITY -->
      <div class="dim-block">
        <div class="dim-name">Validity</div>
        <div class="ring-wrap"><div class="ring">
          <svg viewBox="0 0 200 200">
            <defs><linearGradient id="ringGradV" x1="0" y1="0" x2="1" y2="1">
              <stop offset="0%" stop-color="#4FA3FF"/><stop offset="100%" stop-color="#0A6FE8"/></linearGradient></defs>
            <circle cx="100" cy="100" r="86" fill="none" stroke="#EAEDF1" stroke-width="14"/>
            <circle id="ringArcV" cx="100" cy="100" r="86" fill="none" stroke="url(#ringGradV)" stroke-width="14"
                    stroke-linecap="round" stroke-dasharray="540.35" stroke-dashoffset="540.35"/>
          </svg>
          <div class="ring-text"><div><div class="num" id="ringNumV">0</div><div class="out">/ 100</div></div></div>
        </div></div>
        <span class="tier-pill" id="tierPillV"></span>
        <div class="group-cards">
          <div class="metric-card">
            <div class="metric-icon green"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="8 12 11 15 16 9"/></svg></div>
            <div class="metric-label">Question<br>quality</div>
            <div class="metric-score"><span class="pct" id="mQuality">0</span><span class="pct-sym">%</span></div>
            <div class="metric-bar green"><i id="barQuality" style="width:0%"></i></div>
          </div>
          <div class="metric-card">
            <div class="metric-icon purple"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2v20"/><path d="M2 7l10-5 10 5"/><path d="M4 10v8h16v-8"/></svg></div>
            <div class="metric-label">Construct<br>coverage</div>
            <div class="metric-score"><span class="pct" id="mCoverage">0</span><span class="pct-sym">%</span></div>
            <div class="metric-bar purple"><i id="barCoverage" style="width:0%"></i></div>
          </div>
        </div>
      </div>

      <!-- RELIABILITY -->
      <div class="dim-block">
        <div class="dim-name">Reliability</div>
        <div class="ring-wrap"><div class="ring">
          <svg viewBox="0 0 200 200">
            <defs><linearGradient id="ringGradR" x1="0" y1="0" x2="1" y2="1">
              <stop offset="0%" stop-color="#9476ED"/><stop offset="100%" stop-color="#7856DC"/></linearGradient></defs>
            <circle cx="100" cy="100" r="86" fill="none" stroke="#EAEDF1" stroke-width="14"/>
            <circle id="ringArcR" cx="100" cy="100" r="86" fill="none" stroke="url(#ringGradR)" stroke-width="14"
                    stroke-linecap="round" stroke-dasharray="540.35" stroke-dashoffset="540.35"/>
          </svg>
          <div class="ring-text"><div><div class="num" id="ringNumR">0</div><div class="out">/ 100</div></div></div>
        </div></div>
        <span class="tier-pill" id="tierPillR"></span>
        <div class="group-cards">
          <div class="metric-card">
            <div class="metric-icon blue"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="12" width="4" height="9" rx="1"/><rect x="10" y="6" width="4" height="15" rx="1"/><rect x="17" y="14" width="4" height="7" rx="1"/></svg></div>
            <div class="metric-label">Scale<br>strength</div>
            <div class="metric-score"><span class="pct" id="mScale">0</span><span class="pct-sym">%</span></div>
            <div class="metric-bar blue"><i id="barScale" style="width:0%"></i></div>
          </div>
          <div class="metric-card">
            <div class="metric-icon orange"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg></div>
            <div class="metric-label">Survey<br>flow</div>
            <div class="metric-score"><span class="pct" id="mFlow">0</span><span class="pct-sym">%</span></div>
            <div class="metric-bar orange"><i id="barFlow" style="width:0%"></i></div>
          </div>
        </div>
      </div>
    </div>
  </section>

  <p class="fence-note" id="fenceNote" hidden>Internal consistency and items-per-construct count toward reliability only &mdash; never validity.</p>

  <section class="findings" id="findings" hidden>
    <div class="findings-head"><h3>What to fix</h3><span class="counts" id="findingsCounts"></span></div>
    <div id="findingsList"></div>
  </section>

  <div class="report-actions" id="reportActions" hidden>
    <a class="btn primary" id="editLink" href="/survey-builder.php">Open in Builder</a>
    <button class="btn" type="button" id="assessAnother">Check another survey</button>
  </div>

</main>

<footer class="rsci-foot">
  <div>&copy; <?= date('Y') ?> ReliCheck. All rights reserved.</div>
  <nav><a href="/help/">Help Center</a><a href="/privacy.html">Privacy Policy</a><a href="/terms.html">Terms of Use</a></nav>
</footer>

<!-- Picker modal -->
<div class="rsci-modal-backdrop" id="pickerModal" hidden role="dialog" aria-modal="true" aria-labelledby="pickerTitle">
  <div class="rsci-modal">
    <button class="rsci-modal-close" type="button" data-close-modal aria-label="Close">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6L6 18"/><path d="M6 6l12 12"/></svg>
    </button>
    <h2 id="pickerTitle">Choose a survey to check</h2>
    <p class="modal-lede">Pick a survey you're building. The ReliCheck Survey Checker Index scores its design instantly &mdash; no responses needed.</p>
    <div class="picker-list" id="pickerList"></div>
  </div>
</div>

<script src="/apps/rsci/rsci-engine.js"></script>
<script>
(function () {
  'use strict';
  function $(id){ return document.getElementById(id); }
  function esc(s){ return String(s==null?'':s).replace(/[&<>"']/g,function(c){return ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'})[c];}); }

  var CIRC = 540.35;

  function openPicker(){
    var m = $('pickerModal'); m.hidden = false; document.body.style.overflow='hidden';
    var list = $('pickerList');
    list.innerHTML = '<div class="picker-empty"><strong>Loading your surveys…</strong></div>';
    fetch('/api/surveys/list.php',{credentials:'same-origin',headers:{'Accept':'application/json'}})
      .then(function(r){ return r.ok ? r.json() : null; })
      .then(function(data){
        var rows = (data && data.surveys) || [];
        rows = rows.filter(function(s){ return !s.archived_at; });
        if(!rows.length){ list.innerHTML='<div class="picker-empty"><strong>No surveys yet</strong>Build one in the Survey Builder, then come back to check it.</div>'; return; }
        list.innerHTML = rows.map(function(s){
          var n = s.item_count || 0;
          var when = s.updated_at ? new Date(s.updated_at).toLocaleDateString() : '';
          var meta = n + ' item' + (n===1?'':'s') + (when?(' · '+esc(when)):'');
          return '<button class="picker-row" type="button" data-id="'+s.id+'">'+
            '<span><span class="picker-name">'+esc(s.title||'Untitled survey')+'</span>'+
            '<span class="picker-meta">'+meta+'</span></span>'+
            '<span class="picker-arrow"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14"/><path d="M13 6l6 6-6 6"/></svg></span>'+
          '</button>';
        }).join('');
        list.querySelectorAll('.picker-row').forEach(function(b){
          b.addEventListener('click', function(){ closeModal(); loadAndAssess(+b.getAttribute('data-id')); });
        });
      })
      .catch(function(){ list.innerHTML='<div class="picker-empty"><strong>Could not load surveys.</strong>Check your connection and try again.</div>'; });
  }
  function closeModal(){ $('pickerModal').hidden = true; document.body.style.overflow=''; }

  function questionsFrom(survey){
    if(!survey) return [];
    var bq = survey.settings && Array.isArray(survey.settings.builderQuestions) ? survey.settings.builderQuestions : null;
    if(bq && bq.length) return bq;
    return Array.isArray(survey.questions) ? survey.questions : [];
  }

  function loadAndAssess(id){
    if(!id) return;
    fetch('/api/surveys/get.php?id='+id,{credentials:'same-origin',headers:{'Accept':'application/json'}})
      .then(function(r){ return r.ok ? r.json() : null; })
      .then(function(data){
        var sv = data && data.survey;
        if(!sv){ alert('Could not load that survey.'); return; }
        render(sv.title || 'Untitled survey', id, questionsFrom(sv));
      })
      .catch(function(){ alert('Could not load that survey — check your connection.'); });
  }

  function render(title, id, questions){
    var res = window.RSCIEngine.assess(questions);

    $('introHero').hidden = true;
    $('introCtas').hidden = true;
    $('reportCard').hidden = false;
    $('fenceNote').hidden = false;
    $('findings').hidden = false;
    $('reportActions').hidden = false;
    if(id) $('editLink').href = '/survey-builder.php?id='+id;

    $('reportTitle').textContent = title;
    var s = res.summary;
    $('reportSub').textContent = s.itemCount + ' item' + (s.itemCount===1?'':'s') +
      ' · ' + s.likertCount + ' rating · ' + s.openCount + ' open-ended · ~' +
      s.estMinutes.toFixed(s.estMinutes<1?1:0) + ' min to complete';

    // Two dimension rings + tiers.
    animateRing('V', res.validity.score);
    setTier('V', res.validity.tier);
    animateRing('R', res.reliability.score);
    setTier('R', res.reliability.tier);

    // Four check-group cards.
    setMetric('Quality',  res.groups.questionQuality.score);
    setMetric('Coverage', res.groups.constructCoverage.score);
    setMetric('Scale',    res.groups.scaleStrength.score);
    setMetric('Flow',     res.groups.surveyFlow.score);

    renderFindings(res);
    window.scrollTo({ top: 0, behavior: 'smooth' });
  }

  function setMetric(key, val){
    $('m'+key).textContent = val;
    $('bar'+key).style.width = val + '%';
  }
  function setTier(suffix, tier){
    var pill = $('tierPill'+suffix);
    pill.textContent = tier.label;
    pill.className = 'tier-pill tier-' + tier.key;
  }

  function animateRing(suffix, score){
    var arc = $('ringArc'+suffix), num = $('ringNum'+suffix);
    var target = CIRC * (1 - score/100);
    arc.style.transition = 'stroke-dashoffset 0.9s cubic-bezier(0.22,1,0.36,1)';
    requestAnimationFrame(function(){ arc.style.strokeDashoffset = target; });
    var start = performance.now(), dur = 900;
    function step(now){
      var p = Math.min(1, (now-start)/dur);
      num.textContent = Math.round(score * (1 - Math.pow(1-p,3)));
      if(p<1) requestAnimationFrame(step);
    }
    requestAnimationFrame(step);
  }

  // Which dimension a finding belongs to, for the badge in the findings list.
  var DIM = (window.RSCIEngine && window.RSCIEngine.GROUP_DIM) || {};
  var GROUP_LABEL = { questionQuality:'Validity', constructCoverage:'Validity', scaleStrength:'Reliability', surveyFlow:'Reliability' };

  function renderFindings(res){
    var all = [];
    res.items.forEach(function(it){
      it.flags.forEach(function(f){
        all.push({ sev:f.severity, label:f.label, fix:f.fix, q:'Q'+(it.index+1), dim:GROUP_LABEL[f.group]||'', order:sevOrder(f.severity) });
      });
    });
    res.surveyFlags.forEach(function(f){
      all.push({ sev:f.severity, label:f.label, fix:f.fix, q:'Survey', dim:GROUP_LABEL[f.group]||'', order:sevOrder(f.severity) });
    });
    all.sort(function(a,b){ return a.order - b.order; });

    var c = res.summary.flagCounts;
    $('findingsCounts').textContent =
      (c.critical?c.critical+' critical · ':'') + (c.major?c.major+' major · ':'') + (c.minor?c.minor+' minor':'') ||
      'No blocking issues';

    var host = $('findingsList');
    if(!all.length){
      host.innerHTML = '<div class="findings-clear"><strong>No design issues found.</strong>This survey is clear, well-scaled, and ready to deploy.</div>';
      return;
    }
    host.innerHTML = all.map(function(f){
      return '<div class="finding"><div class="finding-top">'+
        '<span class="sev sev-'+f.sev+'">'+f.sev+'</span>'+
        (f.dim?'<span class="finding-dim">'+esc(f.dim)+'</span>':'')+
        '<span class="finding-q">'+esc(f.q)+'</span></div>'+
        '<div class="finding-label">'+esc(f.label)+'</div>'+
        '<div class="finding-fix"><b>Fix:</b> '+esc(f.fix)+'</div></div>';
    }).join('');
  }
  function sevOrder(s){ return s==='critical'?0:s==='major'?1:s==='minor'?2:3; }

  // ── Sample demo data ──
  function demo(){
    render('Employee Engagement Pulse (sample)', 0, [
      { id:1, text:'', type:'likert', opts:{points:5,minLabel:'',maxLabel:''} },
      { id:2, text:'Don’t you agree that management communicates clearly and supports your growth?', type:'likert', opts:{points:3,minLabel:'Disagree',maxLabel:'Agree'} },
      { id:3, text:'I never feel that my workload is not unmanageable.', type:'likert', opts:{points:5,minLabel:'Strongly disagree',maxLabel:'Strongly agree'} },
      { id:4, text:'I often use the collaboration tooling.', type:'likert', opts:{points:7,minLabel:'Strongly disagree',maxLabel:'Strongly agree'} },
      { id:5, text:'My manager gives me useful feedback.', type:'likert', opts:{points:5,minLabel:'Strongly disagree',maxLabel:'Strongly agree'} },
      { id:6, text:'Describe in detail every aspect of your experience working here this past year, including what went well and what did not.', type:'open', opts:{} }
    ]);
  }

  // ── Wiring ──
  $('ctaAssess').addEventListener('click', openPicker);
  $('ctaDemo').addEventListener('click', demo);
  $('assessAnother').addEventListener('click', function(){
    $('reportCard').hidden = true; $('fenceNote').hidden = true; $('findings').hidden = true; $('reportActions').hidden = true;
    $('introHero').hidden = false; $('introCtas').hidden = false;
    openPicker();
  });
  document.querySelectorAll('[data-close-modal]').forEach(function(b){ b.addEventListener('click', closeModal); });
  $('pickerModal').addEventListener('click', function(e){ if(e.target === e.currentTarget) closeModal(); });
  document.addEventListener('keydown', function(e){ if(e.key==='Escape') closeModal(); });

  var pre = +document.body.getAttribute('data-preselect') || 0;
  if(pre) loadAndAssess(pre);
})();
</script>
</body>
</html>
