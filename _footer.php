<?php
// Shared site footer + nav scripts. Included by every marketing page via
// `<?php include __DIR__ . '/_footer.php'; ?>`. Subpages in subdirectories
// include via the parent path (e.g. `/../_footer.php`).
//
// All hrefs are root-relative so this file works from any directory depth.
?>
<footer class="site-footer">
  <div class="container-content">
    <div class="footer-grid">
      <div>
        <a href="/index.html" class="brand brand-image" style="color:#fff;"><img src="/logo-brand-white.svg" alt="ReliCheck" /></a>
        <p style="color:rgba(255,255,255,0.65);font-size:14px;margin-top:16px;max-width:280px;">Evidence-driven survey software for research, education, HR, and customer experience teams.</p>
      </div>
      <div><h4>Product</h4><ul><li><a href="/overview.html">Overview</a></li><li><a href="/suites.html">Suites</a></li><li><a href="/dashboards/survey-assessment.html">Dashboards</a></li><li><a href="/import-data.html">Import data</a></li><li><a href="/tests-overview.html">Test &amp; item analysis</a></li><li><a href="/ai-features.html">AI features</a></li><li><a href="/pricing.html">Pricing</a></li><li><a href="/developers.html">Developers</a></li></ul></div>
      <div><h4>Solutions</h4><ul><li><a href="/education.html">Education</a></li><li><a href="/tests-overview.html">Classroom tests</a></li><li><a href="/program-evaluation.html">Program evaluation &amp; accreditation</a></li><li></li><li><a href="/hr-teams.html">HR &amp; Teams</a></li><li><a href="/360-surveys.html">360 / Multi-rater</a></li><li><a href="/customer-feedback.html">Customer feedback</a></li><li><a href="/research.html">Research</a></li></ul></div>
      <div><h4>Learn</h4><ul><li><a href="/methodology.html">Methodology</a></li><li><a href="/reliability-guide.html">Reliability guide</a></li><li><a href="/validity-guide.html">Validity guide</a></li><li><a href="/ai-features.html">AI features</a></li><li><a href="/compare.html">Compare ReliCheck</a></li><li><a href="/help.html">Help center</a></li><li><a href="/blog.html">Blog</a></li></ul></div>
      <div><h4>Company</h4><ul><li><a href="/about.html">About</a></li><li><a href="/privacy.html">Privacy</a></li><li><a href="/terms.html">Terms</a></li><li><a href="/case-studies.html">Customer stories</a></li></ul></div>
    </div>
    <div class="footer-bottom"><span>© 2026 ReliCheck Survey. All rights reserved.</span><span>Made for teams that need to explain their numbers.</span></div>
  </div>
</footer>

<script>
  (function () {
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
  })();
</script>
