# TAPhish / T-Alpha brand assets

Active TAPhish / T-Alpha GmbH brand artwork.

The application references these by name through `spear/config/brand.php`:

The current marks are the **TA-PHISH** lockup (lambda-Λ "A", "BY T-ALPHA GMBH"
tagline) from the design-system handoff. The wordmark letterforms are outlined
`<path>`s (render correctly even when embedded via `<img>`); only the small
tagline is live `<text>` in IBM Plex Sans.

| File                  | Used by                                            | Notes                       |
|-----------------------|----------------------------------------------------|-----------------------------|
| `favicon.png`         | Operator-console `<link rel="icon">` tags          | 32x32 PNG; TΛ-fishhook icon |
| `logo-icon.svg`       | Sidebar icon (`z_menu`); about page                | 64x64 viewBox; gradient TΛ + fishhook square |
| `logo-text-white.svg` | **Dark chrome**: sidebar + login + change-pwd + about wordmark | 152.2x34 viewBox; white     |
| `logo-text.svg`       | Blue wordmark — reserved for light/print surfaces  | 152.2x34 viewBox; `#0071bb` |
| `logo.svg`            | Framed primary (icon + wordmark + tagline)         | 180.2x58 viewBox; best on light/print |
| `logo-mono.svg`       | Single-color icon (`currentColor`) for stamping    | 64x64 viewBox               |

The dark "dim-panel" UI uses the **white** wordmark; the blue wordmark and the
grey-framed primary are for light/print contexts.

SVG is preferred so the marks stay sharp at every zoom level and on
hi-DPI displays. The favicon stays PNG for older-browser compatibility.

To rebrand, replace the SVG files in place and adjust the constants in
`spear/config/brand.php` if you want different filenames.
