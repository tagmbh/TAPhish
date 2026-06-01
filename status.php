<?php
/**
 * Alias for /health.
 *
 * Some shared hosts (Hostpoint among them) reserve or intercept the
 * /health path before it reaches PHP. /status hits the same logic
 * via a different name so monitoring still works on those hosts.
 *
 * The .htaccess rewrite at repo root maps /<name> → /<name>.php, so
 * both `GET /health` and `GET /status` resolve here.
 */
require __DIR__ . '/health.php';
