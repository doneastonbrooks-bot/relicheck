<?php
// ReliCheck Instrument Quality Detail render shell.
// -------------------------------------------------------------------
// Six lenses, one shell. Mount page sets window.IQ_LENS.
?>

<section class="iq-app" aria-label="Instrument Quality">

  <!-- Source ribbon —
       Kept in DOM so the engine's setAttribute('data-source', ...) call
       doesn't error, but hidden — the mount template's context strip
       already shows source + row count more accurately. -->
  <div class="iq-source" id="iqSource" hidden style="display:none;">
    <span class="iq-source-pip" aria-hidden="true"></span>
    <span class="iq-source-label" id="iqSourceLabel">Sample data</span>
    <span class="iq-source-meta" id="iqSourceMeta">—</span>
  </div>

  <div class="iq-empty" id="iqEmpty" hidden>
    <p>No dataset is available. Run <a href="/evidence-intake.php?studio=survey">Evidence Intake</a> first.</p>
  </div>

  <!-- Each lens hosts one large section -->
  <section class="iq-lens" data-lens="reliability" hidden>
    <header class="iq-head">
      <div class="iq-eyebrow"><span class="pip" aria-hidden="true"></span><span>Reliability</span></div>
      <h3 id="iqRelTitle">—</h3>
      <p class="iq-sub" id="iqRelSub">—</p>
    </header>
    <div class="iq-body" id="iqRelBody"></div>
    <footer class="iq-foot"><h4 class="iq-block-h">Interpretation</h4><div class="iq-interp" id="iqRelInterp">—</div></footer>
  </section>

  <section class="iq-lens" data-lens="construct_alignment" hidden>
    <header class="iq-head">
      <div class="iq-eyebrow"><span class="pip" aria-hidden="true"></span><span>Construct Alignment</span></div>
      <h3 id="iqCaTitle">—</h3>
      <p class="iq-sub" id="iqCaSub">—</p>
    </header>
    <div class="iq-body" id="iqCaBody"></div>
    <footer class="iq-foot"><h4 class="iq-block-h">Interpretation</h4><div class="iq-interp" id="iqCaInterp">—</div></footer>
  </section>

  <section class="iq-lens" data-lens="validity" hidden>
    <header class="iq-head">
      <div class="iq-eyebrow"><span class="pip" aria-hidden="true"></span><span>Validity Review</span></div>
      <h3 id="iqValTitle">—</h3>
      <p class="iq-sub" id="iqValSub">—</p>
    </header>
    <div class="iq-body" id="iqValBody"></div>
    <footer class="iq-foot"><h4 class="iq-block-h">Interpretation</h4><p class="iq-interp" id="iqValInterp">—</p></footer>
  </section>

  <section class="iq-lens" data-lens="item_quality" hidden>
    <header class="iq-head">
      <div class="iq-eyebrow"><span class="pip" aria-hidden="true"></span><span>Item Quality</span></div>
      <h3 id="iqIqTitle">—</h3>
      <p class="iq-sub" id="iqIqSub">—</p>
    </header>
    <div class="iq-body" id="iqIqBody"></div>
    <footer class="iq-foot"><h4 class="iq-block-h">Interpretation</h4><p class="iq-interp" id="iqIqInterp">—</p></footer>
  </section>

  <section class="iq-lens" data-lens="scale_structure" hidden>
    <header class="iq-head">
      <div class="iq-eyebrow"><span class="pip" aria-hidden="true"></span><span>Scale Structure</span></div>
      <h3 id="iqSsTitle">—</h3>
      <p class="iq-sub" id="iqSsSub">—</p>
    </header>
    <div class="iq-body" id="iqSsBody"></div>
    <footer class="iq-foot"><h4 class="iq-block-h">Interpretation</h4><p class="iq-interp" id="iqSsInterp">—</p></footer>
  </section>

  <section class="iq-lens" data-lens="factor_readiness" hidden>
    <header class="iq-head">
      <div class="iq-eyebrow"><span class="pip" aria-hidden="true"></span><span>Factor Readiness</span></div>
      <h3 id="iqFrTitle">—</h3>
      <p class="iq-sub" id="iqFrSub">—</p>
    </header>
    <div class="iq-body" id="iqFrBody"></div>
    <footer class="iq-foot"><h4 class="iq-block-h">Interpretation</h4><p class="iq-interp" id="iqFrInterp">—</p></footer>
  </section>

  <section class="iq-lens" data-lens="bias_clarity" hidden>
    <header class="iq-head">
      <div class="iq-eyebrow"><span class="pip" aria-hidden="true"></span><span>Bias &amp; Clarity Review</span></div>
      <h3 id="iqBcTitle">—</h3>
      <p class="iq-sub" id="iqBcSub">—</p>
    </header>
    <div class="iq-body" id="iqBcBody"></div>
    <div class="iq-ai-hook">
      <span class="iq-ai-badge">AI upgrade available</span>
      <span class="iq-ai-note">The v1 below is rule-based on the variable names that ReliCheck can see. An AI pass that reads the actual item prompts (when wired in from the Survey Builder) catches double-barreled wording, leading items, jargon, and cultural assumptions.</span>
    </div>
    <footer class="iq-foot"><h4 class="iq-block-h">Interpretation</h4><p class="iq-interp" id="iqBcInterp">—</p></footer>
  </section>

  <section class="iq-lens" data-lens="response_scale" hidden>
    <header class="iq-head">
      <div class="iq-eyebrow"><span class="pip" aria-hidden="true"></span><span>Response Scale Review</span></div>
      <h3 id="iqRsTitle">—</h3>
      <p class="iq-sub" id="iqRsSub">—</p>
    </header>
    <div class="iq-body" id="iqRsBody"></div>
    <footer class="iq-foot"><h4 class="iq-block-h">Interpretation</h4><p class="iq-interp" id="iqRsInterp">—</p></footer>
  </section>

  <!-- Dignity / Framing Readiness — a PRE-DATA validity subdomain. Unlike the
       other lenses it does not read the response dataset; it reviews the
       instrument's item text against AI-proposed, human-settled dignity flags
       and scores deterministically via DignityEngine (apps/sdsi). -->
  <section class="iq-lens" data-lens="dignity_framing" hidden>
    <header class="iq-head">
      <div class="iq-eyebrow"><span class="pip" aria-hidden="true"></span><span>Dignity / Framing Readiness</span></div>
      <h3 id="iqDfTitle">—</h3>
      <p class="iq-sub" id="iqDfSub">—</p>
    </header>
    <div class="iq-body" id="iqDfBody"></div>
    <footer class="iq-foot"><h4 class="iq-block-h">Interpretation</h4><div class="iq-interp" id="iqDfInterp">—</div></footer>
  </section>

  <!-- Access Readiness — the second PRE-DATA validity lens. Like Dignity it
       reviews the instrument's item text (not the response dataset) against
       AI-proposed, human-settled access barriers and scores deterministically
       via AccessEngine (apps/sdsi). -->
  <section class="iq-lens" data-lens="access" hidden>
    <header class="iq-head">
      <div class="iq-eyebrow"><span class="pip" aria-hidden="true"></span><span>Access Readiness</span></div>
      <h3 id="iqAcTitle">—</h3>
      <p class="iq-sub" id="iqAcSub">—</p>
    </header>
    <div class="iq-body" id="iqAcBody"></div>
    <footer class="iq-foot"><h4 class="iq-block-h">Interpretation</h4><div class="iq-interp" id="iqAcInterp">—</div></footer>
  </section>

  <!-- The five remaining PRE-DATA Validity Readiness components share one
       engine (apps/sdsi/validity-lens-engine.js) and one generic renderer
       (renderValidityLens). Each gets a section keyed by its component id with
       IDs the renderer derives as iqV_<component>_{title,sub,body,interp}. -->
  <?php foreach ([
      'construct_definition'      => 'Construct Definition',
      'purpose_alignment'         => 'Purpose Alignment',
      'dimension_coverage'        => 'Dimension / Domain Coverage',
      'item_construct_alignment'  => 'Item-to-Construct Alignment',
      'response_option_validity'  => 'Response-Option Validity',
    ] as $vKey => $vLabel): ?>
  <section class="iq-lens" data-lens="<?= htmlspecialchars($vKey, ENT_QUOTES) ?>" hidden>
    <header class="iq-head">
      <div class="iq-eyebrow"><span class="pip" aria-hidden="true"></span><span><?= htmlspecialchars($vLabel, ENT_QUOTES) ?></span></div>
      <h3 id="iqV_<?= $vKey ?>_title">—</h3>
      <p class="iq-sub" id="iqV_<?= $vKey ?>_sub">—</p>
    </header>
    <div class="iq-body" id="iqV_<?= $vKey ?>_body"></div>
    <footer class="iq-foot"><h4 class="iq-block-h">Interpretation</h4><div class="iq-interp" id="iqV_<?= $vKey ?>_interp">—</div></footer>
  </section>
  <?php endforeach; ?>

  <!-- The five PRE-DATA Reliability Readiness components share the SAME factory
       engine (apps/sdsi/validity-lens-engine.js) and a generic renderer
       (renderReliabilityLens). Each gets a section keyed by its component id
       with IDs the renderer derives as iqR_<component>_{title,sub,body,interp}.
       ALPHA FENCE: these are pre-data design lenses — no alpha/omega/item-total/
       inter-item/factor analysis anywhere. -->
  <?php foreach ([
      'scale_structure_readiness'  => 'Scale Structure Readiness',
      'item_clarity'               => 'Item Clarity / Wording Consistency',
      'response_scale_consistency' => 'Response Scale Consistency',
      'redundancy_balance'         => 'Redundancy Balance',
      'administration_consistency' => 'Administration Consistency for Reliability',
    ] as $rKey => $rLabel): ?>
  <section class="iq-lens" data-lens="<?= htmlspecialchars($rKey, ENT_QUOTES) ?>" hidden>
    <header class="iq-head">
      <div class="iq-eyebrow"><span class="pip" aria-hidden="true"></span><span><?= htmlspecialchars($rLabel, ENT_QUOTES) ?></span></div>
      <h3 id="iqR_<?= $rKey ?>_title">—</h3>
      <p class="iq-sub" id="iqR_<?= $rKey ?>_sub">—</p>
    </header>
    <div class="iq-body" id="iqR_<?= $rKey ?>_body"></div>
    <footer class="iq-foot"><h4 class="iq-block-h">Interpretation</h4><div class="iq-interp" id="iqR_<?= $rKey ?>_interp">—</div></footer>
  </section>
  <?php endforeach; ?>

  <!-- The five PRE-LAUNCH Administration Readiness components share the SAME
       factory engine (apps/sdsi/validity-lens-engine.js) and a generic renderer
       (renderAdministrationLens). Each gets a section keyed by its component id
       with IDs the renderer derives as iqA_<component>_{title,sub,body,interp}.
       PRE-LAUNCH SCOPE: these review whether the survey is ready to be fielded
       responsibly, clearly, safely, and practically — never survey results or
       post-administration data quality (that belongs to RSSI). -->
  <?php foreach ([
      'respondent_instructions' => 'Respondent Instructions & Guidance',
      'consent_privacy'         => 'Consent, Privacy & Use Transparency',
      'fielding_plan'           => 'Fielding Plan & Timing',
      'sensitive_safety'        => 'Sensitive-Topic & Safety Readiness',
      'completion_burden'       => 'Completion Burden & Launch Logistics',
    ] as $aKey => $aLabel): ?>
  <section class="iq-lens" data-lens="<?= htmlspecialchars($aKey, ENT_QUOTES) ?>" hidden>
    <header class="iq-head">
      <div class="iq-eyebrow"><span class="pip" aria-hidden="true"></span><span><?= htmlspecialchars($aLabel, ENT_QUOTES) ?></span></div>
      <h3 id="iqA_<?= $aKey ?>_title">—</h3>
      <p class="iq-sub" id="iqA_<?= $aKey ?>_sub">—</p>
    </header>
    <div class="iq-body" id="iqA_<?= $aKey ?>_body"></div>
    <footer class="iq-foot"><h4 class="iq-block-h">Interpretation</h4><div class="iq-interp" id="iqA_<?= $aKey ?>_interp">—</div></footer>
  </section>
  <?php endforeach; ?>

</section>

<!-- DignityEngine: deterministic scoring + orthogonal launch gate for the
     dignity_framing lens. Loaded before instrument-quality.js (both defer →
     execute in document order) so window.DignityEngine is ready at render. -->
<script src="/apps/sdsi/dignity-engine.js" defer></script>
<!-- AccessEngine: deterministic scoring + orthogonal launch gate for the
     access lens. Loaded before instrument-quality.js (both defer → execute in
     document order) so window.AccessEngine is ready at render. -->
<script src="/apps/sdsi/access-engine.js" defer></script>
<!-- Shared validity-lens factory + the five component specs. Together they
     expose window.SDSI_VALIDITY_LENSES (one engine per component) and
     window.SDSI_VALIDITY_SPECS (check vocabulary + context fields), consumed by
     renderValidityLens in instrument-quality.js. Engine before specs (specs
     calls the factory at load), both before instrument-quality.js. -->
<script src="/apps/sdsi/validity-lens-engine.js" defer></script>
<script src="/apps/sdsi/validity-specs.js" defer></script>
<!-- The five Reliability Readiness specs reuse the SAME factory engine loaded
     just above. Together they expose window.SDSI_RELIABILITY_LENSES (one engine
     per component) and window.SDSI_RELIABILITY_SPECS (check vocabulary + context
     fields), consumed by renderReliabilityLens in instrument-quality.js. -->
<script src="/apps/sdsi/reliability-specs.js" defer></script>
<!-- The five Administration Readiness specs reuse the SAME factory engine loaded
     above. Together they expose window.SDSI_ADMINISTRATION_LENSES (one engine per
     component) and window.SDSI_ADMINISTRATION_SPECS (check vocabulary + context
     fields), consumed by renderAdministrationLens in instrument-quality.js. -->
<script src="/apps/sdsi/administration-specs.js" defer></script>
