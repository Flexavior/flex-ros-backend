# CR141026-Docs-Products-Settings-UI

| Field | Value |
| ----- | ----- |
| CR ID | CR141026-Docs-Products-Settings-UI |
| Classification | Feature / UX / Incomplete closure |
| Repos / branches | frontend, backend |
| Related TIK | TIK141026-021-fe-docs-products-settings-ui, TIK141026-022-be-products-marketing-notifications, TIK141026-023-fe-marketing-welcome-notifications, TIK141026-024-workspace-ghupload-docs-products-ui |
| When | 2026-10-14 |
| Where | MSS-CRM SPA — Documents, Products, Settings (admin), Marketing, login/session |

## 1) Problem / opportunity

Operational and onboarding UX gaps:

1. **Documents — Upload to library** stacks Library, Category, and Title on one row but places a native “Choose file” control and **Upload** on a second row, breaking visual rhythm and wasting vertical space.
2. **Products & Services** loads the full catalogue with no way to narrow by name when the list grows; users need multi-word name filtering without leaving the table.
3. **System Settings (admin)** — General threshold inputs appear misaligned; Inbox access is a full-width block with long inline help; the admin layout should pair General + Inbox side-by-side and move Lead picklists below, with inbox guidance in a help modal instead of body copy.
4. **Marketing — incomplete:** **New Campaign Plan** requires **Channel \*** but many environments show **“No channels registered.”** at the bottom; there is no in-app way to add channels despite `POST /marketing/channels` and the `marketing_channels` table (`id`, `name`, `code`, `type`, `is_active`, timestamps). Product expects a **pre-defined catalogue** (Event, Exhibition & Fair, Facebook, Phone, Email, TikTok, …) plus **+ New Channel** for marketing users.
5. **Login welcome — feature:** No differentiated greeting after successful sign-in; users should see **Welcome {name}** on first successful login and **Welcome back {name}** on subsequent sessions, each appended with **active system notifications** when present (e.g. maintenance banners from System Admin).
6. **System notifications — feature:** System Admin must broadcast messages (e.g. “System Maintenance on DD-MM-YYYY”) to **all authenticated users**, surfaced on login and on session refresh (`/auth/me`) until expiry or deactivation.

## 2) Root cause / drivers

| Area | Driver |
| ---- | ------ |
| Documents | Legacy form layout (`grid cols-3` + full-width file row + submit below). |
| Products | `GET /products-services` supports `type` only; FE table has no search affordance. |
| Settings | Sequential full-width cards and `kpi-sub` prose; General grid max-width constrains alignment vs Inbox checklist. |
| Marketing channels | Seeder exists in `RoleAndUserSeeder` but fresh/partial DBs may have zero rows; `Marketing.jsx` lists channels read-only with no create UI; campaign form cannot satisfy required `channel_id`. |
| Welcome / notifications | No `system_notifications` persistence; auth payload has no welcome hint or broadcast messages; `users` lacks first/last login markers for “first time” vs “returning”. |

PRD alignment: filter-before-display for lists; consistent help pattern (`HelpIcon` + `HelpModal`); admin settings clarity; marketing channel register as master data; operational comms on login without email spam.

## 3) Solution / scope (design-level)

### 3.1 Documents — Upload toolbar (single row)

**Target layout (one horizontal band, same baseline):**

| Library (select) | Category (select) | Title (input) | File drop zone (+) | Upload (primary) |

- Replace native file input with a **compact drop target**: dashed border, centered **+** icon, click-to-browse and drag-and-drop; show selected file name (truncated) inside or beside the zone.
- **Upload** button remains explicit submit (same row, not below the selectors).
- Preserve existing API: `POST /documents/templates` multipart (`library`, `category`, `title`, `file`); RBAC unchanged (`capabilities.upload_libraries`).
- Accessibility: drop zone is a button or `role="button"` with keyboard activation; `aria-label` for file selection; visible focus ring.
- Responsive: below ~768px, wrap to two rows (selectors row + file + upload) without losing drop behavior.

**Files (expected):** `frontend/src/pages/Documents.jsx`, `frontend/src/styles.css` (or scoped `.documents-upload-row`).

### 3.2 Products — Name column search (lens + advanced words)

**UX:**

- In catalogue table header, **Name** column shows label + **lens icon** control (search).
- Activating search opens a small panel or modal (“Search products & services”) with one text field: user may enter **3–4 words** (space-separated); helper text: “All words must appear in the name (any order).”
- **Apply** runs filter and refreshes the table; **Clear** resets to full list.
- Empty filter result: use existing branded empty/sub copy pattern (not a silent blank table).

**API contract (backend):**

- Extend `GET /api/v1/products-services` with optional query param **`search`** (string, max length bounded e.g. 200 chars).
- Server splits on whitespace, drops empty tokens, caps token count (e.g. 4); each token applies `WHERE name LIKE %token%` (case-insensitive per DB collation) — **AND** semantics across tokens.
- Optional: also match `code` for a single token only if product name search is insufficient (document in TIK if added).
- No pagination change required initially (catalogue size small); design leaves room for `page`/`per_page` later.

**Files (expected):** `frontend/src/pages/Products.jsx`, `backend/app/Http/Controllers/Api/V1/ProductServiceController.php`, feature test for search AND semantics.

### 3.3 System Settings — layout + inbox help modal

**Layout:**

```
┌─────────────────────┬─────────────────────┐
│ General             │ Inbox access (role) │
│ (aligned inputs)    │ (checkbox list)     │
└─────────────────────┴─────────────────────┘
┌───────────────────────────────────────────┐
│ Lead picklists (full width)               │
└───────────────────────────────────────────┘
│ Stage checklists (unchanged flow)         │
```

- **General (left):** single column or `cols-2` with consistent `.form-row` alignment (labels + inputs same width grid); remove awkward max-width that causes misalignment with Inbox card.
- **Inbox access (right):** same card height behavior as General where possible; section save button stays in card header.
- **Remove** the paragraph starting “Controls who sees the Inbox menu…”.
- **Add** `HelpIcon` (info/help) beside “Inbox access (by role)” title opening modal with exact copy:

  > Controls who sees the Inbox menu and may use inbox APIs. Only roles defined as inbox-capable in the product can be enabled. After saving, CRM users refocus this tab or reload to refresh the Inbox menu (session is updated automatically).

- Centralize copy in `frontend/src/content/helpText.js` as `inboxAccessSettings` (or equivalent key).
- Lead picklists + checklist sections remain below; no change to save/modal behavior from CR141026 inbox/settings work unless regression found.

**Files (expected):** `frontend/src/pages/Settings.jsx`, `frontend/src/styles.css`, `frontend/src/content/helpText.js`.

### 3.4 Marketing — channel catalogue + New Channel (#Incomplete)

**Data model (existing):** `marketing_channels` — `id`, `name`, `code`, `type` (`social|messaging|search|email|offline|other`), `is_active`, `created_at`, `updated_at`.

**Pre-defined catalogue (seed / migration data):**

| name | code (example) | type |
| ---- | -------------- | ---- |
| Event | `event` | `offline` |
| Exhibition & Fair | `exhibition_fair` | `offline` |
| Facebook | `facebook` | `social` |
| Phone | `phone` | `messaging` |
| Email | `email` | `email` |
| TikTok | `tiktok` | `social` |

- Extend `RoleAndUserSeeder` (or dedicated `MarketingChannelSeeder` invoked from `DatabaseSeeder`) with the above via `updateOrCreate` by `code`; retain or merge legacy seed rows (LinkedIn, Viber, etc.) without duplicate codes.
- `GET /marketing/channels` returns **active only** (`is_active = true`), ordered by `name`.
- Existing `POST /marketing/channels` unchanged contract: `name`, `code`, `type` (validate uniqueness on `code`).

**Marketing page UX:**

- **New Campaign Plan — Channel \***: dropdown populated from API; if empty, show inline hint linking user to **Channels** section below (not a dead-end required field).
- **Channels** card (bottom section):
  - When empty: show **“No channels registered.”** and primary **+ New Channel** (not plain text only).
  - When populated: table or list (name, type, code) + **+ New Channel**.
  - **+ New Channel** opens modal (or inline form): Name \*, Type \*, Code \* (or auto-slug from name with edit); submit → `POST /marketing/channels` → refresh list and campaign dropdown.
- RBAC: marketing module users who can access `/marketing` may create channels unless product matrix restricts — default **marketing role + approvers + org-level** same as campaign create; document in TIK if middleware added.

**Files (expected):** `frontend/src/pages/Marketing.jsx`, `backend/database/seeders/RoleAndUserSeeder.php` (or new seeder), optional `MarketingController::channels()` filter.

### 3.5 Login welcome + system notifications (#Feature)

**Welcome copy (frontend):**

- After successful **password login**, **SSO exchange**, or restored session on first app entry post-login, show a **non-blocking banner or modal** (reuse modal pattern from settings success):
  - First successful login ever: **`Welcome {displayName}`**
  - Later logins: **`Welcome back {displayName}`**
- Append **`(System notification: …)`** or a second line listing one or more active admin messages when present (see below). Dismissible per session (localStorage key e.g. `mss_welcome_dismissed_{userId}_{date}` optional — TIK decides banner vs modal).

**Backend — user session markers:**

- Migration on `users`: `first_login_at` (nullable timestamp), `last_login_at` (nullable timestamp).
- On successful login / SSO token issue: if `first_login_at` is null, set `first_login_at = now()` and flag **`welcome_kind: first`**; else set **`welcome_kind: returning`** and update `last_login_at`.
- Expose on login response and `/auth/me`: `welcome_kind`, `welcome_messages` (array of strings or structured `{ id, body, effective_until }`).

**Backend — system notifications (admin broadcast):**

- New table **`system_notifications`** (name may be `system_broadcasts` in code — single canonical table in TIK):

| Column | Purpose |
| ------ | ------- |
| `id` | PK |
| `body` | Text shown to users (e.g. “System Maintenance on 20-10-2026”) |
| `effective_from` | nullable; default now |
| `effective_until` | nullable; after this, hidden |
| `is_active` | boolean |
| `created_by` | FK users (admin) |
| `timestamps` | audit |

- **Admin APIs** (admin role only): `GET/POST /settings/system-notifications`, `PUT/PATCH` deactivate or update; list active for admin UI.
- **User read:** active rows where `is_active`, `effective_from <= now()`, and (`effective_until` null or `>= now()`); included in auth payload as **`system_notifications`** (not Laravel Notification class required for v1 — DB-backed broadcast).
- **Admin UI:** new card under **System Settings** or **Admin Console**: compose message, optional end date, **Publish to all users**; log `system_notification.created` (align with settings audit pattern).

**Files (expected):** migration, `SystemNotification` model, controller routes under admin settings, `AuthController` / `SsoService` / `AuthUserPayload`, `frontend/src/pages/Login.jsx` or `Layout.jsx` welcome host, `Settings.jsx` or `AdminConsole.jsx` for admin compose.

### 3.6 Out of scope

- mconvymes / ConvyMes integration changes.
- Inbox access rules or `Role::INBOX_AGENTS` definition changes.
- Document library RBAC or new categories.
- Products pagination, export, or bulk edit.
- Per-user targeted notifications (only **all users** in v1).
- Email/SMS/push delivery of system notifications (in-app login banner only).
- Reverb real-time push for system notifications (future CR).

## 4) Risk

| Dimension | Level | Summary |
| --------- | ----- | ------- |
| Technical | Low–Mid | Migrations (users login timestamps, system_notifications); auth payload changes. |
| Operational | Low–Mid | BE migrate + deploy before FE welcome/notifications; re-seed or migration for marketing channels. |
| Security | Low | Admin-only notification CRUD; channel create validated; search param sanitized. |
| Data | Low | Channel seed idempotent; notifications soft-deactivate via `is_active`. |

## 5) Definition of Done (immutable)

| ID | Target outcome | Verification type |
| -- | -------------- | ----------------- |
| CR-DOD-1 | Documents upload: Library, Category, Title, + drop zone, and Upload on one logical row (responsive wrap documented) | Manual smoke + FE build |
| CR-DOD-2 | Products: lens search on Name column; multi-word AND filter on name via API | PHPUnit feature test + manual smoke |
| CR-DOD-3 | Settings: General left / Inbox right / picklists below; General inputs aligned | Manual smoke (admin) |
| CR-DOD-4 | Inbox section: no inline explanation; help modal with specified copy | Manual smoke |
| CR-DOD-5 | No regression on document upload, products CRUD, settings save/modals | Local `npm run build`; optional targeted PHPUnit |
| CR-DOD-6 | Marketing: seeded/default channels available; **+ New Channel** creates row; campaign **Channel \*** usable | Manual smoke + optional API test |
| CR-DOD-7 | First login shows **Welcome {name}**; return login shows **Welcome back {name}**; active system text appended when configured | Manual smoke (new user + returning user) |
| CR-DOD-8 | Admin can publish system notification; all users see it on next login/`/auth/me` until expired/deactivated | PHPUnit + manual smoke |
| CR-DOD-9 | ghUpload incremental sync + deploy window recorded in TIK | TIK evidence |

## 6) Tests (local / CI only)

| Kind | Required? | Scope / notes |
| ---- | --------- | ------------- |
| Unit | Optional | Token parsing helper if extracted |
| Functional | Required | Products search; marketing channel create/list; system notification active scope; auth welcome_kind |
| Regression | Optional | Existing products-services, marketing campaign tests |
| Integration | Optional | Login → welcome payload smoke |

Evidence block (to be filled in TIK):

```text
Commands:
  cd backend && php artisan migrate --force   # local only
  cd backend && php artisan test --filter=ProductService
  cd backend && php artisan test --filter=Marketing
  cd backend && php artisan test --filter=SystemNotification
  cd frontend && npm run build
Results:
Where run: local / CI
```

## 7) Deploy / release summary

- **Backend first:** run migrations (`users.first_login_at` / `last_login_at`, `system_notifications`); deploy API; run channel seed on UAT/prod via `db:seed --class=...` only in approved window (TIK), not ad-hoc on prod without TIK.
- **Frontend:** `npm run build`; sync P:\ with `-IncludeDist -ReleaseAudit`.
- FE-only deploy is **insufficient** for CR-DOD-2, 6, 7, 8.

## 8) Rollback summary

- Revert FE commit / prior dist bundle on Render static host.
- Revert BE controller if search param causes issues; FE can fall back to client-side filter temporarily (TIK note only — not default).
- Deactivate system notifications via admin API or `is_active = false`; rollback migration only in emergency (TIK).

## 9) Closure loop — CR DoD ↔ TIK DoD

| CR-DOD | Proposed TIK-DOD |
| ------ | ---------------- |
| CR-DOD-1,3,4,5 | TIK141026-021-fe-docs-products-settings-ui |
| CR-DOD-2,6,8 (BE) | TIK141026-022-be-products-marketing-notifications |
| CR-DOD-6,7,8 (FE) | TIK141026-023-fe-marketing-welcome-notifications |
| CR-DOD-9 | Deploy evidence in TIK + INDEX |

## 10) Guardian approval

Standard deploy window for migrations + prod seed of marketing channels; no external contract change. Follow Render rollback notes in TIK if auth payload shape affects mobile clients (none today).
