# Changelog

All notable changes to this project are documented here. The project uses [Semantic Versioning](https://semver.org/).

## [1.0.2] - 2026-10-28

- **`queue-prefork:status`** counts only the worker trees' own processes. Any other process in the app's folder whose command line mentioned Horizon or a template (the scheduler's `horizon:snapshot`, `horizon:status`, a tinker session) showed up as a master or a template, with its memory in the total.
- **Deploys:** the templates and the Horizon master load the package's classes when they start. A deploy that replaces or removes the package's files while they still run can't make one load a class from another version at shutdown, or fail to load it and keep the master from exiting.

## [1.0.1] - 2026-10-28

- **PHP 8.2 and 8.3:** forked processes no longer read their parent's `/proc/self`. Before PHP 8.4 a fork keeps its parent's realpath cache, so a worker copying its supervisor's ignored signals (as under `nohup`) read the template's instead of its own. PHP 8.4 and later weren't affected.
- **CI fixes:** the test jobs load only the extensions the package needs (the runner's php-zmq holds an eventfd that a template won't fork with), have a 20-minute limit, and the Mechanics tests skip cleanly without FFI.

## [1.0.0] - 2026-10-28

First release: Laravel queue workers run as forks of a template process that shares their compiled code through one opcache. It's on once installed, with safety checks and a fallback to stock workers, and supports Laravel 11–13.

- **Plain `queue:work`** under supervisord, systemd or an init: each worker is forked from a shared, pre-warmed template, and the process your supervisor started stays as a small `/bin/sh` stub with the same pid, signals and exit status. Needs FFI for the CLI; Linux without FFI writes worker output to a shared, rotated log.
- **Horizon 5.23–5.50:** supervisors and workers are forked from an idle template, with one small fork server per supervisor. On Linux the master is forked too: the process your process manager started becomes the template and keeps its pid.
- **Switches:** on by default; `QUEUE_PREFORK=false` turns everything off, and `QUEUE_PREFORK_QUEUE_WORK=false` or `QUEUE_PREFORK_HORIZON=false` turns off one mode. They're ordinary config settings, read before Laravel boots the way Laravel resolves them (the cached config, else the environment and `.env`); a runtime switch and per-host off files turn it off without a re-cache.
- **Commands:** `queue-prefork:doctor`, `queue-prefork:status`, `queue-prefork:switch` and `queue-prefork:retire`.
- Developed as `horizon-prefork` before the first release, which only covered Horizon.

[1.0.2]: https://github.com/tevkert/queue-prefork/compare/v1.0.1...v1.0.2
[1.0.1]: https://github.com/tevkert/queue-prefork/compare/v1.0.0...v1.0.1
[1.0.0]: https://github.com/tevkert/queue-prefork/releases/tag/v1.0.0
