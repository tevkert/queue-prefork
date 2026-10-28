# The systemd gate: the fixture worker as systemd units, each against a stock unit.
# Built on the test image (docker/php); run with docker/systemd/run.sh.
FROM queue-prefork-php:8.4

RUN set -eux; \
    apt-get update; \
    apt-get install -y --no-install-recommends systemd systemd-sysv; \
    rm -rf /var/lib/apt/lists/*

COPY units/ /etc/systemd/system/

STOPSIGNAL SIGRTMIN+3
CMD ["/lib/systemd/systemd"]
