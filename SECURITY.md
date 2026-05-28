# Security policy

TAPhish is a phishing simulation toolkit. It is intended for **authorized
red-team engagements, employee phishing-awareness training, CTFs, and
security research** where the operator has written permission from the
target organization.

Operating the toolkit against people or systems you do not have
authorization to test is not a use case we support and is illegal in
most jurisdictions.

## What's in scope

We treat the operator admin panel as a production web application. Reports
in any of the following areas are welcome:

- **Authentication, session, and CSRF** — login bypass, session fixation,
  privilege escalation, CSRF on state-changing endpoints
- **Password storage** — anything weaker than the current
  `password_hash(PASSWORD_BCRYPT, ['cost' => 12])` with the legacy
  unsalted-SHA-256 upgrade-on-login path
- **Input handling on operator-supplied URLs** — SSRF in the Site
  Cloner, the OSINT Hunter.io proxy, the bounce-poll IMAP client, etc.
- **XSS in the operator UI** — both stored and reflected
- **SQL injection** — any code path not using prepared statements
- **File-upload boundary** — anything outside the existing
  `finfo_file` / extension whitelisting
- **Public unauthenticated endpoints** (`track.php`, `qt.php`, `mod.php`)
  — these accept untrusted input by design, but they should still be
  resilient to oversized / malformed input and basic rate-limit abuse

## What's out of scope

- The **phishing-payload side** of the workload (cloned landing pages,
  email templates, tracking pixels) is offensive content by design. We
  do not try to detect or block its use.
- **Defending against motivated targets** who fully control their own
  browsers and inboxes. That is outside the threat model of any
  phishing simulator.
- **Self-hosted deployments without operator hardening.** TLS, reverse
  proxy auth, firewalling of the operator panel from the public
  internet, and OS-level patching are the operator's responsibility.

## Reporting a vulnerability

Please open a **private security advisory** on GitHub Security Advisories:

<https://github.com/tagmbh/TAPhish/security/advisories>

Include a minimal reproduction (URL, payload, expected vs. observed
behavior), the commit SHA you tested against, and any environmental
notes (PHP version, web server, MySQL version).

Please do **not** file a public issue for an unfixed vulnerability in
the operator panel.

## Disclosure cadence

- We'll acknowledge receipt within **5 business days**.
- For confirmed issues, we aim to ship a fix within **30 days** for
  high-severity, **90 days** for medium, and tag a release.
- Once the fix is on `main`, we coordinate a disclosure timeline with
  the reporter and credit them on the release notes unless they
  prefer otherwise.

## See also

- Threat model and hardening detail:
  <https://tagmbh.github.io/TAPhish/security.html>
- Per-feature usage notes:
  <https://tagmbh.github.io/TAPhish/features.html>
- Per-release changelog:
  <https://tagmbh.github.io/TAPhish/changelog.html>
