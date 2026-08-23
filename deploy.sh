#!/usr/bin/env bash
# Deploy BookMind — one source tree onto three instances of the same host:
#
#   prod   /home/public/BookMind        lib -> /home/protected/bookmind-lib
#   test   /home/public/test/BookMind   lib -> /home/protected/bookmind-lib-test
#   dev    /home/public/dev/BookMind    lib -> /home/protected/bookmind-lib-dev
#
#   ./deploy.sh            -> TEST only (the safe default, suite-wide)
#   ./deploy.sh prod       -> production only
#   ./deploy.sh all        -> all three
#   ./deploy.sh --dry-run  -> say what would happen, change nothing
#
# The instance split rides seancheren-site's .htaccess: test./dev. subdomains
# rewrite into /test/ and /dev/, so BookMind only has to BE there. Data dirs
# (bookmind-data{,-test,-dev}) are created web-writable and never synced —
# same one-way, no-delete, no-secrets rules as every deploy in the suite.
set -euo pipefail
cd "$(dirname "$0")"

CONF="deploy.conf"
[[ -f $CONF ]] || { echo "no $CONF — copy deploy.conf.sample and set HOST" >&2; exit 1; }
# shellcheck disable=SC1090
source "$CONF"
[[ -n ${HOST:-} ]] || { echo "$CONF sets no HOST" >&2; exit 1; }

MODE="test"; DRY=""
for a in "$@"; do
  case "$a" in
    test|prod|all) MODE="$a" ;;
    --dry-run)     DRY="--dry-run" ;;
    *) echo "unknown arg: $a" >&2; exit 1 ;;
  esac
done

echo "==> lint"
for f in public/*.php lib/*.php tools/*.php; do
  php -l "$f" >/dev/null || { echo "SYNTAX ERROR in $f" >&2; exit 1; }
done
echo "    all PHP OK."

push() {  # $1 public dest, $2 lib dest, $3 data dir, $4 label
  echo "==> [$4] public/ -> $1  lib/ -> $2"
  rsync -rlz $DRY --delete-excluded --exclude '.DS_Store' public/ "$HOST:$1/"
  rsync -rlz $DRY --exclude '.DS_Store' lib/ "$HOST:$2/"
  [[ -n $DRY ]] && return 0
  # The data dir belongs to the web user; the deploy only makes sure it exists
  # and that both users can reach it. Never synced, never deleted.
  ssh -o BatchMode=yes "$HOST" "mkdir -p $1 $2 $3 && chgrp -R web $3 2>/dev/null; chmod 2770 $3 2>/dev/null;
    find $1 $2 -type d -exec chmod a+rx {} + && find $1 $2 -type f -exec chmod a+r {} +"
}

case "$MODE" in
  test) push /home/public/test/BookMind /home/protected/bookmind-lib-test /home/protected/bookmind-data-test TEST ;;
  prod) push /home/public/BookMind      /home/protected/bookmind-lib      /home/protected/bookmind-data      PROD ;;
  all)
    push /home/public/BookMind      /home/protected/bookmind-lib      /home/protected/bookmind-data      PROD
    push /home/public/test/BookMind /home/protected/bookmind-lib-test /home/protected/bookmind-data-test TEST
    push /home/public/dev/BookMind  /home/protected/bookmind-lib-dev  /home/protected/bookmind-data-dev  DEV
    ;;
esac
echo "==> Done ($MODE). Data dirs were not touched."
