#!/bin/sh
# systemd gate scenarios, run as root inside the systemd container (docker/systemd/run.sh). Each
# prefork unit runs next to a stock one (QUEUE_PREFORK=0); prints PASS/FAIL lines, exits 1 on a FAIL.
set -u
FAILED=0

pass() { echo "PASS $1"; }
fail() { echo "FAIL $1: $2"; FAILED=1; }
check() { if eval "$2"; then pass "$1"; else fail "$1" "${3:-}"; fi; }

prop() { systemctl show -p "$2" --value "$1"; }
cmd_of() { tr '\0' ' ' < "/proc/$1/cmdline" 2>/dev/null; }
procs_in() { cat "/sys/fs/cgroup$(prop "$1" ControlGroup)/cgroup.procs" 2>/dev/null; }
log_of() { journalctl -u "$1" -o cat --no-pager 2>/dev/null; }
forked_pid() { log_of "$1" | sed -n 's/.*worker forked pid=\([0-9]*\).*/\1/p' | tail -1; }
templates_in() { for p in $(procs_in "$1"); do cmd_of "$p" | grep -q 'qp-template' && echo "$p"; done; }
ended() { log_of "$1" | sed -n 's/.*Main process exited, \(code=[a-z]*, status=[^ ]*\).*/\1/p' | tail -1; }
wait_for() { # seconds, condition
    n=0
    while [ "$n" -lt $(($1 * 10)) ]; do
        eval "$2" && return 0
        sleep 0.1
        n=$((n + 1))
    done
    return 1
}

stop_pair() { # prefork unit, stock unit, label (check() evals in its own scope: pass values, not $1/$2)
    pu=$1 su=$2
    before=$(procs_in "$pu") # stub, reader, template, worker
    systemctl stop "$pu" "$su"
    left=
    for p in $before; do [ -d "/proc/$p" ] && left="$left $p"; done
    check "$3: the stop ends both units the same way ($(ended "$su"))" '[ -n "$(ended "$su")" ] && [ "$(ended "$pu")" = "$(ended "$su")" ]' "prefork: $(ended "$pu")"
    check "$3: none of the unit's $(echo $before | wc -w) processes is left" '[ -n "$before" ] && [ -z "$left" ]' "left:$left"
}

# 1. Template units with the default KillMode=control-group, and journald output.
systemctl start qp@a qp-stock@a
wait_for 15 '[ -n "$(forked_pid qp@a)" ]' || fail "control-group: a forked worker" "$(log_of qp@a | tail -5)"
wait_for 15 'log_of qp-stock@a | grep -q "worker stock"' || fail "control-group: the stock worker" "$(log_of qp-stock@a | tail -5)"
stub=$(prop qp@a MainPID)
worker=$(forked_pid qp@a)
check "control-group: the unit's main pid is the stub" 'cmd_of "$stub" | grep -q "/stub-"' "$(cmd_of "$stub")"
check "control-group: the worker and one template live in the unit's cgroup" 'procs_in qp@a | grep -qx "$worker" && [ "$(templates_in qp@a | wc -l)" -eq 1 ]'
check "journald: the worker's stdout reaches the unit's journal" 'log_of qp@a | grep -q "STDOUT constant line"'
check "journald: and its stderr" 'log_of qp@a | grep -q "stderr line from forked"'
stop_pair qp@a qp-stock@a "control-group"

# 2. KillMode=mixed: TERM to the main process only, SIGKILL for whatever remains.
systemctl start qp-mixed@a qp-mixed-stock@a
wait_for 15 '[ -n "$(forked_pid qp-mixed@a)" ] && log_of qp-mixed-stock@a | grep -q "worker stock"' || fail "mixed: both workers up" ""
stop_pair qp-mixed@a qp-mixed-stock@a "mixed"
check "mixed: the worker drained (TERM reached it through the stub)" 'log_of qp-mixed@a | grep -q "TERM received"'

# 3. A killed worker: the unit sees the same death and restarts (Restart=always).
systemctl start qp@b qp-stock@b
wait_for 15 '[ -n "$(forked_pid qp@b)" ] && log_of qp-stock@b | grep -q "worker stock"' || fail "kill: both workers up" ""
old=$(prop qp@b MainPID)
kill -KILL "$(forked_pid qp@b)" "$(prop qp-stock@b MainPID)"
wait_for 15 '[ "$(prop qp@b NRestarts)" -ge 1 ] && [ "$(prop qp-stock@b NRestarts)" -ge 1 ]' || fail "kill: both units restarted" ""
check "kill: the same death as stock ($(log_of qp-stock@b | grep -o 'Main process exited, [^.]*' | head -1))" '[ "$(log_of qp@b | grep -o "Main process exited, [^.]*" | head -1)" = "$(log_of qp-stock@b | grep -o "Main process exited, [^.]*" | head -1)" ]' "$(log_of qp@b | grep -o 'Main process exited, [^.]*' | head -1)"
wait_for 15 '[ "$(log_of qp@b | grep -c "worker forked")" -ge 2 ]' && pass "kill: the restarted unit forks again" || fail "kill: the restarted unit forks again" "$(log_of qp@b | tail -5)"
check "kill: the restart has a new stub" '[ "$(prop qp@b MainPID)" != "$old" ]'
stop_pair qp@b qp-stock@b "after the restart"

# 4. Several workers in one unit share one template (the only systemd layout that saves memory).
systemctl start qp-multi
wait_for 20 '[ "$(log_of qp-multi | grep -c "worker forked")" -ge 3 ]' || fail "multi: three forked workers" "$(log_of qp-multi | tail -5)"
t=$(templates_in qp-multi)
check "multi: one template in the unit" '[ "$(echo "$t" | wc -w)" -eq 1 ]' "$t"
check "multi: all three workers are its children" '[ "$(for p in $(log_of qp-multi | sed -n "s/.*worker forked pid=\([0-9]*\).*/\1/p"); do awk "{print \$4}" /proc/$p/stat 2>/dev/null; done | grep -cx "$t")" -eq 3 ]'
before=$(procs_in qp-multi)
systemctl stop qp-multi
left=
for p in $before; do [ -d "/proc/$p" ] && left="$left $p"; done
check "multi: none of the unit's $(echo $before | wc -w) processes is left" '[ -n "$before" ] && [ -z "$left" ]' "left:$left"
check "multi: each worker drained" '[ "$(log_of qp-multi | grep -c "TERM received")" -ge 3 ]'

# 5. A boot crash that happens only in forked workers, under systemd's default start limits: the unit
# must end up running stock, not failed.
systemctl start qp-crash
wait_for 15 'log_of qp-crash | grep -q "worker stock"' || fail "crash: the unit runs stock" "$(log_of qp-crash | tail -5)"
sleep 3
check "crash: the unit is active, running stock" '[ "$(prop qp-crash ActiveState)" = active ] && cmd_of "$(prop qp-crash MainPID)" | grep -q "artisan queue:work"' "$(prop qp-crash ActiveState) $(prop qp-crash NRestarts) restarts"
check "crash: without a failed start" '[ "$(prop qp-crash NRestarts)" = 0 ]' "$(prop qp-crash NRestarts) restarts"
systemctl stop qp-crash

exit "$FAILED"
