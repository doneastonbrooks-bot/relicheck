<?php
// Legacy redirect: the TIA-side Test Data Upload demo lived here in
// the two-app phase. After the Pass 1 refactor on 2026-05-25 there is
// one Evidence Intake engine mounted via /evidence-intake.php?studio=tia.
header('Location: /evidence-intake.php?studio=tia', true, 302);
exit;
