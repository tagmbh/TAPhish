# TAPhish / T-Alpha brand assets

Active TAPhish / T-Alpha GmbH brand artwork.

The application references these by name through `spear/config/brand.php`:

| File             | Used by                                  | Notes                  |
|------------------|------------------------------------------|------------------------|
| `favicon.png`    | All page `<link rel="icon">` tags        | 16x16 PNG (compat)     |
| `logo-icon.svg`  | Sidebar collapsed-mode icon (`z_menu`)   | 64x64 viewBox          |
| `logo-text.svg`  | Sidebar wordmark next to the icon        | 220x40 viewBox         |
| `logo.svg`       | Login + change-password screens          | 320x80 viewBox         |

SVG is preferred so the marks stay sharp at every zoom level and on
hi-DPI displays. The favicon stays PNG for older-browser compatibility.

To rebrand, replace the SVG files in place and adjust the constants in
`spear/config/brand.php` if you want different filenames.
