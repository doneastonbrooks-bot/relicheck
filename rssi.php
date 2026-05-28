<?php
// rssi.php — RSSI app LANDING page (Light Centered with Sample Report).
// Clean white background. Three CTA tiles. Live sample report card inline.
// Modals open for Upload + Saved Reports per earlier request.

require_once __DIR__ . '/api/_db.php';
require_once __DIR__ . '/api/_session.php';

start_session_secure();
$uid = current_user_id();
if (!$uid) {
    header('Location: /login.html?return=' . urlencode('/rssi.php'));
    exit;
}
$rssi_user = current_user();
if (!$rssi_user) { $_SESSION = []; session_destroy(); header('Location: /login.html'); exit; }

$rssi_user_full = $rssi_user['name'] ?? $rssi_user['email'] ?? 'You';
$rssi_initials  = strtoupper(substr(preg_replace('/[^A-Za-z]/', '', $rssi_user_full) ?: 'U', 0, 2));
?><!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>ReliCheck Strength Survey Index</title>
  <link rel="icon" type="image/png" href="/RSSI-logo.png">
  <style>
    /* ═════════════════════════════════════════════════════════════════
       RSSI Landing — Light, centered, with inline sample report
       ═════════════════════════════════════════════════════════════════ */
    * { box-sizing: border-box; }
    :root {
      --bg:           #F6F7F9;
      --surface:      #FFFFFF;
      --hairline:     rgba(15, 23, 42, 0.06);
      --hairline-2:   rgba(15, 23, 42, 0.10);
      --text:         #15171a;
      --text-2:       #5f6368;
      --text-3:       #8E8E93;
      --blue:         #2D8DFF;
      --blue-hi:      #4FA3FF;
      --blue-deep:    #0A6FE8;
      --good:         #34C759;
      --purple:       #7856DC;
      --reliable:     #007AFF;
      --warn:         #FF9F0A;
      --radius-card:  20px;
      --radius-pill:  999px;
      --shadow-sm:    0 1px 3px rgba(15, 23, 42, 0.05);
      --shadow-md:    0 4px 16px rgba(15, 23, 42, 0.06);
      --shadow-lg:    0 14px 40px rgba(15, 23, 42, 0.08);
    }
    html, body {
      margin: 0; padding: 0;
      min-height: 100vh;
      background: var(--bg);
      color: var(--text);
      font-family: -apple-system, BlinkMacSystemFont, "SF Pro Text", "SF Pro Display", "Helvetica Neue", Inter, system-ui, sans-serif;
      letter-spacing: -0.005em;
      -webkit-font-smoothing: antialiased;
    }

    /* ───────────────── Header ───────────────── */
    .rssi-head {
      display: flex; align-items: center; justify-content: space-between;
      padding: 18px 36px;
      border-bottom: 1px solid var(--hairline);
      background: rgba(255, 255, 255, 0.85);
      backdrop-filter: blur(14px); -webkit-backdrop-filter: blur(14px);
      position: sticky; top: 0; z-index: 50;
    }
    .rssi-brand {
      display: flex; align-items: center;
      text-decoration: none; color: inherit;
    }
    .rssi-brand-logo {
      display: block;
      height: 144px;  /* doubled from 72px */
      width: auto;
    }
    /* Larger logo needs more vertical room in the sticky header */
    .rssi-head { padding-top: 16px; padding-bottom: 16px; }
    .rssi-head-right { display: flex; align-items: center; gap: 14px; }
    .rssi-app-pill {
      padding: 7px 14px; border-radius: var(--radius-pill);
      background: #EEF3FA;
      color: var(--blue-deep);
      font-size: 13px; font-weight: 600;
      letter-spacing: -0.005em;
    }
    .rssi-user {
      width: 34px; height: 34px; border-radius: 50%;
      background: #EEF0F3;
      border: 1px solid var(--hairline-2);
      display: grid; place-items: center;
      color: var(--text);
      font-size: 12.5px; font-weight: 600;
      text-decoration: none;
      transition: background 0.15s;
    }
    .rssi-user:hover { background: #E4E6EA; }

    /* ───────────────── Page wrapper ───────────────── */
    .rssi-page {
      max-width: 1180px;
      margin: 0 auto;
      padding: 56px 32px 80px;
    }

    /* ───────────────── Hero (centered, big two-line headline) ───────────────── */
    .rssi-hero {
      text-align: center;
      max-width: 1040px;
      margin: 0 auto 44px;
    }
    .rssi-hero h1 {
      font-size: 78px;
      font-weight: 800;
      letter-spacing: -0.035em;
      line-height: 1.04;
      margin: 0 0 30px;
      color: var(--text);
    }
    .rssi-hero h1 .accent {
      color: var(--blue);
      display: block;
    }
    .rssi-hero p.lede {
      font-size: 18px;
      color: var(--text-2);
      line-height: 1.55;
      margin: 0 auto;
      max-width: 60ch;
    }

    /* ───────────────── Three CTA tiles ───────────────── */
    .cta-row {
      display: grid;
      grid-template-columns: repeat(3, minmax(0, 1fr));
      gap: 14px;
      max-width: 900px;
      margin: 0 auto 56px;
    }
    .cta-tile {
      display: flex; align-items: center; gap: 14px;
      padding: 18px 22px;
      border-radius: 16px;
      background: var(--surface);
      border: 1px solid var(--hairline);
      box-shadow: var(--shadow-sm);
      text-decoration: none; color: var(--text);
      transition: transform 0.15s, box-shadow 0.15s, border-color 0.15s, background 0.15s;
      cursor: pointer;
    }
    .cta-tile:hover {
      transform: translateY(-2px);
      box-shadow: var(--shadow-md);
      border-color: rgba(45, 141, 255, 0.30);
    }
    .cta-tile.primary {
      background: linear-gradient(160deg, #2D8DFF 0%, #1075E0 100%);
      border-color: transparent;
      color: #fff;
      box-shadow: 0 6px 20px rgba(0, 80, 180, 0.28);
    }
    .cta-tile.primary:hover {
      box-shadow: 0 10px 28px rgba(0, 80, 180, 0.40);
    }
    .cta-tile-icon {
      width: 38px; height: 38px;
      border-radius: 11px;
      background: #EEF3FA;
      color: var(--blue);
      display: grid; place-items: center;
      flex-shrink: 0;
    }
    .cta-tile.primary .cta-tile-icon {
      background: rgba(255, 255, 255, 0.22);
      color: #fff;
    }
    .cta-tile-icon svg { width: 18px; height: 18px; }
    .cta-tile-text { min-width: 0; }
    .cta-tile-title {
      font-size: 15px; font-weight: 700; letter-spacing: -0.01em;
      line-height: 1.2;
    }
    .cta-tile-sub {
      font-size: 12.5px; color: var(--text-2);
      margin-top: 2px;
    }
    .cta-tile.primary .cta-tile-sub { color: rgba(255, 255, 255, 0.85); }

    /* ───────────────── Sample report card ───────────────── */
    .sample-card {
      background: var(--surface);
      border: 1px solid var(--hairline);
      border-radius: 22px;
      padding: 42px 48px 44px;
      box-shadow: var(--shadow-md);
      max-width: 940px;
      margin: 0 auto;
    }
    .sample-eyebrow {
      text-align: center;
      font-size: 11.5px; font-weight: 700; letter-spacing: 0.14em;
      text-transform: uppercase;
      color: var(--blue-deep);
      margin-bottom: 10px;
    }
    .sample-title {
      text-align: center;
      font-size: 22px; font-weight: 700; letter-spacing: -0.015em;
      color: var(--text);
      margin: 0 0 28px;
    }
    .sample-ring-wrap {
      display: flex; justify-content: center;
      margin: 0 auto 36px;
    }
    .sample-ring {
      position: relative;
      width: 220px; height: 220px;
    }
    .sample-ring svg { display: block; width: 100%; height: 100%; transform: rotate(-90deg); }
    .sample-ring-text {
      position: absolute; inset: 0;
      display: grid; place-items: center;
      text-align: center;
    }
    .sample-ring-text .num {
      font-size: 56px; font-weight: 700; letter-spacing: -0.04em;
      color: var(--text); line-height: 1;
    }
    .sample-ring-text .out {
      font-size: 13px; color: var(--text-2);
      margin-top: 6px;
    }
    .sample-metrics {
      display: grid;
      grid-template-columns: repeat(4, minmax(0, 1fr));
      gap: 14px;
    }
    .metric-card {
      background: #FAFBFC;
      border: 1px solid var(--hairline);
      border-radius: 14px;
      padding: 16px 14px 18px;
    }
    .metric-icon {
      width: 36px; height: 36px;
      border-radius: 10px;
      display: grid; place-items: center;
      margin-bottom: 12px;
    }
    .metric-icon.green  { background: rgba(52, 199, 89, 0.12);  color: #2BAE51; }
    .metric-icon.purple { background: rgba(120, 86, 220, 0.12); color: #7856DC; }
    .metric-icon.blue   { background: rgba(0, 122, 255, 0.12);  color: #0A6FE8; }
    .metric-icon.orange { background: rgba(255, 159, 10, 0.14); color: #E08800; }
    .metric-icon svg { width: 18px; height: 18px; }
    .metric-label {
      font-size: 13px; font-weight: 600; color: var(--text);
      line-height: 1.3;
      margin-bottom: 10px;
      min-height: 34px; /* keeps the score baselines aligned */
    }
    .metric-score {
      display: flex; align-items: baseline; gap: 4px;
      margin-bottom: 10px;
    }
    .metric-score .pct {
      font-size: 28px; font-weight: 700; letter-spacing: -0.025em;
      line-height: 1;
    }
    .metric-score .pct-sym {
      font-size: 13px; color: var(--text-3); font-weight: 500;
    }
    .metric-bar {
      height: 4px; border-radius: 999px;
      background: rgba(15, 23, 42, 0.06);
      overflow: hidden;
    }
    .metric-bar > i {
      display: block; height: 100%;
      border-radius: inherit;
    }
    .metric-bar.green  > i { background: linear-gradient(90deg, #34C759, #2BAE51); }
    .metric-bar.purple > i { background: linear-gradient(90deg, #9476ED, #7856DC); }
    .metric-bar.blue   > i { background: linear-gradient(90deg, #4FA3FF, #007AFF); }
    .metric-bar.orange > i { background: linear-gradient(90deg, #FFB23B, #FF9F0A); }

    /* ───────────────── Bottom tagline ───────────────── */
    .rssi-tagline {
      text-align: center;
      margin-top: 44px;
      font-size: 15px;
      color: var(--text-2);
      font-weight: 500;
      letter-spacing: -0.005em;
    }

    /* ───────────────── Footer ───────────────── */
    .rssi-foot {
      max-width: 1180px;
      margin: 0 auto;
      padding: 24px 32px 28px;
      display: flex; align-items: center; justify-content: space-between;
      font-size: 12.5px;
      color: var(--text-3);
      border-top: 1px solid var(--hairline);
    }
    .rssi-foot a {
      color: var(--text-2);
      text-decoration: none;
      margin-left: 28px;
      transition: color 0.15s;
    }
    .rssi-foot a:hover { color: var(--text); }

    /* ───────────────── Modals (Upload + Saved Reports) ───────────────── */
    .rssi-modal-backdrop {
      position: fixed; inset: 0;
      background: rgba(15, 23, 42, 0.45);
      backdrop-filter: blur(6px); -webkit-backdrop-filter: blur(6px);
      z-index: 200;
      display: flex; align-items: center; justify-content: center;
      padding: 24px;
      animation: rssiFadeIn 0.18s ease;
    }
    .rssi-modal-backdrop[hidden] { display: none; }
    @keyframes rssiFadeIn { from { opacity: 0; } to { opacity: 1; } }
    @keyframes rssiPopIn { from { opacity: 0; transform: translateY(8px) scale(0.98); } to { opacity: 1; transform: none; } }

    .rssi-modal {
      width: 100%; max-width: 540px;
      background: #fff;
      border-radius: 22px;
      padding: 32px 32px 28px;
      box-shadow: 0 22px 60px rgba(15, 23, 42, 0.28);
      animation: rssiPopIn 0.22s cubic-bezier(0.34, 1.56, 0.64, 1);
      position: relative;
    }
    .rssi-modal-close {
      position: absolute; top: 16px; right: 16px;
      width: 32px; height: 32px; border-radius: 50%;
      background: #F0F1F3; border: none; cursor: pointer;
      color: var(--text-2);
      display: grid; place-items: center;
      transition: background 0.15s;
    }
    .rssi-modal-close:hover { background: #E4E6EA; color: var(--text); }
    .rssi-modal-close svg { width: 14px; height: 14px; }
    .rssi-modal h2 {
      font-size: 22px; font-weight: 700; letter-spacing: -0.015em;
      margin: 0 0 6px; color: var(--text);
    }
    .rssi-modal p.modal-lede {
      font-size: 14px; color: var(--text-2);
      margin: 0 0 22px; line-height: 1.5;
    }

    .modal-dropzone {
      border: 2px dashed var(--hairline-2);
      border-radius: 16px;
      padding: 36px 24px;
      text-align: center; cursor: pointer;
      transition: border-color 0.15s, background 0.15s;
      background: #FAFBFC;
    }
    .modal-dropzone:hover,
    .modal-dropzone.dragover {
      border-color: var(--blue);
      background: #EEF3FA;
    }
    .modal-dropzone .dz-icon {
      width: 48px; height: 48px;
      border-radius: 14px;
      background: #EEF3FA;
      color: var(--blue);
      display: inline-grid; place-items: center;
      margin-bottom: 14px;
    }
    .modal-dropzone .dz-title {
      font-size: 15px; font-weight: 600; color: var(--text);
      margin-bottom: 5px;
    }
    .modal-dropzone .dz-sub {
      font-size: 12.5px; color: var(--text-2);
    }
    .modal-dropzone .dz-sub strong { color: var(--blue); }

    .modal-status {
      margin-top: 14px; padding: 12px 14px;
      border-radius: 10px; font-size: 13px;
      display: none;
    }
    .modal-status.show { display: block; }
    .modal-status.info  { background: #EEF3FA; border: 1px solid #CDE0F5; color: #1A6FD9; }
    .modal-status.error { background: #FFF1F2; border: 1px solid #FECACA; color: #991B1B; }

    .modal-help {
      margin-top: 14px;
      font-size: 12.5px; color: var(--text-2);
      text-align: center;
    }
    .modal-help a { color: var(--blue); font-weight: 500; text-decoration: none; }
    .modal-help a:hover { text-decoration: underline; }

    /* Saved reports list */
    .modal-saved-list {
      display: flex; flex-direction: column; gap: 8px;
      max-height: 50vh; overflow-y: auto;
      margin: 0 -8px; padding: 0 8px;
    }
    .saved-row {
      display: grid;
      grid-template-columns: 54px 1fr auto;
      align-items: center;
      gap: 14px;
      padding: 14px;
      background: #FAFBFC;
      border: 1px solid var(--hairline);
      border-radius: 12px;
      text-decoration: none; color: inherit;
      transition: background 0.15s, border-color 0.15s, transform 0.15s;
    }
    .saved-row:hover {
      background: #F2F4F7; border-color: var(--hairline-2);
      transform: translateX(2px);
    }
    .saved-score {
      width: 54px; height: 54px; border-radius: 12px;
      background: linear-gradient(160deg, #2D8DFF 0%, #0A6FE8 100%);
      display: grid; place-items: center;
      font-size: 20px; font-weight: 700; color: #fff;
      letter-spacing: -0.02em;
    }
    .saved-name {
      font-size: 14px; font-weight: 600; color: var(--text);
      overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
    }
    .saved-date {
      font-size: 12px; color: var(--text-2); margin-top: 2px;
    }
    .saved-row-arrow {
      width: 30px; height: 30px; border-radius: 50%;
      background: #EEF0F3; color: var(--text-2);
      display: grid; place-items: center;
    }
    .saved-row-arrow svg { width: 12px; height: 12px; }
    .saved-empty {
      text-align: center; padding: 36px 20px;
      color: var(--text-2); font-size: 13.5px;
    }
    .saved-empty strong {
      display: block; color: var(--text);
      margin-bottom: 4px; font-size: 15px;
    }

    /* ───────────────── Responsive ───────────────── */
    @media (max-width: 1100px) {
      .rssi-hero h1 { font-size: 60px; }
    }
    @media (max-width: 880px) {
      .cta-row { grid-template-columns: 1fr; gap: 10px; }
      .sample-metrics { grid-template-columns: 1fr 1fr; }
      .rssi-hero h1 { font-size: 44px; }
      .sample-card { padding: 32px 24px; }
    }
    @media (max-width: 560px) {
      .rssi-hero h1 { font-size: 34px; }
    }
    @media (max-width: 560px) {
      .rssi-head { padding: 14px 18px; }
      .rssi-page { padding: 36px 18px 56px; }
      .rssi-app-pill { display: none; }
      .sample-metrics { grid-template-columns: 1fr; }
      .rssi-foot { flex-direction: column; gap: 10px; align-items: flex-start; padding-left: 18px; padding-right: 18px; }
      .rssi-foot a { margin-left: 0; margin-right: 20px; }
    }
  </style>
</head>
<body>

<!-- ─────────── Header ─────────── -->
<header class="rssi-head">
  <a class="rssi-brand" href="/app-2026v4.php" title="Back to ReliCheck">
    <img src="/RSSI-logo.png" alt="ReliCheck Strength Survey Index" class="rssi-brand-logo">
  </a>
  <div class="rssi-head-right">
    <span class="rssi-app-pill">Survey Strength Index</span>
    <a class="rssi-user" href="#" title="<?= htmlspecialchars($rssi_user_full) ?>">
      <?= htmlspecialchars($rssi_initials) ?>
    </a>
  </div>
</header>

<!-- ─────────── Page body ─────────── -->
<main class="rssi-page">

  <!-- Hero -->
  <section class="rssi-hero">
    <h1>
      Know if your survey
      <span class="accent">is strong enough to trust.</span>
    </h1>
    <p class="lede">
      ReliCheck analyzes your survey's psychometric properties and gives you a clear, actionable strength score &mdash; before you rely on the results.
    </p>
  </section>

  <!-- Three CTAs -->
  <div class="cta-row">
    <a class="cta-tile primary" href="#" data-open-modal="upload">
      <span class="cta-tile-icon">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
      </span>
      <span class="cta-tile-text">
        <div class="cta-tile-title">Upload Survey</div>
        <div class="cta-tile-sub">Analyze a new survey</div>
      </span>
    </a>
    <a class="cta-tile" href="#" data-open-modal="saved">
      <span class="cta-tile-icon">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z"/></svg>
      </span>
      <span class="cta-tile-text">
        <div class="cta-tile-title">Open Saved Report</div>
        <div class="cta-tile-sub">Resume where you left off</div>
      </span>
    </a>
    <a class="cta-tile" href="/rssi-upload.php?demo=1">
      <span class="cta-tile-icon">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
      </span>
      <span class="cta-tile-text">
        <div class="cta-tile-title">View Sample Report</div>
        <div class="cta-tile-sub">See what's possible</div>
      </span>
    </a>
  </div>

  <!-- Sample report card -->
  <section class="sample-card">
    <div class="sample-eyebrow">Sample Report</div>
    <h2 class="sample-title">Survey Strength Index</h2>

    <div class="sample-ring-wrap">
      <div class="sample-ring">
        <svg viewBox="0 0 200 200" xmlns="http://www.w3.org/2000/svg">
          <defs>
            <linearGradient id="ringGradLight" x1="0" y1="0" x2="1" y2="1">
              <stop offset="0%"  stop-color="#4FA3FF"/>
              <stop offset="100%" stop-color="#0A6FE8"/>
            </linearGradient>
          </defs>
          <circle cx="100" cy="100" r="86"
                  fill="none" stroke="#EAEDF1" stroke-width="14"/>
          <!-- 82% sweep: dasharray ≈ 540.35, offset ≈ 97.26 -->
          <circle cx="100" cy="100" r="86"
                  fill="none" stroke="url(#ringGradLight)" stroke-width="14"
                  stroke-linecap="round"
                  stroke-dasharray="540.35"
                  stroke-dashoffset="97.26"/>
        </svg>
        <div class="sample-ring-text">
          <div>
            <div class="num">82</div>
            <div class="out">/ 100</div>
          </div>
        </div>
      </div>
    </div>

    <div class="sample-metrics">
      <div class="metric-card">
        <div class="metric-icon green">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="8 12 11 15 16 9"/></svg>
        </div>
        <div class="metric-label">Question<br>Quality</div>
        <div class="metric-score"><span class="pct">88</span><span class="pct-sym">%</span></div>
        <div class="metric-bar green"><i style="width:88%"></i></div>
      </div>
      <div class="metric-card">
        <div class="metric-icon purple">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="12" width="4" height="9" rx="1"/><rect x="10" y="6" width="4" height="15" rx="1"/><rect x="17" y="14" width="4" height="7" rx="1"/></svg>
        </div>
        <div class="metric-label">Scale<br>Strength</div>
        <div class="metric-score"><span class="pct">76</span><span class="pct-sym">%</span></div>
        <div class="metric-bar purple"><i style="width:76%"></i></div>
      </div>
      <div class="metric-card">
        <div class="metric-icon blue">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2.5 4 5.5v6c0 4.5 3.5 8 8 9 4.5-1 8-4.5 8-9v-6z"/></svg>
        </div>
        <div class="metric-label">Reliability</div>
        <div class="metric-score"><span class="pct">91</span><span class="pct-sym">%</span></div>
        <div class="metric-bar blue"><i style="width:91%"></i></div>
      </div>
      <div class="metric-card">
        <div class="metric-icon orange">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><circle cx="12" cy="12" r="6"/><circle cx="12" cy="12" r="2" fill="currentColor" stroke="none"/></svg>
        </div>
        <div class="metric-label">Validity</div>
        <div class="metric-score"><span class="pct">73</span><span class="pct-sym">%</span></div>
        <div class="metric-bar orange"><i style="width:73%"></i></div>
      </div>
    </div>
  </section>

  <!-- Bottom tagline -->
  <div class="rssi-tagline">Move from uncertainty to insight &mdash; in minutes.</div>

</main>

<!-- ─────────── Footer ─────────── -->
<footer class="rssi-foot">
  <div>&copy; <?= date('Y') ?> ReliCheck. All rights reserved.</div>
  <nav>
    <a href="/help/">Help Center</a>
    <a href="/privacy.html">Privacy Policy</a>
    <a href="/terms.html">Terms of Use</a>
  </nav>
</footer>

<!-- ─────────── MODAL: Upload Survey ─────────── -->
<div class="rssi-modal-backdrop" id="rssiUploadModal" hidden role="dialog" aria-modal="true" aria-labelledby="rssiUploadModalTitle">
  <div class="rssi-modal">
    <button class="rssi-modal-close" type="button" data-close-modal aria-label="Close">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6L6 18"/><path d="M6 6l12 12"/></svg>
    </button>
    <h2 id="rssiUploadModalTitle">Upload Survey</h2>
    <p class="modal-lede">Drop a CSV or Excel file with your response data. The Strength Index will score your instrument in seconds.</p>

    <div class="modal-dropzone" id="modalDropzone">
      <input type="file" id="modalFileInput" hidden accept=".csv,.xlsx,.xls,.tsv,.txt">
      <div class="dz-icon">
        <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
      </div>
      <div class="dz-title">Drag &amp; drop your file</div>
      <div class="dz-sub">or <strong>click to browse</strong> &mdash; CSV, XLSX, XLS, TSV</div>
    </div>

    <div class="modal-status" id="modalStatus"></div>

    <div class="modal-help">
      Don't have a file handy? <a href="/rssi-upload.php?demo=1">View a sample report &rarr;</a>
    </div>
  </div>
</div>

<!-- ─────────── MODAL: Saved Reports ─────────── -->
<div class="rssi-modal-backdrop" id="rssiSavedModal" hidden role="dialog" aria-modal="true" aria-labelledby="rssiSavedModalTitle">
  <div class="rssi-modal">
    <button class="rssi-modal-close" type="button" data-close-modal aria-label="Close">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6L6 18"/><path d="M6 6l12 12"/></svg>
    </button>
    <h2 id="rssiSavedModalTitle">Saved Reports</h2>
    <p class="modal-lede">Previously scored surveys on this browser. Click any report to reopen it.</p>
    <div class="modal-saved-list" id="modalSavedList"></div>
  </div>
</div>

<script>
(function () {
  'use strict';

  function openModal(name) {
    const id = name === 'upload' ? 'rssiUploadModal' : 'rssiSavedModal';
    const el = document.getElementById(id);
    if (!el) return;
    el.hidden = false;
    document.body.style.overflow = 'hidden';
    if (name === 'saved') populateSavedList();
    if (name === 'upload') {
      const status = document.getElementById('modalStatus');
      if (status) { status.className = 'modal-status'; status.textContent = ''; }
    }
  }
  function closeAllModals() {
    document.querySelectorAll('.rssi-modal-backdrop').forEach(function (m) { m.hidden = true; });
    document.body.style.overflow = '';
  }

  document.querySelectorAll('[data-open-modal]').forEach(function (el) {
    el.addEventListener('click', function (e) { e.preventDefault(); openModal(el.getAttribute('data-open-modal')); });
  });
  document.querySelectorAll('[data-close-modal]').forEach(function (el) { el.addEventListener('click', closeAllModals); });
  document.querySelectorAll('.rssi-modal-backdrop').forEach(function (m) {
    m.addEventListener('click', function (e) { if (e.target === m) closeAllModals(); });
  });
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape') closeAllModals(); });

  /* Upload modal: drag-drop → stage to sessionStorage → /rssi-upload.php */
  const dz   = document.getElementById('modalDropzone');
  const file = document.getElementById('modalFileInput');
  const status = document.getElementById('modalStatus');
  function setStatus(msg, kind) {
    if (!status) return;
    status.innerHTML = msg;
    status.className = 'modal-status show ' + (kind || 'info');
  }
  function handleFile(f) {
    if (!f) return;
    setStatus('<strong>Reading ' + escapeHtml(f.name) + '&hellip;</strong> ' + Math.round(f.size / 1024) + ' KB', 'info');
    const reader = new FileReader();
    reader.onload = function (ev) {
      try {
        sessionStorage.setItem('rssi.pendingFile', JSON.stringify({
          name: f.name, type: f.type || '', data: ev.target.result,
        }));
        setStatus('<strong>Opening report&hellip;</strong>', 'info');
        window.location = '/rssi-upload.php?frommodal=1';
      } catch (e) { setStatus('<strong>Could not stage file:</strong> ' + escapeHtml(e.message || String(e)), 'error'); }
    };
    reader.onerror = function () { setStatus('<strong>Could not read the file.</strong>', 'error'); };
    reader.readAsDataURL(f);
  }
  if (dz && file) {
    dz.addEventListener('click', function () { file.click(); });
    file.addEventListener('change', function () { if (file.files && file.files[0]) handleFile(file.files[0]); });
    ['dragenter', 'dragover'].forEach(function (ev) {
      dz.addEventListener(ev, function (e) { e.preventDefault(); dz.classList.add('dragover'); });
    });
    ['dragleave', 'drop'].forEach(function (ev) {
      dz.addEventListener(ev, function (e) { e.preventDefault(); dz.classList.remove('dragover'); });
    });
    dz.addEventListener('drop', function (e) {
      const f = e.dataTransfer && e.dataTransfer.files && e.dataTransfer.files[0];
      if (f) handleFile(f);
    });
  }

  function populateSavedList() {
    const list = document.getElementById('modalSavedList');
    if (!list) return;
    // Server-side saved datasets (Phase 1 Q7). Replaces the previous
    // localStorage-only walk so projects persist across browsers and
    // devices. Each row routes to /rssi-upload.php?dataset_id=N, which
    // triggers _loadFromDataset on init.
    list.innerHTML = '<div class="saved-empty"><strong>Loading saved projects…</strong></div>';
    fetch('/api/datasets/list.php', { credentials: 'same-origin' })
      .then(function (r) { return r.json().then(function (j) { return { ok: r.ok, body: j }; }); })
      .then(function (out) {
        if (!out.ok) {
          list.innerHTML = '<div class="saved-empty"><strong>Could not load your saved projects.</strong>Try refreshing the page.</div>';
          return;
        }
        const rows = (out.body && out.body.datasets) || [];
        if (!rows.length) {
          list.innerHTML = '<div class="saved-empty"><strong>No saved projects yet</strong>Upload your first survey to start building your library.</div>';
          return;
        }
        list.innerHTML = rows.map(function (r) {
          const when    = r.updated_at ? new Date(r.updated_at).toLocaleString() : '';
          const likert  = r.likert_count || 0;
          const rowCnt  = r.row_count    || 0;
          const meta    = (likert ? (likert + ' Likert items') : '') +
                          (likert && rowCnt ? ' · ' : '') +
                          (rowCnt ? (rowCnt + ' responses') : '') +
                          ((likert || rowCnt) && when ? ' · ' : '') + escapeHtml(when);
          const title   = r.title || r.source_filename || 'Untitled';
          const href    = '/rssi-upload.php?dataset_id=' + encodeURIComponent(r.id);
          return '<a class="saved-row" href="' + href + '">' +
                   '<div class="saved-score" style="background:var(--blue-soft-bg);color:var(--blue-deep);font-size:14px;">📄</div>' +
                   '<div><div class="saved-name">' + escapeHtml(title) + '</div>' +
                   '<div class="saved-date">' + meta + '</div></div>' +
                   '<span class="saved-row-arrow"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14"/><path d="M13 6l6 6-6 6"/></svg></span>' +
                 '</a>';
        }).join('');
      })
      .catch(function () {
        list.innerHTML = '<div class="saved-empty"><strong>Could not load your saved projects.</strong>Network error — try refreshing.</div>';
      });
  }

  function escapeHtml(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'})[c];
    });
  }
})();
</script>

</body>
</html>
