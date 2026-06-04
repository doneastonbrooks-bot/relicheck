<?php
require_once __DIR__ . '/api/_db.php';
require_once __DIR__ . '/api/_session.php';

start_session_secure();
$uid = current_user_id();
if (!$uid) {
    header('Location: /login.html?return=' . urlencode('/projects.php'));
    exit;
}
$user = current_user();
if (!$user) { $_SESSION = []; session_destroy(); header('Location: /login.html'); exit; }

$user_full = $user['name'] ?? $user['email'] ?? '';
$initials  = strtoupper(substr(preg_replace('/[^A-Za-z]/', '', $user_full) ?: 'U', 0, 2));

$landing_title         = 'Projects — ReliCheck';
$landing_user_initials = $initials;
$landing_user_full     = $user_full;
$landing_show_back     = false;

include __DIR__ . '/_landing_head.php';
?>
<style>
/* ── Full-height layout below the sticky header ── */
.prj-layout {
  display: flex;
  height: calc(100vh - 68px); /* header height */
  overflow: hidden;
}

/* ── Sidebar ── */
.prj-sidebar {
  width: 220px; flex: none;
  background: var(--surface);
  border-right: 1px solid var(--hairline);
  overflow-y: auto;
  padding: 20px 0 32px;
}
.prj-sidebar-section {
  padding: 0 12px 8px;
  font-size: 10px; font-weight: 800; letter-spacing: .12em; text-transform: uppercase;
  color: var(--text-3); margin-top: 20px;
}
.prj-sidebar-section:first-child { margin-top: 0; }
.prj-nav-item {
  display: flex; align-items: center; gap: 9px;
  padding: 7px 16px; border-radius: 0;
  font-size: 13.5px; font-weight: 500; color: var(--text-2);
  cursor: pointer; background: none; border: none; width: 100%; text-align: left;
  transition: background .1s, color .1s;
}
.prj-nav-item:hover { background: var(--bg); color: var(--text); }
.prj-nav-item.active {
  background: var(--accent-soft); color: var(--accent-deep); font-weight: 700;
}
.prj-nav-dot {
  width: 8px; height: 8px; border-radius: 50%; flex: none;
}
.prj-nav-count {
  margin-left: auto;
  font-size: 11.5px; font-weight: 600; color: var(--text-3);
}
.prj-nav-item.active .prj-nav-count { color: var(--accent-deep); }

/* ── Main area ── */
.prj-main {
  flex: 1; min-width: 0;
  display: flex; flex-direction: column;
  overflow: hidden;
  background: var(--bg);
}

/* ── Toolbar ── */
.prj-toolbar {
  display: flex; align-items: center; gap: 12px;
  padding: 16px 28px;
  background: var(--surface);
  border-bottom: 1px solid var(--hairline);
  flex: none;
}
.prj-toolbar-title {
  font-size: 15px; font-weight: 700; color: var(--text);
  margin-right: 4px; white-space: nowrap;
}
.prj-spacer { flex: 1; }

/* ── Filter dropdowns ── */
.prj-select {
  padding: 7px 12px; border-radius: 8px;
  border: 1px solid var(--hairline-2); background: var(--surface);
  font-size: 13px; font-family: inherit; color: var(--text-2);
  cursor: pointer; outline: none;
}
.prj-select:focus { border-color: var(--accent); }

/* ── Search ── */
.prj-search-wrap { position: relative; }
.prj-search-ico {
  position: absolute; left: 10px; top: 50%; transform: translateY(-50%);
  color: var(--text-3); pointer-events: none;
}
.prj-search {
  padding: 7px 12px 7px 32px; border-radius: 8px;
  border: 1px solid var(--hairline-2); background: var(--surface);
  font-size: 13px; font-family: inherit; color: var(--text);
  outline: none; width: 200px; transition: width .2s, border-color .13s;
}
.prj-search:focus { border-color: var(--accent); width: 260px; }
.prj-search::placeholder { color: var(--text-3); }

/* ── Create button ── */
.prj-create {
  display: inline-flex; align-items: center; gap: 6px;
  padding: 8px 18px; border-radius: 8px;
  background: var(--accent); color: #fff;
  font-size: 13.5px; font-weight: 700; border: none; cursor: pointer;
  white-space: nowrap; transition: opacity .14s; text-decoration: none;
}
.prj-create:hover { opacity: .87; }

/* ── New project dropdown ── */
.prj-create-wrap { position: relative; }
.prj-create-menu {
  position: absolute; top: calc(100% + 8px); right: 0;
  min-width: 230px; background: var(--surface);
  border: 1px solid var(--hairline-2); border-radius: 12px;
  box-shadow: var(--shadow-lg); padding: 4px 0;
  opacity: 0; pointer-events: none;
  transform: translateY(-4px); transition: opacity .13s, transform .13s;
  z-index: 100;
}
.prj-create-menu.open { opacity: 1; pointer-events: auto; transform: none; }
.prj-menu-label {
  padding: 8px 14px 4px;
  font-size: 10px; font-weight: 800; letter-spacing: .1em; text-transform: uppercase;
  color: var(--text-3);
}
.prj-menu-item {
  display: flex; align-items: center; gap: 9px;
  padding: 9px 14px; text-decoration: none; color: var(--text);
  font-size: 13.5px; font-weight: 500; transition: background .1s;
}
.prj-menu-item:hover { background: var(--bg); }
.prj-menu-dot { width: 9px; height: 9px; border-radius: 50%; flex: none; }
.prj-menu-div { height: 1px; background: var(--hairline); margin: 3px 0; }

/* ── Table scroll area ── */
.prj-scroll { flex: 1; overflow-y: auto; }

/* ── Table ── */
.prj-table {
  width: 100%; border-collapse: collapse;
  background: var(--surface);
}
.prj-table th {
  position: sticky; top: 0;
  padding: 10px 16px;
  font-size: 11.5px; font-weight: 700; color: var(--text-2);
  text-align: left; white-space: nowrap;
  background: var(--surface); border-bottom: 1px solid var(--hairline);
  cursor: pointer; user-select: none;
}
.prj-table th:hover { color: var(--text); }
.prj-table th.sort-asc::after  { content: ' ↑'; color: var(--accent); }
.prj-table th.sort-desc::after { content: ' ↓'; color: var(--accent); }
.prj-table td {
  padding: 12px 16px;
  font-size: 13.5px; color: var(--text-2);
  border-bottom: 1px solid var(--hairline);
  vertical-align: middle;
}
.prj-table tr:last-child td { border-bottom: none; }
.prj-table tr:hover td { background: var(--bg); }

/* ── Star cell ── */
.prj-star {
  background: none; border: none; cursor: pointer; padding: 2px;
  color: var(--text-3); transition: color .13s; line-height: 1;
}
.prj-star:hover, .prj-star.on { color: #F59E0B; }
.prj-star svg { width: 15px; height: 15px; display: block; }

/* ── Studio icon ── */
.prj-icon { width: 28px; height: 28px; border-radius: 7px; object-fit: contain; }

/* ── Project name ── */
.prj-name-cell { max-width: 340px; }
.prj-name-link {
  font-size: 14px; font-weight: 600; color: var(--text);
  text-decoration: none;
}
.prj-name-link:hover { color: var(--accent-deep); }

/* ── Studio badge ── */
.prj-studio-badge {
  display: inline-flex; align-items: center; gap: 5px;
  font-size: 12px; font-weight: 500; color: var(--text-2); white-space: nowrap;
}
.prj-badge-dot { width: 7px; height: 7px; border-radius: 50%; flex: none; }

/* ── Status badge ── */
.prj-status {
  display: inline-flex; align-items: center;
  padding: 3px 9px; border-radius: 999px;
  font-size: 11.5px; font-weight: 700; white-space: nowrap;
}
.prj-status-active { background: #ECFDF5; color: #065F46; }
.prj-status-draft  { background: var(--bg); color: var(--text-3); border: 1px solid var(--hairline); }

/* ── Chips in table ── */
.prj-meta { font-size: 12.5px; color: var(--text-3); white-space: nowrap; }

/* ── Empty / loading ── */
.prj-state {
  padding: 80px 24px; text-align: center;
}
.prj-state-title { font-size: 16px; font-weight: 700; color: var(--text); margin-bottom: 6px; }
.prj-state-sub   { font-size: 13.5px; color: var(--text-2); }
.prj-spinner {
  width: 22px; height: 22px; border-radius: 50%;
  border: 2px solid var(--hairline-2); border-top-color: var(--accent);
  animation: spin .7s linear infinite; margin: 0 auto 16px;
}
@keyframes spin { to { transform: rotate(360deg); } }
</style>

<div class="prj-layout">

  <!-- Sidebar -->
  <nav class="prj-sidebar" aria-label="Studios">
    <div class="prj-sidebar-section">All</div>
    <button class="prj-nav-item active" data-filter="all" id="nav-all">
      <svg width="14" height="14" viewBox="0 0 14 14" fill="none" style="flex:none"><rect x="1" y="1" width="5" height="5" rx="1.2" stroke="currentColor" stroke-width="1.5"/><rect x="8" y="1" width="5" height="5" rx="1.2" stroke="currentColor" stroke-width="1.5"/><rect x="1" y="8" width="5" height="5" rx="1.2" stroke="currentColor" stroke-width="1.5"/><rect x="8" y="8" width="5" height="5" rx="1.2" stroke="currentColor" stroke-width="1.5"/></svg>
      All Projects
      <span class="prj-nav-count" id="navCountAll">...</span>
    </button>

    <div class="prj-sidebar-section">Apps</div>
    <button class="prj-nav-item" data-filter="survey">
      <span class="prj-nav-dot" style="background:#E07820"></span>
      Survey Dev
      <span class="prj-nav-count" id="navCountSurvey">0</span>
    </button>

    <div class="prj-sidebar-section">Research Studios</div>
    <button class="prj-nav-item" data-filter="da">
      <span class="prj-nav-dot" style="background:#0e7490"></span>
      Descriptive
      <span class="prj-nav-count" id="navCountDa">0</span>
    </button>
    <button class="prj-nav-item" data-filter="is">
      <span class="prj-nav-dot" style="background:#1d4ed8"></span>
      Inferential
      <span class="prj-nav-count" id="navCountIs">0</span>
    </button>
    <button class="prj-nav-item" data-filter="qual">
      <span class="prj-nav-dot" style="background:#1e5c3a"></span>
      Qual Studio
      <span class="prj-nav-count" id="navCountQual">0</span>
    </button>
    <button class="prj-nav-item" data-filter="mm">
      <span class="prj-nav-dot" style="background:#6d4ad8"></span>
      MM Studio
      <span class="prj-nav-count" id="navCountMm">0</span>
    </button>
    <button class="prj-nav-item" data-filter="text">
      <span class="prj-nav-dot" style="background:#D97706"></span>
      Text Analyzer
      <span class="prj-nav-count" id="navCountText">0</span>
    </button>

    <div class="prj-sidebar-section">Assessment Studios</div>
    <button class="prj-nav-item" data-filter="tia">
      <span class="prj-nav-dot" style="background:#0e8a6f"></span>
      TIA Studio
      <span class="prj-nav-count" id="navCountTia">0</span>
    </button>
    <button class="prj-nav-item" data-filter="360">
      <span class="prj-nav-dot" style="background:#2563eb"></span>
      360 Studio
      <span class="prj-nav-count" id="navCount360">0</span>
    </button>
  </nav>

  <!-- Main -->
  <div class="prj-main">

    <!-- Toolbar -->
    <div class="prj-toolbar">
      <span class="prj-toolbar-title" id="toolbarTitle">All Projects</span>

      <select class="prj-select" id="statusFilter">
        <option value="all">All statuses</option>
        <option value="active">Active</option>
        <option value="draft">Draft</option>
      </select>

      <select class="prj-select" id="sortSelect">
        <option value="updated">Last modified</option>
        <option value="name">Name A-Z</option>
        <option value="created">Date created</option>
      </select>

      <div class="prj-spacer"></div>

      <div class="prj-search-wrap">
        <svg class="prj-search-ico" width="14" height="14" viewBox="0 0 14 14" fill="none">
          <circle cx="6" cy="6" r="4.5" stroke="currentColor" stroke-width="1.5"/>
          <path d="M9.5 9.5l3 3" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/>
        </svg>
        <input class="prj-search" type="search" id="searchInput" placeholder="Search..." autocomplete="off">
      </div>

      <div class="prj-create-wrap">
        <button class="prj-create" id="createBtn" aria-haspopup="menu" aria-expanded="false">
          <svg width="12" height="12" viewBox="0 0 12 12" fill="none"><path d="M6 1v10M1 6h10" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>
          New project
        </button>
        <div class="prj-create-menu" id="createMenu">
          <div class="prj-menu-label">Apps</div>
          <a class="prj-menu-item" href="/develop.php?db=1&start=choose"><span class="prj-menu-dot" style="background:#E07820"></span>Survey Development</a>
          <div class="prj-menu-div"></div>
          <div class="prj-menu-label">Research Studios</div>
          <a class="prj-menu-item" href="/descriptive-analysis-workspace.php"><span class="prj-menu-dot" style="background:#0e7490"></span>Descriptive Studio</a>
          <a class="prj-menu-item" href="/inferential-statistics-workspaceV4.php"><span class="prj-menu-dot" style="background:#1d4ed8"></span>Inferential Studio</a>
          <a class="prj-menu-item" href="/qual-studio-workspaceV3.php"><span class="prj-menu-dot" style="background:#1e5c3a"></span>Qual Studio</a>
          <a class="prj-menu-item" href="/mmstudioV4.php"><span class="prj-menu-dot" style="background:#6d4ad8"></span>MM Studio</a>
          <a class="prj-menu-item" href="/text-analyzer.php"><span class="prj-menu-dot" style="background:#D97706"></span>Text Analyzer</a>
          <div class="prj-menu-div"></div>
          <div class="prj-menu-label">Assessment Studios</div>
          <a class="prj-menu-item" href="/tia-wizard.php?step=1"><span class="prj-menu-dot" style="background:#0e8a6f"></span>TIA Studio</a>
          <a class="prj-menu-item" href="/360-wizard.php?step=1"><span class="prj-menu-dot" style="background:#2563eb"></span>360 Studio</a>
        </div>
      </div>
    </div>

    <!-- Table -->
    <div class="prj-scroll">
      <table class="prj-table" id="prjTable">
        <thead>
          <tr>
            <th style="width:32px"></th>
            <th style="width:36px"></th>
            <th data-col="name">Project name</th>
            <th data-col="studio">Studio</th>
            <th data-col="status">Status</th>
            <th data-col="meta">Details</th>
            <th data-col="updated" class="sort-desc">Last modified</th>
          </tr>
        </thead>
        <tbody id="prjBody">
          <tr><td colspan="7">
            <div class="prj-state">
              <div class="prj-spinner"></div>
              <div class="prj-state-title">Loading your projects</div>
            </div>
          </td></tr>
        </tbody>
      </table>
    </div>

  </div>
</div>

<script>
(function () {
  var SOURCES = [
    {
      key: 'survey', label: 'Survey Development', accent: '#E07820', icon: '/SIRI.png',
      url: '/api/dev/project-list.php',
      name:   function(p){ return p.title||'Untitled'; },
      href:   function(p){ return '/develop.php?db=1'; },
      status: function(p){ return p.status||'active'; },
      meta:   function(p){ var c=[]; if(p.items) c.push(p.items+' items'); if(p.response_count) c.push(p.response_count+' responses'); if(p.siri!=null) c.push('SIRI '+Math.round(p.siri)); return c.join(' · '); },
      list:   function(d){ return (d&&d.ok&&d.projects)?d.projects:[]; },
      ts:     function(p){ return p.updated_at||''; },
    },
    {
      key: 'qual', label: 'Qual Studio', accent: '#1e5c3a', icon: '/Qualitative%20Analysis.png',
      url: '/api/qual/list-projects.php',
      name:   function(p){ return p.title||'Untitled'; },
      href:   function(p){ return '/qual-studio-workspaceV3.php?project_id='+p.id; },
      status: function(p){ return p.status||'active'; },
      meta:   function(p){ var c=[]; if(p.seg_count) c.push(p.seg_count+' segments'); if(p.code_count) c.push(p.code_count+' codes'); return c.join(' · '); },
      list:   function(d){ return (d&&d.ok&&d.projects)?d.projects:[]; },
      ts:     function(p){ return p.updated_at||''; },
    },
    {
      key: 'mm', label: 'MM Studio', accent: '#6d4ad8', icon: '/MM%20Studio.png',
      url: '/api/mm/projects.php',
      name:   function(p){ return p.title||'Untitled'; },
      href:   function(p){ return '/mmstudioV4.php?project_id='+p.id; },
      status: function(p){ return p.status||'active'; },
      meta:   function(p){ return p.pathway?p.pathway.replace(/_/g,' '):''; },
      list:   function(d){ return (d&&d.ok&&d.projects)?d.projects:[]; },
      ts:     function(p){ return p.updated_at||''; },
    },
    {
      key: 'tia', label: 'TIA Studio', accent: '#0e8a6f', icon: '/TIA%20Studio.png',
      url: '/api/tia/projects.php',
      name:   function(p){ return p.title||'Untitled'; },
      href:   function(p){ return '/item-quality.php?project_id='+p.id; },
      status: function(p){ return p.status||'active'; },
      meta:   function(p){ return ''; },
      list:   function(d){ return (d&&d.ok&&d.projects)?d.projects:[]; },
      ts:     function(p){ return p.updated_at||''; },
    },
    {
      key: '360', label: '360 Studio', accent: '#2563eb', icon: '/360%20Studio.png',
      url: '/api/panels/list.php',
      name:   function(p){ return p.name||p.survey_title||'Untitled'; },
      href:   function(p){ return '/studio-360.php?project_id='+p.id; },
      status: function(p){ return p.status||'active'; },
      meta:   function(p){ var c=[]; if(p.subjects) c.push(p.subjects+' subjects'); if(p.evaluators) c.push(p.evaluators+' raters'); return c.join(' · '); },
      list:   function(d){ return d&&(d.panels||d.projects||d.list)||[]; },
      ts:     function(p){ return p.updated_at||p.created_at||''; },
    },
  ];

  var all = [];
  var activeFilter = 'all';
  var activeSort   = 'updated';
  var activeStatus = 'all';
  var searchTerm   = '';
  var pending = SOURCES.length;
  var starred = JSON.parse(localStorage.getItem('prj-starred') || '{}');

  var navCounts = {
    all: document.getElementById('navCountAll'),
    survey: document.getElementById('navCountSurvey'),
    da: document.getElementById('navCountDa'),
    is: document.getElementById('navCountIs'),
    qual: document.getElementById('navCountQual'),
    mm: document.getElementById('navCountMm'),
    text: document.getElementById('navCountText'),
    tia: document.getElementById('navCountTia'),
    '360': document.getElementById('navCount360'),
  };

  function starKey(p) { return p.key + ':' + p.id; }

  SOURCES.forEach(function(src) {
    fetch(src.url, {credentials:'same-origin',headers:{Accept:'application/json'}})
      .then(function(r){ return r.ok?r.json():{}; })
      .catch(function(){ return {}; })
      .then(function(data) {
        var list = src.list(data)||[];
        list.forEach(function(p) {
          all.push({
            id:     p.id,
            key:    src.key,
            label:  src.label,
            accent: src.accent,
            icon:   src.icon,
            name:   src.name(p),
            href:   src.href(p),
            status: src.status(p),
            meta:   src.meta(p),
            ts:     src.ts(p)||'',
          });
        });
        pending--;
        if (pending === 0) onAllLoaded();
      });
  });

  function onAllLoaded() {
    updateCounts();
    render();
  }

  function updateCounts() {
    var counts = {survey:0,da:0,is:0,qual:0,mm:0,text:0,tia:0,'360':0};
    all.forEach(function(p){ if(counts[p.key]!==undefined) counts[p.key]++; });
    counts.all = all.length;
    Object.keys(navCounts).forEach(function(k){ if(navCounts[k]) navCounts[k].textContent = counts[k]||0; });
  }

  function render() {
    var list = all.filter(function(p) {
      if (activeFilter !== 'all' && p.key !== activeFilter) return false;
      if (activeStatus !== 'all' && p.status !== activeStatus) return false;
      if (searchTerm && p.name.toLowerCase().indexOf(searchTerm) === -1) return false;
      return true;
    });

    if (activeSort === 'updated') {
      list.sort(function(a,b){ return (b.ts?Date.parse(b.ts):0)-(a.ts?Date.parse(a.ts):0); });
    } else if (activeSort === 'name') {
      list.sort(function(a,b){ return a.name.localeCompare(b.name); });
    }

    var body = document.getElementById('prjBody');
    if (!list.length) {
      body.innerHTML = '<tr><td colspan="7"><div class="prj-state">'
        + '<div class="prj-state-title">No projects found</div>'
        + '<div class="prj-state-sub">Start a new project from the "New project" button above.</div>'
        + '</div></td></tr>';
      return;
    }

    body.innerHTML = list.map(function(p) {
      var sk   = starKey(p);
      var on   = starred[sk] ? ' on' : '';
      var statusClass = (p.status === 'active' || p.status === 'live') ? 'prj-status-active' : 'prj-status-draft';
      var statusLabel = (p.status === 'active' || p.status === 'live') ? 'Active' : (p.status||'Draft');
      statusLabel = statusLabel.charAt(0).toUpperCase() + statusLabel.slice(1);
      var when = fmtDate(p.ts);
      return '<tr>'
        + '<td style="width:32px;padding:0 8px">'
        +   '<button class="prj-star'+on+'" data-sk="'+esc(sk)+'" title="Star">'
        +     '<svg viewBox="0 0 15 15" fill="'+(on?' #F59E0B':'none')+'" stroke="'+(on?'#F59E0B':'currentColor')+'" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round"><path d="M7.5 1l1.8 3.7L13.5 5.4l-3 2.9.7 4.1L7.5 10.3 3.8 12.4l.7-4.1-3-2.9 4.2-.7z"/></svg>'
        +   '</button>'
        + '</td>'
        + '<td style="width:36px;padding:8px 4px">'
        +   '<img class="prj-icon" src="'+esc(p.icon)+'" alt="">'
        + '</td>'
        + '<td class="prj-name-cell">'
        +   '<a class="prj-name-link" href="'+esc(p.href)+'">'+esc(p.name)+'</a>'
        + '</td>'
        + '<td>'
        +   '<span class="prj-studio-badge">'
        +     '<span class="prj-badge-dot" style="background:'+p.accent+'"></span>'
        +     esc(p.label)
        +   '</span>'
        + '</td>'
        + '<td><span class="prj-status '+statusClass+'">'+esc(statusLabel)+'</span></td>'
        + '<td class="prj-meta">'+esc(p.meta)+'</td>'
        + '<td class="prj-meta">'+esc(when)+'</td>'
        + '</tr>';
    }).join('');

    body.querySelectorAll('.prj-star').forEach(function(btn) {
      btn.addEventListener('click', function(e) {
        e.preventDefault(); e.stopPropagation();
        var sk = btn.dataset.sk;
        if (starred[sk]) { delete starred[sk]; } else { starred[sk] = 1; }
        localStorage.setItem('prj-starred', JSON.stringify(starred));
        render();
      });
    });
  }

  /* ── Sidebar nav ── */
  var navTitles = {
    all:'All Projects', survey:'Survey Development', da:'Descriptive Studio',
    is:'Inferential Studio', qual:'Qual Studio', mm:'MM Studio',
    text:'Text Analyzer', tia:'TIA Studio', '360':'360 Studio',
  };
  document.querySelectorAll('.prj-nav-item').forEach(function(btn) {
    btn.addEventListener('click', function() {
      document.querySelectorAll('.prj-nav-item').forEach(function(b){ b.classList.remove('active'); });
      btn.classList.add('active');
      activeFilter = btn.dataset.filter;
      document.getElementById('toolbarTitle').textContent = navTitles[activeFilter]||'Projects';
      render();
    });
  });

  /* ── Toolbar controls ── */
  document.getElementById('statusFilter').addEventListener('change', function(e){ activeStatus = e.target.value; render(); });
  document.getElementById('sortSelect').addEventListener('change', function(e){ activeSort = e.target.value; render(); });
  document.getElementById('searchInput').addEventListener('input', function(e){ searchTerm = e.target.value.trim().toLowerCase(); render(); });

  /* ── New project dropdown ── */
  var createBtn  = document.getElementById('createBtn');
  var createMenu = document.getElementById('createMenu');
  createBtn.addEventListener('click', function(e) {
    e.stopPropagation();
    var open = createMenu.classList.toggle('open');
    createBtn.setAttribute('aria-expanded', String(open));
  });
  document.addEventListener('click', function() { createMenu.classList.remove('open'); createBtn.setAttribute('aria-expanded','false'); });
  document.addEventListener('keydown', function(e) {
    if (e.key==='Escape') { createMenu.classList.remove('open'); createBtn.setAttribute('aria-expanded','false'); }
  });

  /* ── Column sort headers ── */
  document.querySelectorAll('.prj-table th[data-col]').forEach(function(th) {
    th.addEventListener('click', function() {
      var col = th.dataset.col;
      if (col === 'name')    activeSort = 'name';
      if (col === 'updated') activeSort = 'updated';
      document.querySelectorAll('.prj-table th').forEach(function(t){ t.className=''; });
      th.className = 'sort-desc';
      render();
    });
  });

  /* ── Helpers ── */
  function esc(s) {
    return String(s==null?'':s).replace(/[&<>"']/g, function(c){
      return({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'})[c];
    });
  }
  function fmtDate(ts) {
    if (!ts) return '';
    var ms = Date.parse(ts); if (!ms) return '';
    var diff = Date.now()-ms, min = Math.floor(diff/60000);
    if (min < 2)  return 'Just now';
    if (min < 60) return min+'m ago';
    var h = Math.floor(min/60);
    if (h < 24)   return h+'h ago';
    var d = Math.floor(h/24);
    if (d < 7)    return d+'d ago';
    var dt = new Date(ms);
    return dt.toLocaleDateString(undefined,{month:'short',day:'numeric',year: dt.getFullYear()!==new Date().getFullYear()?'numeric':undefined});
  }
})();
</script>

<?php
$landing_tagline = '';
include __DIR__ . '/_landing_foot.php';
