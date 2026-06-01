<?php
$valid_studios = ['survey', 'mm', 'tia', '360'];
$studio_slug   = $_GET['studio'] ?? '360';
if (!in_array($studio_slug, $valid_studios, true)) $studio_slug = '360';
$current_studio  = $studio_slug; $current_section = 'inferential'; $current_item = 'competency_profiles';
$lens_key = 'competency_profile';
$shell_body_attrs = 'data-current-studio="' . $current_studio . '"';
$_studios = require __DIR__ . '/_studio_registry.php';
$_apps    = require __DIR__ . '/_app_registry.php';
$_studio  = $_studios[$current_studio];
$_app     = $_apps['threesixty_analysis'];
$csv = "ratee_id,rater_role,rater_id,communication,judgment,accountability,leadership,strategic_thinking,comment\nAlex,Self,A_self,5,5,4,4,5,\"Strong communicator and reliable\"\nAlex,Peer,A_p1,4,5,4,5,4,\"Great judgment under pressure\"\nAlex,Peer,A_p2,5,4,5,4,5,\"Accountable and clear\"\nAlex,Manager,A_mg,4,5,4,5,4,\"Reliable leader\"\nAlex,Direct Report,A_dr1,4,5,4,4,4,\"Good direction\"\nAlex,Direct Report,A_dr2,5,4,4,5,4,\"Listens well\"\nBeth,Self,B_self,5,5,5,5,5,\"I'm a strong leader\"\nBeth,Peer,B_p1,3,3,3,3,3,\"Average across the board\"\nBeth,Peer,B_p2,3,4,3,3,3,\"Could be more decisive\"\nBeth,Manager,B_mg,3,3,3,3,3,\"Needs to grow into the role\"\nBeth,Direct Report,B_dr1,2,3,2,3,3,\"Hard to read\"\nBeth,Direct Report,B_dr2,3,3,3,3,3,\"Average manager\"\nCarl,Self,C_self,2,3,3,2,3,\"Lots to learn\"\nCarl,Peer,C_p1,4,4,4,4,4,\"Solid performer\"\nCarl,Peer,C_p2,4,4,4,5,4,\"Underrated leader\"\nCarl,Manager,C_mg,4,5,4,4,4,\"Promising\"\nCarl,Direct Report,C_dr1,4,5,4,4,5,\"Caring leader, listens\"\nCarl,Direct Report,C_dr2,4,4,4,4,4,\"Reliable\"\nDana,Self,D_self,4,4,4,3,4,\"Trying to grow into the role\"\nDana,Peer,D_p1,4,4,3,3,4,\"Solid contributor\"\nDana,Peer,D_p2,3,4,3,2,3,\"Quiet, hard to read\"\nDana,Manager,D_mg,4,4,3,3,4,\"Building strategic thinking\"\nDana,Direct Report,D_dr1,4,4,4,4,4,\"Approachable\"\nDana,Direct Report,D_dr2,3,4,3,2,3,\"Reserved\"\n";
$lines = explode("\n", trim($csv));
$headers = str_getcsv(array_shift($lines)); $rows = [];
foreach ($lines as $line) { if (trim($line) === '') continue; $rows[] = str_getcsv($line); }
$type_map = ['ratee_id'=>['categorical'],'rater_role'=>['categorical'],'rater_id'=>['id'],'communication'=>['likert'],'judgment'=>['likert'],'accountability'=>['likert'],'leadership'=>['likert'],'strategic_thinking'=>['likert'],'comment'=>['open']];
$variables = [];
foreach ($headers as $colIdx => $name) { $name = trim($name); $values = array_map(function ($r) use ($colIdx) { return $r[$colIdx] ?? ''; }, $rows); $variables[] = ['name'=>$name,'types'=>$type_map[$name] ?? ['categorical'],'values'=>$values]; }
$dataset = ['source'=>'360 Feedback Sample (4 ratees × 6 raters)','variables'=>$variables,'rowCount'=>count($rows)];
$shell_page_title = 'Competency Profile · ' . $_studio['name'];
$shell_user_initials = 'DE'; $shell_user_full = 'Don Easton-Brooks'; $shell_project_label = '360 Feedback Sample';
$teaching_cards = [];
include __DIR__ . '/_platform_shell_header.php';
?>
<link rel="stylesheet" href="/studio-template.css"><link rel="stylesheet" href="<?= htmlspecialchars($_app['css']) ?>">
<?php include __DIR__ . '/_studio_template_header.php'; ?>
<div class="work-breadcrumb"><span>Inferential Analysis</span><span class="sep">/</span><strong>Competency Profile</strong></div>
<div class="work-head"><h2>Competency Profile</h2><p>The ratee's profile across competencies, ranked from strongest to weakest based on the mean rating from others (Self excluded). Surfaces the top 2 strengths and bottom 2 gaps so development conversations start with the biggest signal.</p></div>
<?php include $_app['render']; ?>
<?php include __DIR__ . '/_studio_template_footer.php'; ?>
<script>
  window.T6_LENS    = <?= json_encode($lens_key) ?>;
  window.T6_DATASET = <?= json_encode($dataset, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
</script>
<script src="<?= htmlspecialchars($_app['js']) ?>" defer></script>
<?php include __DIR__ . '/_platform_shell_footer.php'; ?>
