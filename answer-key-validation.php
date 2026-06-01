<?php
// Answer Key Validation mount.

$valid_studios = ['survey', 'mm', 'tia', '360'];
$studio_slug   = $_GET['studio'] ?? 'tia';
if (!in_array($studio_slug, $valid_studios, true)) $studio_slug = 'tia';
$current_studio  = $studio_slug;
$current_section = 'inferential';
$current_item    = 'answer_key_validation';
$lens_key        = 'answer_key_validation';
$shell_body_attrs = 'data-current-studio="' . $current_studio . '"';
$_studios = require __DIR__ . '/_studio_registry.php';
$_apps    = require __DIR__ . '/_app_registry.php';
$_studio  = $_studios[$current_studio];
$_app     = $_apps['tia_analysis'];

$csv =
  "student_id,gender,grade,q1,q2,q3,q4,q5,q6,q7,q8\n" .
  "S01,F,8,A,B,C,D,B,B,A,C\nS02,F,8,A,B,C,D,B,B,A,C\nS03,F,8,A,B,C,D,B,B,A,C\nS04,M,8,A,B,C,D,B,B,A,C\n" .
  "S05,M,8,A,B,C,A,B,B,A,C\nS06,F,8,A,B,C,A,B,B,A,C\nS07,F,8,A,B,B,A,B,B,A,C\nS08,M,8,A,B,C,A,B,A,A,B\n" .
  "S09,F,8,A,B,C,C,B,B,A,C\nS10,M,8,A,B,C,A,A,B,A,C\nS11,F,8,A,C,C,A,B,B,A,C\nS12,M,8,A,B,C,A,B,A,A,C\n" .
  "S13,M,8,B,B,B,A,B,C,A,C\nS14,F,8,A,C,C,A,A,C,A,B\nS15,M,8,A,C,B,A,A,A,A,B\nS16,M,8,C,C,B,A,A,A,B,C\n" .
  "S17,F,8,B,C,C,A,A,C,A,C\nS18,F,8,A,B,D,A,C,A,A,B\nS19,M,8,B,B,C,A,A,A,B,C\nS20,F,8,A,C,A,A,B,C,A,C\n";

$lines = explode("\n", trim($csv));
$headers = str_getcsv(array_shift($lines)); $rows = [];
foreach ($lines as $line) { if (trim($line) === '') continue; $rows[] = str_getcsv($line); }
$type_map = ['student_id'=>['id'],'gender'=>['categorical'],'grade'=>['categorical']];
$variables = [];
foreach ($headers as $colIdx => $name) {
  $name = trim($name);
  $values = array_map(function ($r) use ($colIdx) { return $r[$colIdx] ?? ''; }, $rows);
  $types = $type_map[$name] ?? (preg_match('/^q\d+/i', $name) ? ['item_response'] : ['categorical']);
  $variables[] = ['name'=>$name,'types'=>$types,'values'=>$values];
}
$answer_key = [
  ['item'=>'q1','correct'=>'A','max'=>1,'type'=>'MC'],['item'=>'q2','correct'=>'B','max'=>1,'type'=>'MC'],
  ['item'=>'q3','correct'=>'C','max'=>1,'type'=>'MC'],['item'=>'q4','correct'=>'D','max'=>1,'type'=>'MC'],
  ['item'=>'q5','correct'=>'A','max'=>1,'type'=>'MC'],['item'=>'q6','correct'=>'B','max'=>1,'type'=>'MC'],
  ['item'=>'q7','correct'=>'A','max'=>1,'type'=>'MC'],['item'=>'q8','correct'=>'C','max'=>1,'type'=>'MC'],
];
$dataset = ['source'=>'Math Grade 8 Assessment (sample)','variables'=>$variables,'rowCount'=>count($rows),'answer_key'=>$answer_key];

$shell_page_title = 'Answer Key Validation · ' . $_studio['name'];
$shell_user_initials = 'DE'; $shell_user_full = 'Don Easton-Brooks'; $shell_project_label = 'Math Grade 8 Assessment (sample)';
$teaching_cards = [];

include __DIR__ . '/_platform_shell_header.php';
?>
<link rel="stylesheet" href="/studio-template.css">
<link rel="stylesheet" href="<?= htmlspecialchars($_app['css']) ?>">
<?php include __DIR__ . '/_studio_template_header.php'; ?>

<div class="work-breadcrumb"><span>Inferential Analysis</span><span class="sep">/</span><strong>Answer Key Validation</strong></div>
<div class="work-head">
  <h2>Answer Key Validation</h2>
  <p>Catches miskeyed items by comparing your answer key to what the top-scoring students actually wrote. If the upper group most often chose B but your key says A, the item is almost certainly miskeyed and every student's total score is being affected. (The sample dataset on this page deliberately includes one miskeyed item to demonstrate the catch.)</p>
</div>

<?php include $_app['render']; ?>
<?php include __DIR__ . '/_studio_template_footer.php'; ?>
<script>
  window.TIA_LENS    = <?= json_encode($lens_key) ?>;
  window.TIA_DATASET = <?= json_encode($dataset, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
</script>
<script src="<?= htmlspecialchars($_app['js']) ?>" defer></script>
<?php include __DIR__ . '/_platform_shell_footer.php'; ?>
