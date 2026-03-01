# Flarum 2.0 Upgrade

This document tracks the status of upgrading forum.archlinux.de to Flarum 2.0.

## Custom code changes

### EnableExtensions command (`src/Console/EnableExtensions.php`)

The `AbstractCommand::fire()` return type changed from `void` to `int` in Flarum 2.0.
Updated to return `0` on success.

### Other custom code (no changes needed)

- `src/Middleware/ContentSecurityPolicy.php` -- `RequestUtil::getActor()` and the middleware API are unchanged.
- `src/ServiceProvider/ErrorLogProvider.php` -- `AbstractServiceProvider` and `Config::inDebugMode()` are unchanged.
- `src/ServiceProvider/SessionServiceProvider.php` -- `AbstractServiceProvider` and the config repository are unchanged.
- `extend.php` -- All extenders (`Console`, `ServiceProvider`, `Middleware`, `Formatter`) and the `s9e\TextFormatter` APIs are unchanged.
- `site.php` -- `Flarum\Foundation\Site::fromPaths()` is unchanged.

## composer.json changes

### Done

| Package | Old | New |
|---------|-----|-----|
| `flarum/core` | `^1.8.13` | `^2.0` |
| `flarum/bbcode` | `^1.8.0` | `*` |
| `flarum/flags` | `^1.8.2` | `*` |
| `flarum/lang-english` | `^1.8.0` | `*` |
| `flarum/likes` | `^1.8.1` | `*` |
| `flarum/lock` | `^1.8.2` | `*` |
| `flarum/markdown` | `^1.8.1` | `*` |
| `flarum/mentions` | `^1.8.5` | `*` |
| `flarum/nicknames` | `^1.8.2` | `*` |
| `flarum/statistics` | `^1.8.1` | `*` |
| `flarum/sticky` | `^1.8.2` | `*` |
| `flarum/subscriptions` | `^1.8.1` | `*` |
| `flarum/suspend` | `^1.8.5` | `*` |
| `flarum/tags` | `^1.8.6` | `*` |
| `fof/anti-spam` | `^1.1.4` | `^2.0` |
| `fof/sitemap` | `^2.5.0` | `^3.0` |
| `fof/split` | `^1.1.1` | `^2.0` |

### Pending: custom extensions

Version constraints for our own extensions are unchanged and need to be updated
after releasing v2-compatible versions of each. The `flarum-v2` branches with the
necessary changes have been pushed to all of the following repos:

| Package | Branch |
|---------|--------|
| `archlinux-de/flarum-theme-archlinux` | `flarum-v2` |
| `archlinux-de/flarum-discussion-feed` | `flarum-v2` |
| `archlinux-de/flarum-anti-spam` | `flarum-v2` |
| `archlinux-de/flarum-redirect-ll` | `flarum-v2` |
| `archlinux-de/flarum-redirect-fluxbb` | `flarum-v2` |
| `archlinux-de/flarum-click-image` | `flarum-v2` |

### Pending: third-party packages without v2 support

| Package | Status | Action |
|---------|--------|--------|
| `flarum-lang/german` | No v2 release yet | Wait for upstream release |
| `fof/nightmode` | No v2 release yet | Flarum 2.0 has built-in dark mode support; this package may no longer be needed |
| `matteocontrini/flarum-imgur-upload` | Appears abandoned (last release 2021) | Consider switching to `fof/upload` |

## MediaWiki integration

The `archlinux-de/flarum-mediawiki` extension (MediaWiki side) uses only the
`POST /api/token` and `GET /api/users/{id}` endpoints. Both are unchanged in
Flarum 2.0 and no update is needed.
