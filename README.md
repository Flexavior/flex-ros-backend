# MSS-CRM — Backend

Laravel 12 **JSON API** for MSS-CRM: sales pipeline, customers, marketing, omnichannel inbox (ConvyMes + Outlook), Microsoft 365 delegated mail, and role-based access.

**API base URL:** `/api/v1`  
**Health check:** `GET /up`

See also: [project root README](../README.md) · [frontend README](../frontend/README.md)

---

## Overview

### What this service does

| Area | Description |
|------|-------------|
| **Auth** | Laravel Sanctum bearer tokens (`POST /auth/login`, `GET /auth/me`) |
| **CRM core** | Leads, customers, appointments, products, agreements, launch plans, marketing |
| **Inbox** | Mirrors ConvyMes (Facebook / Viber / LINE) into MySQL; claim, assign, reply, audit |
| **ConvyMes integration** | `ConvymesClient` pull/sync; HMAC webhook `POST /webhooks/convymes` |
| **Microsoft 365** | Delegated OAuth per user; Graph `sendMail` from leads/customers; Outlook inbox ingest |
| **Realtime UI** | `InboxUpdated` broadcasts via **Laravel Reverb** (optional) |
| **Teams (internal)** | Optional incoming webhook alerts — not customer-facing Teams in inbox |

### How it fits in the stack

```
React SPA (frontend)  ──HTTP Bearer──►  Laravel API (this app)  ──►  MySQL
                                              │
                    ConvyMes ◄── REST / webhook ──┤
                    Microsoft Graph ◄── OAuth ──┘
                    Reverb ◄── WebSocket push ──► browsers (via frontend Echo)
```

### Key directories

```
app/
├── Domain/Crm/           Scope, checklists, lead conversion, dashboard
├── Domain/Inbox/         ConvyMes client, ingest, conversation flow, Reverb notify
├── Domain/Microsoft/     OAuth, Graph mail, subscriptions, Teams notifier
├── Http/Controllers/Api/V1/
├── Models/
config/
├── microsoft.php         Azure / Graph settings
├── services.php          ConvyMes + third-party
database/migrations/
routes/api.php            All /api/v1 routes
```

---

## Prerequisites

| Requirement | Version / notes |
|-------------|-----------------|
| **PHP** | 8.3+ with extensions: `openssl`, `pdo_mysql`, `mbstring`, `tokenizer`, `xml`, `ctype`, `json`, `bcmath` |
| **Composer** | 2.x |
| **MySQL** | 8.x — databases `mss_crm` (dev) and `mss_crm_test` (PHPUnit) |
| **Node.js** | Not required to *run* the API; needed only if you build the frontend on the same machine |

### Optional integrations (configure when needed)

| Integration | Prerequisites |
|-------------|----------------|
| **ConvyMes** | Gateway running; `CONVYMES_*` in `.env` |
| **Reverb (inbox live updates)** | `BROADCAST_CONNECTION=reverb`; run `php artisan reverb:start` |
| **Microsoft 365 Business Basic** | Entra ID app; `AZURE_*`; public HTTPS for Graph webhooks in production |
| **Queue worker** | Recommended in production if using queued jobs (`QUEUE_CONNECTION=database`) |

---

## Initial setup (local development)

### 1. Install dependencies

```powershell
cd C:\laragon\www\mss-crm\backend
composer install
```

If `laravel/reverb` or `pusher/pusher-php-server` fail to install (network), run `composer install` again when Packagist is reachable.

### 2. Environment

```powershell
copy .env.example .env
php artisan key:generate
```

Edit `.env` — minimum for local CRM:

```env
APP_URL=http://127.0.0.1:8000
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=mss_crm
DB_USERNAME=root
DB_PASSWORD=
```

### 3. Database

```powershell
mysql -u root -e "CREATE DATABASE IF NOT EXISTS mss_crm; CREATE DATABASE IF NOT EXISTS mss_crm_test;"
php artisan migrate
php artisan db:seed
```

Seeded logins (password `password`): `sales@mss.test`, `cs@mss.test`, `admin@mss.test`, etc. — see root README.

### 4. Run the API

```powershell
php artisan serve
```

API available at `http://127.0.0.1:8000/api/v1`.

### 5. Optional: ConvyMes inbox

```env
CONVYMES_ENABLED=true
CONVYMES_BASE_URL=http://localhost:3000
CONVYMES_EMAIL=admin@convymes.local
CONVYMES_PASSWORD=admin123
CONVYMES_WEBHOOK_SECRET=your-shared-secret
```

```powershell
php artisan inbox:sync --messages
```

### 6. Optional: Reverb (live inbox)

From `.env.example`, set `BROADCAST_CONNECTION=reverb` and `REVERB_*`. In a second terminal:

```powershell
php artisan reverb:start
```

Set matching `VITE_REVERB_*` in [frontend `.env.local`](../frontend/.env.example).

### 7. Optional: Microsoft 365

```env
AZURE_TENANT_ID=
AZURE_CLIENT_ID=
AZURE_CLIENT_SECRET=
AZURE_REDIRECT_URI=http://127.0.0.1:8000/api/v1/integrations/microsoft/callback
FRONTEND_URL=http://localhost:5173
MICROSOFT_TEAMS_WEBHOOK_URL=
```

Entra ID: delegated permissions `User.Read`, `Mail.Send`, `Mail.Read`, `offline_access` + admin consent.

Renew Graph mail subscriptions (cron daily):

```powershell
php artisan microsoft:renew-subscriptions
```

### 8. Tests

```powershell
php artisan test
```

Uses `mss_crm_test` from `phpunit.xml` with `RefreshDatabase`.

### 9. Smoke test (HTTP)

With `php artisan serve` running:

```powershell
powershell -ExecutionPolicy Bypass -File .\smoke-test.ps1
```

---

## Deployment guide (production)

### 1. Server

- Linux or Windows Server with **PHP 8.3-FPM** (or IIS + FastCGI), **Nginx/Apache**, **MySQL 8**.
- TLS certificate on public hostname.

### 2. Deploy code

```bash
composer install --no-dev --optimize-autoloader
cp .env.example .env   # then edit production values
php artisan key:generate
php artisan migrate --force
php artisan db:seed --force   # or run seeders once in UAT only
php artisan config:cache
php artisan route:cache
```

Set `APP_ENV=production`, `APP_DEBUG=false`, strong `APP_KEY`, real DB credentials.

### 3. Web server

- Document root should **not** expose `storage/` directly; use Laravel’s `public/` as the web root **or** serve API-only behind reverse proxy.
- Typical pattern: Nginx `root` → `backend/public`; try files → `index.php`.

If the **React build** is served separately, API stays on `api.yourdomain.com` with CORS or same-site proxy. If SPA is co-located, copy `frontend/dist` assets as your ops team prefers.

### 4. Processes (supervisor / systemd)

| Process | Command | Purpose |
|---------|---------|---------|
| PHP-FPM | (package default) | HTTP |
| Queue worker | `php artisan queue:work --sleep=3 --tries=3` | Jobs |
| Reverb | `php artisan reverb:start` | Inbox WebSocket |
| Scheduler | `* * * * * php /path/to/artisan schedule:run` | Subscriptions, cron |

Register in `routes/console.php` if you schedule `microsoft:renew-subscriptions` and `inbox:sync`.

### 5. Integration URLs (production)

| Endpoint | Must be public HTTPS |
|----------|----------------------|
| `POST /api/v1/webhooks/convymes` | Yes (from ConvyMes) |
| `GET/POST /api/v1/webhooks/microsoft/graph` | Yes (Microsoft Graph) |
| `GET /api/v1/integrations/microsoft/callback` | Yes (OAuth redirect) |

Set `FRONTEND_URL` to your SPA origin for OAuth return redirects.

### 6. Security checklist

- Never commit `.env` or `APP_KEY`.
- Rotate `CONVYMES_WEBHOOK_SECRET`, Azure client secret, and Sanctum tokens policy on schedule.
- Encrypt Microsoft refresh tokens (enabled via model casts).
- Restrict admin routes (`settings`) to `admin` role only (already enforced in frontend + API).

---

## Troubleshooting

| Symptom | Fix |
|---------|-----|
| `No application encryption key` | `php artisan key:generate` |
| `Unknown database 'mss_crm'` | Create DB; check `.env` DB_* |
| Inbox empty | ConvyMes up, `CONVYMES_ENABLED=true`, `inbox:sync --messages` |
| Webhook 401 (ConvyMes) | Match `CONVYMES_WEBHOOK_SECRET` and ConvyMes `CRM_WEBHOOK_SECRET` |
| Microsoft send fails | User must connect OAuth; check Azure app redirect URI |
| Inbox not live | Start Reverb; `BROADCAST_CONNECTION=reverb`; frontend `VITE_REVERB_*` |
| Broadcast errors | `composer install` (reverb + pusher-php-server) |

---

## Main API routes (reference)

```
POST   /api/v1/auth/login
GET    /api/v1/auth/me
GET    /api/v1/dashboard/metrics
CRUD   /api/v1/leads, /customers, /products-services, …
GET    /api/v1/inbox/conversations   (inbox.agent middleware)
POST   /api/v1/webhooks/convymes
GET    /api/v1/integrations/microsoft/callback
POST   /api/v1/leads/{id}/email
GET    /api/v1/integrations/microsoft/status
POST   /api/v1/broadcasting/auth     (Sanctum — for Echo)
```

Full list: [`routes/api.php`](routes/api.php).
