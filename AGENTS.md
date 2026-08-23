# Working on ReadMind

The baseline for all of Sean's repos lives in ~/GIT/AgentSuite/AGENTS.md
and is imported here; this file holds only what is true of THIS repo.
@../AgentSuite/AGENTS.md

The bookshelf, for every CalMind account — cloned from seancheren-site's
akisbookshelf on 2026-08-23 (Sean: "make a clone of akisbookshelf that uses
calmind logins and call the new repo ReadMind"). Web only, deliberately: one
`public/index.php` renders everything, `lib/app.php` is the whole lib.

## Standing rules

- **This repo holds no accounts.** A login is proven by POSTing CalMind's own
  `login` action and keeping only the verdict — no password is stored, hashed
  or seen here, and the token is dropped at the door. Password work belongs in
  CalMind; the day core provides auth for all apps, this file is where the
  switch happens.
- **Each instance signs in against its own CalMind** (prod → seancheren.com,
  test → test., dev → dev.), so a sandbox login can never be a production one.
- **The clone owes nothing to its parent.** akisbookshelf stays aki's, on the
  site's login, untouched; a fix worth both places is copied by hand and said
  so in the commit. The two-press delete + rich-text helpers were copied from
  the site's lib on day one — if they rot apart, that is drift worth a look.

## Run & test

    php -S 127.0.0.1:8795 -t public     # the app; sign-in needs a reachable CalMind
    php tools/test.php                  # lint, store round-trip, and the login
                                        # conversation against a MOCK CalMind

## Deploy

`./deploy.sh` (test) / `prod` / `all` — one-way rsync onto the seancheren.com
host, subpath `/ReadMind` per instance, data in `readmind-data*` never synced.
`npm`-free, build-free. `tools/dtp.sh` / `tdtp.sh` are the release lanes; the
tag is the version (no version file).
