/* Node tests for the SDSI Build Check deterministic engine. Run:
   node apps/sdsi/buildcheck-engine.test.js                                     */
var BC = require('./buildcheck-engine.js');

var pass = 0, fail = 0;
function ok(name, cond) { if (cond) { pass++; console.log('  ✓ ' + name); } else { fail++; console.log('  ✗ ' + name); } }
function eq(name, a, b) { ok(name + ' (' + a + ' === ' + b + ')', a === b); }

function cat(r, key) { return r.categories.filter(function (c) { return c.key === key; })[0]; }

console.log('\n— Clean, well-built survey scores Strong —');
(function () {
  var r = BC.assess({
    purpose: 'Measure employee engagement and belonging across the support team to guide manager coaching.',
    population: 'Customer support staff, mixed reading levels',
    constructs: [
      { name: 'Engagement', definition: 'The degree to which an employee feels energized by and committed to their work.' },
      { name: 'Belonging', definition: 'The sense of being accepted and valued as part of the team.' }
    ],
    items: [
      { item_ref: 'q1', type: 'Likert Scale', prompt: 'I feel energized by my daily work.', construct: 'Engagement', settings: { points: 5 } },
      { item_ref: 'q2', type: 'Likert Scale', prompt: 'I look forward to starting my workday.', construct: 'Engagement', settings: { points: 5 } },
      { item_ref: 'q3', type: 'Likert Scale', prompt: 'My work gives me a sense of accomplishment.', construct: 'Engagement', settings: { points: 5 } },
      { item_ref: 'q4', type: 'Likert Scale', prompt: 'I feel accepted by my team.', construct: 'Belonging', settings: { points: 5 } },
      { item_ref: 'q5', type: 'Likert Scale', prompt: 'My contributions are valued here.', construct: 'Belonging', settings: { points: 5 } },
      { item_ref: 'q6', type: 'Likert Scale', prompt: 'I can be myself at work.', construct: 'Belonging', settings: { points: 5 } },
      { item_ref: 'q7', type: 'Long Answer', prompt: 'What is one thing that would improve your experience on the team?', construct: 'Engagement' }
    ],
    sections: [{ id: 1 }]
  });
  console.log('    total=' + r.total + ' pct=' + r.pct + ' band="' + r.band + '"');
  ok('total >= 45 (Strong)', r.total >= 45);
  eq('band', r.band, 'Strong');
  eq('not blocked', r.blocked, false);
  eq('max is 50', r.max, 50);
  ok('five scoring domains', r.categories.length === 5);
  ok('every clean item scores 50', r.itemScores.every(function (s) { return s.score === 50; }));
  ok('no serious issues', r.issues.length === 0);
})();

console.log('\n— Empty survey scores Not ready —');
(function () {
  var r = BC.assess({ purpose: '', population: '', constructs: [], items: [], sections: [] });
  console.log('    total=' + r.total + ' band="' + r.band + '"');
  ok('total < 20 (Not ready)', r.total < 20);
  eq('band', r.band, 'Not ready');
  ok('purpose flagged', r.flags.some(function (f) { return f.check === 'purpose_missing'; }));
  ok('empty survey flagged', r.flags.some(function (f) { return f.check === 'empty_survey'; }));
})();

console.log('\n— Problem items get item-level flags —');
(function () {
  var r = BC.assess({
    purpose: 'Understand satisfaction with the onboarding program for new hires in the first 90 days.',
    population: 'New hires',
    constructs: [{ name: 'Onboarding', definition: 'How well new hires feel prepared and supported during onboarding.' }],
    items: [
      { item_ref: 'q1', type: 'Likert Scale', prompt: 'I am rarely stressed and I am never overwhelmed during onboarding.', construct: 'Onboarding', settings: { points: 5 } },
      { item_ref: 'q2', type: 'Multiple Choice', prompt: 'Which best describes your role?', options: [], construct: 'Onboarding' },
      { item_ref: 'q3', type: 'Long Answer', prompt: 'Obviously you found the orientation useful, but what would you change?', construct: 'Onboarding' }
    ],
    sections: [{ id: 1 }]
  });
  console.log('    total=' + r.total + ' issues=' + r.issues.length + ' itemFlags=' + r.itemFlags.length);
  ok('double-barreled flagged', r.flags.some(function (f) { return f.check === 'double_barreled'; }));
  ok('missing-options flagged (empty choice)', r.flags.some(function (f) { return f.check === 'missing_response_options'; }));
  ok('leading wording flagged', r.flags.some(function (f) { return f.check === 'leading'; }));
  ok('item flags carry item_ref', r.itemFlags.some(function (f) { return f.item_ref === 'q1'; }));
  ok('recommendations present', r.recommendations.length > 0);
})();

console.log('\n— Single Choice options are validated (empty + placeholder) —');
(function () {
  var r = BC.assess({
    purpose: 'Understand team experience.',
    population: 'Staff',
    constructs: [{ name: 'Experience', definition: 'How staff experience their work.' }],
    items: [
      { item_ref: 'q1', type: 'Single Choice', prompt: 'Tenure', options: [], construct: 'Experience' },
      { item_ref: 'q2', type: 'Single Choice', prompt: 'Role Level', options: ['Option 1', 'Option 2'], construct: 'Experience' },
      { item_ref: 'q3', type: 'Single Choice', prompt: 'Department', options: ['Engineering', 'Operations'], construct: 'Experience' }
    ],
    sections: [{ id: 1 }]
  });
  var byRef = function (ref, check) { return r.flags.some(function (f) { return f.item_ref === ref && f.check === check; }); };
  ok('empty Single Choice flagged missing_response_options', byRef('q1', 'missing_response_options'));
  ok('placeholder Single Choice flagged placeholder_options', byRef('q2', 'placeholder_options'));
  ok('real-option Single Choice NOT flagged', !r.flags.some(function (f) { return f.item_ref === 'q3' && (f.check === 'too_few_options' || f.check === 'placeholder_options'); }));
})();

console.log('\n— Qualitative-only survey is not punished on reliability —');
(function () {
  var r = BC.assess({
    purpose: 'Explore how community members describe their experience with the new clinic in their own words.',
    population: 'Clinic patients',
    constructs: [{ name: 'Experience', definition: 'How patients narrate their experience of care at the clinic.' }],
    items: [
      { item_ref: 'q1', type: 'Long Answer', prompt: 'Tell us about your most recent visit to the clinic.', construct: 'Experience' },
      { item_ref: 'q2', type: 'Long Answer', prompt: 'What mattered most to you during that visit?', construct: 'Experience' },
      { item_ref: 'q3', type: 'Open-Ended', prompt: 'What could the clinic do to serve you better?', construct: 'Experience' }
    ],
    sections: [{ id: 1 }]
  });
  console.log('    total=' + r.total + ' band="' + r.band + '"');
  // Clean open-ended items are not penalised: each should score full marks, so a
  // qualitative-only design lands Strong on item validity.
  ok('clean qualitative items score 50', r.itemScores.every(function (s) { return s.score === 50; }));
  ok('qualitative design is Strong', r.total >= 45);
  ok('no reliability flags for qualitative design', r.flags.filter(function (f) { return f.category === 'reliability'; }).length === 0);
})();

// ── Required validity-review test cases (spec O) ──────────────────────────
console.log('\n— Required validity-review cases —');
(function () {
  function review(item) {
    return BC.assess({ purpose: 'Team climate study.', population: 'Staff',
      constructs: [{ name: 'C', definition: 'Climate as experienced by staff members.' }],
      items: [Object.assign({ item_ref: 'q', construct: 'C' }, item)], sections: [{ id: 1 }] });
  }
  function has(r, key) { return r.flags.some(function (f) { return f.check === key; }); }

  var t1 = review({ type: 'Single Choice', prompt: 'Tenure', options: ['Option 1', 'Option 2'] });
  ok('T1 placeholder_options', has(t1, 'placeholder_options'));
  ok('T1 analysis_readiness_concern', has(t1, 'analysis_readiness_concern'));
  ok('T1 missing_prefer_not_to_answer', has(t1, 'missing_prefer_not_to_answer'));

  var t2 = review({ type: 'Single Choice', prompt: 'Role Level', options: ['Option 1', 'Option 2'] });
  ok('T2 placeholder_options', has(t2, 'placeholder_options'));
  ok('T2 analysis_readiness_concern', has(t2, 'analysis_readiness_concern'));
  ok('T2 missing_prefer_not_to_answer', has(t2, 'missing_prefer_not_to_answer'));

  var t3 = review({ type: 'Single Choice', prompt: 'Which best describes your role level?', options: ['The Floor', 'Directly Supervise', 'Upper Management'] });
  ok('T3 informal_category_label', has(t3, 'informal_category_label'));
  ok('T3 inconsistent_option_structure', has(t3, 'inconsistent_option_structure'));
  ok('T3 missing_other_option', has(t3, 'missing_other_option'));
  ok('T3 missing_prefer_not_to_answer', has(t3, 'missing_prefer_not_to_answer'));

  var t4 = review({ type: 'Likert Scale', prompt: 'My supervisor communicates clearly and treats employees fairly.', settings: { points: 5 } });
  ok('T4 double_barreled', has(t4, 'double_barreled'));

  var t5 = review({ type: 'Likert Scale', prompt: 'How often does your supervisor provide feedback?', settings: { points: 5, likertLow: 'Strongly disagree', likertHigh: 'Strongly agree' } });
  ok('T5 question_scale_mismatch', has(t5, 'question_scale_mismatch'));

  var t6 = review({ type: 'Single Choice', prompt: 'Age', options: ['18-24', '25-34', '45-54', '55+'] });
  ok('T6 category_gaps', has(t6, 'category_gaps'));

  var t7 = review({ type: 'Single Choice', prompt: 'Tenure', options: ['0-1 years', '1-3 years', '3-5 years'] });
  ok('T7 overlapping_categories', has(t7, 'overlapping_categories'));

  var t8 = review({ type: 'Single Choice', prompt: 'What is your disability status?', required: true, options: ['Yes, I have a disability', 'No'] });
  ok('T8 required_sensitive_item', has(t8, 'required_sensitive_item'));
  ok('T8 missing_prefer_not_to_answer', has(t8, 'missing_prefer_not_to_answer'));
  ok('T8 sensitive_item_without_context', has(t8, 'sensitive_item_without_context'));

  // Likert items unchanged: a clean Likert is not falsely flagged for options
  var clean = review({ type: 'Likert Scale', prompt: 'I feel supported by my manager.', settings: { points: 5 } });
  ok('clean Likert has no option/placeholder flags', !clean.flags.some(function (f) {
    return ['placeholder_options', 'missing_response_options', 'too_few_options'].indexOf(f.check) !== -1;
  }));
})();

// Build a survey with `clean` clean Likert items + `bad` unusable (empty-options) items.
function mixed(clean, bad) {
  var items = [];
  for (var k = 0; k < clean; k++) items.push({ item_ref: 'g' + k, type: 'Likert Scale', prompt: 'I feel supported at work, item ' + k + '.', construct: 'C', settings: { points: 5 } });
  for (var b = 0; b < bad; b++) items.push({ item_ref: 'bad' + b, type: 'Single Choice', prompt: 'Region ' + b, options: [], construct: 'C' });
  return BC.assess({ purpose: 'Climate study.', population: 'Staff', constructs: [{ name: 'C', definition: 'Climate as experienced by staff members at work.' }], items: items, sections: [{ id: 1 }] });
}

console.log('\n— Band cap: 1 blocker -> Caution (number kept) —');
(function () {
  var r = mixed(9, 1); // 1/10 = 10%
  console.log('    numeric=' + r.sdsi_score_numeric + ' raw=' + r.sdsi_raw_band + ' display=' + r.sdsi_display_band);
  ok('numeric score kept high (>=45)', r.sdsi_score_numeric >= 45);
  ok('raw band Strong', r.sdsi_raw_band === 'Strong');
  ok('display band Caution', r.sdsi_display_band === 'Caution');
  ok('band was capped', r.sdsi_band_was_capped === true);
  ok('1 deployment blocker', r.deployment_blocker_count === 1);
  ok('headline phrased per spec', r.blocker_headline === '1 question is not deployment-ready.');
})();

console.log('\n— Band cap: 3 blockers (<20%) -> Weak —');
(function () {
  var r = mixed(13, 3); // 3/16 = 18.75% (< 20%)
  console.log('    numeric=' + r.sdsi_score_numeric + ' display=' + r.sdsi_display_band + ' blockers=' + r.deployment_blocker_count);
  ok('display band Weak', r.sdsi_display_band === 'Weak');
  ok('3 deployment blockers', r.deployment_blocker_count === 3);
  ok('headline counts questions', r.blocker_headline === '3 questions are not deployment-ready.');
})();

console.log('\n— Band cap: >=20% blockers -> Not ready —');
(function () {
  var r = mixed(6, 2); // 2/8 = 25% (>= 20%, even though only 2 blockers)
  console.log('    numeric=' + r.sdsi_score_numeric + ' display=' + r.sdsi_display_band);
  ok('display band Not ready', r.sdsi_display_band === 'Not ready');
  ok('headline uses percentage', /%/.test(r.blocker_headline));
})();

console.log('\n— Clean survey is NOT band-capped —');
(function () {
  var r = mixed(3, 0);
  ok('no blockers', r.deployment_blocker_count === 0);
  ok('not capped, raw === display === Strong', r.sdsi_band_was_capped === false && r.sdsi_display_band === 'Strong' && r.sdsi_raw_band === 'Strong');
})();

console.log('\n— False-positive fixes: clean demographic items —');
(function () {
  function review(item) {
    return BC.assess({ purpose: 'Team climate study.', population: 'Staff',
      constructs: [{ name: 'C', definition: 'Climate as experienced by staff members.' }],
      items: [Object.assign({ item_ref: 'q', construct: 'C' }, item)], sections: [{ id: 1 }] });
  }
  function has(r, key) { return r.flags.some(function (f) { return f.check === key; }); }

  // A well-formed tenure scale with open-ended bands must NOT be falsely flagged.
  var ten = review({ type: 'Single Choice', prompt: 'How long have you worked in your current organization?',
    options: ['Less than 1 year', '1-3 years', '4-6 years', '7-10 years', 'More than 10 years', 'Prefer not to answer'] });
  ok('clean tenure: no false overlapping_categories', !has(ten, 'overlapping_categories'));
  ok('clean tenure: no false category_gaps', !has(ten, 'category_gaps'));
  ok('clean tenure: no false non_exhaustive_categories', !has(ten, 'non_exhaustive_categories'));

  // A well-formed role-level list (legit "Supervisor / manager" level + Other) must NOT be flagged.
  var role = review({ type: 'Single Choice', prompt: 'Which best describes your current role level?',
    options: ['Frontline staff / individual contributor', 'Team lead', 'Supervisor / manager', 'Director / senior manager', 'Executive / senior leadership', 'Other', 'Prefer not to answer'] });
  ok('clean role: no false inconsistent_option_structure', !has(role, 'inconsistent_option_structure'));
  ok('clean role: no false missing_other_option', !has(role, 'missing_other_option'));
  ok('clean role: no false non_exhaustive_categories', !has(role, 'non_exhaustive_categories'));

  // A genuine overlap (closed ranges sharing endpoints) MUST still be caught.
  var ov = review({ type: 'Single Choice', prompt: 'Tenure', options: ['0-1 years', '1-3 years', '3-5 years'] });
  ok('genuine overlap still flagged', has(ov, 'overlapping_categories'));
})();

console.log('\n— Item-validity caps: compounding —');
(function () {
  function one(item) {
    return BC.assess({ purpose: 'Team climate study to guide manager coaching decisions.', population: 'Staff',
      constructs: [{ name: 'C', definition: 'Climate as experienced by staff members.' }],
      items: [Object.assign({ item_ref: 'q', construct: 'C' }, item)], sections: [{ id: 1 }] }).itemScores[0];
  }
  // Single capped flaw (frequency stem on an agreement scale = question_scale_mismatch,
  // cap 30; the accompanying missing_timeframe carries no cap) -> no compound penalty.
  var s1 = one({ type: 'Likert Scale', prompt: 'How often does your supervisor provide feedback?', settings: { points: 5, likertLow: 'Strongly disagree', likertHigh: 'Strongly agree' } });
  ok('debug: exposes triggered_caps array', Array.isArray(s1.triggered_caps));
  ok('debug: exposes raw + final', s1.item_sdsi_raw_score != null && s1.item_sdsi_final_score != null);
  ok('single cap: most_restrictive_cap = 30', s1.most_restrictive_cap === 30);
  ok('single cap: no compound penalty', s1.compound_cap_penalty === 0);
  ok('single cap: final = min(raw, 30)', s1.item_sdsi_final_score === Math.min(s1.item_sdsi_raw_score, 30));

  // Multiple capped flaws (placeholder options cap 20 + required sensitive cap 30) compound.
  var s2 = one({ type: 'Single Choice', prompt: 'What is your disability status?', required: true, options: ['Option 1', 'Option 2'] });
  ok('multi-cap: 2+ triggered caps', s2.triggered_caps.length >= 2);
  ok('multi-cap: penalty = 4*(n-1)', s2.compound_cap_penalty === 4 * (s2.triggered_caps.length - 1));
  ok('multi-cap: final = max(0, min(raw,cap) - penalty)',
     s2.item_sdsi_final_score === Math.max(0, Math.min(s2.item_sdsi_raw_score, s2.most_restrictive_cap) - s2.compound_cap_penalty));
  ok('multi-cap: scores below the most restrictive cap', s2.item_sdsi_final_score < s2.most_restrictive_cap);

  // Survey SDSI = average of FINAL item scores.
  var rr = BC.assess({ purpose: 'Team climate study to guide manager coaching decisions.', population: 'Staff',
    constructs: [{ name: 'C', definition: 'Climate as experienced by staff members.' }],
    items: [
      { item_ref: 'a', construct: 'C', type: 'Likert Scale', prompt: 'I feel respected at work.', settings: { points: 5 } },
      { item_ref: 'b', construct: 'C', type: 'Likert Scale', prompt: 'My manager communicates clearly and supports my growth.', settings: { points: 5 } }
    ], sections: [{ id: 1 }] });
  var avgFinal = (rr.itemScores[0].item_sdsi_final_score + rr.itemScores[1].item_sdsi_final_score) / 2;
  ok('survey total = average of final item scores', Math.abs(rr.total - Math.round(avgFinal * 10) / 10) < 0.05);
})();

console.log('\n— Category weights sum to 50 —');
(function () {
  var sum = BC.CATEGORIES.reduce(function (a, c) { return a + c.weight; }, 0);
  eq('weights sum', sum, 50);
})();

console.log('\n' + (fail === 0 ? 'ALL PASS' : (fail + ' FAILED')) + ' (' + pass + ' passed)');
process.exit(fail === 0 ? 0 : 1);
