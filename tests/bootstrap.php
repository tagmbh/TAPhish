<?php
/**
 * PHPUnit bootstrap.
 *
 * Loads only the pure, dependency-free pieces of the application so the
 * suite can run without MySQL or a session context. Tests that need DB or
 * session state belong in a separate (future) integration suite.
 */

require_once __DIR__ . '/../spear/config/brand.php';
require_once __DIR__ . '/Support/helpers_shim.php';
