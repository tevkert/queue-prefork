#!/bin/sh
# bin/stub.sh scenarios for shells without PHP around (busybox ash on Alpine). Linux only (/proc).
# The PHP suite (StubScriptTest) covers dash, bash and macOS sh with the same scenarios.
#
#   sh tests/Mechanics/stub-harness.sh "<shell command>"     e.g. "busybox sh"
# Prints one line per scenario and exits non-zero if any failed.

SHELL_UNDER_TEST=${1:-sh}
STUB=$(cd "$(dirname "$0")/../../bin" && pwd)/stub.sh
RT=$(mktemp -d)
FAILED=0

pass() { echo "PASS $1"; }
fail() { echo "FAIL $1: $2"; FAILED=1; }

start_of() { read -r l < "/proc/$1/stat"; set -- ${l##*) }; echo "${20}"; }

# A worker: counts TERMs, drains $1 seconds on TERM and exits $2, marks USR2.
worker() {
    sh -c "trap 'n=\$(cat $RT/tc 2>/dev/null || echo 0); echo \$((n+1)) > $RT/tc; sleep $1; exit $2' TERM
           trap 'touch $RT/usr2' USR2
           while :; do sleep 0.05; done" &
    W=$!
    sleep 0.1
}

# The stub in its own session/group, fd 3 = the read end of a fresh FIFO, fd 4 = our (template) end.
stub() { # $1 id, $2 argv line for the stock exec, $3 start time override
    rm -f "$RT/conn$1"; mkfifo "$RT/conn$1"
    exec 4<>"$RT/conn$1"
    printf '%s\n' "$2" > "$RT/req-$1.argv"
    setsid $SHELL_UNDER_TEST "$STUB" "$W" "${3:-$(start_of "$W")}" "$1" 3 "$RT" 3<"$RT/conn$1" 4<&- &
    S=$!
    sleep 0.3
}

# wait in this shell (a $(…) subshell has no children); sets ST
reap() { wait "$1"; ST=$?; }

# 1. Group TERM: forwarded once, the stub outlives the drain, exits with the worker's code.
rm -f "$RT/tc"; worker 0.7 7; stub 1 "set -- /bin/true x"
kill -TERM -"$S"; sleep 0.3
kill -0 "$S" 2>/dev/null && pass "group TERM: stub alive during drain" || fail "group TERM" "stub exited early"
wait "$W"; [ "$(cat "$RT/tc")" = 1 ] && pass "group TERM: forwarded once" || fail "group TERM" "count $(cat "$RT/tc")"
echo 7 >&4; exec 4>&-
reap "$S"; [ "$ST" = 7 ] && pass "group TERM: exit 7" || fail "group TERM" "wrong exit"

# 2. Individual USR2 is forwarded.
rm -f "$RT/usr2"; worker 0 0; stub 2 "set -- /bin/true x"
kill -USR2 "$S"; sleep 0.4
[ -f "$RT/usr2" ] && pass "USR2 forwarded" || fail "USR2" "not forwarded"
kill -9 "$W"; wait "$W" 2>/dev/null; echo 0 >&4; exec 4>&-; reap "$S"

# 3. s15 re-raised (128+15 as seen by wait), s11 exits 139 without re-raising.
worker 0 0; stub 3 "set -- /bin/true x"; kill -9 "$W"; wait "$W" 2>/dev/null
echo s15 >&4; exec 4>&-
reap "$S"; [ "$ST" = 143 ] && pass "s15 → 143" || fail "s15" "wrong status"
worker 0 0; stub 4 "set -- /bin/true x"; kill -9 "$W"; wait "$W" 2>/dev/null
echo s11 >&4; exec 4>&-
reap "$S"; [ "$ST" = 139 ] && pass "s11 → 139" || fail "s11" "wrong status"

# 4. stock: exec the original command in place, handoff disabled; argv file consumed at startup.
worker 0 0; stub 5 "set -- /bin/sh -c 'printf \"%s %s\" \"\$QUEUE_PREFORK_HANDOFF\" \"\$\$\" > $RT/stock-out; exit 5'"
[ -f "$RT/req-5.argv" ] && fail "stock" "argv file not consumed at startup" || pass "argv file consumed at startup"
kill -9 "$W"; wait "$W" 2>/dev/null; echo stock >&4; exec 4>&-
reap "$S"; st=$ST
[ "$st" = 5 ] && [ "$(cat "$RT/stock-out")" = "0 $S" ] && pass "stock exec in place (pid kept)" || fail "stock" "status $st, out $(cat "$RT/stock-out" 2>/dev/null)"

# 5. stock without a command line → 70.
worker 0 0; stub 6 "set --"; kill -9 "$W"; wait "$W" 2>/dev/null; echo stock >&4; exec 4>&-
reap "$S"; [ "$ST" = 70 ] && pass "stock without argv → 70" || fail "stock without argv" "wrong status"

# 6. EOF (template died): the stub waits for the worker, then exits 1.
worker 0 0; stub 7 "set -- /bin/true x"; exec 4>&-; sleep 1.5
kill -0 "$S" 2>/dev/null && pass "EOF: stub waits while the worker lives" || fail "EOF" "stub exited early"
kill -9 "$W"; wait "$W" 2>/dev/null
reap "$S"; [ "$ST" = 1 ] && pass "EOF: exit 1 after the worker is gone" || fail "EOF" "wrong status"

# 7. A mismatched identity is never signalled.
worker 0 0; stub 8 "set -- /bin/true x" "not-the-start-time"
kill -TERM "$S"; sleep 0.5
kill -0 "$W" 2>/dev/null && pass "mismatched identity not signalled" || fail "identity" "worker was signalled"
kill -9 "$W"; wait "$W" 2>/dev/null; echo 0 >&4; exec 4>&-
reap "$S"; [ "$ST" = 0 ] && pass "identity: exit 0" || fail "identity" "wrong status"

rm -rf "$RT"
exit $FAILED
