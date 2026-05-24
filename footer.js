/* ReliCheck shared site footer (JavaScript-injected).
 *
 * Each marketing page mounts the footer by including:
 *   <div id="footer-mount"></div>
 *   <script src="/footer.js" defer></script>
 *
 * After this file runs, the placeholder is replaced with the full footer.
 *
 * Single source of truth: edit this file once and every page picks up
 * the change on next reload.
 */
(function () {
  var html =
'<footer class="site-footer">' +
'  <div class="container-content">' +
'    <div class="footer-grid">' +
'      <div>' +
'        <a href="/index.html" class="brand brand-image" style="color:#fff;"><img src="/logo-brand-white.svg" alt="ReliCheck" /></a>' +
'        <p style="color:rgba(255,255,255,0.65);font-size:14px;margin-top:16px;max-width:280px;">Evidence-driven survey software for research, education, HR, and customer experience teams.</p>' +
'      </div>' +
'      <div><h4>Product</h4><ul>' +
'        <li><a href="/overview.html">Overview</a></li>' +
'        <li><a href="/suites.html">Suites</a></li>' +
'        <li><a href="/dashboards/survey-assessment.html">Dashboards</a></li>' +
'        <li><a href="/import-data.html">Import data</a></li>' +
'        <li><a href="/tests-overview.html">Test &amp; item analysis</a></li>' +
'        <li><a href="/ai-features.html">AI features</a></li>' +
'        <li><a href="/pricing.html">Pricing</a></li>' +
'        <li><a href="/developers.html">Developers</a></li>' +
'      </ul></div>' +
'      <div><h4>Solutions</h4><ul>' +
'        <li><a href="/education.html">Education</a></li>' +
'        <li><a href="/tests-overview.html">Classroom tests</a></li>' +
'        <li><a href="/program-evaluation.html">Program evaluation &amp; accreditation</a></li>' +
'        <li><a href="/hr-teams.html">HR &amp; Teams</a></li>' +
'        <li><a href="/360-surveys.html">360 / Multi-rater</a></li>' +
'        <li><a href="/customer-feedback.html">Customer feedback</a></li>' +
'        <li><a href="/research.html">Research</a></li>' +
'      </ul></div>' +
'      <div><h4>Learn</h4><ul>' +
'        <li><a href="/methodology.html">Methodology</a></li>' +
'        <li><a href="/reliability-guide.html">Reliability guide</a></li>' +
'        <li><a href="/validity-guide.html">Validity guide</a></li>' +
'        <li><a href="/ai-features.html">AI features</a></li>' +
'        <li><a href="/compare.html">Compare ReliCheck</a></li>' +
'        <li><a href="/help.html">Help center</a></li>' +
'        <li><a href="/blog.html">Blog</a></li>' +
'      </ul></div>' +
'      <div><h4>Company</h4><ul>' +
'        <li><a href="/about.html">About</a></li>' +
'        <li><a href="/privacy.html">Privacy</a></li>' +
'        <li><a href="/terms.html">Terms</a></li>' +
'        <li><a href="/case-studies.html">Customer stories</a></li>' +
'      </ul></div>' +
'    </div>' +
'    <div class="footer-bottom">' +
'      <span>&copy; 2026 ReliCheck Survey. All rights reserved.</span>' +
'      <span>Made for teams that need to explain their numbers.</span>' +
'    </div>' +
'  </div>' +
'</footer>';

  function inject() {
    var mount = document.getElementById('footer-mount');
    if (!mount) return;
    mount.outerHTML = html;
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', inject);
  } else {
    inject();
  }
})();
