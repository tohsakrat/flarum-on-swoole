# Flarum Swoole Worker

Place this `swoole-worker` directory directly under the Flarum root.

Expected layout:

```text
flarum/
  public/index.php
  site.php
  vendor/autoload.php
  extend.php
  swoole-worker/
    flarum-worker-swoole-co.php
    Config.php
    Cache.php
    ...
```

Run:

```bash
php swoole-worker/flarum-worker-swoole-co.php
```

`WORKER_BASE_DIR` is resolved as the parent directory of `swoole-worker`, so Flarum's public entry is `../public/index.php` from the worker directory.
