# WP GitHub Updater Test Theme

A permanent test fixture for [scottoffen/wp-github-updater](https://github.com/scottoffen/wp-github-updater). The theme registers the updater, shows its settings section, and has a test page that shows what the updater did and a checklist for testing it. It has no other features, so it can be used to test or troubleshoot the updater with no other code in the way.

## What It Does

The theme registers the updater for the repository `scottoffen/wp-github-updater-test-theme` with the prefix `wpgutt`. The token constant is `WPGUTT_GITHUB_TOKEN`.

It adds three pages under Appearance. The first two cover both ways of showing the settings section:

- Updater Test Theme (library page) is a page the updater adds with `addPage()`. It shows only the settings section.
- Updater Test Theme (test page) is a page the theme adds itself, and it shows the section with `render()`, which is how a theme embeds the section in its own page. It is also the test page described below. The checklist and its steps call the first page the library page and this one the test page.

The third page, Updater Test Theme (checklist), is the test checklist described below.

The details link of an update opens a page the updater serves from `admin-post.php`, because a theme has no details popup of its own.

WordPress loads the code of a theme only while the theme is active or is the parent of the active theme, so the updater answers only then.

## The Test Page

Updater Test Theme (test page), under Appearance, shows the updater's settings section first. Below it are three things only a test needs.

- The details the section does not show: the repository, the slug, the installed version, the `Update URI` header that WordPress reads from `style.css`, where the token comes from, whether the saved token and the stored state exist, the last check with its outcome and HTTP status, the release and the asset found, whether GitHub reported a digest for the asset, and the stored ETag.
- What WordPress holds about this theme: whether WordPress's own update list has an update or an up-to-date entry for it, the path of the package address in that entry, and the details address, which is the page the updater serves. This can differ from the updater's stored state, for example after a forced check.
- The requests to GitHub: the answer to every request WordPress sent to GitHub or to one of its download hosts, newest first, up to 30 rows. The log records requests from any plugin or theme, not only this one.

Five buttons sit above the log. "Clear the request log" empties it. "Delete the stored state" removes what the updater remembers about the last check, so the next check starts from nothing and sends no ETag. It leaves the saved token alone. "Age the stored check by 2 hours" and "Age the stored check by 13 hours" move the stored time of the last check back, so the one hour retry after a failure and the twelve hour cache can be tested without waiting. They change nothing else in the stored state. "Make WordPress check for updates now" clears WordPress's own list of updates and runs its check straight away. WordPress decides when to ask a plugin or theme for news, and it skips them for a while after it last built its list, so "Check again" on the Updates screen, which only forces WordPress's own core check, does not always reach the updater. The notice after the click says how many requests WordPress made to GitHub.

### Reading the Log

Each row shows the time, what caused the request, the status, the host, the path, the host a redirect points to, whether a token was sent, whether the request was conditional, and the rate limit left. A request that got no answer shows its error in place of a status.

The log stores whether a token was sent, never the token. It also drops the query string of every address, because a signed download address carries a secret in it. Caused by says what started the request: Save token, Remove token, Check for updates now, WordPress check, Download, or Other. The page learns this by hooking the settings buttons, WordPress's own check and the download, a step before the updater's own code runs, and it changes nothing. If one request has more than one cause, the first is shown. Every row also has a number that keeps counting up, even when the log is cleared, so the checklist can tell what came before what.

These are the rows to expect.

| Action | Rows |
|---|---|
| Check with no token, private repository | 404 from `api.github.com`, Token sent no. |
| Check with a valid token | 200, Token sent yes, Conditional no. |
| Automatic check after the cache expires, with a stored ETag | 304 when the releases have not changed, Token sent yes, Conditional yes. |
| Check for updates now | Conditional no, because a forced check sends no ETag. |
| Wrong token | 401, Token sent yes. |
| Installing an update | A row on `api.github.com` with an asset path, 302, Token sent yes, and the storage host under Redirects to. Then a row on that storage host, 200, Token sent no. |

The last row is the one that matters. It shows that the token is not sent to the storage host.

### Deleting the Theme

WordPress has no uninstall step for themes and runs none of the theme's code when it is deleted. The saved token, the stored state, the request log, the request counter and the saved checklist all stay in the options table afterward. To leave nothing behind, remove the token on the settings page, clear the log and reset the checklist before you delete the theme.

## The Checklist

Updater Test Theme (checklist), under Appearance, shows the test checklist: 53 items in 10 parts, in the order they depend on each other. The same text is in `TESTING.md`, so it can be read outside WordPress.

- Every item has a tick box and a note field. The ticks and notes are saved when you press "Save Test Results". The button is pinned to the bottom of the screen, so it is always in reach, and the page keeps your place when it reloads after the save. They stay after reloads and after the theme is updated. Updating the theme is one of the tests.
- For 35 of the items, the page works out from what it already knows whether the item looks the way it should, and shows a green "the page sees the expected result" or an amber "the page sees something different", with what it saw. A hint never ticks the box. You do. The other items say the page cannot check them.
- The page remembers a pass. The moment an item passes, it stores the time, the cause and what it saw, so the item stays green, with its time, after the log moves on. An item can only pass when the one before it in its part has passed or been ticked, from a request newer than that one, and from a request with the cause the item names, such as Check for updates now. That keeps look-alike steps apart: the check that saving a token starts is not the forced check in 4.5. A request that passes an item is used up, so the next item needs a newer one. If a step really fails, write a note and tick it, and the steps after it still count. A ticked item is your call, so the page stops looking at it. Facts about the setup, such as the environment, pass as soon as they are true. Retest, next to a passed or ticked item, clears it so you can do the step again, and only a request made after that can pass it again.
- Under the Environment block are a Before You Start list, a Where Things Are list that names every page and part of a page the items mention, and closed How To sections for the steps that come up more than once, such as publishing a release, changing the repository's visibility and editing `wp-config.php`. Each item points to a How To by name.
- Each item lists what to do as numbered steps, and then says what to expect. The run starts with a public repository and no token, makes the repository private, and saves a bad token. Your real token is saved once, in 4.1, and the checklist does not remove or replace it again until 10.1, so keep a copy of it. GitHub shows a token only once.
- The Environment block at the top names the PHP and WordPress versions, whether `WP_DEBUG` and `WP_DEBUG_LOG` are on, and the version of the updater library this theme bundles. The library version is read from the theme's own `vendor` folder, so it is right even when another plugin or theme bundles a different version.
- At the bottom, "Copy the report" gives you the versions, every tick and note, the details table, the WordPress table and the request log as plain text, with the time, the cause and what the page saw for every pass. It never contains the token.
- Two boxes hold the sample release notes and the hostile notes the checklist asks you to paste into a release. The hostile notes are for your own test site only.
- "Reset the checklist" clears every tick, note and pass.

## Layout

- `.github/workflows/package.yml` packages the theme and drafts a release.
- `.github/workflows/defect-release.yml` drafts a release that is wrong on purpose.
- `scripts/wordpress-package.ps1` is the packaging script from the shared workflows repo.
- `scripts/defect-release.ps1` builds the defect releases.
- `TESTING.md` is the test checklist as a document.
- `src/wp-github-updater-test-theme/` holds the theme, its `includes/` folder with the test page, the checklist, the hints and the checklist's data, its `composer.json` and its `.distignore`.

The workflows call the reusable workflows in [scottoffen/workflows](https://github.com/scottoffen/workflows).

## First-Time Setup

1. In `src/wp-github-updater-test-theme/`, run `composer update` and commit the `composer.lock` it creates. The `vendor` folder is not committed, because the build installs it.
2. Push to `main`. The Package workflow creates a draft release for the version in the theme's header.

## Releasing a Version

1. Change the `Version` header in `src/wp-github-updater-test-theme/style.css`.
2. Merge or push to `main`. The Package workflow builds the zip and creates a draft release named `v<version>` with generated notes. A version with a suffix, such as `0.2.0-beta.1`, is marked as a pre-release.
3. Edit the release notes if the test needs it, then publish the draft.

## Changing the Library Version

The theme requires `scottoffen/wp-github-updater` at `~0.1`, which accepts `0.1` up to, but not including, `1.0`. To take a newer release of the library:

1. In `src/wp-github-updater-test-theme/`, run `composer update scottoffen/wp-github-updater` and commit the changed `composer.lock`.
2. Change the `Version` header and release as described above.

To test a library upgrade, install the earlier theme release on a site, publish the new one, and update from the Themes screen.

## Defect Releases

The Defect Release workflow creates a draft release that gets one thing wrong on purpose, so you can watch the updater refuse it on a real site. Run it from the Actions tab and choose a defect and a version. The version must not have a release or tag yet. A version higher than every real release makes the defect release the one the updater looks at first, so the checklist gives each defect its own version, from `9.9.1` to `9.9.7`.

The release stays a draft until you publish it. Delete the release and its tag when the test is done.

| Defect | What is wrong | What the updater does |
|---|---|---|
| `wrong-folder` | The top-level folder in the zip has the wrong name. | Offers the update. Installing it fails with the folder error, and nothing is installed. |
| `no-folder` | The zip has no top-level folder. | Offers the update. Installing it fails with the folder error, and nothing is installed. |
| `wrong-asset-name` | The asset name does not match `<slug>-*.zip`. | Offers no update. The status line in the settings section says the newest release is not set up correctly. |
| `two-assets` | Two assets match `<slug>-*.zip`. | Same as `wrong-asset-name`. |
| `no-asset` | The release has no asset. | Same as `wrong-asset-name`. |
| `bad-tag` | The tag has no leading `v`. | Ignores the release and offers the highest valid release, or none. |
| `pre-release-flag` | A plain version is marked as a pre-release on GitHub. | Ignores the release and offers the highest valid release, or none. |

While a release with an asset problem is published and has the highest version, the updater offers no update at all. It does not fall back to an older release. Delete the defect release to get updates back.

To build a defect without creating a release, run the script with `-DryRun`. It writes the assets and a `plan.json` to `dist/defect`. The script runs the packaging script, so Composer needs to be on the `PATH`.

```powershell
./scripts/defect-release.ps1 -Defect wrong-folder -Version 9.9.9 -DryRun
```
