/* ════════════════════════════════════════════════════════════════════════
   SDSI — Administration Readiness — the five factory-lens specifications
   ────────────────────────────────────────────────────────────────────────
   Administration Readiness is 15 of SDSI's 100 points (Validity Readiness 50 +
   Reliability Readiness 35 + Administration Readiness 15 = 100). It is a
   PRE-LAUNCH review: it asks whether the survey is ready to be fielded
   RESPONSIBLY, CLEARLY, SAFELY, and PRACTICALLY in the real world — before any
   data is collected. It shares the same deterministic factory as the validity
   and reliability lenses (validity-lens-engine.js); only the check vocabulary,
   blockers, and SDSI weight differ.

   ╔══════════════════════════════════════════════════════════════════════╗
   ║  BOUNDARY — Administration READINESS ≠ Administration CONSISTENCY.     ║
   ║  Administration Consistency for Reliability already lives inside       ║
   ║  Reliability Readiness; it asks whether administration conditions      ║
   ║  support STABLE responses and reliable interpretation. Administration  ║
   ║  READINESS (here) asks whether the survey is ready to LAUNCH in the    ║
   ║  real world: guidance, transparency, safety, and logistics.            ║
   ╚══════════════════════════════════════════════════════════════════════╝

   Five lenses (sum = 15 = Administration Readiness):
     respondent_instructions   4 · consent_privacy           4 ·
     fielding_plan             3 · sensitive_safety          2 ·
     completion_burden         2

   SCORE ONCE, SURFACE TWICE: the Sensitive-Topic & Safety lens can surface
   Dignity/Framing or Access concerns, but it must NEVER double-subtract for an
   issue already scored under Validity Readiness. The factory only scores a
   lens's OWN checks, so a Dignity/Access problem lowers the score in its home
   lens; here it is surfaced (and may raise a safety blocker) without a second
   penalty.

   BLOCKERS ARE PROVISIONAL SHELLS. Per the build brief, blockers are kept
   sparing and will be revised one lens at a time (like Validity & Reliability).
   The four wired here are conservative and context-gated so they can be cleared:
     • missing_consent_or_required_status     (Consent, Privacy & Use)
     • no_clear_target_population              (Fielding Plan & Timing)
     • launch_plan_missing_for_required_survey (Fielding Plan & Timing)
     • unsafe_sensitive_disclosure            (Sensitive-Topic & Safety)

   EXPLANATORY NOTES (verbatim — to be surfaced by the aggregator/render when it
   is built; the aggregator is intentionally NOT built until the five
   vocabularies are reviewed and locked):
     "Administration Readiness is a pre-launch review of whether the survey is
      ready to be fielded responsibly. It does not evaluate survey results. It
      checks whether respondents have enough guidance, transparency, safety, and
      logistical clarity to complete the survey appropriately."
     "SDSI evaluates survey design strength before launch."
     "RSSI evaluates survey performance after data collection."

   Warnings are intentionally left empty on every lens for now — advisory
   caution vocabulary will be added during the one-lens-at-a-time revision.
   ════════════════════════════════════════════════════════════════════════ */
(function (root) {
  'use strict';

  var SPECS = {

    // ── 1. Respondent Instructions & Guidance (4) ───────────────────────
    // Do respondents know what the survey is for, how to complete it, what
    // timeframe to use, and what kind of answers are expected?
    respondent_instructions: {
      key: 'respondent_instructions', name: 'Respondent Instructions & Guidance', noun: 'instructions', weight: 4,
      intro: 'Do respondents know what the survey is for, how to complete it, what timeframe to use, and what kind of answers are expected? This is a pre-launch readiness check on the guidance respondents receive — not a check of construct fit (Validity) or response consistency (Reliability). It asks whether the instructions, purpose statement, completion expectations, timeframe guidance, section transitions, and respondent role are clear enough to complete the survey appropriately.',
      reviewerPrompt: 'Before scoring this lens, review the respondent-facing instructions. Determine whether respondents are clearly told why they are taking the survey, how to complete it, what timeframe to use, what role or perspective to answer from, and how to move through the survey. Do not evaluate consent, privacy, fielding logistics, sensitive-topic safeguards, or psychometric validity in this lens.',
      checks: {
        missing_instructions:            'No opening instructions, or instructions too thin to complete the survey',
        unclear_purpose_for_respondents: 'Survey purpose not explained in plain respondent-facing language',
        unclear_completion_expectations: 'What a complete/expected response looks like is unclear',
        unclear_timeframe_guidance:      'Answering timeframe guidance is missing or unclear',
        unclear_section_transitions:     'Transitions between sections are missing or unclear',
        unclear_respondent_role:         'Respondent role or perspective to answer from is unclear'
      },
      checkDesc: {
        missing_instructions:            'the survey provides no opening instructions, or the instructions are too thin for respondents to know how to complete the survey.',
        unclear_purpose_for_respondents: 'the survey does not explain the purpose of the survey in plain, respondent-facing language — why respondents are being asked to take it. (This is NOT the researcher/construct purpose, which belongs to Validity Readiness.)',
        unclear_completion_expectations: 'respondents cannot tell what kind, amount, or format of response is expected (e.g. select one vs. select all, short vs. detailed).',
        unclear_timeframe_guidance:      'the survey does not tell respondents what time period to use when answering, where a reference window is needed. (Inconsistent timeframes across sections that affect score comparability belong to Administration Consistency for Reliability.)',
        unclear_section_transitions:     'the survey moves between sections, topics, or item types without enough orientation for respondents.',
        unclear_respondent_role:         'the survey does not make clear what role or perspective the respondent should answer from (self, parent/guardian, teacher, staff, student, observer). (Role ambiguity that creates inconsistent response conditions across groups/versions belongs to Administration Consistency for Reliability.)'
      },
      severityHints: {
        missing_instructions: 'major', unclear_purpose_for_respondents: 'moderate', unclear_completion_expectations: 'moderate',
        unclear_timeframe_guidance: 'moderate', unclear_section_transitions: 'minor', unclear_respondent_role: 'moderate'
      },
      severityGuidance:
        'Use minor when the guidance issue is localized and unlikely to affect completion. Use moderate when the issue may cause respondents to answer inconsistently or with uncertainty. Use major when the missing or unclear guidance affects the whole survey or a required section. Reserve critical only when respondents cannot reasonably know what they are being asked to do in a high-stakes or required survey.',
      contextFields: [
        { key: 'purpose',            label: 'Stated survey purpose (as respondents would see it)', type: 'textarea' },
        { key: 'respondents',        label: 'Intended respondent group',          type: 'text' },
        { key: 'respondent_role',    label: 'Role/perspective respondents answer from', type: 'text' },
        { key: 'reference_timeframe',label: 'Reference timeframe for answering',   type: 'text' },
        { key: 'has_sections',       label: 'Does the survey have multiple sections?', type: 'select',
          options: ['yes', 'no'] },
        { key: 'has_instruction_block', label: 'Does the survey have an opening instruction block?', type: 'select',
          options: ['yes', 'no'] },
        { key: 'survey_required',    label: 'Is completing this survey required?', type: 'select',
          options: ['yes', 'no'] },
        { key: 'stakes',             label: 'Stakes of the decisions the results will drive', type: 'select',
          options: ['low', 'moderate', 'high'] },
        { key: 'estimated_completion_time', label: 'Estimated completion time (if stated)', type: 'text' },
        { key: 'item_count',         label: 'Number of items reviewed',            type: 'number' }
      ],
      // PROVISIONAL blocker. Most instruction issues only lower the score. But if
      // the survey has NO instruction block at all and respondents cannot
      // reasonably know what they are being asked to do, block launch readiness
      // until corrected. Orthogonal, score-neutral, accepted-flags-only,
      // verdict-only. Gated to moderate/high stakes OR a required survey.
      blockers: [
        { key: 'instructions_absent',
          label: 'No instruction block at all on a required or moderate/high-stakes survey — respondents cannot reasonably know what they are being asked to do; not launch-ready until instructions are added',
          test: function (f, mitigations, context) {
            if (f.check !== 'missing_instructions') return false;
            if (!context || context.has_instruction_block !== 'no') return false;
            var elevatedStakes = context.stakes === 'moderate' || context.stakes === 'high';
            var required = context.survey_required === 'yes';
            return elevatedStakes || required;
          } }
      ],
      warnings: [],
      sample: {
        title: 'Sample: staff climate survey with a thin instruction block',
        context: {
          purpose: 'Understand staff experience of school climate.',
          respondents: 'School staff', respondent_role: 'Self',
          reference_timeframe: '', has_sections: 'yes', has_instruction_block: 'yes',
          survey_required: 'no', stakes: 'moderate', estimated_completion_time: '', item_count: 18
        },
        flags: [
          { check: 'unclear_timeframe_guidance', item_ref: 'intro',
            quote: 'No answering timeframe is given for the climate items',
            severity: 'moderate',
            rationale: 'Without a stated timeframe, respondents may answer about different periods, but more importantly they are not guided on what window to consider — a readiness gap before launch.',
            suggested_revision: 'Add a timeframe to the section instructions (e.g. “Think about this school year so far”).' },
          { check: 'unclear_section_transitions', item_ref: 'section2',
            quote: 'The survey jumps from workload items to leadership items with no heading',
            severity: 'moderate',
            rationale: 'Respondents are not oriented when the topic shifts, which can cause confusion about what each block is asking.',
            suggested_revision: 'Add a short transition heading and one line of context at the start of each section.' }
        ]
      }
    },

    // ── 2. Consent, Privacy & Use Transparency (4) ──────────────────────
    // Are respondents told how responses will be used, whether participation is
    // voluntary, and how privacy/confidentiality is handled?
    consent_privacy: {
      key: 'consent_privacy', name: 'Consent, Privacy & Use Transparency', noun: 'consent', weight: 4,
      intro: 'Are respondents clearly told whether participation is voluntary, required, expected, or unstated; how their responses will be used; who may see individual responses or summary results; and whether privacy/confidentiality claims match the data collected? This pre-launch lens flags TRANSPARENCY READINESS RISKS — not legal, IRB, FERPA, COPPA, HIPAA, district-policy, or employment-law compliance. Use the language of transparency gap, readiness risk, and review needed — never compliant/noncompliant.',
      reviewerPrompt: 'Before scoring this lens, review the respondent-facing participation, privacy, confidentiality/anonymity, and data-use language. Determine whether respondents can understand whether participation is voluntary, required, expected, or unstated; how their responses will be used; who may see individual responses or summary results; and whether privacy claims match the information collected. This lens flags transparency readiness risks, not legal or IRB compliance.',
      checks: {
        missing_participation_statement: 'Missing respondent-facing participation statement',
        unclear_participation_status:    'Voluntary / required / expected status is unclear',
        unclear_confidentiality_anonymity:'Anonymity / confidentiality / identifiability is unclear',
        overpromised_privacy:            'Privacy claim may not match the data collected (transparency risk)',
        unclear_use_of_results:          'How results will be used is unclear',
        unclear_data_audience:           'Who may see individual responses or summary results is unclear',
        missing_guardian_language:       'Parent/guardian language missing when relevant'
      },
      checkDesc: {
        missing_participation_statement: 'the survey does not provide a respondent-facing participation statement explaining what respondents are being asked to do. (This is a transparency readiness gap, not a determination of legal consent.)',
        unclear_participation_status:    'the survey does not clearly state whether participation is voluntary, required, expected, or otherwise requested.',
        unclear_confidentiality_anonymity:'the survey does not clearly explain whether responses are anonymous, confidential, identifiable, or reported only in aggregate.',
        overpromised_privacy:            'the survey promises anonymity, confidentiality, or privacy protections that may not match the data collected, reporting design, login state, or respondent-identifiability risk (e.g. claims "anonymous" while collecting name, email, student ID, authenticated login, or small-group demographics).',
        unclear_use_of_results:          'the survey does not clearly explain how responses or results will be used.',
        unclear_data_audience:           'the survey does not clearly explain who may see individual responses, identifiable data, aggregate results, or reports.',
        missing_guardian_language:       'parent/guardian notification, consent, or explanation language is missing where minors or K-12 family/student administration make it relevant.'
      },
      severityHints: {
        missing_participation_statement: 'major', unclear_participation_status: 'major', unclear_confidentiality_anonymity: 'major',
        overpromised_privacy: 'critical', unclear_use_of_results: 'moderate', unclear_data_audience: 'moderate', missing_guardian_language: 'major'
      },
      severityGuidance:
        'Use minor when the transparency issue is localized and unlikely to affect respondent understanding. Use moderate when respondents may lack important information about use, audience, or reporting. Use major when respondents cannot clearly understand participation expectations, privacy status, or guardian/minor-related protections. Use critical when the survey makes a privacy promise that appears inaccurate or misleading, especially when individual, sensitive, or small-group data may be identifiable.',
      contextFields: [
        { key: 'respondent_type', label: 'Respondent type', type: 'select',
          options: ['students', 'families', 'staff', 'mixed'] },
        { key: 'participation_status', label: 'Participation status', type: 'select',
          options: ['voluntary', 'required', 'expected', 'unstated'] },
        { key: 'privacy_status', label: 'Privacy/identifiability handling', type: 'select',
          options: ['anonymous', 'confidential', 'identifiable', 'aggregate_only', 'unstated'] },
        { key: 'results_audience', label: 'Who will see the results', type: 'text' },
        { key: 'involves_minors', label: 'Does the survey involve minors?', type: 'select',
          options: ['yes', 'no'] },
        { key: 'consent_obtained_elsewhere', label: 'Is consent handled through a separate, documented process?', type: 'select',
          options: ['yes', 'no'] },
        { key: 'stakes', label: 'Stakes of the decisions the results will drive', type: 'select',
          options: ['low', 'moderate', 'high'] },
        { key: 'collects_identifiers', label: 'Does the survey collect identifiers (name, email, ID, login)?', type: 'select',
          options: ['yes', 'no'] },
        { key: 'collects_sensitive_information', label: 'Does the survey collect sensitive information?', type: 'select',
          options: ['yes', 'no'] },
        { key: 'small_group_reporting_risk', label: 'Is there a small-group reporting/identifiability risk?', type: 'select',
          options: ['yes', 'no'] },
        { key: 'guardian_language_needed', label: 'Is parent/guardian language needed?', type: 'select',
          options: ['yes', 'no'] },
        { key: 'guardian_language_present', label: 'Is parent/guardian language present?', type: 'select',
          options: ['yes', 'no'] },
        { key: 'data_use_statement_present', label: 'Is a data-use statement present?', type: 'select',
          options: ['yes', 'no'] },
        { key: 'individual_response_access', label: 'Who can access individual responses', type: 'text' },
        { key: 'aggregate_results_audience', label: 'Audience for aggregate results', type: 'text' }
      ],
      // PROVISIONAL blocker (legacy/internal key retained to avoid refactoring).
      // A survey should not be marked ready to launch if respondents have no
      // participation statement, cannot tell whether participation is required or
      // expected, or are given a privacy claim that may be inaccurate. Orthogonal,
      // score-neutral, accepted-flags-only, verdict-only.
      blockers: [
        { key: 'missing_consent_or_required_status',
          label: 'No participation statement, unclear required/expected status, or a privacy claim that may be inaccurate — review needed before this survey is launch-ready',
          test: function (f, mitigations, context) {
            if (!context) return false;
            var elevatedStakes = context.stakes === 'moderate' || context.stakes === 'high';
            // Missing/unclear participation: required, expected, or moderate/high stakes.
            if (f.check === 'missing_participation_statement' || f.check === 'unclear_participation_status') {
              if (context.consent_obtained_elsewhere === 'yes') return false;
              var requiredOrExpected = context.participation_status === 'required' || context.participation_status === 'expected';
              return requiredOrExpected || elevatedStakes;
            }
            // Overpromised privacy: moderate/high stakes OR identifiable/sensitive/minor data.
            if (f.check === 'overpromised_privacy') {
              var identifiable = context.collects_identifiers === 'yes'
                || context.collects_sensitive_information === 'yes'
                || context.involves_minors === 'yes';
              return elevatedStakes || identifiable;
            }
            return false;
          } }
      ],
      warnings: [],
      sample: {
        title: 'Sample: staff survey missing a participation statement and a use statement',
        context: {
          respondent_type: 'staff', participation_status: 'unstated', privacy_status: 'confidential',
          results_audience: 'District leadership', involves_minors: 'no',
          consent_obtained_elsewhere: 'no', stakes: 'moderate',
          collects_identifiers: 'no', collects_sensitive_information: 'no', small_group_reporting_risk: 'no',
          guardian_language_needed: 'no', guardian_language_present: 'no', data_use_statement_present: 'no',
          individual_response_access: '', aggregate_results_audience: 'District leadership'
        },
        flags: [
          { check: 'missing_participation_statement', item_ref: 'intro',
            quote: 'The survey opens directly with the first item; there is no participation statement',
            severity: 'major',
            rationale: 'Respondents are not given a statement explaining what they are being asked to do — a transparency readiness gap before launch.',
            suggested_revision: 'Add a brief participation statement that states the purpose, participation status, and how responses will be handled.' },
          { check: 'unclear_use_of_results', item_ref: 'intro',
            quote: 'Nothing tells staff how the results will be used',
            severity: 'moderate',
            rationale: 'Respondents are not told how their answers will be used, reducing transparency at launch.',
            suggested_revision: 'State plainly how results will be used and at what level they will be reported.' }
        ]
      }
    },

    // ── 3. Fielding Plan & Timing (3) ───────────────────────────────────
    // Does the survey have a practical launch plan?
    fielding_plan: {
      key: 'fielding_plan', name: 'Fielding Plan & Timing', noun: 'fielding', weight: 3,
      intro: 'Can this survey actually be launched and managed as intended — delivered to the right people, at the right time, through the right channel, with clear ownership and follow-up? This pre-launch lens is PRACTICAL and OPERATIONAL: target population, delivery channel, launch window, close date, distribution ownership, reminder/follow-up plan, and major launch dependencies. It does not evaluate psychometric validity, reliability consistency, consent/privacy transparency, sensitive-topic safeguards, or post-data quality.',
      reviewerPrompt: 'Before scoring this lens, review whether the survey has a practical fielding plan. Determine whether the intended respondents, delivery channel, launch window, close date, distribution owner, reminder/follow-up plan, and major launch dependencies are clear enough for the survey to be administered as intended.',
      checks: {
        unclear_target_population:           'Target population is missing or unclear',
        unclear_delivery_channel:            'Delivery channel is missing or unclear',
        unclear_launch_window:               'Launch window is missing or unclear',
        unclear_close_date:                  'Close date or completion window is missing or unclear',
        unclear_distribution_responsibility: 'Responsibility for distribution is unclear',
        unclear_reminder_followup_plan:      'Reminder / follow-up / nonresponse plan is missing or unclear',
        major_logistics_gap:                 'Major operational gap could prevent administration (narrow catch-all)'
      },
      checkDesc: {
        unclear_target_population:           'the intended respondents are not clearly identified. (This is fielding clarity, NOT statistical sample representativeness, which belongs after fielding or to a separate sampling/recruitment analysis.)',
        unclear_delivery_channel:            'the method for delivering the survey (email, text, paper, LMS, QR code, in-class, etc.) is missing or unclear.',
        unclear_launch_window:               'the date or window for opening the survey is missing or unclear.',
        unclear_close_date:                  'the deadline, close date, or completion window is missing or unclear.',
        unclear_distribution_responsibility: 'the person, role, office, or team responsible for distributing the survey is unclear.',
        unclear_reminder_followup_plan:      'the plan for reminders, follow-up, or nonresponse outreach is missing or unclear. (If reminder language pressures or coerces respondents, surface that under Consent/Transparency or Sensitive-Topic/Safety instead.)',
        major_logistics_gap:                 'a significant operational gap could prevent the survey from being launched, distributed, completed, or monitored as intended, and the issue does not fit a more specific fielding check. Use sparingly.'
      },
      severityHints: {
        unclear_target_population: 'major', unclear_delivery_channel: 'moderate', unclear_launch_window: 'moderate',
        unclear_close_date: 'moderate', unclear_distribution_responsibility: 'moderate',
        unclear_reminder_followup_plan: 'minor', major_logistics_gap: 'major'
      },
      severityGuidance:
        'Use minor when the fielding issue is localized and unlikely to affect launch. Use moderate when the issue may reduce participation, create confusion, or weaken fielding control. Use major when the issue could prevent the survey from reaching the intended respondents or being administered as intended. Reserve critical only when a high-stakes or required survey cannot responsibly launch because of a severe operational gap.',
      contextFields: [
        { key: 'target_population',    label: 'Target population',                type: 'text' },
        { key: 'launch_window',        label: 'Planned launch window',            type: 'text' },
        { key: 'close_date',           label: 'Planned close date',               type: 'text' },
        { key: 'delivery_channel',     label: 'Delivery channel',                 type: 'text' },
        { key: 'reminder_plan',        label: 'Reminder plan',                    type: 'textarea' },
        { key: 'distribution_owner',   label: 'Who is responsible for distribution', type: 'text' },
        { key: 'participation_status', label: 'Participation status',             type: 'select',
          options: ['voluntary', 'required', 'expected', 'unstated'] },
        { key: 'stakes',               label: 'Stakes of the decisions the results will drive', type: 'select',
          options: ['low', 'moderate', 'high'] },
        { key: 'followup_owner',       label: 'Who is responsible for follow-up', type: 'text' },
        { key: 'nonresponse_plan',     label: 'Nonresponse / follow-up plan',     type: 'textarea' },
        { key: 'monitoring_plan',      label: 'How fielding will be monitored',   type: 'textarea' },
        { key: 'delivery_tested',      label: 'Has the delivery channel been tested?', type: 'select',
          options: ['yes', 'no'] },
        { key: 'launch_dependencies',  label: 'Major launch dependencies',        type: 'textarea' }
      ],
      // PROVISIONAL blockers. no_clear_target_population: a survey cannot launch
      // without knowing who it is for (cleared when a target population is
      // declared). launch_plan_missing_for_required_survey: a required OR expected
      // survey with no open/close plan, or a major operational gap, should not be
      // marked launch-ready (cleared when voluntary/unstated).
      blockers: [
        { key: 'no_clear_target_population',
          label: 'No clear target population — the survey cannot be fielded until the intended respondents are identified',
          test: function (f, mitigations, context) {
            if (f.check !== 'unclear_target_population') return false;
            var declared = context && typeof context.target_population === 'string' && context.target_population.trim() !== '';
            return !declared;
          } },
        { key: 'launch_plan_missing_for_required_survey',
          label: 'A required or expected survey has no open/close plan or a major operational gap — review needed before it is launch-ready',
          test: function (f, mitigations, context) {
            if (f.check !== 'unclear_launch_window' && f.check !== 'unclear_close_date' && f.check !== 'major_logistics_gap') return false;
            return !!(context && (context.participation_status === 'required' || context.participation_status === 'expected'));
          } }
      ],
      warnings: [],
      sample: {
        title: 'Sample: required family survey with no named audience and a thin follow-up plan',
        context: {
          target_population: '', launch_window: 'Mid-October', close_date: 'End of October',
          delivery_channel: 'Email link', reminder_plan: '', distribution_owner: 'Front office',
          participation_status: 'required', stakes: 'moderate',
          followup_owner: '', nonresponse_plan: '', monitoring_plan: '', delivery_tested: 'no', launch_dependencies: ''
        },
        flags: [
          { check: 'unclear_target_population', item_ref: 'plan',
            quote: 'The plan says “families” but does not specify which families or grades',
            severity: 'major',
            rationale: 'The intended respondents are not clearly identified, so the survey cannot be reliably delivered to the right people.',
            suggested_revision: 'Name the exact population (e.g. families of students in grades 6–8 at School X).' },
          { check: 'unclear_reminder_followup_plan', item_ref: 'plan',
            quote: 'No reminder or follow-up schedule is described',
            severity: 'minor',
            rationale: 'Without a reminder/follow-up plan, response rates may suffer, though the survey can still launch.',
            suggested_revision: 'Add a simple reminder/follow-up schedule (e.g. reminders at day 3 and day 7, with a nonresponse outreach step).' }
        ]
      }
    },

    // ── 4. Sensitive-Topic & Safety Readiness (2) ───────────────────────
    // Is sensitive content handled with care before launch?
    // SCORE ONCE, SURFACE TWICE: may surface Dignity/Access concerns but does
    // not double-subtract — the factory scores only this lens's own checks.
    sensitive_safety: {
      key: 'sensitive_safety', name: 'Sensitive-Topic & Safety Readiness', noun: 'safety', weight: 2,
      intro: 'Is sensitive survey content introduced, framed, supported, and protected safely enough before launch? This pre-launch lens checks whether sensitive content has enough context and stated purpose, a safe decline path, support/resource language, a follow-up plan when risk may be disclosed, careful placement, and safeguards for minors and other vulnerable groups. It is NOT Dignity/Framing (whether wording diminishes/stereotypes/blames/erases) and NOT Access (whether respondents can reach and complete the survey). Score once, surface twice: if an issue is primarily a Dignity/Framing or Access problem, score it there and dismiss the duplicate here with a related-note recommendation.',
      reviewerPrompt: 'Before scoring this lens, review whether sensitive survey content is introduced, explained, sequenced, and supported safely enough before launch. Determine whether respondents have a safe decline path where needed, whether support/resource language is present where needed, whether risk disclosure has a follow-up plan, and whether minors, families, staff, or vulnerable groups have appropriate safeguards. Do not double-score issues that belong primarily under Dignity / Framing or Access.',
      checks: {
        sensitive_topic_unintroduced:            'Sensitive content appears without enough lead-in, context, or preparation',
        missing_decline_path:                    'No safe skip / prefer-not-to-answer / decline path where needed',
        missing_support_resource:                'Missing support/resource/help-seeking language where needed',
        risk_followup_plan_absent:               'No documented follow-up plan when risk disclosure is possible',
        abrupt_sensitive_item_placement:         'Sensitive questions placed abruptly, too early, or destabilizingly',
        minor_or_vulnerable_group_safeguard_gap: 'Sensitive items for minors/vulnerable groups without appropriate safeguards',
        sensitive_topic_purpose_unclear:         'It is unclear why the sensitive information is being requested'
      },
      checkDesc: {
        sensitive_topic_unintroduced:            'sensitive content appears without enough lead-in, context, or preparation for respondents.',
        missing_decline_path:                    'a sensitive item does not provide a clear skip, decline, prefer-not-to-answer, not-applicable, or other safe nonresponse option where one is needed.',
        missing_support_resource:                'the survey asks about sensitive or potentially distressing topics without providing appropriate support, resource, or help-seeking language where needed.',
        risk_followup_plan_absent:               'the survey may collect disclosure of risk, harm, danger, or urgent need, but does not describe or document what follow-up process exists.',
        abrupt_sensitive_item_placement:         'sensitive questions are placed abruptly, too early, or in a context that may surprise or destabilize respondents.',
        minor_or_vulnerable_group_safeguard_gap: 'sensitive questions are asked of minors, families, staff, or other vulnerable respondent groups without safeguards appropriate to the group and topic.',
        sensitive_topic_purpose_unclear:         'the survey does not clearly explain why sensitive information is being requested or how asking it serves the stated survey purpose.'
      },
      severityHints: {
        sensitive_topic_unintroduced: 'major', missing_decline_path: 'major', missing_support_resource: 'moderate',
        risk_followup_plan_absent: 'major', abrupt_sensitive_item_placement: 'moderate',
        minor_or_vulnerable_group_safeguard_gap: 'major', sensitive_topic_purpose_unclear: 'moderate'
      },
      severityGuidance:
        'Use minor when the safety issue is localized and unlikely to affect respondent comfort or safety. Use moderate when sensitive content needs clearer context, placement, resources, or explanation. Use major when sensitive content is required, involves minors or vulnerable groups, lacks a decline path, or lacks appropriate safeguards. Use critical when the survey may collect risk disclosure, harm disclosure, legal/immigration/family safety information, self-harm information, abuse/neglect disclosure, or other high-risk sensitive content without adequate protection, follow-up, or safe nonresponse options.',
      contextFields: [
        { key: 'respondent_type', label: 'Respondent type', type: 'select',
          options: ['students', 'families', 'staff', 'mixed'] },
        { key: 'sensitive_topics', label: 'Sensitive topics present (one per line)', type: 'list' },
        { key: 'collects_risk_disclosure', label: 'Could responses disclose risk of harm?', type: 'select',
          options: ['yes', 'no'] },
        { key: 'collects_sensitive_information', label: 'Does the survey collect sensitive information?', type: 'select',
          options: ['yes', 'no'] },
        { key: 'decline_path_available', label: 'Is a skip / prefer-not-to-answer / decline path available?', type: 'select',
          options: ['yes', 'no'] },
        { key: 'support_resource_language', label: 'Support/resource language provided', type: 'textarea' },
        { key: 'risk_followup_plan', label: 'Follow-up plan if risk is disclosed', type: 'textarea' },
        { key: 'sensitive_topic_purpose', label: 'Stated purpose for the sensitive content', type: 'textarea' },
        { key: 'involves_minors', label: 'Does the survey involve minors?', type: 'select',
          options: ['yes', 'no'] },
        { key: 'vulnerable_group_present', label: 'Is a vulnerable respondent group present?', type: 'select',
          options: ['yes', 'no'] },
        { key: 'sensitive_items_required', label: 'Are sensitive items required?', type: 'select',
          options: ['yes', 'no'] },
        { key: 'guardian_or_staff_safeguards_present', label: 'Are guardian/staff safeguards present?', type: 'select',
          options: ['yes', 'no'] },
        { key: 'stakes', label: 'Stakes of the decisions the results will drive', type: 'select',
          options: ['low', 'moderate', 'high'] },
        { key: 'item_count', label: 'Number of items reviewed', type: 'number' }
      ],
      // PROVISIONAL blocker: a survey should not be launch-ready if sensitive
      // disclosure is requested without a safe response path, needed safeguards,
      // or a clear follow-up process. Orthogonal, score-neutral, accepted-flags-
      // only, verdict-only. Each accepted trigger flag is gated by context.
      blockers: [
        { key: 'unsafe_sensitive_disclosure',
          label: 'Sensitive disclosure requested without a safe response path, needed safeguards, or a follow-up process — review needed before launch',
          test: function (f, mitigations, context) {
            if (!context) return false;
            var risk = context.collects_risk_disclosure === 'yes';
            var minors = context.involves_minors === 'yes';
            var sensitive = context.collects_sensitive_information === 'yes';
            var elevatedStakes = context.stakes === 'moderate' || context.stakes === 'high';
            switch (f.check) {
              case 'risk_followup_plan_absent':               return risk;
              case 'minor_or_vulnerable_group_safeguard_gap': return risk || minors || elevatedStakes;
              case 'missing_decline_path':                    return risk || minors || sensitive || elevatedStakes;
              case 'sensitive_topic_purpose_unclear':         return sensitive && elevatedStakes;
              default:                                        return false;
            }
          } }
      ],
      warnings: [],
      sample: {
        title: 'Sample: student wellbeing survey with sensitive items and no decline path',
        context: {
          respondent_type: 'students', sensitive_topics: ['Mood', 'Belonging'],
          collects_risk_disclosure: 'no', collects_sensitive_information: 'yes',
          decline_path_available: 'no', support_resource_language: '', risk_followup_plan: '',
          sensitive_topic_purpose: '', involves_minors: 'yes', vulnerable_group_present: 'yes',
          sensitive_items_required: 'yes', guardian_or_staff_safeguards_present: 'no',
          stakes: 'moderate', item_count: 12
        },
        flags: [
          { check: 'sensitive_topic_unintroduced', item_ref: 'q7',
            quote: 'How often do you feel hopeless? — appears with no lead-in',
            severity: 'moderate',
            rationale: 'A sensitive mood item is presented without context or preparation for a student respondent.',
            suggested_revision: 'Add a brief, age-appropriate lead-in and stated purpose before the sensitive block.' },
          { check: 'missing_decline_path', item_ref: 'q7',
            quote: 'The item is required with no “prefer not to say” option',
            severity: 'moderate',
            rationale: 'Students cannot decline a sensitive item, which is not appropriate for this content and group; with minors present this is a safety-readiness blocker.',
            suggested_revision: 'Make sensitive items optional and add a “prefer not to say” response.' }
        ]
      }
    },

    // ── 5. Completion Burden & Launch Logistics (2) ─────────────────────
    // Is the survey practical for respondents to complete?
    completion_burden: {
      key: 'completion_burden', name: 'Completion Burden & Launch Logistics', noun: 'completion', weight: 2,
      intro: 'Is the survey practical for respondents to complete once they receive it? This respondent-completion-facing pre-launch lens checks completion burden and logistics: overall length/density, a stated estimated completion time, required-item burden, device/mode readiness, save-and-return clarity, final submission clarity, and other respondent-facing completion friction. It is NOT Fielding Plan & Timing (which asks whether the organization can launch, distribute, monitor, and close the survey).',
      reviewerPrompt: 'Before scoring this lens, review whether the survey is practical for respondents to complete once they receive it. Consider survey length, estimated completion time, required-item burden, device or mode readiness, save-and-return behavior, final submission clarity, and other respondent-facing completion friction. Do not evaluate fielding ownership, launch timing, consent/privacy, sensitive-topic safety, validity, or empirical reliability in this lens.',
      checks: {
        excessive_completion_burden:     'Survey appears too long, dense, complex, or demanding for the respondent group or mode',
        missing_completion_time_estimate:'Estimated completion time is missing where respondents would need one',
        required_item_burden:            'Too many items are required or forced, raising fatigue and drop-off',
        poor_device_or_mode_readiness:   'Survey format is poorly suited to the expected device, platform, or mode',
        unclear_save_return_behavior:    'Whether respondents can save, pause, and return is unclear where it matters',
        unclear_submission_confirmation: 'Final submission, completion, or confirmation process is unclear',
        completion_friction_risk:        'Other respondent-facing completion friction not fitting a more specific check'
      },
      checkDesc: {
        excessive_completion_burden:     'the survey appears too long, dense, complex, or demanding for the intended respondent group or administration mode.',
        missing_completion_time_estimate:'the survey does not provide an estimated completion time where respondents would reasonably need one.',
        required_item_burden:            'the survey requires too many items or forces responses in a way that may increase fatigue, drop-off, or poor-quality completion.',
        poor_device_or_mode_readiness:   'the survey format is not well suited to the device, platform, or mode respondents are expected to use.',
        unclear_save_return_behavior:    'the survey does not clearly explain whether respondents can save progress, pause, return later, or must complete it in one sitting when that matters.',
        unclear_submission_confirmation: 'the final submission, completion, or confirmation process is unclear, so respondents may not know they finished.',
        completion_friction_risk:        'a respondent-facing usability or completion barrier (e.g. many matrices, repeated page loads, confusing navigation, excessive scrolling, burdensome uploads or open-ended items) may make the survey harder to finish, and the issue does not fit a more specific completion-burden check.'
      },
      severityHints: {
        excessive_completion_burden: 'moderate', missing_completion_time_estimate: 'minor', required_item_burden: 'moderate',
        poor_device_or_mode_readiness: 'moderate', unclear_save_return_behavior: 'minor',
        unclear_submission_confirmation: 'moderate', completion_friction_risk: 'moderate'
      },
      severityGuidance:
        'Use minor when the completion issue is small, localized, and easy to correct. Use moderate when the issue may reduce completion, increase fatigue, or lower response quality. Use major when the survey is likely impractical for the intended respondents, device, mode, or completion context. Reserve critical only when a required or high-stakes survey is realistically not completable by a meaningful portion of intended respondents. Boundary: use Fielding Plan when the organization lacks a plan to launch/distribute/monitor/close; use Access when respondents cannot reach the survey (disability, language, device access, assistive tech, reading access, format); use Sensitive-Topic & Safety when sensitive content needs decline paths/resources/safeguards/follow-up; use Item Clarity when wording makes an item hard to interpret. Use Completion Burden when respondents can access the survey but the experience is too long, forced, cumbersome, confusing, or impractical to complete.',
      contextFields: [
        { key: 'item_count',          label: 'Total number of items',            type: 'number' },
        { key: 'required_item_count', label: 'Number of required items',         type: 'number' },
        { key: 'estimated_minutes',   label: 'Estimated completion time',        type: 'text' },
        { key: 'delivery_mode',       label: 'Primary completion mode',          type: 'select',
          options: ['online', 'mobile', 'paper', 'mixed'] },
        { key: 'save_return_supported', label: 'Can respondents save and return?', type: 'select',
          options: ['yes', 'no', 'na'] },
        { key: 'stakes',              label: 'Stakes of the decisions the results will drive', type: 'select',
          options: ['low', 'moderate', 'high'] },
        { key: 'participation_status', label: 'Participation status', type: 'select',
          options: ['voluntary', 'required', 'expected', 'unstated'] },
        { key: 'respondent_group',    label: 'Respondent group',                 type: 'text' },
        { key: 'expected_device_or_mode', label: 'Expected device or mode',      type: 'text' },
        { key: 'page_count',          label: 'Number of pages/sections',         type: 'number' },
        { key: 'matrix_item_count',   label: 'Number of matrix/grid items',      type: 'number' },
        { key: 'open_ended_item_count', label: 'Number of open-ended items',     type: 'number' },
        { key: 'mobile_tested',       label: 'Has mobile completion been tested?', type: 'select',
          options: ['yes', 'no'] },
        { key: 'confirmation_screen_present', label: 'Is a submission/confirmation screen present?', type: 'select',
          options: ['yes', 'no'] },
        // Denominator for the widespread_completion_burden_risk warning. The
        // warning is silent if no usable denominator is available.
        { key: 'denominator_reviewed', label: 'Items/pages/sections reviewed (denominator)', type: 'number' }
      ],
      // PROVISIONAL blocker: most burden issues should only lower the score. But
      // a required or expected survey should not be marked launch-ready if
      // intended respondents realistically cannot complete it in the expected
      // mode. Orthogonal, score-neutral, accepted-flags-only, verdict-only.
      blockers: [
        { key: 'survey_not_reasonably_completable',
          label: 'A required/expected survey may not be reasonably completable by intended respondents in the expected mode — review needed before launch',
          test: function (f, mitigations, context) {
            if (!context) return false;
            if (f.check !== 'excessive_completion_burden' && f.check !== 'poor_device_or_mode_readiness' &&
                f.check !== 'required_item_burden' && f.check !== 'completion_friction_risk') return false;
            var requiredOrExpected = context.participation_status === 'required' || context.participation_status === 'expected';
            if (!requiredOrExpected) return false;
            var elevatedStakes = context.stakes === 'moderate' || context.stakes === 'high';
            var mobileMode = context.delivery_mode === 'mobile' || context.delivery_mode === 'mixed';
            var deviceProblem = mobileMode && (f.check === 'poor_device_or_mode_readiness' ||
              f.check === 'excessive_completion_burden' || f.check === 'completion_friction_risk');
            return elevatedStakes || deviceProblem;
          } }
      ],
      // Advisory only: does not change the score, does not block launch, depends
      // on accepted/severity-overridden flags only, silent without a denominator.
      warnings: [
        { key: 'widespread_completion_burden_risk',
          label: 'Widespread completion-burden risk: completion friction affects much of the survey, which likely needs redesign rather than isolated edits.',
          test: function (accepted, context) {
            if (!context) return false;
            var n = Number(context.denominator_reviewed) || Number(context.item_count) ||
                    Number(context.page_count);
            if (!(n > 0)) return false;
            var flaggedUnits = {};
            accepted.forEach(function (f) { if (f.item_ref) flaggedUnits[f.item_ref] = true; });
            return (Object.keys(flaggedUnits).length / n) > 0.4;
          } }
      ],
      sample: {
        title: 'Sample: long required mobile survey with no time estimate',
        context: {
          item_count: 64, required_item_count: 60, estimated_minutes: '',
          delivery_mode: 'mobile', save_return_supported: 'no', stakes: 'moderate',
          participation_status: 'required', respondent_group: 'Frontline staff',
          expected_device_or_mode: 'Personal mobile phones', page_count: 8,
          matrix_item_count: 6, open_ended_item_count: 4, mobile_tested: 'no',
          confirmation_screen_present: 'no', denominator_reviewed: 8
        },
        flags: [
          { check: 'excessive_completion_burden', item_ref: 'survey',
            quote: '64 items, nearly all required, delivered on mobile',
            severity: 'moderate',
            rationale: 'A 64-item required mobile survey is long and dense enough to raise burden and drop-off for the intended group.',
            suggested_revision: 'Trim or split the survey, or reduce the number of required items.' },
          { check: 'missing_completion_time_estimate', item_ref: 'intro',
            quote: 'No estimated completion time is given',
            severity: 'minor',
            rationale: 'Respondents are not told how long it will take, which affects their willingness to start and finish.',
            suggested_revision: 'Add an estimated completion time to the introduction.' }
        ]
      }
    }

  };

  // Order Administration Readiness presents the five lenses.
  var ORDER = ['respondent_instructions', 'consent_privacy', 'fielding_plan', 'sensitive_safety', 'completion_burden'];

  root.SDSI_ADMINISTRATION_SPECS = SPECS;
  root.SDSI_ADMINISTRATION_ORDER = ORDER;

  // Build live engines when the factory is present (browser: validity-lens-engine.js
  // loads first; node: require it). The factory is construct-agnostic — the same
  // locked spine scores validity, reliability, and administration lenses identically.
  var factory = (typeof root.ValidityLens !== 'undefined') ? root.ValidityLens
              : (typeof module !== 'undefined' && module.exports) ? require('./validity-lens-engine.js')
              : null;
  if (factory) {
    var lenses = {};
    ORDER.forEach(function (k) { lenses[k] = factory.make(SPECS[k]); });
    root.SDSI_ADMINISTRATION_LENSES = lenses;
  }

})(typeof window !== 'undefined' ? window : this);

if (typeof module !== 'undefined' && module.exports) {
  var _a = (typeof window !== 'undefined' ? window : this);
  module.exports = { SPECS: _a.SDSI_ADMINISTRATION_SPECS, ORDER: _a.SDSI_ADMINISTRATION_ORDER, LENSES: _a.SDSI_ADMINISTRATION_LENSES };
}
