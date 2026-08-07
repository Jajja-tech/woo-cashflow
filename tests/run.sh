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

# A test file that FATALLY ERRORS must not be able to look like one that
# passed. applierContract.test.php died on an undefined function and the run
# still read as clean, because the only visible summary came from the file
# that happened to run last.
for t in tests/*.test.php; do
  echo ""
  echo "── $t"
  if ! php "$t"; then
    echo "✖ $t exited non-zero (a fatal error counts as a failure)"
    fail=1
  fi
done
[ $fail -ne 0 ] && echo "" && echo "✖ one or more test files failed"
exit $fail
