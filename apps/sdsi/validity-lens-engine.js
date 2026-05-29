/* ════════════════════════════════════════════════════════════════════════
   SDSI — Validity Readiness — shared deterministic lens engine (factory)
   ────────────────────────────────────────────────────────────────────────
   Validity Readiness (50 of SDSI's 100 points) is assessed by SEVEN pre-data
   lenses, each scored on the SAME locked spine:

       Construct Definition          8
       Purpose Alignment             7
       Dimension / Domain Coverage   8
       Item-to-Construct Alignment   7
       Response-Option Validity      4
       Dignity / Framing             8   (apps/sdsi/dignity-engine.js)
       Access                        8   (apps/sdsi/access-engine.js)
       ───────────────────────────────
       Validity Readiness           50

   Dignity and Access ship as standalone engines (already built + verified).
   The five OTHER lenses share this one factory: identical scoring math, only
   the check vocabulary, the launch blockers, and the SDSI weight differ. One
   source of truth for the arithmetic; per-lens specs supply the content.

   Like the dignity/access engines, this is NOT a detector. Detection is a
   judgment act: the AI PROPOSES flags (quoted evidence + rationale + a
   suggested fix), a human SETTLES each (accept / dismiss / override severity /
   revise), and the engine computes a deterministic, traceable score plus an
   ORTHOGONAL launch gate from the settled flags.

   These are VALIDITY checks. They never use Cronbach's alpha, omega,
   item-total correlations, or any internal-consistency logic — scale length,
   redundancy, and internal consistency belong to Reliability Readiness.

   LOCKED RULES (shared spine — see project-sdsi-validity-design)
   - Per-check penalty cap = -18 (evidence preserved, penalty bounded).
   - Mitigation credits are offset-only (cannot raise above 100); total +12.
     (The five factory lenses define NO mitigations — the absence of a flag is
     already the good state, so crediting it would double-count. The mechanism
     is kept so the spine is identical to dignity/access.)
   - Launch blockers are an ORTHOGONAL gate — they never touch the number.
   - Blockers are conditions on existing checks, fired only from ACCEPTED flags.
   - Dismissed flags stay in the ledger but contribute 0.

   FORMULA  (per lens, on its own 0–100 internal score)
       Score      = 100 - cappedPenalties + mitigationCredits
       Final      = min(100, max(0, Score))
       SDSI Points= (Final / 100) * weight
   ════════════════════════════════════════════════════════════════════════ */
(function (root) {
  'use strict';

  var SEVERITY_PENALTY = { minor: -3, moderate: -6, major: -10, critical: -18 };
  var PER_CHECK_CAP = 18;
  var CREDIT_CAP    = 12;

  // Generic 0–100 bands, phrased with the lens's short name.
  function defaultBand(noun) {
    return function (s) {
      if (s >= 90) return { key: 'strong',      label: 'Strong ' + noun + ' readiness' };
      if (s >= 80) return { key: 'good',        label: 'Good — minor ' + noun + ' revisions recommended' };
      if (s >= 70) return { key: 'moderate',    label: 'Moderate ' + noun + ' risk' };
      if (s >= 60) return { key: 'significant', label: 'Significant ' + noun + ' risk' };
      return             { key: 'high',         label: 'High ' + noun + ' risk — revise before launch' };
    };
  }

  /* spec = {
       key:        'construct_definition',
       name:       'Construct Definition',      // human label
       noun:       'construct-definition',       // used in band text
       weight:     8,                            // SDSI points this lens contributes
       checks:     { check_key: 'Label', ... },  // the failure modes
       blockers:   [ { key, label, test(flag, mitigations, ctx) } ],
       mitigations:{ type: credit },             // optional; empty for the five
       itemScopedMitigations: { type: true },    // optional
       band:       fn(score)                     // optional; defaults to noun bands
       contextFields: [ { key, label, type } ]   // reviewer-declared inputs
     } */
  function makeValidityLens(spec) {
    var CHECKS            = spec.checks || {};
    var MITIGATION_CREDIT = spec.mitigations || {};
    var ITEM_SCOPED       = spec.itemScopedMitigations || {};
    var BLOCKER_DEFS      = spec.blockers || [];
    var WARNING_DEFS      = spec.warnings || [];
    var WEIGHT            = spec.weight;
    var NAME              = spec.name;
    var band              = spec.band || defaultBand(spec.noun || NAME.toLowerCase());

    function counts(flag) {
      return flag.decision === 'accepted' || flag.decision === 'severity_overridden';
    }
    function penaltyOf(flag) {
      var p = SEVERITY_PENALTY[flag.severity];
      if (p == null) throw new Error(NAME + ' lens: unknown severity "' + flag.severity + '" on flag ' + flag.flag_id);
      return p;
    }

    function score(flags, mitigations) {
      flags = flags || [];
      mitigations = mitigations || [];

      var perCheck = {};
      Object.keys(CHECKS).forEach(function (c) { perCheck[c] = { raw: 0, capped: 0, flags: [] }; });

      flags.filter(counts).forEach(function (f) {
        if (!CHECKS[f.check]) throw new Error(NAME + ' lens: unknown check "' + f.check + '" on flag ' + f.flag_id);
        perCheck[f.check].raw += penaltyOf(f);
        perCheck[f.check].flags.push(f.flag_id);
      });

      var cappedPenalty = 0;
      Object.keys(perCheck).forEach(function (c) {
        perCheck[c].capped = Math.max(perCheck[c].raw, -PER_CHECK_CAP);
        cappedPenalty += perCheck[c].capped;
      });

      var rawCredit = 0;
      mitigations.filter(function (m) { return m.decision === 'accepted'; }).forEach(function (m) {
        var cr = MITIGATION_CREDIT[m.type];
        if (cr == null) throw new Error(NAME + ' lens: unknown mitigation "' + m.type + '" on ' + m.mitigation_id);
        rawCredit += cr;
      });
      var credit = Math.min(rawCredit, CREDIT_CAP);

      var raw   = 100 + cappedPenalty + credit;
      var final = Math.min(100, Math.max(0, raw));
      var points = Math.round((final / 100) * WEIGHT * 10) / 10;

      return {
        startedAt: 100, cappedPenalty: cappedPenalty, rawCredit: rawCredit, credit: credit,
        raw: raw, final: final, sdsiPoints: points, sdsiWeight: WEIGHT, band: band(final), perCheck: perCheck
      };
    }

    function acceptedFlags(flags) { return (flags || []).filter(counts); }

    function protectedOnItem(flag, mitigations, type) {
      return (mitigations || []).some(function (m) {
        if (m.decision !== 'accepted') return false;
        if (m.type !== type) return false;
        if (!ITEM_SCOPED[m.type]) return false;
        return m.item_ref === flag.item_ref || (m.section != null && m.section === flag.section);
      });
    }

    function evaluateBlockers(flags, mitigations, context) {
      context = context || {};
      var accepted = acceptedFlags(flags);
      var fired = [];
      BLOCKER_DEFS.forEach(function (def) {
        accepted.forEach(function (f) {
          // The 5th arg (accepted) lets a blocker require a COMBINATION of
          // accepted flags (e.g. construct_unnamed AND definition_absent), not
          // just the single flag being iterated. Tests that ignore it still work.
          if (def.test(f, mitigations, context, protectedOnItem, accepted)) {
            fired.push({
              key: def.key, label: def.label, triggeredBy: f.flag_id,
              item_ref: f.item_ref, reviewed: f.blocker_reviewed === true
            });
          }
        });
      });
      return fired;
    }

    // Report-level cautions: aggregate, advisory signals computed from the
    // accepted flags + context. They NEVER touch the score and NEVER block
    // launch — they surface a caution for the report only. Each def is
    // { key, label, test(accepted, context, flags) -> bool }, evaluated once.
    function evaluateWarnings(flags, context) {
      context = context || {};
      var accepted = acceptedFlags(flags);
      var fired = [];
      WARNING_DEFS.forEach(function (def) {
        if (def.test(accepted, context, flags || [])) {
          fired.push({ key: def.key, label: def.label });
        }
      });
      return fired;
    }

    function assess(input) {
      input = input || {};
      var flags = input.flags || [];
      var mitigations = input.mitigations || [];
      // `context` carries any reviewer declarations a blocker test may read
      // (e.g. population for dignity/access-style tests). The five factory
      // lenses read only the flags, but the seam is kept uniform.
      var context = input.context || input.population || {};

      var s = score(flags, mitigations);
      var blockers = evaluateBlockers(flags, mitigations, context);
      var unreviewed = blockers.filter(function (b) { return !b.reviewed; });
      var warnings = evaluateWarnings(flags, context);

      var result = {
        score: s.final, sdsiPoints: s.sdsiPoints, sdsiWeight: s.sdsiWeight, band: s.band,
        math: { startedAt: s.startedAt, cappedPenalty: s.cappedPenalty, rawCredit: s.rawCredit, credit: s.credit, raw: s.raw, final: s.final },
        perCheck: s.perCheck,
        launchReady: unreviewed.length === 0,
        blockers: blockers,
        warnings: warnings,
        ledger: flags.map(function (f) {
          return {
            flag_id: f.flag_id, check: f.check, checkLabel: CHECKS[f.check] || f.check,
            item_ref: f.item_ref, quote: f.quote, severity: f.severity,
            penalty: counts(f) ? penaltyOf(f) : 0, rationale: f.rationale,
            suggested_revision: f.suggested_revision, decision: f.decision, counted: counts(f)
          };
        }),
        mitigations: mitigations
      };

      if (result.score !== s.final || result.sdsiPoints !== s.sdsiPoints) {
        throw new Error(NAME + ' lens: launch gate altered the score — orthogonality violated.');
      }
      return result;
    }

    return {
      key: spec.key, name: NAME, contextFields: spec.contextFields || [],
      assess: assess, score: score, evaluateBlockers: evaluateBlockers, evaluateWarnings: evaluateWarnings, band: band,
      CHECKS: CHECKS, SEVERITY_PENALTY: SEVERITY_PENALTY, MITIGATION_CREDIT: MITIGATION_CREDIT,
      PER_CHECK_CAP: PER_CHECK_CAP, CREDIT_CAP: CREDIT_CAP, SDSI_WEIGHT: WEIGHT
    };
  }

  root.ValidityLens = {
    make: makeValidityLens,
    SEVERITY_PENALTY: SEVERITY_PENALTY,
    PER_CHECK_CAP: PER_CHECK_CAP,
    CREDIT_CAP: CREDIT_CAP
  };
})(typeof window !== 'undefined' ? window : this);

if (typeof module !== 'undefined' && module.exports) {
  module.exports = (typeof window !== 'undefined' ? window : this).ValidityLens;
}
