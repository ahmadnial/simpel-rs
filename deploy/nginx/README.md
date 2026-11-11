# PDF worker MIME

The production VM was prepared on 12 November 2026. See
[the preparation and QC report](../../docs/incidents/2026-11-12-production-vm-prepared.md)
before repeating any server configuration steps. Application code is still
deployed separately through Git.

Include `build-assets.conf` inside the application's HTTPS `server` block,
before generic regex locations. The existing `root` must point to `public`.
This changes only the response MIME for `.mjs` build assets, not the UI or PDF
review requirement. Missing assets must still return 404.

Production application requires a separately approved configuration rollout.
Back up the existing vhost, install the snippet, run `nginx -t`, then reload
Nginx only after validation succeeds. To roll back, remove the include, validate,
and reload. No database migration is needed for this MIME correction.

After rollout, use the current worker path from `public/build/manifest.json`:

```sh
curl --fail --head https://YOUR-HOST/build/assets/pdf.worker.min-HASH.mjs
```

Require HTTP 200 and `Content-Type: application/javascript` (or another valid
JavaScript MIME). Verify an unknown `.mjs` path returns 404. In the browser,
confirm the worker loads, the first PDF page appears, and the existing submit
button enables after a valid review. Then exercise a revision, replacement
upload, preview, and resubmission using an approved test document.

Production SIMPEL now uses the dedicated PHP-FPM socket
`/run/php/php8.4-fpm-simpel.sock`, with the pool configuration in
[`../php-fpm/simpel.conf`](../php-fpm/simpel.conf). Upload limits are 10M and POST
16M in that pool. Keep the other applications on their existing `www` pool.
Do not replace this with a per-request `PHP_VALUE` upload override: QC found
that the override persisted on reused workers in the shared pool.
