/* journey.js — shared step-rail navigation + light sample interactions for the
   ReliCheck evidence-journey apps (siri-app.php + rssi-app.php). Mockup phase:
   no engine calls; the numbers are sample data. */
(function () {
  'use strict';

  /* Shared sample survey used across both apps so the journey feels continuous.
     Normal professional case = 4 constructs (never a single construct). */
  window.JOURNEY_SAMPLE = {
    project: 'Team Climate Pulse',
    responses: 248,
    constructs: [
      { key: 'engagement', name: 'Engagement',      items: 5, alpha: 0.88 },
      { key: 'manager',    name: 'Manager Support', items: 4, alpha: 0.90 },
      { key: 'belonging',  name: 'Belonging',       items: 4, alpha: 0.83 },
      { key: 'workload',   name: 'Workload Balance', items: 4, alpha: 0.71 }
    ]
  };

  function activateStep(stepId) {
    var steps = [].slice.call(document.querySelectorAll('.jr-step'));
    var idx = steps.findIndex(function (s) { return s.getAttribute('data-step') === stepId; });
    steps.forEach(function (s, i) {
      s.setAttribute('data-active', s.getAttribute('data-step') === stepId ? '1' : '0');
      // mark earlier steps as "done" for the progress feel
      s.setAttribute('data-done', (idx > -1 && i < idx) ? '1' : '0');
    });
    [].slice.call(document.querySelectorAll('.jr-panel')).forEach(function (p) {
      p.setAttribute('data-active', p.getAttribute('data-step') === stepId ? '1' : '0');
    });
    var main = document.querySelector('.jr-main');
    if (main) main.scrollTo ? main.scrollTo({ top: 0 }) : (main.scrollTop = 0);
    window.scrollTo({ top: 0, behavior: 'smooth' });
    if (history.replaceState) history.replaceState(null, '', '#' + stepId);
  }
  window.JOURNEY_GO = activateStep;

  function init() {
    document.querySelectorAll('.jr-step').forEach(function (btn) {
      btn.addEventListener('click', function () { activateStep(btn.getAttribute('data-step')); });
    });
    // in-page "next/back/go to step" links
    document.querySelectorAll('[data-go]').forEach(function (el) {
      el.addEventListener('click', function (e) { e.preventDefault(); activateStep(el.getAttribute('data-go')); });
    });
    // construct pill toggles (sample interaction — visual only this pass)
    document.querySelectorAll('.construct-pills').forEach(function (row) {
      row.querySelectorAll('.cp').forEach(function (cp) {
        cp.addEventListener('click', function () {
          cp.setAttribute('data-on', cp.getAttribute('data-on') === '1' ? '0' : '1');
        });
      });
    });
    // honor an initial hash, else first step
    var hash = (location.hash || '').replace('#', '');
    var first = document.querySelector('.jr-step');
    activateStep(hash && document.querySelector('.jr-step[data-step="' + hash + '"]') ? hash
      : (first ? first.getAttribute('data-step') : null));
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
  else init();
})();
