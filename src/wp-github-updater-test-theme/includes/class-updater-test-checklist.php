<?php

declare(strict_types=1);

defined('ABSPATH') || exit;

use ScottOffen\WpGithubUpdater\Package\PackageUpdater;

/**
 * Appearance > Updater Test Theme (checklist). It shows the test checklist from
 * checklist.json: every item with a tick box and a note, a hint where the page
 * can work out for itself whether the item looks right, and a report to copy.
 *
 * The same text is in TESTING.md. Ticks, notes and passes are saved in one
 * option, so they stay after reloads and after the fixture is updated.
 *
 * A hint is worked out from what the page knows. The moment an item passes, the
 * page stores what it saw, so the pass stays after the log moves on. An item can
 * pass only after the item before it has passed or been ticked, only from a
 * request newer than that one, and only from a request that has the cause the
 * item names. A hint never ticks a box. The report never contains the token.
 */
final class Updater_Test_Theme_Checklist
{
    private const SLUG = 'wpgutt_checklist';
    private const OPTION = 'wpgutt_checklist';
    private const ACTION = 'wpgutt_checklist_action';
    private const NOTE_LIMIT = 2000;
    private const RESET = 'The checklist was reset.';

    /** The causes that tie an item to a request in the log. */
    private const BOUND = ['save' => true, 'remove' => true, 'check' => true, 'wordpress' => true, 'download' => true];

    /** @var array<string, mixed>|null */
    private ?array $data = null;

    public function __construct(
        private readonly PackageUpdater $updater,
        private readonly Updater_Test_Theme_Page $page,
        private readonly string $styleFile,
        private readonly Updater_Test_Theme_Hints $hints = new Updater_Test_Theme_Hints(),
    ) {
    }

    public static function boot(PackageUpdater $updater, Updater_Test_Theme_Page $page, string $styleFile): self
    {
        $checklist = new self($updater, $page, $styleFile);

        add_action('admin_menu', $checklist->addMenu(...));

        // A pass is remembered the moment it happens: at the end of a request that went to GitHub, when WordPress
        // writes its own update list, and when the test page or this page is viewed.
        add_action('shutdown', $checklist->captureAfterRequests(...));
        add_action('set_site_transient_update_themes', $checklist->capture(...));
        add_action('wpgutt_test_page_viewed', $checklist->capture(...));

        return $checklist;
    }

    public function addMenu(): void
    {
        add_theme_page(
            'Updater Test Theme (checklist)',
            'Updater Test Theme (checklist)',
            'manage_options',
            self::SLUG,
            $this->render(...)
        );
    }

    public function render(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die('You do not have permission to view this page.');
        }

        $data = $this->data();
        $message = $this->handlePost($data);
        $this->capture();
        $progress = $this->progress();
        $snapshot = $this->snapshot();
        $verdicts = $this->verdicts($data, $snapshot);
        $ticked = count(array_filter($progress['items'], static fn (array $i): bool => !empty($i['done'])));

        echo '<div class="wrap"><h1>Updater Test Theme (checklist)</h1>';
        $this->styles();

        // Every message but the reset goes in the pinned bar, because the page keeps your place and the top of it is out of sight.
        $inBar = $message !== '' && $message !== self::RESET;

        if ($message === self::RESET) {
            echo '<div class="notice notice-info inline"><p>' . esc_html($message) . '</p></div>';
        }

        echo '<p>' . esc_html($ticked . ' of ' . count($data['items']) . ' items ticked.') . ' ';
        echo '<a href="' . esc_url(admin_url('themes.php?page=wpgutt_test_page')) . '">Open the test page</a> for the details and the request log.</p>';

        $this->environment($snapshot);
        $this->guide($data);

        echo '<form method="post" id="wpgutt-checklist-form">';
        wp_nonce_field(self::ACTION);

        foreach ($data['parts'] as $part) {
            $items = array_values(array_filter($data['items'], static fn (array $i): bool => $i['part'] === $part['id']));

            if ($items === []) {
                continue;
            }

            echo '<h2>' . esc_html('Part ' . $part['id'] . ': ' . $part['title']) . '</h2>';

            foreach ($part['intro'] as $paragraph) {
                echo '<p>' . $this->inline($paragraph) . '</p>';
            }

            foreach ($items as $item) {
                $this->item($item, $progress['items'][$item['id']] ?? [], $verdicts[$item['id']] ?? null, $progress['passes'][$item['id']] ?? null, $this->waitingFor($item, $data, $progress));
            }
        }

        echo '<div class="wpgutt-savebar"><button class="button button-primary button-large" name="wpgutt_checklist_action" value="save">Save Test Results</button> ';
        echo '<button class="button button-large" name="wpgutt_checklist_action" value="checkall">Check all steps</button> ';
        echo '<span class="wpgutt-dirty" role="status" hidden>Unsaved changes</span>';
        echo '<span class="wpgutt-savestatus" role="status">' . ($inBar ? esc_html($message) : '') . '</span></div>';
        echo '</form>';
        $this->keepScrollPosition();
        $this->warnAboutUnsavedChanges();

        echo '<h2>What to Send Back</h2>';
        echo '<p>Copy the report and paste it into your reply. It holds the versions, every tick and note, the details table and the request log, and never the token.</p>';
        $this->copyBox('wpgutt-report', $this->report($data, $progress, $snapshot, $verdicts), 'Copy the report', 22);

        echo '<h2>Appendix A: Sample Release Notes</h2>';
        echo '<p>Paste this as the description of the release.</p>';
        $this->copyBox('wpgutt-sample', (string) $data['sample_notes'], 'Copy the sample notes', 12);

        echo '<h2>Appendix B: Hostile Notes</h2>';
        echo '<p>This is for your own test site only.</p>';
        $this->copyBox('wpgutt-hostile', (string) $data['hostile_notes'], 'Copy the hostile notes', 14);

        echo '<form method="post" style="margin-top:2em">';
        wp_nonce_field(self::ACTION);
        echo '<button class="button" name="wpgutt_checklist_action" value="reset" onclick="return confirm(\'Clear every tick and note?\');">Reset the checklist</button>';
        echo '</form></div>';
    }

    /**
     * @return array{parts: array<int, array<string, mixed>>, items: array<int, array<string, mixed>>, sample_notes: string, hostile_notes: string}
     */
    private function data(): array
    {
        if ($this->data === null) {
            $raw = is_file(__DIR__ . '/checklist.json') ? file_get_contents(__DIR__ . '/checklist.json') : false;
            $decoded = $raw !== false ? json_decode($raw, true) : null;

            $this->data = is_array($decoded) ? $decoded : ['before' => [], 'where' => [], 'howto' => [], 'parts' => [], 'items' => [], 'sample_notes' => '', 'hostile_notes' => ''];
        }

        return $this->data;
    }

    /**
     * @return array{items: array<string, array<string, mixed>>, passes: array<string, array<string, mixed>>, floors: array<string, int>, meta: array<string, mixed>}
     */
    private function progress(): array
    {
        $stored = get_option(self::OPTION, []);
        $stored = is_array($stored) ? $stored : [];

        return [
            'items' => is_array($stored['items'] ?? null) ? $stored['items'] : [],
            'passes' => is_array($stored['passes'] ?? null) ? $stored['passes'] : [],
            'floors' => is_array($stored['floors'] ?? null) ? $stored['floors'] : [],
            'meta' => is_array($stored['meta'] ?? null) ? $stored['meta'] : [],
        ];
    }

    /**
     * @param array{items: array<int, array<string, mixed>>} $data
     */
    private function handlePost(array $data): string
    {
        if (!isset($_POST['wpgutt_checklist_action'])) {
            return '';
        }

        check_admin_referer(self::ACTION);

        $action = sanitize_text_field(wp_unslash($_POST['wpgutt_checklist_action']));
        $progress = $this->progress();

        if ($action === 'reset') {
            $progress['items'] = [];
            $progress['passes'] = [];
            $progress['floors'] = [];
            update_option(self::OPTION, $progress, false);

            return self::RESET;
        }

        $retest = str_starts_with($action, 'retest:') ? substr($action, 7) : null;
        $check = str_starts_with($action, 'check:') ? substr($action, 6) : null;

        if ($action !== 'save' && $action !== 'checkall' && $retest === null && $check === null) {
            return '';
        }

        // Every button saves what is on the page first, so nothing typed is lost.
        $progress = $this->saveResults($data, $progress);

        if ($action === 'checkall' || $check !== null) {
            [$progress, $message] = $this->checkSteps($data, $progress, $check);
            update_option(self::OPTION, $progress, false);

            return $message;
        }

        if ($retest !== null) {
            if (in_array($retest, array_column($data['items'], 'id'), true)) {
                unset($progress['passes'][$retest]);

                if (isset($progress['items'][$retest])) {
                    $progress['items'][$retest]['done'] = false;
                    unset($progress['items'][$retest]['seq']);
                }

                // Only a request from now on can pass it again, not the one that passed it before.
                $progress['floors'][$retest] = $this->page->currentSeq();
            }

            update_option(self::OPTION, $progress, false);

            return 'Item ' . $retest . ' can be tested again.';
        }

        update_option(self::OPTION, $progress, false);

        return 'The test results were saved at ' . wp_date('H:i:s') . '.';
    }

    /**
     * Looks for the evidence now, for one item or for every item the page can
     * check, and ticks each one that has passed. A tick made this way takes its
     * place in the order from the evidence, not from the moment it was saved,
     * so the items after it still count from the right request.
     *
     * @param array<string, mixed> $data
     * @param array<string, mixed> $progress
     * @return array{0: array<string, mixed>, 1: string}
     */
    private function checkSteps(array $data, array $progress, ?string $only): array
    {
        $snapshot = $this->snapshot();
        [$progress] = $this->findPasses($data, $progress, $snapshot);
        $ticked = [];
        $notPassing = [];

        foreach ($data['items'] as $item) {
            $id = (string) $item['id'];

            if (($only !== null && $only !== $id) || empty($item['hint']) || !empty($progress['items'][$id]['done'])) {
                continue;
            }

            if (!isset($progress['passes'][$id])) {
                $notPassing[$id] = $this->whyNot($item, $data, $progress, $snapshot);

                continue;
            }

            $progress['items'][$id] = ['done' => true, 'note' => (string) ($progress['items'][$id]['note'] ?? ''), 'seq' => (int) $progress['passes'][$id]['anchor']];
            $ticked[$id] = $this->when((int) $progress['passes'][$id]['time']);
        }

        if ($only !== null) {
            if ($ticked !== []) {
                return [$progress, 'Item ' . $only . ' passed at ' . $ticked[$only] . ' and is ticked.'];
            }

            return [$progress, isset($notPassing[$only]) ? 'Item ' . $only . ' is not passing yet. ' . $notPassing[$only] : 'Item ' . $only . ' is already ticked, or the page cannot check it.'];
        }

        $message = $ticked === [] ? 'Nothing new to tick.' : 'Ticked ' . count($ticked) . ': ' . implode(', ', array_keys($ticked)) . '.';

        return [$progress, $message . ($notPassing === [] ? '' : ' ' . count($notPassing) . ' not passing yet.')];
    }

    /**
     * Why an item that the page can check has not passed.
     *
     * @param array<string, mixed> $item
     * @param array<string, mixed> $data
     * @param array<string, mixed> $progress
     * @param array<string, mixed> $snapshot
     */
    private function whyNot(array $item, array $data, array $progress, array $snapshot): string
    {
        $waiting = $this->waitingFor($item, $data, $progress);

        if ($waiting !== '') {
            return $waiting;
        }

        $cause = (string) ($item['cause'] ?? 'any');
        $view = $snapshot;

        if (isset(self::BOUND[$cause])) {
            [, $anchor] = $this->gate($item, $progress);
            $rows = $this->rowsAfter($cause, max($anchor, (int) ($progress['floors'][(string) $item['id']] ?? 0)), $snapshot);

            if ($rows === []) {
                return 'No new request caused by ' . $this->page->causeLabel($cause) . ' has been logged. Do the step first, and then check it.';
            }

            $view['log'] = $rows;
        }

        [, $text] = $this->hints->evaluate((string) $item['hint'], $view, (string) ($item['param'] ?? ''));

        return $text;
    }

    /**
     * Takes the ticks and notes from the form. A tick remembers how many requests
     * had been logged when it was ticked, so the items after it count from there.
     *
     * @param array{items: array<int, array<string, mixed>>} $data
     * @param array<string, mixed> $progress
     * @return array<string, mixed>
     */
    private function saveResults(array $data, array $progress): array
    {
        $done = isset($_POST['done']) && is_array($_POST['done']) ? wp_unslash($_POST['done']) : [];
        $notes = isset($_POST['note']) && is_array($_POST['note']) ? wp_unslash($_POST['note']) : [];
        $items = [];

        foreach ($data['items'] as $item) {
            $id = (string) $item['id'];
            $note = isset($notes[$id]) && is_string($notes[$id]) ? mb_substr(sanitize_textarea_field($notes[$id]), 0, self::NOTE_LIMIT) : '';
            $tick = isset($done[$id]);

            if (!$tick && $note === '') {
                continue;
            }

            $entry = ['done' => $tick, 'note' => $note];

            if ($tick) {
                $before = $progress['items'][$id] ?? [];
                $entry['seq'] = !empty($before['done']) && isset($before['seq']) ? (int) $before['seq'] : $this->page->currentSeq();
            }

            $items[$id] = $entry;
        }

        $progress['items'] = $items;

        return $progress;
    }

    /**
     * Action: shutdown. Looks for passes at the end of a request that went to
     * GitHub, because by then the log, the stored state and WordPress's own
     * update list are all up to date.
     */
    public function captureAfterRequests(): void
    {
        if ($this->page->loggedRequests() > 0) {
            $this->capture();
        }
    }

    /**
     * Looks for items that pass now, and stores what the page saw. It never
     * breaks the request it runs in.
     */
    public function capture(): void
    {
        try {
            $data = $this->data();
            $progress = $this->progress();
            [$progress, $changed] = $this->findPasses($data, $progress, $this->snapshot());

            if ($changed) {
                update_option(self::OPTION, $progress, false);
            }
        } catch (Throwable) {
            // Remembering a pass must not break a request.
        }
    }

    /**
     * The rules, in one place:
     * - an item is only looked at once the item before it has passed or been ticked
     * - a ticked item is the person's call, so the page stops looking at it
     * - an item with a cause only passes from a request with that cause, and one newer than the item before it
     * - a request that passes an item with a cause is used up, so the next item needs a newer one
     * - an item with no cause is a fact about the state, so it passes as soon as it is true
     *
     * @param array<string, mixed> $data
     * @param array<string, mixed> $progress
     * @param array<string, mixed> $snapshot
     * @return array{0: array<string, mixed>, 1: bool}
     */
    private function findPasses(array $data, array $progress, array $snapshot): array
    {
        $changed = false;
        $usedHere = [];

        foreach ($data['items'] as $item) {
            $id = (string) $item['id'];
            $hint = (string) ($item['hint'] ?? '');

            if ($hint === '' || isset($progress['passes'][$id]) || !empty($progress['items'][$id]['done'])) {
                continue;
            }

            $cause = (string) ($item['cause'] ?? 'any');
            $bound = isset(self::BOUND[$cause]);
            [$open, $anchor] = $this->gate($item, $progress);

            if (!$open || ($cause !== 'static' && isset($usedHere[(string) ($item['after'] ?? '')]))) {
                continue;
            }

            $anchor = max($anchor, (int) ($progress['floors'][$id] ?? 0));
            $view = $snapshot;
            $rows = [];

            if ($bound) {
                $rows = $this->rowsAfter($cause, $anchor, $snapshot);

                if ($rows === []) {
                    continue;
                }

                $view['log'] = $rows;
            }

            [$verdict, $text] = $this->hints->evaluate($hint, $view, (string) ($item['param'] ?? ''));

            if ($verdict !== Updater_Test_Theme_Hints::MATCH) {
                continue;
            }

            $newest = $bound ? (int) $rows[0]['seq'] : $this->page->currentSeq();
            $progress['passes'][$id] = ['time' => time(), 'seq' => $newest, 'anchor' => $bound ? $newest : $anchor, 'cause' => $cause, 'text' => $text, 'rows' => array_slice($rows, 0, 2)];
            $changed = true;

            if ($bound) {
                $usedHere[$id] = true;
            }
        }

        return [$progress, $changed];
    }

    /**
     * The logged requests with one cause that came after a request number, newest first.
     *
     * @param array<string, mixed> $snapshot
     * @return array<int, array<string, mixed>>
     */
    private function rowsAfter(string $cause, int $anchor, array $snapshot): array
    {
        return array_values(array_filter($snapshot['log'], static fn (array $row): bool => (int) ($row['seq'] ?? 0) > $anchor && ($row['cause'] ?? 'other') === $cause));
    }

    /**
     * The time of day, with the date added when it was not today, because a
     * run can take more than one day.
     */
    private function when(int $time): string
    {
        return wp_date('Y-m-d', $time) === wp_date('Y-m-d') ? wp_date('H:i:s', $time) : wp_date('M j, H:i:s', $time);
    }

    /**
     * Whether an item may pass yet, and the request number it has to come after.
     *
     * @param array<string, mixed> $item
     * @param array<string, mixed> $progress
     * @return array{0: bool, 1: int}
     */
    private function gate(array $item, array $progress): array
    {
        $after = $item['after'] ?? null;

        if ($after === null) {
            return [true, 0];
        }

        $after = (string) $after;

        if (isset($progress['passes'][$after])) {
            return [true, (int) $progress['passes'][$after]['anchor']];
        }

        if (!empty($progress['items'][$after]['done'])) {
            return [true, (int) ($progress['items'][$after]['seq'] ?? 0)];
        }

        return [false, 0];
    }

    /**
     * The line that says why an item that the page can check is not counting yet.
     *
     * @param array<string, mixed> $item
     * @param array<string, mixed> $data
     * @param array<string, mixed> $progress
     */
    private function waitingFor(array $item, array $data, array $progress): string
    {
        if (empty($item['hint']) || isset($progress['passes'][$item['id']]) || $this->gate($item, $progress)[0]) {
            return '';
        }

        foreach ($data['items'] as $other) {
            if ($other['id'] === $item['after']) {
                return 'Not counted yet. It counts after ' . $other['id'] . ' ' . $other['title'] . ' has passed or been ticked.';
            }
        }

        return '';
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshot(): array
    {
        $state = $this->updater->state();
        $release = $state->release;
        $header = get_file_data($this->styleFile, ['UpdateURI' => 'Update URI'], 'theme');
        $theme = wp_get_theme();

        return [
            'env' => [
                'php' => PHP_VERSION,
                'wp' => get_bloginfo('version'),
                'debug' => defined('WP_DEBUG') && WP_DEBUG,
                'debug_log' => defined('WP_DEBUG_LOG') && WP_DEBUG_LOG,
                'folder' => basename(dirname($this->styleFile)),
                'vendor' => is_file(dirname($this->styleFile) . '/vendor/autoload.php'),
                'theme' => $theme->get('Name'),
                'theme_active' => $theme->get_stylesheet() === $this->updater->slug() || $theme->get_template() === $this->updater->slug(),
            ],
            'slug' => $this->updater->slug(),
            'repository' => $this->updater->repository(),
            'update_uri' => $header['UpdateURI'],
            'installed' => $this->updater->installedVersion(),
            'token_source' => $this->updater->tokens()->source(),
            'saved_token' => get_option('wpgutt_updater_github_token', '') !== '',
            'state' => [
                'checked' => $state->hasChecked(),
                'outcome' => $state->outcome,
                'status' => $state->status,
                'etag' => $state->etag,
                'tag' => $release?->tag ?? '',
                'version' => $release?->version ?? '',
                'asset' => $release?->assetName ?? '',
                'digest' => $release !== null && $release->assetDigest !== '',
            ],
            'wp' => $this->page->wordpressEntry(),
            'log' => $this->page->logRows(),
            'library' => ['now' => $this->libraryVersion()],
        ];
    }

    /**
     * @param array{items: array<int, array<string, mixed>>} $data
     * @param array<string, mixed> $snapshot
     * @return array<string, array{0: string, 1: string}>
     */
    private function verdicts(array $data, array $snapshot): array
    {
        $verdicts = [];

        foreach ($data['items'] as $item) {
            if (!empty($item['hint'])) {
                $verdicts[(string) $item['id']] = $this->hints->evaluate((string) $item['hint'], $snapshot, (string) ($item['param'] ?? ''));
            }
        }

        return $verdicts;
    }

    /**
     * The version of the updater library this fixture bundles, read from the
     * fixture's own vendor folder, so it is right even when another plugin or
     * theme bundles a different version.
     */
    private function libraryVersion(): string
    {
        $file = dirname($this->styleFile) . '/vendor/composer/installed.php';

        if (!is_file($file)) {
            return '';
        }

        $installed = include $file;
        $version = is_array($installed) ? ($installed['versions']['scottoffen/wp-github-updater']['pretty_version'] ?? '') : '';

        return ltrim((string) $version, 'vV');
    }

    /**
     * Before You Start, Where Things Are, and the How To sections that the items
     * point to. The How To sections are closed until you open one.
     *
     * @param array<string, mixed> $data
     */
    private function guide(array $data): void
    {
        foreach (['before' => 'Before You Start', 'where' => 'Where Things Are'] as $key => $heading) {
            if (($data[$key] ?? []) === []) {
                continue;
            }

            echo '<h2>' . esc_html($heading) . '</h2><ul style="list-style:disc;margin-left:22px;max-width:900px">';

            foreach ($data[$key] as $line) {
                echo '<li>' . $this->inline((string) $line) . '</li>';
            }

            echo '</ul>';
        }

        if (($data['howto'] ?? []) !== []) {
            echo '<h2>How To</h2>';

            foreach ($data['howto'] as $howto) {
                echo '<details class="wpgutt-howto"><summary>' . esc_html((string) $howto['title']) . '</summary>';
                $this->blocks($howto['blocks']);
                echo '</details>';
            }
        }

        echo '<p>Do the parts in order. Each part assumes the state the one before left behind.</p>';
    }

    /**
     * Paragraphs, code and numbered steps. A list of steps that carries on after
     * a code block starts at the number it was written with.
     *
     * @param array<int, array<string, mixed>> $blocks
     */
    private function blocks(array $blocks): void
    {
        foreach ($blocks as $block) {
            if ($block['t'] === 'code') {
                echo '<pre>' . esc_html((string) $block['text']) . '</pre>';
            } elseif ($block['t'] === 'steps') {
                echo '<ol' . ((int) $block['start'] > 1 ? ' start="' . (int) $block['start'] . '"' : '') . '>';

                foreach ($block['items'] as $step) {
                    echo '<li>' . $this->inline((string) $step) . '</li>';
                }

                echo '</ol>';
            } else {
                echo '<p>' . $this->inline((string) $block['text']) . '</p>';
            }
        }
    }

    /**
     * @param array<string, mixed> $snapshot
     */
    private function environment(array $snapshot): void
    {
        $e = $snapshot['env'];
        $rows = [
            ['PHP', $e['php']],
            ['WordPress', $e['wp']],
            ['WP_DEBUG', $e['debug'] ? 'on' : 'off'],
            ['WP_DEBUG_LOG', $e['debug_log'] ? 'on' : 'off'],
            ['Active theme', $e['theme']],
            ['Fixture', $this->updater->name() . ' ' . $snapshot['installed']],
            ['Updater library', $snapshot['library']['now'] !== '' ? $snapshot['library']['now'] : '(unknown)'],
            ['Repository', $snapshot['repository']],
            ['Token source', $snapshot['token_source']],
        ];

        echo '<h2>Environment</h2><table class="widefat striped" style="max-width:760px"><tbody>';

        foreach ($rows as [$label, $value]) {
            echo '<tr><th style="width:220px">' . esc_html((string) $label) . '</th><td>' . esc_html((string) $value) . '</td></tr>';
        }

        echo '</tbody></table>';
    }

    /**
     * @param array<string, mixed> $item
     * @param array<string, mixed> $saved
     * @param array{0: string, 1: string}|null $verdict
     */
    private function item(array $item, array $saved, ?array $verdict, ?array $pass, string $waiting): void
    {
        $id = (string) $item['id'];
        $anchor = 'item-' . str_replace('.', '-', $id);

        echo '<div class="wpgutt-item" id="' . esc_attr($anchor) . '">';
        echo '<h3>' . esc_html($id . ' ' . $item['title'] . (!empty($item['optional']) ? ' (optional)' : '')) . '</h3>';

        $this->blocks($item['blocks']);

        if ($verdict === null) {
            echo '<p class="wpgutt-hint wpgutt-none">The page cannot check this one.</p>';
        } elseif ($pass !== null) {
            $by = isset(self::BOUND[(string) $pass['cause']]) ? ' (caused by ' . $this->page->causeLabel((string) $pass['cause']) . ')' : '';
            echo '<p class="wpgutt-hint wpgutt-match">' . esc_html('Passed at ' . $this->when((int) $pass['time']) . $by . '. ' . $pass['text']) . '</p>';
        } else {
            [$kind, $text] = $verdict;
            $lead = match ($kind) {
                Updater_Test_Theme_Hints::MATCH => 'The page sees the expected result.',
                Updater_Test_Theme_Hints::DIFFER => 'The page sees something different.',
                default => 'The page has nothing to check yet.',
            };
            echo '<p class="wpgutt-hint wpgutt-' . esc_attr($kind) . '">' . esc_html($lead . ' ' . $text) . '</p>';

            if ($waiting !== '') {
                echo '<p class="wpgutt-gate">' . esc_html($waiting) . '</p>';
            }
        }

        echo '<p><label><input type="checkbox" name="done[' . esc_attr($id) . ']" value="1"' . (!empty($saved['done']) ? ' checked' : '') . '> Done</label> ';
        echo '<input type="text" class="large-text" style="max-width:640px" maxlength="' . self::NOTE_LIMIT . '" name="note[' . esc_attr($id) . ']" placeholder="Note" value="' . esc_attr((string) ($saved['note'] ?? '')) . '">';

        if ($verdict !== null && empty($saved['done'])) {
            echo ' <button class="button button-small" name="wpgutt_checklist_action" value="check:' . esc_attr($id) . '">Check this step</button>';
        }

        if ($verdict !== null && ($pass !== null || !empty($saved['done']))) {
            echo ' <button class="button-link" name="wpgutt_checklist_action" value="retest:' . esc_attr($id) . '">Retest</button>';
        }

        echo '</p></div>';
    }

    /**
     * Escapes the text, then turns `code` into code elements.
     */
    private function inline(string $text): string
    {
        $parts = explode('`', $text);
        $html = '';

        foreach ($parts as $i => $part) {
            $html .= $i % 2 === 1 ? '<code>' . esc_html($part) . '</code>' : esc_html($part);
        }

        return $html;
    }

    private function copyBox(string $id, string $text, string $label, int $rows): void
    {
        echo '<p><button type="button" class="button" onclick="var t=document.getElementById(\'' . esc_attr($id) . '\');t.select();navigator.clipboard&amp;&amp;navigator.clipboard.writeText(t.value);">' . esc_html($label) . '</button></p>';
        echo '<textarea id="' . esc_attr($id) . '" readonly rows="' . $rows . '" class="large-text code" style="max-width:900px">' . esc_textarea($text) . '</textarea>';
    }

    /**
     * The form posts and the page loads again, which would send you back to the
     * top. This keeps the scroll position across that one reload.
     */
    private function keepScrollPosition(): void
    {
        echo '<script>(function(){var k="wpgutt-checklist-scroll",f=document.getElementById("wpgutt-checklist-form");'
            . 'try{var y=window.sessionStorage.getItem(k);if(y!==null){window.sessionStorage.removeItem(k);window.scrollTo(0,parseInt(y,10)||0);}}catch(e){}'
            . 'if(f){f.addEventListener("submit",function(){try{window.sessionStorage.setItem(k,String(window.scrollY));}catch(e){}});}})();</script>';
    }

    /**
     * Marks the bar as soon as something on the page changes, and asks the
     * browser to confirm before you leave the page without saving. Every
     * button on the form saves it, so submitting clears the mark.
     */
    private function warnAboutUnsavedChanges(): void
    {
        echo '<script>(function(){var f=document.getElementById("wpgutt-checklist-form"),m=document.querySelector(".wpgutt-dirty"),d=false;if(!f){return;}'
            . 'function mark(){d=true;if(m){m.hidden=false;}}'
            . 'f.addEventListener("input",mark);f.addEventListener("change",mark);'
            . 'f.addEventListener("submit",function(){d=false;});'
            . 'window.addEventListener("beforeunload",function(e){if(d){e.preventDefault();e.returnValue="";}});})();</script>';
    }

    private function styles(): void
    {
        echo '<style>'
            . '.wpgutt-item{background:#fff;border:1px solid #c3c4c7;border-left-width:4px;padding:0 16px 8px;margin:0 0 14px;max-width:900px}'
            . '.wpgutt-item h3{margin:14px 0 8px}'
            . '.wpgutt-item pre{background:#f6f7f7;padding:8px 10px;overflow:auto}'
            . '.wpgutt-hint{padding:6px 10px;border-left:4px solid #8c8f94;background:#f6f7f7}'
            . '.wpgutt-match{border-left-color:#00a32a;background:#edfaef}'
            . '.wpgutt-differ{border-left-color:#dba617;background:#fcf9e8}'
            . '.wpgutt-waiting{border-left-color:#8c8f94}'
            . '.wpgutt-none{color:#50575e}'
            . '.wpgutt-gate{margin:-4px 0 8px;color:#50575e;font-size:12px}'
            . '.wpgutt-item ol,.wpgutt-howto ol{margin:6px 0 10px 22px}'
            . '.wpgutt-howto{background:#fff;border:1px solid #c3c4c7;padding:6px 14px;margin:0 0 6px;max-width:900px}'
            . '.wpgutt-howto summary{cursor:pointer;font-weight:600}'
            . '.wpgutt-savebar{position:sticky;bottom:0;z-index:50;background:#fff;border-top:1px solid #c3c4c7;box-shadow:0 -2px 6px rgba(0,0,0,.08);padding:10px 16px;margin-top:14px}'
            . '.wpgutt-savestatus{margin-left:10px;color:#1d2327}'
            . '.wpgutt-dirty{margin-left:10px;color:#b32d2e;font-weight:600}'
            . '</style>';
    }

    /**
     * The report to paste into a reply.
     *
     * @param array{items: array<int, array<string, mixed>>} $data
     * @param array{items: array<string, array<string, mixed>>, passes: array<string, array<string, mixed>>, floors: array<string, int>, meta: array<string, mixed>} $progress
     * @param array<string, mixed> $snapshot
     * @param array<string, array{0: string, 1: string}> $verdicts
     */
    private function report(array $data, array $progress, array $snapshot, array $verdicts): string
    {
        $e = $snapshot['env'];
        $lines = [
            'Updater Test Theme report',
            'Generated: ' . gmdate('Y-m-d H:i:s') . ' UTC',
            'Fixture: ' . $this->updater->name() . ' ' . $snapshot['installed'] . ' (' . $snapshot['slug'] . ')',
            'Updater library: ' . ($snapshot['library']['now'] !== '' ? $snapshot['library']['now'] : '(unknown)'),
            'Repository: ' . $snapshot['repository'],
            'WordPress: ' . $e['wp'] . ', PHP: ' . $e['php'],
            'WP_DEBUG: ' . ($e['debug'] ? 'on' : 'off') . ', WP_DEBUG_LOG: ' . ($e['debug_log'] ? 'on' : 'off'),
            'Active theme: ' . $e['theme'],
            'Token source: ' . $snapshot['token_source'],
            '',
        ];

        $ticked = count(array_filter($progress['items'], static fn (array $i): bool => !empty($i['done'])));
        $lines[] = 'Checklist (' . $ticked . ' of ' . count($data['items']) . ' ticked)';

        foreach ($data['items'] as $item) {
            $id = (string) $item['id'];
            $saved = $progress['items'][$id] ?? [];
            $line = (!empty($saved['done']) ? '[x] ' : '[ ] ') . $id . ' ' . $item['title'];

            if (isset($progress['passes'][$id])) {
                $pass = $progress['passes'][$id];
                $by = isset(self::BOUND[(string) $pass['cause']]) ? ', caused by ' . $this->page->causeLabel((string) $pass['cause']) : '';
                $line .= ' | passed at ' . $this->when((int) $pass['time']) . $by . ': ' . $pass['text'];
            } elseif (isset($verdicts[$id])) {
                $line .= ' | page: ' . $verdicts[$id][0] . ($verdicts[$id][0] === Updater_Test_Theme_Hints::DIFFER ? ' (' . $verdicts[$id][1] . ')' : '');
            }

            if (($saved['note'] ?? '') !== '') {
                $line .= ' | note: ' . str_replace(["\r", "\n"], ' ', (string) $saved['note']);
            }

            $lines[] = $line;
        }

        $lines[] = '';
        $lines[] = 'Details';

        foreach ($this->page->detailsRows() as [$label, $value]) {
            $lines[] = $label . ': ' . $value;
        }

        $lines[] = '';
        $lines[] = 'What WordPress holds';

        foreach ($this->page->wordpressRows() as [$label, $value]) {
            $lines[] = $label . ': ' . $value;
        }

        $lines[] = '';
        $lines[] = 'Requests to GitHub (newest first)';
        $lines[] = 'Time | Status | Host | Path | Redirects to | Token sent | Conditional | Rate limit left';

        foreach ($snapshot['log'] as $row) {
            $lines[] = $this->when((int) ($row['time'] ?? 0)) . ' | ' . ($row['status'] ?? '') . ' | ' . ($row['host'] ?? '') . ' | ' . ($row['path'] ?? '') . ' | ' . ($row['redirect'] ?? '') . ' | ' . ($row['token_sent'] ?? '') . ' | ' . ($row['conditional'] ?? '') . ' | ' . ($row['rate_remaining'] ?? '');
        }

        if ($snapshot['log'] === []) {
            $lines[] = '(nothing logged)';
        }

        return implode("\n", $lines);
    }
}
