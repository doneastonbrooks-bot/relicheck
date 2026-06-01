<?php
// Project Snapshot · Overview lens.
// First item in the Overview rail section. Lens of the apps/overview/
// engine-plus-lenses bundle. All wiring lives in _studio_mount.php.

$mount_app            = 'overview';
$mount_lens           = 'project_snapshot';
$mount_section        = 'overview';
$mount_item           = 'project_snapshot';
$mount_breadcrumb     = ['Overview', 'Project Snapshot'];
$mount_title          = 'Project Snapshot';
$mount_intro          = 'One-page overview of the project: sample size, variable counts by type, completion rate, response date range, project purpose, and the full variable list with missingness per column.';
$mount_dataset_global = 'OVERVIEW_DATASET';
$mount_lens_global    = 'OVERVIEW_LENS';

include __DIR__ . '/_studio_mount.php';
