<?php
// apps/deploy/render.php
// Survey Deployment Module.
// Compact main view (status + summary + actions) with a step-wizard modal
// holding the 7 configuration sections. No long-scroll page.

$__deploySlug   = '';
$__deployConfig = null;
if (!empty($pdo) && $projectId > 0) {
    try {
        $__ds = $pdo->prepare('SELECT slug, settings FROM surveys WHERE id = :id LIMIT 1');
        $__ds->execute([':id' => $projectId]);
        $__row = $__ds->fetch(PDO::FETCH_ASSOC);
        if ($__row) {
            $__deploySlug = (string)$__row['slug'];
            $__set = json_decode((string)$__row['settings'], true) ?: [];
            if (isset($__set['deployment']) && is_array($__set['deployment'])) {
                $__deployConfig = $__set['deployment'];
            }
        }
    } catch (Throwable $__de) {}
}
?>
<script>
  window.RELICHECK_PROJECT_ID    = <?= json_encode($projectId) ?>;
  window.RELICHECK_SURVEY_SLUG   = <?= json_encode($__deploySlug) ?>;
  window.RELICHECK_DEPLOY_CONFIG = <?= json_encode($__deployConfig, JSON_UNESCAPED_UNICODE) ?>;
</script>

<div class="deploy-wrap">

  <!-- ══════════════════════════════════════════════════════════════
       PAGE HEADER
  ══════════════════════════════════════════════════════════════ -->
  <div class="deploy-head">
    <h1>Survey Deployment</h1>
    <p>Configure your survey, then launch when the readiness score reaches green.</p>
  </div>

  <!-- ══════════════════════════════════════════════════════════════
       MAIN VIEW — compact, two columns
  ══════════════════════════════════════════════════════════════ -->
  <div class="deploy-cols">

    <!-- ── LEFT COLUMN: Status + Summary + Preview ────────────── -->
    <div class="deploy-main">

      <!-- Big status hero card -->
      <div class="dp-rail-card dp-hero">
        <div class="dp-hero-grid">

          <!-- Score ring -->
          <div class="dp-hero-score">
            <div style="position:relative;width:160px;height:160px;display:inline-flex;align-items:center;justify-content:center;">
              <svg id="scoreRingSvg" width="160" height="160" viewBox="0 0 160 160" aria-hidden="true" style="position:absolute;top:0;left:0;">
                <circle cx="80" cy="80" r="62"
                  fill="none"
                  stroke="#e9eaec"
                  stroke-width="12"/>
                <circle cx="80" cy="80" r="62"
                  fill="none"
                  stroke="#c2492f"
                  stroke-width="12"
                  stroke-linecap="round"
                  stroke-dasharray="389.56"
                  stroke-dashoffset="389.56"
                  transform="rotate(-90 80 80)"
                  data-ring
                  style="transition: stroke-dashoffset 0.5s ease, stroke 0.4s ease;"/>
              </svg>
              <span id="readinessScore" style="font-size:48px;font-weight:800;letter-spacing:-0.03em;line-height:1;color:var(--score-color,#c2492f);transition:color 0.4s;position:relative;z-index:1;">0</span>
            </div>
            <p class="dp-score-label">readiness score</p>
            <div class="dp-status-badge not-ready" id="readinessStatus">
              <span class="sdot"></span>
              <span class="status-label">Not Ready</span>
            </div>
          </div>

          <!-- Actions -->
          <div class="dp-hero-actions">
            <button class="dp-action-btn primary dp-btn-lg" id="btnConfigure" type="button">
              <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 20h9"/><path d="M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4L16.5 3.5z"/></svg>
              Configure deployment
            </button>

            <button class="dp-action-btn launch dp-btn-lg" id="btnLaunch" type="button" disabled>
              <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 3l14 9-14 9V3z"/></svg>
              Launch survey
            </button>

            <div class="dp-hero-sub-actions">
              <button class="dp-action-btn ghost" id="btnCopyLink" type="button">
                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="9" y="9" width="13" height="13" rx="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></svg>
                Copy link
              </button>
              <button class="dp-action-btn ghost" id="btnResolveIssues" type="button">
                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="10"/><path d="M12 8v4"/><circle cx="12" cy="16" r="0.8" fill="currentColor" stroke="none"/></svg>
                Resolve issues
              </button>
              <button class="dp-action-btn ghost" id="btnSendTest" type="button">
                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M22 2L11 13"/><path d="M22 2L15 22 11 13 2 9l20-7z"/></svg>
                Send test
              </button>
            </div>
          </div>

        </div><!-- /.dp-hero-grid -->
      </div><!-- /hero card -->

      <!-- Configuration summary (clickable rows open the modal) -->
      <div class="dp-rail-card">
        <div class="dp-rail-card-head-row">
          <p class="dp-rail-card-title" style="margin:0;">Configuration</p>
          <button class="dp-link-btn" id="btnEditConfig" type="button">Edit →</button>
        </div>
        <ul class="dp-summary-list" id="launchSummaryList">
          <li class="dp-summary-row" data-jump="audience">
            <span class="dp-summary-label">Audience</span>
            <span class="dp-summary-value">—</span>
          </li>
          <li class="dp-summary-row" data-jump="access">
            <span class="dp-summary-label">Access</span>
            <span class="dp-summary-value">—</span>
          </li>
          <li class="dp-summary-row" data-jump="identity">
            <span class="dp-summary-label">Identity</span>
            <span class="dp-summary-value">—</span>
          </li>
          <li class="dp-summary-row" data-jump="channels">
            <span class="dp-summary-label">Channel</span>
            <span class="dp-summary-value">—</span>
          </li>
          <li class="dp-summary-row" data-jump="schedule">
            <span class="dp-summary-label">Schedule</span>
            <span class="dp-summary-value">Launch now</span>
          </li>
          <li class="dp-summary-row" data-jump="reminders">
            <span class="dp-summary-label">Reminders</span>
            <span class="dp-summary-value">—</span>
          </li>
        </ul>
      </div>

    </div><!-- /.deploy-main -->

    <!-- ── RIGHT COLUMN: Checklist + Mobile preview ───────────── -->
    <div class="deploy-rail">

      <!-- Readiness checklist -->
      <div class="dp-rail-card">
        <p class="dp-rail-card-title">Readiness checklist</p>
        <div class="dp-checklist" id="readinessChecklist">
          <?php
            $__checks = [
              'audience'  => 'Target audience defined',
              'identity'  => 'Identity mode selected',
              'access'    => 'Access type configured',
              'channel'   => 'Deployment channel selected',
              'schedule'  => 'Launch date configured',
              'reminder'  => 'Reminder plan selected',
              'preview'   => 'Mobile preview reviewed',
              'intro'     => 'Introduction / consent text added',
              'statement' => 'Confidentiality statement present',
              'length'    => 'Survey length is reasonable',
              'openended' => 'Open-ended balance is good',
            ];
            $__defaultPass = ['schedule', 'length', 'openended'];
            $__svgFail = '<svg width="16" height="16" viewBox="0 0 16 16" fill="none" aria-hidden="true"><circle cx="8" cy="8" r="8" fill="#e5e7eb"/><path d="M5.5 5.5l5 5M10.5 5.5l-5 5" stroke="#9ca3af" stroke-width="1.5" stroke-linecap="round"/></svg>';
            $__svgPass = '<svg width="16" height="16" viewBox="0 0 16 16" fill="none" aria-hidden="true"><circle cx="8" cy="8" r="8" fill="#0e8a6f"/><path d="M4.5 8l2.5 2.5 4.5-4.5" stroke="#fff" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg>';
            foreach ($__checks as $__k => $__lbl):
              $__pass = in_array($__k, $__defaultPass, true);
          ?>
          <div class="dp-check-item <?= $__pass ? 'dp-check-pass' : 'dp-check-fail' ?>" data-check="<?= $__k ?>">
            <span class="dp-check-icon"><?= $__pass ? $__svgPass : $__svgFail ?></span>
            <div class="dp-check-body">
              <span class="dp-check-text"><?= htmlspecialchars($__lbl) ?></span>
            </div>
          </div>
          <?php endforeach; ?>
        </div>
      </div>

      <!-- Mobile preview -->
      <div class="dp-rail-card" id="sectionPreview">
        <p class="dp-rail-card-title">Respondent preview</p>
        <div class="dp-mobile-preview">
          <div class="dp-mobile-frame" id="mobilePreviewFrame">
            <div class="dp-mobile-screen">
              <div class="dp-mobile-q">How satisfied are you with...?</div>
              <div class="dp-mobile-opt">⭕ Very satisfied</div>
              <div class="dp-mobile-opt">⭕ Satisfied</div>
              <div class="dp-mobile-opt">⭕ Neutral</div>
              <div class="dp-mobile-opt">⭕ Dissatisfied</div>
              <div class="dp-mobile-btn">Next →</div>
            </div>
          </div>
        </div>
        <button class="dp-action-btn ghost" id="btnPreviewMobile" type="button" style="margin-top:14px;">
          <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="5" y="2" width="14" height="20" rx="2"/><circle cx="12" cy="17.5" r="0.8" fill="currentColor" stroke="none"/></svg>
          Preview mobile view
        </button>
      </div>

    </div><!-- /.deploy-rail -->
  </div><!-- /.deploy-cols -->
</div><!-- /.deploy-wrap -->


<!-- ══════════════════════════════════════════════════════════════
     CONFIGURATION MODAL — hidden by default
     Steps 1-7 live here; opens via #btnConfigure, #btnEditConfig,
     summary row clicks, or #btnResolveIssues.
══════════════════════════════════════════════════════════════ -->
<div class="dp-modal-backdrop" id="configModal" hidden>
  <div class="dp-modal" role="dialog" aria-labelledby="configModalTitle" aria-modal="true">

    <!-- Modal head -->
    <header class="dp-modal-head">
      <div>
        <h2 id="configModalTitle">Configure Deployment</h2>
        <p class="dp-modal-sub" id="configModalStep">Step 1 of 7 · Target Audience</p>
      </div>
      <button class="dp-modal-close" id="btnCloseModal" type="button" aria-label="Close">
        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M18 6L6 18"/><path d="M6 6l12 12"/></svg>
      </button>
    </header>

    <!-- Step tab nav -->
    <nav class="dp-step-nav" id="stepNav">
      <button class="dp-step-tab active" data-step="audience"  type="button"><span class="step-num">1</span><span class="step-name">Audience</span></button>
      <button class="dp-step-tab"        data-step="access"    type="button"><span class="step-num">2</span><span class="step-name">Access</span></button>
      <button class="dp-step-tab"        data-step="identity"  type="button"><span class="step-num">3</span><span class="step-name">Identity</span></button>
      <button class="dp-step-tab"        data-step="channels"  type="button"><span class="step-num">4</span><span class="step-name">Channels</span></button>
      <button class="dp-step-tab"        data-step="schedule"  type="button"><span class="step-num">5</span><span class="step-name">Schedule</span></button>
      <button class="dp-step-tab"        data-step="reminders" type="button"><span class="step-num">6</span><span class="step-name">Reminders</span></button>
      <button class="dp-step-tab"        data-step="branding"  type="button"><span class="step-num">7</span><span class="step-name">Branding</span></button>
    </nav>

    <!-- Modal body — only the active pane is visible -->
    <div class="dp-modal-body">

      <!-- ── 1. AUDIENCE ──────────────────────────────────────── -->
      <div class="dp-step-pane active" data-pane="audience">
        <div class="dp-section" id="sectionAudience">
          <div class="dp-section-head">
            <div class="dp-section-head-text">
              <h2>Target Audience</h2>
              <p class="dp-section-desc">Who is this survey for? Selecting an audience type unlocks the right access controls and reminders.</p>
            </div>
          </div>

          <p class="dp-subsection-label">Audience type</p>
          <div class="dp-audience-grid">
            <div class="dp-type-pill" data-audience="open">
              <span class="pill-icon">🌐</span>
              <span class="pill-label">Open Public</span>
              <span class="pill-sub">Anyone with the link can respond</span>
            </div>
            <div class="dp-type-pill" data-audience="private">
              <span class="pill-icon">✉️</span>
              <span class="pill-label">Private Invited</span>
              <span class="pill-sub">Only people you invite directly</span>
            </div>
            <div class="dp-type-pill" data-audience="roster">
              <span class="pill-icon">📋</span>
              <span class="pill-label">Uploaded Roster</span>
              <span class="pill-sub">Match responses to a known list</span>
            </div>
            <div class="dp-type-pill" data-audience="domain">
              <span class="pill-icon">🏢</span>
              <span class="pill-label">Domain / Org</span>
              <span class="pill-sub">Restricted to a specific domain</span>
            </div>
            <div class="dp-type-pill" data-audience="panel">
              <span class="pill-icon">💼</span>
              <span class="pill-label">Panel / Paid</span>
              <span class="pill-sub">Recruited or compensated panel</span>
            </div>
          </div>

          <hr class="dp-divider">

          <p class="dp-subsection-label">Segmentation variables <span class="dp-chip">optional — select all that apply</span></p>
          <div class="dp-seg-grid">
            <span class="dp-seg-tag" data-seg="age">Age</span>
            <span class="dp-seg-tag" data-seg="gender">Gender</span>
            <span class="dp-seg-tag" data-seg="role">Role / Job title</span>
            <span class="dp-seg-tag" data-seg="department">Department</span>
            <span class="dp-seg-tag" data-seg="location">Location</span>
            <span class="dp-seg-tag" data-seg="tenure">Tenure</span>
            <span class="dp-seg-tag" data-seg="education">Education</span>
            <span class="dp-seg-tag" data-seg="ethnicity">Ethnicity</span>
            <span class="dp-seg-tag" data-seg="grade">Grade / Year</span>
            <span class="dp-seg-tag" data-seg="income">Income band</span>
            <span class="dp-seg-tag" data-seg="custom">Custom variable</span>
          </div>
        </div>
      </div>

      <!-- ── 2. ACCESS ─────────────────────────────────────────── -->
      <div class="dp-step-pane" data-pane="access" hidden>
        <div class="dp-section" id="sectionAccess">
          <div class="dp-section-head">
            <div class="dp-section-head-text">
              <h2>Access Control</h2>
              <p class="dp-section-desc">Choose how respondents reach your survey and whether duplicate responses are blocked.</p>
            </div>
          </div>

          <div class="dp-access-stack">
            <div class="dp-access-item" data-access="open">
              <div class="dp-access-icon">🔗</div>
              <div class="dp-access-text">
                <span class="label">Open link</span>
                <span class="sub">Single public URL — anyone who has it can respond</span>
              </div>
              <div class="dp-radio-dot"></div>
            </div>
            <div class="dp-access-item" data-access="private">
              <div class="dp-access-icon">📨</div>
              <div class="dp-access-text">
                <span class="label">Unique links</span>
                <span class="sub">Each invitee receives a personalised, one-use URL</span>
              </div>
              <div class="dp-radio-dot"></div>
            </div>
            <div class="dp-access-item" data-access="password">
              <div class="dp-access-icon">🔑</div>
              <div class="dp-access-text">
                <span class="label">Password-protected</span>
                <span class="sub">Respondents must enter a shared passphrase to proceed</span>
              </div>
              <div class="dp-radio-dot"></div>
            </div>
            <div class="dp-access-item" data-access="domain">
              <div class="dp-access-icon">🏛️</div>
              <div class="dp-access-text">
                <span class="label">Domain-restricted</span>
                <span class="sub">Only email addresses matching your domain can access</span>
              </div>
              <div class="dp-radio-dot"></div>
            </div>
            <div class="dp-access-item" data-access="roster">
              <div class="dp-access-icon">✅</div>
              <div class="dp-access-text">
                <span class="label">Roster-only</span>
                <span class="sub">Responses are matched to an uploaded participant list</span>
              </div>
              <div class="dp-radio-dot"></div>
            </div>
          </div>

          <div class="dp-toggle-row">
            <div class="dp-toggle" id="toggleOnePerPerson" role="switch" aria-checked="false" aria-label="One response per person"></div>
            <span class="dp-toggle-label">Limit to one response per person (blocks duplicates via cookie + fingerprint)</span>
          </div>
        </div>
      </div>

      <!-- ── 3. IDENTITY MODE ───────────────────────────────────── -->
      <div class="dp-step-pane" data-pane="identity" hidden>
        <div class="dp-section" id="sectionIdentity">
          <div class="dp-section-head">
            <div class="dp-section-head-text">
              <h2>Identity Mode</h2>
              <p class="dp-section-desc">How is respondent identity handled? This affects data ethics, IRB compliance, and what participants are told.</p>
            </div>
          </div>

          <div class="dp-identity-grid">
            <div class="dp-identity-card anonymous" data-identity="anonymous">
              <div class="id-icon">🕶️</div>
              <span class="dp-identity-badge" style="background:#dbeafe;color:#1e40af;">Anonymous</span>
              <h3>Fully Anonymous</h3>
              <p>No identifying data collected. IP addresses are not logged. Responses cannot be linked to individuals.</p>
              <p class="id-trust">Highest trust signal for sensitive topics. IRB-friendly.</p>
            </div>
            <div class="dp-identity-card confidential" data-identity="confidential">
              <div class="id-icon">🔒</div>
              <span class="dp-identity-badge" style="background:#fef3c7;color:#92400e;">Confidential</span>
              <h3>Confidential</h3>
              <p>Identity is known to the researcher but not disclosed in reports. Data is aggregated before sharing.</p>
              <p class="id-trust">Common for workplace and HR surveys. Requires a confidentiality statement.</p>
            </div>
            <div class="dp-identity-card identified" data-identity="identified">
              <div class="id-icon">👤</div>
              <span class="dp-identity-badge" style="background:#d1fae5;color:#065f46;">Identified</span>
              <h3>Identified</h3>
              <p>Respondent names or IDs are collected and tied to responses. Participants are informed of this upfront.</p>
              <p class="id-trust">Used for longitudinal studies, follow-ups, and incentivised research.</p>
            </div>
            <div class="dp-identity-card completion" data-identity="completion">
              <div class="id-icon">📊</div>
              <span class="dp-identity-badge" style="background:#ede9fe;color:#5b21b6;">Completion-tracked</span>
              <h3>Completion-Tracked</h3>
              <p>System knows who has responded (for reminder targeting) but individual answers remain unlinked.</p>
              <p class="id-trust">Balanced approach for organisations needing response-rate visibility without full identification.</p>
            </div>
          </div>
        </div>
      </div>

      <!-- ── 4. DEPLOYMENT CHANNEL ──────────────────────────────── -->
      <div class="dp-step-pane" data-pane="channels" hidden>
        <div class="dp-section" id="sectionChannels">
          <div class="dp-section-head">
            <div class="dp-section-head-text">
              <h2>Deployment Channel</h2>
              <p class="dp-section-desc">Select all channels you'll use to distribute this survey. Multi-channel tracking is supported.</p>
            </div>
          </div>

          <div class="dp-channel-grid">
            <div class="dp-channel-card" data-channel="link">
              <div class="dp-channel-check">✓</div>
              <div class="ch-icon">🔗</div>
              <div class="ch-name">Direct link</div>
              <div class="ch-desc">Share the survey URL anywhere — social media, websites, documents.</div>
              <div class="ch-best">Best for: open public surveys</div>
              <div class="ch-setup">Copy link</div>
            </div>
            <div class="dp-channel-card" data-channel="email">
              <div class="dp-channel-check">✓</div>
              <div class="ch-icon">✉️</div>
              <div class="ch-name">Email</div>
              <div class="ch-desc">Send personalised invitations to a contact list via your email client.</div>
              <div class="ch-best">Best for: private invited audiences</div>
              <div class="ch-setup">Download template</div>
            </div>
            <div class="dp-channel-card" data-channel="embed">
              <div class="dp-channel-check">✓</div>
              <div class="ch-icon">🖼️</div>
              <div class="ch-name">Embedded</div>
              <div class="ch-desc">Embed the survey inline on a webpage using a one-line script tag.</div>
              <div class="ch-best">Best for: website intercepts</div>
              <div class="ch-setup">Get embed code</div>
            </div>
            <div class="dp-channel-card" data-channel="qr">
              <div class="dp-channel-check">✓</div>
              <div class="ch-icon">📷</div>
              <div class="ch-name">QR Code</div>
              <div class="ch-desc">Generate a scannable QR code for printed materials and physical spaces.</div>
              <div class="ch-best">Best for: in-person and event research</div>
              <div class="ch-setup">Download QR</div>
            </div>
            <div class="dp-channel-card" data-channel="lms">
              <div class="dp-channel-check">✓</div>
              <div class="ch-icon">🎓</div>
              <div class="ch-name">LMS / Intranet</div>
              <div class="ch-desc">Post the survey inside a learning platform, SharePoint, or company intranet.</div>
              <div class="ch-best">Best for: employee and student surveys</div>
              <div class="ch-setup">Get link</div>
            </div>
            <div class="dp-channel-card" data-channel="api">
              <div class="dp-channel-check">✓</div>
              <div class="ch-icon">⚙️</div>
              <div class="ch-name">API / Webhook</div>
              <div class="ch-desc">Trigger survey invitations programmatically via the ReliCheck REST API.</div>
              <div class="ch-best">Best for: product or CRM integrations</div>
              <div class="ch-setup">View API docs</div>
            </div>
          </div>
        </div>
      </div>

      <!-- ── 5. SCHEDULE ───────────────────────────────────────── -->
      <div class="dp-step-pane" data-pane="schedule" hidden>
        <div class="dp-section" id="sectionSchedule">
          <div class="dp-section-head">
            <div class="dp-section-head-text">
              <h2>Schedule</h2>
              <p class="dp-section-desc">Set when this survey opens, closes, and what happens when it reaches its target.</p>
            </div>
          </div>

          <div class="dp-toggle-row" style="margin-top:0;">
            <div class="dp-toggle on" id="toggleLaunchNow" role="switch" aria-checked="true" aria-label="Launch immediately"></div>
            <span class="dp-toggle-label">Launch immediately when I click Deploy</span>
          </div>

          <div class="dp-gap-sm"></div>

          <div class="dp-schedule-grid" id="launchDateField" style="visibility:hidden;">
            <div class="dp-field">
              <label class="dp-label" for="launchDate">Launch date &amp; time</label>
              <input class="dp-input" type="datetime-local" id="launchDate" name="launchDate">
            </div>
            <div class="dp-field">
              <label class="dp-label" for="timezone">Timezone</label>
              <select class="dp-select" id="timezone" name="timezone">
                <option value="">Detect automatically</option>
                <option value="America/New_York">America/New_York (ET)</option>
                <option value="America/Chicago">America/Chicago (CT)</option>
                <option value="America/Denver">America/Denver (MT)</option>
                <option value="America/Los_Angeles">America/Los_Angeles (PT)</option>
                <option value="America/Anchorage">America/Anchorage (AKT)</option>
                <option value="Pacific/Honolulu">Pacific/Honolulu (HST)</option>
                <option value="Europe/London">Europe/London (GMT/BST)</option>
                <option value="Europe/Paris">Europe/Paris (CET)</option>
                <option value="Europe/Berlin">Europe/Berlin (CET)</option>
                <option value="Asia/Tokyo">Asia/Tokyo (JST)</option>
                <option value="Asia/Shanghai">Asia/Shanghai (CST)</option>
                <option value="Asia/Kolkata">Asia/Kolkata (IST)</option>
                <option value="Australia/Sydney">Australia/Sydney (AEST)</option>
                <option value="UTC">UTC</option>
              </select>
            </div>
          </div>

          <hr class="dp-divider">

          <div class="dp-schedule-grid">
            <div class="dp-field">
              <label class="dp-label" for="closeDate">Close date <span class="dp-chip">optional</span></label>
              <input class="dp-input" type="datetime-local" id="closeDate" name="closeDate">
            </div>
            <div class="dp-field">
              <label class="dp-label" for="targetResponses">Target responses <span class="dp-chip">optional</span></label>
              <input class="dp-input" type="number" id="targetResponses" name="targetResponses" placeholder="e.g. 200" min="1">
            </div>
          </div>

          <div class="dp-toggle-row">
            <div class="dp-toggle" id="toggleGrace" role="switch" aria-checked="false" aria-label="Grace period"></div>
            <span class="dp-toggle-label">Grace period — allow in-progress responses to complete after close date</span>
          </div>

          <div class="dp-toggle-row">
            <div class="dp-toggle" id="toggleReopen" role="switch" aria-checked="false" aria-label="Reopen survey"></div>
            <span class="dp-toggle-label">Allow survey to be reopened after closing</span>
          </div>
        </div>
      </div>

      <!-- ── 6. REMINDERS ──────────────────────────────────────── -->
      <div class="dp-step-pane" data-pane="reminders" hidden>
        <div class="dp-section" id="sectionReminders">
          <div class="dp-section-head">
            <div class="dp-section-head-text">
              <h2>Reminder Strategy</h2>
              <p class="dp-section-desc">Choose how nonrespondents are followed up with. Well-timed reminders can lift response rates by 20–40%.</p>
            </div>
          </div>

          <div class="dp-reminder-stack">
            <div class="dp-reminder-item" data-reminder="none">
              <div class="rem-dot"></div>
              <div class="rem-text">
                <span class="rem-label">No reminders</span>
                <span class="rem-sub">Send once. No follow-up messages will be sent.</span>
              </div>
            </div>
            <div class="dp-reminder-item" data-reminder="manual">
              <div class="rem-dot"></div>
              <div class="rem-text">
                <span class="rem-label">Manual reminders</span>
                <span class="rem-sub">I'll send reminders myself from my email client when I choose.</span>
              </div>
            </div>
            <div class="dp-reminder-item" data-reminder="scheduled">
              <div class="rem-dot"></div>
              <div class="rem-text">
                <span class="rem-label">Scheduled reminders</span>
                <span class="rem-sub">Send automated reminders at set intervals (e.g. day 3, day 7, day 14).</span>
              </div>
            </div>
            <div class="dp-reminder-item" data-reminder="nonrespondent">
              <div class="rem-dot"></div>
              <div class="rem-text">
                <span class="rem-label">Nonrespondents only</span>
                <span class="rem-sub">Automatically send a single follow-up only to people who haven't responded yet.</span>
              </div>
            </div>
            <div class="dp-reminder-item" data-reminder="partial">
              <div class="rem-dot"></div>
              <div class="rem-text">
                <span class="rem-label">Partial completion nudge</span>
                <span class="rem-sub">Re-engage respondents who started but didn't finish. Resumes from where they left off.</span>
              </div>
            </div>
            <div class="dp-reminder-item" data-reminder="final">
              <div class="rem-dot"></div>
              <div class="rem-text">
                <span class="rem-label">Final call before close</span>
                <span class="rem-sub">Send a last-chance reminder 24–48 hours before the survey closes.</span>
              </div>
            </div>
          </div>

          <div class="dp-reminder-note">
            <span class="dp-reminder-note-icon">💡</span>
            <span>Reminders require the <strong>Unique Links</strong> or <strong>Roster-Only</strong> access type, or <strong>Completion-Tracked</strong> identity mode so the system can identify who still needs to respond.</span>
          </div>
        </div>
      </div>

      <!-- ── 7. BRANDING & INTRO ────────────────────────────────── -->
      <div class="dp-step-pane" data-pane="branding" hidden>
        <div class="dp-section" id="sectionBranding">
          <div class="dp-section-head">
            <div class="dp-section-head-text">
              <h2>Branding &amp; Intro</h2>
              <p class="dp-section-desc">Customise what respondents see before and after the survey. A clear intro and confidentiality statement significantly improve completion rates.</p>
            </div>
          </div>

          <div class="dp-branding-grid">
            <div class="dp-field">
              <label class="dp-label" for="brandTitle">Survey display title</label>
              <input class="dp-input" type="text" id="brandTitle" name="brandTitle" placeholder="What respondents see as the survey name">
            </div>
            <div class="dp-field">
              <label class="dp-label" for="brandOrg">Organisation / Researcher name</label>
              <input class="dp-input" type="text" id="brandOrg" name="brandOrg" placeholder="e.g. Faculty of Education, Acme Corp">
            </div>
            <div class="dp-field full-width">
              <label class="dp-label" for="brandIntro">Introduction &amp; consent statement <span class="dp-chip" style="background:#fee2e2;color:#991b1b;">required for score</span></label>
              <textarea class="dp-textarea" id="brandIntro" name="brandIntro" rows="4" placeholder="Describe the purpose of this survey, how data will be used, whether participation is voluntary, and your confidentiality or anonymity promise. Include keywords like 'confidential', 'anonymous', or 'private' to satisfy the readiness check."></textarea>
            </div>
            <div class="dp-field">
              <label class="dp-label" for="brandTime">Estimated completion time</label>
              <input class="dp-input" type="text" id="brandTime" name="brandTime" placeholder="e.g. 5–7 minutes">
            </div>
            <div class="dp-field">
              <label class="dp-label" for="brandContact">Contact / support email</label>
              <input class="dp-input" type="email" id="brandContact" name="brandContact" placeholder="researcher@example.com">
            </div>
            <div class="dp-field full-width">
              <label class="dp-label" for="brandThanks">Thank-you / completion message</label>
              <textarea class="dp-textarea" id="brandThanks" name="brandThanks" rows="3" placeholder="Message shown after the respondent submits. Thank them and explain what happens next."></textarea>
            </div>
          </div>

          <hr class="dp-divider">

          <div class="dp-logo-zone">
            <span class="logo-icon">🖼️</span>
            Click to upload a logo or banner image<br>
            <span style="font-size:11.5px;color:#b0b3b8;">PNG, JPG, SVG · max 2 MB · displayed at the top of the survey</span>
          </div>

          <div class="dp-toggle-row">
            <div class="dp-toggle on" id="toggleBranding" role="switch" aria-checked="true" aria-label="Show ReliCheck branding"></div>
            <span class="dp-toggle-label">Show "Powered by ReliCheck" footer on the survey page</span>
          </div>
        </div>
      </div>

    </div><!-- /.dp-modal-body -->

    <!-- Modal foot -->
    <footer class="dp-modal-foot">
      <button class="dp-action-btn ghost" id="stepPrev" type="button">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="15 18 9 12 15 6"/></svg>
        Previous
      </button>
      <div style="flex:1;text-align:center;font-size:12.5px;color:#5f6368;font-weight:600;" id="stepCounter">Step 1 of 7</div>
      <button class="dp-action-btn primary" id="stepNext" type="button">
        Next
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="9 18 15 12 9 6"/></svg>
      </button>
      <button class="dp-action-btn secondary" id="stepDone" type="button" hidden>
        Done
      </button>
    </footer>

  </div><!-- /.dp-modal -->
</div><!-- /#configModal -->
