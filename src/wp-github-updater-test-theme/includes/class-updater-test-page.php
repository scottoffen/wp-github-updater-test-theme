<?php

declare(strict_types=1);

defined('ABSPATH') || exit;

use ScottOffen\WpGithubUpdater\Package\PackageUpdater;
use ScottOffen\WpGithubUpdater\Update\ReleaseState;

/**
 * Appearance > Updater Test Theme (test page). It shows the updater's settings
 * section by calling render(), and adds what only a test needs: the details the
 * section does not show, what WordPress itself holds about the update, a log of
 * every request WordPress sent to GitHub, and buttons that reset test data.
 *
 * The log records whether a token was sent, never the token. It leaves out the
 * query string of every address, because a signed download address carries a
 * secret in it.
 *
 * WordPress runs no code when a theme is deleted, so the log stays in the options
 * table afterward. The "Clear the request log" button removes it.
 *
 * The checklist page reads the same data through the public methods below, so
 * the tables, the hints and the report never disagree.
 */
final class Updater_Test_Theme_Page
{
    private const SLUG = 'wpgutt_test_page';
    private const LOG_OPTION = 'wpgutt_http_log';
    private const STATE_OPTION = 'wpgutt_updater_release_state';
    private const TOKEN_OPTION = 'wpgutt_updater_github_token';
    private const LOG_SIZE = 30;
    private const SEQ_OPTION = 'wpgutt_http_seq';
    private const VIEWED = 'wpgutt_test_page_viewed';

    /** What can cause a request to GitHub, as the log shows it. */
    private const CAUSES = [
        'save' => 'Save token',
        'remove' => 'Remove token',
        'check' => 'Check for updates now',
        'wordpress' => 'WordPress check',
        'download' => 'Download',
        'other' => 'Other',
    ];
    private const ACTION = 'wpgutt_test_action';

    /** How many requests to GitHub have been logged in this request. */
    private int $requests = 0;

    private string $cause = 'other';

    private int $logged = 0;

    public function __construct(
        private readonly PackageUpdater $updater,
        private readonly string $styleFile,
    ) {
    }

    public static function boot(PackageUpdater $updater, string $styleFile): self
    {
        $page = new self($updater, $styleFile);

        add_action('admin_menu', $page->addMenu(...));
        add_action('http_api_debug', $page->logRequest(...), 10, 5);

        // What caused a request to GitHub. These run a step before the updater's own handlers and filters,
        // and change nothing. The first cause in a request is the one the log shows.
        add_action('admin_post_wpgutt_updater_save', static fn () => $page->noteCause('save'), 1);
        add_action('admin_post_wpgutt_updater_remove', static fn () => $page->noteCause('remove'), 1);
        add_action('admin_post_wpgutt_updater_check', static fn () => $page->noteCause('check'), 1);
        add_filter('update_themes_github.com', static function (mixed $update) use ($page): mixed {
            $page->noteCause('wordpress');

            return $update;
        }, 1);
        add_filter('upgrader_pre_download', static function (mixed $reply) use ($page): mixed {
            $page->noteCause('download');

            return $reply;
        }, 0);

        return $page;
    }

    public function addMenu(): void
    {
        add_theme_page(
            'Updater Test Theme (test page)',
            'Updater Test Theme (test page)',
            'manage_options',
            self::SLUG,
            $this->render(...)
        );
    }

    /**
     * Action: http_api_debug. Records the answer to every request WordPress
     * sent to GitHub or to one of its download hosts.
     */
    public function logRequest(mixed $response, mixed $context = '', mixed $class = '', mixed $args = [], mixed $url = ''): void
    {
        if ($context !== 'response' || !is_string($url) || !is_array($args)) {
            return;
        }

        $host = strtolower((string) wp_parse_url($url, PHP_URL_HOST));

        if (!$this->isGitHubHost($host)) {
            return;
        }

        $headers = array_change_key_case(is_array($args['headers'] ?? null) ? $args['headers'] : [], CASE_LOWER);
        $failed = is_wp_error($response);
        $location = $failed ? '' : wp_remote_retrieve_header($response, 'location');

        $log = $this->logRows();

        $this->logged++;

        array_unshift($log, [
            'seq' => $this->nextSeq(),
            'time' => time(),
            'cause' => $this->cause,
            'status' => $failed ? 'error: ' . $response->get_error_message() : (string) wp_remote_retrieve_response_code($response),
            'host' => $host,
            'path' => (string) wp_parse_url($url, PHP_URL_PATH),
            'redirect' => is_string($location) ? (string) wp_parse_url($location, PHP_URL_HOST) : '',
            'token_sent' => isset($headers['authorization']) ? 'yes' : 'no',
            'conditional' => isset($headers['if-none-match']) ? 'yes' : 'no',
            'rate_remaining' => $failed ? '' : (string) wp_remote_retrieve_header($response, 'x-ratelimit-remaining'),
        ]);

        update_option(self::LOG_OPTION, array_slice($log, 0, self::LOG_SIZE), false);
        $this->requests++;
    }

    /**
     * Notes what caused the requests in this PHP request. The first cause wins,
     * so a save that goes on to check is still a save.
     */
    public function noteCause(string $cause): void
    {
        if ($this->cause === 'other' && isset(self::CAUSES[$cause])) {
            $this->cause = $cause;
        }
    }

    /** The label the log shows for a cause. */
    public function causeLabel(string $cause): string
    {
        return self::CAUSES[$cause] ?? self::CAUSES['other'];
    }

    /** How many requests to GitHub were logged in this PHP request. */
    public function loggedRequests(): int
    {
        return $this->logged;
    }

    /** The sequence number of the newest request ever logged, even if the log was cleared since. */
    public function currentSeq(): int
    {
        return (int) get_option(self::SEQ_OPTION, 0);
    }

    /** For tests: forget the cause and the count, as a new PHP request would. */
    public function startRequest(): void
    {
        $this->cause = 'other';
        $this->logged = 0;
    }

    private function nextSeq(): int
    {
        $seq = $this->currentSeq() + 1;
        update_option(self::SEQ_OPTION, $seq, false);

        return $seq;
    }

    /**
     * Whether a host is GitHub or one of its subdomains, so api.github.com and
     * release-assets.githubusercontent.com count, and evilgithub.com does not.
     */
    private function isGitHubHost(string $host): bool
    {
        foreach (['github.com', 'githubusercontent.com'] as $domain) {
            if ($host === $domain || str_ends_with($host, '.' . $domain)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The logged requests, newest first.
     *
     * @return array<int, array<string, mixed>>
     */
    public function logRows(): array
    {
        $log = get_option(self::LOG_OPTION, []);

        return is_array($log) ? $log : [];
    }

    /**
     * The details table as label and value pairs.
     *
     * @return array<int, array{0: string, 1: string}>
     */
    public function detailsRows(): array
    {
        $state = $this->updater->state();
        $release = $state->release;
        $header = get_file_data($this->styleFile, ['UpdateURI' => 'Update URI'], 'theme');

        return [
            ['Repository', $this->updater->repository()],
            ['Slug', $this->updater->slug()],
            ['Installed version', $this->updater->installedVersion() ?: '(none)'],
            ['Update URI header', $header['UpdateURI'] !== '' ? $header['UpdateURI'] : '(none)'],
            ['Token source', $this->updater->tokens()->source()],
            ['Token constant', $this->updater->tokenConstant()],
            ['Saved token option', get_option(self::TOKEN_OPTION, '') !== '' ? 'yes' : 'no'],
            ['Stored state option', get_option(self::STATE_OPTION, null) !== null ? 'yes' : 'no'],
            ['Last check', $state->hasChecked() ? wp_date('Y-m-d H:i:s', $state->checkedAt) : '(never)'],
            ['Last outcome', $state->outcome !== '' ? $state->outcome . ($state->status > 0 ? ' (HTTP ' . $state->status . ')' : '') : '(none)'],
            ['Release found', $release !== null ? $release->tag : '(none)'],
            ['Asset found', $release !== null ? $release->assetName . ', ' . $release->assetSize . ' bytes, digest ' . ($release->assetDigest !== '' ? 'yes' : 'no') : '(none)'],
            ['ETag stored', $state->etag !== '' ? $state->etag : '(none)'],
        ];
    }

    /**
     * What WordPress keeps in its own update data, in a form a test can compare
     * with the updater's stored state. It can differ from that state, for
     * example after a forced check. A theme's entry is an array, and its details
     * address is the page the updater serves.
     *
     * @return array{kind: string, version: string, package: string, details: string, checked: int}
     */
    public function wordpressEntry(): array
    {
        $stylesheet = $this->updater->slug();
        $data = get_site_transient('update_themes');
        $entry = ['kind' => 'none', 'version' => '', 'package' => '', 'details' => '', 'checked' => 0];

        if (!is_object($data)) {
            return $entry;
        }

        $entry['checked'] = !empty($data->last_checked) ? (int) $data->last_checked : 0;

        if (isset($data->response[$stylesheet]) && is_array($data->response[$stylesheet])) {
            $update = $data->response[$stylesheet];
            $entry['kind'] = 'update';
            $entry['version'] = (string) ($update['new_version'] ?? '');
            $entry['package'] = (string) wp_parse_url((string) ($update['package'] ?? ''), PHP_URL_PATH);
            $entry['details'] = $this->addressWithoutHost((string) ($update['url'] ?? ''));
        } elseif (isset($data->no_update[$stylesheet]) && is_array($data->no_update[$stylesheet])) {
            $entry['kind'] = 'current';
            $entry['version'] = (string) ($data->no_update[$stylesheet]['new_version'] ?? '');
            $entry['details'] = $this->addressWithoutHost((string) ($data->no_update[$stylesheet]['url'] ?? ''));
        }

        return $entry;
    }

    /**
     * @return array<int, array{0: string, 1: string}>
     */
    public function wordpressRows(): array
    {
        $entry = $this->wordpressEntry();
        $text = match ($entry['kind']) {
            'update' => 'update available: ' . ($entry['version'] !== '' ? $entry['version'] : '?'),
            'current' => 'up to date: ' . ($entry['version'] !== '' ? $entry['version'] : '?'),
            default => '(no entry)',
        };

        return [
            ['Stylesheet', $this->updater->slug()],
            ['Entry in the update list', $text],
            ['Package path in that entry', $entry['package'] !== '' ? $entry['package'] : '(none)'],
            ['Details address in that entry', $entry['details'] !== '' ? $entry['details'] : '(none)'],
            ['Update list last checked', $entry['checked'] > 0 ? wp_date('Y-m-d H:i:s', $entry['checked']) : '(never)'],
        ];
    }

    /**
     * The path and query of an address, so the page shows where the details link
     * points without repeating the site's own host.
     */
    private function addressWithoutHost(string $address): string
    {
        if ($address === '') {
            return '';
        }

        $path = (string) wp_parse_url($address, PHP_URL_PATH);
        $query = (string) wp_parse_url($address, PHP_URL_QUERY);

        return $path . ($query !== '' ? '?' . $query : '');
    }

    public function render(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die('You do not have permission to view this page.');
        }

        $message = $this->handlePost();

        do_action(self::VIEWED);

        echo '<div class="wrap"><h1>Updater Test Theme (test page)</h1>';

        if ($message !== '') {
            echo '<div class="notice notice-info inline"><p>' . esc_html($message) . '</p></div>';
        }

        // The updater's settings section: the token field, the status, and the buttons.
        $this->updater->settings()->render('Updates');

        echo '<h2>Details the section does not show</h2>';
        $this->table($this->detailsRows());

        echo '<h2>What WordPress holds about this theme</h2>';
        $this->table($this->wordpressRows());

        echo '<h2>Requests to GitHub (newest first)</h2>';
        echo '<form method="post" style="margin:0 0 1em">';
        wp_nonce_field(self::ACTION);
        echo '<button class="button" name="wpgutt_action" value="clear_log">Clear the request log</button> ';
        echo '<button class="button" name="wpgutt_action" value="delete_state">Delete the stored state</button> ';
        echo '<button class="button" name="wpgutt_action" value="age_2">Age the stored check by 2 hours</button> ';
        echo '<button class="button" name="wpgutt_action" value="age_13">Age the stored check by 13 hours</button> ';
        echo '<button class="button" name="wpgutt_action" value="wp_check">Make WordPress check for updates now</button>';
        echo '</form>';
        $this->logTable($this->logRows());

        echo '</div>';
    }

    /**
     * @param array<int, array{0: string, 1: string}> $rows
     */
    private function table(array $rows): void
    {
        echo '<table class="widefat striped" style="max-width:760px"><tbody>';

        foreach ($rows as [$label, $value]) {
            $this->row($label, $value);
        }

        echo '</tbody></table>';
    }

    /**
     * @param array<int, array<string, mixed>> $log
     */
    private function logTable(array $log): void
    {
        $columns = [
            'time' => 'Time',
            'cause' => 'Caused by',
            'status' => 'Status',
            'host' => 'Host',
            'path' => 'Path',
            'redirect' => 'Redirects to',
            'token_sent' => 'Token sent',
            'conditional' => 'Conditional',
            'rate_remaining' => 'Rate limit left',
        ];

        echo '<table class="widefat striped"><thead><tr>';

        foreach ($columns as $label) {
            echo '<th>' . esc_html($label) . '</th>';
        }

        echo '</tr></thead><tbody>';

        if ($log === []) {
            echo '<tr><td colspan="' . count($columns) . '">Nothing yet.</td></tr>';
        }

        foreach ($log as $entry) {
            echo '<tr>';

            foreach (array_keys($columns) as $key) {
                $value = $entry[$key] ?? '';
                $shown = match ($key) {
                    'time' => wp_date('H:i:s', (int) $value),
                    'cause' => $this->causeLabel((string) $value),
                    default => (string) $value,
                };
                echo '<td>' . esc_html($shown) . '</td>';
            }

            echo '</tr>';
        }

        echo '</tbody></table>';
    }

    private function handlePost(): string
    {
        if (!isset($_POST['wpgutt_action'])) {
            return '';
        }

        check_admin_referer(self::ACTION);

        return match (sanitize_text_field(wp_unslash($_POST['wpgutt_action']))) {
            'clear_log' => $this->clearLog(),
            'delete_state' => $this->deleteState(),
            'age_2' => $this->ageState(2),
            'age_13' => $this->ageState(13),
            'wp_check' => $this->makeWordPressCheck(),
            default => '',
        };
    }

    private function clearLog(): string
    {
        delete_option(self::LOG_OPTION);

        return 'The request log was cleared.';
    }

    /**
     * Test only. It removes what the updater remembers about the last check, so
     * the next check starts from nothing and sends no ETag. The saved token stays.
     */
    private function deleteState(): string
    {
        delete_option(self::STATE_OPTION);

        return 'The stored state was deleted. The next check starts from nothing and sends no ETag.';
    }

    /**
     * Test only. It moves the stored time of the last check back, so the one
     * hour retry window and the twelve hour cache can be tested without
     * waiting. Nothing else in the stored state changes.
     */
    private function ageState(int $hours): string
    {
        $stored = get_option(self::STATE_OPTION, null);
        $state = is_array($stored) ? ReleaseState::fromArray($stored) : null;

        if ($state === null || !$state->hasChecked()) {
            return 'There is no stored check to age. Run a check first.';
        }

        $aged = new ReleaseState(max(1, $state->checkedAt - $hours * HOUR_IN_SECONDS), $state->outcome, $state->status, $state->etag, $state->release);

        update_option(self::STATE_OPTION, $aged->toArray(), false);

        return 'The stored check is now ' . $hours . ' hours older. WordPress asks GitHub again the next time it checks for updates.';
    }

    /**
     * Test only. WordPress decides when to ask an updater for news, and it skips
     * a plugin or theme for a while after it last built its own list. "Check again" on the
     * Updates screen only forces WordPress's core check, so it does not always
     * reach the updater. This clears WordPress's list and runs its check straight
     * away, so the updater is asked every time, and it says how many requests
     * WordPress made to GitHub while it did.
     */
    private function makeWordPressCheck(): string
    {
        if (!function_exists('wp_update_themes')) {
            return 'WordPress could not be asked to check for updates from here.';
        }

        $before = $this->requests;

        delete_site_transient('update_themes');
        wp_update_themes();

        $made = $this->requests - $before;

        return 'WordPress checked for updates and ' . match ($made) {
            0 => 'made no request to GitHub.',
            1 => 'made 1 request to GitHub.',
            default => 'made ' . $made . ' requests to GitHub.',
        };
    }

    private function row(string $label, string $value): void
    {
        echo '<tr><th style="width:220px">' . esc_html($label) . '</th><td>' . esc_html($value) . '</td></tr>';
    }
}
