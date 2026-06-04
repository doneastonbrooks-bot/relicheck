<?php
// ===================================================================
// app-2026v4.php — Main ReliCheck hub (Step 1 of the user flow).
// ===================================================================
// The hub for the whole ReliCheck ecosystem, in the shared RSSI-style
// landing visual language (landing.css + _landing_head/_landing_foot).
//
// User flow:
//   1. Login → app-2026v4.php (this page) — welcome + studio/app cards
//   2. Click a card → its RSSI-style landing page (studio-*.php /
//      survey-dev.php / rssi.php) — NOT straight into a backend workspace
//   3. From the landing, start/open a project → the workspace (with rail)
//
// Each card carries its own accent (per-studio identity) within the
// shared layout. Cards are registry-driven (_studio_registry.php).

require_once __DIR__ . '/api/_db.php';
require_once __DIR__ . '/api/_session.php';

start_session_secure();
$uid = current_user_id();
if (!$uid) {
  header('Location: /login.html?return=' . urlencode('/app-2026v4.php'));
  exit;
}
$user = current_user();
if (!$user) {
  $_SESSION = []; session_destroy();
  header('Location: /login.html');
  exit;
}

$studios = require __DIR__ . '/_studio_registry.php';

// Hero / greeting
$hour = (int)date('G');
if      ($hour < 12) { $greeting = 'Good morning'; }
else if ($hour < 17) { $greeting = 'Good afternoon'; }
else                 { $greeting = 'Good evening'; }
$user_full  = $user['name'] ?? $user['email'] ?? 'You';
$user_first = trim((string)preg_replace('/\s.*/', '', $user_full));
if ($user_first === '') $user_first = 'there';
$initials   = strtoupper(substr(preg_replace('/[^A-Za-z]/', '', $user_full) ?: 'U', 0, 2));

// Split registry into Basic (free), Apps, Research Studios, and Assessment Studios.
$basic_entry      = null;
$apps_only        = [];
$research_studios = [];
$assessment_studios = [];
foreach ($studios as $slug => $s) {
  if ($slug === 'basic') {
    $basic_entry = $s;
  } elseif ((string)($s['kind'] ?? 'studio') === 'app') {
    $apps_only[$slug] = $s;
  } elseif ((string)($s['category'] ?? 'research') === 'assessment') {
    $assessment_studios[$slug] = $s;
  } else {
    $research_studios[$slug] = $s;
  }
}

// Landing shell variables
$landing_title         = 'ReliCheck';
$landing_user_initials = $initials;
$landing_user_full     = $user_full;
$landing_pill_label    = '';

include __DIR__ . '/_landing_head.php';
echo '<div class="relicheck-main-shell">';

// Card renderer shared by Studios + Apps.
function lp_card(array $s, string $open_label): void {
  $accent = $s['accent'] ?? '#2D8DFF';
  $intro  = htmlspecialchars($s['route']  ?? '#');
  $direct = htmlspecialchars($s['direct'] ?? $s['route'] ?? '#');
  ?>
  <div class="lp-card relicheck-product-card" style="--card-accent: <?= htmlspecialchars($accent) ?>;">
    <a class="lp-card-body" href="<?= $intro ?>" aria-label="Learn about <?= htmlspecialchars($s['name']) ?>">
      <span class="glyph" aria-hidden="true"><img src="<?= htmlspecialchars($s['mark']) ?>" alt=""></span>
      <h3 class="name"><?= htmlspecialchars($s['name']) ?></h3>
      <p class="desc"><?= htmlspecialchars($s['description']) ?></p>
    </a>
    <div class="foot">
      <span class="lp-status" data-status="<?= htmlspecialchars($s['status']) ?>"><?= htmlspecialchars($s['status_label']) ?></span>
      <div class="card-btns">
        <a class="card-btn-intro" href="<?= $intro ?>">Intro</a>
        <a class="card-btn-open"  href="<?= $direct ?>"><?= htmlspecialchars($open_label) ?> →</a>
      </div>
    </div>
  </div>
  <?php
}
?>

<!-- ===== Hero greeting ===== -->
<section class="lp-hero left">
  <h1>
    <?= htmlspecialchars($greeting) ?>, <?= htmlspecialchars($user_first) ?>.
    <span class="accent">Choose where your evidence work begins.</span>
  </h1>
  <p class="lede">Build surveys, strengthen instruments, analyze responses, and move from data to evidence you can trust.</p>
</section>

<!-- ===== Projects + Basic strips (side by side) ===== -->
<style>
.rc-strips-row {
  display: flex; gap: 16px; align-items: stretch;
}
@media (max-width: 600px) {
  .rc-strips-row { flex-direction: column; }
}
.rc-projects-strip {
  flex: 1;
  display: flex; align-items: center; gap: 16px;
  padding: 18px 24px; border-radius: 16px;
  background: var(--surface); border: 1px solid var(--hairline-2);
  text-decoration: none; color: inherit;
  box-shadow: var(--shadow-sm);
  transition: box-shadow .15s, border-color .15s;
}
.rc-projects-strip:hover {
  box-shadow: var(--shadow-md);
  border-color: var(--accent);
}
.rc-projects-icon {
  width: 40px; height: 40px; border-radius: 11px; flex: none;
  background: var(--accent-soft); color: var(--accent-deep);
  display: grid; place-items: center;
}
.rc-projects-icon svg { width: 18px; height: 18px; }
.rc-projects-label {
  font-size: 15px; font-weight: 700; color: var(--text); letter-spacing: -.01em;
}
.rc-projects-sub {
  font-size: 13px; color: var(--text-2); margin-top: 2px;
}
.rc-projects-arrow {
  margin-left: auto; flex: none;
  font-size: 13px; font-weight: 600; color: var(--accent-deep);
  white-space: nowrap;
}
.rc-free-strip {
  flex: 1;
  display: flex; align-items: center; gap: 16px;
  padding: 18px 20px; border-radius: 16px;
  background: #f8f8f8; border: 1px solid #e5e7eb;
  text-decoration: none; color: inherit;
  transition: border-color .15s;
}
.rc-free-strip:hover { border-color: #bbb; }
.rc-free-strip img { height: 34px; width: auto; display: block; opacity: .75; }
.rc-free-strip-text { flex: 1; font-size: 13px; color: #6b7280; line-height: 1.4; }
.rc-free-strip-text strong { font-size: 13.5px; font-weight: 700; color: #374151; display: block; margin-bottom: 2px; }
</style>
<section class="lp-section" style="padding-top:32px;padding-bottom:0">
  <div class="rc-strips-row">
    <?php if ($basic_entry): ?>
    <a href="<?= htmlspecialchars($basic_entry['route'] ?? '#') ?>" class="rc-free-strip">
      <img src="<?= htmlspecialchars($basic_entry['mark']) ?>" alt="<?= htmlspecialchars($basic_entry['name']) ?>">
      <div class="rc-free-strip-text">
        <strong><?= htmlspecialchars($basic_entry['name']) ?></strong>
        <?= htmlspecialchars($basic_entry['description']) ?>
      </div>
      <span style="font-size:12px;color:#9ca3af;white-space:nowrap;">Try free &rarr;</span>
    </a>
    <?php endif; ?>
    <a href="/projects.php" class="rc-projects-strip">
      <div class="rc-projects-icon">
        <svg viewBox="0 0 18 18" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round">
          <rect x="1" y="1" width="7" height="7" rx="1.5"/>
          <rect x="10" y="1" width="7" height="7" rx="1.5"/>
          <rect x="1" y="10" width="7" height="7" rx="1.5"/>
          <rect x="10" y="10" width="7" height="7" rx="1.5"/>
        </svg>
      </div>
      <div>
        <div class="rc-projects-label">Your Projects</div>
        <div class="rc-projects-sub" id="projectsStripSub">All your work across every studio</div>
      </div>
      <span class="rc-projects-arrow">View all &rarr;</span>
    </a>
  </div>
</section>
<script>
(function(){
  var sub = document.getElementById('projectsStripSub');
  if (!sub) return;
  var sources = [
    '/api/dev/project-list.php',
    '/api/qual/list-projects.php',
    '/api/mm/projects.php',
    '/api/tia/projects.php',
    '/api/panels/list.php',
  ];
  var total = 0, done = 0;
  sources.forEach(function(url) {
    fetch(url, {credentials:'same-origin', headers:{Accept:'application/json'}})
      .then(function(r){ return r.ok ? r.json() : {}; })
      .catch(function(){ return {}; })
      .then(function(d) {
        var list = d.projects || d.panels || d.list || [];
        total += Array.isArray(list) ? list.length : 0;
        done++;
        if (done === sources.length && total > 0) {
          sub.textContent = total + ' project' + (total === 1 ? '' : 's') + ' across your studios';
        }
      });
  });
})();
</script>

<!-- ===== Apps ===== -->
<?php if (!empty($apps_only)): ?>
<section class="lp-section">
  <div class="lp-section-head">
    <h2 class="relicheck-section-title">Apps</h2>
    <p class="relicheck-section-subtitle">Build a survey end to end, or score the evidence you have already collected.</p>
  </div>
  <div class="relicheck-card-grid">
    <?php foreach ($apps_only as $s) lp_card($s, 'Open'); ?>
  </div>
</section>
<?php endif; ?>

<!-- ===== Research Studios ===== -->
<?php if (!empty($research_studios)): ?>
<section class="lp-section">
  <div class="lp-section-head">
    <h2 class="relicheck-section-title">Research Studios</h2>
    <p class="relicheck-section-subtitle">Analyze and interpret your evidence: descriptively, inferentially, qualitatively, or across methods.</p>
  </div>
  <div class="relicheck-card-grid cols-2">
    <?php foreach ($research_studios as $s) lp_card($s, 'Open'); ?>
  </div>
</section>
<?php endif; ?>

<!-- ===== Assessment Studios ===== -->
<?php if (!empty($assessment_studios)): ?>
<section class="lp-section">
  <div class="lp-section-head">
    <h2 class="relicheck-section-title">Assessment Studios</h2>
    <p class="relicheck-section-subtitle">Purpose-built workspaces for specific instrument types: tests, items, and multi-rater feedback.</p>
  </div>
  <div class="relicheck-card-grid cols-2">
    <?php foreach ($assessment_studios as $s) lp_card($s, 'Open'); ?>
  </div>
</section>
<?php endif; ?>

<!-- ===== Recent projects strip ===== -->
<section class="lp-section">
  <div class="lp-section-head">
    <h2>Recent</h2>
    <p>Jump back in. Reopening a recent project goes straight to its workspace.</p>
  </div>
  <div class="lp-recent-grid" id="recentGrid">
    <p class="lp-recent-loading">Loading your recent projects…</p>
  </div>
</section>

<script>
  (function () {
    const host = document.getElementById('recentGrid');
    if (!host) return;
    const endpoints = [
      { studio: 'mm',     label: 'MM Studio',                  url: '/api/mm/projects.php',        href: function(p){ return '/mmstudioV4.php?project_id=' + encodeURIComponent(p.id); } },
      { studio: 'survey', label: 'Survey Development System',  url: '/api/dev/project-list.php',   href: function(){ return '/develop.php?db=1'; } },
      { studio: 'qual',   label: 'Qualitative Analysis Studio',url: '/api/qual/list-projects.php', href: function(p){ return '/qual-studio-workspaceV3.php?project_id=' + encodeURIComponent(p.id); } },
      { studio: '360',    label: '360 Studio',                 url: '/api/panels/list.php',        href: function(p){ return '/studio-360.php?project_id=' + encodeURIComponent(p.id); } },
    ];
    Promise.all(endpoints.map(function (e) {
      return fetch(e.url, { credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
        .then(function (r) { return r.ok ? r.json() : { ok: false }; })
        .catch(function () { return { ok: false }; })
        .then(function (d) {
          if (!d || !d.ok) return [];
          const list = d.projects || d.surveys || d.panels || d.list || [];
          return list.map(function (p) {
            return {
              studio: e.studio, studioLabel: e.label, id: p.id,
              title: p.title || p.name || 'Untitled',
              updated_at: p.updated_at || p.modified_at || p.created_at || null,
              href: e.href(p),
            };
          });
        });
    }))
      .then(function (groups) {
        const all = [].concat.apply([], groups)
          .filter(function (p) { return p && p.title; })
          .sort(function (a, b) {
            return (b.updated_at ? Date.parse(b.updated_at) || 0 : 0) - (a.updated_at ? Date.parse(a.updated_at) || 0 : 0);
          })
          .slice(0, 4);
        if (!all.length) {
          host.innerHTML = '<p class="lp-recent-empty">No recent projects yet. Pick a studio above to start one.</p>';
          return;
        }
        host.innerHTML = all.map(function (p) {
          const when = p.updated_at ? new Date(p.updated_at).toLocaleDateString() : 'recent';
          return '<a class="lp-recent-card" href="' + esc(p.href) + '" aria-label="Open ' + esc(p.title) + '">' +
                   '<span class="stripe" aria-hidden="true"></span>' +
                   '<div class="r-label">' + esc(p.studioLabel) + '</div>' +
                   '<div class="r-title">' + esc(p.title) + '</div>' +
                   '<div class="r-meta">' + esc(when) + '</div>' +
                 '</a>';
        }).join('');
      })
      .catch(function () {
        host.innerHTML = '<p class="lp-recent-error">Could not load recent projects.</p>';
      });
    function esc(s) {
      return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
        return ({ '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;' })[c];
      });
    }
  })();
</script>

<?php
echo '</div>'; // .relicheck-main-shell
$landing_tagline = 'From first draft to evidence you can defend.';
include __DIR__ . '/_landing_foot.php';
