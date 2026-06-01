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
  eq('band', r.band, 'Strong design');
  eq('not blocked', r.blocked, false);
  eq('max is 50', r.max, 50);
  ok('seven categories', r.categories.length === 7);
  ok('no serious issues', r.issues.length === 0);
})();

console.log('\n— Empty survey scores Not ready —');
(function () {
  var r = BC.assess({ purpose: '', population: '', constructs: [], items: [], sections: [] });
  console.log('    total=' + r.total + ' band="' + r.band + '"');
  ok('total < 30', r.total < 30);
  eq('band', r.band, 'Not ready for launch review');
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
  ok('too-few-options flagged', r.flags.some(function (f) { return f.check === 'too_few_options'; }));
  ok('leading wording flagged', r.flags.some(function (f) { return f.check === 'leading'; }));
  ok('item flags carry item_ref', r.itemFlags.some(function (f) { return f.item_ref === 'q1'; }));
  ok('recommendations present', r.recommendations.length > 0);
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
  var rel = cat(r, 'reliability');
  console.log('    reliability points=' + rel.points + '/' + rel.weight);
  eq('reliability at full weight', rel.points, rel.weight);
  ok('no reliability flags for qualitative design', r.flags.filter(function (f) { return f.category === 'reliability'; }).length === 0);
})();

console.log('\n— Category weights sum to 50 —');
(function () {
  var sum = BC.CATEGORIES.reduce(function (a, c) { return a + c.weight; }, 0);
  eq('weights sum', sum, 50);
})();

console.log('\n' + (fail === 0 ? 'ALL PASS' : (fail + ' FAILED')) + ' (' + pass + ' passed)');
process.exit(fail === 0 ? 0 : 1);
