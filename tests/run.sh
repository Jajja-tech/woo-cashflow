#!/usr/bin/env bash
# Lint every plugin file, then execute the behavioural tests.
# Requires PHP (brew install php). Run from the plugin root: ./tests/run.sh
set -e
cd "$(dirname "$0")/.."

fail=0
# CF_TEST_FILES / CF_SKIP_LINT exist so harnessContract.test.php can run THIS
# script against deliberately broken test files and prove it fails them. A
# normal run sets neither and behaves exactly as before.
if [ -z "$CF_SKIP_LINT" ]; then
  for f in $(find . -name "*.php" -not -path "./includes/plugin-update-checker/*"); do
    out=$(php -l "$f" 2>&1)
    case "$out" in *"No syntax errors"*) ;; *) echo "✖ $f"; echo "$out"; fail=1;; esac
  done
  [ $fail -eq 0 ] && echo "✔ lint: all files parse"
  [ $fail -ne 0 ] && exit 1
fi

# A test file that FATALLY ERRORS must not be able to look like one that
# passed. applierContract.test.php died on an undefined function and the run
# still read as clean, because the only visible summary came from the file
# that happened to run last.
# 🔴 AND A FILE THAT NEVER CALLS summary() MUST NOT BE ABLE TO PASS EITHER.
# bootstrap.php claims "a file cannot forget to report" — it can. addressApply
# ran 11 assertions, called no summary(), and exited 0; a broken assertion in it
# would have been invisible. summary() is what turns $fail into an exit code, so
# without it the file is a test that cannot fail. Checked here rather than
# trusted, because the previous version of this comment was already wrong once.
for t in ${CF_TEST_FILES:-tests/*.test.php}; do
  echo ""
  echo "── $t"
  if ! php "$t"; then
    echo "✖ $t exited non-zero (a fatal error counts as a failure)"
    fail=1
  fi
  # ⚠️ MUST MATCH AN UNCOMMENTED CALL. The first version of this guard was
  # `grep -q 'summary()'`, which a commented-out `// summary();` satisfies — so
  # it passed a file it was written to catch. Same trap as the image-proxy and
  # courier-badge guards: a check its own prose can satisfy proves nothing.
  if ! grep -qE '^[[:space:]]*summary\(\)' "$t"; then
    echo "✖ $t never calls summary() — its assertions cannot fail the run"
    fail=1
  fi
done
[ $fail -ne 0 ] && echo "" && echo "✖ one or more test files failed"
exit $fail
