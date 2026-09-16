# Deploying to SiteGround shared hosting

This guide assumes a SiteGround shared plan (StartUp, GrowBig or GoGeek) managed through **Site Tools**, with the app on its own domain or subdomain, e.g. `leads.example.com`.

Paths below use SiteGround's layout:

```
/home/customer/www/leads.example.com/
├── public_html/     ← web root (fixed by SiteGround). Gets the contents of public/
└── gscraper/        ← the application. Outside the web root, never served
```

Replace `leads.example.com` with your domain everywhere.

---

## 0. Before you start (on your PC)

```bash
composer install
npm ci
npm run build
php artisan test
composer package
```

The result is `dist/gscraper-deploy-<timestamp>.zip`, which contains `gscraper/` and `public_html/`.

---

## 1. Create the MySQL database

Site Tools → **Site → MySQL → Databases** → **Create Database**. SiteGround generates a prefixed name such as `dbabc123xyz`. Note it.

## 2. Create the database user

Site Tools → **Site → MySQL → Users** → **Create User**. Use the generated password or a long random one. Note the username (e.g. `uabc123xyz`) and password.

## 3. Assign database permissions

In the **Users** list, open the user's ⋮ menu → **Add New Database** (or, on the database, **Manage Access**) → pick the database → **All Privileges** → Confirm.

The app needs SELECT, INSERT, UPDATE, DELETE, CREATE, ALTER, INDEX, DROP and REFERENCES. "All Privileges" covers these.

## 4. Upload the project

**Option A: File Manager (no SSH needed for this step)**

1. Site Tools → **Site → File Manager** → open `www/leads.example.com/`.
2. Upload the zip there (not inside `public_html`).
3. Right-click the zip → **Extract**. You now have `gscraper/` and a second `public_html/` folder inside the extraction.
4. If `public_html/` already has SiteGround's default files (`index.html`, `default.html`), delete them. Move the extracted `public_html/*` contents into the real `public_html/`, **including the hidden `.htaccess`**. In File Manager's settings, enable "Show hidden files".
5. Delete the zip.

**Option B: SSH / SFTP**

Enable SSH: Site Tools → **Devs → SSH Keys Manager** → create or import a key. The connection details (host, username, port **18765**) are shown there.

```bash
scp -P 18765 dist/gscraper-deploy-*.zip u123-abc@ssh.leads.example.com:~/www/leads.example.com/
ssh -p 18765 u123-abc@ssh.leads.example.com
cd ~/www/leads.example.com
unzip -o gscraper-deploy-*.zip
rm -f public_html/index.html public_html/default.html
rm gscraper-deploy-*.zip
```

`unzip` merges the zip's `public_html/` into the existing one.

After uploading, `public_html/index.php` must contain `__DIR__.'/../gscraper/vendor/autoload.php'`. The package script writes this for you.

## 5. Configure the document root

For a primary domain, SiteGround's web root is always `public_html`, and the split layout above already fits it. **Nothing to change.**

For a subdomain: Site Tools → **Domain → Subdomains** → create `leads.example.com`. SiteGround creates `www/leads.example.com/public_html`. Use that folder and put `gscraper/` next to it, as above.

> **Don't** upload the whole project into `public_html/`. That would put `.env`, `storage/` and the source code inside the web root. As a safety net, `gscraper/.htaccess` denies all HTTP access, but don't rely on it.

Enable HTTPS now: Site Tools → **Security → SSL Manager** → install **Let's Encrypt** for the domain, then **Security → HTTPS Enforce** → On.

## 6. Configure PHP

Site Tools → **Devs → PHP Manager**:

- **PHP Version:** choose **8.3** (or 8.4) under "Change PHP version" → Manual. Laravel 13 needs PHP 8.3 or newer.
- **PHP Variables** (set if they're lower):
  - `memory_limit` = `256M`
  - `max_execution_time` = `60`
  - `upload_max_filesize` = `25M` and `post_max_size` = `30M` (legacy imports up to 20 MB)
- **PHP Extensions:** pdo_mysql, mbstring, openssl, fileinfo, zip and curl are enabled by default. Confirm fileinfo is on, because upload validation uses it.

Check over SSH that the CLI uses the same version:

```bash
php -v
```

If it prints an older version, use the versioned binary instead of `php` in every command and in the cron job. `ls /usr/local/bin | grep php` lists them, e.g. `/usr/local/bin/php83`.

## 7. Configure `.env`

```bash
cd ~/www/leads.example.com/gscraper
cp .env.example .env
nano .env
```

Set at least:

```ini
APP_ENV=production
APP_DEBUG=false
APP_URL=https://leads.example.com
APP_DISPLAY_TIMEZONE=Africa/Cairo
# Language a new visitor sees: en or ar. Each person can switch it in the top bar.
APP_LOCALE=ar

DB_CONNECTION=mysql
DB_HOST=localhost
DB_PORT=3306
DB_DATABASE=dbabc123xyz
DB_USERNAME=uabc123xyz
DB_PASSWORD=the-password-from-step-2

MAIL_MAILER=smtp
MAIL_SCHEME=smtps
MAIL_HOST=mail.leads.example.com
MAIL_PORT=465
MAIL_USERNAME=no-reply@leads.example.com
MAIL_PASSWORD=mailbox-password
MAIL_FROM_ADDRESS="no-reply@leads.example.com"

FORCE_HTTPS=true
SECURITY_HSTS=true
SESSION_SECURE_COOKIE=true

# Shared daily scraping budget per internet connection (Google limits by IP)
SCRAPE_DAILY_PER_CONNECTION=300
SCRAPE_PER_RUN=100

# Default for "phone calls count as a way to reach a lead" on the search form.
# true for a team calling businesses in its own country.
PHONE_IS_CHANNEL=false
```

Create the mailbox in Site Tools → **Email → Accounts**. The exact SMTP host and port are under the account's ⋮ → **Mail Configuration**.

Generate the encryption key (it writes into `.env`), then lock the file down:

```bash
php artisan key:generate --force
chmod 600 .env
```

**Keep a copy of `APP_KEY` somewhere safe.** Losing it logs everyone out and makes encrypted sessions unreadable.

Without SSH, generate a key locally with `php artisan key:generate --show` and paste it into `.env` with File Manager.

## 8. Install Composer dependencies (only if needed)

The package already contains `vendor/` built with `--no-dev`, so **skip this step** normally.

If you uploaded the source without `vendor/` (e.g. deployed with git), SiteGround has Composer available over SSH:

```bash
cd ~/www/leads.example.com/gscraper
composer install --no-dev --optimize-autoloader --no-interaction
```

## 9. Run migrations

```bash
cd ~/www/leads.example.com/gscraper
php artisan migrate --force
```

This creates every table with utf8mb4 and InnoDB foreign keys. To check the connection first: `php artisan db:show`.

Create the first admin account:

```bash
php artisan app:create-admin you@example.com --name="Your Name"
```

(No SSH? Set `INITIAL_ADMIN_EMAIL` and `INITIAL_ADMIN_PASSWORD` in `.env`, run `php artisan db:seed --force` from a one-off cron job, then delete both lines from `.env`.)

## 10. Configure storage

The app stores nothing publicly: uploads are parsed in PHP's temp directory and discarded. Therefore **`php artisan storage:link` is not needed, so don't run it.**

The package already contains the required empty directories. If any is missing:

```bash
cd ~/www/leads.example.com/gscraper
mkdir -p storage/logs storage/framework/{cache/data,sessions,views} storage/app/private bootstrap/cache
```

## 11. Set file permissions

On SiteGround, PHP runs as your own user, so standard permissions are enough:

```bash
cd ~/www/leads.example.com
find gscraper public_html -type d -exec chmod 755 {} \;
find gscraper public_html -type f -exec chmod 644 {} \;
chmod 600 gscraper/.env
chmod -R u+rwX gscraper/storage gscraper/bootstrap/cache
```

| Path | Needs |
|---|---|
| `gscraper/storage/` (recursive) | writable by PHP |
| `gscraper/bootstrap/cache/` | writable by PHP |
| `gscraper/.env` | readable by PHP only (600) |
| everything else | read-only (644 files / 755 dirs) |

Never use 777.

## 12. Configure the cron job

Site Tools → **Devs → Cron Jobs** → **Create**:

- **Command:**
  ```
  php /home/customer/www/leads.example.com/gscraper/artisan schedule:run >> /dev/null 2>&1
  ```
  Use the versioned PHP binary from step 6 if `php -v` showed an older version.
- **Interval:** every minute (`* * * * *`).

If your plan doesn't allow a one-minute interval, choose the shortest one it offers. The app still works; stale runs are just marked failed later.

What the scheduler runs, all short-lived:

| Task | When |
|---|---|
| `runs:fail-stale` | every minute: fails runs whose worker has been silent for `WORKER_STALE_MINUTES` |
| `queue:work --stop-when-empty --max-time=50` | every minute: drains the database queue, then exits |
| `runs:prune-logs` | daily 03:15: deletes run log lines older than `RUN_LOG_RETENTION_DAYS` |
| `auth:clear-resets` | daily 03:30: removes expired password-reset tokens |

Check it's registered: `php artisan schedule:list`.

## 13. Clear and rebuild caches

Run after every deployment and every `.env` change:

```bash
cd ~/www/leads.example.com/gscraper
php artisan optimize:clear
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan event:cache
```

Turn off SiteGround's page cache for this app: Site Tools → **Speed → Caching → Dynamic Cache** → switch it off for the domain, or add an exclusion for `/`. Every page is private and live, and cached HTML would show stale progress. Memcached isn't used.

## 14. Test the application

1. Open `https://leads.example.com/up`. It should say the application is up.
2. Open `https://leads.example.com`. You should be redirected to `/login`, with CSS loaded.
3. Sign in with the admin from step 9.
4. **Workers** → create a token → set up the worker on your PC ([WORKER_SETUP.md](WORKER_SETUP.md)). Within ~15 s it shows **Online**.
5. **Find leads** → queue a small search (1 search, 3 results). Watch the live log, then check results and download the CSVs.
6. **Forgot password** from a private window. The email should arrive.
7. `https://leads.example.com/.env` and `https://leads.example.com/../gscraper/.env` must both return 403 or 404.
8. Check `gscraper/storage/logs/` for errors.

The full checklist is in [TESTING.md](TESTING.md#production-deployment).

## 15. Troubleshooting

### 500 Internal Server Error

- Read the real error: `tail -n 50 ~/www/leads.example.com/gscraper/storage/logs/laravel-$(date +%F).log`. If there's no log at all, `storage/` isn't writable (step 11), or the error happened before Laravel booted: check Site Tools → **Statistics → Error Log**.
- `No application encryption key`: run step 7's `key:generate`, then `config:cache`.
- `Class not found` / `vendor/autoload.php` missing: `vendor/` wasn't uploaded, or `index.php` points to the wrong folder. It must reference `../gscraper/`.
- `SQLSTATE[HY000] [1045] Access denied`: wrong DB user or password, or the user wasn't granted access to the database (step 3). After fixing `.env`, run `php artisan config:cache`.
- `SQLSTATE[HY000] [2002]`: `DB_HOST` must be `localhost`.
- A PHP version error ("requires php >= 8.3"): set PHP 8.3+ in PHP Manager (step 6).
- `.htaccess` syntax error: restore `public_html/.htaccess` from the package.
- Changed `.env` but nothing happened: config is cached. Run `php artisan config:cache` again.

### 403 Forbidden

- On every page: `public_html/index.php` is missing, or permissions are wrong. Files must be 644 and folders 755.
- Only on some requests: SiteGround's security layer may be blocking them. Check Site Tools → **Security → Blocked Traffic** and **Site Tools → Statistics → Error Log**.
- In the app ("Not allowed"): the signed-in user lacks permission. Admin pages need the Admin role.
- The worker gets 403: unlikely from the app itself (the app answers 401 for bad tokens). It's usually SiteGround's bot protection. Allow the worker PC's IP, or contact support mentioning API requests with `User-Agent: GScraperWorker`.

### 404 Not Found

- Every page except the home page returns 404: `public_html/.htaccess` is missing (hidden files weren't uploaded) or not being read. Upload it again.
- `/build/assets/app-….css` returns 404 (unstyled page): `public_html/build/` wasn't uploaded, or you deployed an old `manifest.json`. Run `npm run build` and re-package.
- `/runs/123` returns 404: the run was deleted.

### 419 Page Expired

- The session expired (`SESSION_LIFETIME`, default 120 minutes). Refresh and try again.
- It happens immediately after signing in: `SESSION_SECURE_COOKIE=true` while the site is on `http://`. Enable HTTPS (step 5), or temporarily set it to `false`.
- `APP_URL` doesn't match the domain you're using (www vs non-www).

### 429 Too Many Requests

The login limit is 5 attempts per minute per email+IP. Wait a minute.

### Redirect loop, or links to http:// on an https site

Keep `FORCE_HTTPS=true`. If the loop continues, set `TRUSTED_PROXIES=*` and run `php artisan config:cache`.

### Emails don't arrive

Test with `php artisan tinker --execute="Mail::raw('test', fn(\$m) => \$m->to('you@example.com')->subject('GScraper test'));"`. Check `MAIL_*` values against the mailbox's Mail Configuration, and check the log for SMTP errors.

### Worker shows "Offline" or runs stay "Queued"

See [WORKER_SETUP.md → Troubleshooting](WORKER_SETUP.md#troubleshooting). A queued **search** also waits while the worker is cooling down or at its daily limit; the dashboard shows a countdown.

---

## Updating to a new version

```bash
# on your PC
npm run build && php artisan test && composer package
# upload + extract the new zip over the old files (step 4), keeping gscraper/.env, then:
cd ~/www/leads.example.com/gscraper
php artisan down
php artisan migrate --force
php artisan optimize:clear && php artisan config:cache && php artisan route:cache && php artisan view:cache && php artisan event:cache
php artisan up
```

Back up the database first: Site Tools → **Security → Backups**, or `mysqldump`. To roll back, restore the previous zip and the database backup.

---

## Final deployment checklist

**On your PC, before uploading**

- [ ] `php artisan test` passes (and `cd worker && npm test`)
- [ ] `npm run build` ran after the last CSS/JS/Blade change, and `public/build/manifest.json` exists
- [ ] `composer package` produced a new zip in `dist/`
- [ ] The zip contains no `.env`, `node_modules/`, `tests/` or `worker/` (the script excludes them)
- [ ] No secrets committed to git (`git status` doesn't show `.env`)

**SiteGround**

- [ ] Database and user created, user has All Privileges on the database
- [ ] PHP 8.3+ selected, and `php -v` over SSH matches (or you know the versioned binary)
- [ ] `upload_max_filesize` ≥ 25M, `post_max_size` ≥ 30M, `memory_limit` ≥ 256M
- [ ] `gscraper/` is **next to** `public_html/`, not inside it
- [ ] `public_html/.htaccess` and `public_html/build/` uploaded
- [ ] SSL certificate installed, HTTPS Enforce on
- [ ] Dynamic Cache off (or excluded) for the app

**Configuration**

- [ ] `.env`: `APP_ENV=production`, `APP_DEBUG=false`, correct `APP_URL` with https
- [ ] `APP_KEY` generated and backed up somewhere safe
- [ ] DB credentials correct (`php artisan db:show` works)
- [ ] Mail settings tested (password reset email arrives)
- [ ] `FORCE_HTTPS=true`, `SESSION_SECURE_COOKIE=true`, `SECURITY_HSTS=true`
- [ ] `INITIAL_ADMIN_*` lines empty or removed
- [ ] `chmod 600 .env`, storage and bootstrap/cache writable

**Commands run on the server**

- [ ] `php artisan migrate --force`
- [ ] `php artisan app:create-admin …`
- [ ] `config:cache`, `route:cache`, `view:cache`, `event:cache`
- [ ] Cron job created, and `php artisan schedule:list` shows the tasks

**Smoke test**

- [ ] `/up` responds; `/` redirects to `/login` with styles
- [ ] `/.env` is not downloadable
- [ ] Admin can sign in, create a user and create a worker token
- [ ] Worker comes online and a small search completes end to end
- [ ] CSV downloads open correctly in Excel/Sheets
- [ ] The language button switches the interface and survives signing out
- [ ] **How to use** opens in both languages
- [ ] `storage/logs` has no new errors
