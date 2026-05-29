/* Node tests for the five Administration Readiness lens specs
   (apps/sdsi/administration-specs.js). Confirms each spec assembles through the
   shared ValidityLens factory, weights sum to 15, each sample scores
   deterministically, the four PROVISIONAL blockers fire under their conditions,
   clear under their context gates, stay orthogonal (never change the score), and
   that blocker scope per lens is as wired. These are SHELL specs — the
   vocabulary (and any warnings) will be revised one lens at a time. */
'use strict';

var A = require('./administration-specs.js');

var pass = 0, fail = 0;
function eq(label, got, want) {
  if (got === want) { pass++; }
  else { fail++; console.error('FAIL: ' + label + ' — got ' + JSON.stringify(got) + ', want ' + JSON.stringify(want)); }
}
function ok(label, cond) { eq(label, !!cond, true); }

// Sample flags carry no decision; settle them as accepted (mirrors the
// aggregator's seedFlags convention used by the validity/reliability tests).
function seed(arr) {
  return (arr || []).map(function (f) {
    var c = {}; for (var k in f) c[k] = f[k];
    if (c.decision == null) c.decision = 'accepted';
    if (c.blocker_reviewed == null) c.blocker_reviewed = false;
    return c;
  });
}
function assess(key, flags, context) {
  return A.LENSES[key].assess({ flags: seed(flags), context: context || {} });
}

/* ── Structure: five lenses, weights sum to 15. ── */
(function structure() {
  eq('five administration lenses', A.ORDER.length, 5);
  eq('order is as specified', A.ORDER.join(','),
     'respondent_instructions,consent_privacy,fielding_plan,sensitive_safety,completion_burden');
  var sum = A.ORDER.reduce(function (a, k) { return a + A.SPECS[k].weight; }, 0);
  eq('weights sum to 15', sum, 15);
  eq('respondent_instructions weight', A.SPECS.respondent_instructions.weight, 4);
  eq('consent_privacy weight', A.SPECS.consent_privacy.weight, 4);
  eq('fielding_plan weight', A.SPECS.fielding_plan.weight, 3);
  eq('sensitive_safety weight', A.SPECS.sensitive_safety.weight, 2);
  eq('completion_burden weight', A.SPECS.completion_burden.weight, 2);
  A.ORDER.forEach(function (k) {
    ok(k + ' assembled via factory', A.LENSES[k] && typeof A.LENSES[k].assess === 'function');
    eq(k + ' factory weight matches spec', A.LENSES[k].SDSI_WEIGHT, A.SPECS[k].weight);
  });
  // Respondent-instructions check rename: unclear_respondent_purpose → unclear_purpose_for_respondents.
  var riChecks = Object.keys(A.SPECS.respondent_instructions.checks);
  ok('respondent_instructions has unclear_purpose_for_respondents', riChecks.indexOf('unclear_purpose_for_respondents') !== -1);
  ok('respondent_instructions no longer has unclear_respondent_purpose', riChecks.indexOf('unclear_respondent_purpose') === -1);
  // Completion-burden check renames + new check (7 total).
  var cbChecks = Object.keys(A.SPECS.completion_burden.checks);
  eq('completion_burden has 7 checks', cbChecks.length, 7);
  ['excessive_completion_burden', 'missing_completion_time_estimate', 'required_item_burden',
   'poor_device_or_mode_readiness', 'unclear_save_return_behavior', 'unclear_submission_confirmation',
   'completion_friction_risk'].forEach(function (k) {
    ok('completion_burden has ' + k, cbChecks.indexOf(k) !== -1);
  });
  ['excessive_length', 'unclear_completion_time', 'too_many_required_items',
   'poor_mobile_readiness', 'unclear_save_return', 'confusing_submission'].forEach(function (k) {
    ok('completion_burden no longer has ' + k, cbChecks.indexOf(k) === -1);
  });
})();

/* ── Each sample scores deterministically. ── */
(function samples() {
  eq('respondent_instructions sample pts',
     assess('respondent_instructions', A.SPECS.respondent_instructions.sample.flags, A.SPECS.respondent_instructions.sample.context).sdsiPoints, 3.5);
  eq('consent_privacy sample pts',
     assess('consent_privacy', A.SPECS.consent_privacy.sample.flags, A.SPECS.consent_privacy.sample.context).sdsiPoints, 3.4);
  eq('fielding_plan sample pts',
     assess('fielding_plan', A.SPECS.fielding_plan.sample.flags, A.SPECS.fielding_plan.sample.context).sdsiPoints, 2.6);
  eq('sensitive_safety sample pts',
     assess('sensitive_safety', A.SPECS.sensitive_safety.sample.flags, A.SPECS.sensitive_safety.sample.context).sdsiPoints, 1.8);
  eq('completion_burden sample pts',
     assess('completion_burden', A.SPECS.completion_burden.sample.flags, A.SPECS.completion_burden.sample.context).sdsiPoints, 1.8);
})();

/* ── Respondent-instructions blocker: instructions_absent. ── */
(function instructionsAbsentBlocker() {
  var absent = [{ check: 'missing_instructions', item_ref: 'intro', quote: 'q', severity: 'major', rationale: 'r', suggested_revision: 'r' }];
  // Fires: no instruction block + moderate stakes.
  var byStakes = assess('respondent_instructions', absent, { has_instruction_block: 'no', stakes: 'moderate', survey_required: 'no' });
  ok('fires when no instruction block + moderate stakes',
     byStakes.blockers.some(function (b) { return b.key === 'instructions_absent'; }));
  ok('→ not launch ready', byStakes.launchReady === false);
  // Fires: no instruction block + required survey (low stakes).
  var byRequired = assess('respondent_instructions', absent, { has_instruction_block: 'no', stakes: 'low', survey_required: 'yes' });
  ok('fires when no instruction block + required survey',
     byRequired.blockers.some(function (b) { return b.key === 'instructions_absent'; }));
  // Cleared: an instruction block exists.
  var hasBlock = assess('respondent_instructions', absent, { has_instruction_block: 'yes', stakes: 'high', survey_required: 'yes' });
  ok('cleared when an instruction block exists',
     !hasBlock.blockers.some(function (b) { return b.key === 'instructions_absent'; }));
  // Cleared: low stakes + not required (only lowers the score).
  var lowOptional = assess('respondent_instructions', absent, { has_instruction_block: 'no', stakes: 'low', survey_required: 'no' });
  ok('cleared when low stakes + not required',
     !lowOptional.blockers.some(function (b) { return b.key === 'instructions_absent'; }));
  ok('→ low/optional still launch ready', lowOptional.launchReady === true);
  // Orthogonality: the gate never moves the number.
  eq('blocked score equals unblocked score', byStakes.score, lowOptional.score);
  eq('blocked points equal unblocked points', byStakes.sdsiPoints, lowOptional.sdsiPoints);
})();

/* ── Consent blocker: missing_consent_or_required_status (legacy/internal key). ── */
(function consentBlocker() {
  function fired(r) { return r.blockers.some(function (b) { return b.key === 'missing_consent_or_required_status'; }); }
  var missing = [{ check: 'missing_participation_statement', item_ref: 'intro', quote: 'q', severity: 'major', rationale: 'r', suggested_revision: 'r' }];
  // Fires: missing participation statement + moderate stakes + consent not handled elsewhere.
  var byStakes = assess('consent_privacy', missing, { consent_obtained_elsewhere: 'no', stakes: 'moderate' });
  ok('fires on missing_participation_statement + moderate stakes', fired(byStakes));
  ok('→ not launch ready', byStakes.launchReady === false);
  // Fires: required participation status even at low stakes.
  var status = [{ check: 'unclear_participation_status', item_ref: 'intro', quote: 'q', severity: 'major', rationale: 'r', suggested_revision: 'r' }];
  ok('fires on unclear_participation_status when required',
     fired(assess('consent_privacy', status, { consent_obtained_elsewhere: 'no', participation_status: 'required', stakes: 'low' })));
  ok('fires on unclear_participation_status when expected',
     fired(assess('consent_privacy', status, { consent_obtained_elsewhere: 'no', participation_status: 'expected', stakes: 'low' })));
  // Cleared: consent handled elsewhere.
  ok('cleared when consent handled elsewhere',
     !fired(assess('consent_privacy', missing, { consent_obtained_elsewhere: 'yes', stakes: 'high' })));
  // Cleared: voluntary + low stakes (only lowers the score).
  var lowVoluntary = assess('consent_privacy', missing, { consent_obtained_elsewhere: 'no', participation_status: 'voluntary', stakes: 'low' });
  ok('cleared when voluntary + low stakes', !fired(lowVoluntary));
  ok('→ voluntary/low still launch ready', lowVoluntary.launchReady === true);

  // overpromised_privacy is the third trigger.
  var overpromise = [{ check: 'overpromised_privacy', item_ref: 'intro', quote: 'q', severity: 'critical', rationale: 'r', suggested_revision: 'r' }];
  ok('overpromised_privacy fires at high stakes',
     fired(assess('consent_privacy', overpromise, { stakes: 'high' })));
  ok('overpromised_privacy fires when identifiers collected at low stakes',
     fired(assess('consent_privacy', overpromise, { stakes: 'low', collects_identifiers: 'yes' })));
  ok('overpromised_privacy fires when minors involved',
     fired(assess('consent_privacy', overpromise, { stakes: 'low', involves_minors: 'yes' })));
  ok('overpromised_privacy cleared at low stakes with no identifiable/sensitive/minor data',
     !fired(assess('consent_privacy', overpromise, { stakes: 'low', collects_identifiers: 'no', collects_sensitive_information: 'no', involves_minors: 'no' })));
})();

/* ── Fielding blockers: no_clear_target_population + launch_plan_missing_for_required_survey. ── */
(function fieldingBlockers() {
  function fired(r, key) { return r.blockers.some(function (b) { return b.key === key; }); }
  var noPop = [{ check: 'unclear_target_population', item_ref: 'plan', quote: 'q', severity: 'major', rationale: 'r', suggested_revision: 'r' }];
  ok('no_clear_target_population fires when population blank',
     fired(assess('fielding_plan', noPop, { target_population: '' }), 'no_clear_target_population'));
  ok('cleared when a target population is declared',
     !fired(assess('fielding_plan', noPop, { target_population: 'Grade 6–8 families at School X' }), 'no_clear_target_population'));

  var noWindow = [{ check: 'unclear_launch_window', item_ref: 'plan', quote: 'q', severity: 'moderate', rationale: 'r', suggested_revision: 'r' }];
  ok('launch_plan_missing fires for a required survey',
     fired(assess('fielding_plan', noWindow, { participation_status: 'required' }), 'launch_plan_missing_for_required_survey'));
  ok('launch_plan_missing fires for an expected survey too',
     fired(assess('fielding_plan', noWindow, { participation_status: 'expected' }), 'launch_plan_missing_for_required_survey'));
  ok('cleared when the survey is voluntary',
     !fired(assess('fielding_plan', noWindow, { participation_status: 'voluntary' }), 'launch_plan_missing_for_required_survey'));
  ok('cleared when participation status is unstated',
     !fired(assess('fielding_plan', noWindow, { participation_status: 'unstated' }), 'launch_plan_missing_for_required_survey'));
  // close-date is a second trigger.
  var noClose = [{ check: 'unclear_close_date', item_ref: 'plan', quote: 'q', severity: 'moderate', rationale: 'r', suggested_revision: 'r' }];
  ok('launch_plan_missing fires on unclear_close_date for required survey too',
     fired(assess('fielding_plan', noClose, { participation_status: 'required' }), 'launch_plan_missing_for_required_survey'));
  // major_logistics_gap is the third trigger.
  var gap = [{ check: 'major_logistics_gap', item_ref: 'plan', quote: 'q', severity: 'major', rationale: 'r', suggested_revision: 'r' }];
  ok('launch_plan_missing fires on major_logistics_gap for required/expected survey',
     fired(assess('fielding_plan', gap, { participation_status: 'expected' }), 'launch_plan_missing_for_required_survey'));
  ok('major_logistics_gap does not block a voluntary survey',
     !fired(assess('fielding_plan', gap, { participation_status: 'voluntary' }), 'launch_plan_missing_for_required_survey'));
})();

/* ── Safety blocker: unsafe_sensitive_disclosure (4 gated triggers). ── */
(function safetyBlocker() {
  function fired(r) { return r.blockers.some(function (b) { return b.key === 'unsafe_sensitive_disclosure'; }); }
  function flag(check) { return [{ check: check, item_ref: 'q7', quote: 'q', severity: 'major', rationale: 'r', suggested_revision: 'r' }]; }

  // Trigger 1: risk_followup_plan_absent gated by collects_risk_disclosure.
  var rfp = flag('risk_followup_plan_absent');
  ok('risk_followup_plan_absent fires when risk disclosure collected', fired(assess('sensitive_safety', rfp, { collects_risk_disclosure: 'yes' })));
  ok('→ not launch ready', assess('sensitive_safety', rfp, { collects_risk_disclosure: 'yes' }).launchReady === false);
  ok('risk_followup_plan_absent cleared when no risk disclosure', !fired(assess('sensitive_safety', rfp, { collects_risk_disclosure: 'no', stakes: 'high', involves_minors: 'yes' })));

  // Trigger 2: minor_or_vulnerable_group_safeguard_gap gated by risk OR minors OR moderate/high stakes.
  var sg = flag('minor_or_vulnerable_group_safeguard_gap');
  ok('safeguard gap fires when minors involved', fired(assess('sensitive_safety', sg, { involves_minors: 'yes', collects_risk_disclosure: 'no', stakes: 'low' })));
  ok('safeguard gap fires at moderate stakes', fired(assess('sensitive_safety', sg, { involves_minors: 'no', collects_risk_disclosure: 'no', stakes: 'moderate' })));
  ok('safeguard gap cleared when no minors/risk and low stakes', !fired(assess('sensitive_safety', sg, { involves_minors: 'no', collects_risk_disclosure: 'no', stakes: 'low' })));

  // Trigger 3: missing_decline_path gated by risk OR minors OR sensitive OR moderate/high stakes.
  var mdp = flag('missing_decline_path');
  ok('missing_decline_path fires when sensitive info collected', fired(assess('sensitive_safety', mdp, { collects_sensitive_information: 'yes', stakes: 'low', involves_minors: 'no', collects_risk_disclosure: 'no' })));
  ok('missing_decline_path cleared when nothing elevated', !fired(assess('sensitive_safety', mdp, { collects_sensitive_information: 'no', stakes: 'low', involves_minors: 'no', collects_risk_disclosure: 'no' })));

  // Trigger 4: sensitive_topic_purpose_unclear gated by sensitive AND moderate/high stakes (both required).
  var stp = flag('sensitive_topic_purpose_unclear');
  ok('purpose-unclear fires when sensitive + high stakes', fired(assess('sensitive_safety', stp, { collects_sensitive_information: 'yes', stakes: 'high' })));
  ok('purpose-unclear cleared when sensitive but low stakes', !fired(assess('sensitive_safety', stp, { collects_sensitive_information: 'yes', stakes: 'low' })));
  ok('purpose-unclear cleared when high stakes but not sensitive', !fired(assess('sensitive_safety', stp, { collects_sensitive_information: 'no', stakes: 'high' })));

  // A non-trigger sensitive check never blocks.
  var unintro = flag('sensitive_topic_unintroduced');
  ok('sensitive_topic_unintroduced never blocks', !fired(assess('sensitive_safety', unintro, { collects_risk_disclosure: 'yes', involves_minors: 'yes', stakes: 'high' })));
})();

/* ── Blocker orthogonality: a blocker never changes the numeric score. ── */
(function orthogonality() {
  var missing = [{ check: 'missing_participation_statement', item_ref: 'intro', quote: 'q', severity: 'major', rationale: 'r', suggested_revision: 'r' }];
  var blocked = assess('consent_privacy', missing, { consent_obtained_elsewhere: 'no', stakes: 'moderate' });
  var clean   = assess('consent_privacy', missing, { consent_obtained_elsewhere: 'yes', stakes: 'moderate' });
  eq('blocked score equals unblocked score', blocked.score, clean.score);
  eq('blocked points equal unblocked points', blocked.sdsiPoints, clean.sdsiPoints);
  ok('only the verdict differs', blocked.launchReady === false && clean.launchReady === true);
})();

/* ── Blocker scope per lens (provisional shells). ── */
(function blockerScope() {
  eq('respondent_instructions has 1 blocker', A.SPECS.respondent_instructions.blockers.length, 1);
  eq('consent_privacy has 1 blocker', A.SPECS.consent_privacy.blockers.length, 1);
  eq('fielding_plan has 2 blockers', A.SPECS.fielding_plan.blockers.length, 2);
  eq('sensitive_safety has 1 blocker', A.SPECS.sensitive_safety.blockers.length, 1);
  eq('completion_burden has 1 blocker', A.SPECS.completion_burden.blockers.length, 1);
})();

/* ── Completion-burden blocker: survey_not_reasonably_completable. ── */
(function completableBlocker() {
  function fired(r) { return r.blockers.some(function (b) { return b.key === 'survey_not_reasonably_completable'; }); }
  function flag(check) { return [{ check: check, item_ref: 'survey', quote: 'q', severity: 'moderate', rationale: 'r', suggested_revision: 'r' }]; }
  var burden = flag('excessive_completion_burden');
  // Fires: required survey at moderate stakes.
  ok('fires for required survey at moderate stakes',
     fired(assess('completion_burden', burden, { participation_status: 'required', stakes: 'moderate', delivery_mode: 'online' })));
  // Fires: expected mobile survey with a device-completion problem even at low stakes.
  ok('fires for expected mobile survey with device problem at low stakes',
     fired(assess('completion_burden', flag('poor_device_or_mode_readiness'), { participation_status: 'expected', stakes: 'low', delivery_mode: 'mobile' })));
  // Cleared: voluntary survey never blocks.
  ok('cleared for voluntary survey',
     !fired(assess('completion_burden', burden, { participation_status: 'voluntary', stakes: 'high', delivery_mode: 'mobile' })));
  // Cleared: required survey, low stakes, online mode (no device problem).
  ok('cleared for required low-stakes online survey',
     !fired(assess('completion_burden', burden, { participation_status: 'required', stakes: 'low', delivery_mode: 'online' })));
  // Cleared: trigger flag not in the eligible set.
  ok('missing_completion_time_estimate never triggers the blocker',
     !fired(assess('completion_burden', flag('missing_completion_time_estimate'), { participation_status: 'required', stakes: 'high', delivery_mode: 'mobile' })));
  // Orthogonality: verdict differs, score does not.
  var blocked = assess('completion_burden', burden, { participation_status: 'required', stakes: 'moderate', delivery_mode: 'online' });
  var clean   = assess('completion_burden', burden, { participation_status: 'voluntary', stakes: 'moderate', delivery_mode: 'online' });
  eq('blocked score equals unblocked score', blocked.score, clean.score);
  eq('blocked points equal unblocked points', blocked.sdsiPoints, clean.sdsiPoints);
  ok('only the verdict differs', blocked.launchReady === false && clean.launchReady === true);
})();

/* ── Completion-burden advisory warning: widespread_completion_burden_risk. ── */
(function widespreadBurdenWarning() {
  function warned(r) { return r.warnings.some(function (w) { return w.key === 'widespread_completion_burden_risk'; }); }
  function f(ref) { return { check: 'completion_friction_risk', item_ref: ref, quote: 'q', severity: 'moderate', rationale: 'r', suggested_revision: 'r' }; }
  // 3 of 5 reviewed units flagged (>40%) → warns.
  ok('warns when >40% of reviewed units carry a flag',
     warned(assess('completion_burden', [f('p1'), f('p2'), f('p3')], { denominator_reviewed: 5 })));
  // 1 of 5 (<40%) → silent.
  ok('silent below the 40% threshold',
     !warned(assess('completion_burden', [f('p1')], { denominator_reviewed: 5 })));
  // No denominator → silent (advisory infra unavailable).
  ok('silent without a denominator',
     !warned(assess('completion_burden', [f('p1'), f('p2'), f('p3')], {})));
  // Falls back to item_count when denominator_reviewed is absent.
  ok('falls back to item_count as denominator',
     warned(assess('completion_burden', [f('p1'), f('p2'), f('p3')], { item_count: 5 })));
  // Advisory only: never changes the score or blocks launch on its own.
  var w = assess('completion_burden', [f('p1'), f('p2'), f('p3')], { denominator_reviewed: 5 });
  ok('warning does not block launch by itself', w.launchReady === true);
})();

/* ── Warnings: only completion_burden carries one; the other four are empty. ── */
(function warningScope() {
  A.ORDER.forEach(function (k) {
    if (k === 'completion_burden') {
      eq(k + ' has 1 advisory warning', A.SPECS[k].warnings.length, 1);
      eq(k + ' warning key', A.SPECS[k].warnings[0].key, 'widespread_completion_burden_risk');
    } else {
      eq(k + ' has no warnings yet', (A.SPECS[k].warnings || []).length, 0);
    }
  });
})();

/* ── Every lens carries the renderer/proposer scaffolding it needs. ── */
(function scaffolding() {
  A.ORDER.forEach(function (k) {
    var s = A.SPECS[k];
    ok(k + ' has intro', typeof s.intro === 'string' && s.intro.length > 0);
    ok(k + ' has reviewerPrompt', typeof s.reviewerPrompt === 'string' && s.reviewerPrompt.length > 0);
    ok(k + ' has at least 6 checks', Object.keys(s.checks).length >= 6);
    ok(k + ' has a checkDesc per check', Object.keys(s.checkDesc).length === Object.keys(s.checks).length);
    ok(k + ' has a severityHint per check', Object.keys(s.severityHints).length === Object.keys(s.checks).length);
    ok(k + ' has contextFields', Array.isArray(s.contextFields) && s.contextFields.length > 0);
    ok(k + ' has a sample with flags', s.sample && Array.isArray(s.sample.flags));
  });
})();

if (fail === 0) console.log('ALL PASS — ' + pass + ' passed, 0 failed');
else { console.error('\n' + fail + ' FAILED (' + pass + ' passed)'); process.exit(1); }
