#!/bin/sh
# Builds the systemd image, boots systemd in a privileged container with the repo mounted read-only,
# runs gate.sh in it, and removes the container.   usage: docker/systemd/run.sh   (from the repo root)
set -eu
cd "$(dirname "$0")/../.."
docker build -q -t queue-prefork-systemd docker/systemd >/dev/null
docker rm -f queue-prefork-systemd >/dev/null 2>&1 || true
docker run -d --name queue-prefork-systemd --privileged --cgroupns=private --network none \
    --tmpfs /run --tmpfs /run/lock -v "$PWD:/repo:ro" queue-prefork-systemd >/dev/null
trap 'docker rm -f queue-prefork-systemd >/dev/null 2>&1' EXIT
i=0
until docker exec queue-prefork-systemd systemctl is-system-running 2>/dev/null | grep -qE 'running|degraded'; do
    i=$((i + 1))
    [ "$i" -lt 60 ] || { echo "systemd did not boot"; exit 1; }
    sleep 1
done
docker exec queue-prefork-systemd sh /repo/docker/systemd/gate.sh
