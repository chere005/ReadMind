# ReadMind

Feel free to deploy this on your own website, etc.

**This is a personal project to have some fun with claude code, which generated essentially all of the code, and the rest of this readme:**

A bookshelf for every CalMind account, at
[seancheren.com/ReadMind](https://seancheren.com/ReadMind) — plain PHP, no
framework, no build step. Anyone with a CalMind account may sign in, and the
sign-in IS CalMind's: this repo stores no credentials at all. Books come from
the Open Library API, notes are per book and rich-text, data is encrypted JSON
on disk, one file per user per kind.

**This is a personal project to have some fun with claude code, which
generated essentially all of the code, and the rest of this readme.**

## Run & test

```sh
php -S 127.0.0.1:8795 -t public     # the app; sign-in needs a reachable CalMind
php tools/test.php                  # lint, store round-trip, mocked CalMind login
```

## More

The long version — the tree, the login, the data model, the three instances and
how they deploy, the release lanes, and why each is the way it is — lives in
[ARCHITECTURE.md](ARCHITECTURE.md). Agents working here read
[AGENTS.md](AGENTS.md) for the rules.

## License

BSD 3-Clause — see [LICENSE](LICENSE).
