#!/usr/bin/env bash
# Lint every plugin file, then execute the behavioural tests.
# Requires PHP (brew install php). Run from the plugin root: ./tests/run.sh
set -e
cd "$(dirname "$0")/.."

fail=0
for f in $(find . -name "*.php" -not -path "./includes/plugin-update-checker/*"); do
  out=$(php -l "$f" 2>&1)
  case "$out" in *"No syntax errors"*) ;; *) echo "✖ $f"; echo "$out"; fail=1;; esac
done
[ $fail -eq 0 ] && echo "✔ lint: all files parse"
[ $fail -ne 0 ] && exit 1

for t in tests/*.test.php; do
  echo ""
  echo "── $t"
  php "$t" || fail=1
done
exit $fail
