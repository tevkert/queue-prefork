---
name: Bug report
about: A worker behaves differently from stock, or falls back to stock unexpectedly
labels: bug
---

**What happened, and what did you expect?**


**Versions:** PHP, Laravel, Horizon (if used), queue-prefork, OS/kernel, FFI on or off

**How your workers are started:** `queue:work` or Horizon; supervisord / systemd / container (which init?) / Forge / other

<details><summary><code>php artisan queue-prefork:doctor --json</code></summary>

```json

```
</details>

<details><summary><code>php artisan queue-prefork:status --json</code></summary>

```json

```
</details>

**`queue-prefork:` lines from your logs:**

```

```

Security issues: please don't file them here; see the Security section of the README.
