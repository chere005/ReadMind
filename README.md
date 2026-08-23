# BookMind

A bookshelf for every CalMind account, at
[seancheren.com/BookMind](https://seancheren.com/BookMind) — plain PHP, no
framework, no build step. Cloned from seancheren.com's private bookshelf and
different in exactly two ways: anyone with a CalMind account may sign in, and
the sign-in IS CalMind's — this repo stores no credentials at all.

Books come from the Open Library API, notes are per book and rich-text, data
is encrypted JSON on disk, one file per user.

**This is a personal project to have some fun with claude code, which
generated essentially all of the code, and the rest of this readme.**

## Run & test

```sh
php -S 127.0.0.1:8795 -t public
php tools/test.php
```

## License

BSD 3-Clause — see [LICENSE](LICENSE).
