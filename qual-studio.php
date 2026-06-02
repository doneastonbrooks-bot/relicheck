<?php
// Qualitative Analysis Studio — landing page
require_once __DIR__ . '/api/_db.php';
require_once __DIR__ . '/api/_session.php';
require_once __DIR__ . '/api/_qual_studio.php';

start_session_secure();
$uid = current_user_id();
if (!$uid) {
    $qs = !empty($_SERVER['QUERY_STRING']) ? '?' . $_SERVER['QUERY_STRING'] : '';
    header('Location: /login.html?return=' . urlencode('/qual-studio.php' . $qs));
    exit;
}
$user = current_user();
if (!$user) { $_SESSION = []; session_destroy(); header('Location: /login.html'); exit; }

$pdo = db();
qual_ensure_schema($pdo);

// Recent projects for the "Open saved" list
$recent = $pdo->prepare(
    "SELECT p.id, p.title, p.analysis_approach, p.updated_at,
            (SELECT COUNT(*) FROM qual_segments s WHERE s.project_id=p.id AND s.status='active') AS seg_count,
            (SELECT COUNT(*) FROM qual_codes   c WHERE c.project_id=p.id AND c.status <> 'retired') AS code_count
     FROM qual_projects p WHERE p.user_id=:u AND p.status='active'
     ORDER BY p.updated_at DESC LIMIT 5"
);
$recent->execute([':u' => $uid]);
$recentProjects = $recent->fetchAll(PDO::FETCH_ASSOC);

$user_full = $user['name'] ?? $user['email'] ?? 'You';
$initials  = strtoupper(substr(preg_replace('/[^A-Za-z]/', '', $user_full) ?: 'U', 0, 2));

$landing_title         = 'Qualitative Analysis Studio — ReliCheck';
$landing_accent        = '#1e5c3a';
$landing_accent_deep   = '#174d30';
$landing_accent_soft   = '#e8f5ee';
$landing_logo          = '/Qual%20Studio.png';
$landing_logo_name     = 'Qualitative Analysis Studio';
$landing_pill_label    = 'In development';
$landing_show_back     = true;
$landing_user_initials = $initials;
$landing_user_full     = $user_full;
include __DIR__ . '/_landing_head.php';
?>
<link rel="stylesheet" href="/studio-landing.css">
<style>
  .qs-grid { display:grid; grid-template-columns:1fr 1fr 1fr; gap:24px; margin-top:48px; }
  @media(max-width:900px){ .qs-grid{grid-template-columns:1fr;} }
  .qs-card {
    background:#fff; border:1.5px solid #e5e7eb; border-radius:18px;
    padding:32px 28px; display:flex; flex-direction:column; gap:16px;
    transition:border-color .18s, box-shadow .18s;
  }
  .qs-card:hover { border-color:var(--accent); box-shadow:0 4px 24px rgba(30,92,58,.10); }
  .qs-card-icon {
    width:48px; height:48px; border-radius:14px;
    background:var(--accent-soft); display:grid; place-items:center;
    color:var(--accent); flex:none;
  }
  .qs-card-icon svg { width:24px; height:24px; }
  .qs-card h3 { font-size:20px; font-weight:800; color:#1d1d1f; margin:0; letter-spacing:-.02em; }
  .qs-card p  { font-size:15px; color:#6e6e73; margin:0; line-height:1.6; flex:1; }
  .qs-card-btn {
    display:inline-flex; align-items:center; gap:8px;
    background:var(--accent); color:#fff;
    font-size:15px; font-weight:700; padding:12px 24px;
    border-radius:999px; text-decoration:none; border:none; cursor:pointer;
    transition:opacity .15s; align-self:flex-start;
  }
  .qs-card-btn:hover { opacity:.88; }
  .qs-card-btn.outline {
    background:transparent; color:var(--accent);
    border:1.5px solid var(--accent);
  }
  .qs-recent { margin-top:56px; }
  .qs-recent-h { font-size:13px; font-weight:800; letter-spacing:.08em; text-transform:uppercase; color:#86868b; margin-bottom:16px; }
  .qs-proj-list { display:flex; flex-direction:column; gap:10px; }
  .qs-proj-row {
    display:flex; align-items:center; gap:16px; padding:14px 18px;
    background:#fff; border:1.5px solid #e5e7eb; border-radius:12px;
    text-decoration:none; color:inherit; transition:border-color .15s;
  }
  .qs-proj-row:hover { border-color:var(--accent); }
  .qs-proj-dot { width:9px; height:9px; border-radius:50%; background:var(--accent); flex:none; }
  .qs-proj-name { font-size:15px; font-weight:700; color:#1d1d1f; flex:1; }
  .qs-proj-meta { font-size:13px; color:#86868b; white-space:nowrap; }
  .qs-approach-chip {
    font-size:11px; font-weight:700; letter-spacing:.05em; text-transform:uppercase;
    padding:3px 10px; border-radius:999px;
    background:var(--accent-soft); color:var(--accent);
  }
  .qs-philosophy {
    background:color-mix(in srgb, var(--accent) 5%, white);
    border-left:4px solid var(--accent);
    border-radius:0 12px 12px 0;
    padding:20px 24px; margin-top:48px;
    font-size:16px; font-style:italic; color:#374151; line-height:1.7;
  }
  .qs-philosophy strong { font-style:normal; color:var(--accent); }
  .qs-modal-overlay {
    display:none; position:fixed; inset:0; background:rgba(0,0,0,.45);
    z-index:200; align-items:center; justify-content:center;
  }
  .qs-modal-overlay.open { display:flex; }
  .qs-modal {
    background:#fff; border-radius:20px; padding:36px 32px; width:100%; max-width:560px;
    box-shadow:0 20px 60px rgba(0,0,0,.18); position:relative;
  }
  .qs-modal h2 { font-size:22px; font-weight:800; margin:0 0 20px; color:#1d1d1f; }
  .qs-field { margin-bottom:16px; }
  .qs-field label { display:block; font-size:13px; font-weight:700; color:#374151; margin-bottom:6px; }
  .qs-field input, .qs-field textarea, .qs-field select {
    width:100%; box-sizing:border-box; padding:10px 14px; font-size:15px;
    border:1.5px solid #d1d5db; border-radius:10px; outline:none; font-family:inherit;
    transition:border-color .15s;
  }
  .qs-field input:focus, .qs-field textarea:focus, .qs-field select:focus { border-color:var(--accent); }
  .qs-field textarea { resize:vertical; min-height:80px; }
  .qs-modal-close {
    position:absolute; top:16px; right:18px; background:none; border:none;
    font-size:22px; color:#9ca3af; cursor:pointer; line-height:1;
  }
  .qs-modal-close:hover { color:#374151; }
  .qs-modal-actions { display:flex; gap:12px; justify-content:flex-end; margin-top:24px; }
  .qs-modal-actions .cancel { background:#f3f4f6; color:#374151; border:none; }
</style>

<!-- New Project Modal -->
<div class="qs-modal-overlay" id="newProjectModal">
  <div class="qs-modal">
    <button class="qs-modal-close" onclick="document.getElementById('newProjectModal').classList.remove('open')">&times;</button>
    <h2>New Qualitative Project</h2>
    <div class="qs-field">
      <label>Project title <span style="color:#c0392b">*</span></label>
      <input type="text" id="np-title" placeholder="e.g. Staff Experience Open-Ends 2026">
    </div>
    <div class="qs-field">
      <label>Research question</label>
      <input type="text" id="np-rq" placeholder="What are participants saying about...">
    </div>
    <div class="qs-field">
      <label>Analysis approach</label>
      <select id="np-approach">
        <option value="thematic">Thematic Analysis</option>
        <option value="content">Content Analysis</option>
        <option value="framework">Framework Analysis</option>
        <option value="open_ended_survey">Open-Ended Survey Analysis</option>
        <option value="document">Document Analysis</option>
        <option value="grounded_theory" disabled>Grounded Theory (coming soon)</option>
        <option value="narrative" disabled>Narrative Analysis (coming soon)</option>
      </select>
    </div>
    <div class="qs-field">
      <label>Researcher stance memo <span style="color:#9ca3af;font-weight:400">(optional)</span></label>
      <textarea id="np-stance" placeholder="What assumptions, roles, or experiences might shape how you interpret this data?"></textarea>
    </div>
    <div id="np-error" style="color:#c0392b;font-size:14px;display:none;margin-top:8px;"></div>
    <div class="qs-modal-actions">
      <button class="qs-card-btn cancel" onclick="document.getElementById('newProjectModal').classList.remove('open')">Cancel</button>
      <button class="qs-card-btn" id="np-submit" onclick="submitNewProject()">Create project</button>
    </div>
  </div>
</div>

<section class="sl-hero" style="min-height:70vh;">
  <h1 class="sl-h1 rv rv-d1" style="max-width:22ch;">
    <span class="thin">Turn words into</span><br>evidence.
  </h1>
  <p class="sl-body rv rv-d2">
    Qualitative analysis is not about finding frequent words. ReliCheck helps you examine language patterns, concepts, speaker intent, and evidence strength so your findings can be explained and defended.
  </p>
  <div class="sl-actions rv rv-d3">
    <button class="sl-btn-a" onclick="document.getElementById('newProjectModal').classList.add('open')">New project</button>
    <a href="#open-saved" class="sl-btn-b">Open saved project</a>
  </div>
</section>

<div style="background:#f5f5f7; padding:72px 0;">
  <div class="studio-landing-shell">

    <div class="qs-philosophy rv">
      <strong>Concepts before codes. Codes before themes. Evidence before claims.</strong><br>
      The Qualitative Analysis Studio is for researchers who need findings they can explain, defend, and act on — not an AI-generated list of topics.
    </div>

    <div class="qs-grid">
      <div class="qs-card rv rv-d1">
        <div class="qs-card-icon">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/><polyline points="10 9 9 9 8 9"/></svg>
        </div>
        <h3>Upload qualitative data</h3>
        <p>CSV, XLSX, or a ReliCheck survey project. The studio detects open-ended columns and brings each response in as a codeable unit.</p>
        <button class="qs-card-btn" onclick="document.getElementById('newProjectModal').classList.add('open')">New project</button>
      </div>

      <div class="qs-card rv rv-d2" id="open-saved">
        <div class="qs-card-icon">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z"/></svg>
        </div>
        <h3>Open saved project</h3>
        <p>Continue coding, reviewing, or building themes in a project you have already started.</p>
        <?php if (count($recentProjects) > 0): ?>
          <a href="/qual-studio-workspace.php?project_id=<?= $recentProjects[0]['id'] ?>" class="qs-card-btn outline">Continue last project</a>
        <?php else: ?>
          <span style="font-size:14px;color:#9ca3af;">No saved projects yet.</span>
        <?php endif; ?>
      </div>

      <div class="qs-card rv rv-d3">
        <div class="qs-card-icon">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
        </div>
        <h3>Start from a ReliCheck survey</h3>
        <p>Pull open-ended responses directly from an existing survey project. Participant metadata travels with every response.</p>
        <button class="qs-card-btn outline" onclick="document.getElementById('newProjectModal').classList.add('open')">Choose survey</button>
      </div>
    </div>

    <?php if (count($recentProjects) > 0): ?>
    <div class="qs-recent rv" style="margin-top:56px;">
      <div class="qs-recent-h">Recent projects</div>
      <div class="qs-proj-list">
        <?php foreach ($recentProjects as $p):
            $approachLabels = [
                'thematic'          => 'Thematic',
                'content'           => 'Content',
                'framework'         => 'Framework',
                'open_ended_survey' => 'Survey Analysis',
                'document'          => 'Document',
            ];
            $label = $approachLabels[$p['analysis_approach']] ?? ucfirst($p['analysis_approach']);
        ?>
        <a href="/qual-studio-workspace.php?project_id=<?= (int)$p['id'] ?>" class="qs-proj-row">
          <span class="qs-proj-dot"></span>
          <span class="qs-proj-name"><?= htmlspecialchars($p['title']) ?></span>
          <span class="qs-approach-chip"><?= htmlspecialchars($label) ?></span>
          <span class="qs-proj-meta">
            <?= (int)$p['seg_count'] ?> segments &middot; <?= (int)$p['code_count'] ?> codes
          </span>
          <span class="qs-proj-meta"><?= date('M j', strtotime($p['updated_at'])) ?></span>
        </a>
        <?php endforeach; ?>
      </div>
    </div>
    <?php endif; ?>

  </div>
</div>

<!-- What it builds -->
<div style="background:#fff; padding:72px 0;">
  <div class="studio-landing-shell">
    <div class="sl-feat-tag rv">The workflow</div>
    <h2 class="sl-feat-h rv rv-d1" style="margin-bottom:32px;"><span class="light">From raw text to</span><br>defensible findings.</h2>
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:20px;" class="rv rv-d2">
      <?php
      $steps = [
        ['Project Setup','Define your research question, approach, and researcher stance before any coding begins.'],
        ['Data Import','Upload CSV/XLSX or connect a ReliCheck survey. Open-text columns are detected automatically.'],
        ['Familiarization','Explore the corpus before coding: word counts, response length, early patterns.'],
        ['Coding Workspace','Code responses manually, with participant context alongside every segment.'],
        ['Codebook Builder','Build and maintain a full codebook with definitions, inclusion rules, and evidence links.'],
        ['Theme Builder','Develop interpretive themes backed by coded evidence, not just recurring words.'],
        ['Trustworthiness Review','Check credibility, dependability, confirmability, and reflexivity before reporting.'],
        ['Report Builder','Generate a basic, research, or executive report using only approved findings.'],
      ];
      foreach ($steps as $i => $s): ?>
      <div style="background:#f5f5f7;border-radius:14px;padding:22px 20px;">
        <div style="font-size:11px;font-weight:800;text-transform:uppercase;letter-spacing:.08em;color:var(--accent);margin-bottom:10px;"><?= $i+1 ?>. <?= $s[0] ?></div>
        <div style="font-size:14px;color:#374151;line-height:1.6;"><?= $s[1] ?></div>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
</div>

<?php
$landing_tagline = 'Concepts before codes. Codes before themes. Evidence before claims.';
include __DIR__ . '/_landing_foot.php';
?>

<script>
async function submitNewProject() {
  const title = document.getElementById('np-title').value.trim();
  const err   = document.getElementById('np-error');
  if (!title) { err.textContent = 'A project title is required.'; err.style.display = 'block'; return; }
  err.style.display = 'none';
  const btn = document.getElementById('np-submit');
  btn.disabled = true; btn.textContent = 'Creating...';
  try {
    const res = await fetch('/api/qual/create-project.php', {
      method:'POST', headers:{'Content-Type':'application/json'},
      body: JSON.stringify({
        title,
        research_question:     document.getElementById('np-rq').value.trim(),
        analysis_approach:     document.getElementById('np-approach').value,
        researcher_stance_memo:document.getElementById('np-stance').value.trim(),
      })
    });
    const data = await res.json();
    if (data.ok) {
      window.location.href = '/qual-studio-workspace.php?project_id=' + data.project_id;
    } else {
      err.textContent = data.message || 'Could not create project.';
      err.style.display = 'block';
      btn.disabled = false; btn.textContent = 'Create project';
    }
  } catch(e) {
    err.textContent = 'Network error. Please try again.';
    err.style.display = 'block';
    btn.disabled = false; btn.textContent = 'Create project';
  }
}

// Scroll reveal
const rv = document.querySelectorAll('.rv');
const io = new IntersectionObserver(es => es.forEach(e => { if(e.isIntersecting) e.target.classList.add('in'); }), {threshold:.08});
rv.forEach(el => io.observe(el));
</script>
