<?php
// Shared vocabulary + proposer-prompt builder for the five factory ADMINISTRATION
// READINESS lenses (respondent_instructions, consent_privacy, fielding_plan,
// sensitive_safety, completion_burden).
//
// This mirrors the check vocabulary, severities, severity guidance, blocker
// conditions, warnings, context fields, and SDSI weights declared in
// apps/sdsi/administration-specs.js. The JS specs drive the client engine + UI;
// this PHP copy is the server-side validation gate for AI proposals and the
// prompt content. KEEP THE TWO IN LOCKSTEP — the lockstep test
// (apps/sdsi/administration-prompts-lockstep.test.js) compares this file against
// administration-specs.js (component keys, check keys, severityHints, context
// field keys + select options, blocker keys, warning keys) and fails on drift.
//
// ╔══════════════════════════════════════════════════════════════════════╗
// ║  PRE-LAUNCH SCOPE — NOT A STATISTICAL OR POST-DATA REVIEW.            ║
// ║  Administration Readiness asks whether the survey is ready to be      ║
// ║  fielded RESPONSIBLY, CLEARLY, SAFELY, and PRACTICALLY before any     ║
// ║  data is collected. It never evaluates survey results or             ║
// ║  post-administration data quality — that belongs to RSSI, AFTER data  ║
// ║  collection. (There is NO alpha fence here; that constraint is        ║
// ║  Reliability-only. These lenses review launch readiness, not          ║
// ║  psychometrics.)                                                      ║
// ║                                                                       ║
// ║  BOUNDARY — Administration READINESS ≠ Administration CONSISTENCY.    ║
// ║  Administration Consistency for Reliability already lives inside      ║
// ║  Reliability Readiness (whether conditions support STABLE responses). ║
// ║  These lenses ask whether the survey can LAUNCH in the real world:    ║
// ║  guidance, transparency, safety, and logistics.                       ║
// ╚══════════════════════════════════════════════════════════════════════╝

declare(strict_types=1);

/**
 * Returns the full spec for an administration component, or null if unknown.
 * Shape: [ label, noun, weight, principle, checks{key=>label},
 *          checkDesc{key=>oneLine}, severityHints{key=>sev}, severityGuidance,
 *          contextFields[ {key,label,type,options?} ], blockerConditions[string],
 *          warnings[string] ]
 */
function administration_component_spec(string $component): ?array
{
    static $specs = null;
    if ($specs === null) {
        $specs = [

            // ── 1. Respondent Instructions & Guidance (4) ───────────────────
            'respondent_instructions' => [
                'label'  => 'Respondent Instructions & Guidance',
                'noun'   => 'instructions',
                'weight' => 4,
                'principle' =>
                    "Judge whether respondents have enough guidance to complete the survey appropriately BEFORE launch. " .
                    "Ask whether respondents are clearly told why they are taking the survey, how to complete it, what " .
                    "timeframe to use, what role or perspective to answer from, and how to move through the survey. This is a " .
                    "pre-launch readiness check on the guidance respondents receive — NOT a check of construct fit (Validity) " .
                    "or response consistency (Reliability). Role ambiguity or inconsistent timeframes that create inconsistent " .
                    "response CONDITIONS across groups/versions belong to Administration Consistency for Reliability, not here.",
                'checks' => [
                    'missing_instructions'            => 'No opening instructions, or instructions too thin to complete the survey',
                    'unclear_purpose_for_respondents' => 'Survey purpose not explained in plain respondent-facing language',
                    'unclear_completion_expectations' => 'What a complete/expected response looks like is unclear',
                    'unclear_timeframe_guidance'      => 'Answering timeframe guidance is missing or unclear',
                    'unclear_section_transitions'     => 'Transitions between sections are missing or unclear',
                    'unclear_respondent_role'         => 'Respondent role or perspective to answer from is unclear',
                ],
                'checkDesc' => [
                    'missing_instructions'            => 'the survey provides no opening instructions, or the instructions are too thin for respondents to know how to complete the survey.',
                    'unclear_purpose_for_respondents' => 'the survey does not explain the purpose of the survey in plain, respondent-facing language — why respondents are being asked to take it. (This is NOT the researcher/construct purpose, which belongs to Validity Readiness.)',
                    'unclear_completion_expectations' => 'respondents cannot tell what kind, amount, or format of response is expected (e.g. select one vs. select all, short vs. detailed).',
                    'unclear_timeframe_guidance'      => 'the survey does not tell respondents what time period to use when answering, where a reference window is needed. (Inconsistent timeframes across sections that affect score comparability belong to Administration Consistency for Reliability.)',
                    'unclear_section_transitions'     => 'the survey moves between sections, topics, or item types without enough orientation for respondents.',
                    'unclear_respondent_role'         => 'the survey does not make clear what role or perspective the respondent should answer from (self, parent/guardian, teacher, staff, student, observer). (Role ambiguity that creates inconsistent response conditions across groups/versions belongs to Administration Consistency for Reliability.)',
                ],
                'severityHints' => [
                    'missing_instructions' => 'major', 'unclear_purpose_for_respondents' => 'moderate', 'unclear_completion_expectations' => 'moderate',
                    'unclear_timeframe_guidance' => 'moderate', 'unclear_section_transitions' => 'minor', 'unclear_respondent_role' => 'moderate',
                ],
                'severityGuidance' =>
                    "Use minor when the guidance issue is localized and unlikely to affect completion. Use moderate when the " .
                    "issue may cause respondents to answer inconsistently or with uncertainty. Use major when the missing or " .
                    "unclear guidance affects the whole survey or a required section. Reserve critical only when respondents " .
                    "cannot reasonably know what they are being asked to do in a high-stakes or required survey.",
                'contextFields' => [
                    ['key' => 'purpose',                 'label' => 'Stated survey purpose (as respondents would see it)', 'type' => 'textarea'],
                    ['key' => 'respondents',             'label' => 'Intended respondent group',          'type' => 'text'],
                    ['key' => 'respondent_role',         'label' => 'Role/perspective respondents answer from', 'type' => 'text'],
                    ['key' => 'reference_timeframe',     'label' => 'Reference timeframe for answering',  'type' => 'text'],
                    ['key' => 'has_sections',            'label' => 'Does the survey have multiple sections?', 'type' => 'select',
                        'options' => ['yes', 'no']],
                    ['key' => 'has_instruction_block',   'label' => 'Does the survey have an opening instruction block?', 'type' => 'select',
                        'options' => ['yes', 'no']],
                    ['key' => 'survey_required',         'label' => 'Is completing this survey required?', 'type' => 'select',
                        'options' => ['yes', 'no']],
                    ['key' => 'stakes',                  'label' => 'Stakes of the decisions the results will drive', 'type' => 'select',
                        'options' => ['low', 'moderate', 'high']],
                    ['key' => 'estimated_completion_time', 'label' => 'Estimated completion time (if stated)', 'type' => 'text'],
                    ['key' => 'item_count',              'label' => 'Number of items reviewed',           'type' => 'number'],
                ],
                'blockerConditions' => ['instructions_absent'],
                'warnings'          => [],
            ],

            // ── 2. Consent, Privacy & Use Transparency (4) ──────────────────
            'consent_privacy' => [
                'label'  => 'Consent, Privacy & Use Transparency',
                'noun'   => 'consent',
                'weight' => 4,
                'principle' =>
                    "Judge whether respondents can understand, before launch, whether participation is voluntary, required, " .
                    "expected, or unstated; how their responses will be used; who may see individual responses or summary " .
                    "results; and whether privacy/confidentiality claims match the data collected. This lens flags TRANSPARENCY " .
                    "READINESS RISKS — NOT legal, IRB, FERPA, COPPA, HIPAA, district-policy, or employment-law compliance. Use " .
                    "the language of transparency gap, readiness risk, and review needed — never compliant/noncompliant.",
                'checks' => [
                    'missing_participation_statement'   => 'Missing respondent-facing participation statement',
                    'unclear_participation_status'      => 'Voluntary / required / expected status is unclear',
                    'unclear_confidentiality_anonymity' => 'Anonymity / confidentiality / identifiability is unclear',
                    'overpromised_privacy'              => 'Privacy claim may not match the data collected (transparency risk)',
                    'unclear_use_of_results'            => 'How results will be used is unclear',
                    'unclear_data_audience'             => 'Who may see individual responses or summary results is unclear',
                    'missing_guardian_language'         => 'Parent/guardian language missing when relevant',
                ],
                'checkDesc' => [
                    'missing_participation_statement'   => 'the survey does not provide a respondent-facing participation statement explaining what respondents are being asked to do. (This is a transparency readiness gap, not a determination of legal consent.)',
                    'unclear_participation_status'      => 'the survey does not clearly state whether participation is voluntary, required, expected, or otherwise requested.',
                    'unclear_confidentiality_anonymity' => 'the survey does not clearly explain whether responses are anonymous, confidential, identifiable, or reported only in aggregate.',
                    'overpromised_privacy'              => 'the survey promises anonymity, confidentiality, or privacy protections that may not match the data collected, reporting design, login state, or respondent-identifiability risk (e.g. claims "anonymous" while collecting name, email, student ID, authenticated login, or small-group demographics).',
                    'unclear_use_of_results'            => 'the survey does not clearly explain how responses or results will be used.',
                    'unclear_data_audience'             => 'the survey does not clearly explain who may see individual responses, identifiable data, aggregate results, or reports.',
                    'missing_guardian_language'         => 'parent/guardian notification, consent, or explanation language is missing where minors or K-12 family/student administration make it relevant.',
                ],
                'severityHints' => [
                    'missing_participation_statement' => 'major', 'unclear_participation_status' => 'major', 'unclear_confidentiality_anonymity' => 'major',
                    'overpromised_privacy' => 'critical', 'unclear_use_of_results' => 'moderate', 'unclear_data_audience' => 'moderate', 'missing_guardian_language' => 'major',
                ],
                'severityGuidance' =>
                    "Use minor when the transparency issue is localized and unlikely to affect respondent understanding. Use " .
                    "moderate when respondents may lack important information about use, audience, or reporting. Use major when " .
                    "respondents cannot clearly understand participation expectations, privacy status, or guardian/minor-related " .
                    "protections. Use critical when the survey makes a privacy promise that appears inaccurate or misleading, " .
                    "especially when individual, sensitive, or small-group data may be identifiable.",
                'contextFields' => [
                    ['key' => 'respondent_type', 'label' => 'Respondent type', 'type' => 'select',
                        'options' => ['students', 'families', 'staff', 'mixed']],
                    ['key' => 'participation_status', 'label' => 'Participation status', 'type' => 'select',
                        'options' => ['voluntary', 'required', 'expected', 'unstated']],
                    ['key' => 'privacy_status', 'label' => 'Privacy/identifiability handling', 'type' => 'select',
                        'options' => ['anonymous', 'confidential', 'identifiable', 'aggregate_only', 'unstated']],
                    ['key' => 'results_audience', 'label' => 'Who will see the results', 'type' => 'text'],
                    ['key' => 'involves_minors', 'label' => 'Does the survey involve minors?', 'type' => 'select',
                        'options' => ['yes', 'no']],
                    ['key' => 'consent_obtained_elsewhere', 'label' => 'Is consent handled through a separate, documented process?', 'type' => 'select',
                        'options' => ['yes', 'no']],
                    ['key' => 'stakes', 'label' => 'Stakes of the decisions the results will drive', 'type' => 'select',
                        'options' => ['low', 'moderate', 'high']],
                    ['key' => 'collects_identifiers', 'label' => 'Does the survey collect identifiers (name, email, ID, login)?', 'type' => 'select',
                        'options' => ['yes', 'no']],
                    ['key' => 'collects_sensitive_information', 'label' => 'Does the survey collect sensitive information?', 'type' => 'select',
                        'options' => ['yes', 'no']],
                    ['key' => 'small_group_reporting_risk', 'label' => 'Is there a small-group reporting/identifiability risk?', 'type' => 'select',
                        'options' => ['yes', 'no']],
                    ['key' => 'guardian_language_needed', 'label' => 'Is parent/guardian language needed?', 'type' => 'select',
                        'options' => ['yes', 'no']],
                    ['key' => 'guardian_language_present', 'label' => 'Is parent/guardian language present?', 'type' => 'select',
                        'options' => ['yes', 'no']],
                    ['key' => 'data_use_statement_present', 'label' => 'Is a data-use statement present?', 'type' => 'select',
                        'options' => ['yes', 'no']],
                    ['key' => 'individual_response_access', 'label' => 'Who can access individual responses', 'type' => 'text'],
                    ['key' => 'aggregate_results_audience', 'label' => 'Audience for aggregate results', 'type' => 'text'],
                ],
                'blockerConditions' => ['missing_consent_or_required_status'],
                'warnings'          => [],
            ],

            // ── 3. Fielding Plan & Timing (3) ───────────────────────────────
            'fielding_plan' => [
                'label'  => 'Fielding Plan & Timing',
                'noun'   => 'fielding',
                'weight' => 3,
                'principle' =>
                    "Judge whether this survey can actually be launched and managed as intended — delivered to the right people, " .
                    "at the right time, through the right channel, with clear ownership and follow-up. This pre-launch lens is " .
                    "PRACTICAL and OPERATIONAL: target population, delivery channel, launch window, close date, distribution " .
                    "ownership, reminder/follow-up plan, and major launch dependencies. It does not evaluate psychometric " .
                    "validity, reliability consistency, consent/privacy transparency, sensitive-topic safeguards, or post-data " .
                    "quality. Target-population clarity here is FIELDING clarity, NOT statistical sample representativeness.",
                'checks' => [
                    'unclear_target_population'           => 'Target population is missing or unclear',
                    'unclear_delivery_channel'            => 'Delivery channel is missing or unclear',
                    'unclear_launch_window'               => 'Launch window is missing or unclear',
                    'unclear_close_date'                  => 'Close date or completion window is missing or unclear',
                    'unclear_distribution_responsibility' => 'Responsibility for distribution is unclear',
                    'unclear_reminder_followup_plan'      => 'Reminder / follow-up / nonresponse plan is missing or unclear',
                    'major_logistics_gap'                 => 'Major operational gap could prevent administration (narrow catch-all)',
                ],
                'checkDesc' => [
                    'unclear_target_population'           => 'the intended respondents are not clearly identified. (This is fielding clarity, NOT statistical sample representativeness, which belongs after fielding or to a separate sampling/recruitment analysis.)',
                    'unclear_delivery_channel'            => 'the method for delivering the survey (email, text, paper, LMS, QR code, in-class, etc.) is missing or unclear.',
                    'unclear_launch_window'               => 'the date or window for opening the survey is missing or unclear.',
                    'unclear_close_date'                  => 'the deadline, close date, or completion window is missing or unclear.',
                    'unclear_distribution_responsibility' => 'the person, role, office, or team responsible for distributing the survey is unclear.',
                    'unclear_reminder_followup_plan'      => 'the plan for reminders, follow-up, or nonresponse outreach is missing or unclear. (If reminder language pressures or coerces respondents, surface that under Consent/Transparency or Sensitive-Topic/Safety instead.)',
                    'major_logistics_gap'                 => 'a significant operational gap could prevent the survey from being launched, distributed, completed, or monitored as intended, and the issue does not fit a more specific fielding check. Use sparingly.',
                ],
                'severityHints' => [
                    'unclear_target_population' => 'major', 'unclear_delivery_channel' => 'moderate', 'unclear_launch_window' => 'moderate',
                    'unclear_close_date' => 'moderate', 'unclear_distribution_responsibility' => 'moderate',
                    'unclear_reminder_followup_plan' => 'minor', 'major_logistics_gap' => 'major',
                ],
                'severityGuidance' =>
                    "Use minor when the fielding issue is localized and unlikely to affect launch. Use moderate when the issue " .
                    "may reduce participation, create confusion, or weaken fielding control. Use major when the issue could " .
                    "prevent the survey from reaching the intended respondents or being administered as intended. Reserve " .
                    "critical only when a high-stakes or required survey cannot responsibly launch because of a severe " .
                    "operational gap.",
                'contextFields' => [
                    ['key' => 'target_population',    'label' => 'Target population',                'type' => 'text'],
                    ['key' => 'launch_window',        'label' => 'Planned launch window',            'type' => 'text'],
                    ['key' => 'close_date',           'label' => 'Planned close date',               'type' => 'text'],
                    ['key' => 'delivery_channel',     'label' => 'Delivery channel',                 'type' => 'text'],
                    ['key' => 'reminder_plan',        'label' => 'Reminder plan',                    'type' => 'textarea'],
                    ['key' => 'distribution_owner',   'label' => 'Who is responsible for distribution', 'type' => 'text'],
                    ['key' => 'participation_status', 'label' => 'Participation status',             'type' => 'select',
                        'options' => ['voluntary', 'required', 'expected', 'unstated']],
                    ['key' => 'stakes',               'label' => 'Stakes of the decisions the results will drive', 'type' => 'select',
                        'options' => ['low', 'moderate', 'high']],
                    ['key' => 'followup_owner',       'label' => 'Who is responsible for follow-up', 'type' => 'text'],
                    ['key' => 'nonresponse_plan',     'label' => 'Nonresponse / follow-up plan',     'type' => 'textarea'],
                    ['key' => 'monitoring_plan',      'label' => 'How fielding will be monitored',   'type' => 'textarea'],
                    ['key' => 'delivery_tested',      'label' => 'Has the delivery channel been tested?', 'type' => 'select',
                        'options' => ['yes', 'no']],
                    ['key' => 'launch_dependencies',  'label' => 'Major launch dependencies',        'type' => 'textarea'],
                ],
                'blockerConditions' => ['no_clear_target_population', 'launch_plan_missing_for_required_survey'],
                'warnings'          => [],
            ],

            // ── 4. Sensitive-Topic & Safety Readiness (2) ───────────────────
            'sensitive_safety' => [
                'label'  => 'Sensitive-Topic & Safety Readiness',
                'noun'   => 'safety',
                'weight' => 2,
                'principle' =>
                    "Judge whether sensitive survey content is introduced, framed, supported, and protected safely enough " .
                    "before launch: enough context and stated purpose, a safe decline path, support/resource language, a " .
                    "follow-up plan when risk may be disclosed, careful placement, and safeguards for minors and other " .
                    "vulnerable groups. This is NOT Dignity/Framing (whether wording diminishes/stereotypes/blames/erases) and " .
                    "NOT Access (whether respondents can reach and complete the survey). SCORE ONCE, SURFACE TWICE: if an issue " .
                    "is primarily a Dignity/Framing or Access problem, score it there and surface it here with a related-note " .
                    "recommendation — never double-subtract.",
                'checks' => [
                    'sensitive_topic_unintroduced'            => 'Sensitive content appears without enough lead-in, context, or preparation',
                    'missing_decline_path'                    => 'No safe skip / prefer-not-to-answer / decline path where needed',
                    'missing_support_resource'                => 'Missing support/resource/help-seeking language where needed',
                    'risk_followup_plan_absent'               => 'No documented follow-up plan when risk disclosure is possible',
                    'abrupt_sensitive_item_placement'         => 'Sensitive questions placed abruptly, too early, or destabilizingly',
                    'minor_or_vulnerable_group_safeguard_gap' => 'Sensitive items for minors/vulnerable groups without appropriate safeguards',
                    'sensitive_topic_purpose_unclear'         => 'It is unclear why the sensitive information is being requested',
                ],
                'checkDesc' => [
                    'sensitive_topic_unintroduced'            => 'sensitive content appears without enough lead-in, context, or preparation for respondents.',
                    'missing_decline_path'                    => 'a sensitive item does not provide a clear skip, decline, prefer-not-to-answer, not-applicable, or other safe nonresponse option where one is needed.',
                    'missing_support_resource'                => 'the survey asks about sensitive or potentially distressing topics without providing appropriate support, resource, or help-seeking language where needed.',
                    'risk_followup_plan_absent'               => 'the survey may collect disclosure of risk, harm, danger, or urgent need, but does not describe or document what follow-up process exists.',
                    'abrupt_sensitive_item_placement'         => 'sensitive questions are placed abruptly, too early, or in a context that may surprise or destabilize respondents.',
                    'minor_or_vulnerable_group_safeguard_gap' => 'sensitive questions are asked of minors, families, staff, or other vulnerable respondent groups without safeguards appropriate to the group and topic.',
                    'sensitive_topic_purpose_unclear'         => 'the survey does not clearly explain why sensitive information is being requested or how asking it serves the stated survey purpose.',
                ],
                'severityHints' => [
                    'sensitive_topic_unintroduced' => 'major', 'missing_decline_path' => 'major', 'missing_support_resource' => 'moderate',
                    'risk_followup_plan_absent' => 'major', 'abrupt_sensitive_item_placement' => 'moderate',
                    'minor_or_vulnerable_group_safeguard_gap' => 'major', 'sensitive_topic_purpose_unclear' => 'moderate',
                ],
                'severityGuidance' =>
                    "Use minor when the safety issue is localized and unlikely to affect respondent comfort or safety. Use " .
                    "moderate when sensitive content needs clearer context, placement, resources, or explanation. Use major " .
                    "when sensitive content is required, involves minors or vulnerable groups, lacks a decline path, or lacks " .
                    "appropriate safeguards. Use critical when the survey may collect risk disclosure, harm disclosure, " .
                    "legal/immigration/family safety information, self-harm information, abuse/neglect disclosure, or other " .
                    "high-risk sensitive content without adequate protection, follow-up, or safe nonresponse options.",
                'contextFields' => [
                    ['key' => 'respondent_type', 'label' => 'Respondent type', 'type' => 'select',
                        'options' => ['students', 'families', 'staff', 'mixed']],
                    ['key' => 'sensitive_topics', 'label' => 'Sensitive topics present (one per line)', 'type' => 'list'],
                    ['key' => 'collects_risk_disclosure', 'label' => 'Could responses disclose risk of harm?', 'type' => 'select',
                        'options' => ['yes', 'no']],
                    ['key' => 'collects_sensitive_information', 'label' => 'Does the survey collect sensitive information?', 'type' => 'select',
                        'options' => ['yes', 'no']],
                    ['key' => 'decline_path_available', 'label' => 'Is a skip / prefer-not-to-answer / decline path available?', 'type' => 'select',
                        'options' => ['yes', 'no']],
                    ['key' => 'support_resource_language', 'label' => 'Support/resource language provided', 'type' => 'textarea'],
                    ['key' => 'risk_followup_plan', 'label' => 'Follow-up plan if risk is disclosed', 'type' => 'textarea'],
                    ['key' => 'sensitive_topic_purpose', 'label' => 'Stated purpose for the sensitive content', 'type' => 'textarea'],
                    ['key' => 'involves_minors', 'label' => 'Does the survey involve minors?', 'type' => 'select',
                        'options' => ['yes', 'no']],
                    ['key' => 'vulnerable_group_present', 'label' => 'Is a vulnerable respondent group present?', 'type' => 'select',
                        'options' => ['yes', 'no']],
                    ['key' => 'sensitive_items_required', 'label' => 'Are sensitive items required?', 'type' => 'select',
                        'options' => ['yes', 'no']],
                    ['key' => 'guardian_or_staff_safeguards_present', 'label' => 'Are guardian/staff safeguards present?', 'type' => 'select',
                        'options' => ['yes', 'no']],
                    ['key' => 'stakes', 'label' => 'Stakes of the decisions the results will drive', 'type' => 'select',
                        'options' => ['low', 'moderate', 'high']],
                    ['key' => 'item_count', 'label' => 'Number of items reviewed', 'type' => 'number'],
                ],
                'blockerConditions' => ['unsafe_sensitive_disclosure'],
                'warnings'          => [],
            ],

            // ── 5. Completion Burden & Launch Logistics (2) ─────────────────
            'completion_burden' => [
                'label'  => 'Completion Burden & Launch Logistics',
                'noun'   => 'completion',
                'weight' => 2,
                'principle' =>
                    "Judge whether the survey is practical for respondents to complete once they receive it. This " .
                    "respondent-completion-facing pre-launch lens reviews completion burden and logistics: overall " .
                    "length/density, a stated estimated completion time, required-item burden, device/mode readiness, " .
                    "save-and-return clarity, final submission clarity, and other respondent-facing completion friction. It is " .
                    "NOT Fielding Plan & Timing (whether the organization can launch, distribute, monitor, and close the " .
                    "survey), NOT Access (whether respondents can REACH the survey), and NOT Item Clarity (whether wording " .
                    "makes an item hard to interpret). Use Completion Burden when respondents can access the survey but the " .
                    "experience is too long, forced, cumbersome, confusing, or impractical to complete.",
                'checks' => [
                    'excessive_completion_burden'      => 'Survey appears too long, dense, complex, or demanding for the respondent group or mode',
                    'missing_completion_time_estimate' => 'Estimated completion time is missing where respondents would need one',
                    'required_item_burden'             => 'Too many items are required or forced, raising fatigue and drop-off',
                    'poor_device_or_mode_readiness'    => 'Survey format is poorly suited to the expected device, platform, or mode',
                    'unclear_save_return_behavior'     => 'Whether respondents can save, pause, and return is unclear where it matters',
                    'unclear_submission_confirmation'  => 'Final submission, completion, or confirmation process is unclear',
                    'completion_friction_risk'         => 'Other respondent-facing completion friction not fitting a more specific check',
                ],
                'checkDesc' => [
                    'excessive_completion_burden'      => 'the survey appears too long, dense, complex, or demanding for the intended respondent group or administration mode.',
                    'missing_completion_time_estimate' => 'the survey does not provide an estimated completion time where respondents would reasonably need one.',
                    'required_item_burden'             => 'the survey requires too many items or forces responses in a way that may increase fatigue, drop-off, or poor-quality completion.',
                    'poor_device_or_mode_readiness'    => 'the survey format is not well suited to the device, platform, or mode respondents are expected to use.',
                    'unclear_save_return_behavior'     => 'the survey does not clearly explain whether respondents can save progress, pause, return later, or must complete it in one sitting when that matters.',
                    'unclear_submission_confirmation'  => 'the final submission, completion, or confirmation process is unclear, so respondents may not know they finished.',
                    'completion_friction_risk'         => 'a respondent-facing usability or completion barrier (e.g. many matrices, repeated page loads, confusing navigation, excessive scrolling, burdensome uploads or open-ended items) may make the survey harder to finish, and the issue does not fit a more specific completion-burden check.',
                ],
                'severityHints' => [
                    'excessive_completion_burden' => 'moderate', 'missing_completion_time_estimate' => 'minor', 'required_item_burden' => 'moderate',
                    'poor_device_or_mode_readiness' => 'moderate', 'unclear_save_return_behavior' => 'minor',
                    'unclear_submission_confirmation' => 'moderate', 'completion_friction_risk' => 'moderate',
                ],
                'severityGuidance' =>
                    "Use minor when the completion issue is small, localized, and easy to correct. Use moderate when the issue " .
                    "may reduce completion, increase fatigue, or lower response quality. Use major when the survey is likely " .
                    "impractical for the intended respondents, device, mode, or completion context. Reserve critical only when " .
                    "a required or high-stakes survey is realistically not completable by a meaningful portion of intended " .
                    "respondents. Boundary: use Fielding Plan when the organization lacks a plan to launch/distribute/monitor/" .
                    "close; use Access when respondents cannot reach the survey (disability, language, device access, assistive " .
                    "tech, reading access, format); use Sensitive-Topic & Safety when sensitive content needs decline paths/" .
                    "resources/safeguards/follow-up; use Item Clarity when wording makes an item hard to interpret.",
                'contextFields' => [
                    ['key' => 'item_count',          'label' => 'Total number of items',            'type' => 'number'],
                    ['key' => 'required_item_count', 'label' => 'Number of required items',         'type' => 'number'],
                    ['key' => 'estimated_minutes',   'label' => 'Estimated completion time',        'type' => 'text'],
                    ['key' => 'delivery_mode',       'label' => 'Primary completion mode',          'type' => 'select',
                        'options' => ['online', 'mobile', 'paper', 'mixed']],
                    ['key' => 'save_return_supported', 'label' => 'Can respondents save and return?', 'type' => 'select',
                        'options' => ['yes', 'no', 'na']],
                    ['key' => 'stakes',              'label' => 'Stakes of the decisions the results will drive', 'type' => 'select',
                        'options' => ['low', 'moderate', 'high']],
                    ['key' => 'participation_status', 'label' => 'Participation status', 'type' => 'select',
                        'options' => ['voluntary', 'required', 'expected', 'unstated']],
                    ['key' => 'respondent_group',    'label' => 'Respondent group',                 'type' => 'text'],
                    ['key' => 'expected_device_or_mode', 'label' => 'Expected device or mode',      'type' => 'text'],
                    ['key' => 'page_count',          'label' => 'Number of pages/sections',         'type' => 'number'],
                    ['key' => 'matrix_item_count',   'label' => 'Number of matrix/grid items',      'type' => 'number'],
                    ['key' => 'open_ended_item_count', 'label' => 'Number of open-ended items',     'type' => 'number'],
                    ['key' => 'mobile_tested',       'label' => 'Has mobile completion been tested?', 'type' => 'select',
                        'options' => ['yes', 'no']],
                    ['key' => 'confirmation_screen_present', 'label' => 'Is a submission/confirmation screen present?', 'type' => 'select',
                        'options' => ['yes', 'no']],
                    ['key' => 'denominator_reviewed', 'label' => 'Items/pages/sections reviewed (denominator)', 'type' => 'number'],
                ],
                'blockerConditions' => ['survey_not_reasonably_completable'],
                'warnings'          => ['widespread_completion_burden_risk'],
            ],

        ];
    }
    return $specs[$component] ?? null;
}

/** All valid administration component keys, in display order (mirrors SDSI_ADMINISTRATION_ORDER). */
function administration_components(): array
{
    return ['respondent_instructions', 'consent_privacy', 'fielding_plan', 'sensitive_safety', 'completion_burden'];
}

/**
 * Builds the AI proposer system prompt for one administration component. The
 * model PROPOSES flags with verbatim evidence; it never computes a score.
 * Severity drives the penalty downstream; the model must not emit numbers. This
 * is a PRE-LAUNCH readiness review — it never evaluates survey results or
 * post-administration data quality (that belongs to RSSI, after data collection).
 */
function administration_system_prompt(array $spec): string
{
    $checkLines = [];
    foreach ($spec['checks'] as $key => $label) {
        $desc = $spec['checkDesc'][$key] ?? $label;
        $checkLines[] = " - {$key} : {$desc}";
    }
    $checkBlock = implode("\n", $checkLines);
    $label = $spec['label'];
    $severityGuidance = isset($spec['severityGuidance'])
        ? "\n" . $spec['severityGuidance'] . "\n"
        : '';

    return <<<SYS
You review survey instruments BEFORE they are fielded, for {$label} — one
component of Administration Readiness (the 15-point pre-launch domain of SIRI,
the Survey Instrument Readiness Index). You are advisory: you PROPOSE issues with
verbatim evidence; a human makes the final call. You never compute the score.

{$spec['principle']}

PRE-LAUNCH SCOPE — this is a readiness review of whether the survey is ready to
be fielded responsibly, clearly, safely, and practically. It NEVER evaluates
survey results or post-administration data quality — that belongs to RSSI, AFTER
data is collected. (SIRI evaluates survey instrument readiness before launch;
RSSI evaluates survey performance after data collection.) There is no statistical
review here: review the survey's launch readiness, not its psychometrics.

THE CHECKS (use these exact keys):
{$checkBlock}

SEVERITY (propose one; the human may override): minor, moderate, major, critical.
 - minor: small readiness gap; mostly ready to launch.
 - moderate: a real readiness gap that should be addressed before launch.
 - major: a substantial administration-readiness threat for this component.
 - critical: the survey is not responsibly launch-ready as designed for this component.
{$severityGuidance}Do NOT output penalty numbers — severity alone drives the penalty downstream.

For EVERY proposed flag output: check, item_ref (the item id, a section name, or
a context field key like "purpose"/"participation_status" when the issue is in
the declared context rather than an item), quote (verbatim evidence from the item
or the declared context), severity, rationale (one line: why it fires AND why it
matters for administration readiness), suggested_revision (a concrete fix).

Be conservative: only flag what the text actually supports, and always quote the
exact words. If nothing fires, return an empty flags array. Output STRICT JSON
only, no prose outside the JSON, in this shape:
{ "flags": [ { "check": "...", "item_ref": "...", "quote": "...", "severity": "...", "rationale": "...", "suggested_revision": "..." } ], "notes": "" }
SYS;
}
