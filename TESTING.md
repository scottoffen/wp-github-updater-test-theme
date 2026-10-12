```bash
wp config set WP_DEBUG true --raw
wp config set WP_DEBUG_LOG true --raw
wp config set WP_DEBUG_DISPLAY false --raw
```

# Theme Test Checklist

The checklist for the WP GitHub Updater Test Theme. The same items are on the screen Appearance, Updater Test Theme (checklist), where each one has a tick box and a note, and where the page shows a hint for the items it can work out for itself. This file is the same text for reading outside WordPress.

## Before You Start

- A local WordPress site on WordPress 7.1 or later and PHP 8.1 or later, with `WP_DEBUG` and `WP_DEBUG_LOG` turned on. Item 1.1 shows how.
- The fixture repository on GitHub, with release `v0.1.0` published. Make it public before you begin, because Part 3 starts with a public repository and makes it private later. See Change a Repository's Visibility.
- A fine-grained personal access token for this repository only, with Contents set to Read-only. See Create a Token. You do not need it until 4.1.
- Keep a copy of that token, for example in your password manager. GitHub shows it only once. You save it in WordPress once, in 4.1, and the checklist does not remove or replace it again until 9.1. Removing it in WordPress does not revoke it on GitHub, so your copy still works if you want to run more tests afterwards.
- To test one fixture at a time, keep the other one deactivated. The request log records requests from any plugin or theme.

Do the parts in order. Each part assumes the state the one before left behind.

## Where Things Are

- The library page is under Appearance, Updater Test Theme (library page). It shows only the settings section.
- The test page is under Appearance, Updater Test Theme (test page). It shows the settings section, and below it the details table, the WordPress table and the request log, with five buttons above the log.
- The checklist page is under Appearance, Updater Test Theme (checklist). It shows this checklist, with a tick box and a note for every item.
- The settings section is the block with the Access token field, the Save token button, the Check for updates now button and the status line. A Remove token button appears once a token is saved. It is on the library page and at the top of the test page.
- The details table, headed "Details the section does not show", lists what the updater has stored.
- The WordPress table, headed "What WordPress holds about this theme", shows WordPress's own update entry.
- The request log, headed "Requests to GitHub (newest first)", has one row for every request WordPress sent to GitHub. The newest row is at the top. The columns are Time, Caused by, Status, Host, Path, Redirects to, Token sent, Conditional and Rate limit left. Caused by says what started the request: Save token, Remove token, Check for updates now, WordPress check (WordPress asking on its own, or through Make WordPress check for updates now), Download, or Other.
- The notice is the colored bar at the top of a page, right under its title. The buttons on the test page show their result there.
- A hint is the colored line under an item. Green means the page saw the expected result, and it remembers: the item then shows when it passed and what the page saw, even after the log moves on. Amber means the page saw something different. A step can only pass from something you do after the step before it passed or was ticked, and only when what you did is what the item names, such as Check for updates now. Retest clears a pass so you can do the step again.
- The site shell is Local's terminal for the site. In Local, select the site and click Site shell. Commands that start with `wp` run there.

## How To

### Create a Token

1. On GitHub, click your profile picture, and then Settings.
2. In the left menu, scroll down and click Developer settings.
3. Click Personal access tokens, and then Fine-grained tokens.
4. Click Generate new token.
5. Give it a name, such as `wp-github-updater-test-theme test`, and set the expiration to 30 days.
6. Under Repository access, choose Only select repositories, and pick the fixture repository.
7. Under Permissions, open Repository permissions, and set Contents to Read-only. Leave everything else at No access. GitHub adds read access to Metadata by itself.
8. Click Generate token, and copy the token. It starts with `github_pat_`. GitHub shows it only once, so keep a copy.

GitHub changes the wording of these pages now and then, so the names may differ a little.

### Change a Repository's Visibility

1. On GitHub, open the fixture repository, and click its Settings tab. This is the repository's Settings, not your profile's.
2. On the General page, scroll to the Danger Zone at the bottom.
3. Click Change repository visibility, and choose Make public or Make private.
4. Follow the prompts. GitHub asks you to type the repository name to confirm.

### Publish a Release

1. In the fixture repository, open `src/wp-github-updater-test-theme/style.css`, and change the `Version:` line near the top to the version you are publishing, for example `0.2.0`.
2. Commit the change and push it to `main`.
3. On GitHub, open the repository's Actions tab, and wait for the Package workflow to finish.
4. Open the repository's Releases page. The workflow has created a draft release named for the version, such as `v0.2.0`, with one asset named `wp-github-updater-test-theme-0.2.0.zip`.
5. Open the draft. Leave the notes as GitHub generated them unless the item says otherwise, and click Publish release.

### Edit a Release's Notes

1. On GitHub, open the repository's Releases page.
2. Find the release, and click the pencil icon on it, or open it and click Edit.
3. Replace the description with the text the item gives you, and click Update release.
4. On the test page, click Check for updates now, so the updater picks up the new notes. WordPress's own check never does, because it is answered from the stored result.
5. If the View version ... details link is missing afterward, click Make WordPress check for updates now. The forced check makes WordPress discard its update list, and WordPress normally rebuilds it on the next admin page.

### Add or Remove a Token Constant

1. In Local, right-click the site, choose Go to site folder, and open `app/public/wp-config.php` in a text editor.
2. Find the line that says to stop editing. It reads `/* That's all, stop editing! Happy publishing. */`.
3. To add the constant, paste the `define` line the item gives you on a new line above that one. To remove it, delete that line.
4. Save the file, and reload the page in your browser.

### Run the Defect Release Workflow

1. On GitHub, open the fixture repository's Actions tab.
2. Click Defect Release in the list on the left, and then click Run workflow.
3. Choose the defect named in the item, and type the version the item gives.
4. Click Run workflow, and wait for it to finish.
5. Open the Releases page, and publish the draft it created.

### Delete a Release and Its Tag

1. On GitHub, open the repository's Releases page, open the release, and click Delete.
2. Open the repository's Tags page, find the tag, and delete it.

### Create an Editor User

1. In WordPress, go to Users, Add New User.
2. Fill in a username, an email address and a password, and set the Role to Editor.
3. Click Add New User.

## Part 1: Setup

### 1.1 Check the Environment

1. Scroll to the Environment block near the top of this page.

Expect PHP 8.1 or later, WordPress 7.1 or later, and `WP_DEBUG` and `WP_DEBUG_LOG` on. If either debug setting is off, run these in the site shell, and then reload this page. The third keeps errors off the pages and writes them only to `debug.log`, so a notice cannot break a page in the middle of a test.

```bash
wp config set WP_DEBUG true --raw
wp config set WP_DEBUG_LOG true --raw
wp config set WP_DEBUG_DISPLAY false --raw
```

The page can check this.

### 1.2 Install the Release Asset

1. In the site folder, delete `app/public/wp-content/debug.log` if it exists, so that every line in it from now on is yours.
2. On GitHub, open the repository's Releases page, and open release `v0.1.0`.
3. Under Assets, download the file named `wp-github-updater-test-theme-0.1.0.zip`. Do not download "Source code (zip)" or "Source code (tar.gz)".
4. In WordPress, go to Appearance, Themes, Add New Theme, click Upload Theme, choose the file, and click Install Now.
5. Click Activate.

Expect no error on activation, and no new `debug.log`, or no new lines in it.

The page can check that its folder is named after the slug and that `vendor/autoload.php` exists. It cannot see the debug log, so you look.

### 1.3 The Theme Is Active

1. Go to Appearance, Themes.
2. Check that WP GitHub Updater Test Theme is the active theme. If it is not, click it, and click Activate.

Expect the theme to be the active theme, or the parent of the active theme. Its code does not load otherwise, and the updater never answers.

The page can check this.

### 1.4 The Update URI Header

1. Open the test page.
2. In the details table, find the row named Update URI header.

Expect `https://github.com/scottoffen/wp-github-updater-test-theme`, the same repository the updater is set to.

The page can check this.

## Part 2: The Settings Section

### 2.1 Both Pages Show the Same Section

1. Open the library page, and look at the settings section.
2. Open the test page, and look at the settings section at the top of it.

Expect the same section on both: the Access token field, the Save token button, the Check for updates now button, and the status line. There is no Remove token button yet, because no token is saved. The test page adds the details table, the WordPress table and the request log below the section.

The page cannot check this one.

### 2.2 Nothing Checked Yet

1. Open the test page.
2. Read the status line in the settings section.

Expect "Not checked yet." followed by "Installed version: 0.1.0." WordPress may already have asked GitHub on its own, because loading the Plugins or Themes screen can start a check. If the status line shows a check, click Delete the stored state above the request log, and reload the page.

The page can check this while no check has run.

### 2.3 A Person Without the Capability

1. Create an Editor user. See Create an Editor User.
2. Open a private browser window, and sign in to the site as that user.
3. Look at the menu on the left for the updater pages.
4. In the address bar, replace the page after the site address with `/wp-admin/themes.php?page=wpgutt_updater_settings`, and press Enter.

Expect no entry for the updater pages in the menu. Opening the address directly gives "Sorry, you are not allowed to access this page." Close the private window afterward.

The page cannot check this one.

## Part 3: A Repository With No Token

This part starts with the repository public and no token. It then makes the repository private, and saves a bad token. Your real token is saved once, in Part 4.

### 3.1 A Public Repository With No Token

1. Make sure the repository is public. See Change a Repository's Visibility.
2. On the test page, click Check for updates now.
3. Look at the newest row in the request log.

Expect a new row Caused by Check for updates now, with status 200, host `api.github.com`, Token sent no and Conditional no. The Rate limit left column shows 60 or less, which is the unauthenticated limit. The status line says "Latest version found: 0.1.0." with no problem sentence.

The page can check the row.

### 3.2 Make It Private

1. Make the repository private. See Change a Repository's Visibility.
2. On the test page, click Check for updates now.
3. Look at the newest row in the request log, and read the status line.

Expect one new row Caused by Check for updates now, with status 404, host `api.github.com`, a path ending in `/releases`, Token sent no and Conditional no. The status line reads "Last checked: ...", "Installed version: 0.1.0.", and then "The updates could not be found. If they are private, check the access token." The details table shows the last outcome as `not_found (HTTP 404)`.

The page can check this.

### 3.3 A Bad Token

1. On the test page, click in the Access token field, and type `github_pat_wrong`.
2. Click Save token.
3. Look at the newest row in the request log, and read the status line.

Expect to land back on the same page with a green notice, "The access token was saved and the updates were checked." Expect a new row Caused by Save token, with status 401 and Token sent yes. The status line ends with "Access was refused. Check the access token." The details table shows `auth (HTTP 401)`. A Remove token button now appears, and the field shows a placeholder of bullets and the last four characters. The next part replaces this token with your real one.

The page can check this.

## Part 4: The Token

WordPress decides when to ask an updater for news, and it skips a plugin or theme for a while after it last built its own list. "Check again" on the Updates screen only forces WordPress's core check, and the Plugins and Themes screens wait an hour. Some steps below use Make WordPress check for updates now on the test page. It clears WordPress's own list and runs its check straight away, so the updater is asked every time. The notice after each click says how many requests WordPress made to GitHub.

### 4.1 Save a Token

1. Create a token if you have not. See Create a Token.
2. On the test page, click in the Access token field, and paste your real token. It replaces the bad one.
3. Click Save token.
4. Look at the newest row in the request log, and read the status line and the details table.

Expect to land back on the same page with a green notice, "The access token was saved and the updates were checked." Expect a new row Caused by Save token, with status 200, Token sent yes and Conditional no. The status line shows "Latest version found: 0.1.0." The details table shows release `v0.1.0`, the asset `wp-github-updater-test-theme-0.1.0.zip` with its size, and a stored ETag.

The page can check the log row and the details.

### 4.2 The Token Is Never Shown

1. Look at the Access token field on the test page.
2. Right-click the page, choose View page source, and press Ctrl+F (Cmd+F on a Mac).
3. Search for the first ten characters of your token.

Expect the field to show a placeholder of eight bullets and the last four characters of the token, never a value. Expect the search to find nothing.

The page cannot check this one.

### 4.3 The Asset Has a Digest

1. On the test page, find the row named Asset found in the details table.

Expect the row to end with "digest yes". If it says "digest no", write that down. It means GitHub did not report a checksum for the asset, so the downloaded file is not verified.

The page can check this.

### 4.4 A Conditional Request

1. On the test page, click Age the stored check by 13 hours.
2. Click Make WordPress check for updates now.
3. Read the notice, and look at the newest row in the request log.

Expect the notice to say WordPress made 1 request to GitHub. Expect one new row Caused by WordPress check, with status 304, Token sent yes and Conditional yes. The Rate limit left column does not drop, or drops by at most one. This shows GitHub honors the saved ETag for a private repository.

The page can check the row.

### 4.5 A Forced Check Sends No ETag

1. In the settings section, click Check for updates now.
2. Look at the newest row in the request log.

Expect a new row Caused by Check for updates now, with status 200, Token sent yes and Conditional no. A forced check always asks for the full list.

The page can check this.

### 4.6 The Cache Is Used

1. Straight after 4.5, click Make WordPress check for updates now. Do not age the check first.
2. Read the notice, and look at the request log.

Expect the notice to say WordPress made no request to GitHub, and no new row in the log. The stored result is fresh for 12 hours, so WordPress's check is answered from it.

The page cannot check this one.

### 4.7 A Wrong Token in the Constant

1. Add this line to `wp-config.php`. See Add or Remove a Token Constant.

```php
define('WPGUTT_GITHUB_TOKEN', 'github_pat_wrong');
```

2. Reload the test page.
3. In the settings section, click Check for updates now.
4. Look at the newest row in the request log, and read the status line.

Leave the constant in place for the next two items. Expect a new row Caused by Check for updates now, with status 401 and Token sent yes. The status line ends with "Access was refused. Check the access token." The details table shows `auth (HTTP 401)`. The token you saved in 4.1 was not touched, and it would have returned 200, so the 401 shows that the constant wins over a saved token.

The page can check this.

### 4.8 The Retry Window After a Failure

1. Click Make WordPress check for updates now, and read the notice.
2. Click Age the stored check by 2 hours.
3. Click Make WordPress check for updates now again, and read the notice.

Expect the first notice to say WordPress made no request to GitHub, because a failed check is not retried for an hour. Expect the second to say it made 1 request, and a new 401 row in the log.

The page cannot check this one.

### 4.9 The Constant Locks the Settings Section

1. With the constant still defined, look at the settings section on the test page.
2. Look at the details table.

Expect the Access token field and the Save token and Remove token buttons to be replaced by a disabled field holding the constant's name, and the line "This token is set by the WPGUTT_GITHUB_TOKEN constant, so it cannot be changed here." Only Check for updates now remains. The details table shows Token source `constant` and Saved token option yes.

The page can check the token source and the saved option.

### 4.10 Remove the Constant

1. Delete the `define` line from `wp-config.php`. See Add or Remove a Token Constant.
2. Reload the test page.
3. In the settings section, click Check for updates now.
4. Look at the newest row in the request log, and read the status line.

Expect the field and the buttons back, and a new row Caused by Check for updates now, with status 200 and Token sent yes, because the token you saved in 4.1 is in use again. This shows the saved token was kept all along. The status line is back to "Latest version found: 0.1.0." with no problem sentence.

The page can check this.

### 4.11 A Token Without Contents Access (optional)

1. Create a second token for the same repository, but set Contents to No access. See Create a Token.
2. Add the constant line from 4.7 to `wp-config.php` again, with the new token in place of `github_pat_wrong`. See Add or Remove a Token Constant.
3. Reload the test page, and click Check for updates now in the settings section.
4. Write down the status in the newest row of the request log and the exact text of the status line.
5. Delete the constant line from `wp-config.php`, reload the test page, and click Check for updates now once more.

Expect to see what people see when a token has the wrong permissions. GitHub answers 403, and the status line reads "Access was refused. Check the access token." The token you saved in 4.1 was not touched, and step 5 puts the status line back.

The page cannot check this one.

### 4.12 Save With the Field Empty

1. On the test page, leave the Access token field empty.
2. Click Save token.

Expect a notice reading "Enter an access token to save it." and no new row in the request log.

The page cannot check this one.

### 4.13 Each Page Returns to Itself

1. On the library page, leave the Access token field empty, and click Save token.
2. On the test page, do the same.
3. Click Check for updates now on each page.

Expect each click to land you back on the page you clicked from, with its notice. Removing the token is tested in 9.1.

The page cannot check this one.

### 4.14 Reloading Does Not Resubmit

1. Click any button, for example Check for updates now.
2. Click the browser's reload button.

Expect no "confirm form resubmission" prompt and no new row in the request log.

The page cannot check this one.

### 4.15 An Expired Form (optional)

1. Open the browser's developer tools (F12), and go to the Elements or Inspector tab.
2. Find the hidden input named `_wpnonce` in the Check for updates now form, and change its value to `bad`.
3. Click Check for updates now.

Expect to land back on the page with the notice "The form expired. Please try again." and no new row in the request log.

The page cannot check this one.

## Part 5: An Update

Each release in this part follows Publish a Release. Do not install a new version by hand. Updating through WordPress is the test.

### 5.1 A Draft Is Ignored

1. Follow Publish a Release for version `0.2.0`, but stop before step 5. Leave the draft unpublished.
2. On the test page, click Check for updates now.
3. Read the status line.

Expect the latest version found to still be 0.1.0, and no sign of 0.2.0.

The page can check this.

### 5.2 The Newer Release Is Found

1. Open the `v0.2.0` draft on the Releases page. Leave the notes as GitHub generated them, and click Publish release.
2. On the test page, click Check for updates now.
3. Read the status line, and look at the details table.

Expect the latest version found to read 0.2.0. The details table shows release `v0.2.0` and an asset named `wp-github-updater-test-theme-0.2.0.zip` with its size and a digest.

The page can check this.

### 5.3 WordPress Shows the Update

1. Go to Appearance, Themes.
2. Look at the entry for the fixture.
3. On the test page, look at the WordPress table.

Expect WordPress's usual notice on the theme that a new version is available, with "Update now".
The WordPress table shows "update available: 0.2.0" with a package path of the form `/repos/scottoffen/.../releases/assets/<number>`.

The page can check that the WordPress table agrees with the stored state.

### 5.4 The Details View Shows the Generated Notes

1. On Appearance, Themes, click the theme, and then click "View version 0.2.0 details". A frame opens with a page that the updater serves.

Expect the notes' list of changes with its links, and the Full Changelog line, as GitHub generated them, on a page headed with the theme's name and version.

The page cannot check this one.

### 5.5 Update Now

1. On Appearance, Themes, click the theme, click Update now, and wait for the message that the update succeeded.
2. Open the test page, and read the installed version in the details table.

Expect the update to succeed, the installed version to read 0.2.0, a single entry for the theme in the list, and the theme still the active theme. Open the site's front page, and expect it to render.

The page can check the installed version.

### 5.6 The Token Does Not Reach the Storage Host

1. Open the test page.
2. Look at the two newest rows in the request log.

Expect both rows to be Caused by Download. Expect the older of the two to be on `api.github.com` with a path ending in `/releases/assets/<number>`, status 302, Token sent yes, and a host under Redirects to that ends in `githubusercontent.com`. Expect the newer to be on that host, status 200, Token sent no. The newer row is the important one. It shows the token is not sent to the storage host.

The page can check this.

### 5.7 State Is Kept After the Update

1. On the test page, look at the settings section and the details table.

Expect the token still saved and masked, or the constant note if you use the constant, and a stored ETag in the details table. You should not need to enter anything again.

The page can check this.

### 5.8 Check for Updates Now Refreshes WordPress's List

1. Follow Publish a Release for version `0.2.1`, and publish it.
2. On the test page, click Check for updates now.
3. Look at the WordPress table on the test page, which loads right after the click.

Expect the table to say "update available: 0.2.1" straight away, not "0.2.0". The updater makes WordPress discard its own update list, so that it cannot keep offering the old package, and WordPress rebuilds the list on the next admin page, which is the page the click sends you back to. You should not normally see "(no entry)".

The page can check this. It accepts the moment of the click, when WordPress holds no entry, and it accepts an entry with the new version. It does not accept an entry with the old one.

### 5.9 Automatic Updates (optional)

1. On Appearance, Themes, click the theme, and click Enable auto-updates.
2. Make sure an update is waiting. The `0.2.1` from 5.8 is, as long as you have not installed it. On the test page, click Check for updates now.
3. Open the site shell, and run the command below. The first part tells WordPress it is running as a background task, which is how a real automatic update runs.

```bash
wp eval "define('DOING_CRON', true); wp_maybe_auto_update();"
```

4. On Appearance, Themes, click the theme, and click Disable auto-updates.

Expect the newer version to be installed and the theme to still be the active theme. If nothing changes, WordPress may not be able to reach your local site's own address for its safety check, so skip this item and say so.

The page cannot check this one.

## Part 6: Release Notes

Each item edits the notes of the latest published release. See Edit a Release's Notes. The latest release is the one with the highest version number.

Before you start, follow Publish a Release for version `0.2.2`, and do not install it. WordPress shows the View version ... details link only while an update is waiting, and the popup always describes the latest release, whichever link opens it.

### 6.2 Edited Notes on the Theme's Page

1. Edit the release's notes, and paste in the sample from Appendix A. The box at the bottom of this page has a Copy the sample notes button.
2. On Appearance, Themes, click the theme, and then click "View version ... details".

Expect everything to render: the five alerts with their colors, the table with borders and alignment, the `<details>` block opening and closing, the avatar image loaded, the second image shown as a link, and the code blocks. Expect no repository address in the heading.

The page cannot check this one.

### 6.3 Hostile Notes

1. Edit the release's notes, and paste in the text from Appendix B. The box at the bottom of this page has a Copy the hostile notes button.
2. On Appearance, Themes, click the theme, and then click "View version ... details".
3. Watch the browser tab's title and the page while it loads.

Expect nothing to run. The browser tab's title does not change, no alert appears, the page is not hidden, and no other site is loaded in a frame. The `javascript:` link shows as plain text, and the tracker image shows as a link.

The page cannot check this one.

### 6.4 A Release With No Notes

1. Edit the release, and delete everything in its description.
2. On Appearance, Themes, click the theme, and then click "View version ... details".

Expect the sentence "No release notes were published for this version."

The page cannot check this one.

## Part 7: A Renamed Repository

### 7.1 Follow the Redirect

1. On GitHub, open the repository's Settings, and find Repository name on the General page.
2. Add `-renamed` to the end of the name, and click Rename.
3. Do not change anything in WordPress.
4. On the test page, click Check for updates now.
5. Look at the two newest rows in the request log.

Expect both rows to be Caused by Check for updates now. Expect the older row to be on `api.github.com` with the old repository in the path, status 301. Expect the newer to be on `api.github.com` with a path of the form `/repositories/<number>/releases`, status 200, and Token sent yes on both. The status line shows the latest version found. Write down the exact path shape of the second row.

The page can check this.

### 7.2 Rename It Back

1. On GitHub, open the repository's Settings, and remove `-renamed` from the repository name. Click Rename.
2. On the test page, click Check for updates now.
3. Look at the newest row in the request log.

Expect a plain row Caused by Check for updates now, with status 200 on the original path.

The page can check this.

## Part 8: Releases That Are Wrong on Purpose

Each item runs the Defect Release workflow once. Do them one at a time, and delete the release and its tag before you start the next. See Run the Defect Release Workflow and Delete a Release and Its Tag.

### 8.1 wrong-folder

1. Run the Defect Release workflow with the defect `wrong-folder` and the version `9.9.1`, and publish the draft it creates.
2. On the test page, click Check for updates now, and read the status line.
3. On Appearance, Themes, click the theme, and click Update now.
4. Delete the release and its tag.

Expect the update to be offered, with the latest version found 9.9.1. Expect the update to fail with a message that includes "The update package is not in the right folder, so it was not installed." and the fixture to stay as it was.

The page can check that 9.9.1 was offered. You watch the failure.

### 8.2 no-folder

1. Run the Defect Release workflow with the defect `no-folder` and the version `9.9.2`, and publish the draft it creates.
2. On the test page, click Check for updates now, and read the status line.
3. On Appearance, Themes, click the theme, and click Update now.
4. Delete the release and its tag.

Expect the update to be offered, with the latest version found 9.9.2. Expect the update to fail with a message that includes "The update package is not in the right folder, so it was not installed." and the fixture to stay as it was.

The page can check that 9.9.2 was offered.

### 8.3 wrong-asset-name

1. Run the Defect Release workflow with the defect `wrong-asset-name` and the version `9.9.3`, and publish the draft it creates.
2. On the test page, click Check for updates now, and read the status line and the details table.
3. Delete the release and its tag.

Expect no update to be offered. The status line reads "The newest release is not set up correctly, so it cannot be installed." and the details table shows no release found. The updater does not fall back to an older release while this one is published.

The page can check this.

### 8.4 two-assets

1. Run the Defect Release workflow with the defect `two-assets` and the version `9.9.4`, and publish the draft it creates.
2. On the test page, click Check for updates now, and read the status line and the details table.
3. Delete the release and its tag.

Expect no update to be offered. The status line reads "The newest release is not set up correctly, so it cannot be installed." and the details table shows no release found.

The page can check this.

### 8.5 no-asset

1. Run the Defect Release workflow with the defect `no-asset` and the version `9.9.5`, and publish the draft it creates.
2. On the test page, click Check for updates now, and read the status line and the details table.
3. Delete the release and its tag.

Expect no update to be offered. The status line reads "The newest release is not set up correctly, so it cannot be installed." and the details table shows no release found.

The page can check this.

### 8.6 bad-tag

1. Run the Defect Release workflow with the defect `bad-tag` and the version `9.9.6`, and publish the draft it creates.
2. On the test page, click Check for updates now, and read the status line and the details table.
3. Delete the release and its tag.

Expect the release to be ignored, because its tag has no leading `v`. The latest version found is the highest valid release.

The page can check this.

### 8.7 pre-release-flag

1. Run the Defect Release workflow with the defect `pre-release-flag` and the version `9.9.7`, and publish the draft it creates.
2. On the test page, click Check for updates now, and read the status line and the details table.
3. Delete the release and its tag.

Expect the release to be ignored, because GitHub marks it as a pre-release. The latest version found is the highest valid release.

The page can check this.

### 8.8 A Suffixed Version

1. Follow Publish a Release for the version `0.3.0-beta.1`. At step 5, look at the draft before you publish it, and then publish it.
2. On the test page, click Check for updates now, and read the status line.
3. Delete the release and its tag.

Expect the draft to be marked as a pre-release already, and the published release to be ignored.

The page can check that it was ignored.

## Part 9: Clean-Up

Do this part last. Copy the report first, because it holds the results.

### 9.1 Remove the Token

1. On the test page, click Remove token in the settings section.
2. Look at the newest row in the request log, and read the status line.

Expect a green notice, "The access token was removed and the updates were checked." Expect a new row Caused by Remove token, with status 404 and Token sent no. The status line ends with the not found problem. The update notice, if one was showing, disappears. This only removes the token from WordPress. It does not revoke it on GitHub.

The page can check this.

### 9.3 Delete the Theme

1. On the test page, click Clear the request log.
2. Go to Appearance, Themes, click another theme, and click Activate.
3. Click this theme, and click Delete.
4. Open the site shell, and run this:

```bash
wp option list --search="wpgutt_*"
```

Expect the saved checklist and anything you did not clear to stay in the options table, because WordPress runs none of a theme's code when it is deleted.

The page cannot check this one.

### 9.4 Tokens and Test Releases

1. On GitHub, go to Settings, Developer settings, Personal access tokens, Fine-grained tokens, and delete the token you created.
2. Delete any defect releases and tags that are left. See Delete a Release and Its Tag.
3. Make the repository private again if you want it private.

## What to Send Back

Press "Copy the report" at the bottom of the checklist screen and paste it into your reply. It holds the versions, every tick and note, the details table and the request log, and never the token. Add:

- Anything that looked wrong, even if small, such as a missing log row, a different status than expected, a notice that did not appear, a button that sent you to the wrong page, or a warning on screen.
- The exact text of the status line for items 3.2, 3.3, 4.11, 8.3 and 8.6.
- The exact path shape of the second row in item 7.1.

## Appendix A: Sample Release Notes

Paste this as the description of the release.

```markdown
This release exists to test how release notes are shown. Nothing in it changes how the theme works.

## What's Changed
* Add the settings section to the Appearance menu by @scottoffen in https://github.com/scottoffen/wp-github-updater-test-theme/pull/1
* Show the status of the last update check by @scottoffen in https://github.com/scottoffen/wp-github-updater-test-theme/pull/2
* Fix the notice shown after a token is saved by @scottoffen in https://github.com/scottoffen/wp-github-updater-test-theme/pull/3

## New Contributors
* @scottoffen made their first contribution in https://github.com/scottoffen/wp-github-updater-test-theme/pull/1

**Full Changelog**: https://github.com/scottoffen/wp-github-updater-test-theme/commits/v0.1.0

## Text
A paragraph with **bold**, *italic*, ***both***, ~~strikethrough~~ and `inline code`. It has a [link](https://github.com/scottoffen/wp-github-updater), a [reference link][updater], and a plain address: https://github.com/scottoffen/wp-github-updater.

Emoji shortcodes are converted: :tada: :rocket: :white_check_mark: :warning:

[updater]: https://github.com/scottoffen/wp-github-updater

A hard line break follows this line.  
This is the next line.

---

## Lists
- A bullet
- Another bullet
  - A nested bullet
  - Another nested bullet
- A last bullet

1. First step
2. Second step
   1. A nested step
3. Third step

## Quote
> A quote with **bold** text.
>
> A second paragraph in the quote.

## Alerts
> [!NOTE]
> A note. Useful information.

> [!TIP]
> A tip. A helpful suggestion.

> [!IMPORTANT]
> Important. Something the reader needs.

> [!WARNING]
> A warning. Something that needs attention.

> [!CAUTION]
> A caution. Something with a risk.

## Table
| Feature | Supported | Notes |
|:--|:-:|--:|
| Tables | Yes | Aligned left, center and right |
| Alerts | Yes | Five kinds |
| Details | Yes | Block form only |

## Code
Inline `code` was shown above. A fenced block follows:

```php
<?php
$updater = Updater::theme(__FILE__)
    ->fromGitHub('owner/repository')
    ->prefix('example')
    ->register();
```

```json
{"name": "example", "version": "0.1.0"}
```

## Images
An image from a host the converter allows:

![GitHub avatar](https://avatars.githubusercontent.com/u/9919?s=64&v=4)

An image from a host it does not allow, which is shown as a link:

![Not allowed](https://example.com/images/sample.png)

## Details
<details>
<summary>Click to expand</summary>

Hidden text with **bold** and a list:

- One
- Two

</details>
```

## Appendix B: Hostile Notes

This is for your own test site only.

```markdown
## Hostile Notes
Plain text before.

<script>document.title='SCRIPT RAN'</script>

<img src=x onerror="document.title='ONERROR RAN'">

<iframe src="https://example.com"></iframe>

[a link](javascript:alert('JS LINK RAN'))

<a href="https://example.com" onclick="document.title='ONCLICK RAN'">an html link</a>

<style>body{display:none}</style>

![tracker](https://example.com/pixel.png)

Plain text after.
```
