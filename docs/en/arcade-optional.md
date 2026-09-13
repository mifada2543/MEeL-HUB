# 🕹️ Arcade as a Separate Extension

> **Principle:** MEeL-HUB must keep working 100% without Arcade — no errors,
> no broken links, no leftovers. Arcade is a *separate extension*, not a
> core dependency.

> 🇮🇩 Versi Bahasa Indonesia: [docs/id/arcade-optional.md](arcade-optional.md)

---

## Contents

- [Overview](#overview)
- [What Changed](#what-changed)
- [Three Decision Layers](#three-decision-layers)
- [How to Install / Remove](#how-to-install--remove)
- [Behavior When Not Installed](#behavior-when-not-installed)
- [Arcade Database](#arcade-database)
- [Integration Points](#integration-points)
- [FAQ](#faq)

---

## Overview

Arcade (9 mini-games: Miku & Teto Run, Chess, Snake, 2048, Tetris, Breakout,
Simon Says, Ludo, MEeL!Mania) is a **separate extension** from MEeL-HUB.
The `arcade/` folder is not required — it can be installed at any time or
removed entirely without affecting the HUB.

The arcade extension manages its **own database** (`arcade/schema.sql` +
`arcade/migrate.php`) — the `rooms`, `moves`, `arcade_song`, and
`arcade_score` tables are not part of the core HUB schema.

### What Changed

| Aspect | Previously (built-in) | Now (extension) |
|---|---|---|
| `arcade/` folder | Part of HUB repo | Separate extension, can be absent |
| DB `rooms`, `moves` | In `database/schema.sql` | In `arcade/schema.sql` |
| DB `arcade_song`, `arcade_score` | In `arcade/rhythm/migration.sql` | In `arcade/schema.sql` |
| Migration | `php database/migrate.php` (v12) | `php arcade/migrate.php` (separate) |
| install.sh | Core migration only | Optional arcade prompts (step 6b) |

## Three Decision Layers

`Modules::enabled('arcade')` returns **true** only when all three pass.
Every failure is fail-closed (unreadable → treated as disabled):

| Layer | Check | Control |
|---|---|---|
| 1. **Physical** | `arcade/index.php` exists on disk | Absent folder = extension not installed |
| 2. **Flag** | `arcade/.disabled` file exists (ignored when `MEEL_ENV=development`) | Deploy-level kill switch |
| 3. **Toggle** | `site_settings.modules_arcade` ≠ `'0'` | Admin panel (runtime, persistent) |

The DB connection for layer 3 reuses the global `$conn` when available or
creates its own from `auth/settings.php` — and **never throws**: without a
DB/table the module counts as enabled (layers 1 & 2 are enough).

## How to Install / Remove

### Installing Arcade

1. Copy the `arcade/` folder to the HUB project root.
2. Run the arcade migration:
   ```bash
   php arcade/migrate.php
   ```
   Or use `install.sh` which offers interactive arcade installation.

### Disabling — Admin Panel (recommended)

1. Log in as admin → menu **☰ Modules** (or open `/admin/modules`).
2. **MEeL Arcade** card → flip the toggle.
3. Done. Takes effect for every user immediately — no restart, no deploy.

Stored in `site_settings` (key `modules_arcade`) and recorded in the activity
log (`toggle_module_arcade_1` / `toggle_module_arcade_0`).

### Disabling — Flag file (deploy/CLI level)

```bash
# disable
touch arcade/.disabled

# re-enable
rm arcade/.disabled
```

The flag is ignored automatically in `development` environments.

### Removing Arcade Completely

```bash
rm -rf arcade/
```

No further steps required:

- Router moves all arcade routes to the optional route map — missing
  routes are automatically served with a 302 redirect to the HUB.
- All links/menus/sitemap depend on `Modules::enabled()` → automatically
  not rendered.
- `GarbageCollector::cleanChessRooms()` becomes a no-op.

## Behavior When Not Installed

| Surface | Behavior |
|---|---|
| `/arcade/*` pages | **302 → HUB** (`/`) — 302, not 301, so arcade can be re-enabled anytime |
| `/arcade/rhythm/api/*` + all `arcade/chess/controller/*.php` endpoints | **JSON 404** `{"error": "Module not available"}` |
| Physical files under `arcade/` | Gated by `arcade/.htaccess` when `.disabled` flag exists or folder is absent |
| MEeL logo on the HUB home | Rendered as a plain image (no arcade link) |
| Admin menu "Chess Room" | Not rendered |
| `sitemap.xml` | `/arcade/beranda` + 8 game pages excluded |
| Garbage Collector | `cleanChessRooms()` returns 0 without querying |
| PHPUnit | Chess & chess-GC suites auto-**skipped** |
| Database | **No changes** — no arcade tables in core schema |

## Arcade Database

The arcade extension manages its own database — **no arcade tables exist in
`database/schema.sql`** or `database/migrate.php`:

| Table | Origin | Description |
|---|---|---|
| `rooms` | `arcade/schema.sql` | Chess multiplayer rooms |
| `moves` | `arcade/schema.sql` | Chess moves |
| `arcade_song` | `arcade/schema.sql` | Rhythm songs (builtin + custom) |
| `arcade_score` | `arcade/schema.sql` | Rhythm scores |

Migration is run separately:
```bash
php arcade/migrate.php
```

The arcade migration uses an `arcade_db_version` table for version tracking
(equivalent to `db_version` in core). Currently only v1 creates all tables.

## Integration Points

| File | Role |
|---|---|
| `modules/core/Modules.php` | Central gate: `exists()`, `enabled()`, `guardRedirect()`, `guardJson()`, `guardJson404()` |
| `modules/core/Router.php` | `OPTIONAL_ROUTES` (separate from core `ROUTES`) + redirect/JSON-404 in `dispatch()` |
| `arcade/.htaccess` | Static gate for physical files while `.disabled` exists |
| `arcade/_gate.php` | PHP guard — optional module, page path → 302, API path → JSON 404 |
| `arcade/chess/controller/chess_helpers.php` | JSON 404 guard for all chess endpoints (skipped for CLI/PHPUnit) |
| `index.php` | Conditional HUB logo |
| `admin/header-admin.php` | Conditional "Chess Room" menu + new "Modules" menu |
| `admin/modules.php` + `controllers/admin/admin_actions.php` | Admin toggle page & handler |
| `modules/core/GarbageCollector.php` | `cleanChessRooms()` no-op when disabled |
| `sitemap.php` | Conditional arcade entries |
| `tests/integration/Chess*Test.php`, `GarbageCollectorChessRoomsIntegrationTest.php` | Skip guards |

## FAQ

**Does removing arcade delete data?**
No. Arcade data is stored in extension DB tables (`rooms`, `moves`,
`arcade_song`, `arcade_score`). Removing the `arcade/` folder only removes
the PHP/JS code — data stays safe in the DB.

**How do I install arcade after HUB is running?**
1. Copy the `arcade/` folder to the project root
2. Run `php arcade/migrate.php`
3. Enable from Admin → Modules (toggle)

**Why is the redirect 302 and not 301?**
301 is cached permanently by browsers; 302 allows arcade to be re-enabled without cache issues.

**What happens if the DB goes down?**
The gate still works from physical detection + flag. DB toggle read failure → module treated as enabled (fail-open only on the toggle layer; never blocks the HUB).

**How to add a new optional module?**
Add a new entry in `Modules::OPTIONAL` (marker, flag_file, setting,
home_route), move its routes to `OPTIONAL_ROUTES` if using the router, then
add its module card in `admin/modules.php`.
