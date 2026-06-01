<?php
// Legacy redirect: the Survey-side Data Upload demo lived here in
// the two-app phase (data_upload + data_upload_test). After the Pass 1
// refactor on 2026-05-25 there is one Evidence Intake engine, mounted
// via /evidence-intake.php?studio=<slug>. This file 302-redirects so
// any bookmarked / shared URL keeps working.
header('Location: /evidence-intake.php?studio=survey', true, 302);
exit;
