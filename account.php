<?php
require_once __DIR__ . '/api/_db.php';
require_once __DIR__ . '/api/_session.php';
require_once __DIR__ . '/api/_tiers.php';

start_session_secure();
$uid = current_user_id();
if (!$uid) {
    header('Location: /login.html?return=' . urlencode('/account.php'));
    exit;
}
$user = current_user();
if (!$user) { $_SESSION = []; session_destroy(); header('Location: /login.html'); exit; }

$pdo = db();
$row = $pdo->prepare('SELECT id, email, name, created_at FROM users WHERE id = :id');
$row->execute([':id' => $uid]);
$u = $row->fetch();

$tierInfo = tier_for_user($uid);
$tier     = $tierInfo['tier'];
$catalog  = tier_catalog();
$tierDef  = $catalog[$tier];

$sub = null;
try {
    $s = $pdo->prepare("SELECT cycle, current_period_end, cancel_at_period_end
                          FROM subscriptions WHERE user_id = :uid
                          AND status IN ('active','trialing','past_due')
                          ORDER BY created_at DESC LIMIT 1");
    $s->execute([':uid' => $uid]);
    $sub = $s->fetch() ?: null;
} catch (\Throwable $e) {}

$user_full    = $u['name'] ?? $u['email'] ?? '';
$initials     = strtoupper(substr(preg_replace('/[^A-Za-z]/', '', $user_full) ?: 'U', 0, 2));
$member_since = $u['created_at'] ? date('F j, Y', strtotime($u['created_at'])) : '';

$feats = $tierDef['features'];
$lims  = $tierDef['limits'];
$max_r = $lims['max_responses_per_survey'];
$max_d = $lims['max_rows_per_dataset'];
$active_chips = array_values(array_filter([
    ['label' => $max_r >= PHP_INT_MAX ? 'Unlimited responses' : number_format($max_r) . ' responses / survey'],
    ['label' => $max_d >= PHP_INT_MAX ? 'Unlimited dataset rows' : number_format($max_d) . ' rows / dataset'],
    $feats['skip_logic']       ? ['label' => 'Skip logic']       : null,
    $feats['anonymous_mode']   ? ['label' => 'Anonymous mode']   : null,
    $feats['group_rollups']    ? ['label' => 'Group rollups']    : null,
    $feats['team_sharing']     ? ['label' => 'Team sharing (' . $feats['team_sharing'] . ')'] : null,
    $feats['priority_support'] ? ['label' => 'Priority support'] : null,
    $feats['remove_branding']  ? ['label' => 'Remove branding']  : null,
    $feats['api_access']       ? ['label' => 'API access']       : null,
    $feats['custom_domain']    ? ['label' => 'Custom domain']    : null,
]));

$studios_shown = [
    ['img' => '/SIRI.png',                   'name' => 'Survey Development', 'type' => 'App'],
    ['img' => '/rssi-icon.png',              'name' => 'RSSI',               'type' => 'App'],
    ['img' => '/Qualitative%20Analysis.png', 'name' => 'Qual Studio',        'type' => 'Research Studio'],
    ['img' => '/MM%20Studio.png',            'name' => 'MM Studio',          'type' => 'Research Studio'],
    ['img' => '/TIA%20Studio.png',           'name' => 'TIA Studio',         'type' => 'Assessment Studio'],
    ['img' => '/360%20Studio.png',           'name' => '360 Studio',         'type' => 'Assessment Studio'],
];

$landing_title         = 'My Account — ReliCheck';
$landing_user_initials = $initials;
$landing_user_full     = $user_full;
$landing_show_back     = true;

include __DIR__ . '/_landing_head.php';
?>
<style>
/* ── Layout ── */
.ac {
  max-width: 1100px;
  margin: 0 auto;
  padding: 52px 32px 100px;
}
@media (max-width: 700px) { .ac { padding: 32px 16px 72px; } }

/* ── Hero ── */
.ac-hero {
  display: flex; align-items: center; gap: 22px;
  padding-bottom: 40px;
  border-bottom: 1px solid var(--hairline);
  margin-bottom: 24px;
}
.ac-avatar {
  width: 68px; height: 68px; border-radius: 50%; flex: none;
  background: var(--accent-soft); color: var(--accent-deep);
  font-size: 22px; font-weight: 800;
  display: grid; place-items: center;
  border: 2px solid rgba(10,111,232,.10);
}
.ac-hero-text { flex: 1; min-width: 0; }
.ac-tier-pill {
  display: inline-block; padding: 3px 10px; border-radius: var(--radius-pill);
  background: var(--accent-soft); color: var(--accent-deep);
  font-size: 10.5px; font-weight: 700; letter-spacing: .08em; text-transform: uppercase;
  margin-bottom: 7px;
}
.ac-name {
  font-size: 26px; font-weight: 800; letter-spacing: -.03em;
  color: var(--text); line-height: 1.1; margin-bottom: 4px;
}
.ac-since { font-size: 13px; color: var(--text-3); }
.ac-upgrade-cta {
  flex: none; padding: 10px 22px; border-radius: var(--radius-pill);
  background: var(--accent); color: #fff;
  font-size: 13.5px; font-weight: 700; text-decoration: none; transition: opacity .15s;
}
.ac-upgrade-cta:hover { opacity: .85; }

/* ── Panel grid rows ── */
.ac-row-2 {
  display: grid; grid-template-columns: 1fr 1fr; gap: 12px;
  margin-bottom: 12px;
}
.ac-row-3 {
  display: grid; grid-template-columns: 3fr 2fr; gap: 12px;
  margin-bottom: 12px;
}
@media (max-width: 780px) {
  .ac-row-2, .ac-row-3 { grid-template-columns: 1fr; }
}

/* ── Card shell ── */
.ac-card {
  background: var(--surface);
  border: 1px solid var(--hairline-2);
  border-radius: var(--radius-card);
  overflow: hidden;
  box-shadow: var(--shadow-sm);
}
.ac-card-mb { margin-bottom: 12px; }

/* ── Card header ── */
.ac-card-top {
  display: flex; align-items: center; justify-content: space-between;
  padding: 22px 26px 0;
}
.ac-section-label {
  font-size: 10px; font-weight: 800; letter-spacing: .14em; text-transform: uppercase;
  color: var(--text-3);
}
.ac-manage {
  font-size: 13px; font-weight: 600; color: var(--accent-deep); text-decoration: none;
}
.ac-manage:hover { text-decoration: underline; }

/* ── Plan panel ── */
.ac-plan-block { padding: 12px 26px 26px; }
.ac-plan-name {
  font-size: 46px; font-weight: 800; letter-spacing: -.04em;
  color: var(--text); line-height: 1; margin-bottom: 6px;
}
.ac-plan-price { font-size: 14px; color: var(--text-2); }
.ac-plan-renew { font-size: 12.5px; color: var(--text-3); margin-top: 4px; }
.ac-plan-renew strong { color: var(--text-2); font-weight: 600; }

/* ── Divider ── */
.ac-rule { height: 1px; background: var(--hairline); margin: 0 26px; }

/* ── Features panel: 2-col checklist ── */
.ac-feat-list {
  display: grid; grid-template-columns: 1fr 1fr; gap: 0;
  padding: 18px 26px 24px;
}
.ac-feat {
  display: flex; align-items: center; gap: 9px;
  padding: 8px 0;
  font-size: 13.5px; font-weight: 500; color: var(--text);
  border-bottom: 1px solid var(--hairline);
}
.ac-feat:nth-last-child(-n+2) { border-bottom: none; }
.ac-feat-check {
  width: 18px; height: 18px; border-radius: 50%; flex: none;
  background: var(--accent-soft); color: var(--accent-deep);
  display: grid; place-items: center;
}
.ac-feat-check svg { width: 10px; height: 10px; }

/* ── Studios shelf (6-across) ── */
.ac-shelf-label {
  padding: 0 26px 10px;
  font-size: 10px; font-weight: 800; letter-spacing: .14em; text-transform: uppercase;
  color: var(--text-3);
}
.ac-shelf {
  display: grid; grid-template-columns: repeat(6, 1fr);
  border-top: 1px solid var(--hairline);
}
@media (max-width: 780px) { .ac-shelf { grid-template-columns: repeat(3, 1fr); } }
@media (max-width: 480px) { .ac-shelf { grid-template-columns: repeat(2, 1fr); } }
.ac-studio {
  display: flex; flex-direction: column; align-items: center; gap: 8px;
  padding: 18px 12px;
  border-right: 1px solid var(--hairline);
  text-align: center;
}
.ac-studio:last-child { border-right: none; }
@media (max-width: 780px) {
  .ac-studio:nth-child(3n) { border-right: none; }
  .ac-studio:nth-child(n+4) { border-top: 1px solid var(--hairline); }
  .ac-studio:not(:nth-child(3n)) { border-right: 1px solid var(--hairline); }
}
.ac-studio img { width: 40px; height: 40px; border-radius: 10px; object-fit: contain; }
.ac-studio-name { font-size: 12px; font-weight: 700; color: var(--text); line-height: 1.25; }
.ac-studio-type { font-size: 10.5px; color: var(--text-3); }

/* ── Field rows ── */
.ac-field {
  display: flex; align-items: flex-start; justify-content: space-between;
  padding: 18px 28px;
  border-bottom: 1px solid var(--hairline);
  gap: 20px;
}
.ac-field:last-child { border-bottom: none; }
.ac-field-body { flex: 1; min-width: 0; }
.ac-field-label {
  font-size: 10.5px; font-weight: 700; letter-spacing: .07em; text-transform: uppercase;
  color: var(--text-3); margin-bottom: 3px;
}
.ac-field-val  { font-size: 15px; color: var(--text); font-weight: 500; }
.ac-field-sub  { font-size: 13px; color: var(--text-2); }

/* ── Edit button ── */
.ac-edit {
  flex: none; padding: 7px 16px; border-radius: 10px;
  background: var(--surface-2); border: 1px solid var(--hairline-2);
  font-size: 13px; font-weight: 600; color: var(--text-2);
  cursor: pointer; white-space: nowrap;
  transition: background .12s, border-color .12s;
}
.ac-edit:hover { background: var(--accent-soft); border-color: var(--accent-soft); color: var(--accent-deep); }

/* ── Inline edit ── */
.ac-inline { display: none; margin-top: 10px; }
.ac-inline.open { display: flex; gap: 8px; align-items: center; flex-wrap: wrap; }
.ac-inp {
  flex: 1; min-width: 180px; padding: 9px 13px;
  border: 1.5px solid var(--hairline-2); border-radius: 11px;
  font-size: 14px; font-family: inherit; color: var(--text);
  background: var(--surface); outline: none; transition: border-color .13s;
}
.ac-inp:focus { border-color: var(--accent); }
.ac-save {
  padding: 9px 20px; border-radius: 11px;
  background: var(--accent); color: #fff;
  font-size: 13px; font-weight: 700; border: none; cursor: pointer; transition: opacity .13s;
}
.ac-save:hover { opacity: .87; }
.ac-cancel {
  padding: 9px 14px; border-radius: 11px;
  background: none; border: 1px solid var(--hairline-2);
  font-size: 13px; font-weight: 600; color: var(--text-2); cursor: pointer;
}
.ac-cancel:hover { background: var(--bg); }

/* ── Password form ── */
.ac-pw { display: none; margin-top: 14px; }
.ac-pw.open { display: block; }
.ac-pw-field { margin-bottom: 10px; }
.ac-pw-field label {
  display: block; font-size: 10.5px; font-weight: 700; letter-spacing: .06em;
  text-transform: uppercase; color: var(--text-3); margin-bottom: 5px;
}
.ac-pw-field input {
  width: 100%; padding: 10px 13px;
  border: 1.5px solid var(--hairline-2); border-radius: 11px;
  font-size: 14px; font-family: inherit; color: var(--text);
  background: var(--surface); outline: none; transition: border-color .13s;
  box-sizing: border-box;
}
.ac-pw-field input:focus { border-color: var(--accent); }
.ac-pw-row { display: flex; gap: 8px; margin-top: 14px; }
.ac-pw-save {
  padding: 10px 22px; border-radius: 11px;
  background: var(--accent); color: #fff;
  font-size: 13.5px; font-weight: 700; border: none; cursor: pointer; transition: opacity .13s;
}
.ac-pw-save:hover { opacity: .87; }
.ac-pw-cancel {
  padding: 10px 16px; border-radius: 11px;
  background: none; border: 1px solid var(--hairline-2);
  font-size: 13px; font-weight: 600; color: var(--text-2); cursor: pointer;
}
.ac-pw-cancel:hover { background: var(--bg); }

/* ── Status messages ── */
.ac-msg { font-size: 13px; font-weight: 500; padding: 9px 13px; border-radius: 9px; margin-top: 8px; display: none; }
.ac-msg.ok  { background: var(--accent-soft); color: var(--accent-deep); display: block; }
.ac-msg.err { background: #FEF2F2; color: #991B1B; display: block; }

/* ── Contact strip (3-col horizontal) ── */
.ac-contact {
  display: grid; grid-template-columns: repeat(3, 1fr);
}
@media (max-width: 600px) { .ac-contact { grid-template-columns: 1fr; } }
.ac-contact-cell {
  padding: 20px 26px;
  border-right: 1px solid var(--hairline);
}
.ac-contact-cell:last-child { border-right: none; }
@media (max-width: 600px) {
  .ac-contact-cell { border-right: none; border-bottom: 1px solid var(--hairline); }
  .ac-contact-cell:last-child { border-bottom: none; }
}
.ac-contact-who {
  font-size: 10px; font-weight: 800; letter-spacing: .12em; text-transform: uppercase;
  color: var(--text-3); margin-bottom: 5px;
}
.ac-contact-link {
  font-size: 13.5px; font-weight: 600; color: var(--accent-deep);
  text-decoration: none; white-space: nowrap;
}
.ac-contact-link:hover { text-decoration: underline; }
</style>

<div class="ac">

  <!-- Hero -->
  <div class="ac-hero">
    <div class="ac-avatar"><?= htmlspecialchars($initials) ?></div>
    <div class="ac-hero-text">
      <div class="ac-tier-pill"><?= htmlspecialchars($tierDef['name']) ?></div>
      <div class="ac-name"><?= htmlspecialchars($user_full) ?></div>
      <?php if ($member_since): ?>
        <div class="ac-since">Member since <?= $member_since ?></div>
      <?php endif; ?>
    </div>
    <?php if ($tier === 'free'): ?>
      <a href="/app-2026v4.php" class="ac-upgrade-cta">Upgrade</a>
    <?php endif; ?>
  </div>

  <!-- Row 1: Plan | Features -->
  <div class="ac-row-2">

    <!-- Plan panel -->
    <div class="ac-card">
      <div class="ac-card-top">
        <span class="ac-section-label">Plan</span>
        <?php if ($tier !== 'free'): ?>
          <a href="#" class="ac-manage">Manage billing &rarr;</a>
        <?php endif; ?>
      </div>
      <div class="ac-plan-block">
        <div class="ac-plan-name"><?= htmlspecialchars($tierDef['name']) ?></div>
        <div class="ac-plan-price">
          <?php if ($tier === 'free'): ?>
            Free forever
          <?php elseif ($sub && $sub['cycle'] === 'annual'): ?>
            $<?= number_format($tierDef['price_annual_cents'] / 100, 0) ?> / year &middot; billed annually
          <?php else: ?>
            $<?= number_format($tierDef['price_monthly_cents'] / 100, 0) ?> / month
          <?php endif; ?>
        </div>
        <?php if ($sub && !empty($sub['current_period_end'])): ?>
          <div class="ac-plan-renew">
            <?= $sub['cancel_at_period_end'] ? 'Cancels' : 'Renews' ?> on
            <strong><?= date('F j, Y', strtotime($sub['current_period_end'])) ?></strong>
          </div>
        <?php endif; ?>
      </div>
    </div>

    <!-- Features panel -->
    <div class="ac-card">
      <div class="ac-card-top" style="padding-bottom:4px">
        <span class="ac-section-label">What&rsquo;s included</span>
      </div>
      <div class="ac-feat-list">
        <?php foreach ($active_chips as $chip): ?>
          <div class="ac-feat">
            <span class="ac-feat-check">
              <svg viewBox="0 0 10 10" fill="none">
                <path d="M1.5 5l2.5 2.5 4.5-4" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"/>
              </svg>
            </span>
            <?= htmlspecialchars($chip['label']) ?>
          </div>
        <?php endforeach; ?>
      </div>
    </div>

  </div>

  <!-- Row 2: Studios shelf (full width) -->
  <div class="ac-card ac-card-mb">
    <div class="ac-card-top" style="padding-bottom:14px">
      <span class="ac-section-label">Studios &amp; Apps</span>
    </div>
    <div class="ac-shelf">
      <?php foreach ($studios_shown as $st): ?>
        <div class="ac-studio">
          <img src="<?= htmlspecialchars($st['img']) ?>" alt="<?= htmlspecialchars($st['name']) ?>">
          <div class="ac-studio-name"><?= htmlspecialchars($st['name']) ?></div>
          <div class="ac-studio-type"><?= htmlspecialchars($st['type']) ?></div>
        </div>
      <?php endforeach; ?>
    </div>
  </div>

  <!-- Row 3: Profile | Security -->
  <div class="ac-row-3">

    <!-- Profile -->
    <div class="ac-card">
      <div class="ac-card-top" style="padding-bottom:14px">
        <span class="ac-section-label">Profile</span>
      </div>
      <div class="ac-field">
        <div class="ac-field-body">
          <div class="ac-field-label">Name</div>
          <div class="ac-field-val" id="dispName"><?= htmlspecialchars($u['name'] ?? '') ?></div>
          <div class="ac-inline" id="formName">
            <input class="ac-inp" id="inpName" type="text" value="<?= htmlspecialchars($u['name'] ?? '') ?>" maxlength="120" autocomplete="name">
            <button class="ac-save" onclick="saveName()">Save</button>
            <button class="ac-cancel" onclick="closeEdit('Name')">Cancel</button>
          </div>
          <div class="ac-msg" id="msgName"></div>
        </div>
        <button class="ac-edit" id="btnName" onclick="openEdit('Name')">Edit</button>
      </div>
      <div class="ac-field">
        <div class="ac-field-body">
          <div class="ac-field-label">Email</div>
          <div class="ac-field-val" id="dispEmail"><?= htmlspecialchars($u['email'] ?? '') ?></div>
          <div class="ac-inline" id="formEmail">
            <input class="ac-inp" id="inpEmail" type="email" value="<?= htmlspecialchars($u['email'] ?? '') ?>" maxlength="255" autocomplete="email">
            <button class="ac-save" onclick="saveEmail()">Save</button>
            <button class="ac-cancel" onclick="closeEdit('Email')">Cancel</button>
          </div>
          <div class="ac-msg" id="msgEmail"></div>
        </div>
        <button class="ac-edit" id="btnEmail" onclick="openEdit('Email')">Edit</button>
      </div>
      <?php if ($member_since): ?>
      <div class="ac-field">
        <div class="ac-field-body">
          <div class="ac-field-label">Member since</div>
          <div class="ac-field-val"><?= $member_since ?></div>
        </div>
      </div>
      <?php endif; ?>
    </div>

    <!-- Security + Contact stacked -->
    <div style="display:flex;flex-direction:column;gap:12px">

      <div class="ac-card">
        <div class="ac-card-top" style="padding-bottom:14px">
          <span class="ac-section-label">Security</span>
        </div>
        <div class="ac-field" style="border-bottom:none">
          <div class="ac-field-body">
            <div class="ac-field-label">Password</div>
            <div class="ac-field-sub">Keep your account secure.</div>
            <div class="ac-pw" id="pwForm">
              <div class="ac-pw-field" style="margin-top:12px">
                <label for="pwCurrent">Current password</label>
                <input type="password" id="pwCurrent" autocomplete="current-password">
              </div>
              <div class="ac-pw-field">
                <label for="pwNew">New password</label>
                <input type="password" id="pwNew" autocomplete="new-password" placeholder="8+ characters">
              </div>
              <div class="ac-pw-field">
                <label for="pwConfirm">Confirm new password</label>
                <input type="password" id="pwConfirm" autocomplete="new-password">
              </div>
              <div class="ac-msg" id="msgPw"></div>
              <div class="ac-pw-row">
                <button class="ac-pw-save" onclick="savePassword()">Update password</button>
                <button class="ac-pw-cancel" onclick="closePw()">Cancel</button>
              </div>
            </div>
          </div>
          <button class="ac-edit" id="btnPw" onclick="openPw()">Change</button>
        </div>
      </div>

    </div>
  </div>

  <!-- Contact (full-width) -->
  <div class="ac-card">
    <div class="ac-card-top" style="padding-bottom:16px">
      <span class="ac-section-label">Get in touch</span>
    </div>
    <div class="ac-rule"></div>
    <div class="ac-contact">
      <div class="ac-contact-cell">
        <div class="ac-contact-who">Sales</div>
        <a class="ac-contact-link" href="mailto:sales@relichecksurvey.com">sales@relichecksurvey.com</a>
      </div>
      <div class="ac-contact-cell">
        <div class="ac-contact-who">Membership</div>
        <a class="ac-contact-link" href="mailto:membership@relichecksurvey.com">membership@relichecksurvey.com</a>
      </div>
      <div class="ac-contact-cell">
        <div class="ac-contact-who">Customer Service</div>
        <a class="ac-contact-link" href="mailto:service@relichecksurvey.com">service@relichecksurvey.com</a>
      </div>
    </div>
  </div>

</div>

<script>
function openEdit(f) {
  document.getElementById('form'+f).classList.add('open');
  document.getElementById('btn'+f).style.display = 'none';
  document.getElementById('inp'+f).focus();
}
function closeEdit(f) {
  document.getElementById('form'+f).classList.remove('open');
  document.getElementById('btn'+f).style.display = '';
  clrMsg('msg'+f);
}
function setMsg(id, type, text) {
  var el = document.getElementById(id);
  el.textContent = text; el.className = 'ac-msg ' + type;
}
function clrMsg(id) { var el = document.getElementById(id); el.textContent=''; el.className='ac-msg'; }

function saveName() {
  var name = document.getElementById('inpName').value.trim();
  if (!name) { setMsg('msgName','err','Name cannot be empty.'); return; }
  patch({name: name}, function(d){ document.getElementById('dispName').textContent = d.user.name; closeEdit('Name'); },
    function(m){ setMsg('msgName','err',m); });
}
function saveEmail() {
  var email = document.getElementById('inpEmail').value.trim();
  if (!email) { setMsg('msgEmail','err','Email cannot be empty.'); return; }
  patch({email: email}, function(d){ document.getElementById('dispEmail').textContent = d.user.email; closeEdit('Email'); },
    function(m){ setMsg('msgEmail','err',m); });
}
function patch(body, onOk, onErr) {
  fetch('/api/account/profile.php', {
    method:'PATCH', credentials:'same-origin',
    headers:{'Content-Type':'application/json','X-Requested-With':'XMLHttpRequest'},
    body: JSON.stringify(body)
  }).then(function(r){ return r.json().then(function(d){ return {ok:r.ok,d:d}; }); })
    .then(function(res){ if(res.ok && res.d.ok) onOk(res.d); else onErr(res.d.message||'Something went wrong.'); })
    .catch(function(){ onErr('Network error.'); });
}

function openPw() {
  document.getElementById('pwForm').classList.add('open');
  document.getElementById('btnPw').style.display = 'none';
  document.getElementById('pwCurrent').focus();
}
function closePw() {
  document.getElementById('pwForm').classList.remove('open');
  document.getElementById('btnPw').style.display = '';
  ['pwCurrent','pwNew','pwConfirm'].forEach(function(id){ document.getElementById(id).value = ''; });
  clrMsg('msgPw');
}
function savePassword() {
  var cur = document.getElementById('pwCurrent').value;
  var nw  = document.getElementById('pwNew').value;
  var cf  = document.getElementById('pwConfirm').value;
  if (!cur)          { setMsg('msgPw','err','Enter your current password.'); return; }
  if (nw.length < 8) { setMsg('msgPw','err','New password must be at least 8 characters.'); return; }
  if (nw !== cf)     { setMsg('msgPw','err','Passwords do not match.'); return; }
  fetch('/api/account/change_password.php', {
    method:'POST', credentials:'same-origin',
    headers:{'Content-Type':'application/json','X-Requested-With':'XMLHttpRequest'},
    body: JSON.stringify({current_password:cur, new_password:nw})
  }).then(function(r){ return r.json().then(function(d){ return {ok:r.ok,d:d}; }); })
    .then(function(res){
      if(res.ok && res.d.ok){ setMsg('msgPw','ok','Password updated.'); setTimeout(closePw,1800); }
      else setMsg('msgPw','err', res.d.message||'Could not update password.');
    }).catch(function(){ setMsg('msgPw','err','Network error.'); });
}
</script>

<?php
$landing_tagline = '';
include __DIR__ . '/_landing_foot.php';
