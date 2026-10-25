# ReadMind

Feel free to deploy this on your own website, etc.

**This is a personal project to have some fun with claude code, which generated essentially all of the code, and the rest of this readme:**

A bookshelf for every CalMind account, at
[seancheren.com/ReadMind](https://seancheren.com/ReadMind). Plain PHP, no
framework, no build step. Anyone with a
[CalMind](https://github.com/chere005/CalMind) account can sign in, and the
sign-in is CalMind's: the password goes to CalMind's API and this repo keeps
only the user name, never a credential. Books are found through the Open
Library API, each book takes a rich-text note, and everything is stored as
encrypted JSON on disk, one file per user per kind (books, notes,
preferences).

## Run and test

```sh
php -S 127.0.0.1:8795 -t public     # the app; sign-in needs a reachable CalMind
php tools/test.php                  # lint, store round-trip, mocked CalMind login
```

The host has three instances, prod, test and dev, each with its own session
cookie; `./deploy.sh` ships test by default and `./deploy.sh all` ships all
three. `sh tools/dtp.sh` is the release lane (deploy, tag, push; `--full` runs
the test first), and the tag is the version.

## More

[ARCHITECTURE.md](ARCHITECTURE.md) is the long version: the tree, the login,
the data model, the three instances and how they deploy, the release lanes,
and why each is the way it is. [AGENTS.md](AGENTS.md) is the rules for agents
working here. BSD 3-Clause; see [LICENSE](LICENSE).
