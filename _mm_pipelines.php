<?php
// ============================================================
// MM Studio · design→pipeline mapping (server-authoritative)
//
// Single source of truth for the five A–E mixed-methods designs and
// the ordered pipeline each one produces. Consumed by mmstudioV4.php
// (injected as JSON for the view), and reusable by any endpoint that
// needs to know a design's step order or leading strand.
//
// Design slugs MUST match api/mm/wizard.php $valid['design_choice'] and
// api/mm/design-recommendations.php. Step `route` is reserved for Phase 3,
// when each workstation mounts its existing tested engine via
// `route?studio=mm&project_id=N&embed=1` (the mechanism _analysis_studio_shell.php
// already uses). It is null here because Phase 1/2 workstations are placeholders.
//
// Strand vocabulary: quan | qual | both | neutral.
// Mode vocabulary:   setup | work | output  (what the center renders).
// ============================================================

// ---------- Fixed spine shared by every design ----------
// Single orientation step — users come straight in here (like SIRI), so the
// separate MM landing page, Study Setup, and Data Map all collapse into Start.
// It is an overview of the uploaded data and the chosen study design; the
// footer dock carries the upload / pull-data intake.
$SETUP = [
  ['id'=>'start','label'=>'Start','strand'=>'neutral','mode'=>'start','route'=>null,
   'title'=>'Connect numbers, narratives, and meaning.','lede'=>'Bring your quantitative and qualitative evidence into one project, then move through the analysis. Pick a starting point — you can change course at any step.','done'=>false,
   'palette'=>['intro'=>'Orientation','groups'=>[]]],
  // Overview: a review of everything from setup — the data brought in and the
  // framing questions answered, plus the chosen design. Users can return here
  // anytime; nothing is locked. Then they jump into the analysis.
  ['id'=>'overview','label'=>'Overview','strand'=>'neutral','mode'=>'overview','route'=>'/project-snapshot.php',
   'title'=>'Overview','lede'=>'Everything from your setup in one place — the data you brought in and the questions you answered. Come back anytime; nothing here is locked.','done'=>false,
   'palette'=>['intro'=>'Overview','groups'=>[]]],
  // Data Map — organize the dataset into analysis roles (quantitative, qualitative,
  // demographic, integration) BEFORE evaluation. Classification only; Data Quality
  // does the evaluation. Confirmed roles persist to the dataset for later modules.
  ['id'=>'data_map','label'=>'Data Map','strand'=>'neutral','mode'=>'datamap','route'=>null,
   'title'=>'Data Map','lede'=>'Organize your dataset into qualitative, quantitative, demographic, and integration roles.','done'=>false,
   'palette'=>['intro'=>'Roles','groups'=>[]]],
  // Data quality gate — conditions that can distort or weaken the analysis,
  // each with the risk it carries. Shown before the analysis in every design.
  ['id'=>'data_quality','label'=>'Data Quality','strand'=>'neutral','mode'=>'quality','route'=>null,
   'title'=>'Data Quality','lede'=>'Check the conditions that can distort or weaken your analysis before you run it. Address what you can; note the rest.','done'=>false,
   'palette'=>['intro'=>'Checks','groups'=>[]],
   'checks'=>[
     ['name'=>'Missing data',      'risk'=>'Results may be biased'],
     ['name'=>'Scale reliability', 'risk'=>'Weak scales weaken interpretation'],
     ['name'=>'Variable clarity',  'risk'=>'Poor variables produce poor explanations'],
     ['name'=>'Group size',        'risk'=>'Some comparisons may be unstable'],
     ['name'=>'Outliers',          'risk'=>'Extreme values may distort results'],
   ]],
];
$CONCLUDE = [
  ['id'=>'interp','label'=>'Integrated Interpretation','strand'=>'neutral','mode'=>'work','route'=>'/cohort-summary.php',
   'title'=>'Integrated Interpretation','lede'=>'Interpret what the combined evidence means for the decision.',
   'palette'=>['intro'=>'Interpretation views:','groups'=>[['name'=>'Lenses','items'=>[
     ['name'=>'For the decision','strand'=>'both'],['name'=>'For the field','strand'=>'both']]]]]],
  ['id'=>'evidence_strength','label'=>'Evidence Strength','strand'=>'both','mode'=>'work','route'=>null,
   'title'=>'Evidence Strength','lede'=>'Gauge how strong the integrated evidence is before you report it.',
   'palette'=>['intro'=>'Strength views:','groups'=>[['name'=>'Strength','items'=>[
     ['name'=>'Per finding','strand'=>'both'],['name'=>'Overall','strand'=>'both']]]]]],
  ['id'=>'report','label'=>'Report Builder','strand'=>'neutral','mode'=>'work','route'=>'/executive-summary.php',
   'title'=>'Report Builder','lede'=>'Assemble findings, limitations, and quotes into the report.',
   'palette'=>['intro'=>'Report sections:','groups'=>[['name'=>'Sections','items'=>[
     ['name'=>'Findings','strand'=>'both'],['name'=>'Limitations','strand'=>'neutral'],['name'=>'Quotes','strand'=>'qual']]]]]],
];

// ---------- Step library (the design-specific middle) ----------
$TOOLS = [
  'q_instr'=>['label'=>'Instrument Quality','strand'=>'quan','mode'=>'work','route'=>null,
    'title'=>'Instrument Quality','lede'=>'Confirm the measure is trustworthy. Reliability is referenced read-only from RSSI.',
    'palette'=>['intro'=>'Instrument references:','groups'=>[['name'=>'From RSSI','items'=>[
      ['name'=>'Reliability (read-only)','strand'=>'quan'],['name'=>'Scale composition','strand'=>'quan']]]]]],
  'q_desc'=>['label'=>'Quantitative Descriptives','strand'=>'quan','mode'=>'work','route'=>'/group-summaries.php',
    'title'=>'Quantitative Descriptives','lede'=>'Describe what the numbers show before testing anything.',
    'palette'=>['intro'=>'Pick a descriptive view:','groups'=>[['name'=>'Descriptives','items'=>[
      ['name'=>'Frequencies','strand'=>'quan'],['name'=>'Means & Distributions','strand'=>'quan'],
      ['name'=>'Cross-Tabs','strand'=>'quan'],['name'=>'Group Summaries','strand'=>'quan']]]]]],
  'q_inf'=>['label'=>'Quantitative Results','strand'=>'quan','mode'=>'work','route'=>'/recommended-analyses.php',
    'title'=>'Quantitative Results','lede'=>'Test the patterns your other strand will need to interpret.',
    'palette'=>['intro'=>'Choose a test:','groups'=>[['name'=>'Tests','items'=>[
      ['name'=>'t-test','strand'=>'quan'],
      ['name'=>'ANOVA','strand'=>'quan'],
      ['name'=>'Chi-square','strand'=>'quan'],
      ['name'=>'Correlation','strand'=>'quan'],
      ['name'=>'Regression','strand'=>'quan'],
      ['name'=>'Reliability','strand'=>'quan']]]]]],
  'q_build'=>['label'=>'Build & Test Measures','strand'=>'quan','mode'=>'work','route'=>null,
    'title'=>'Build & Test Measures','lede'=>'Run the measures you derived from the themes and test them on the sample.',
    'palette'=>['intro'=>'Test the new measures:','groups'=>[
      ['name'=>'Build','items'=>[['name'=>'Item performance','strand'=>'quan'],['name'=>'Scale reliability','strand'=>'quan']]],
      ['name'=>'Confirm','items'=>[['name'=>'T-Test','strand'=>'quan'],['name'=>'Effect Sizes','strand'=>'quan']]]]]],
  'l_trust'=>['label'=>'Trustworthiness','strand'=>'qual','mode'=>'work','route'=>null,
    'title'=>'Trustworthiness','lede'=>'Document the credibility of the qualitative work, the qualitative parallel to instrument quality.',
    'palette'=>['intro'=>'Credibility checks:','groups'=>[['name'=>'Rigor','items'=>[
      ['name'=>'Audit trail','strand'=>'qual'],['name'=>'Member checking','strand'=>'qual'],['name'=>'Coding agreement (κ)','strand'=>'qual']]]]]],
  // Native Qualitative Themes view (renderThemes) wired to codebook.php /
  // coded-responses.php / build.php. route=null so it does NOT mount the legacy
  // 360 /comment-theme.php engine, which is a different feature.
  'l_themes'=>['label'=>'Qualitative Themes','strand'=>'qual','mode'=>'work','route'=>null,
    'title'=>'Qualitative Themes','lede'=>'Build and review the themes that carry the meaning behind the responses.',
    'palette'=>['intro'=>'Themes','groups'=>[]]],
  'l_bygroup'=>['label'=>'Theme by Group','strand'=>'qual','mode'=>'work','route'=>null,
    'title'=>'Theme by Group','lede'=>'See how each theme distributes across the groups the quantitative side compared.',
    'palette'=>['intro'=>'Group the themes:','groups'=>[['name'=>'Crosscuts','items'=>[
      ['name'=>'by Group A','strand'=>'qual'],['name'=>'by Group B','strand'=>'qual']]]]]],
  'l_book'=>['label'=>'Codebook & Evidence','strand'=>'qual','mode'=>'work','route'=>null,
    'title'=>'Codebook & Evidence','lede'=>'Define the codes and the evidence behind each theme.',
    'palette'=>['intro'=>'Structure the codes:','groups'=>[['name'=>'Codebook','items'=>[
      ['name'=>'Code list','strand'=>'qual'],['name'=>'Coding rules','strand'=>'qual'],['name'=>'Co-occurrence matrix','strand'=>'qual']]]]]],
  'l_exemp'=>['label'=>'Exemplar Quotes','strand'=>'qual','mode'=>'work','route'=>'/open-ended-summary.php',
    'title'=>'Exemplar Quotes','lede'=>'Select the quotes that best represent each theme for the report.',
    'palette'=>['intro'=>'Pull quotes:','groups'=>[['name'=>'Quotes','items'=>[
      ['name'=>'Quotes · Theme one','strand'=>'qual'],['name'=>'Quotes · Theme two','strand'=>'qual']]]]]],
  // Native joint display (renderJoint) on joint-display.php's GET JSON. route=null
  // so it does NOT iframe the API endpoint (which would show raw JSON).
  'joint'=>['label'=>'Joint Displays','strand'=>'both','mode'=>'work','route'=>null,
    'title'=>'Joint Displays','lede'=>"Lay each theme's two strands side by side in one display.",
    'palette'=>['intro'=>'Joint display','groups'=>[]]],
  'converge'=>['label'=>'Convergence & Divergence','strand'=>'both','mode'=>'work','route'=>null,
    'title'=>'Convergence & Divergence','lede'=>'Name where the strands agree, expand on each other, or contradict.',
    'palette'=>['intro'=>'Inspect the alignment:','groups'=>[['name'=>'Patterns','items'=>[
      ['name'=>'Points of agreement','strand'=>'both'],['name'=>'Points of divergence','strand'=>'both']]]]]],
  'meta'=>['label'=>'Meta-inferences','strand'=>'both','mode'=>'work','route'=>null,
    'title'=>'Meta-inferences','lede'=>'Draw the higher-order inferences only the combined evidence supports.',
    'palette'=>['intro'=>'Build inferences:','groups'=>[['name'=>'Synthesis','items'=>[
      ['name'=>'Meta-inferences','strand'=>'both']]]]]],
  'qual_sampling'=>['label'=>'Qualitative Sampling Plan','strand'=>'qual','mode'=>'work','route'=>null,
    'title'=>'Qualitative Sampling Plan','lede'=>'Decide who to follow up with qualitatively to explain the results you selected.',
    'palette'=>['intro'=>'Plan the follow-up:','groups'=>[['name'=>'Sampling','items'=>[
      ['name'=>'Who to interview','strand'=>'qual'],['name'=>'What to ask','strand'=>'qual']]]]]],
  'explain_map'=>['label'=>'Quant → Qual Explanation Map','strand'=>'both','mode'=>'work','route'=>null,
    'title'=>'Quant → Qual Explanation Map','lede'=>'Map each quantitative result to the qualitative theme that explains it.',
    'palette'=>['intro'=>'Map explanations:','groups'=>[['name'=>'Mapping','items'=>[
      ['name'=>'Result → theme','strand'=>'both']]]]]],
];

// ---------- Pivots (a numbered, dashed handoff step) ----------
$PIVOTS = [
  'merge'=>['id'=>'merge','label'=>'Merge & Compare','strand'=>'both','mode'=>'work','pivot'=>true,'route'=>null,
    'title'=>'Merge & Compare','lede'=>'The two strands meet here as equals. Build both fully, then bring them together to compare.',
    'palette'=>['intro'=>'Carry across:','groups'=>[
      ['name'=>'Quantitative','items'=>[['name'=>'Group differences','strand'=>'quan']]],
      ['name'=>'Qualitative','items'=>[['name'=>'Themes & coverage','strand'=>'qual']]]]]],
  'explain'=>['id'=>'explain','label'=>'Identify Results to Explain','strand'=>'both','mode'=>'work','pivot'=>true,'route'=>null,
    'title'=>'Identify Results to Explain','lede'=>'Pick the quantitative findings that most need a human explanation; they shape the qualitative phase.',
    'palette'=>['intro'=>'Carry into the qual phase:','groups'=>[['name'=>'Findings to explain','items'=>[
      ['name'=>'Largest group gap','strand'=>'quan'],['name'=>'Unexpected result','strand'=>'quan']]]]]],
  'q2q'=>['id'=>'q2q','label'=>'Qual → Quant','strand'=>'both','mode'=>'work','pivot'=>true,'route'=>null,
    'title'=>'Qual → Quant','lede'=>'Turn the themes you built into measurable variables, so the quantitative phase can test them.',
    'palette'=>['intro'=>'Build measures from themes:','groups'=>[
      ['name'=>'From themes','items'=>[['name'=>'Theme one → items','strand'=>'qual']]],
      ['name'=>'Into measures','items'=>[['name'=>'Draft scale','strand'=>'quan']]]]]],
];

// ---------- The 3 core mixed-methods designs (Creswell & Plano Clark) ----------
// The studio presents THESE three. The A–E framing answers (below) are the
// recommendation questions that determine which of the three to pick.
// `mid` is an ordered list of step ids: a TOOLS key (string) or a pivot ref ('@merge').
$DESIGNS = [
  'convergent'=>['short'=>'Convergent Parallel','lead'=>'both','leadLabel'=>'Equal',
    'why'=>'You chose <b>Convergent Parallel</b>: the quantitative and qualitative strands run as equals, then meet to compare where they agree and differ.',
    'flow'=>[['quan','QUAN'],['qual','QUAL'],['both','Merge']],
    'mid'=>['q_desc','q_inf','l_trust','l_themes','l_bygroup','@merge','joint','converge','meta']],
  'explanatory'=>['short'=>'Explanatory Sequential','lead'=>'quan','leadLabel'=>'QUAN first',
    'why'=>'You chose <b>Explanatory Sequential</b>: the numbers come first, then qualitative work explains them. Use the pivot to mark which results most need explaining.',
    'flow'=>[['quan','QUAN'],['both','explain'],['qual','QUAL']],
    'mid'=>['q_desc','q_inf','@explain','qual_sampling','l_themes','l_book','explain_map','joint','converge']],
  'exploratory'=>['short'=>'Exploratory Sequential','lead'=>'qual','leadLabel'=>'QUAL first',
    'why'=>'You chose <b>Exploratory Sequential</b>: themes come first and shape what you later measure. Use the Qual → Quant pivot to turn themes into measures.',
    'flow'=>[['qual','QUAL'],['both','build'],['quan','QUAN']],
    'mid'=>['l_trust','l_themes','l_book','@q2q','q_build','q_desc','joint','converge','meta']],
];
$DESIGN_ORDER = ['convergent','explanatory','exploratory'];
$DESIGN_DEFAULT = 'explanatory';

// Map the backend's A–E recommendation slugs (mm_projects.design_choice, validated
// by api/mm/wizard.php) to the 3 core designs, and back to a representative slug
// for persistence. A → explanatory; C/E → convergent; B/D → exploratory.
$AE_TO_CORE = [
  'A_explain_numbers'        => 'explanatory',
  'B_comments_to_themes'     => 'exploratory',
  'C_compare_themes_groups'  => 'convergent',
  'D_variables_from_text'    => 'exploratory',
  'E_full_integrated_report' => 'convergent',
];
$CORE_TO_AE = [
  'convergent'  => 'C_compare_themes_groups',
  'explanatory' => 'A_explain_numbers',
  'exploratory' => 'B_comments_to_themes',
];

// "Help me choose" — the A–E framing questions that recommend one of the 3.
// (slug = core design it points to, strand, badge, headline, sub.)
$HELP = [
  ['explanatory','quan','QUAN FIRST','Explain my survey numbers with comments','I have survey results and open-ended answers that explain them.'],
  ['convergent','both','EQUAL','Compare themes across groups','I want to compare how groups differ in numbers and in their words.'],
  ['exploratory','qual','QUAL FIRST','Build variables from open-ended data','Text is my main source and I want to shape what I measure from it.'],
  ['convergent','both','EQUAL','Create a full integrated report','I want a complete mixed-methods report pulling everything together.'],
];

return [
  'setup'      => $SETUP,
  'conclude'   => $CONCLUDE,
  'tools'      => $TOOLS,
  'pivots'     => $PIVOTS,
  'designs'    => $DESIGNS,
  'order'      => $DESIGN_ORDER,
  'default'    => $DESIGN_DEFAULT,
  'help'       => $HELP,
  'ae_to_core' => $AE_TO_CORE,
  'core_to_ae' => $CORE_TO_AE,
];
