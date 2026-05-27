(function () {
  'use strict';

  /* ─────────────────────────────────────────────
   *  STATE
   * ───────────────────────────────────────────── */
  const state = {
    audience:  { type: null, segments: [] },
    access:    { type: null, onePerPerson: false },
    identity:  null,   // 'anonymous' | 'confidential' | 'identified' | 'completion'
    channels:  [],     // array of selected channel keys
    schedule: {
      launchNow: true,
      launchDate: '',
      closeDate: '',
      timezone: Intl.DateTimeFormat().resolvedOptions().timeZone,
      targetResponses: '',
      gracePeriod: false,
      reopenable: false,
    },
    reminder:  null,   // 'none'|'manual'|'scheduled'|'nonrespondent'|'partial'|'final'
    branding: {
      title: '',
      orgName: '',
      intro: '',
      estimatedTime: '',
      contact: '',
      thankYou: '',
      showBranding: true,
    },
    preview: { mobileSeen: false },
  };

  /* ─────────────────────────────────────────────
   *  READINESS CHECKS
   * ───────────────────────────────────────────── */
  const CHECKS = [
    { key: 'audience',   weight: 15, label: 'Target audience defined',             fix: 'Select an audience type in the Audience section' },
    { key: 'identity',   weight: 15, label: 'Identity mode selected',              fix: 'Choose how respondent identity is handled' },
    { key: 'access',     weight: 10, label: 'Access type configured',              fix: 'Select how respondents will access the survey' },
    { key: 'channel',    weight: 10, label: 'Deployment channel selected',         fix: 'Choose at least one channel to distribute your survey' },
    { key: 'schedule',   weight: 10, label: 'Launch date configured',              fix: 'Set a launch date or choose Launch Now' },
    { key: 'reminder',    weight: 5, label: 'Reminder plan selected',              fix: 'Choose a reminder strategy for nonrespondents' },
    { key: 'preview',     weight: 5, label: 'Mobile preview reviewed',             fix: 'Click Preview Mobile View to check the respondent experience' },
    { key: 'intro',      weight: 10, label: 'Introduction / consent text added',   fix: 'Add an intro statement in Branding & Intro' },
    { key: 'statement',  weight: 10, label: 'Confidentiality statement present',   fix: 'Add a privacy/confidentiality statement to the intro text' },
    { key: 'length',      weight: 5, label: 'Survey length is reasonable',         fix: 'Surveys over 25 questions may reduce completion rates' },
    { key: 'openended',   weight: 5, label: 'Open-ended question balance is good', fix: 'More than 40% open-ended questions can fatigue respondents' },
  ];

  /* ─────────────────────────────────────────────
   *  CHECK EVALUATION
   * ───────────────────────────────────────────── */
  function evalChecks(s) {
    const intro = s.branding.intro.toLowerCase();
    return {
      audience:  s.audience.type !== null,
      identity:  s.identity !== null,
      access:    s.access.type !== null,
      channel:   s.channels.length > 0,
      schedule:  s.schedule.launchNow || s.schedule.launchDate !== '',
      reminder:  s.reminder !== null,
      preview:   s.preview.mobileSeen,
      intro:     s.branding.intro.trim().length > 20,
      statement: intro.includes('confidential') || intro.includes('anonymous') || intro.includes('private'),
      length:    true,
      openended: true,
    };
  }

  /* ─────────────────────────────────────────────
   *  SCORE & STATUS
   * ───────────────────────────────────────────── */
  function calcScore(results) {
    let total = 0;
    for (const chk of CHECKS) {
      if (results[chk.key]) total += chk.weight;
    }
    return Math.min(100, Math.max(0, total));
  }

  function statusFromScore(score) {
    if (score >= 80) return { key: 'ready',     label: 'Ready to Launch' };
    if (score >= 50) return { key: 'review',    label: 'Needs Review' };
    return              { key: 'not-ready',  label: 'Not Ready' };
  }

  /* ─────────────────────────────────────────────
   *  SUMMARY LABEL HELPERS
   * ───────────────────────────────────────────── */
  function audienceLabel(s) {
    const m = { open: 'Open public', private: 'Private invited', roster: 'Uploaded roster', domain: 'Domain/org', panel: 'Panel/paid' };
    return s.audience.type ? (m[s.audience.type] || s.audience.type) : '—';
  }
  function accessLabel(s) {
    const m = { open: 'Open link', private: 'Unique links', password: 'Password-protected', domain: 'Domain-restricted', roster: 'Roster-only' };
    return s.access.type ? (m[s.access.type] || s.access.type) : '—';
  }
  function identityLabel(s) {
    const m = { anonymous: 'Anonymous', confidential: 'Confidential', identified: 'Identified', completion: 'Completion-tracked' };
    return s.identity ? m[s.identity] : '—';
  }
  function channelLabel(s) { return s.channels.length ? s.channels.join(', ') : '—'; }
  function scheduleLabel(s) { return s.schedule.launchNow ? 'Launch now' : (s.schedule.launchDate || '—'); }
  function reminderLabel(s) {
    const m = { none: 'No reminders', manual: 'Manual', scheduled: 'Scheduled', nonrespondent: 'Nonrespondents only', partial: 'Partial completion', final: 'Final before close' };
    return s.reminder ? m[s.reminder] : '—';
  }

  /* ─────────────────────────────────────────────
   *  TOAST
   * ───────────────────────────────────────────── */
  function showToast(msg, type) {
    type = type || 'ok';
    const t = document.createElement('div');
    t.className = 'dp-toast dp-toast-' + type;
    t.textContent = msg;
    document.body.appendChild(t);
    requestAnimationFrame(function () { t.classList.add('dp-toast-visible'); });
    setTimeout(function () {
      t.classList.remove('dp-toast-visible');
      setTimeout(function () { t.remove(); }, 400);
    }, 2500);
  }

  /* ─────────────────────────────────────────────
   *  TOAST CSS
   * ───────────────────────────────────────────── */
  function injectToastStyles() {
    const style = document.createElement('style');
    style.textContent = [
      '.dp-toast {',
      '  position: fixed;',
      '  bottom: 28px;',
      '  left: 50%;',
      '  transform: translateX(-50%);',
      '  background: #15171a;',
      '  color: #fff;',
      '  padding: 10px 20px;',
      '  border-radius: 999px;',
      '  font-size: 13px;',
      '  font-weight: 600;',
      '  z-index: 9000;',
      '  opacity: 0;',
      '  transition: opacity 0.2s;',
      '  pointer-events: none;',
      '  white-space: nowrap;',
      '}',
      '.dp-toast-visible { opacity: 1; }',
      '.dp-toast-error { background: #c2492f; }',
    ].join('\n');
    document.head.appendChild(style);
  }

  /* ─────────────────────────────────────────────
   *  SVG HELPERS FOR CHECKLIST
   * ───────────────────────────────────────────── */
  const SVG_PASS = '<svg width="16" height="16" viewBox="0 0 16 16" fill="none" aria-hidden="true"><circle cx="8" cy="8" r="8" fill="#0e8a6f"/><path d="M4.5 8l2.5 2.5 4.5-4.5" stroke="#fff" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg>';
  const SVG_FAIL = '<svg width="16" height="16" viewBox="0 0 16 16" fill="none" aria-hidden="true"><circle cx="8" cy="8" r="8" fill="#e5e7eb"/><path d="M5.5 5.5l5 5M10.5 5.5l-5 5" stroke="#9ca3af" stroke-width="1.5" stroke-linecap="round"/></svg>';

  /* ─────────────────────────────────────────────
   *  SCORE RING
   * ───────────────────────────────────────────── */
  const CIRC = 2 * Math.PI * 54; // ≈ 339.3

  function updateRing(score, statusKey) {
    const ring = document.getElementById('scoreRingSvg');
    if (!ring) return;
    const circle = ring.querySelector('circle[data-ring]') || ring.querySelector('circle:last-of-type');
    if (!circle) return;
    const offset = CIRC * (1 - score / 100);
    circle.style.strokeDasharray  = CIRC.toFixed(2);
    circle.style.strokeDashoffset = offset.toFixed(2);
    const colorMap = { ready: '#0e8a6f', review: '#d97706', 'not-ready': '#c2492f' };
    circle.style.stroke = colorMap[statusKey] || '#c2492f';
  }

  /* ─────────────────────────────────────────────
   *  REFRESH — called after every state change
   * ───────────────────────────────────────────── */
  function refresh() {
    const results = evalChecks(state);
    const score   = calcScore(results);
    const status  = statusFromScore(score);

    /* Score number */
    const scoreEl = document.getElementById('readinessScore');
    if (scoreEl) {
      scoreEl.textContent = score;
      const colorMap = { ready: '#0e8a6f', review: '#d97706', 'not-ready': '#c2492f' };
      scoreEl.style.setProperty('--score-color', colorMap[status.key] || '#c2492f');
    }

    /* Status badge */
    const statusEl = document.getElementById('readinessStatus');
    if (statusEl) {
      statusEl.classList.remove('ready', 'review', 'not-ready');
      statusEl.classList.add(status.key);
      const lbl = statusEl.querySelector('.status-label');
      if (lbl) lbl.textContent = status.label;
      else statusEl.textContent = status.label;
    }

    /* Checklist */
    const list = document.getElementById('readinessChecklist');
    if (list) {
      CHECKS.forEach(function (chk) {
        const item = list.querySelector('[data-check="' + chk.key + '"]');
        if (!item) return;
        const pass   = results[chk.key];
        const iconEl = item.querySelector('.dp-check-icon');
        if (iconEl) iconEl.innerHTML = pass ? SVG_PASS : SVG_FAIL;
        item.classList.toggle('dp-check-pass', pass);
        item.classList.toggle('dp-check-fail', !pass);
      });
    }

    /* Score ring */
    updateRing(score, status.key);

    /* Launch summary */
    const summaryList = document.getElementById('launchSummaryList');
    if (summaryList) {
      const rows = [
        { label: 'Audience',  value: audienceLabel(state),  jump: 'audience'  },
        { label: 'Access',    value: accessLabel(state),    jump: 'access'    },
        { label: 'Identity',  value: identityLabel(state),  jump: 'identity'  },
        { label: 'Channel',   value: channelLabel(state),   jump: 'channels'  },
        { label: 'Schedule',  value: scheduleLabel(state),  jump: 'schedule'  },
        { label: 'Reminders', value: reminderLabel(state),  jump: 'reminders' },
      ];
      summaryList.innerHTML = rows.map(function (r) {
        return '<li class="dp-summary-row" data-jump="' + escHtml(r.jump) + '"><span class="dp-summary-label">' +
          escHtml(r.label) + '</span><span class="dp-summary-value">' +
          escHtml(r.value) + '</span></li>';
      }).join('');
    }

    /* Launch button */
    const launchBtn = document.getElementById('btnLaunch');
    if (launchBtn) {
      launchBtn.disabled = score < 50;
    }
  }

  /* ─────────────────────────────────────────────
   *  SECTION MAP FOR RESOLVE-ISSUES BUTTON
   * ───────────────────────────────────────────── */
  const CHECK_SECTION_MAP = {
    audience:  'sectionAudience',
    identity:  'sectionIdentity',
    access:    'sectionAccess',
    channel:   'sectionChannels',
    schedule:  'sectionSchedule',
    reminder:  'sectionReminders',
    preview:   'sectionPreview',
    intro:     'sectionBranding',
    statement: 'sectionBranding',
    length:    'sectionBranding',
    openended: 'sectionBranding',
  };

  /* ─────────────────────────────────────────────
   *  TINY ESCAPE HELPER
   * ───────────────────────────────────────────── */
  function escHtml(str) {
    return String(str)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;');
  }

  /* ─────────────────────────────────────────────
   *  TOGGLE HELPER (shared pattern)
   * ───────────────────────────────────────────── */
  function wireToggle(id, onToggle) {
    const el = document.getElementById(id);
    if (!el) return;
    el.addEventListener('click', function () {
      el.classList.toggle('on');
      onToggle(el.classList.contains('on'));
      refresh();
    });
  }

  /* ─────────────────────────────────────────────
   *  DOM WIRING
   * ───────────────────────────────────────────── */
  function wireAll() {

    /* — Audience type pills — */
    document.querySelectorAll('.dp-type-pill').forEach(function (pill) {
      pill.addEventListener('click', function () {
        document.querySelectorAll('.dp-type-pill').forEach(function (p) { p.classList.remove('selected'); });
        pill.classList.add('selected');
        state.audience.type = pill.dataset.audience;
        refresh();
      });
    });

    /* — Segmentation tags — */
    document.querySelectorAll('.dp-seg-tag').forEach(function (tag) {
      tag.addEventListener('click', function () {
        tag.classList.toggle('active');
        const seg = tag.dataset.seg;
        if (tag.classList.contains('active')) {
          if (!state.audience.segments.includes(seg)) state.audience.segments.push(seg);
        } else {
          state.audience.segments = state.audience.segments.filter(function (x) { return x !== seg; });
        }
        refresh();
      });
    });

    /* — Access items — */
    document.querySelectorAll('.dp-access-item').forEach(function (item) {
      item.addEventListener('click', function () {
        document.querySelectorAll('.dp-access-item').forEach(function (i) { i.classList.remove('selected'); });
        item.classList.add('selected');
        state.access.type = item.dataset.access;
        refresh();
      });
    });

    /* — One-per-person toggle — */
    wireToggle('toggleOnePerPerson', function (on) { state.access.onePerPerson = on; });

    /* — Identity cards — */
    document.querySelectorAll('.dp-identity-card').forEach(function (card) {
      card.addEventListener('click', function () {
        document.querySelectorAll('.dp-identity-card').forEach(function (c) { c.classList.remove('selected'); });
        card.classList.add('selected');
        state.identity = card.dataset.identity;
        refresh();
      });
    });

    /* — Channel cards — */
    document.querySelectorAll('.dp-channel-card').forEach(function (card) {
      card.addEventListener('click', function () {
        card.classList.toggle('selected');
        const ch = card.dataset.channel;
        if (card.classList.contains('selected')) {
          if (!state.channels.includes(ch)) state.channels.push(ch);
        } else {
          state.channels = state.channels.filter(function (x) { return x !== ch; });
        }
        refresh();
      });
    });

    /* — Launch now toggle — */
    const toggleLaunchNow = document.getElementById('toggleLaunchNow');
    if (toggleLaunchNow) {
      /* Set initial visual state to match state.schedule.launchNow = true */
      if (state.schedule.launchNow) toggleLaunchNow.classList.add('on');

      toggleLaunchNow.addEventListener('click', function () {
        toggleLaunchNow.classList.toggle('on');
        state.schedule.launchNow = toggleLaunchNow.classList.contains('on');
        const launchDateField = document.getElementById('launchDateField');
        if (launchDateField) {
          launchDateField.style.visibility = state.schedule.launchNow ? 'hidden' : 'visible';
        }
        refresh();
      });
    }

    /* — Grace period toggle — */
    wireToggle('toggleGrace', function (on) { state.schedule.gracePeriod = on; });

    /* — Reopen toggle — */
    wireToggle('toggleReopen', function (on) { state.schedule.reopenable = on; });

    /* — Schedule inputs — */
    function wireInput(id, updater) {
      const el = document.getElementById(id);
      if (!el) return;
      el.addEventListener('input',  function () { updater(el.value); refresh(); });
      el.addEventListener('change', function () { updater(el.value); refresh(); });
    }
    wireInput('launchDate',      function (v) { state.schedule.launchDate = v; });
    wireInput('closeDate',       function (v) { state.schedule.closeDate = v; });
    wireInput('timezone',        function (v) { state.schedule.timezone = v; });
    wireInput('targetResponses', function (v) { state.schedule.targetResponses = v; });

    /* — Reminder items — */
    document.querySelectorAll('.dp-reminder-item').forEach(function (item) {
      item.addEventListener('click', function () {
        document.querySelectorAll('.dp-reminder-item').forEach(function (i) { i.classList.remove('selected'); });
        item.classList.add('selected');
        state.reminder = item.dataset.reminder;
        refresh();
      });
    });

    /* — Branding inputs — */
    const brandingMap = [
      ['brandTitle',   function (v) { state.branding.title = v; }],
      ['brandOrg',     function (v) { state.branding.orgName = v; }],
      ['brandIntro',   function (v) { state.branding.intro = v; }],
      ['brandTime',    function (v) { state.branding.estimatedTime = v; }],
      ['brandContact', function (v) { state.branding.contact = v; }],
      ['brandThanks',  function (v) { state.branding.thankYou = v; }],
    ];
    brandingMap.forEach(function (pair) {
      const el = document.getElementById(pair[0]);
      if (!el) return;
      el.addEventListener('input', function () { pair[1](el.value); refresh(); });
    });

    /* — Show branding toggle — */
    const toggleBranding = document.getElementById('toggleBranding');
    if (toggleBranding) {
      if (state.branding.showBranding) toggleBranding.classList.add('on');
      toggleBranding.addEventListener('click', function () {
        toggleBranding.classList.toggle('on');
        state.branding.showBranding = toggleBranding.classList.contains('on');
        refresh();
      });
    }

    /* — Preview button — */
    const btnPreviewMobile = document.getElementById('btnPreviewMobile');
    if (btnPreviewMobile) {
      btnPreviewMobile.addEventListener('click', function () {
        state.preview.mobileSeen = true;
        const frame = document.getElementById('mobilePreviewFrame');
        if (frame) frame.classList.toggle('preview-active');
        refresh();
      });
    }

    /* — Resolve issues button — opens modal at first failing step — */
    const btnResolveIssues = document.getElementById('btnResolveIssues');
    if (btnResolveIssues) {
      btnResolveIssues.addEventListener('click', function () {
        const results = evalChecks(state);
        for (let i = 0; i < CHECKS.length; i++) {
          const chk = CHECKS[i];
          if (!results[chk.key]) {
            const step = CHECK_TO_STEP[chk.key];
            if (step) openModal(step);
            else openModal('audience');
            return;
          }
        }
        // No failing checks — open at step 1
        openModal('audience');
      });
    }

    /* — Configuration modal: open / close / step nav — */
    wireModal();

    /* — Copy link button — */
    const btnCopyLink = document.getElementById('btnCopyLink');
    if (btnCopyLink) {
      btnCopyLink.addEventListener('click', function () {
        const slug = window.RELICHECK_SURVEY_SLUG;
        if (!slug) {
          showToast('No survey link available', 'error');
          return;
        }
        const url = location.origin + '/s/' + slug;
        if (navigator.clipboard && navigator.clipboard.writeText) {
          navigator.clipboard.writeText(url).then(function () {
            showToast('Link copied!');
          }).catch(function () {
            fallbackCopy(url);
          });
        } else {
          fallbackCopy(url);
        }
      });
    }

    /* — Launch button — */
    const btnLaunch = document.getElementById('btnLaunch');
    if (btnLaunch) {
      btnLaunch.addEventListener('click', function () {
        const results = evalChecks(state);
        const score   = calcScore(results);
        if (score < 50) {
          alert('Please resolve critical issues before launching.');
          return;
        }
        const projectId = window.RELICHECK_PROJECT_ID;
        fetch('/api/surveys/update.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ id: projectId, is_published: true }),
        }).then(function (res) {
          if (!res.ok) throw new Error('Server returned ' + res.status);
          return res.json();
        }).then(function () {
          showToast('Survey launched!');
          btnLaunch.textContent = 'Survey is Live ✓';
          btnLaunch.disabled = true;
        }).catch(function (err) {
          showToast('Launch failed: ' + err.message, 'error');
        });
      });
    }
  }

  /* ─────────────────────────────────────────────
   *  FALLBACK CLIPBOARD COPY
   * ───────────────────────────────────────────── */
  function fallbackCopy(text) {
    const ta = document.createElement('textarea');
    ta.value = text;
    ta.style.cssText = 'position:fixed;left:-9999px;top:-9999px;opacity:0';
    document.body.appendChild(ta);
    ta.focus();
    ta.select();
    try {
      document.execCommand('copy');
      showToast('Link copied!');
    } catch (e) {
      showToast('Could not copy link', 'error');
    }
    ta.remove();
  }

  /* ─────────────────────────────────────────────
   *  MODAL + STEP WIZARD
   * ───────────────────────────────────────────── */
  const STEPS = [
    { key: 'audience',  label: 'Target Audience' },
    { key: 'access',    label: 'Access Control' },
    { key: 'identity',  label: 'Identity Mode' },
    { key: 'channels',  label: 'Deployment Channel' },
    { key: 'schedule',  label: 'Schedule' },
    { key: 'reminders', label: 'Reminder Strategy' },
    { key: 'branding',  label: 'Branding & Intro' },
  ];

  const CHECK_TO_STEP = {
    audience:  'audience',
    identity:  'identity',
    access:    'access',
    channel:   'channels',
    schedule:  'schedule',
    reminder:  'reminders',
    intro:     'branding',
    statement: 'branding',
    length:    'branding',
    openended: 'branding',
    /* preview is on the main page, not in the modal */
  };

  let currentStep = 'audience';

  function showStep(key) {
    currentStep = key;
    const idx = STEPS.findIndex(function (s) { return s.key === key; });
    if (idx < 0) return;

    /* Toggle panes */
    document.querySelectorAll('.dp-step-pane').forEach(function (pane) {
      pane.hidden = pane.dataset.pane !== key;
      pane.classList.toggle('active', pane.dataset.pane === key);
    });

    /* Toggle tabs */
    document.querySelectorAll('.dp-step-tab').forEach(function (tab) {
      tab.classList.toggle('active', tab.dataset.step === key);
    });

    /* Update step counter + sub */
    const counter = document.getElementById('stepCounter');
    if (counter) counter.textContent = 'Step ' + (idx + 1) + ' of ' + STEPS.length;
    const sub = document.getElementById('configModalStep');
    if (sub) sub.textContent = 'Step ' + (idx + 1) + ' of ' + STEPS.length + ' · ' + STEPS[idx].label;

    /* Prev / Next / Done button states */
    const prev = document.getElementById('stepPrev');
    const next = document.getElementById('stepNext');
    const done = document.getElementById('stepDone');
    if (prev) prev.disabled = (idx === 0);
    if (idx === STEPS.length - 1) {
      if (next) next.hidden = true;
      if (done) done.hidden = false;
    } else {
      if (next) next.hidden = false;
      if (done) done.hidden = true;
    }

    /* Scroll modal body to top so each step starts clean */
    const body = document.querySelector('.dp-modal-body');
    if (body) body.scrollTop = 0;
  }

  function openModal(stepKey) {
    const modal = document.getElementById('configModal');
    if (!modal) return;
    modal.hidden = false;
    document.body.style.overflow = 'hidden';
    showStep(stepKey || currentStep || 'audience');
  }

  function closeModal() {
    const modal = document.getElementById('configModal');
    if (!modal) return;
    modal.hidden = true;
    document.body.style.overflow = '';
  }

  function wireModal() {
    /* Open triggers */
    const btnConfigure  = document.getElementById('btnConfigure');
    const btnEditConfig = document.getElementById('btnEditConfig');
    if (btnConfigure)  btnConfigure.addEventListener('click', function () { openModal(); });
    if (btnEditConfig) btnEditConfig.addEventListener('click', function () { openModal(); });

    /* Summary rows jump straight to the matching step.
       Delegated, because refresh() rebuilds these rows via innerHTML. */
    const summaryList = document.getElementById('launchSummaryList');
    if (summaryList) {
      summaryList.addEventListener('click', function (e) {
        const row = e.target.closest('[data-jump]');
        if (row && summaryList.contains(row)) openModal(row.dataset.jump);
      });
    }

    /* Close triggers */
    const btnClose = document.getElementById('btnCloseModal');
    if (btnClose) btnClose.addEventListener('click', closeModal);

    const modal = document.getElementById('configModal');
    if (modal) {
      modal.addEventListener('click', function (e) {
        /* Click outside .dp-modal closes */
        if (e.target === modal) closeModal();
      });
    }

    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape' && modal && !modal.hidden) closeModal();
    });

    /* Step tabs */
    document.querySelectorAll('.dp-step-tab').forEach(function (tab) {
      tab.addEventListener('click', function () { showStep(tab.dataset.step); });
    });

    /* Prev / Next / Done */
    const stepPrev = document.getElementById('stepPrev');
    const stepNext = document.getElementById('stepNext');
    const stepDone = document.getElementById('stepDone');

    if (stepPrev) stepPrev.addEventListener('click', function () {
      const idx = STEPS.findIndex(function (s) { return s.key === currentStep; });
      if (idx > 0) showStep(STEPS[idx - 1].key);
    });
    if (stepNext) stepNext.addEventListener('click', function () {
      const idx = STEPS.findIndex(function (s) { return s.key === currentStep; });
      if (idx < STEPS.length - 1) showStep(STEPS[idx + 1].key);
    });
    if (stepDone) stepDone.addEventListener('click', closeModal);
  }

  /* ─────────────────────────────────────────────
   *  HYDRATE STATE from server-injected config
   * ───────────────────────────────────────────── */
  function hydrateFromConfig() {
    const cfg = window.RELICHECK_DEPLOY_CONFIG;
    if (!cfg || typeof cfg !== 'object') return;

    // Pull values into state
    if (cfg.audience && typeof cfg.audience === 'object') {
      state.audience.type     = cfg.audience.type     || null;
      state.audience.segments = Array.isArray(cfg.audience.segments) ? cfg.audience.segments.slice() : [];
    }
    if (cfg.access && typeof cfg.access === 'object') {
      state.access.type         = cfg.access.type     || null;
      state.access.onePerPerson = !!cfg.access.onePerPerson;
    }
    if (typeof cfg.identity !== 'undefined') state.identity = cfg.identity || null;
    if (Array.isArray(cfg.channels)) state.channels = cfg.channels.slice();
    if (cfg.schedule && typeof cfg.schedule === 'object') {
      state.schedule.launchNow       = !!cfg.schedule.launchNow;
      state.schedule.launchDate      = cfg.schedule.launchDate || '';
      state.schedule.closeDate       = cfg.schedule.closeDate  || '';
      if (cfg.schedule.timezone)        state.schedule.timezone = cfg.schedule.timezone;
      state.schedule.targetResponses = (cfg.schedule.targetResponses != null) ? String(cfg.schedule.targetResponses) : '';
      state.schedule.gracePeriod     = !!cfg.schedule.gracePeriod;
      state.schedule.reopenable      = !!cfg.schedule.reopenable;
    }
    if (typeof cfg.reminder !== 'undefined') state.reminder = cfg.reminder || null;
    if (cfg.branding && typeof cfg.branding === 'object') {
      state.branding.title         = cfg.branding.title         || '';
      state.branding.orgName       = cfg.branding.orgName       || '';
      state.branding.intro         = cfg.branding.intro         || '';
      state.branding.estimatedTime = cfg.branding.estimatedTime || '';
      state.branding.contact       = cfg.branding.contact       || '';
      state.branding.thankYou      = cfg.branding.thankYou      || '';
      state.branding.showBranding  = (cfg.branding.showBranding !== false);
    }
  }

  /* ─────────────────────────────────────────────
   *  REFLECT STATE in DOM (after hydrate)
   * ───────────────────────────────────────────── */
  function reflectStateInDom() {
    // Audience type pills
    document.querySelectorAll('.dp-type-pill').forEach(function (p) {
      p.classList.toggle('selected', p.dataset.audience === state.audience.type);
    });
    // Segmentation tags
    document.querySelectorAll('.dp-seg-tag').forEach(function (t) {
      t.classList.toggle('active', state.audience.segments.indexOf(t.dataset.seg) !== -1);
    });
    // Access items
    document.querySelectorAll('.dp-access-item').forEach(function (i) {
      i.classList.toggle('selected', i.dataset.access === state.access.type);
    });
    // One-per-person toggle
    const tOpp = document.getElementById('toggleOnePerPerson');
    if (tOpp) tOpp.classList.toggle('on', state.access.onePerPerson);
    // Identity cards
    document.querySelectorAll('.dp-identity-card').forEach(function (c) {
      c.classList.toggle('selected', c.dataset.identity === state.identity);
    });
    // Channel cards
    document.querySelectorAll('.dp-channel-card').forEach(function (c) {
      c.classList.toggle('selected', state.channels.indexOf(c.dataset.channel) !== -1);
    });
    // Launch-now toggle + date field visibility
    const tLN = document.getElementById('toggleLaunchNow');
    if (tLN) tLN.classList.toggle('on', state.schedule.launchNow);
    const ldField = document.getElementById('launchDateField');
    if (ldField) ldField.style.visibility = state.schedule.launchNow ? 'hidden' : 'visible';
    // Grace / Reopen
    const tG = document.getElementById('toggleGrace');
    if (tG) tG.classList.toggle('on', state.schedule.gracePeriod);
    const tR = document.getElementById('toggleReopen');
    if (tR) tR.classList.toggle('on', state.schedule.reopenable);
    // Schedule inputs
    const map = [
      ['launchDate',      state.schedule.launchDate],
      ['closeDate',       state.schedule.closeDate],
      ['timezone',        state.schedule.timezone],
      ['targetResponses', state.schedule.targetResponses],
    ];
    map.forEach(function (pair) {
      const el = document.getElementById(pair[0]);
      if (el && pair[1] != null) el.value = pair[1];
    });
    // Reminder items
    document.querySelectorAll('.dp-reminder-item').forEach(function (i) {
      i.classList.toggle('selected', i.dataset.reminder === state.reminder);
    });
    // Branding fields
    const bMap = [
      ['brandTitle',   state.branding.title],
      ['brandOrg',     state.branding.orgName],
      ['brandIntro',   state.branding.intro],
      ['brandTime',    state.branding.estimatedTime],
      ['brandContact', state.branding.contact],
      ['brandThanks',  state.branding.thankYou],
    ];
    bMap.forEach(function (pair) {
      const el = document.getElementById(pair[0]);
      if (el && pair[1] != null) el.value = pair[1];
    });
    const tB = document.getElementById('toggleBranding');
    if (tB) tB.classList.toggle('on', state.branding.showBranding);
  }

  /* ─────────────────────────────────────────────
   *  DEBOUNCED AUTOSAVE
   * ───────────────────────────────────────────── */
  let saveTimer = null;
  let lastSaveOk = true;

  function scheduleSave() {
    if (saveTimer) clearTimeout(saveTimer);
    saveTimer = setTimeout(saveConfig, 700);
  }

  function saveConfig() {
    const id = window.RELICHECK_PROJECT_ID;
    if (!id) return;
    const body = { id: id, config: state };
    fetch('/api/surveys/deploy-config.php', {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(body),
    }).then(function (res) {
      if (!res.ok) throw new Error('HTTP ' + res.status);
      return res.json();
    }).then(function () {
      if (!lastSaveOk) showToast('Saved');
      lastSaveOk = true;
    }).catch(function (err) {
      lastSaveOk = false;
      showToast('Save failed: ' + err.message, 'error');
    });
  }

  // Patch refresh() so every state change also schedules a save.
  const _origRefresh = refresh;
  refresh = function () {
    _origRefresh();
    scheduleSave();
  };

  /* ─────────────────────────────────────────────
   *  INIT
   * ───────────────────────────────────────────── */
  function init() {
    injectToastStyles();
    hydrateFromConfig();
    wireAll();
    reflectStateInDom();

    /* Launch date field visibility matches whatever state we hydrated */
    const launchDateField = document.getElementById('launchDateField');
    if (launchDateField) {
      launchDateField.style.visibility = state.schedule.launchNow ? 'hidden' : 'visible';
    }

    /* Populate timezone selector with the resolved local timezone if blank */
    const tzEl = document.getElementById('timezone');
    if (tzEl && !tzEl.value) {
      tzEl.value = state.schedule.timezone;
    }

    /* First render (uses patched refresh which also schedules a save —
       suppress that one save since nothing actually changed since load) */
    lastSaveOk = true;
    _origRefresh();
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }

})();
