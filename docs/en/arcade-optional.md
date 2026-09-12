# 🕹️ Arcade as an Optional Module

> **Principle:** MEeL-HUB must keep working 100% even if Arcade is missing,
> disabled, or deleted — no errors, no broken links, no leftovers. Arcade is
> an *add-on*, not a dependency.

> 🇮🇩 Versi Bahasa Indonesia: [docs/id/arcade-optional.md](arcade-optional.md)

---

## Contents

- [Overview](#overview)
- [Three Decision Layers](#three-decision-layers)
- [How to Disable / Enable](#how-to-disable--enable)
- [Behavior When Disabled](#behavior-when-disabled)
- [Integration Points](#integration-points)
- [Database](#database)

---

## Overview

Arcade (9 mini-games: Miku & Teto Run, Chess, Snake, 2048, Tetris, Breakout,
Simon Says, Ludo, MEeL!Mania) is no longer a mandatory part of the platform.
Every access, view, and maintenance path goes through one central gate:

```
modules/core/Modules.php   ← single source of truth
```

The class is self-contained: no autoloader, no session, no hard dependencies —
safe to call from `router.php`, `sitemap.php`, core pages, and arcade
controllers alike.

## Three Decision Layers

`Modules::enabled('arcade')` returns **true** only when all three pass.
Every failure is fail-closed (unreadable → treated as disabled):

| Layer | Check | Control |
|---|---|---|
| 1. **Physical** | `arcade/index.php` exists on disk | Deleting the folder removes the module |
| 2. **Flag** | `arcade/.disabled` file exists (ignored when `MEEL_ENV=development`) | Deploy-level kill switch |
| 3. **Toggle** | `site_settings.modules_arcade` ≠ `'0'` | Admin panel (runtime, persistent) |

The DB connection for layer 3 reuses the global `$conn` when available or
creates its own from `auth/settings.php` — and **never throws**: without a
DB/table the module counts as enabled (layers 1 & 2 are enough).

## How to Disable / Enable

### Option 1 — Admin panel (recommended)

1. Log in as admin → menu **☰ Modules** (or open `/admin/modules`).
2. **MEeL Arcade** card → flip the toggle.
3. Done. Takes effect for every user immediately — no restart, no deploy.

Stored in `site_settings` (key `modules_arcade`) and recorded in the activity
log (`toggle_module_arcade_1` / `toggle_module_arcade_0`).

### Option 2 — Flag file (deploy/CLI level)

```bash
# disable
touch arcade/.disabled

# re-enable
rm arcade/.disabled
```

The flag is ignored automatically in `development` environments. Ideal for
deploy pipelines that must guarantee arcade stays off on public servers
without touching the DB.

### Option 3 — Delete the folder

```bash
rm -rf arcade/
```

No further steps required — no code edits needed anywhere.

## Behavior When Disabled

| Surface | Behavior |
|---|---|
| `/arcade/*` pages | **302 → HUB** (`/`) — 302, not 301, so nothing is cached permanently and arcade can come back anytime |
| `/arcade/rhythm/api/*` + all `arcade/chess/controller/*.php` endpoints | **JSON 404** `{"error": "Module not available"}` |
| Physical files under `arcade/` | Gated by `arcade/.htaccess` **only** while the `.disabled` flag exists |
| MEeL logo on the HUB home | Rendered as a plain image (no arcade link) |
| Admin menu "Chess Room" | Not rendered |
| `sitemap.xml` | `/arcade/beranda` + 8 game pages excluded |
| Garbage Collector | `cleanChessRooms()` returns 0 without querying |
| PHPUnit | Chess & chess-GC suites auto-**skipped** |
| Database | **No changes** — data is preserved; re-enabling restores everything |

## Integration Points

| File | Role |
|---|---|
| `modules/core/Modules.php` | Central gate: `exists()`, `enabled()`, `guardRedirect()`, `guardJson()`, `guardJson404()` |
| `modules/core/Router.php` | `OPTIONAL_ROUTES` (separate from core `ROUTES`) + redirect/JSON-404 in `dispatch()` |
| `arcade/.htaccess` | Static gate for physical files while `.disabled` exists |
| `arcade/chess/controller/chess_helpers.php` | JSON 404 guard for all chess endpoints (skipped for CLI/PHPUnit) |
| `index.php` | Conditional HUB logo |
| `admin/header-admin.php` | Conditional "Chess Room" menu + new "Modules" menu |
| `admin/modules.php` + `controllers/admin/admin_actions.php` | Admin toggle page & handler |
| `modules/core/GarbageCollector.php` | `cleanChessRooms()` no-op when disabled |
| `sitemap.php` | Conditional arcade entries |
| `tests/integration/Chess*Test.php`, `GarbageCollectorChessRoomsIntegrationTest.php` | Skip guards |

## Database

No schema changes. The following tables remain and are idempotent:

| Table | Origin | State when module is gone |
|---|---|---|
| `rooms`, `moves` | `database/schema.sql` (core) | Left in place — legacy GC keeps them clean |
| `arcade_song`, `arcade_score` | `arcade/rhythm/migration.sql` | Import **optional** — only needed for MEeL!Mania |
