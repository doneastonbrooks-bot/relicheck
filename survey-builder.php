<?php
// /survey-builder.php — Survey question builder, split-pane layout.
// Left  : question textarea + type picker + question list.
// Right : live preview card — updates as the user types.
// Follows the platform shell / studio-template.css iPadOS design system.

require_once __DIR__ . '/api/_db.php';
require_once __DIR__ . '/api/_session.php';

start_session_secure();
$uid = current_user_id();
if (!$uid) {
  header('Location: /login.html?return=' . urlencode('/survey-builder.php'));
  exit;
}
$user = current_user();
if (!$user) { $_SESSION = []; session_destroy(); header('Location: /login.html'); exit; }

$user_full  = $user['name'] ?? $user['email'] ?? 'You';
$initials   = strtoupper(substr(preg_replace('/[^A-Za-z]/', '', $user_full) ?: 'U', 0, 2));

$load_survey_id = isset($_GET['id']) ? max(0, (int)$_GET['id']) : 0;
$shell_page_title    = 'Survey Builder — ReliCheck';
$shell_user_initials = $initials;
$shell_user_full     = $user_full;
$shell_project_label = '';          // suppress project switcher in the nav
$shell_body_attrs    = 'data-current-studio="survey" data-builder="1"';

include __DIR__ . '/_platform_shell_header.php';
?>

<style>
/* ── Escape page-frame constraints so builder fills the viewport ── */
.relicheck-app-shell  { padding: 0 !important; }
.relicheck-page-frame { max-width: none !important; padding: 0 !important; margin: 0 !important; width: 100% !important; }
.hero-blob            { display: none !important; }

/* ── Root tokens (resolved from studio-template.css) ── */
:root {
  --bldr-accent:      #e85d3a;
  --bldr-accent-soft: #fdeee9;
  --bldr-border:      rgba(15,23,42,0.08);
  --bldr-shadow:      0 4px 12px rgba(15,23,42,0.10);
  --bldr-shadow-lg:   0 8px 24px rgba(15,23,42,0.16);
  --bldr-text:        #15171a;
  --bldr-text-2:      #5f6368;
  --bldr-text-3:      #8a8f98;
  --bldr-bg:          #f5f6f8;
  --bldr-panel:       #ffffff;
  --bldr-left-w:      380px;
}

/* ── Builder chrome ── */
.bldr { display: flex; flex-direction: column; height: calc(100vh - 56px); }

/* Top action bar */
.bldr-bar {
  display: flex; align-items: center; gap: 16px;
  padding: 0 24px;
  height: 52px;
  background: var(--bldr-panel);
  border-bottom: 1px solid var(--bldr-border);
  flex-shrink: 0;
}
.bldr-back {
  display: inline-flex; align-items: center; gap: 6px;
  font-size: 13px; font-weight: 500; color: var(--bldr-text-2);
  text-decoration: none; white-space: nowrap;
  padding: 6px 10px; border-radius: 8px;
  transition: background 0.12s, color 0.12s;
}
.bldr-back:hover { background: var(--bldr-bg); color: var(--bldr-text); }
.bldr-back svg   { flex-shrink: 0; }
.bldr-bar-actions { display: flex; align-items: center; gap: 8px; margin-left: auto; flex-shrink: 0; }

/* ── RSCI: Survey check button + slide-over ── */
.bldr-rsci-btn {
  display: inline-flex; align-items: center; gap: 7px;
  padding: 7px 14px; border-radius: 999px; cursor: pointer;
  font: inherit; font-size: 13px; font-weight: 600;
  background: #EEF3FA; color: #0A6FE8; border: 1px solid rgba(45,141,255,0.22);
  transition: background 0.15s, border-color 0.15s;
}
.bldr-rsci-btn:hover { background: #E2EDFB; border-color: rgba(45,141,255,0.4); }
.bldr-rsci-btn svg { flex-shrink: 0; }
.bldr-rsci-badge {
  display: inline-grid; place-items: center; min-width: 22px; height: 20px;
  padding: 0 6px; border-radius: 999px; font-size: 12px; font-weight: 700;
  background: #0A6FE8; color: #fff;
}
.bldr-rsci-badge[data-tier="ready"]       { background: #1f9e44; }
.bldr-rsci-badge[data-tier="flag"]        { background: #c47700; }
.bldr-rsci-badge[data-tier="not_ready"]   { background: #c4271f; }

.rsci-over-backdrop {
  position: fixed; inset: 0; z-index: 300; background: rgba(15,23,42,0.35);
  backdrop-filter: blur(4px); -webkit-backdrop-filter: blur(4px);
  opacity: 0; transition: opacity 0.2s; pointer-events: none;
}
.rsci-over-backdrop.open { opacity: 1; pointer-events: auto; }
.rsci-over {
  position: fixed; top: 0; right: 0; bottom: 0; z-index: 301; width: 420px; max-width: 92vw;
  background: #fff; box-shadow: -12px 0 40px rgba(15,23,42,0.18);
  transform: translateX(100%); transition: transform 0.26s cubic-bezier(0.22,1,0.36,1);
  display: flex; flex-direction: column;
  font-family: -apple-system, BlinkMacSystemFont, "SF Pro Text", Inter, system-ui, sans-serif;
}
.rsci-over.open { transform: none; }
.rsci-over-head {
  display: flex; align-items: center; justify-content: space-between;
  padding: 18px 22px; border-bottom: 1px solid rgba(15,23,42,0.08); flex-shrink: 0;
}
.rsci-over-head .eyebrow { font-size: 10.5px; font-weight: 700; letter-spacing: 0.12em; text-transform: uppercase; color: #0A6FE8; }
.rsci-over-head h3 { margin: 2px 0 0; font-size: 17px; font-weight: 700; color: #15171a; }
.rsci-over-close { width: 30px; height: 30px; border-radius: 50%; border: none; background: #F0F1F3; cursor: pointer; color: #5f6368; display: grid; place-items: center; }
.rsci-over-close:hover { background: #E4E6EA; }
.rsci-over-body { padding: 22px; overflow-y: auto; flex: 1; }
.rsci-over-score { display: flex; align-items: center; gap: 16px; margin-bottom: 18px; }
.rsci-over-num { font-size: 46px; font-weight: 800; letter-spacing: -0.04em; line-height: 1; color: #15171a; }
.rsci-over-num small { font-size: 15px; font-weight: 500; color: #8E8E93; }
.rsci-over-tier { font-size: 13px; font-weight: 700; padding: 5px 12px; border-radius: 999px; }
.rsci-over-tier.tier-ready { background: rgba(52,199,89,.14); color: #1f9e44; }
.rsci-over-tier.tier-flag { background: rgba(255,159,10,.16); color: #c47700; }
.rsci-over-tier.tier-not_ready { background: rgba(255,59,48,.12); color: #c4271f; }
.rsci-dims { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; margin-bottom: 14px; }
.rsci-dim { background: #FAFBFC; border: 1px solid rgba(15,23,42,0.06); border-radius: 12px; padding: 12px 14px; }
.rsci-dim-row { display: flex; align-items: baseline; justify-content: space-between; }
.rsci-dim-name { font-size: 11px; font-weight: 700; letter-spacing: .08em; text-transform: uppercase; color: #5f6368; }
.rsci-dim-num { font-size: 28px; font-weight: 800; letter-spacing: -0.03em; color: #15171a; }
.rsci-dim-num small { font-size: 12px; font-weight: 500; color: #8E8E93; margin-left: 2px; }
.rsci-dim .rsci-over-tier { display: inline-block; margin-top: 6px; }
.rsci-subgrid { display: grid; grid-template-columns: 1fr 1fr; gap: 8px; margin-bottom: 14px; }
.rsci-sub { background: #FAFBFC; border: 1px solid rgba(15,23,42,0.06); border-radius: 10px; padding: 10px 12px; }
.rsci-sub .lbl { font-size: 11.5px; color: #5f6368; font-weight: 600; }
.rsci-sub .val { font-size: 20px; font-weight: 700; letter-spacing: -0.02em; }
.rsci-sub-dim { font-size: 10px; font-weight: 600; letter-spacing: .04em; text-transform: uppercase; color: #a0a3a8; margin-top: 2px; }
.rsci-fence { font-size: 11.5px; color: #8E8E93; line-height: 1.5; margin: 0 0 20px; }
.rsci-fdim { font-size: 10.5px; font-weight: 600; text-transform: uppercase; letter-spacing: .04em; color: #a0a3a8; }
.rsci-sev-info { background: rgba(120,86,220,.12); color: #6a4fc0; }
.rsci-over h4 { font-size: 13.5px; font-weight: 700; color: #15171a; margin: 0 0 10px; }
.rsci-finding { border: 1px solid rgba(15,23,42,0.06); border-radius: 10px; padding: 11px 13px; margin-bottom: 8px; }
.rsci-finding .ft { display: flex; align-items: center; gap: 8px; margin-bottom: 4px; }
.rsci-sev { font-size: 9.5px; font-weight: 700; letter-spacing: .05em; text-transform: uppercase; padding: 2px 7px; border-radius: 999px; }
.rsci-sev-critical { background: rgba(255,59,48,.12); color: #c4271f; }
.rsci-sev-major { background: rgba(255,159,10,.16); color: #c47700; }
.rsci-sev-minor { background: rgba(45,141,255,.12); color: #0A6FE8; }
.rsci-fq { font-size: 11.5px; color: #8E8E93; font-weight: 600; }
.rsci-finding .lbl { font-size: 13px; font-weight: 600; color: #15171a; line-height: 1.4; }
.rsci-finding .fix { font-size: 12.5px; color: #5f6368; margin-top: 3px; line-height: 1.4; }
.rsci-finding .fix b { color: #15171a; }
.rsci-clear { text-align: center; padding: 28px 16px; color: #5f6368; font-size: 13.5px; }
.rsci-clear strong { display: block; color: #1f9e44; font-size: 15px; margin-bottom: 4px; }
.bldr-btn {
  display: inline-flex; align-items: center; gap: 6px;
  padding: 7px 16px; border-radius: 10px; font-family: inherit;
  font-size: 13px; font-weight: 600; cursor: pointer;
  border: 1px solid var(--bldr-border); background: var(--bldr-panel);
  color: var(--bldr-text-2); transition: background 0.12s, color 0.12s, border-color 0.12s;
}
.bldr-btn:hover { background: var(--bldr-bg); color: var(--bldr-text); }
.bldr-btn.primary {
  background: var(--bldr-accent); border-color: var(--bldr-accent); color: #fff;
}
.bldr-btn.primary:hover { background: #d4522f; border-color: #d4522f; }
.bldr-btn-count {
  display: inline-flex; align-items: center; justify-content: center;
  width: 18px; height: 18px; border-radius: 50%;
  background: rgba(255,255,255,0.25); font-size: 11px; font-weight: 700;
}

/* ── Two-panel body ── */
.bldr-body { display: flex; flex: 1; min-height: 0; }

/* Left panel */
.bldr-left {
  width: var(--bldr-left-w); flex-shrink: 0;
  background: var(--bldr-panel);
  border-right: 1px solid var(--bldr-border);
  display: flex; flex-direction: column;
  overflow: hidden;
}
.bldr-left-scroll { flex: 1; overflow-y: auto; padding: 24px; display: flex; flex-direction: column; gap: 20px; align-items: stretch; }
.bldr-survey-title-wrap { display: flex; flex-direction: column; align-items: center; gap: 4px; text-align: center; }
.bldr-survey-title-label { font-size: 11px; font-weight: 700; letter-spacing: 0.07em; text-transform: uppercase; color: var(--bldr-text-3); }
.bldr-survey-title-input {
  width: 100%; box-sizing: border-box;
  border: none; border-bottom: 1.5px solid var(--bldr-border);
  border-radius: 0; padding: 6px 0;
  font-family: Inter, -apple-system, sans-serif;
  font-size: 18px; font-weight: 700; letter-spacing: -0.02em;
  color: var(--bldr-text); background: transparent;
  text-align: center; outline: none;
  transition: border-color 0.15s;
}
.bldr-survey-title-input::placeholder { color: var(--bldr-text-3); font-weight: 400; }
.bldr-survey-title-input:focus { border-color: var(--bldr-accent); }

/* Question number nav */
.bldr-qnav {
  display: flex; align-items: center; justify-content: space-between;
  flex-shrink: 0;
}
.bldr-qnum-label {
  font-size: 12px; font-weight: 700; letter-spacing: 0.06em;
  text-transform: uppercase; color: var(--bldr-text-3);
}
.bldr-qnav-arrows { display: flex; gap: 4px; }
.bldr-arrow {
  width: 28px; height: 28px; border-radius: 8px;
  border: 1px solid var(--bldr-border); background: transparent;
  display: flex; align-items: center; justify-content: center;
  cursor: pointer; color: var(--bldr-text-2);
  transition: background 0.1s, color 0.1s;
}
.bldr-arrow:hover:not(:disabled) { background: var(--bldr-bg); color: var(--bldr-text); }
.bldr-arrow:disabled { opacity: 0.3; cursor: default; }

/* Question textarea */
.bldr-qtextarea {
  width: 100%; box-sizing: border-box;
  min-height: 120px; resize: none;
  border: 1.5px solid var(--bldr-border);
  border-radius: 14px; padding: 16px;
  font-family: Inter, -apple-system, sans-serif;
  font-size: 17px; font-weight: 500; line-height: 1.5;
  letter-spacing: -0.01em; color: var(--bldr-text);
  background: #ffffff !important;
  transition: border-color 0.15s, box-shadow 0.15s;
  outline: none;
}
.bldr-qtextarea::placeholder { color: var(--bldr-text-3); font-weight: 400; }
.bldr-qtextarea:focus {
  border-color: var(--bldr-accent);
  box-shadow: 0 0 0 3px var(--bldr-accent-soft);
}

/* Type picker */
.bldr-type-label {
  font-size: 11px; font-weight: 700; letter-spacing: 0.07em;
  text-transform: uppercase; color: var(--bldr-text-3);
  margin-bottom: 8px;
}
.bldr-type-select-wrap {
  position: relative;
}
.bldr-type-select-wrap::after {
  content: "";
  position: absolute; right: 14px; top: 50%; transform: translateY(-50%);
  width: 0; height: 0;
  border-left: 5px solid transparent;
  border-right: 5px solid transparent;
  border-top: 6px solid var(--bldr-text-3);
  pointer-events: none;
}
.bldr-type-select {
  width: 100%; appearance: none; -webkit-appearance: none;
  padding: 10px 36px 10px 14px;
  border: 1.5px solid var(--bldr-border); border-radius: 12px;
  background: #fff; color: var(--bldr-text);
  font-family: Inter, -apple-system, sans-serif;
  font-size: 14px; font-weight: 600;
  cursor: pointer; outline: none;
  transition: border-color 0.12s, box-shadow 0.12s;
}
.bldr-type-select:focus {
  border-color: var(--bldr-accent);
  box-shadow: 0 0 0 3px var(--bldr-accent-soft);
}

/* Type options area */
.bldr-type-opts { display: flex; flex-direction: column; gap: 12px; }
.opts-group-label {
  font-size: 11px; font-weight: 700; letter-spacing: 0.07em;
  text-transform: uppercase; color: var(--bldr-text-3);
  margin-bottom: 4px;
}
.opts-row { display: flex; flex-direction: column; gap: 6px; }
.opts-input {
  width: 100%; box-sizing: border-box;
  padding: 8px 12px; border-radius: 10px;
  border: 1.5px solid var(--bldr-border);
  font-family: inherit; font-size: 14px; color: var(--bldr-text);
  background: #fff; outline: none;
  transition: border-color 0.12s, box-shadow 0.12s;
}
.opts-input:focus { border-color: var(--bldr-accent); box-shadow: 0 0 0 3px var(--bldr-accent-soft); }
.opts-scale-btns { display: flex; gap: 5px; flex-wrap: wrap; }
.scale-btn {
  width: 36px; height: 36px; border-radius: 10px;
  border: 1.5px solid var(--bldr-border);
  background: var(--bldr-bg); color: var(--bldr-text-2);
  font-family: inherit; font-size: 14px; font-weight: 600;
  cursor: pointer; transition: all 0.12s;
}
.scale-btn:hover  { border-color: var(--bldr-accent); color: var(--bldr-accent); }
.scale-btn.active { background: var(--bldr-accent); border-color: var(--bldr-accent); color: #fff; }
.opts-hint { font-size: 13px; color: var(--bldr-text-3); line-height: 1.5; margin: 0; }
.choice-row { display: flex; align-items: center; gap: 8px; }
.choice-row .opts-input { flex: 1; }
.choice-remove {
  flex-shrink: 0; width: 28px; height: 28px; border-radius: 8px;
  border: 1px solid var(--bldr-border); background: transparent;
  color: var(--bldr-text-3); font-size: 16px; line-height: 1;
  cursor: pointer; transition: background 0.1s, color 0.1s;
  display: flex; align-items: center; justify-content: center;
}
.choice-remove:hover { background: #fff0ee; border-color: var(--bldr-accent); color: var(--bldr-accent); }
.btn-add-choice {
  align-self: flex-start; padding: 6px 12px; border-radius: 8px;
  border: 1.5px dashed var(--bldr-border); background: transparent;
  color: var(--bldr-text-3); font-family: inherit; font-size: 13px;
  font-weight: 600; cursor: pointer; transition: all 0.12s;
}
.btn-add-choice:hover { border-color: var(--bldr-accent); color: var(--bldr-accent); background: var(--bldr-accent-soft); }

/* Question list (bottom of left panel) */
.bldr-qlist-section { margin-top: auto; padding-top: 16px; border-top: 1px solid var(--bldr-border); }
.bldr-qlist-head {
  display: flex; align-items: center; justify-content: space-between;
  margin-bottom: 0; cursor: pointer; padding: 4px 2px;
  border-radius: 8px; transition: background 0.1s;
  user-select: none;
}
.bldr-qlist-head:hover { background: var(--bldr-bg); }
.bldr-qlist-toggle {
  display: flex; align-items: center; gap: 6px;
  font-size: 11px; font-weight: 700; letter-spacing: 0.07em;
  text-transform: uppercase; color: var(--bldr-text-3);
}
.bldr-qlist-chevron {
  transition: transform 0.2s; color: var(--bldr-text-3);
  display: flex; align-items: center;
}
.bldr-qlist-section.is-open .bldr-qlist-chevron { transform: rotate(180deg); }
.bldr-qlist-body {
  display: none; flex-direction: column; gap: 8px; margin-top: 8px;
}
.bldr-qlist-section.is-open .bldr-qlist-body { display: flex; }
.bldr-qlist-label {
  font-size: 11px; font-weight: 700; letter-spacing: 0.07em;
  text-transform: uppercase; color: var(--bldr-text-3);
}
.btn-add-q {
  display: inline-flex; align-items: center; gap: 5px;
  padding: 5px 12px; border-radius: 8px;
  border: 1.5px solid var(--bldr-border);
  background: transparent; color: var(--bldr-text-2);
  font-family: inherit; font-size: 12px; font-weight: 600;
  cursor: pointer; transition: all 0.12s;
}
.btn-add-q:hover { border-color: var(--bldr-accent); color: var(--bldr-accent); background: var(--bldr-accent-soft); }
.bldr-qlist { display: flex; flex-direction: column; gap: 3px; }
.qlist-item {
  display: flex; align-items: center; gap: 10px;
  padding: 8px 10px; border-radius: 10px;
  cursor: pointer; transition: background 0.1s;
}
.qlist-item:hover  { background: var(--bldr-bg); }
.qlist-item.active { background: var(--bldr-accent-soft); }
.qlist-num {
  flex-shrink: 0; width: 24px; height: 24px; border-radius: 6px;
  background: var(--bldr-border); display: flex; align-items: center; justify-content: center;
  font-size: 11px; font-weight: 700; color: var(--bldr-text-2);
}
.qlist-item.active .qlist-num { background: var(--bldr-accent); color: #fff; }
.qlist-text {
  font-size: 13px; font-weight: 500; color: var(--bldr-text-2);
  white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
}
.qlist-item.active .qlist-text { color: var(--bldr-text); }

/* ── Right panel — preview ── */
.bldr-right {
  flex: 1; background: var(--bldr-bg);
  display: flex; align-items: flex-start; justify-content: center;
  padding: 40px 32px; overflow-y: auto;
}
.bldr-preview-wrap { width: 100%; max-width: 680px; }

/* Preview card — canonical .card values from studio-template.css */
.bldr-preview-card {
  background: #fff;
  border: 1px solid var(--bldr-border);
  border-radius: 26px;
  box-shadow: var(--bldr-shadow);
  padding: 40px 48px;
  transition: box-shadow 0.15s;
}
.preview-eyebrow {
  font-size: 12px; font-weight: 700; letter-spacing: 0.07em;
  text-transform: uppercase; color: var(--bldr-accent);
  margin-bottom: 16px;
}
.preview-qtext {
  font-size: 24px; font-weight: 700; letter-spacing: -0.025em;
  line-height: 1.3; color: var(--bldr-text);
  margin: 0 0 32px;
  min-height: 1.3em; /* prevent collapse when empty */
}
.preview-qtext.is-placeholder { color: var(--bldr-text-3); font-weight: 400; font-size: 20px; }
.preview-response { margin-top: 4px; }

/* Likert scale preview */
.likert-scale  { display: flex; flex-direction: column; gap: 10px; }
.likert-row    { display: flex; gap: 0; }
.likert-point  {
  flex: 1; display: flex; flex-direction: column; align-items: center; gap: 8px;
  cursor: pointer; position: relative;
}
.likert-point input[type="radio"] { position: absolute; opacity: 0; width: 0; height: 0; }
.likert-dot {
  width: 36px; height: 36px; border-radius: 50%;
  border: 2px solid var(--bldr-border);
  background: #fff; transition: all 0.12s;
  display: flex; align-items: center; justify-content: center;
}
.likert-point:hover .likert-dot { border-color: var(--bldr-accent); background: var(--bldr-accent-soft); }
.likert-point input:checked ~ .likert-dot { background: var(--bldr-accent); border-color: var(--bldr-accent); }
.likert-point input:checked ~ .likert-dot::after {
  content: ""; width: 10px; height: 10px; border-radius: 50%; background: #fff;
}
.likert-num    { font-size: 12px; font-weight: 600; color: var(--bldr-text-3); }
.likert-labels {
  display: flex; justify-content: space-between;
  font-size: 12px; color: var(--bldr-text-3); font-weight: 500;
  padding: 0 2px;
}

/* Open-ended preview */
.prev-open {
  width: 100%; box-sizing: border-box;
  min-height: 100px; border-radius: 14px;
  border: 1.5px solid var(--bldr-border);
  padding: 14px 16px;
  font-family: Inter, -apple-system, sans-serif;
  font-size: 15px; color: var(--bldr-text-3);
  resize: none; background: var(--bldr-bg);
}

/* Multiple choice preview */
.prev-choices { display: flex; flex-direction: column; gap: 10px; }
.prev-choice  {
  display: flex; align-items: center; gap: 12px;
  padding: 12px 16px; border-radius: 12px;
  border: 1.5px solid var(--bldr-border); background: #fff;
  font-size: 15px; color: var(--bldr-text); cursor: pointer;
  transition: border-color 0.12s, background 0.12s;
}
.prev-choice:hover { border-color: var(--bldr-accent); background: var(--bldr-accent-soft); }
.prev-choice input { accent-color: var(--bldr-accent); width: 16px; height: 16px; }

/* Sliding scale preview */
.prev-slider-wrap { display: flex; flex-direction: column; gap: 10px; }
.prev-slider {
  -webkit-appearance: none; appearance: none;
  width: 100%; height: 6px; border-radius: 999px;
  background: linear-gradient(to right, var(--bldr-accent) 50%, rgba(15,23,42,0.12) 50%);
  outline: none; cursor: pointer;
}
.prev-slider::-webkit-slider-thumb {
  -webkit-appearance: none; appearance: none;
  width: 26px; height: 26px; border-radius: 50%;
  background: #fff; border: 2.5px solid var(--bldr-accent);
  box-shadow: 0 2px 8px rgba(232,93,58,0.25);
  cursor: grab; transition: box-shadow 0.12s;
}
.prev-slider::-webkit-slider-thumb:active { cursor: grabbing; box-shadow: 0 0 0 6px var(--bldr-accent-soft); }
.prev-slider::-moz-range-thumb {
  width: 26px; height: 26px; border-radius: 50%;
  background: #fff; border: 2.5px solid var(--bldr-accent);
  box-shadow: 0 2px 8px rgba(232,93,58,0.25); cursor: grab;
}
.prev-slider-meta { display: flex; justify-content: space-between; align-items: center; }
.prev-slider-labels { display: flex; justify-content: space-between; font-size: 12px; color: var(--bldr-text-3); font-weight: 500; }
.prev-slider-value {
  font-size: 22px; font-weight: 700; letter-spacing: -0.03em;
  color: var(--bldr-accent); min-width: 2ch; text-align: right;
}

/* Matrix preview */
.prev-matrix { width: 100%; border-collapse: collapse; }
.prev-matrix th {
  padding: 8px 12px; font-size: 12px; font-weight: 700;
  letter-spacing: 0.04em; text-transform: uppercase;
  color: var(--bldr-text-3); text-align: center;
  border-bottom: 1.5px solid var(--bldr-border);
}
.prev-matrix th:first-child { text-align: left; }
.prev-matrix td {
  padding: 12px; border-bottom: 1px solid var(--bldr-border);
  font-size: 14px; color: var(--bldr-text); vertical-align: middle;
}
.prev-matrix td:not(:first-child) { text-align: center; }
.prev-matrix tr:last-child td { border-bottom: none; }
.prev-matrix tr:hover td { background: var(--bldr-bg); }
.prev-matrix input[type="radio"] { accent-color: var(--bldr-accent); width: 16px; height: 16px; cursor: pointer; }

/* Ranking preview */
.prev-ranking { display: flex; flex-direction: column; gap: 8px; }
.rank-item {
  display: flex; align-items: center; gap: 12px;
  padding: 12px 16px; border-radius: 12px;
  border: 1.5px solid var(--bldr-border); background: #fff;
  cursor: grab; transition: box-shadow 0.12s, border-color 0.12s;
  user-select: none;
}
.rank-item:hover { border-color: var(--bldr-accent); box-shadow: var(--bldr-shadow); }
.rank-item.dragging { opacity: 0.5; box-shadow: var(--bldr-shadow-lg); }
.rank-num {
  flex-shrink: 0; width: 26px; height: 26px; border-radius: 8px;
  background: var(--bldr-accent); color: #fff;
  font-size: 12px; font-weight: 700;
  display: flex; align-items: center; justify-content: center;
}
.rank-handle {
  flex-shrink: 0; color: var(--bldr-text-3);
  display: flex; flex-direction: column; gap: 3px; padding: 2px;
}
.rank-handle span { display: block; width: 16px; height: 2px; border-radius: 2px; background: currentColor; }
.rank-label { flex: 1; font-size: 15px; font-weight: 500; color: var(--bldr-text); }

/* Priority preview */
.prev-priority { display: flex; flex-direction: column; gap: 10px; }
.priority-item { display: flex; flex-direction: column; gap: 6px; }
.priority-item-head { display: flex; justify-content: space-between; align-items: baseline; }
.priority-item-label { font-size: 14px; font-weight: 500; color: var(--bldr-text); }
.priority-item-val { font-size: 14px; font-weight: 700; color: var(--bldr-accent); min-width: 3ch; text-align: right; }
.priority-bar-track {
  height: 8px; border-radius: 999px;
  background: rgba(15,23,42,0.08); overflow: hidden;
}
.priority-bar-fill {
  height: 100%; border-radius: 999px;
  background: var(--bldr-accent);
  transition: width 0.2s;
}
.priority-total {
  display: flex; justify-content: space-between; align-items: center;
  padding: 10px 14px; border-radius: 10px;
  background: var(--bldr-bg); border: 1px solid var(--bldr-border);
  font-size: 13px; font-weight: 600; color: var(--bldr-text-2);
  margin-top: 4px;
}
.priority-total-num { font-size: 15px; font-weight: 700; color: var(--bldr-text); }

/* Rating preview */
.prev-rating { display: flex; gap: 8px; flex-wrap: wrap; }
.star-btn {
  width: 48px; height: 48px; border-radius: 12px;
  border: 1.5px solid var(--bldr-border); background: #fff;
  font-size: 22px; color: var(--bldr-text-3);
  cursor: pointer; transition: all 0.12s;
  display: flex; align-items: center; justify-content: center;
}
.star-btn:hover, .star-btn.lit { background: var(--bldr-accent-soft); border-color: var(--bldr-accent); color: var(--bldr-accent); }

/* Empty state in preview */
.preview-empty {
  text-align: center; padding: 48px 24px;
  color: var(--bldr-text-3); font-size: 15px; line-height: 1.6;
}
</style>

<div class="bldr">

  <!-- Top bar -->
  <div class="bldr-bar">
    <a class="bldr-back" href="/studio-survey.php">
      <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><polyline points="15 18 9 12 15 6"/></svg>
      Survey Studio
    </a>
    <div class="bldr-bar-actions">
      <button class="bldr-rsci-btn" id="btnRsci" type="button" title="Check this survey's predicted validity and reliability with ReliCheck">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 11l3 3 8-8"/><path d="M20 12v6a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h9"/></svg>
        Check survey
        <span class="bldr-rsci-badge" id="rsciBadge" hidden>—</span>
      </button>
    </div>
  </div>

  <!-- Body: left + right -->
  <div class="bldr-body">

    <!-- Left panel -->
    <aside class="bldr-left">
      <div class="bldr-left-scroll">

        <!-- Survey title -->
        <div class="bldr-survey-title-wrap">
          <span class="bldr-survey-title-label">Survey Title</span>
          <input class="bldr-survey-title-input" id="surveyTitle" type="text" placeholder="Untitled survey" autocomplete="off" spellcheck="false">
        </div>

        <!-- Q# nav -->
        <div class="bldr-qnav">
          <span class="bldr-qnum-label">Question <span id="qNumDisplay">1</span> of <span id="qTotalDisplay">1</span></span>
          <div class="bldr-qnav-arrows">
            <button class="bldr-arrow" id="btnPrev" title="Previous question" disabled>
              <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><polyline points="15 18 9 12 15 6"/></svg>
            </button>
            <button class="bldr-arrow" id="btnNext" title="Next question" disabled>
              <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 18 15 12 9 6"/></svg>
            </button>
            <button class="btn-add-q" id="btnAddQ" title="Add question">
              <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
              Add question
            </button>
          </div>
        </div>

        <!-- Question text input -->
        <textarea class="bldr-qtextarea" id="questionText" placeholder="Type your question here…" rows="4"></textarea>

        <button class="bldr-btn primary" id="btnSaveDraft" style="width:100%;justify-content:center;">
          Save draft
          <span class="bldr-btn-count" id="qCountBadge">1</span>
        </button>

        <!-- Type picker -->
        <div>
          <div class="bldr-type-label">Question type</div>
          <div class="bldr-type-select-wrap">
            <select class="bldr-type-select" id="typeSelect">
              <option value="likert">Likert Scale</option>
              <option value="open">Open-Ended</option>
              <option value="multiple">Multiple Choice</option>
              <option value="rating">Rating</option>
              <option value="slider">Sliding Scale</option>
              <option value="matrix">Matrix</option>
              <option value="ranking">Ranking</option>
              <option value="priority">Priority</option>
            </select>
          </div>
        </div>

        <!-- Type-specific options -->
        <div class="bldr-type-opts" id="typeOpts"></div>

        <!-- Question list -->
        <div class="bldr-qlist-section" id="qlistSection">
          <div class="bldr-qlist-head" id="qlistToggle">
            <span class="bldr-qlist-toggle">
              <span class="bldr-qlist-chevron">
                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 12 15 18 9"/></svg>
              </span>
              All questions
            </span>
          </div>
          <div class="bldr-qlist-body">
            <div class="bldr-qlist" id="questionList"></div>
          </div>
        </div>

      </div>
    </aside>

    <!-- Right panel: live preview -->
    <div class="bldr-right">
      <div class="bldr-preview-wrap">
        <div class="bldr-preview-card">
          <div class="preview-eyebrow" id="previewEyebrow">Question 1</div>
          <p class="preview-qtext is-placeholder" id="previewQText">Your question will appear here as you type…</p>
          <div class="preview-response" id="previewResponse"></div>
        </div>
        <div style="display:flex;justify-content:center;margin-top:20px;">
          <button class="bldr-btn" id="btnPreviewSurvey" style="padding:10px 28px;">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
            Preview survey
          </button>
        </div>
      </div>
    </div>

  </div><!-- /.bldr-body -->
</div><!-- /.bldr -->

<!-- ── Survey preview modal ── -->
<div class="prev-modal-backdrop" id="prevModalBackdrop" hidden>
  <div class="prev-modal" role="dialog" aria-modal="true" aria-labelledby="prevModalTitle">
    <div class="prev-modal-header">
      <span class="prev-modal-eyebrow" id="prevModalEyebrow">Preview</span>
      <h2 class="prev-modal-title" id="prevModalTitle"></h2>
      <button class="prev-modal-close" id="prevModalClose" aria-label="Close preview">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
      </button>
    </div>
    <div class="prev-modal-body" id="prevModalBody"></div>
    <div class="prev-modal-footer">
      <button class="bldr-btn" id="prevModalPrev">
        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><polyline points="15 18 9 12 15 6"/></svg>
        Back
      </button>
      <span class="prev-modal-progress" id="prevModalProgress">1 of 1</span>
      <button class="bldr-btn primary" id="prevModalNext">Next</button>
    </div>
  </div>
</div>

<style>
.prev-modal-backdrop {
  position: fixed; inset: 0; z-index: 1000;
  background: rgba(15,23,42,0.45);
  backdrop-filter: blur(4px);
  display: flex; align-items: center; justify-content: center;
  padding: 24px;
}
.prev-modal-backdrop[hidden] { display: none; }
.prev-modal {
  background: #fff; border-radius: 26px;
  box-shadow: 0 24px 64px rgba(15,23,42,0.22);
  width: 100%; max-width: 640px;
  display: flex; flex-direction: column;
  max-height: calc(100vh - 48px); overflow: hidden;
}
.prev-modal-header {
  display: flex; align-items: flex-start; gap: 12px;
  padding: 28px 32px 20px; border-bottom: 1px solid rgba(15,23,42,0.08);
  flex-shrink: 0;
}
.prev-modal-eyebrow {
  font-size: 11px; font-weight: 700; letter-spacing: 0.07em;
  text-transform: uppercase; color: var(--bldr-accent);
  margin-bottom: 4px; display: block;
}
.prev-modal-title {
  flex: 1; font-size: 20px; font-weight: 700;
  letter-spacing: -0.02em; line-height: 1.3;
  color: var(--bldr-text); margin: 0;
}
.prev-modal-close {
  flex-shrink: 0; width: 32px; height: 32px; border-radius: 8px;
  border: 1px solid rgba(15,23,42,0.08); background: transparent;
  color: var(--bldr-text-3); cursor: pointer;
  display: flex; align-items: center; justify-content: center;
  transition: background 0.12s, color 0.12s;
}
.prev-modal-close:hover { background: var(--bldr-bg); color: var(--bldr-text); }
.prev-modal-body {
  flex: 1; overflow-y: auto; padding: 28px 32px;
}
.prev-modal-footer {
  display: flex; align-items: center; justify-content: space-between;
  padding: 16px 32px; border-top: 1px solid rgba(15,23,42,0.08);
  flex-shrink: 0;
}
.prev-modal-progress {
  font-size: 13px; font-weight: 600; color: var(--bldr-text-3);
}
</style>

<script>
(function () {
  'use strict';

  /* ── State ── */
  var state = {
    questions: [mkQ(1)],
    current:   0,
    nextId:    2
  };

  function mkQ(id) {
    return {
      id:      id,
      text:    '',
      type:    'likert',
      opts:    { points: 5, minLabel: 'Strongly disagree', maxLabel: 'Strongly agree', stars: 5 },
      choices: ['Option 1', 'Option 2', 'Option 3']
    };
  }

  /* ── Element refs ── */
  var qTextarea      = document.getElementById('questionText');
  var previewQText   = document.getElementById('previewQText');
  var previewEyebrow = document.getElementById('previewEyebrow');
  var previewResp    = document.getElementById('previewResponse');
  var qNumDisplay    = document.getElementById('qNumDisplay');
  var qTotalDisplay  = document.getElementById('qTotalDisplay');
  var typeOpts       = document.getElementById('typeOpts');
  var questionList   = document.getElementById('questionList');
  var qCountBadge    = document.getElementById('qCountBadge');
  var btnPrev        = document.getElementById('btnPrev');
  var btnNext        = document.getElementById('btnNext');

  /* ── Helpers ── */
  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c];
    });
  }

  function q() { return state.questions[state.current]; }

  /* ── Live preview: question text ── */
  qTextarea.addEventListener('input', function () {
    q().text = qTextarea.value;
    renderPreviewText();
    renderQuestionList();
  });

  function renderPreviewText() {
    var txt = q().text.trim();
    if (txt) {
      previewQText.textContent = txt;
      previewQText.classList.remove('is-placeholder');
    } else {
      previewQText.textContent = 'Your question will appear here as you type…';
      previewQText.classList.add('is-placeholder');
    }
  }

  function renderPreviewEyebrow() {
    previewEyebrow.textContent = 'Question ' + (state.current + 1);
  }

  function renderNavCounters() {
    qNumDisplay.textContent   = state.current + 1;
    qTotalDisplay.textContent = state.questions.length;
    qCountBadge.textContent   = state.questions.length;
    btnPrev.disabled = state.current === 0;
    btnNext.disabled = state.current === state.questions.length - 1;
  }

  /* ── Type select ── */
  var typeSelect = document.getElementById('typeSelect');
  typeSelect.addEventListener('change', function () {
    q().type = typeSelect.value;
    renderTypeOpts();
    renderPreviewResponse();
  });

  /* ── Type options panel ── */
  function renderTypeOpts() {
    var cur = q();
    if (cur.type === 'likert') {
      var pts = cur.opts.points || 5;
      typeOpts.innerHTML =
        '<div class="opts-group-label">Scale points</div>' +
        '<div class="opts-scale-btns">' +
          [3, 4, 5, 6, 7].map(function (n) {
            return '<button class="scale-btn' + (pts === n ? ' active' : '') + '" data-points="' + n + '">' + n + '</button>';
          }).join('') +
        '</div>' +
        '<div class="opts-row">' +
          '<div class="opts-group-label" style="margin-top:4px">Low label</div>' +
          '<input class="opts-input" id="optMinLabel" type="text" value="' + esc(cur.opts.minLabel || 'Strongly disagree') + '" placeholder="Strongly disagree">' +
        '</div>' +
        '<div class="opts-row">' +
          '<div class="opts-group-label">High label</div>' +
          '<input class="opts-input" id="optMaxLabel" type="text" value="' + esc(cur.opts.maxLabel || 'Strongly agree') + '" placeholder="Strongly agree">' +
        '</div>';

      typeOpts.querySelectorAll('.scale-btn').forEach(function (b) {
        b.addEventListener('click', function () {
          q().opts.points = parseInt(b.dataset.points, 10);
          typeOpts.querySelectorAll('.scale-btn').forEach(function (x) { x.classList.toggle('active', x === b); });
          renderPreviewResponse();
        });
      });
      var minInp = document.getElementById('optMinLabel');
      var maxInp = document.getElementById('optMaxLabel');
      if (minInp) minInp.addEventListener('input', function () { q().opts.minLabel = minInp.value; renderPreviewResponse(); });
      if (maxInp) maxInp.addEventListener('input', function () { q().opts.maxLabel = maxInp.value; renderPreviewResponse(); });

    } else if (cur.type === 'open') {
      typeOpts.innerHTML = '<p class="opts-hint">Respondents type their answer freely. No additional configuration needed.</p>';

    } else if (cur.type === 'multiple') {
      renderMultipleOpts();

    } else if (cur.type === 'rating') {
      var stars = cur.opts.stars || 5;
      typeOpts.innerHTML =
        '<div class="opts-group-label">Number of stars</div>' +
        '<div class="opts-scale-btns">' +
          [3, 4, 5, 6, 7, 10].map(function (n) {
            return '<button class="scale-btn' + (stars === n ? ' active' : '') + '" data-stars="' + n + '">' + n + '</button>';
          }).join('') +
        '</div>';
      typeOpts.querySelectorAll('.scale-btn').forEach(function (b) {
        b.addEventListener('click', function () {
          q().opts.stars = parseInt(b.dataset.stars, 10);
          typeOpts.querySelectorAll('.scale-btn').forEach(function (x) { x.classList.toggle('active', x === b); });
          renderPreviewResponse();
        });
      });

    } else if (cur.type === 'slider') {
      var slMin  = cur.opts.sliderMin  != null ? cur.opts.sliderMin  : 0;
      var slMax  = cur.opts.sliderMax  != null ? cur.opts.sliderMax  : 100;
      var slStep = cur.opts.sliderStep != null ? cur.opts.sliderStep : 1;
      var slMinL = cur.opts.sliderMinLabel || '';
      var slMaxL = cur.opts.sliderMaxLabel || '';
      typeOpts.innerHTML =
        '<div class="opts-row">' +
          '<div class="opts-group-label">Minimum value</div>' +
          '<input class="opts-input" id="optSlMin" type="number" value="' + slMin + '">' +
        '</div>' +
        '<div class="opts-row">' +
          '<div class="opts-group-label">Maximum value</div>' +
          '<input class="opts-input" id="optSlMax" type="number" value="' + slMax + '">' +
        '</div>' +
        '<div class="opts-row">' +
          '<div class="opts-group-label">Step</div>' +
          '<input class="opts-input" id="optSlStep" type="number" min="1" value="' + slStep + '">' +
        '</div>' +
        '<div class="opts-row">' +
          '<div class="opts-group-label">Low label <span style="font-weight:400;text-transform:none;letter-spacing:0">(optional)</span></div>' +
          '<input class="opts-input" id="optSlMinLabel" type="text" value="' + esc(slMinL) + '" placeholder="e.g. Not at all">' +
        '</div>' +
        '<div class="opts-row">' +
          '<div class="opts-group-label">High label <span style="font-weight:400;text-transform:none;letter-spacing:0">(optional)</span></div>' +
          '<input class="opts-input" id="optSlMaxLabel" type="text" value="' + esc(slMaxL) + '" placeholder="e.g. Extremely">' +
        '</div>';

      function bindSliderOpts() {
        var minI  = document.getElementById('optSlMin');
        var maxI  = document.getElementById('optSlMax');
        var stepI = document.getElementById('optSlStep');
        var minLI = document.getElementById('optSlMinLabel');
        var maxLI = document.getElementById('optSlMaxLabel');
        if (minI)  minI.addEventListener('input',  function () { q().opts.sliderMin  = parseFloat(minI.value);  renderPreviewResponse(); });
        if (maxI)  maxI.addEventListener('input',  function () { q().opts.sliderMax  = parseFloat(maxI.value);  renderPreviewResponse(); });
        if (stepI) stepI.addEventListener('input', function () { q().opts.sliderStep = parseFloat(stepI.value) || 1; renderPreviewResponse(); });
        if (minLI) minLI.addEventListener('input', function () { q().opts.sliderMinLabel = minLI.value; renderPreviewResponse(); });
        if (maxLI) maxLI.addEventListener('input', function () { q().opts.sliderMaxLabel = maxLI.value; renderPreviewResponse(); });
      }
      bindSliderOpts();

    } else if (cur.type === 'matrix') {
      cur.matrixRows = cur.matrixRows || ['Statement 1', 'Statement 2', 'Statement 3'];
      cur.matrixCols = cur.matrixCols || ['Strongly disagree', 'Disagree', 'Neutral', 'Agree', 'Strongly agree'];
      renderMatrixOpts();

    } else if (cur.type === 'ranking') {
      cur.rankItems = cur.rankItems || ['Item 1', 'Item 2', 'Item 3'];
      renderRankingOpts();

    } else if (cur.type === 'priority') {
      cur.priorityItems  = cur.priorityItems  || ['Item 1', 'Item 2', 'Item 3'];
      cur.priorityPoints = cur.priorityPoints != null ? cur.priorityPoints : 100;
      renderPriorityOpts();
    }
  }

  function renderMultipleOpts() {
    var cur = q();
    cur.choices = cur.choices || ['Option 1', 'Option 2'];
    typeOpts.innerHTML =
      '<div class="opts-group-label">Answer options</div>' +
      '<div class="opts-choices" id="choicesList">' +
        cur.choices.map(function (c, i) {
          return '<div class="choice-row">' +
            '<input class="opts-input" type="text" value="' + esc(c) + '" data-idx="' + i + '" placeholder="Option ' + (i + 1) + '">' +
            '<button class="choice-remove" data-idx="' + i + '" title="Remove option">×</button>' +
            '</div>';
        }).join('') +
      '</div>' +
      '<button class="btn-add-choice" id="btnAddChoice">+ Add option</button>';

    typeOpts.querySelectorAll('.choice-row input').forEach(function (inp) {
      inp.addEventListener('input', function () {
        q().choices[+inp.dataset.idx] = inp.value;
        renderPreviewResponse();
      });
    });
    typeOpts.querySelectorAll('.choice-remove').forEach(function (btn) {
      btn.addEventListener('click', function () {
        q().choices.splice(+btn.dataset.idx, 1);
        renderMultipleOpts();
        renderPreviewResponse();
      });
    });
    var addChoiceBtn = document.getElementById('btnAddChoice');
    if (addChoiceBtn) {
      addChoiceBtn.addEventListener('click', function () {
        q().choices.push('Option ' + (q().choices.length + 1));
        renderMultipleOpts();
        renderPreviewResponse();
      });
    }
  }

  function renderMatrixOpts() {
    var cur = q();
    typeOpts.innerHTML =
      '<div class="opts-group-label">Row statements</div>' +
      '<div id="matrixRowsList">' +
        cur.matrixRows.map(function (r, i) {
          return '<div class="choice-row"><input class="opts-input" type="text" value="' + esc(r) + '" data-ridx="' + i + '" placeholder="Statement ' + (i+1) + '"><button class="choice-remove" data-ridx="' + i + '">×</button></div>';
        }).join('') +
      '</div>' +
      '<button class="btn-add-choice" id="btnAddRow">+ Add row</button>' +
      '<div class="opts-group-label" style="margin-top:14px">Column labels</div>' +
      '<div id="matrixColsList">' +
        cur.matrixCols.map(function (c, i) {
          return '<div class="choice-row"><input class="opts-input" type="text" value="' + esc(c) + '" data-cidx="' + i + '" placeholder="Column ' + (i+1) + '"><button class="choice-remove" data-cidx="' + i + '">×</button></div>';
        }).join('') +
      '</div>' +
      '<button class="btn-add-choice" id="btnAddCol">+ Add column</button>';

    typeOpts.querySelectorAll('[data-ridx]').forEach(function (el) {
      if (el.tagName === 'INPUT') el.addEventListener('input', function () { q().matrixRows[+el.dataset.ridx] = el.value; renderPreviewResponse(); });
      if (el.tagName === 'BUTTON') el.addEventListener('click', function () { q().matrixRows.splice(+el.dataset.ridx, 1); renderMatrixOpts(); renderPreviewResponse(); });
    });
    typeOpts.querySelectorAll('[data-cidx]').forEach(function (el) {
      if (el.tagName === 'INPUT') el.addEventListener('input', function () { q().matrixCols[+el.dataset.cidx] = el.value; renderPreviewResponse(); });
      if (el.tagName === 'BUTTON') el.addEventListener('click', function () { q().matrixCols.splice(+el.dataset.cidx, 1); renderMatrixOpts(); renderPreviewResponse(); });
    });
    var addRow = document.getElementById('btnAddRow');
    var addCol = document.getElementById('btnAddCol');
    if (addRow) addRow.addEventListener('click', function () { q().matrixRows.push('Statement ' + (q().matrixRows.length + 1)); renderMatrixOpts(); renderPreviewResponse(); });
    if (addCol) addCol.addEventListener('click', function () { q().matrixCols.push('Column ' + (q().matrixCols.length + 1)); renderMatrixOpts(); renderPreviewResponse(); });
  }

  function renderRankingOpts() {
    var cur = q();
    typeOpts.innerHTML =
      '<div class="opts-group-label">Items to rank</div>' +
      '<div id="rankItemsList">' +
        cur.rankItems.map(function (r, i) {
          return '<div class="choice-row"><input class="opts-input" type="text" value="' + esc(r) + '" data-ridx="' + i + '" placeholder="Item ' + (i+1) + '"><button class="choice-remove" data-ridx="' + i + '">×</button></div>';
        }).join('') +
      '</div>' +
      '<button class="btn-add-choice" id="btnAddRankItem">+ Add item</button>';

    typeOpts.querySelectorAll('[data-ridx]').forEach(function (el) {
      if (el.tagName === 'INPUT') el.addEventListener('input', function () { q().rankItems[+el.dataset.ridx] = el.value; renderPreviewResponse(); });
      if (el.tagName === 'BUTTON') el.addEventListener('click', function () { q().rankItems.splice(+el.dataset.ridx, 1); renderRankingOpts(); renderPreviewResponse(); });
    });
    var addBtn = document.getElementById('btnAddRankItem');
    if (addBtn) addBtn.addEventListener('click', function () { q().rankItems.push('Item ' + (q().rankItems.length + 1)); renderRankingOpts(); renderPreviewResponse(); });
  }

  function renderPriorityOpts() {
    var cur = q();
    typeOpts.innerHTML =
      '<div class="opts-group-label">Items to prioritize</div>' +
      '<div id="priorityItemsList">' +
        cur.priorityItems.map(function (r, i) {
          return '<div class="choice-row"><input class="opts-input" type="text" value="' + esc(r) + '" data-ridx="' + i + '" placeholder="Item ' + (i+1) + '"><button class="choice-remove" data-ridx="' + i + '">×</button></div>';
        }).join('') +
      '</div>' +
      '<button class="btn-add-choice" id="btnAddPriorityItem">+ Add item</button>' +
      '<div class="opts-row" style="margin-top:14px">' +
        '<div class="opts-group-label">Total points to allocate</div>' +
        '<input class="opts-input" id="optPriorityPoints" type="number" min="1" value="' + (cur.priorityPoints || 100) + '">' +
      '</div>';

    typeOpts.querySelectorAll('[data-ridx]').forEach(function (el) {
      if (el.tagName === 'INPUT') el.addEventListener('input', function () { q().priorityItems[+el.dataset.ridx] = el.value; renderPreviewResponse(); });
      if (el.tagName === 'BUTTON') el.addEventListener('click', function () { q().priorityItems.splice(+el.dataset.ridx, 1); renderPriorityOpts(); renderPreviewResponse(); });
    });
    var addBtn = document.getElementById('btnAddPriorityItem');
    if (addBtn) addBtn.addEventListener('click', function () { q().priorityItems.push('Item ' + (q().priorityItems.length + 1)); renderPriorityOpts(); renderPreviewResponse(); });
    var ptInp = document.getElementById('optPriorityPoints');
    if (ptInp) ptInp.addEventListener('input', function () { q().priorityPoints = parseInt(ptInp.value, 10) || 100; renderPreviewResponse(); });
  }

  /* ── Preview response control ── */
  function renderPreviewResponse() {
    var cur = q();
    if (cur.type === 'likert') {
      var pts   = cur.opts.points || 5;
      var minL  = cur.opts.minLabel || 'Strongly disagree';
      var maxL  = cur.opts.maxLabel || 'Strongly agree';
      var dots  = '';
      for (var n = 1; n <= pts; n++) {
        dots += '<label class="likert-point">' +
          '<input type="radio" name="prev_likert" value="' + n + '">' +
          '<span class="likert-dot"></span>' +
          '<span class="likert-num">' + n + '</span>' +
          '</label>';
      }
      previewResp.innerHTML =
        '<div class="likert-scale">' +
          '<div class="likert-row">' + dots + '</div>' +
          '<div class="likert-labels"><span>' + esc(minL) + '</span><span>' + esc(maxL) + '</span></div>' +
        '</div>';

    } else if (cur.type === 'open') {
      previewResp.innerHTML = '<textarea class="prev-open" placeholder="Respondent\'s answer…" disabled></textarea>';

    } else if (cur.type === 'multiple') {
      var choices = cur.choices || [];
      previewResp.innerHTML =
        '<div class="prev-choices">' +
          choices.map(function (c, i) {
            return '<label class="prev-choice">' +
              '<input type="checkbox">' +
              '<span>' + esc(c || 'Option ' + (i + 1)) + '</span>' +
              '</label>';
          }).join('') +
        '</div>';

    } else if (cur.type === 'rating') {
      var max   = cur.opts.stars || 5;
      var stars = '';
      for (var s = 1; s <= max; s++) {
        stars += '<button class="star-btn" data-val="' + s + '">★</button>';
      }
      previewResp.innerHTML = '<div class="prev-rating">' + stars + '</div>';
      previewResp.querySelectorAll('.star-btn').forEach(function (btn) {
        btn.addEventListener('click', function () {
          var val = +btn.dataset.val;
          previewResp.querySelectorAll('.star-btn').forEach(function (b, i) {
            b.classList.toggle('lit', i < val);
          });
        });
      });

    } else if (cur.type === 'slider') {
      var slMin  = cur.opts.sliderMin  != null ? cur.opts.sliderMin  : 0;
      var slMax  = cur.opts.sliderMax  != null ? cur.opts.sliderMax  : 100;
      var slStep = cur.opts.sliderStep != null ? cur.opts.sliderStep : 1;
      var slMinL = cur.opts.sliderMinLabel || '';
      var slMaxL = cur.opts.sliderMaxLabel || '';
      var mid    = Math.round((slMin + slMax) / 2);
      previewResp.innerHTML =
        '<div class="prev-slider-wrap">' +
          '<div class="prev-slider-meta">' +
            '<div class="prev-slider-labels" style="visibility:' + ((slMinL || slMaxL) ? 'visible' : 'hidden') + ';flex:1;margin-right:16px">' +
              '<span>' + esc(slMinL) + '</span>' +
              '<span>' + esc(slMaxL) + '</span>' +
            '</div>' +
            '<span class="prev-slider-value" id="sliderVal">' + mid + '</span>' +
          '</div>' +
          '<input class="prev-slider" id="prevSlider" type="range" min="' + slMin + '" max="' + slMax + '" step="' + slStep + '" value="' + mid + '">' +
          '<div class="prev-slider-labels">' +
            '<span>' + slMin + '</span>' +
            '<span>' + slMax + '</span>' +
          '</div>' +
        '</div>';

      var sliderEl  = document.getElementById('prevSlider');
      var sliderVal = document.getElementById('sliderVal');
      if (sliderEl && sliderVal) {
        sliderEl.addEventListener('input', function () {
          sliderVal.textContent = sliderEl.value;
          var pct = ((sliderEl.value - slMin) / (slMax - slMin)) * 100;
          sliderEl.style.background =
            'linear-gradient(to right, var(--bldr-accent) ' + pct + '%, rgba(15,23,42,0.12) ' + pct + '%)';
        });
      }

    } else if (cur.type === 'matrix') {
      var rows = cur.matrixRows || [];
      var cols = cur.matrixCols || [];
      var thead = '<tr><th></th>' + cols.map(function (c) { return '<th>' + esc(c || '—') + '</th>'; }).join('') + '</tr>';
      var tbody = rows.map(function (r, ri) {
        return '<tr><td>' + esc(r || 'Statement ' + (ri+1)) + '</td>' +
          cols.map(function (_, ci) {
            return '<td><input type="radio" name="mat_r' + ri + '" value="' + ci + '"></td>';
          }).join('') +
          '</tr>';
      }).join('');
      previewResp.innerHTML = '<div style="overflow-x:auto"><table class="prev-matrix"><thead>' + thead + '</thead><tbody>' + tbody + '</tbody></table></div>';

    } else if (cur.type === 'ranking') {
      var items = (cur.rankItems || []).slice();
      previewResp.innerHTML =
        '<div class="prev-ranking" id="rankPreview">' +
          items.map(function (item, i) {
            return '<div class="rank-item" draggable="true" data-idx="' + i + '">' +
              '<span class="rank-num">' + (i+1) + '</span>' +
              '<span class="rank-handle"><span></span><span></span><span></span></span>' +
              '<span class="rank-label">' + esc(item || 'Item ' + (i+1)) + '</span>' +
              '</div>';
          }).join('') +
        '</div>';

      // Simple drag-to-reorder in the preview
      var rankEl = document.getElementById('rankPreview');
      var dragged = null;
      if (rankEl) {
        rankEl.querySelectorAll('.rank-item').forEach(function (el) {
          el.addEventListener('dragstart', function () { dragged = el; el.classList.add('dragging'); });
          el.addEventListener('dragend',   function () { el.classList.remove('dragging'); dragged = null; reNumberRank(); });
          el.addEventListener('dragover',  function (e) {
            e.preventDefault();
            if (dragged && dragged !== el) {
              var rect = el.getBoundingClientRect();
              var after = e.clientY > rect.top + rect.height / 2;
              rankEl.insertBefore(dragged, after ? el.nextSibling : el);
            }
          });
        });
        function reNumberRank() {
          rankEl.querySelectorAll('.rank-item').forEach(function (el, i) {
            var numEl = el.querySelector('.rank-num');
            if (numEl) numEl.textContent = i + 1;
          });
        }
      }

    } else if (cur.type === 'priority') {
      var pitems = cur.priorityItems || [];
      var total  = cur.priorityPoints || 100;
      var perItem = pitems.length ? Math.floor(total / pitems.length) : 0;
      var allocated = pitems.map(function () { return perItem; });

      function buildPriorityPreview() {
        var usedTotal = allocated.reduce(function (a, b) { return a + b; }, 0);
        previewResp.innerHTML =
          '<div class="prev-priority">' +
            pitems.map(function (item, i) {
              var pct = total > 0 ? Math.min(100, (allocated[i] / total) * 100) : 0;
              return '<div class="priority-item" data-pidx="' + i + '">' +
                '<div class="priority-item-head">' +
                  '<span class="priority-item-label">' + esc(item || 'Item ' + (i+1)) + '</span>' +
                  '<span class="priority-item-val">' + allocated[i] + ' pts</span>' +
                '</div>' +
                '<div class="priority-bar-track"><div class="priority-bar-fill" style="width:' + pct + '%"></div></div>' +
                '<input type="range" min="0" max="' + total + '" value="' + allocated[i] + '" style="width:100%;margin-top:4px;accent-color:var(--bldr-accent)">' +
                '</div>';
            }).join('') +
            '<div class="priority-total"><span>Allocated</span><span class="priority-total-num" id="prioTotalNum">' + usedTotal + ' / ' + total + ' pts</span></div>' +
          '</div>';

        previewResp.querySelectorAll('.priority-item').forEach(function (wrap) {
          var idx   = +wrap.dataset.pidx;
          var range = wrap.querySelector('input[type="range"]');
          var val   = wrap.querySelector('.priority-item-val');
          var fill  = wrap.querySelector('.priority-bar-fill');
          if (!range) return;
          range.addEventListener('input', function () {
            allocated[idx] = +range.value;
            val.textContent = allocated[idx] + ' pts';
            var pct = total > 0 ? Math.min(100, (allocated[idx] / total) * 100) : 0;
            fill.style.width = pct + '%';
            var used = allocated.reduce(function (a, b) { return a + b; }, 0);
            var tot  = document.getElementById('prioTotalNum');
            if (tot) tot.textContent = used + ' / ' + total + ' pts';
          });
        });
      }
      buildPriorityPreview();
    }
  }

  /* ── Navigation ── */
  function loadQuestion(idx) {
    state.current = Math.max(0, Math.min(idx, state.questions.length - 1));
    var cur = q();
    qTextarea.value = cur.text;
    typeSelect.value = cur.type;
    renderPreviewText();
    renderPreviewEyebrow();
    renderNavCounters();
    renderTypeOpts();
    renderPreviewResponse();
    renderQuestionList();
    qTextarea.focus();
  }

  btnPrev.addEventListener('click', function () { if (state.current > 0) loadQuestion(state.current - 1); });
  btnNext.addEventListener('click', function () { if (state.current < state.questions.length - 1) loadQuestion(state.current + 1); });

  /* ── Question list dropdown toggle ── */
  var qlistSection = document.getElementById('qlistSection');
  var qlistToggle  = document.getElementById('qlistToggle');
  if (qlistToggle) {
    qlistToggle.addEventListener('click', function (e) {
      // Don't collapse when clicking the Add button inside the header
      if (e.target.closest('#btnAddQ')) return;
      qlistSection.classList.toggle('is-open');
    });
  }

  /* ── Add question ── */
  document.getElementById('btnAddQ').addEventListener('click', function () {
    state.questions.push(mkQ(state.nextId++));
    loadQuestion(state.questions.length - 1);
  });

  /* ── Question list ── */
  function renderQuestionList() {
    questionList.innerHTML = state.questions.map(function (sq, i) {
      var label = sq.text.trim().slice(0, 42) || 'Untitled question';
      if (sq.text.trim().length > 42) label += '…';
      return '<div class="qlist-item' + (i === state.current ? ' active' : '') + '" data-idx="' + i + '">' +
        '<span class="qlist-num">Q' + (i + 1) + '</span>' +
        '<span class="qlist-text">' + esc(label) + '</span>' +
        '</div>';
    }).join('');
    questionList.querySelectorAll('.qlist-item').forEach(function (item) {
      item.addEventListener('click', function () { loadQuestion(+item.dataset.idx); });
    });
  }

  /* ── Preview modal ── */
  var prevBackdrop  = document.getElementById('prevModalBackdrop');
  var prevTitle     = document.getElementById('prevModalTitle');
  var prevEyebrow   = document.getElementById('prevModalEyebrow');
  var prevBody      = document.getElementById('prevModalBody');
  var prevProgress  = document.getElementById('prevModalProgress');
  var prevModalNext = document.getElementById('prevModalNext');
  var prevModalPrev = document.getElementById('prevModalPrev');
  var prevIdx       = 0;

  function openPreview() {
    prevIdx = 0;
    renderModalQuestion();
    prevBackdrop.hidden = false;
    document.body.style.overflow = 'hidden';
  }

  function closePreview() {
    prevBackdrop.hidden = true;
    document.body.style.overflow = '';
  }

  function renderModalQuestion() {
    var total = state.questions.length;
    var sq    = state.questions[prevIdx];
    prevEyebrow.textContent  = 'Question ' + (prevIdx + 1) + ' of ' + total;
    prevTitle.textContent    = sq.text.trim() || 'Untitled question';
    prevProgress.textContent = (prevIdx + 1) + ' of ' + total;
    prevModalPrev.disabled   = prevIdx === 0;
    prevModalNext.textContent = prevIdx === total - 1 ? 'Done' : 'Next';

    // Render the response control into the modal body
    // Temporarily swap previewResp to the modal body, render, then restore
    var origResp = previewResp;
    previewResp  = prevBody;
    var origQ    = state.current;
    state.current = prevIdx;
    renderPreviewResponse();
    state.current = origQ;
    previewResp  = origResp;
  }

  document.getElementById('btnPreviewSurvey').addEventListener('click', openPreview);
  document.getElementById('prevModalClose').addEventListener('click', closePreview);
  prevBackdrop.addEventListener('click', function (e) { if (e.target === prevBackdrop) closePreview(); });
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && !prevBackdrop.hidden) closePreview(); });

  prevModalNext.addEventListener('click', function () {
    if (prevIdx < state.questions.length - 1) {
      prevIdx++;
      renderModalQuestion();
    } else {
      closePreview();
    }
  });
  prevModalPrev.addEventListener('click', function () {
    if (prevIdx > 0) { prevIdx--; renderModalQuestion(); }
  });

  /* ── Save draft ── */
  var surveyId = <?= (int)$load_survey_id ?>; // pre-set when opened via ?id=N

  function saveDraft(callback) {
    var btn   = document.getElementById('btnSaveDraft');
    var title = document.getElementById('surveyTitle').value.trim() || 'Untitled survey';
    var payload = { title: title, questions: state.questions };
    if (surveyId > 0) payload.id = surveyId;

    var orig = btn.innerHTML;
    btn.textContent = 'Saving…';
    btn.disabled = true;

    fetch('/api/surveys/save.php', {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
      body: JSON.stringify(payload)
    })
    .then(function (r) { return r.json(); })
    .then(function (data) {
      if (data && data.ok) {
        surveyId = data.id;
        btn.textContent = 'Saved ✓';
        setTimeout(function () { btn.innerHTML = orig; btn.disabled = false; }, 2000);
        if (typeof callback === 'function') callback(data);
      } else {
        btn.innerHTML = orig;
        btn.disabled = false;
        alert('Save failed. Please try again.');
      }
    })
    .catch(function () {
      btn.innerHTML = orig;
      btn.disabled = false;
      alert('Save failed — check your connection.');
    });
  }

  document.getElementById('btnSaveDraft').addEventListener('click', function () { saveDraft(); });

  /* ── Init ── load existing survey if ?id=N was supplied ── */
  if (surveyId > 0) {
    fetch('/api/surveys/get.php?id=' + surveyId, { credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
      .then(function (r) { return r.ok ? r.json() : null; })
      .then(function (data) {
        if (!data || !data.survey) { loadQuestion(0); return; }
        var sv = data.survey;
        // Restore title
        var titleEl = document.getElementById('surveyTitle');
        if (titleEl && sv.title) titleEl.value = sv.title;
        // Restore builder questions from settings.builderQuestions if present
        var bq = sv.settings && Array.isArray(sv.settings.builderQuestions) ? sv.settings.builderQuestions : null;
        if (bq && bq.length) {
          state.questions = bq;
          state.nextId    = bq.reduce(function (max, q) { return Math.max(max, +q.id || 0); }, 0) + 1;
        }
        loadQuestion(0);
      })
      .catch(function () { loadQuestion(0); });
  } else {
    loadQuestion(0);
  }

  /* ── ReliCheck Intelligence: live survey check (RSCI) ── */
  var rsciBtn      = document.getElementById('btnRsci');
  var rsciBadge    = document.getElementById('rsciBadge');
  var rsciBackdrop = document.getElementById('rsciBackdrop');
  var rsciOver     = document.getElementById('rsciOver');

  var GROUP_DIM = (window.RSCIEngine && window.RSCIEngine.GROUP_DIM) || {};
  var GROUP_LABEL = { questionQuality:'Validity', constructCoverage:'Validity', scaleStrength:'Reliability', surveyFlow:'Reliability' };

  function rsciOpen() {
    if (!window.RSCIEngine) return;
    var res = window.RSCIEngine.assess(state.questions);
    // No single overall — the badge shows the weaker dimension, the gating one.
    var gate = res.validity.score <= res.reliability.score ? res.validity : res.reliability;
    rsciBadge.hidden = false;
    rsciBadge.textContent = gate.score;
    rsciBadge.setAttribute('data-tier', gate.tier.key);
    rsciRender(res);
    rsciBackdrop.classList.add('open');
    rsciOver.classList.add('open');
  }
  function rsciClose() {
    rsciBackdrop.classList.remove('open');
    rsciOver.classList.remove('open');
  }

  function rsciRender(res) {
    document.getElementById('rsciDims').innerHTML =
      dim('Validity', res.validity) + dim('Reliability', res.reliability);

    var g = res.groups;
    document.getElementById('rsciSubs').innerHTML =
      sub(g.questionQuality.label, g.questionQuality.score, 'Validity') +
      sub(g.constructCoverage.label, g.constructCoverage.score, 'Validity') +
      sub(g.scaleStrength.label, g.scaleStrength.score, 'Reliability') +
      sub(g.surveyFlow.label, g.surveyFlow.score, 'Reliability');

    var all = [];
    res.items.forEach(function (it) {
      it.flags.forEach(function (f) {
        all.push({ sev: f.severity, label: f.label, fix: f.fix, q: 'Q' + (it.index + 1), dim: GROUP_LABEL[f.group] || '', o: sevOrder(f.severity) });
      });
    });
    res.surveyFlags.forEach(function (f) {
      all.push({ sev: f.severity, label: f.label, fix: f.fix, q: 'Survey', dim: GROUP_LABEL[f.group] || '', o: sevOrder(f.severity) });
    });
    all.sort(function (a, b) { return a.o - b.o; });

    var host = document.getElementById('rsciFindings');
    if (!all.length) {
      host.innerHTML = '<div class="rsci-clear"><strong>No design issues found.</strong>This survey is clear, well-scaled, and ready to deploy.</div>';
      return;
    }
    host.innerHTML = all.map(function (f) {
      return '<div class="rsci-finding"><div class="ft"><span class="rsci-sev rsci-sev-' + f.sev + '">' + f.sev + '</span>' +
        (f.dim ? '<span class="rsci-fdim">' + esc(f.dim) + '</span>' : '') +
        '<span class="rsci-fq">' + esc(f.q) + '</span></div>' +
        '<div class="lbl">' + esc(f.label) + '</div>' +
        '<div class="fix"><b>Fix:</b> ' + esc(f.fix) + '</div></div>';
    }).join('');
  }
  function dim(lbl, d) {
    return '<div class="rsci-dim"><div class="rsci-dim-row">' +
      '<span class="rsci-dim-name">' + lbl + '</span>' +
      '<span class="rsci-dim-num">' + d.score + '<small>/100</small></span></div>' +
      '<span class="rsci-over-tier tier-' + d.tier.key + '">' + esc(d.tier.label) + '</span></div>';
  }
  function sub(lbl, val, dimName) {
    return '<div class="rsci-sub"><div class="lbl">' + esc(lbl) + '</div>' +
      '<div class="val">' + val + '<small style="font-size:12px;color:#8E8E93;font-weight:500;">%</small></div>' +
      '<div class="rsci-sub-dim">' + esc(dimName) + '</div></div>';
  }
  function sevOrder(s) { return s === 'critical' ? 0 : s === 'major' ? 1 : s === 'minor' ? 2 : 3; }

  if (rsciBtn)      rsciBtn.addEventListener('click', rsciOpen);
  if (rsciBackdrop) rsciBackdrop.addEventListener('click', rsciClose);
  var rsciCloseBtn = document.getElementById('rsciOverClose');
  if (rsciCloseBtn) rsciCloseBtn.addEventListener('click', rsciClose);
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape') rsciClose(); });
})();
</script>

<!-- ReliCheck Survey Checker Index — slide-over -->
<div class="rsci-over-backdrop" id="rsciBackdrop"></div>
<aside class="rsci-over" id="rsciOver" aria-label="Survey Checker Index">
  <div class="rsci-over-head">
    <div>
      <div class="eyebrow">ReliCheck Survey Checker Index</div>
      <h3>Predicted validity &amp; reliability</h3>
    </div>
    <button class="rsci-over-close" id="rsciOverClose" type="button" aria-label="Close">
      <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6L6 18"/><path d="M6 6l12 12"/></svg>
    </button>
  </div>
  <div class="rsci-over-body">
    <div class="rsci-dims" id="rsciDims"></div>
    <div class="rsci-subgrid" id="rsciSubs"></div>
    <p class="rsci-fence">Internal consistency and items-per-construct count toward reliability only &mdash; never validity.</p>
    <h4>What to fix</h4>
    <div id="rsciFindings"></div>
  </div>
</aside>
<script src="/apps/rsci/rsci-engine.js"></script>

<?php include __DIR__ . '/_platform_shell_footer.php'; ?>
