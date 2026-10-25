# ReadMind

Feel free to deploy this on your own website, etc.

**This is a personal project to have some fun with claude code, which generated essentially all of the code, and the rest of this readme:**

A bookshelf with notes for CalMind accounts. Plain PHP, no build step. Live at [seancheren.com/ReadMind](https://seancheren.com/ReadMind).

- Sign in with your [CalMind](https://github.com/chere005/CalMind) account. Nothing stored here but your username.
- Books come from Open Library. One rich-text note per book.
- Data is encrypted JSON on disk, one file per user per kind.

## Run

```sh
php -S 127.0.0.1:8795 -t public
php tools/test.php
```

Deploy: `./deploy.sh` (test) or `./deploy.sh all`. Release: `sh tools/dtp.sh`.

Docs: [ARCHITECTURE](ARCHITECTURE.md) · [AGENTS](AGENTS.md). BSD 3-Clause.
