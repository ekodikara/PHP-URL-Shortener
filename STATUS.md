# Snip — Session Status / Handoff

_Last updated: 2026-06-09. Read `CLAUDE.md` first for architecture & conventions._

## TL;DR
The full "Snip" app (accounts, plans, custom names, QR, click stats, glassmorphism
UI) is **built and verified end-to-end** in Docker. Everything works. The work is
**not yet committed** to git.

## Where we left off
- Branch: `master`. **18 uncommitted changes** in the working tree (5 modified,
  13 new). Nothing has been committed yet.
- Docker stack was left **running** (`web` + `db`) at http://localhost:8088.
  Tomorrow: `docker compose up -d --build` if it's down; `docker compose ps` to check.
- Live DB volume has a test account: `alice@example.com` / `supersecret1` (Premium,
  ~11 sample links). Lost only if you `docker compose down -v`.

## Done & verified ✅
- Security hardening of the original shortener (PDO prepared statements, CSRF,
  http(s)-only redirects, removed `mysql_*` + `magic_quotes`, env-based creds).
- Rewrite into multi-page app: landing, register, login, logout, dashboard, upgrade.
- Auth (bcrypt, session regeneration, hardened cookies).
- Quota engine: free 10/mo + 1000 visits/link, pro 100/mo unlimited visits,
  premium unlimited + 100 custom names. Verified the free cutoff fires at exactly 10.
- Custom slugs gated to Premium; reserved words + duplicates rejected.
- Dashboard: usage meter, AJAX shorten, per-row QR, copy, click counts, delete.
- Docker (PHP 8.3/Apache + MySQL 8), `.htaccess` routing + `/inc` & `*.sql` blocked.
- Verified via curl AND browser screenshots (landing + dashboard render correctly).
- Documentation: `CLAUDE.md` (architecture) + memory entry written.

## Next up (pick any) ⏭️
1. **Commit the work** — branch off master, commit the rewrite + docs, optionally open a PR.
   (User's repo flow: branch first, commit only when asked.)
2. **Real billing** — replace the demo plan-switch in `upgrade.php` with Stripe
   Checkout + a verified webhook before mutating `users.plan`.
3. **Vendor qrcodejs locally** — currently loaded from cdnjs (needs internet); make offline-safe.
4. **Auth hardening** — password reset, email verification, login rate limiting.
5. **Cleanup** — remove unused legacy files (`README`, `index.html`, `shortenedurls.sql`).
6. **Production** — TLS/HTTPS, set `SITE_HOST`, real secrets (not the demo passwords in compose).

## Useful commands
```bash
docker compose up -d --build     # start
docker compose ps                # status
docker compose logs -f web       # tail web logs
docker compose down              # stop (keeps DB)
docker compose down -v           # stop + wipe DB (forces schema.sql reload)
php -l <file>                    # lint a PHP file
```
