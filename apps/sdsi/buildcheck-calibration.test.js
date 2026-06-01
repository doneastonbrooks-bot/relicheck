/* SDSI Build Check — CALIBRATION HARNESS (Phase 2B.1)
   Exercises the deterministic engine across weak → strong → incomplete surveys
   and asserts each lands in the expected readiness band. Run:
       node apps/sdsi/buildcheck-calibration.test.js
   It also prints a per-case report (score, label, category points, major flags)
   so the calibration is auditable.                                            */
var BC = require('./buildcheck-engine.js');

var pass = 0, fail = 0;
function ok(name, cond) { if (cond) { pass++; console.log('    ✓ ' + name); } else { fail++; console.log('    ✗ ' + name); } }

function major(r) {
  return r.flags.filter(function (f) {
    return f.severity === 'moderate' || f.severity === 'major' || f.severity === 'critical';
  }).map(function (f) { return f.check; });
}
function report(label, r) {
  console.log('\n— ' + label + ' —');
  console.log('    SCORE  ' + r.total.toFixed(1) + ' / 50   (' + r.pct + '%)   →  ' + r.band);
  console.log('    cats   ' + r.categories.map(function (c) { return c.key + ' ' + c.points + '/' + c.weight; }).join('  '));
  var mf = major(r);
  console.log('    major  ' + (mf.length ? mf.join(', ') : '(none)'));
}

function likert(prompt, construct) { return { type: 'Likert Scale', prompt: prompt, construct: construct, settings: { points: 5 } }; }

/* ── Case 1: Empty survey → Not ready ─────────────────────────────────────── */
(function () {
  var r = BC.assess({ purpose: '', population: '', constructs: [], items: [], sections: [] });
  report('Case 1 · Empty survey', r);
  ok('band = Not ready for launch review', r.band === 'Not ready for launch review');
})();

/* ── Case 2: Very weak → Weak or Not ready ────────────────────────────────── */
(function () {
  var r = BC.assess({
    purpose: 'feedback', // unclear / thin
    population: '',
    constructs: [], // no constructs
    items: [
      { type: 'Likert Scale', prompt: 'Stuff?', settings: { points: 5 } },                 // fragment
      { type: 'Likert Scale', prompt: 'Are you always happy and never upset here?', settings: { points: 7 } }, // double-barreled + absolute + mixed length
      { type: 'Multiple Choice', prompt: 'Pick one', options: [] },                          // too few options
      { type: 'Rating Scale', prompt: 'Rate it', settings: {} },                             // no max
      { type: 'Open-Ended', prompt: 'Comments' }
    ],
    sections: [{ id: 1 }]
  });
  report('Case 2 · Very weak survey', r);
  ok('band = Weak or Not ready', r.bandKey === 'weak' || r.bandKey === 'notready');
})();

/* ── Case 3: Basic usable → Developing ────────────────────────────────────── */
(function () {
  var r = BC.assess({
    purpose: 'Understand how new hires experience the first month of onboarding so we can improve it.',
    population: 'New hires in their first 90 days',
    constructs: [], // limited construct mapping (none defined)
    items: [
      likert('I understood what was expected of me.'),
      likert('My manager was available when I had questions.'),
      likert('The training sessions were useful.'),
      likert('I had the tools I needed to start.'),
      { type: 'Multiple Choice', prompt: 'Which team did you join?', options: ['Support', 'Sales', 'Engineering'] },
      { type: 'Long Answer', prompt: 'What was confusing during your first week?' },
      { type: 'Likert Scale', prompt: 'Onboarding was good and the people were friendly.', settings: { points: 5 } }, // double-barreled
      { type: 'Open-Ended', prompt: 'Anything else?' }
    ],
    sections: [{ id: 1 }]
  });
  report('Case 3 · Basic usable survey', r);
  ok('band = Developing design', r.bandKey === 'developing');
})();

/* ── Case 4: Strong with one or two flaws → Solid or low Strong ───────────── */
(function () {
  var r = BC.assess({
    purpose: 'Measure employee engagement and belonging on the support team to guide manager coaching.',
    population: 'Customer support staff',
    constructs: [
      { name: 'Engagement', definition: 'How energized by and committed to their work an employee feels.' },
      { name: 'Belonging', definition: 'The sense of being accepted and valued as part of the team.' }
    ],
    items: [
      likert('I feel energized by my daily work.', 'Engagement'),
      likert('I look forward to starting my workday.', 'Engagement'),
      likert('My work gives me a sense of accomplishment.', 'Engagement'),
      likert('I feel accepted by my team.', 'Belonging'),
      likert('I am rarely stressed and I am never overwhelmed at work.', 'Belonging'), // double-barreled + absolute
      { type: 'Long Answer', prompt: 'What is one thing that would improve your experience?', construct: 'Engagement' }
    ],
    sections: [{ id: 1 }]
  });
  report('Case 4 · Strong with one or two flaws', r);
  ok('band = Solid or Strong (not near-perfect)', r.bandKey === 'solid' || r.bandKey === 'strong');
  ok('score is not near-perfect (< 47.5)', r.total < 47.5);
})();

/* ── Case 5: Research-grade → Strong ──────────────────────────────────────── */
(function () {
  var items = [];
  var eng = ['I feel energized by my daily work.', 'I look forward to starting my workday.', 'My work gives me a real sense of accomplishment.', 'Time passes quickly when I am working.'];
  var bel = ['I feel accepted by the people on my team.', 'My contributions are genuinely valued here.', 'I can be myself at work without worrying.', 'People on my team have my back when things get hard.'];
  var sup = ['My manager gives me feedback I can act on.', 'My manager removes obstacles that get in my way.', 'My manager treats me with respect.', 'My manager helps me grow in my role.'];
  eng.forEach(function (p) { items.push(likert(p, 'Engagement')); });
  bel.forEach(function (p) { items.push(likert(p, 'Belonging')); });
  sup.forEach(function (p) { items.push(likert(p, 'Manager Support')); });
  items.push({ type: 'Long Answer', prompt: 'What is one change that would most improve your experience on the team?', construct: 'Engagement' });
  var r = BC.assess({
    purpose: 'Measure engagement, belonging, and perceived manager support across the support organization to target coaching and retention efforts.',
    population: 'Customer support staff across three regional teams, mixed reading levels',
    constructs: [
      { name: 'Engagement', definition: 'The degree to which an employee feels energized by and absorbed in their work.' },
      { name: 'Belonging', definition: 'An employee\'s sense of being accepted, valued, and safe within their team.' },
      { name: 'Manager Support', definition: 'The extent to which a manager provides actionable feedback, removes obstacles, and supports growth.' }
    ],
    items: items,
    sections: [{ id: 1 }, { id: 2 }, { id: 3 }]
  });
  report('Case 5 · Research-grade survey', r);
  ok('band = Strong design', r.bandKey === 'strong');
})();

/* ── Case 6: Qualitative-only must not be unfairly penalised on reliability ── */
(function () {
  var r = BC.assess({
    purpose: 'Explore, in patients\' own words, what shaped their experience of care at the new community clinic.',
    population: 'Adult patients seen at the clinic in the past month',
    constructs: [{ name: 'Care Experience', definition: 'How patients narrate and make sense of their experience of care at the clinic.' }],
    items: [
      { type: 'Long Answer', prompt: 'Tell us about your most recent visit to the clinic, start to finish.', construct: 'Care Experience' },
      { type: 'Long Answer', prompt: 'What mattered most to you during that visit?', construct: 'Care Experience' },
      { type: 'Open-Ended', prompt: 'What, if anything, would have made your visit better?', construct: 'Care Experience' },
      { type: 'Long Answer', prompt: 'How did the staff make you feel?', construct: 'Care Experience' },
      { type: 'Comment Box', prompt: 'Anything else you would like us to know?', construct: 'Care Experience' }
    ],
    sections: [{ id: 1 }]
  });
  report('Case 6 · Qualitative-only survey', r);
  var rel = r.categories.filter(function (c) { return c.key === 'reliability'; })[0];
  ok('reliability at full weight (' + rel.points + '/' + rel.weight + ')', rel.points === rel.weight);
  ok('no reliability flags', r.flags.filter(function (f) { return f.category === 'reliability'; }).length === 0);
  ok('still lands Solid or Strong', r.bandKey === 'solid' || r.bandKey === 'strong');
})();

console.log('\n' + (fail === 0 ? 'ALL PASS' : (fail + ' FAILED')) + ' (' + pass + ' passed)');
process.exit(fail === 0 ? 0 : 1);
