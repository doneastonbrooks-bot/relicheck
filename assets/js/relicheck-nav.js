/* ReliCheck shared navigation (single source of truth).
 *
 * Each marketing page mounts the nav by including:
 *
 *   <div id="relicheck-nav"></div>
 *   <script src="/assets/js/relicheck-nav.js" defer></script>
 *
 * Edit this one file to change the nav anywhere. No PHP, no .htaccess
 * config, no per-page edits.
 *
 * SurveyMonkey-style: thin white top bar, simple top-level labels, large
 * mega panels. Platform uses a left product-card panel (light background)
 * + three feature columns. Mobile hamburger with accordion drawer.
 */
(function () {
  'use strict';

  // ----- Active page detection -------------------------------------------
  var path = (window.location.pathname || '/').toLowerCase();
  function isActive(patterns) {
    for (var i = 0; i < patterns.length; i++) {
      var p = patterns[i];
      if (p === path) return true;
      if (p.charAt(p.length - 1) === '/' && path.indexOf(p) === 0) return true;
    }
    return false;
  }
  var activeProduct   = isActive([
    '/overview.html', '/import-data.html', '/import-survey.html',
    '/samples.html', '/templates.html'
  ]) || path.indexOf('/samples/') === 0;
  var activeSolutions = isActive([
    '/hr-teams.html', '/marketing.html', '/research.html',
    '/program-evaluation.html', '/tests-overview.html',
    '/education.html', '/customer-feedback.html', '/mixed-methods.html',
    '/360-surveys.html', '/businesses.html', '/community-nonprofit.html'
  ]);
  var activeAnalysis  = isActive([
    '/survey-strength-index.html', '/pre-publish-check.html',
    '/reliability-guide.html', '/validity-guide.html',
    '/methodology.html'
  ]);
  var activeAI        = isActive(['/ai-features.html']) || path.indexOf('/ai/') === 0;
  var activeResources = isActive([
    '/help.html', '/blog.html', '/case-studies.html', '/compare.html',
    '/survey-design-guide.html', '/privacy.html'
  ]) || path.indexOf('/dashboards/') === 0
     || path.indexOf('/blog/') === 0
     || path.indexOf('/help/') === 0
     || path.indexOf('/case-studies/') === 0;
  var activePricing   = isActive(['/pricing.html']);

  // ----- Inject styles ---------------------------------------------------
  var css =
    '.rc-nav { position: sticky; top: 0; z-index: 100; background: #ffffff; border-bottom: 1px solid #ececf2; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif; color: #1a1f33; -webkit-font-smoothing: antialiased; }' +
    '.rc-nav-bar { display: flex; align-items: center; justify-content: space-between; max-width: 1280px; margin: 0 auto; padding: 12px 22px; gap: 32px; }' +
    '.rc-nav-brand { display: flex; align-items: center; }' +
    '.rc-nav-brand img { height: 48px; width: auto; display: block; }' +
    '.rc-nav-list { display: flex; align-items: center; gap: 4px; list-style: none; margin: 0; padding: 0; flex: 1; justify-content: center; }' +
    '.rc-nav-item { position: static; }' +
    '.rc-nav-link { display: inline-flex; align-items: center; gap: 4px; padding: 8px 14px; border: 0; background: transparent; color: #1a1f33; font-size: 15.5px; font-weight: 500; line-height: 1; cursor: pointer; text-decoration: none; border-radius: 6px; transition: color 0.15s; font-family: inherit; }' +
    '.rc-nav-link:hover { color: #e85d3a; }' +
    '.rc-nav-link.is-active { color: #e85d3a; }' +
    '.rc-nav-link .rc-caret { font-size: 9px; color: #8c92a6; transition: transform 0.15s; }' +
    '.rc-nav-item[data-open="true"] .rc-nav-link .rc-caret { transform: rotate(180deg); color: #1a1f33; }' +
    '.rc-nav-cta { display: flex; align-items: center; gap: 10px; }' +
    '.rc-btn { display: inline-flex; align-items: center; justify-content: center; padding: 8px 16px; border: 0; background: transparent; color: #1a1f33; font-size: 15.5px; font-weight: 700; text-decoration: none; border-radius: 6px; cursor: pointer; transition: background 0.15s, color 0.15s, opacity 0.15s; font-family: inherit; }' +
    '.rc-btn:hover { color: #e85d3a; }' +
    '.rc-btn-primary { background: #e85d3a; color: #ffffff; padding: 9px 18px; }' +
    '.rc-btn-primary:hover { background: #d44e2c; color: #ffffff; opacity: 1; }' +
    '.rc-nav-toggle { display: none; align-items: center; justify-content: center; width: 38px; height: 38px; border: 1px solid transparent; background: transparent; cursor: pointer; padding: 0; border-radius: 8px; }' +
    '.rc-nav-toggle:hover { background: #f6f7fb; }' +
    '.rc-nav-toggle svg { width: 20px; height: 20px; }' +
    /* Mega panel */
    '.rc-mega { position: absolute; left: 0; right: 0; top: 100%; background: #ffffff; border-top: 1px solid #ececf2; border-bottom: 1px solid #ececf2; box-shadow: 0 8px 24px rgba(11, 23, 51, 0.06); opacity: 0; visibility: hidden; transform: translateY(-4px); transition: opacity 0.18s, transform 0.18s, visibility 0s 0.18s; }' +
    '.rc-nav-item[data-open="true"] .rc-mega { opacity: 1; visibility: visible; transform: translateY(0); transition: opacity 0.18s, transform 0.18s, visibility 0s; }' +
    '.rc-mega-inner { max-width: 1280px; margin: 0 auto; padding: 28px 22px 32px; display: grid; gap: 28px; }' +
    '.rc-mega-3 { grid-template-columns: repeat(3, minmax(0, 1fr)); }' +
    '.rc-mega-2 { grid-template-columns: repeat(2, minmax(0, 1fr)); max-width: 880px; }' +
    '.rc-mega-col h5 { margin: 0 0 14px; font-size: 13px; font-weight: 700; letter-spacing: 0.16em; text-transform: uppercase; color: #8c92a6; }' +
    '.rc-mega-col a { display: block; padding: 8px 0; color: #1a1f33; font-size: 16.5px; font-weight: 600; text-decoration: none; line-height: 1.4; transition: color 0.15s; }' +
    '.rc-mega-col a:hover { color: #e85d3a; }' +
    '.rc-mega-col a.is-emphasis { color: #e85d3a; font-weight: 600; }' +
    /* Platform mega: left product panel + right columns */
    '.rc-mega-platform .rc-mega-inner { display: grid; grid-template-columns: 360px 1fr; gap: 0; padding: 0; max-width: 1280px; }' +
    '.rc-mega-pp-left { background: #f7f9fc; padding: 30px 26px 32px; border-right: 1px solid #ececf2; }' +
    '.rc-mega-pp-left h5 { margin: 0 0 16px; font-size: 13px; font-weight: 700; letter-spacing: 0.16em; text-transform: uppercase; color: #8c92a6; }' +
    '.rc-mega-pp-cards { display: grid; gap: 10px; }' +
    '.rc-mega-pp-card { display: flex; gap: 12px; padding: 12px 14px; background: #ffffff; border: 1px solid #e5e8ed; border-radius: 10px; text-decoration: none; transition: border-color 0.15s, box-shadow 0.15s, transform 0.15s; }' +
    '.rc-mega-pp-card:hover { border-color: #e85d3a; box-shadow: 0 4px 12px rgba(232, 93, 58, 0.08); transform: translateY(-1px); }' +
    '.rc-mega-pp-card .icon { width: 36px; height: 36px; flex: 0 0 36px; display: flex; align-items: center; justify-content: center; background: #1a1f33; color: #ffffff; border-radius: 8px; }' +
    '.rc-mega-pp-card:hover .icon { background: #e85d3a; }' +
    '.rc-mega-pp-card .icon svg { width: 18px; height: 18px; stroke: currentColor; stroke-width: 2; fill: none; stroke-linecap: round; stroke-linejoin: round; }' +
    '.rc-mega-pp-card .body { min-width: 0; }' +
    '.rc-mega-pp-card .body strong { display: block; color: #1a1f33; font-size: 20px; font-weight: 700; line-height: 1.3; margin-bottom: 6px; }' +
    '.rc-mega-pp-card .body span { display: block; color: #6b7088; font-size: 15.5px; line-height: 1.45; }' +
    '.rc-mega-pp-right { padding: 30px 28px 32px; display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 28px; }' +
    /* Mobile drawer */
    '.rc-drawer { position: fixed; top: 60px; left: 0; right: 0; bottom: 0; background: #ffffff; padding: 18px 22px 60px; overflow-y: auto; transform: translateX(100%); transition: transform 0.22s ease; z-index: 99; }' +
    '.rc-drawer.is-open { transform: translateX(0); }' +
    '.rc-drawer-section { border-bottom: 1px solid #ececf2; }' +
    '.rc-drawer-toggle { display: flex; align-items: center; justify-content: space-between; width: 100%; padding: 16px 0; border: 0; background: transparent; color: #1a1f33; font-family: inherit; font-size: 16px; font-weight: 600; cursor: pointer; text-align: left; }' +
    '.rc-drawer-toggle .rc-caret { font-size: 11px; color: #8c92a6; transition: transform 0.18s; }' +
    '.rc-drawer-section[data-open="true"] .rc-drawer-toggle .rc-caret { transform: rotate(180deg); }' +
    '.rc-drawer-panel { display: none; padding: 0 0 18px; }' +
    '.rc-drawer-section[data-open="true"] .rc-drawer-panel { display: block; }' +
    '.rc-drawer-col { margin-bottom: 18px; }' +
    '.rc-drawer-col:last-child { margin-bottom: 0; }' +
    '.rc-drawer-col h6 { margin: 0 0 8px; font-size: 11px; font-weight: 700; letter-spacing: 0.08em; text-transform: uppercase; color: #8c92a6; }' +
    '.rc-drawer-col a { display: block; padding: 8px 0; color: #1a1f33; font-size: 15px; font-weight: 500; text-decoration: none; line-height: 1.4; }' +
    '.rc-drawer-col a:hover { color: #e85d3a; }' +
    '.rc-drawer-pp { background: #f7f9fc; border-radius: 10px; padding: 12px; margin-bottom: 16px; display: grid; gap: 8px; }' +
    '.rc-drawer-pp a { display: flex; gap: 10px; padding: 10px; background: #ffffff; border: 1px solid #e5e8ed; border-radius: 8px; text-decoration: none; }' +
    '.rc-drawer-pp a strong { display: block; color: #1a1f33; font-size: 14px; font-weight: 600; }' +
    '.rc-drawer-pp a span { display: block; color: #6b7088; font-size: 12px; margin-top: 2px; line-height: 1.4; }' +
    '.rc-drawer-link { display: block; padding: 16px 0; color: #1a1f33; font-size: 16px; font-weight: 600; text-decoration: none; border-bottom: 1px solid #ececf2; }' +
    '.rc-drawer-link.is-active { color: #e85d3a; }' +
    '.rc-drawer-cta { display: flex; flex-direction: column; gap: 10px; padding-top: 22px; }' +
    '.rc-drawer-cta .rc-btn { width: 100%; justify-content: center; padding: 14px 18px; font-size: 15px; }' +
    '.rc-drawer-cta .rc-btn-outline { border: 1px solid #e3e6ee; }' +
    /* Responsive breakpoints */
    '@media (max-width: 1080px) {' +
      '.rc-mega-platform .rc-mega-inner { grid-template-columns: 320px 1fr; }' +
      '.rc-mega-pp-right { grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 20px; padding: 24px 20px 28px; }' +
      '.rc-mega-pp-left { padding: 24px 20px 28px; }' +
    '}' +
    '@media (max-width: 960px) {' +
      '.rc-nav-list, .rc-nav-cta .rc-btn { display: none; }' +
      '.rc-nav-toggle { display: inline-flex; }' +
      '.rc-nav-bar { gap: 12px; padding: 10px 18px; }' +
    '}' +
    '@media (min-width: 961px) {' +
      '.rc-drawer { display: none; }' +
    '}';

  var styleEl = document.createElement('style');
  styleEl.textContent = css;
  document.head.appendChild(styleEl);

  // ----- Icon library (inline SVGs, no external deps) --------------------
  var ICONS = {
    survey:    '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M9 5H7a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2h-2"/><path d="M9 5a2 2 0 0 1 2-2h2a2 2 0 0 1 2 2v0a2 2 0 0 1-2 2h-2a2 2 0 0 1-2-2v0Z"/><path d="m9 14 2 2 4-4"/></svg>',
    gauge:     '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 12a9 9 0 0 1 18 0"/><path d="m12 12 4-3"/><circle cx="12" cy="12" r="1.5"/></svg>',
    shield:    '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10Z"/><path d="m9 12 2 2 4-4"/></svg>',
    grid:      '<svg viewBox="0 0 24 24" aria-hidden="true"><rect x="3.5" y="3.5" width="7" height="7" rx="1"/><rect x="13.5" y="3.5" width="7" height="7" rx="1"/><rect x="3.5" y="13.5" width="7" height="7" rx="1"/><rect x="13.5" y="13.5" width="7" height="7" rx="1"/></svg>'
  };

  // ----- Menu data -------------------------------------------------------
  // Top-level item shapes:
  //   { id, label, active, mega: { layout, columns, productPanel? } }
  //   { id, label, active, href }
  //
  // mega.layout: '3' | '2' | 'platform'
  // productPanel (platform layout only): { title, cards: [{ icon, label, sub, href }] }
  var menu = [
    {
      id: 'product',
      label: 'Product',
      active: activeProduct,
      mega: {
        layout: '3',
        columns: [
          { title: 'Overview', links: [
            { href: '/overview.html',           label: 'Overview' },
            { href: '/overview.html#how',       label: 'How ReliCheck Works' },
            { href: '/samples.html',            label: 'Sample Reports' }
          ]},
          { title: 'Get data in', links: [
            { href: '/signup.html',             label: 'Build an Instrument' },
            { href: '/import-survey.html',      label: 'Upload an Existing Survey' },
            { href: '/import-data.html',        label: 'Upload Data' },
            { href: '/overview.html#collect',   label: 'Collect Responses' }
          ]},
          { title: 'Get results out', links: [
            { href: '/overview.html#analyze',   label: 'Reports & Exports' },
            { href: '/templates.html',          label: 'Templates', emphasis: true }
          ]}
        ]
      }
    },
    {
      id: 'solutions',
      label: 'Solutions',
      active: activeSolutions,
      mega: {
        layout: '2',
        columns: [
          { title: 'For organizations', links: [
            { href: '/hr-teams.html',           label: 'HR & Teams' },
            { href: '/marketing.html',          label: 'Marketing & Customer Feedback' },
            { href: '/program-evaluation.html', label: 'Program Evaluation & Accreditation' }
          ]},
          { title: 'For schools and research', links: [
            { href: '/education.html',          label: 'Education & Course Evaluations' },
            { href: '/research.html',           label: 'Research Teams' },
            { href: '/tests-overview.html',     label: 'Testing & Assessment' }
          ]}
        ]
      }
    },
    {
      id: 'analysis',
      label: 'Analysis',
      active: activeAnalysis,
      mega: {
        layout: '3',
        columns: [
          { title: 'Survey quality', links: [
            { href: '/survey-strength-index.html',   label: 'Survey Strength Index' },
            { href: '/pre-publish-check.html',       label: 'Survey Readiness Check' },
            { href: '/reliability-guide.html',       label: 'Reliability Analysis' },
            { href: '/validity-guide.html',          label: 'Factor & Validity Analysis' }
          ]},
          { title: 'Data analysis', links: [
            { href: '/methodology.html#descriptive', label: 'Descriptive Analysis' },
            { href: '/methodology.html#compare',     label: 'Group Comparisons & Subgroups' },
            { href: '/methodology.html#prepost',     label: 'Pre/Post and Trends' },
            { href: '/methodology.html#predictors',  label: 'Predictors & Key Drivers' }
          ]},
          { title: 'Specialized', links: [
            { href: '/methodology.html#open-ended',  label: 'Open-Ended Response Analysis' },
            { href: '/tests-overview.html',          label: 'Test & Item Analysis' },
            { href: '/import-data.html',             label: 'Data Upload Analysis' }
          ]}
        ]
      }
    },
    {
      id: 'ai',
      label: 'ReliCheck Intelligence',
      active: activeAI,
      mega: {
        layout: '3',
        columns: [
          { title: 'Survey design', links: [
            { href: '/ai-features.html',                label: 'Overview' },
            { href: '/ai/before-you-collect.html',      label: 'Before you collect' },
            { href: '/ai/while-you-analyze.html#readiness-brief', label: 'Readiness Brief' }
          ]},
          { title: 'Collection & analysis', links: [
            { href: '/ai/while-you-collect.html',       label: 'Conversational Take' },
            { href: '/ai/while-you-analyze.html',       label: 'Briefs & Verdict Cards' },
            { href: '/ai/when-you-report.html#test-analytics-brief', label: 'Test Analytics Brief' }
          ]},
          { title: 'Reporting & trust', links: [
            { href: '/ai/when-you-report.html',         label: 'Report Drafter & Verdict' },
            { href: '/ai/principles.html',              label: 'Principles & Privacy' }
          ]}
        ]
      }
    },
    {
      id: 'resources',
      label: 'Resources',
      active: activeResources,
      mega: {
        layout: '3',
        columns: [
          { title: 'Learn', links: [
            { href: '/methodology.html',           label: 'Methodology' },
            { href: '/reliability-guide.html',     label: 'Reliability Guide' },
            { href: '/blog.html',                  label: 'Blog' }
          ]},
          { title: 'Examples', links: [
            { href: '/samples.html',      label: 'Sample Reports' },
            { href: '/case-studies.html', label: 'Case Studies' },
            { href: '/compare.html',      label: 'Compare ReliCheck' }
          ]},
          { title: 'Support', links: [
            { href: '/help.html',                  label: 'Help Center' },
            { href: '/help/privacy-compliance.html', label: 'Privacy & Security' }
          ]}
        ]
      }
    },
    {
      id: 'pricing',
      label: 'Pricing',
      active: activePricing,
      href: '/pricing.html'
    }
  ];

  // ----- Markup builders -------------------------------------------------
  function renderColumn(col) {
    var linksHtml = col.links.map(function (l) {
      var cls = l.emphasis ? ' class="is-emphasis"' : '';
      return '<a href="' + l.href + '"' + cls + '>' + l.label + '</a>';
    }).join('');
    return '<div class="rc-mega-col"><h5>' + col.title + '</h5>' + linksHtml + '</div>';
  }

  function renderProductPanel(panel) {
    var cardsHtml = panel.cards.map(function (c) {
      var icon = ICONS[c.icon] || '';
      return '<a class="rc-mega-pp-card" href="' + c.href + '">' +
        '<span class="icon">' + icon + '</span>' +
        '<span class="body"><strong>' + c.label + '</strong><span>' + c.sub + '</span></span>' +
      '</a>';
    }).join('');
    return '<div class="rc-mega-pp-left">' +
      '<h5>' + panel.title + '</h5>' +
      '<div class="rc-mega-pp-cards">' + cardsHtml + '</div>' +
    '</div>';
  }

  // Build top-level desktop list.
  var topItemsHtml = menu.map(function (item) {
    var activeCls = item.active ? ' is-active' : '';
    if (item.href) {
      return '<li class="rc-nav-item">' +
        '<a class="rc-nav-link' + activeCls + '" href="' + item.href + '">' + item.label + '</a>' +
      '</li>';
    }
    var mega = item.mega;
    var colsHtml = mega.columns.map(renderColumn).join('');
    var inner;
    var megaCls = 'rc-mega';
    if (mega.layout === 'platform' && mega.productPanel) {
      inner = renderProductPanel(mega.productPanel) +
        '<div class="rc-mega-pp-right">' + colsHtml + '</div>';
      megaCls += ' rc-mega-platform';
    } else {
      var layoutCls = 'rc-mega-' + (mega.layout || '3');
      inner = '<div class="rc-mega-inner ' + layoutCls + '">' + colsHtml + '</div>';
    }
    var innerWrap = (mega.layout === 'platform')
      ? '<div class="rc-mega-inner">' + inner + '</div>'
      : inner;
    return '<li class="rc-nav-item" data-rc-mega="' + item.id + '">' +
      '<button type="button" class="rc-nav-link' + activeCls + '" aria-haspopup="true" aria-expanded="false">' +
        item.label + ' <span class="rc-caret">&#9662;</span>' +
      '</button>' +
      '<div class="' + megaCls + '" role="menu">' + innerWrap + '</div>' +
    '</li>';
  }).join('');

  // Build mobile drawer accordion.
  function renderDrawerProductPanel(panel) {
    var cardsHtml = panel.cards.map(function (c) {
      return '<a href="' + c.href + '"><span><strong>' + c.label + '</strong><span>' + c.sub + '</span></span></a>';
    }).join('');
    return '<div class="rc-drawer-pp">' + cardsHtml + '</div>';
  }

  var drawerHtml = menu.map(function (item) {
    if (item.href) {
      var activeClsLink = item.active ? ' is-active' : '';
      return '<a class="rc-drawer-link' + activeClsLink + '" href="' + item.href + '">' + item.label + '</a>';
    }
    var mega = item.mega;
    var pp = (mega.layout === 'platform' && mega.productPanel)
      ? renderDrawerProductPanel(mega.productPanel) : '';
    var colsHtml = mega.columns.map(function (col) {
      return '<div class="rc-drawer-col">' +
        '<h6>' + col.title + '</h6>' +
        col.links.map(function (l) {
          return '<a href="' + l.href + '">' + l.label + '</a>';
        }).join('') +
      '</div>';
    }).join('');
    return '<div class="rc-drawer-section" data-rc-section="' + item.id + '">' +
      '<button type="button" class="rc-drawer-toggle" aria-expanded="false">' +
        item.label + ' <span class="rc-caret">&#9662;</span>' +
      '</button>' +
      '<div class="rc-drawer-panel">' + pp + colsHtml + '</div>' +
    '</div>';
  }).join('');

  var html =
    '<header class="rc-nav" id="relicheck-nav-header">' +
      '<div class="rc-nav-bar">' +
        '<a class="rc-nav-brand" href="/index.html" aria-label="ReliCheck home">' +
          '<img src="/logo-brand.svg" alt="ReliCheck" />' +
        '</a>' +
        '<ul class="rc-nav-list">' + topItemsHtml + '</ul>' +
        '<div class="rc-nav-cta">' +
          '<a class="rc-btn" href="/login.html">Sign in</a>' +
          '<a class="rc-btn rc-btn-primary" href="/signup.html">Start free</a>' +
          '<button type="button" class="rc-nav-toggle" aria-label="Open menu" aria-expanded="false">' +
            '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M4 7h16M4 12h16M4 17h16"/></svg>' +
          '</button>' +
        '</div>' +
      '</div>' +
      '<div class="rc-drawer" id="relicheck-nav-drawer" aria-hidden="true">' +
        drawerHtml +
        '<div class="rc-drawer-cta">' +
          '<a class="rc-btn rc-btn-outline" href="/login.html">Sign in</a>' +
          '<a class="rc-btn rc-btn-primary" href="/signup.html">Start free</a>' +
        '</div>' +
      '</div>' +
    '</header>';

  // ----- Mount + wire interactions ---------------------------------------
  function mount() {
    var slot = document.getElementById('relicheck-nav');
    if (!slot) return;
    slot.outerHTML = html;
    wire();
  }

  function wire() {
    // Desktop mega-menu hover + click.
    var items = document.querySelectorAll('.rc-nav-item[data-rc-mega]');
    var openTimer;
    items.forEach(function (it) {
      var btn = it.querySelector('.rc-nav-link');
      var setOpen = function (state) {
        clearTimeout(openTimer);
        if (state) {
          items.forEach(function (other) {
            if (other !== it) other.removeAttribute('data-open');
            var otherBtn = other.querySelector('.rc-nav-link');
            if (otherBtn && other !== it) otherBtn.setAttribute('aria-expanded', 'false');
          });
          it.setAttribute('data-open', 'true');
          if (btn) btn.setAttribute('aria-expanded', 'true');
        } else {
          openTimer = setTimeout(function () {
            it.removeAttribute('data-open');
            if (btn) btn.setAttribute('aria-expanded', 'false');
          }, 140);
        }
      };
      it.addEventListener('mouseenter', function () { setOpen(true); });
      it.addEventListener('mouseleave', function () { setOpen(false); });
      if (btn) btn.addEventListener('click', function (e) {
        e.preventDefault();
        setOpen(it.getAttribute('data-open') !== 'true');
      });
    });
    // Close any open mega on outside click.
    document.addEventListener('click', function (e) {
      if (!e.target.closest('.rc-nav-item[data-rc-mega]')) {
        items.forEach(function (it) {
          it.removeAttribute('data-open');
          var b = it.querySelector('.rc-nav-link');
          if (b) b.setAttribute('aria-expanded', 'false');
        });
      }
    });
    // Close on Escape.
    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape') {
        items.forEach(function (it) {
          it.removeAttribute('data-open');
          var b = it.querySelector('.rc-nav-link');
          if (b) b.setAttribute('aria-expanded', 'false');
        });
      }
    });

    // Mobile hamburger toggle.
    var toggle = document.querySelector('.rc-nav-toggle');
    var drawer = document.getElementById('relicheck-nav-drawer');
    if (toggle && drawer) {
      toggle.addEventListener('click', function () {
        var open = drawer.classList.toggle('is-open');
        drawer.setAttribute('aria-hidden', String(!open));
        toggle.setAttribute('aria-expanded', String(open));
        document.body.style.overflow = open ? 'hidden' : '';
      });
    }

    // Mobile accordion toggles.
    document.querySelectorAll('.rc-drawer-section').forEach(function (sec) {
      var t = sec.querySelector('.rc-drawer-toggle');
      if (!t) return;
      t.addEventListener('click', function () {
        var open = sec.getAttribute('data-open') === 'true';
        if (open) {
          sec.removeAttribute('data-open');
          t.setAttribute('aria-expanded', 'false');
        } else {
          sec.setAttribute('data-open', 'true');
          t.setAttribute('aria-expanded', 'true');
        }
      });
    });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', mount);
  } else {
    mount();
  }
})();
