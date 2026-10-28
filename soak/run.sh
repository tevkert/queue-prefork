#!/usr/bin/env bash
# Soak: runs the stock and prefork stacks side by side for <hours>, both fed the same job stream
# (soak:dispatch with the same seed) and sampled by soak/sample.php, then stops them (volumes kept)
# and writes soak/results/report.md.
#
# usage: soak/run.sh <hours>            e.g. soak/run.sh 8, or soak/run.sh 0.25 for a smoke run
#
# Environment (all optional):
#   SOAK_MIX=heavy            heavy (a production-shaped queue layout, plus a 60-job digest burst every 5 min) or light
#                             (notifications, small writes, cache, short HTTP calls)
#   SOAK_RATE=420             jobs per minute, per container
#   SOAK_SEED=1               seed of the job stream
#   SOAK_SAMPLE_INTERVAL=60   seconds between samples
#   SOAK_DRAIN=120            seconds to keep sampling after the stream ends
#   SUPERVISOR_STOPASGROUP, HORIZON_FAST_TERMINATION, CONFIG_CACHE   see docker/compose.yml
#   SOAK_MODE_STOCK=stock, SOAK_MODE_PREFORK=prefork   result directory names (e.g. stock-uncached)
#
# Earlier results for the same mode names are moved to soak/results/archive/<UTC time>/.
set -euo pipefail

hours=${1:-}
if [[ ! $hours =~ ^[0-9]+([.][0-9]+)?$ ]]; then
    echo "usage: $0 <hours>" >&2
    exit 2
fi

repo=$(cd "$(dirname "$0")/.." && pwd)
results=$repo/soak/results
compose=(docker compose -f "$repo/docker/compose.yml")
minutes=$(awk -v h="$hours" 'BEGIN { m = int(h * 60 + 0.5); print (m < 1 ? 1 : m) }')
drain=${SOAK_DRAIN:-120}
stock=${SOAK_MODE_STOCK:-stock}
prefork=${SOAK_MODE_PREFORK:-prefork}

export SOAK_DISPATCH=true SOAK_SAMPLE=true SOAK_DURATION=$minutes
export SOAK_MIX=${SOAK_MIX:-heavy} SOAK_RATE=${SOAK_RATE:-420} SOAK_SEED=${SOAK_SEED:-1} SOAK_SAMPLE_INTERVAL=${SOAK_SAMPLE_INTERVAL:-60}

archive=$results/archive/$(date -u +%Y%m%dT%H%M%SZ)
for mode in "$stock" "$prefork"; do
    if [ -e "$results/$mode" ]; then
        mkdir -p "$archive"
        mv "$results/$mode" "$archive/"
    fi
done
if [ -e "$results/report.md" ] && [ -d "$archive" ]; then
    mv "$results/report.md" "$archive/"
fi

stop() {
    echo "soak: stopping both stacks (volumes kept)"
    "${compose[@]}" down
}

report() {
    echo "soak: writing soak/results/report.md"
    docker run --rm --network none -u 1000:1000 -v "$repo:/repo" queue-prefork-php:8.4 \
        php /repo/soak/report.php --modes="$stock,$prefork" >/dev/null
    echo "soak: report at $results/report.md"
}

last_row() {
    local file=$results/$1/cgroup.csv
    if [ -s "$file" ]; then
        awk -F, 'END { printf "%.0f MB, cpu %s%%, %s jobs/min, %s pending", $3 / 1024, $10, $15, $18 }' "$file"
    else
        echo "no samples yet"
    fi
}

trap 'echo; echo "soak: interrupted"; stop; report; exit 130' INT TERM

echo "soak: ${hours} h (${minutes} min), ${SOAK_MIX} mix at ${SOAK_RATE} jobs/min per container, seed ${SOAK_SEED}, sampling every ${SOAK_SAMPLE_INTERVAL} s"
"${compose[@]}" up -d --force-recreate app-stock app-prefork

started=$(date +%s)
deadline=$((started + minutes * 60 + drain))

while true; do
    now=$(date +%s)
    [ "$now" -ge "$deadline" ] && break
    nap=$(( deadline - now < 300 ? deadline - now : 300 ))
    sleep "$nap" &
    wait $! || true   # a signal interrupts the wait, so the trap runs at once
    elapsed=$(( ($(date +%s) - started) / 60 ))
    echo "[$(date -u +%H:%M:%S)] ${elapsed}/${minutes} min | ${stock}: $(last_row "$stock") | ${prefork}: $(last_row "$prefork")"
done

trap - INT TERM
stop
report
