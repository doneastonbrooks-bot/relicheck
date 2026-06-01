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

// Split registry into Studios (exploratory workspaces) and Apps (one-shot tools).
$studios_only = [];
$apps_only    = [];
foreach ($studios as $slug => $s) {
  if ((string)($s['kind'] ?? 'studio') === 'app') $apps_only[$slug] = $s;
  else                                            $studios_only[$slug] = $s;
}

// Landing shell variables
$landing_title         = 'ReliCheck';
$landing_user_initials = $initials;
$landing_user_full     = $user_full;
$landing_pill_label    = '';

include __DIR__ . '/_landing_head.php';

// Card renderer shared by Studios + Apps.
function lp_card(array $s, string $open_label): void {
  $accent      = $s['accent']      ?? '#2D8DFF';
  $accent_deep = $s['accent_deep'] ?? $accent;
  ?>
  <a class="lp-card"
     style="--card-accent: <?= htmlspecialchars($accent) ?>;"
     href="<?= htmlspecialchars($s['route'] ?? '#') ?>"
     aria-label="Open <?= htmlspecialchars($s['name']) ?>">
    <span class="glyph" aria-hidden="true"><img src="<?= htmlspecialchars($s['mark']) ?>" alt=""></span>
    <h3 class="name"><?= htmlspecialchars($s['name']) ?></h3>
    <p class="desc"><?= htmlspecialchars($s['description']) ?></p>
    <div class="foot">
      <span class="lp-status" data-status="<?= htmlspecialchars($s['status']) ?>"><?= htmlspecialchars($s['status_label']) ?></span>
      <span class="open-link"><?= htmlspecialchars($open_label) ?>
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14"/><path d="M13 6l6 6-6 6"/></svg>
      </span>
    </div>
  </a>
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

<!-- ===== Apps ===== -->
<?php if (!empty($apps_only)): ?>
<section class="lp-section" style="max-width:none;">
  <div class="lp-section-head">
    <h2>Apps</h2>
    <p>Build a survey end to end, or score the evidence you have already collected.</p>
  </div>
  <div class="lp-grid cols-3">
    <?php foreach ($apps_only as $s) lp_card($s, 'Open'); ?>
  </div>
</section>
<?php endif; ?>

<!-- ===== Studios ===== -->
<section class="lp-section" style="max-width:none;">
  <div class="lp-section-head">
    <h2>Studios</h2>
    <p>Exploratory workspaces. Pick the one that fits your study design.</p>
  </div>
  <div class="lp-grid cols-3">
    <?php foreach ($studios_only as $s) lp_card($s, 'Open'); ?>
  </div>
</section>

<!-- ===== Recent projects strip ===== -->
<section class="lp-section" style="max-width:none;">
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
      { studio: 'mm',     label: 'MM Studio',     url: '/api/mm/projects.php'   },
      { studio: 'survey', label: 'Survey Studio', url: '/api/surveys/list.php'  },
      { studio: '360',    label: '360 Studio',    url: '/api/panels/list.php'   },
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
              href: e.studio === 'mm'     ? '/studio-mm.php?project_id='     + encodeURIComponent(p.id) :
                    e.studio === 'survey' ? '/studio-survey.php?project_id=' + encodeURIComponent(p.id) :
                    e.studio === '360'    ? '/studio-360.php?project_id='    + encodeURIComponent(p.id) : '#',
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
$landing_tagline = 'From first draft to evidence you can defend.';
include __DIR__ . '/_landing_foot.php';
