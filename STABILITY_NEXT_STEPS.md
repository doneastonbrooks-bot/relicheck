# Stability & Safety — remaining work

The site is in a much better place than this morning (see "What's already in place" below). These are the three items that still need your decision before I can finish them.

## What's already in place

- ✅ Git version control, pushed to private GitHub repo
- ✅ `_config.php` validator on the server — bad credentials fail loudly, never silently
- ✅ Regression test fixtures for `dei.xlsx` and the test-retest dataset (all 33 stat values frozen in JSON)
- ✅ Pre-commit hook that runs JS syntax check + regression tests before every `git commit`
- ✅ Dropbox version history is your existing file-level backup (30 days on Plus, longer on Pro)
- ✅ Auto-upload (every 15s) means deploy step is automated; pre-commit hook is the brake before changes hit production

## What's NOT in place yet

### #3 — Automated database backup
**Problem:** Dropbox covers `_config.php`. Nothing yet covers the database. If a query corrupted the `users` or response tables tomorrow, there's no point-in-time recovery.

**Three paths, ranked by effort:**

1. **Easiest** — turn on Ionos's built-in database backup
   - In Ionos control panel → Databases → your DB → Backup
   - Usually daily snapshots, retained 14–30 days
   - Free with most Ionos plans
   - No code to write

2. **Medium** — a `launchd` job on your Mac that nightly runs `mysqldump` over SSH to a local backup folder
   - Need: SFTP credentials (which you have in FileZilla) + DB password (which is in `_config.php`)
   - Backups land in `/Users/don/backups/relicheck/relicheck-YYYY-MM-DD.sql.gz`
   - Dropbox would sync these for off-site redundancy
   - ~30 lines of bash + a `.plist` file

3. **Robust** — a managed external backup service (e.g. SimpleBackups, BackupSheep) pointing at Ionos
   - Costs ~$5–10/month
   - Most reliable but adds a vendor

**Recommendation:** start with #1 (Ionos built-in). Add #2 only if you find Ionos's retention too short.

### #4 — Staging environment
**Problem:** With auto-upload-every-15s, every save you make goes to production. There's no buffer to catch a broken UI before users see it. The pre-commit hook helps for *git* commits, but the auto-upload doesn't go through git.

**The cleanest fix:** point a staging subdomain at the `/v2/` folder that already exists on Ionos.

1. In Ionos control panel → Domains → `relichecksurvey.com` → Subdomains → Add `staging.relichecksurvey.com`
2. Point it at the directory `/v2/` (or whatever path holds a sibling copy of the site)
3. Change the auto-upload so it goes to `/v2/` instead of `/relicheck/` while you're developing
4. When ready to release, copy `/v2/*` → `/relicheck/*` on the server

I can help with steps 3 and 4 once the subdomain exists.

Alternative if Ionos subdomains are a hassle: install PHP 8 locally + MAMP/Laravel Valet, run the whole stack on `localhost:8000`. Costs a bit of setup but cuts the "save → production" loop entirely.

### #5 — Safe deploy script
**Status:** Effectively addressed by the combination of (a) the auto-upload you already had and (b) the new pre-commit hook. The hook catches broken code at `git commit` time; the auto-upload then ships it.

**Remaining gap:** the auto-upload picks up *any* save in the folder, even if you haven't committed yet. To make that safer:
- Keep working files **outside** the auto-uploaded folder until you're ready to deploy, then move them in
- Or change the auto-upload trigger from "any save" to "only after git commit succeeds"

If the staging environment lands first, this concern mostly goes away.

## Tomorrow's checklist (if you want one)

1. Turn on Ionos DB backups (5 minutes in their control panel)
2. Create `staging.relichecksurvey.com` subdomain pointed at `/v2/`
3. Drop your Anthropic API key into `_config.php` to unlock the AI panels across the site

Tell me when any of those are done and I'll wire up the code side.
