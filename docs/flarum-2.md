# Flarum 2 integration

This branch integrates Flarum 2.0.2 with the Arch Linux DE extensions on
`dev-main`. FoF Merge Discussions still uses a beta release;
Composer prefers stable releases when available. `fof/nightmode` is removed:
Flarum 2 provides native color scheme selection. Check the Appearance settings
and user preferences when testing an existing database.

## Official migration references

- [Forum upgrade guide](https://docs.flarum.org/update/)
- [Extension migration guide](https://docs.flarum.org/extend/update-2_0/)
- [API layer migration guide](https://docs.flarum.org/extend/update-2_0-api/)
- [Theming](https://docs.flarum.org/themes/)

Use a backed-up staging copy of a current 1.8 installation. PHP 8.3+ and
Composer 2 are required; this repository uses PHP 8.5. Change the database driver
in the staging `config.php` from `mysql` to `mariadb` for MariaDB. The local
installer already selects `mariadb`. Review local extenders and third-party
compatibility before migrating. Disable third-party extensions if needed to
isolate upgrade errors.

## Production readiness and release status

As reviewed on **30 October 2026**, keep production on Flarum 1.8 for now and
retain this branch for integration testing. Prefer migrating once every
extension we use has a stable compatible release. Reassess in November 2026,
leaving time to resolve remaining blockers before Flarum 1.8 reaches end of life.

The integration lock file already contains the latest Flarum-2-compatible FoF
releases available at the time of review:

| Extension | Compatible release | Release date | Status |
| --- | --- | --- | --- |
| [Anti Spam](https://packagist.org/packages/fof/anti-spam) | 2.0.1 | 23 October 2026 | Stable |
| [Formatting](https://packagist.org/packages/fof/formatting) | 2.0.0 | 23 October 2026 | Stable |
| [Merge Discussions](https://packagist.org/packages/fof/merge-discussions) | 2.0.0-beta.4 | 8 July 2026 | Beta |
| [Sitemap](https://packagist.org/packages/fof/sitemap) | 3.0.0 | 23 October 2026 | Stable |
| [Split](https://packagist.org/packages/fof/split) | 2.0.1 | 23 October 2026 | Stable |
| [Upload](https://packagist.org/packages/fof/upload) | 2.0.3 | 23 October 2026 | Stable |

Merge Discussions is the only remaining FoF prerelease. On 25 October,
[GreXXL reported](https://discuss.flarum.org/d/19460/196) that it has been used on
the Flarum community forum without issues since its first beta and simply lacks
a stable tag. This is a maintainer report, not a release date or a replacement
for testing our own configuration. No stable-release ETA has been published.
Our six `archlinux-de/*` extensions also remain on `dev-main`.

Recheck release tags and the open work when reassessing readiness:

- Sitemap is now stable, but still has an open [large-forum memory-exhaustion fix port](https://github.com/FriendsOfFlarum/sitemap/issues/78)
  from 1.x. Validate sitemap generation against our production dataset.
- Merge Discussions has pending [broader tests and bug fixes](https://github.com/FriendsOfFlarum/merge-discussions/pull/95).
- Split’s [stable 2.0.0 release](https://github.com/FriendsOfFlarum/split/releases/tag/2.0.0)
  includes fixes for posts by deleted users and read/unread state.

The [official support policy](https://github.com/flarum/flarum/security) gives
Flarum 1.8 critical security and critical bug fixes only until **31 December
2026**. After that date, 1.x receives no further releases, including security
fixes. Waiting for stable extensions must be reassessed against this deadline;
there is no upstream guarantee that every extension will be stable beforehand.

Before production deployment, tag compatible releases of all six
`archlinux-de/*` extensions and replace their `dev-main` constraints with release
constraints. Refresh `composer.lock` and repeat the staging checks with the
actual versions intended for production. The successful migration rehearsal
alone does not establish readiness for authenticated moderation and upload
workflows, which still need validation.

## Production migration

1. Take a fresh database backup and a complete file backup, including avatars,
   uploaded attachments, `config.php` and the current code/lock file. Keep these
   together for rollback. Pause writes and scheduled jobs for the migration.
2. While Flarum 1.x and `fof/nightmode` are still installed, disable Nightmode
   and use its admin **Uninstall** action. This rolls back its migration records
   and removes published extension assets. Its rollback migrations are no-ops,
   so this does **not** delete its settings or user preferences. Do this before
   deploying the branch, while the extension code is still available.
3. Deploy the reviewed integration revision, including `composer.lock`. For
   MariaDB, change the production `config.php` database driver from `mysql` to
   `mariadb`. From the production forum directory, run `just deploy` as the usual
   deployment user. It installs the locked packages (removing `fof/nightmode`),
   enables installed extensions, migrates, publishes assets, clears caches and
   restarts the forum PHP service. Do not use the local `init` or import recipes
   on production; they replace the database, and import also selects log-only mail.
4. In a database client connected to the production forum database, remove the
   obsolete Nightmode keys with the SQL below. We intentionally discard these
   choices instead of migrating them. Other user preferences are preserved.
5. In **Appearance**, select **Auto** and turn off **Show theme selector**, then
   save. Run `php flarum cache:clear` after the direct database cleanup. Auto
   follows the system preference for users without a native colour preference.
   Hiding the selector removes the header control; Flarum 2.0.1 still offers
   colour choices in account settings, so this is not an enforced auto-only mode.
6. Check guest and signed-in pages in light and dark system modes, login,
   posting, moderation, private messages, search, uploads, avatars, local emoji,
   feeds and legacy redirects. Verify Emoji's CDN setting is unset for our local
   default and browser requests for emoji stay on our domain. Confirm production
   mail and scheduling settings before resuming writes and scheduled jobs.

Run this cleanup after Nightmode has been disabled/uninstalled and its Composer
package removed, so it cannot recreate preferences:

```sql
START TRANSACTION;

UPDATE users
SET preferences = JSON_REMOVE(preferences, '$.fofNightMode', '$.fofNightMode_perDevice')
WHERE JSON_VALID(preferences)
  AND JSON_CONTAINS_PATH(preferences, 'one', '$.fofNightMode', '$.fofNightMode_perDevice');

DELETE FROM settings
WHERE settings.key IN (
    'fof-nightmode.default_theme',
    'fofNightMode.show_theme_toggle_on_header_always',
    'fofNightMode.show_theme_toggle_in_solid'
);

COMMIT;
```

Nightmode's `flarum_nightmode` browser cookie is ignored by native Flarum; no
browser-side migration or cookie cleanup is needed. Do not delete native
`colorScheme` preferences or unrelated settings. The inspected Nightmode 1.6.0
[source](https://github.com/FriendsOfFlarum/nightmode/tree/1.6.0/migrations)
confirms that uninstall does not clean these database keys.

If rollback is needed, restore the matching pre-upgrade database, files and
code together. Switching code back alone does not reverse the Flarum 2 migration.

## Local migration

Docker images in this repository are for development and CI only. The PHP image
includes `zip` because Flarum 2 checks for it when installing a fresh test forum.
Production PHP and Nginx configuration are managed separately.

With the existing local database running:

```sh
just install
just flarum migrate
just flarum app:enable-extensions
just flarum migrate
just flarum assets:publish
just flarum cache:clear
just test
just test-e2e
```

For a new disposable database, use `just init` instead of the migration commands.
It deletes and recreates the local database. Restart PHP after upgrading an
existing running instance. Production deployment needs its own backup and
configuration update before running the deployment recipe.

## Editing extensions

Composer installs `archlinux-de/*` from source and other packages from archives.
Each directory in `vendor/archlinux-de/` is an independent Git checkout, initially
on `main` at the revision pinned in `composer.lock`:

```sh
git -C vendor/archlinux-de/flarum-click-image status
git -C vendor/archlinux-de/flarum-click-image switch -c flarum-2-integration
```

Edit and test those files directly. For extensions with frontend changes, build
in their `js/` directory using their own package scripts, then run
`just flarum assets:publish` and `just flarum cache:clear`.

Extension edits are ignored by the parent repository and are **not** saved by a
commit on this integration branch. Commit them in each extension repository and
keep those commits available to collaborators. Do not run dependency updates
with unsaved extension work. After publishing extension commits, refresh the
pinned revisions with:

```sh
just composer update 'archlinux-de/*' --with-all-dependencies
just composer update --lock --no-scripts
```

`dev-main` allows updates; `composer.lock` still pins exact commits for installs.
The existing mc-bumpface plugin tightens resolved release constraints, so refresh
the lock hash with the second command after updates (as `just update` does).

The disposable installer disables FoF Anti Spam's registration throttle so the
browser suite can register several users from one address. Existing test
databases need that setting changed separately; production should retain its
registration limits.

## Local API changes

Console commands now return integer exit codes. Scheduled callbacks return void.
Extension ordering includes enabled dependencies before selecting extensions to
enable. Hidden post pruning uses the internal API client in place of the removed
`DeletePost` command, retaining authorization and extension deletion hooks.

## Validation

A fresh Flarum 2.0.2 installation passes `just test` (four PHPUnit tests, Composer
validation, coding standards and static analysis) and all eight Cypress tests:
registration, posting with locally served emoji, feed, both legacy redirects,
branding, native dark mode persistence and the private messages page. The
fresh-install check also verifies the development PHP image meets the installer
requirements. Composer reported no security advisories.

The production backup rehearsal below used 2.0.1 and has not been repeated
with the 30 October dependency update. Authenticated moderation,
uploads and production-specific operational settings still need validation
before deployment.

## Avatar cleanup

Flarum 2.0.1 removes the base avatar and its `@2x`/`@3x` variants from the
user model's deletion hook. The custom scheduled orphan sweep has been removed:
it only recognized base filenames and would delete valid variants. Native
cleanup was verified by creating and deleting a temporary user with all three
avatar files, then rolling back the database transaction.

Existing orphan files from older installations are not retroactively removed.

## Suspended user retention

`app:purge-suspended-users` retains its existing policy: delete non-staff users
whose suspension is still active and whose last activity was more than three
years ago. Its description and constant name now reflect that inactivity rule.
Flarum's suspension extension stores `suspended_until`, but no suspension start
date, so this command cannot determine how long an account has been suspended.

## Official extensions under test

Messages and Emoji are enabled for integration testing. Approval is now a direct
dependency, so removing FoF Anti Spam will not remove it. Emoji uses the local
asset configuration below. Audit, Deck, Statistics and Realtime are omitted.

`app:enable-extensions` enables all installed Flarum extensions in dependency
order and skips those already enabled. Composer dependencies determine which
extensions are deployed; remove an unwanted extension from Composer rather than
maintaining a separate activation list.

## Local emoji assets

Emoji uses locally served Twemoji PNGs, rather than its upstream jsDelivr
default. `twemoji/flarum-assets` is a local Composer package definition for the
upstream [jdecked/twemoji](https://github.com/jdecked/twemoji/tree/v17.0.3)
archive, not an official Packagist package. Composer downloads, checksums,
caches and locks the archive. The package is declared as an asset-only Flarum
extension and depends on `flarum/emoji`, so activation orders them correctly.

Flarum publishes the archive's existing `assets/` directory using its normal
[extension asset mechanism](https://docs.flarum.org/extend/assets/), exactly as
for the Arch Linux theme. Images are served from
`/assets/extensions/twemoji-assets/72x72/`. Both PNG and SVG assets are published;
Emoji uses the PNGs. Licenses are retained in `vendor/twemoji/flarum-assets/`.
Twemoji graphics are CC BY 4.0.

There is no custom publisher, extra recipe or Composer install hook. Enabling
the asset extension and the usual `just flarum assets:publish` publish the files.
The existing initialization, database import and deployment workflows already
run that command. `just install` only installs dependencies; publication follows
once Flarum is configured. To repair missing images, rerun `assets:publish`.

The Emoji CDN setting remains configurable in the admin panel; leave it unset
for our local default. The duplicate local emoticon mapping was removed because
the enabled Emoji extension provides the same mapping.

Emoji updates have no custom version or bundle-hash gate. Flarum currently
declares `@twemoji/api: "17"` and uses only that major version for its CDN mirror
placeholder. We use the same-major asset family as a practical compatibility
policy, with a browser rendering check of locally served images. This is an
expectation, not an upstream guarantee that every future image remains present.
Missing images do not block activation or asset publication.

The root Composer requirement `^17.0.3` allows compatible 17.x releases. Since
the asset package is defined inline, Composer can only select releases added to
that definition; `composer update` does not discover new upstream tags itself.
To update assets, change the package version, archive URL, reference and checksum
in `composer.json`, then run:

```sh
just composer update twemoji/flarum-assets --no-scripts
just composer update --lock --no-scripts
just flarum assets:publish
just test
```

No PHP compatibility constants or hashes need changing. For a new Twemoji major,
adjust the root asset constraint too, and check rendering. Updating the Flarum
Emoji extension within its supported Composer range needs no separate review
step or approval gate.

Upstream documents the self-hosted mirror setting and required `72x72/` layout
in its [Emoji guide](https://docs.flarum.org/de/extensions/emoji/). The local
package uses Composer's [package repository support](https://getcomposer.org/doc/05-repositories.md#package-2).

## Web-server asset delivery

The Docker Nginx configuration now compresses text responses, caches JavaScript
and stylesheets for one year, and images/fonts for one month. Cache rules apply
only under `/assets/`; forum responses retain `no-store` and feed routes still
reach Flarum. Missing static assets return 404. Production Nginx configuration
is managed outside this repository and needs the equivalent settings if absent.

Composer's blanket security advisory bypass has been removed. Regular validation
covers our extension-enabling command, coding standards, static analysis and
the remaining browser tests. The command uses PHPUnit 12 unit tests in
`tests/unit`, run through `just test`, following the structure described in
[Flarum’s testing guide](https://docs.flarum.org/extend/testing/). These tests use
fixed extension fixtures and mocked manager operations without booting Flarum or
connecting to a database. Fresh installation and local emoji rendering are
covered by the end-to-end workflow. Development Nginx configuration, cache
headers and missing-asset responses were checked once;
these checks do not validate the separately managed production Nginx setup. The
registration fixtures use alphabetic names so random digit strings cannot be
mistaken for contact details by the active spam filter.

## Production backup migration rehearsal

On 2026-10-16, the supplied Flarum 1.8.20 production database and avatar archive
were restored locally and migrated to 2.0.1. The complete import recipe was then
rerun successfully:

```sh
just import-db-dump ../forum.sql.gz
just import-avatars ../forum-avatars.tar.gz
```

The database import replaces the local database. It selects log-only mail before
booting Flarum, enables installed extensions, migrates, publishes assets and
clears caches. Avatar import makes the extracted directory and images publicly
readable; the restored directory initially had mode `0700`, which prevented
Nginx from serving the images.

Both restores preserved all 2,835 users, 28,588 discussions, 252,364 posts and
14 tags. Aggregate fingerprints of post content and discussion metadata matched
before and after migration. Of the 33 original tables, only migration records,
settings and permissions changed row counts. Migration records increased from
177 to 223; repeating migration reported nothing pending. All 413 referenced
avatars exist and return HTTP 200.

Read-only HTTP checks passed for the forum, discussion and tag APIs, search,
an existing discussion, both legacy redirect formats, the Atom feed and local
emoji images. A Chrome smoke test rendered the discussion list and an existing
post without JavaScript errors. `just test` passed. Avatar import permissions
were verified during the rehearsal. Authenticated moderation, messaging and administrative workflows
were not exercised against the restored accounts.

The supplied archive contains avatars only. The database retains 1,007 upload
records, but their attachment files were not supplied, so attachment delivery
was not validated. Production was unchanged. The local migrated forum is
available at <http://localhost:8380>; its database uses the development container's
temporary storage and is lost when that container is removed.
