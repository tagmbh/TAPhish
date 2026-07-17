<?php
/**
 * Shared sidebar-navigation bootstrap.
 *
 * Include this once, right before </body>, on every manager page that renders
 * the sidebar (z_menu.php). It loads sidebarmenu.js, which wires the sidebar's
 * active-state highlighting and submenu expand/collapse. Pages that skip it
 * exhibit the "click Home first before the left nav works" bug.
 *
 * sidebarmenu.js is deferred and depends on jQuery, which every manager page
 * already loads earlier in the body, so this executes after jQuery is ready.
 * Guarded so accidental double-includes emit the tag only once.
 */
if (!defined('TAPHISH_NAVBOOT_EMITTED')) {
    define('TAPHISH_NAVBOOT_EMITTED', true);
    echo '<script defer src="js/libs/sidebarmenu.js"></script>' . "\n";
}
