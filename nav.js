/* ReliCheck shared site header (JavaScript-injected).
 *
 * Each marketing page mounts the nav by including:
 *   <div id="nav-mount"></div>
 *   <script src="/nav.js" defer></script>
 *
 * After this file runs, the placeholder is replaced with the full header.
 * Active top-level item is auto-detected from window.location.pathname so
 * no per-page variable is needed.
 *
 * Also wires the sticky-header scroll listener, mega-menu hover/click,
 * and the mobile drawer toggle.
 *
 * Single source of truth: edit this file once and every page picks up
 * the change on next reload.
 */
(function () {
  // ----- Active-item detection (intentionally a no-op) ---------------------
  // Active-state highlighting on the current page made Dashboards (when on a
  // /dashboards/* page) read as a chunky pill that visually grouped with the
  // adjacent Build a Survey trigger whenever its mega-menu was open. We now
  // skip the active highlight entirely; the breadcrumb on each page handles
  // orientation. To restore active highlighting in the future, populate
  // activeKey from window.location.pathname and set cls / aria below.
  var dashAct = { cls: '', aria: '' };
  var suiteAct = { cls: '', aria: '' };
  var sampleAct = { cls: '', aria: '' };
  var priceAct = { cls: '', aria: '' };

  // ----- Header markup -----------------------------------------------------
  var html =
'<header class="site-header" id="siteHeader">' +
'  <div class="container-app site-header-inner">' +
'    <a href="/index.html" class="brand brand-image" aria-label="ReliCheck home"><img src="/logo-brand.svg" alt="ReliCheck" /></a>' +
'    <nav class="nav-primary" aria-label="Primary">' +
'      <div class="nav-dd" data-dd>' +
'        <button class="nav-dd-trigger ">Check a Survey <span class="nav-caret">&#9662;</span></button>' +
'        <div class="nav-dd-panel"><div class="nav-dd-panel-inner">' +
'          <div class="mega-section">' +
'            <h5>The results</h5>' +
'            <a href="/overview.html#analyze"><strong>Survey Results overview</strong><span>How the Strength Score works.</span></a>' +
'            <a href="/overview.html#analyze"><strong>Survey Strength Score</strong><span>One score, five checks, clear next steps.</span></a>' +
'          </div>' +
'          <div class="mega-section">' +
'            <h5>What ReliCheck checks</h5>' +
'            <a href="/reliability-guide.html"><strong>Do your questions work together?</strong><span>Reliability and item-total checks.</span></a>' +
'            <a href="/validity-guide.html"><strong>What is your survey really measuring?</strong><span>Factor analysis, KMO, rotation.</span></a>' +
'            <a href="/reliability-guide.html"><strong>Are your results strong enough?</strong><span>Response quality and missing data.</span></a>' +
'            <a href="/ai-features.html"><strong>What are people saying?</strong><span>Open-ended themes and quotes.</span></a>' +
'          </div>' +
'          <div class="mega-section">' +
'            <h5>See it</h5>' +
'            <a class="mega-link" href="/dashboards/survey-assessment.html">Dashboards</a>' +
'            <a class="mega-link" href="/samples.html">Sample reports</a>' +
'            <a class="mega-link" href="/methodology.html">Methodology</a>' +
'            <a class="mega-link" href="/reliability-guide.html">Reliability guide</a>' +
'            <a class="mega-link" href="/validity-guide.html">Validity guide</a>' +
'            <a class="mega-see-all" href="/help.html">View all resources &rarr;</a>' +
'          </div>' +
'        </div></div>' +
'      </div>' +

'      <a href="/import-data.html" class="nav-dd-trigger ">Import Data</a>' +

'      <a href="/suites.html" class="nav-dd-trigger ' + suiteAct.cls + '"' + suiteAct.aria + '>Suites</a>' +

'      <div class="nav-dd" data-dd>' +
'        <button class="nav-dd-trigger ' + dashAct.cls + '"' + dashAct.aria + '>Dashboards <span class="nav-caret">&#9662;</span></button>' +
'        <div class="nav-dd-panel"><div class="nav-dd-panel-inner">' +
'          <div class="mega-section">' +
'            <h5>By level</h5>' +
'            <a href="/dashboards/survey-assessment.html"><strong>Survey assessment</strong><span>Strength Index, Readiness, Reliability, Validity, Response Quality, Completion.</span></a>' +
'            <a href="/dashboards/data-analysis.html"><strong>Data analysis</strong><span>Description, Compare, Subgroups, Equity Gaps, Predictors, Key Drivers, IRT, MLM, Trends.</span></a>' +
'            <a href="/dashboards/test-analytics.html"><strong>Test analytics</strong><span>Classroom test analytics: difficulty, distractor, skill rollups, item health.</span></a>' +
'          </div>' +
'          <div class="mega-section">' +
'            <h5>Related</h5>' +
'            <a href="/methodology.html"><strong>Methodology</strong><span>The math behind every dashboard, written for reviewers.</span></a>' +
'            <a href="/ai-features.html"><strong>AI features</strong><span>The narrators and verdict cards on every analytics tab.</span></a>' +
'            <a href="/samples.html"><strong>Sample reports</strong><span>End-to-end use-case mockups beyond the dashboard previews.</span></a>' +
'          </div>' +
'        </div></div>' +
'      </div>' +

'      <div class="nav-dd" data-dd>' +
'        <button class="nav-dd-trigger ">Build a Survey <span class="nav-caret">&#9662;</span></button>' +
'        <div class="nav-dd-panel"><div class="nav-dd-panel-inner">' +
'          <div class="mega-section">' +
'            <h5>Build</h5>' +
'            <a href="/overview.html#build"><strong>Question types</strong><span>Likert, choice, open-ended, matrix, ranking.</span></a>' +
'            <a href="/ai-features.html"><strong>AI question review</strong><span>Catch double-barreled, leading, vague items.</span></a>' +
'            <a href="/overview.html#collect"><strong>Collect responses</strong><span>Links, email, QR, embed.</span></a>' +
'            <a href="/import-survey.html"><strong>Upload your survey</strong><span>Bring a survey from SurveyMonkey or Qualtrics.</span></a>' +
'          </div>' +
'          <div class="mega-section">' +
'            <h5>Templates</h5>' +
'            <a href="/app.html?template=course_evaluation"><strong>Course Evaluation</strong><span>End-of-term and mid-course feedback.</span></a>' +
'            <a href="/app.html?template=student_belonging"><strong>Student Belonging</strong><span>Validated belonging and connectedness scales.</span></a>' +
'            <a href="/app.html?template=workplace_engagement"><strong>Employee Engagement</strong><span>Quarterly pulse and annual engagement.</span></a>' +
'            <a href="/app.html?template=program_evaluation"><strong>Program Evaluation</strong><span>Pre/post outcome scales for grant reporting.</span></a>' +
'          </div>' +
'          <div class="mega-section">' +
'            <h5>Popular templates</h5>' +
'            <a class="mega-link" href="/app.html?template=customer_satisfaction">Customer Satisfaction</a>' +
'            <a class="mega-link" href="/app.html?template=climate_survey">Climate Survey</a>' +
'            <a class="mega-link" href="/app.html?template=training_feedback">Training Feedback</a>' +
'            <a class="mega-link" href="/app.html?template=community_needs">Community Needs</a>' +
'            <a class="mega-see-all" href="/samples.html">View all templates &rarr;</a>' +
'          </div>' +
'        </div></div>' +
'      </div>' +

'      <a href="/samples.html" class="nav-dd-trigger ' + sampleAct.cls + '"' + sampleAct.aria + '>Sample Reports</a>' +

'      <div class="nav-dd" data-dd>' +
'        <button class="nav-dd-trigger ">Solutions <span class="nav-caret">&#9662;</span></button>' +
'        <div class="nav-dd-panel"><div class="nav-dd-panel-inner">' +
'          <div class="mega-section">' +
'            <h5>By team</h5>' +
'            <a href="/education.html"><strong>Education</strong><span>Course evaluation, belonging, school climate.</span></a>' +
'            <a href="/tests-overview.html"><strong>Classroom tests</strong><span>Item analysis for teachers and assessment coordinators.</span></a>' +
'            <a href="/hr-teams.html"><strong>HR &amp; Teams</strong><span>Engagement, onboarding, exit, climate.</span></a>' +
'            <a href="/customer-feedback.html"><strong>Customer Feedback</strong><span>CSAT, NPS, product, service feedback.</span></a>' +
'            <a href="/research.html"><strong>Research</strong><span>Validated scales, manuscript exports.</span></a>' +
'          </div>' +
'          <div class="mega-section">' +
'            <h5>By use case</h5>' +
'            <a href="/360-surveys.html"><strong>360 / Multi-rater surveys</strong><span>Manager 360s, peer feedback, leadership development.</span></a>' +
'            <a href="/education.html"><strong>Course evaluation</strong><span>End-of-term and mid-course feedback.</span></a>' +
'            <a href="/tests-overview.html"><strong>Test &amp; item analysis</strong><span>Reliability, difficulty, discrimination, quality.</span></a>' +
'            <a href="/hr-teams.html"><strong>Engagement &amp; pulse</strong><span>Recurring employee surveys with k-anonymity.</span></a>' +
'            <a href="/program-evaluation.html"><strong>Program evaluation &amp; accreditation</strong><span>Same category, different scopes.</span></a>' +
'            <a href="/mixed-methods.html"><strong>Mixed-methods research</strong><span>Quantitative scales and qualitative themes.</span></a>' +
'          </div>' +
'          <div class="mega-section">' +
'            <h5>Other resources</h5>' +
'            <a class="mega-link" href="/ai-features.html">AI features</a>' +
'            <a class="mega-link" href="/case-studies.html">Customer stories</a>' +
'            <a class="mega-link" href="/compare.html">Compare ReliCheck</a>' +
'            <a class="mega-link" href="/help.html">Help center</a>' +
'            <a class="mega-link" href="/blog.html">Blog</a>' +
'            <a class="mega-see-all" href="/help.html">View all resources &rarr;</a>' +
'          </div>' +
'        </div></div>' +
'      </div>' +

'      <a href="/pricing.html" class="nav-dd-trigger ' + priceAct.cls + '"' + priceAct.aria + '>Pricing</a>' +
'    </nav>' +

'    <div class="nav-cta">' +
'      <a href="/login.html" class="btn btn-ghost btn-sm">Sign in</a>' +
'      <a href="/signup.html" class="btn btn-primary btn-sm">Get started</a>' +
'      <button class="nav-toggle" id="navToggle" aria-label="Open menu" aria-expanded="false">' +
'        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M4 7h16M4 12h16M4 17h16"/></svg>' +
'      </button>' +
'    </div>' +
'  </div>' +

'  <div class="nav-drawer" id="navDrawer" aria-hidden="true">' +
'    <div class="drawer-group"><h5>Check a Survey</h5><a href="/overview.html#analyze">Survey Results overview</a><a href="/dashboards/survey-assessment.html">Dashboards</a><a href="/reliability-guide.html">Reliability guide</a><a href="/validity-guide.html">Validity guide</a><a href="/methodology.html">Methodology</a><a href="/samples.html">Sample reports</a></div>' +
'    <div class="drawer-group"><h5>Import Data</h5><a href="/import-data.html">Import results</a><a href="/import-survey.html">Upload your survey</a><a href="/tests-overview.html">Upload a classroom test</a></div>' +
'    <div class="drawer-group"><h5>Suites</h5><a href="/suites.html">All suites</a><a href="/suites.html#360">360 Feedback</a><a href="/suites.html#hr">HR &amp; Teams</a><a href="/suites.html#pulse">Pulse</a><a href="/suites.html#program-eval">Program Evaluation</a><a href="/suites.html#education">Education</a><a href="/suites.html#test-analysis">Test &amp; Item Analysis</a><a href="/suites.html#researcher">Researcher</a><a href="/suites.html#cx">Customer Experience</a></div>' +
'    <div class="drawer-group"><h5>Dashboards</h5><a href="/dashboards/survey-assessment.html">Survey assessment</a><a href="/dashboards/data-analysis.html">Data analysis</a><a href="/dashboards/test-analytics.html">Test analytics</a></div>' +
'    <div class="drawer-group"><h5>Build a Survey</h5><a href="/overview.html#build">Build</a><a href="/ai-features.html">AI features</a><a href="/app.html?template=course_evaluation">Course Evaluation</a><a href="/app.html?template=workplace_engagement">Employee Engagement</a><a href="/app.html?template=program_evaluation">Program Evaluation</a></div>' +
'    <div class="drawer-group"><h5>Sample Reports</h5><a href="/samples.html">Sample reports</a><a href="/samples/interactive-course-evaluation.html">Interactive demo</a></div>' +
'    <div class="drawer-group"><h5>Solutions</h5><a href="/education.html">Education &amp; Evaluation</a><a href="/tests-overview.html">Classroom tests</a><a href="/hr-teams.html">HR &amp; Teams</a><a href="/360-surveys.html">360 / Multi-rater surveys</a><a href="/customer-feedback.html">Customer Feedback</a><a href="/research.html">Research</a><a href="/mixed-methods.html">Mixed-Methods</a><a href="/compare.html">Compare ReliCheck</a><a href="/case-studies.html">Customer stories</a><a href="/help.html">Help center</a><a href="/blog.html">Blog</a></div>' +
'    <div class="drawer-group"><h5>Plans</h5><a href="/pricing.html">Pricing</a></div>' +
'    <div class="drawer-cta"><a href="/login.html" class="btn btn-outline">Sign in</a><a href="/signup.html" class="btn btn-primary">Get started</a></div>' +
'  </div>' +
'</header>';

  // ----- Inject ------------------------------------------------------------
  function inject() {
    var mount = document.getElementById('nav-mount');
    if (!mount) return;
    // Replace the placeholder div entirely so the <header> is a top-level
    // child of <body> (not nested inside a div).
    mount.outerHTML = html;
    wire();
  }

  // ----- Wiring (sticky header, mega-menu, mobile drawer) ------------------
  function wire() {
    var header = document.getElementById('siteHeader');
    if (header) {
      var onScroll = function () { header.classList.toggle('is-scrolled', window.scrollY > 6); };
      document.addEventListener('scroll', onScroll, { passive: true });
      onScroll();
    }
    document.querySelectorAll('.nav-dd').forEach(function (dd) {
      var trigger = dd.querySelector('.nav-dd-trigger');
      var timer;
      var open = function (s) {
        clearTimeout(timer);
        if (s) {
          document.querySelectorAll('.nav-dd[data-open="true"]').forEach(function (o) { if (o !== dd) o.removeAttribute('data-open'); });
          dd.setAttribute('data-open', 'true');
          if (header) header.classList.add('is-mega-open');
        } else {
          timer = setTimeout(function () {
            dd.removeAttribute('data-open');
            if (header && !document.querySelector('.nav-dd[data-open="true"]')) header.classList.remove('is-mega-open');
          }, 120);
        }
      };
      dd.addEventListener('mouseenter', function () { open(true); });
      dd.addEventListener('mouseleave', function () { open(false); });
      if (trigger) trigger.addEventListener('click', function (e) { e.preventDefault(); open(dd.getAttribute('data-open') !== 'true'); });
    });
    var toggle = document.getElementById('navToggle');
    var drawer = document.getElementById('navDrawer');
    if (toggle && drawer) {
      toggle.addEventListener('click', function () {
        var openState = drawer.classList.toggle('is-open');
        drawer.setAttribute('aria-hidden', String(!openState));
        document.body.style.overflow = openState ? 'hidden' : '';
      });
    }
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', inject);
  } else {
    inject();
  }
})();
