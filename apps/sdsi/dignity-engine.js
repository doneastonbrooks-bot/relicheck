/* ════════════════════════════════════════════════════════════════════════
   SDSI — Dignity / Framing Readiness — deterministic scoring engine
   ────────────────────────────────────────────────────────────────────────
   This engine does NOT detect flags. Detection is a judgment act: the AI
   PROPOSES flags (with quoted evidence + rationale), a human SETTLES each one
   (accept / dismiss / override severity / revise). This module takes the
   SETTLED flags + settled mitigations + a small set of population facts and
   computes a fully deterministic, traceable score and an orthogonal launch
   gate. Same settled inputs in → same number out, every time.

   Lives inside Validity Readiness (SDSI). The Dignity/Framing subdomain is
   worth 8 of Validity Readiness's 50 SDSI points.

   LOCKED RULES (see project memory: project-sdsi-validity-design)
   - Score once, surface twice: dignity penalties live ONLY here.
   - Per-check penalty cap = -18 (evidence preserved, penalty bounded).
   - Mitigation credits are offset-only (cannot raise above 100); total +12.
   - Launch blockers are an ORTHOGONAL gate — they never touch the number;
     they block a launch-ready verdict until a human reviews them.
   - Blockers are conditions on existing checks, fired only from ACCEPTED
     flags; dismissing a flag clears any blocker that depended on it.
   - Mitigations that clear blockers must be item- or section-scoped to the
     triggering item; a global skip/purpose statement cannot clear a blocker
     on a specific sensitive item.
   - Dismissed flags stay in the ledger but contribute 0. Accepted and
     severity-overridden flags count toward penalties.

   FORMULA
       Dignity Score = 100 - cappedPenalties + mitigationCredits
       Final         = min(100, max(0, Dignity Score))
       SDSI Points   = (Final / 100) * 8
   ════════════════════════════════════════════════════════════════════════ */
(function (root) {
  'use strict';

  /* ── The six checks. Each flag's `check` MUST be one of these. ── */
  var CHECKS = {
    deficit_framing:      'Deficit-vs-asset framing',
    othering_labeling:    'Othering / labeling',
    identity_erasure:     'Identity erasure in response options',
    extractive_disclosure:'Extractive disclosure',
    embedded_stereotype:  'Embedded stereotype',
    judging_respondent:   'Judging the respondent'
  };

  /* ── Severity → penalty. Penalty is DERIVED from severity, never free. ── */
  var SEVERITY_PENALTY = { minor: -3, moderate: -6, major: -10, critical: -18 };

  /* ── Mitigation type → credit. Some are item-scoped (can clear blockers),
        some are survey-scoped (global design only). ── */
  var MITIGATION_CREDIT = {
    clear_purpose:      3,   // item/section-scoped
    decline_option:     3,   // item/section-scoped
    resource_framing:   3,   // item/section-scoped
    community_language: 2,   // survey-scoped (global)
    neutral_wording:    2,   // survey-scoped (global)
    multiselect_writein:3    // item-scoped on a demographic item, or global design
  };

  /* Mitigations that are eligible to CLEAR a blocker only when attached to the
     same item/section as the triggering flag. */
  var ITEM_SCOPED_MITIGATIONS = {
    clear_purpose: true, decline_option: true,
    resource_framing: true, multiselect_writein: true
  };

  var PER_CHECK_CAP = 18;   // max penalty magnitude per check
  var CREDIT_CAP    = 12;   // max total mitigation credit
  var SDSI_WEIGHT   = 8;    // points this subdomain contributes to Validity Readiness

  /* ── Interpretation bands for the 0–100 dignity score. ── */
  function band(score) {
    if (score >= 90) return { key: 'strong',      label: 'Strong dignity/framing readiness' };
    if (score >= 80) return { key: 'good',        label: 'Good — minor wording revisions recommended' };
    if (score >= 70) return { key: 'moderate',    label: 'Moderate dignity/framing risk' };
    if (score >= 60) return { key: 'significant', label: 'Significant framing risk' };
    return                  { key: 'high',         label: 'High dignity/framing risk — revise before launch' };
  }

  /* ── A settled flag counts toward penalties only if accepted/overridden. ── */
  function counts(flag) {
    return flag.decision === 'accepted' || flag.decision === 'severity_overridden';
  }

  /* Resolve the penalty for a settled flag from its (possibly overridden)
     severity — never trust a hand-entered penalty field. */
  function penaltyOf(flag) {
    var p = SEVERITY_PENALTY[flag.severity];
    if (p == null) throw new Error('Dignity engine: unknown severity "' + flag.severity + '" on flag ' + flag.flag_id);
    return p;
  }

  /* ════════════════════════════════════════════════════════════════════
     SCORING — deterministic from settled flags + settled mitigations.
     ════════════════════════════════════════════════════════════════════ */
  function score(flags, mitigations) {
    flags = flags || [];
    mitigations = mitigations || [];

    // Group counting flags by check, summing penalties, then cap per check.
    var perCheck = {};
    Object.keys(CHECKS).forEach(function (c) { perCheck[c] = { raw: 0, capped: 0, flags: [] }; });

    flags.filter(counts).forEach(function (f) {
      if (!CHECKS[f.check]) throw new Error('Dignity engine: unknown check "' + f.check + '" on flag ' + f.flag_id);
      perCheck[f.check].raw += penaltyOf(f);     // negative
      perCheck[f.check].flags.push(f.flag_id);
    });

    var cappedPenalty = 0;   // negative number
    Object.keys(perCheck).forEach(function (c) {
      // raw is <= 0; bound its magnitude at PER_CHECK_CAP.
      perCheck[c].capped = Math.max(perCheck[c].raw, -PER_CHECK_CAP);
      cappedPenalty += perCheck[c].capped;
    });

    // Mitigation credits: accepted only, summed, then capped at +12.
    var rawCredit = 0;
    mitigations.filter(function (m) { return m.decision === 'accepted'; }).forEach(function (m) {
      var cr = MITIGATION_CREDIT[m.type];
      if (cr == null) throw new Error('Dignity engine: unknown mitigation "' + m.type + '" on ' + m.mitigation_id);
      rawCredit += cr;
    });
    var credit = Math.min(rawCredit, CREDIT_CAP);

    var raw   = 100 + cappedPenalty + credit;      // cappedPenalty is negative
    var final = Math.min(100, Math.max(0, raw));
    var points = Math.round((final / 100) * SDSI_WEIGHT * 10) / 10;   // 1 decimal

    return {
      startedAt: 100,
      cappedPenalty: cappedPenalty,          // e.g. -44
      rawCredit: rawCredit,
      credit: credit,                        // capped
      raw: raw,
      final: final,                          // 0–100
      sdsiPoints: points,                    // x/8, one decimal
      sdsiWeight: SDSI_WEIGHT,
      band: band(final),
      perCheck: perCheck                     // audit: raw + capped per check
    };
  }

  /* ════════════════════════════════════════════════════════════════════
     BLOCKERS — orthogonal gate. Conditions on ACCEPTED flags + population.
     Each returns true to fire. Never reads the score.

     population = {
       minors: bool,            // are respondents minors?
       peopleFacing: bool,      // people-facing (vs staff/admin-only)?
       communities: [..]        // calibration only; not read here
     }
     A flag is "protected on its own item/section" when an item-scoped
     mitigation of the required type targets the same item_ref/section.
     ════════════════════════════════════════════════════════════════════ */

  function acceptedFlags(flags) { return (flags || []).filter(counts); }

  // Is there an accepted, item/section-scoped mitigation of `type` attached to
  // the same item or section as `flag`?
  function protectedOnItem(flag, mitigations, type) {
    return (mitigations || []).some(function (m) {
      if (m.decision !== 'accepted') return false;
      if (m.type !== type) return false;
      if (!ITEM_SCOPED_MITIGATIONS[m.type]) return false;   // must be item-scoped to clear
      return m.item_ref === flag.item_ref ||
             (m.section != null && m.section === flag.section);
    });
  }

  var BLOCKER_DEFS = [
    {
      key: 'minor_sensitive_disclosure',
      label: 'Sensitive disclosure from minors without clear purpose or decline path',
      test: function (f, mit, pop) {
        return f.check === 'extractive_disclosure' && pop.minors &&
          !protectedOnItem(f, mit, 'decline_option') &&
          !protectedOnItem(f, mit, 'clear_purpose');
      }
    },
    {
      key: 'unguarded_legal_status',
      label: 'Immigration/legal status asked without explicit purpose and skip option',
      test: function (f, mit, pop) {
        return f.check === 'extractive_disclosure' && f.topic === 'legal_status' &&
          (!protectedOnItem(f, mit, 'clear_purpose') || !protectedOnItem(f, mit, 'decline_option'));
      }
    },
    {
      key: 'forced_binary_gender',
      label: 'Forced binary/required gender with no self-describe or decline (identity erasure)',
      test: function (f, mit, pop) {
        return f.check === 'identity_erasure' && f.topic === 'gender' &&
          f.required === true && pop.peopleFacing &&
          !protectedOnItem(f, mit, 'multiselect_writein') &&
          !protectedOnItem(f, mit, 'decline_option');
      }
    },
    {
      key: 'forced_misclassification',
      label: 'Race/ethnicity categories that force misclassification',
      test: function (f, mit, pop) {
        return f.check === 'identity_erasure' &&
          (f.topic === 'race' || f.topic === 'ethnicity') &&
          !protectedOnItem(f, mit, 'multiselect_writein');
      }
    },
    {
      key: 'disability_as_deficit',
      label: 'Disability categories worded as deficits or abnormalities',
      test: function (f, mit, pop) {
        return (f.check === 'deficit_framing' || f.check === 'embedded_stereotype') &&
          f.topic === 'disability';
      }
    },
    {
      key: 'group_stereotype',
      label: 'Stereotype-based wording tied to a protected/at-risk group',
      test: function (f, mit, pop) {
        var topics = { race:1, ethnicity:1, income:1, language:1, disability:1, gender:1,
                       legal_status:1, family_structure:1, housing:1, special_education:1 };
        return f.check === 'embedded_stereotype' && !!topics[f.topic];
      }
    }
  ];

  function evaluateBlockers(flags, mitigations, population) {
    population = population || {};
    var accepted = acceptedFlags(flags);
    var fired = [];
    BLOCKER_DEFS.forEach(function (def) {
      accepted.forEach(function (f) {
        if (def.test(f, mitigations, population)) {
          fired.push({
            key: def.key,
            label: def.label,
            triggeredBy: f.flag_id,
            item_ref: f.item_ref,
            reviewed: f.blocker_reviewed === true
          });
        }
      });
    });
    return fired;
  }

  /* ════════════════════════════════════════════════════════════════════
     ASSESS — the full result: score + ledger + orthogonal gate.
     ════════════════════════════════════════════════════════════════════ */
  function assess(input) {
    input = input || {};
    var flags = input.flags || [];
    var mitigations = input.mitigations || [];
    var population = input.population || {};

    var s = score(flags, mitigations);
    var blockers = evaluateBlockers(flags, mitigations, population);
    var unreviewedBlockers = blockers.filter(function (b) { return !b.reviewed; });

    var result = {
      score: s.final,
      sdsiPoints: s.sdsiPoints,
      sdsiWeight: s.sdsiWeight,
      band: s.band,
      math: {                                 // visible arithmetic for the report
        startedAt: s.startedAt,
        cappedPenalty: s.cappedPenalty,
        rawCredit: s.rawCredit,
        credit: s.credit,
        raw: s.raw,
        final: s.final
      },
      perCheck: s.perCheck,
      // The gate is orthogonal: it never alters score/sdsiPoints.
      launchReady: unreviewedBlockers.length === 0,
      blockers: blockers,
      // The full evidence ledger — every flag, INCLUDING dismissed (contribute 0).
      ledger: flags.map(function (f) {
        return {
          flag_id: f.flag_id,
          check: f.check,
          checkLabel: CHECKS[f.check] || f.check,
          item_ref: f.item_ref,
          quote: f.quote,
          severity: f.severity,
          penalty: counts(f) ? penaltyOf(f) : 0,
          rationale: f.rationale,
          suggested_revision: f.suggested_revision,
          decision: f.decision,
          counted: counts(f)
        };
      }),
      mitigations: mitigations
    };

    assertOrthogonal(result, s);
    return result;
  }

  /* The gate must never have moved the number. If a blocker fired but the
     score differs from a score computed ignoring blockers, the orthogonality
     guarantee regressed. (Score is computed without any blocker input, so this
     is structurally true — asserted to catch future refactors.) */
  function assertOrthogonal(result, s) {
    if (result.score !== s.final || result.sdsiPoints !== s.sdsiPoints) {
      throw new Error('Dignity engine: launch gate altered the score — orthogonality violated.');
    }
  }

  root.DignityEngine = {
    assess: assess,
    score: score,
    evaluateBlockers: evaluateBlockers,
    band: band,
    CHECKS: CHECKS,
    SEVERITY_PENALTY: SEVERITY_PENALTY,
    MITIGATION_CREDIT: MITIGATION_CREDIT,
    PER_CHECK_CAP: PER_CHECK_CAP,
    CREDIT_CAP: CREDIT_CAP,
    SDSI_WEIGHT: SDSI_WEIGHT
  };
})(typeof window !== 'undefined' ? window : this);

if (typeof module !== 'undefined' && module.exports) {
  module.exports = (typeof window !== 'undefined' ? window : this).DignityEngine;
}
