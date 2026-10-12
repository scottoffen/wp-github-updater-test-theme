<?php

declare(strict_types=1);

use ScottOffen\WpGithubUpdater\Updater;

if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/includes/class-updater-test-page.php';
require_once __DIR__ . '/includes/class-updater-test-hints.php';
require_once __DIR__ . '/includes/class-updater-test-checklist.php';

$wpgutt_updater = Updater::theme(__FILE__)
    ->fromGitHub('scottoffen/wp-github-updater-test-theme')
    ->prefix('wpgutt')
    ->register();

// The updater adds this page to the Appearance menu.
$wpgutt_updater->settings()->addPage('Updater Test Theme (library page)', 'themes.php');

// This page belongs to the theme. It shows the same section with render(), and adds the details and the
// request log that a test needs. See Updater_Test_Theme_Page.
$wpgutt_page = Updater_Test_Theme_Page::boot($wpgutt_updater, __DIR__ . '/style.css');

// The test checklist, with a tick box and a note for every item. See Updater_Test_Theme_Checklist.
Updater_Test_Theme_Checklist::boot($wpgutt_updater, $wpgutt_page, __DIR__ . '/style.css');
