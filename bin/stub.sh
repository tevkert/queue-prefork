# queue-prefork stub. POSIX sh: dash, bash, busybox ash, macOS sh.
#
# The process the process manager started (`php artisan queue:work …`) handed its worker to the template
# and exec'd into this script, keeping its pid. It forwards signals to the worker, then exits the way the
# worker did. It never exits while the worker lives.
#
#   $1 worker pid   $2 worker start time (Identity's format)   $3 request id   $4 connection fd (3-9)
#   $5 runtime dir   $6 the signals the original process ignored (nohup), by name, for a stock exec
#
# The stub releases its worker by writing `{"t":"go"}` once its traps are set. The connection to the
# template then carries one status line: `<n>` (exit code), `s<n>` (died from signal n)
# or `stock` (the worker declined prefork before reserving a job). EOF without a line means the template
# died.

W=$1 WS=$2 ID=$3 FD=$4 RT=$5 IGN=${6:-}
ST="$RT/req-$ID.st"

# Descriptors the PHP process left open (its own script, for one) stay out of a stock exec. The shells
# keep the script they run on fd 10 or above.
for n in 3 4 5 6 7 8 9; do
    [ "$n" = "$FD" ] || eval "exec $n>&-"
done

# The original command line, as `set -- 'php' 'artisan' 'queue:work' …`, for the stock exec.
if [ -r "$RT/req-$ID.argv" ]; then
    . "$RT/req-$ID.argv"
    rm -f "$RT/req-$ID.argv"
else
    set --
fi

# Is $W still the worker we were given: not a recycled pid, and not a zombie waiting to be reaped?
same() {
    if [ -r "/proc/$W/stat" ]; then
        IFS= read -r l < "/proc/$W/stat" 2>/dev/null || return 1
        set -- ${l##*) }
        [ "$1" != Z ] && [ "${20}" = "$WS" ]
    else
        l=$(LC_ALL=C TZ=UTC ps -o stat=,lstart= -p "$W" 2>/dev/null) || return 1
        set -- $l
        case $1 in Z*) return 1 ;; esac
        shift
        l="$*"
        set -- $WS
        [ -n "$l" ] && [ "$l" = "$*" ]
    fi
}

forward() {
    if [ -z "$GONE" ] && same; then
        kill -"$1" "$W" 2>/dev/null
    else
        GONE=1
    fi
}

GONE=
for s in HUP INT QUIT USR1 USR2 ALRM TERM CONT WINCH; do
    trap "forward $s" "$s" # the worker's own disposition decides: it starts with $IGN ignored, as stock
done

# The reader ignores the forwarded signals: with stopasgroup or a systemd stop the whole process group
# gets them, and the reader must outlive the worker's drain. Once it does, and the main shell forwards
# them, it releases the worker: the template admits it on this line.
(
    trap '' HUP INT QUIT USR1 USR2 ALRM TERM WINCH
    printf '%s\n' '{"t":"go"}' >&"$FD" 2>/dev/null
    IFS= read -r s <&"$FD" && printf '%s\n' "$s" > "$ST"
) &
R=$!

while :; do
    wait "$R"
    kill -0 "$R" 2>/dev/null || break
done

s=
if [ -f "$ST" ]; then
    IFS= read -r s < "$ST"
    rm -f "$ST"
fi

case $s in
    '')
        # The template died: no status will come. Wait until the worker is gone, then report failure.
        while same; do
            sleep 1 &
            wait $!
        done
        exit 1
        ;;
    stock)
        eval "exec $FD<&-"
        [ $# -ge 2 ] || exit 70
        for s in $IGN; do
            trap '' "$s" # the stock process inherits the original process's ignored signals
        done
        exec env QUEUE_PREFORK_HANDOFF=0 "$@"
        ;;
    s*)
        n=${s#s}
        sig=$(kill -l "$n" 2>/dev/null)
        case $sig in
            HUP | INT | KILL | USR1 | USR2 | PIPE | ALRM | TERM)
                trap - "$sig"
                kill -"$sig" $$
                ;;
        esac
        # PID 1 drops its own signals, and core-dumping signals aren't re-raised: exit like a shell would.
        exit $((128 + n))
        ;;
    *)
        exit "$s"
        ;;
esac
