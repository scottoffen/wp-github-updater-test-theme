<?php

declare(strict_types=1);

defined('ABSPATH') || exit;

/**
 * Works out, from what the test page already knows, whether an item on the
 * checklist looks the way it should. A hint is only a hint. It never ticks the
 * box, because a green hint on its own would give false confidence.
 *
 * The class reads nothing from WordPress. It gets a snapshot, so each rule can
 * be tested on its own.
 *
 * The snapshot holds:
 *   env      php, wp, debug, debug_log, folder, vendor, theme_active
 *   slug, repository, update_uri, installed, token_source, saved_token
 *   state    checked, outcome, status, etag, tag, version, asset, digest
 *   wp       kind (none, update or current), version, package
 *   log      the logged requests, newest first
 *   library  now
 *
 * A hint for a defect release also gets the version that item gives, because
 * each defect release has a version of its own.
 */
final class Updater_Test_Theme_Hints
{
    public const MATCH = 'match';
    public const DIFFER = 'differ';
    public const WAITING = 'waiting';

    /**
     * @param array<string, mixed> $snap
     * @param string $param What the item itself gives, such as the version of a defect release.
     * @return array{0: string, 1: string} The verdict and what the page saw.
     */
    public function evaluate(string $key, array $snap, string $param = ''): array
    {
        $method = 'hint' . str_replace('_', '', ucwords($key, '_'));

        if (!method_exists($this, $method)) {
            return [self::WAITING, 'No hint is defined for this item.'];
        }

        return $this->$method($snap, $param);
    }

    /** @param array<string, mixed> $s */
    private function hintEnv(array $s): array
    {
        $e = $s['env'];
        $problems = [];

        if (version_compare((string) $e['php'], '8.1', '<')) {
            $problems[] = 'PHP is below 8.1';
        }

        if (version_compare((string) $e['wp'], '7.1', '<')) {
            $problems[] = 'WordPress is below 7.1';
        }

        if (empty($e['debug'])) {
            $problems[] = 'WP_DEBUG is off';
        }

        if (empty($e['debug_log'])) {
            $problems[] = 'WP_DEBUG_LOG is off';
        }

        $seen = 'PHP ' . $e['php'] . ', WordPress ' . $e['wp'] . ', WP_DEBUG ' . (!empty($e['debug']) ? 'on' : 'off') . ', WP_DEBUG_LOG ' . (!empty($e['debug_log']) ? 'on' : 'off') . '.';

        return $problems === [] ? $this->ok($seen) : $this->differ(implode(', ', $problems) . '. ' . $seen);
    }

    /** @param array<string, mixed> $s */
    private function hintInstall(array $s): array
    {
        $e = $s['env'];

        if ($e['folder'] !== $s['slug']) {
            return $this->differ('The folder is named "' . $e['folder'] . '" but the slug is "' . $s['slug'] . '". It looks installed from the wrong archive.');
        }

        return !empty($e['vendor']) ? $this->ok('The folder is named "' . $s['slug'] . '" and its vendor folder is there.') : $this->differ('The vendor folder is missing.');
    }

    /** @param array<string, mixed> $s */
    private function hintThemeActive(array $s): array
    {
        return !empty($s['env']['theme_active']) ? $this->ok('This theme is the active theme or its parent.') : $this->differ('This theme is not the active theme or its parent, so its code is not running.');
    }

    /** @param array<string, mixed> $s */
    private function hintUpdateUri(array $s): array
    {
        $expected = 'https://github.com/' . $s['repository'];

        return strtolower((string) $s['update_uri']) === strtolower($expected)
            ? $this->ok('The header is ' . $s['update_uri'] . '.')
            : $this->differ('The header is "' . $s['update_uri'] . '" but the repository is ' . $s['repository'] . '.');
    }

    /** @param array<string, mixed> $s */
    private function hintNeverChecked(array $s): array
    {
        return empty($s['state']['checked'])
            ? $this->ok('No check has run yet.')
            : $this->differ('A check has already run. Press "Delete the stored state" first.');
    }

    /** @param array<string, mixed> $s */
    private function hintNoTokenCheck(array $s): array
    {
        return $this->newest($s, fn (array $r): bool => $r['status'] === '404' && $r['host'] === 'api.github.com' && str_ends_with((string) $r['path'], '/releases') && $r['token_sent'] === 'no' && $r['conditional'] === 'no' && $s['state']['outcome'] === 'not_found', 'Expected 404 from api.github.com on a path ending in /releases, Token sent no, Conditional no, and the outcome not_found.');
    }

    /** @param array<string, mixed> $s */
    private function hintSaveToken(array $s): array
    {
        $installed = (string) $s['installed'];

        return $this->newest($s, fn (array $r): bool => $r['status'] === '200' && $r['token_sent'] === 'yes' && $r['conditional'] === 'no' && $s['state']['tag'] === 'v' . $installed && $s['state']['etag'] !== '', 'Expected 200, Token sent yes, Conditional no, release v' . $installed . ' found and an ETag stored. The release found is "' . $s['state']['tag'] . '".');
    }

    /** @param array<string, mixed> $s */
    private function hintDigest(array $s): array
    {
        if ($s['state']['tag'] === '') {
            return [self::WAITING, 'No release has been found yet.'];
        }

        return !empty($s['state']['digest']) ? $this->ok('GitHub reported a digest for the asset.') : $this->differ('GitHub reported no digest for the asset, so the download is not verified.');
    }

    /** @param array<string, mixed> $s */
    private function hintConditional(array $s): array
    {
        return $this->newest($s, fn (array $r): bool => $r['status'] === '304' && $r['token_sent'] === 'yes' && $r['conditional'] === 'yes', 'Expected 304, Token sent yes, Conditional yes.');
    }

    /** @param array<string, mixed> $s */
    private function hintForced(array $s): array
    {
        return $this->newest($s, fn (array $r): bool => $r['status'] === '200' && $r['token_sent'] === 'yes' && $r['conditional'] === 'no', 'Expected 200, Token sent yes, Conditional no.');
    }

    /** @param array<string, mixed> $s */
    private function hintWrongToken(array $s): array
    {
        return $this->newest($s, fn (array $r): bool => $r['status'] === '401' && $r['token_sent'] === 'yes' && $s['state']['outcome'] === 'auth', 'Expected 401, Token sent yes, and the outcome auth.');
    }

    /** @param array<string, mixed> $s */
    private function hintConstantLocks(array $s): array
    {
        if ($s['token_source'] !== 'constant') {
            return $this->differ('The token source is "' . $s['token_source'] . '", not "constant". Is the constant defined?');
        }

        return !empty($s['saved_token'])
            ? $this->ok('The constant is the token source while a token is also saved, so the constant wins over it.')
            : $this->differ('The constant is the token source, but no token is saved, so this does not show that the constant wins over one.');
    }

    /** @param array<string, mixed> $s */
    private function hintRemoveConstant(array $s): array
    {
        if ($s['token_source'] !== 'settings') {
            return $this->differ('The token source is "' . $s['token_source'] . '", not "settings".');
        }

        return $this->newest($s, fn (array $r): bool => $r['status'] === '200' && $r['token_sent'] === 'yes', 'The saved token should be in use again. Expected the newest row to be 200 with Token sent yes.');
    }

    /** @param array<string, mixed> $s */
    private function hintRemoveToken(array $s): array
    {
        if ($s['token_source'] !== 'none' || !empty($s['saved_token'])) {
            return $this->differ('A token is still in use. The token source is "' . $s['token_source'] . '".');
        }

        return $this->newest($s, fn (array $r): bool => $r['status'] === '404' && $r['token_sent'] === 'no' && $s['state']['outcome'] === 'not_found', 'No token is in use, as expected. Expected 404, Token sent no, and the outcome not_found.');
    }

    /** @param array<string, mixed> $s */
    private function hintPublicRepo(array $s): array
    {
        if ($s['token_source'] !== 'none') {
            return $this->differ('A token is still in use. The token source is "' . $s['token_source'] . '".');
        }

        $rows = $s['log'];

        if ($rows === []) {
            return [self::WAITING, 'Nothing has been logged yet.'];
        }

        $r = $rows[0];
        $left = (string) $r['rate_remaining'];
        $limited = ctype_digit($left) && (int) $left <= 60;

        if ($r['status'] === '200' && $r['token_sent'] === 'no' && $r['conditional'] === 'no') {
            return $limited ? $this->ok('200 with no token. ' . $left . ' requests left, which is the unauthenticated limit.') : $this->differ('200 with no token, but the rate limit left is "' . $left . '", which does not look like the unauthenticated limit of 60.');
        }

        return $this->differ('Expected 200, Token sent no, Conditional no. The newest row is ' . $this->describe($r) . '.');
    }

    /** @param array<string, mixed> $s */
    private function hintDraftIgnored(array $s): array
    {
        if ($s['state']['outcome'] !== 'ok') {
            return $this->differ('The last outcome is "' . $s['state']['outcome'] . '", not ok.');
        }

        return $s['state']['tag'] === 'v' . $s['installed']
            ? $this->ok('The latest release found is still v' . $s['installed'] . '.')
            : $this->differ('The latest release found is "' . $s['state']['tag'] . '", not v' . $s['installed'] . '. Is the release still a draft?');
    }

    /** @param array<string, mixed> $s */
    private function hintNewerFound(array $s): array
    {
        $st = $s['state'];

        if ($st['outcome'] !== 'ok' || $st['tag'] === '') {
            return $this->differ('The last outcome is "' . $st['outcome'] . '" and no release is found.');
        }

        if (!version_compare((string) $st['version'], (string) $s['installed'], '>')) {
            return $this->differ('The release found is ' . $st['tag'] . ', which is not newer than the installed ' . $s['installed'] . '.');
        }

        $expected = $s['slug'] . '-' . $st['version'] . '.zip';

        if ($st['asset'] !== $expected) {
            return $this->differ('The asset is "' . $st['asset'] . '", expected "' . $expected . '".');
        }

        return !empty($st['digest']) ? $this->ok($st['tag'] . ' is found with the asset ' . $st['asset'] . ' and a digest.') : $this->differ($st['tag'] . ' is found with the asset ' . $st['asset'] . ', but GitHub reported no digest.');
    }

    /** @param array<string, mixed> $s */
    private function hintWpShowsUpdate(array $s): array
    {
        $wp = $s['wp'];

        if ($wp['kind'] !== 'update') {
            return $this->differ('WordPress holds "' . $wp['kind'] . '", not an update. Open the Plugins or Themes screen so WordPress rebuilds its list.');
        }

        return $wp['version'] === $s['state']['version']
            ? $this->ok('WordPress offers ' . $wp['version'] . ', the same as the stored state, with the package path ' . $wp['package'] . '.')
            : $this->differ('WordPress offers ' . $wp['version'] . ' but the stored state has ' . $s['state']['version'] . '.');
    }

    /** @param array<string, mixed> $s */
    private function hintUpdateDone(array $s): array
    {
        if ($s['state']['version'] === '') {
            return [self::WAITING, 'No release is known yet.'];
        }

        return $s['installed'] === $s['state']['version']
            ? $this->ok('The installed version is ' . $s['installed'] . ', the same as the release found.')
            : $this->differ('The installed version is ' . $s['installed'] . ' but the release found is ' . $s['state']['version'] . '.');
    }

    /** @param array<string, mixed> $s */
    private function hintNoTokenStorage(array $s): array
    {
        $rows = $s['log'];

        for ($i = 0; $i < count($rows) - 1; $i++) {
            $storage = $rows[$i];
            $api = $rows[$i + 1];

            if (!str_ends_with((string) $storage['host'], 'githubusercontent.com') || $storage['status'] !== '200') {
                continue;
            }

            $apiOk = $api['host'] === 'api.github.com' && str_contains((string) $api['path'], '/releases/assets/') && $api['status'] === '302' && $api['token_sent'] === 'yes' && str_ends_with((string) $api['redirect'], 'githubusercontent.com');

            if ($storage['token_sent'] === 'no' && $apiOk) {
                return $this->ok('The asset request on api.github.com was 302 with the token and redirected to ' . $api['redirect'] . '. The request to ' . $storage['host'] . ' was 200 and sent no token.');
            }

            return $this->differ('Found a storage request, but expected: the api.github.com asset row 302 with Token sent yes, then ' . $storage['host'] . ' 200 with Token sent no. The storage row has Token sent ' . $storage['token_sent'] . '.');
        }

        return [self::WAITING, 'No download has been logged yet.'];
    }

    /** @param array<string, mixed> $s */
    private function hintStateKept(array $s): array
    {
        $problems = [];

        if ($s['token_source'] === 'none') {
            $problems[] = 'no token is in use';
        }

        if ($s['state']['etag'] === '') {
            $problems[] = 'no ETag is stored';
        }

        return $problems === [] ? $this->ok('A token is in use (' . $s['token_source'] . ') and an ETag is stored.') : $this->differ(ucfirst(implode(' and ', $problems)) . '.');
    }

    /** @param array<string, mixed> $s */
    private function hintRebuild(array $s): array
    {
        $wp = $s['wp'];
        $stored = (string) $s['state']['version'];

        if ($wp['kind'] === 'none') {
            return $this->ok('WordPress holds no entry at the moment of the check, because the updater made it discard its list. It rebuilds the list on the next admin page.');
        }

        return $stored !== '' && $wp['version'] === $stored
            ? $this->ok('WordPress lists the update again as ' . $wp['version'] . ', the same as the stored release. It rebuilt its list on the page after the check.')
            : $this->differ('WordPress still holds an entry (' . $wp['kind'] . ' ' . $wp['version'] . '), but the stored release is "' . $stored . '". It may be offering the old update.');
    }

    /** @param array<string, mixed> $s */
    private function hintRenameFollow(array $s): array
    {
        $rows = $s['log'];

        for ($i = 0; $i < count($rows) - 1; $i++) {
            $new = $rows[$i];
            $old = $rows[$i + 1];

            if ($old['status'] === '301' && str_starts_with((string) $old['path'], '/repos/') && str_starts_with((string) $new['path'], '/repositories/')) {
                if ($new['status'] === '200' && $old['token_sent'] === 'yes' && $new['token_sent'] === 'yes') {
                    return $this->ok('301 on ' . $old['path'] . ', then 200 on ' . $new['path'] . ', with the token on both.');
                }

                return $this->differ('The redirect was followed to ' . $new['path'] . ', but expected 200 with Token sent yes on both rows. The rows are ' . $this->describe($old) . ' and ' . $this->describe($new) . '.');
            }
        }

        return [self::WAITING, 'No redirect has been logged yet.'];
    }

    /** @param array<string, mixed> $s */
    private function hintRenameBack(array $s): array
    {
        return $this->newest($s, fn (array $r): bool => $r['status'] === '200' && str_starts_with((string) $r['path'], '/repos/') && str_contains((string) $r['path'], (string) $s['repository']), 'Expected 200 on a /repos/ path for ' . $s['repository'] . '.');
    }

    /** @param array<string, mixed> $s */
    private function hintDefectOffered(array $s, string $version): array
    {
        $st = $s['state'];

        return $st['outcome'] === 'ok' && $st['tag'] === 'v' . $version
            ? $this->ok('v' . $version . ' is offered, as expected.')
            : $this->differ('Expected v' . $version . ' to be offered. The release found is "' . $st['tag'] . '" and the outcome is "' . $st['outcome'] . '".');
    }

    /** @param array<string, mixed> $s */
    private function hintDefectMismatch(array $s): array
    {
        $st = $s['state'];

        return $st['outcome'] === 'asset_mismatch' && $st['tag'] === ''
            ? $this->ok('The outcome is asset_mismatch and no release is offered, as expected.')
            : $this->differ('Expected the outcome asset_mismatch and no release. The outcome is "' . $st['outcome'] . '" and the release found is "' . $st['tag'] . '".');
    }

    /** @param array<string, mixed> $s */
    private function hintDefectIgnored(array $s, string $version): array
    {
        $st = $s['state'];

        if ($st['outcome'] !== 'ok' || $st['tag'] === '') {
            return $this->differ('Expected a valid older release to be offered. The outcome is "' . $st['outcome'] . '" and the release found is "' . $st['tag'] . '".');
        }

        return ltrim($st['tag'], 'v') !== $version
            ? $this->ok($version . ' is ignored. The highest valid release is ' . $st['tag'] . '.')
            : $this->differ($version . ' was offered, but it should be ignored.');
    }

    /** @param array<string, mixed> $s */
    private function hintSuffixIgnored(array $s): array
    {
        $st = $s['state'];

        if ($st['outcome'] !== 'ok' || $st['tag'] === '') {
            return $this->differ('Expected a valid release to be offered. The outcome is "' . $st['outcome'] . '".');
        }

        return !str_contains((string) $st['version'], '-')
            ? $this->ok('The release offered, ' . $st['tag'] . ', has no suffix, so the suffixed version was ignored.')
            : $this->differ('The release offered is ' . $st['tag'] . ', which has a suffix and should have been ignored.');
    }

    /**
     * Checks the newest logged request.
     *
     * @param array<string, mixed> $s
     * @return array{0: string, 1: string}
     */
    private function newest(array $s, callable $test, string $expected): array
    {
        if ($s['log'] === []) {
            return [self::WAITING, 'Nothing has been logged yet.'];
        }

        $row = $s['log'][0];

        return $test($row) ? $this->ok('The newest row is ' . $this->describe($row) . '.') : $this->differ($expected . ' The newest row is ' . $this->describe($row) . '.');
    }

    /** @param array<string, mixed> $row */
    private function describe(array $row): string
    {
        return $row['status'] . ' ' . $row['host'] . ' ' . $row['path'] . ', Token sent ' . $row['token_sent'] . ', Conditional ' . $row['conditional'];
    }

    /** @return array{0: string, 1: string} */
    private function ok(string $text): array
    {
        return [self::MATCH, $text];
    }

    /** @return array{0: string, 1: string} */
    private function differ(string $text): array
    {
        return [self::DIFFER, $text];
    }
}
