#!/bin/sh
# supervisord gate (Opus, implementation review): a numprocs=1 program whose forked worker crashes at
# boot, with supervisord's defaults (startsecs=1, startretries=3), must end up RUNNING stock, not FATAL.
#   docker run --rm --init --network none -u 1000:1000 -v "$PWD:/repo:ro" queue-prefork-php:8.4 sh /repo/docker/supervisord/gate.sh
set -u
D=$(mktemp -d)
cat > "$D/supervisord.conf" <<CONF
[supervisord]
nodaemon=false
logfile=$D/supervisord.log
pidfile=$D/supervisord.pid

[unix_http_server]
file=$D/supervisor.sock

[rpcinterface:supervisor]
supervisor.rpcinterface_factory = supervisor.rpcinterface:make_main_rpcinterface

[supervisorctl]
serverurl=unix://$D/supervisor.sock

[program:crash]
command=php /repo/tests/Fixtures/queue-app/artisan queue:work --name=crash
directory=/repo
environment=FIXTURE_CRASH="1",XDG_RUNTIME_DIR="$D"
stdout_logfile=$D/crash.out
stderr_logfile=$D/crash.err
CONF
supervisord -c "$D/supervisord.conf"
sleep 12
status=$(supervisorctl -c "$D/supervisord.conf" status crash)
echo "$status"
supervisorctl -c "$D/supervisord.conf" shutdown >/dev/null
if echo "$status" | grep -q RUNNING && grep -q "worker stock" "$D/crash.out"; then
    echo "PASS supervisord: the crashing program runs stock"
    exit 0
fi
echo "FAIL supervisord: $status"; tail -n 5 "$D/crash.out" "$D/crash.err" "$D/supervisord.log"
exit 1
