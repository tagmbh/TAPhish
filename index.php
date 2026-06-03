<?php
/**
 * Docroot landing redirect.
 *
 * The application lives under /spear/. Hitting the bare docroot used
 * to return 403 (Options -Indexes, no index document). Redirect to the
 * operator panel so the root URL is usable.
 */
header('Location: spear/', true, 302);
exit;
