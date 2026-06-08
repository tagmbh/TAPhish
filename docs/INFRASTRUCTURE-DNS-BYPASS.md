# Infrastructure / DNS-block bypass — operator playbook

When Swisscom Internet Guard (or any DNS-protection database — Cisco
Umbrella, Quad9, etc.) blocks the host that runs your TAPhish install,
recipients on those networks can't reach the simulation landing page and
the engagement breaks mid-flight. This document is the runbook for getting
back online quickly **and** for building a setup that doesn't keep getting
blocked every couple of weeks.

> **Status (2026-06-09):** the immediate-fix content layer is shipped
> (PR #130 turned the root URL into a legitimate Security Awareness
> Training landing instead of a 302 to `/spear/`). PR #131 makes the
> platform fully subdomain-portable so a domain migration is a 10-minute
> operator-side change, not a code change.

---

## Why this keeps happening

The classifier signal stack — what Swisscom (and the reputation
databases they consume) actually looks at:

1. **Hostname pattern** — `ptbe.autodiscover.li` looks like an
   M365-Autodiscover mimic. That alone is a textbook phishing
   infrastructure signal. The `autodiscover.li` TLD shape mirrors
   Microsoft's real `autodiscover.outlook.com` lookup pattern; classifiers
   weight that heavily.
2. **Root content** — until PR #130, hitting the bare domain redirected
   to `/spear/` (an obvious operator login page). Reputation databases
   that crawl the root saw "credential harvesting interface" and
   categorised accordingly.
3. **Domain age and traffic shape** — a host that exists only to serve
   bursty per-engagement traffic with a credential-shaped landing pattern
   matches a phishing fingerprint.
4. **Brand-resemblance landings** — `/p/m365-login-*` paths with M365
   sign-in clones are independently classified.

PR #130 addresses (2). PR #131 makes (1) fixable in 10 minutes without
code changes. Items (3) and (4) need infrastructure-level rotation.

---

## Tactical: get back online tonight

If a critical engagement is blocked right now:

1. **Spin up a fresh subdomain.** From the Hostpoint control panel, add a
   new alias of the operator host's docroot — anything brand-neutral and
   training-themed:
     - `training.t-alpha.ch`
     - `awareness.t-alpha.ch`
     - `cyberacademy.t-alpha.ch`
   Avoid: `phish*`, `m365*`, `outlook*`, `admin*`, anything that names a
   target brand or the platform type.
2. **Verify it serves the same install.** Hit
   `https://<new-host>/` — you should see the Security Awareness
   Training landing (PR #130 + #131). Hit `https://<new-host>/spear/`
   — you should see the operator login.
3. **Update the operator panel's base URL.** Settings → General → "Server
   base URL" — set to the new host. From this point onward, every new
   campaign's `{{TRACKINGURL}}` and tracker pixels resolve to the new
   host.
4. **Old campaigns keep working** as long as the old host stays
   reachable (Hostpoint serves the same docroot under both aliases).

> Index.php and every operator-facing endpoint are domain-agnostic —
> they read `$_SERVER['HTTP_HOST']` and the `tb_main_variables.baseurl`
> column. A subdomain flip needs **zero code changes** and **zero new
> deploys**. PR #131's tests pin this guarantee.

---

## Strategic: stop getting blocked

The right move is to **separate the public-facing host from the
operator-facing host**, with brand-neutral naming, and to rotate the
public host per-engagement so reputation accumulates per engagement, not
per platform.

### Recommended structure

| Role                                  | Hostname                              | Rotated? |
|---------------------------------------|---------------------------------------|----------|
| Operator panel (you log in here)      | `panel.t-alpha.ch` (or stay on ptbe)  | No       |
| Public root + recipient landings      | `<engagement>.t-alpha.ch`             | Yes — per engagement |
| Mail-tracking endpoint (`/tmail`)      | Same as the landing host              | Follows landings |

Concrete examples for a quarterly cadence:

- `q3-2026.t-alpha.ch`
- `awareness-acme.t-alpha.ch`
- `training-fall26.t-alpha.ch`

After the engagement is over, decommission the subdomain. Even if
Swisscom eventually flags it, the next engagement starts fresh.

### Why subdomain rotation works

Reputation databases categorise at the **left-most label** of the
domain. A blocked `training-q2.t-alpha.ch` does **not** taint
`training-q3.t-alpha.ch` (different label, different reputation row).
The right-most labels (`t-alpha.ch`) accumulate a generic
"corporate parent" reputation that benefits all subdomains and is hard
to block without false-positive blasting an entire Swiss company.

### What NOT to do

- **Don't** keep using `ptbe.autodiscover.li` for new engagements. The
  `autodiscover.li` shape is a permanent classifier red flag — content
  cleanup helps, but the hostname will keep dragging reputation down.
- **Don't** use brand-specific subdomain naming
  (`microsoft-365-portal.t-alpha.ch`, `acme-it-portal.t-alpha.ch`).
  Trademark-resemblance classifiers fire on those instantly.
- **Don't** route through an aggressive CDN provider that itself
  classifies phishing (Cloudflare's anti-phishing scanning is
  increasingly hostile to red-team work). A simple Hostpoint subdomain
  alias is enough.
- **Don't** assume Cloudflare or another reverse proxy will mask the
  classification — modern blocklists look at hostname + content
  fingerprint, not just IP.

### Optional: brand-isolated TLD per high-stakes engagement

For a high-stakes engagement where customer-side blocking would be
catastrophic, register a fresh **brand-unrelated** domain
(`learn-security-2026.ch`, `securityreminders.ch`). Costs ~CHF 15/year.
Use it for that one engagement, then sunset.

---

## Compliance / customer-facing posture

Every public-facing host on this platform must continue to:

- serve the legitimate Security Awareness Training root landing
  (PR #130) — Schema.org JSON-LD declaring `serviceType:
  "Cybersecurity Awareness Training"` and `parentOrganization: T-Alpha
  GmbH`;
- carry a clear recipient-facing notice on the landing
  ("Did you receive a simulation email...");
- list contact info that resolves to T-Alpha;
- allow robots crawling (so the legitimate content reaches the
  classifiers in the first place).

These items are exercised by `tests/RootLandingPageTest.php` and run on
every CI build, so a future change can't silently break the posture.

---

## Migration checklist (copy/paste)

When you stand up a new subdomain for an engagement:

- [ ] Add the alias in Hostpoint control panel (`<name>.t-alpha.ch` →
      same docroot as ptbe)
- [ ] Wait for DNS to propagate (usually <5 min on Hostpoint)
- [ ] `curl -sI https://<name>.t-alpha.ch/` → expect HTTP 200, content
      type text/html, body contains "Security Awareness Training"
- [ ] `curl -sI https://<name>.t-alpha.ch/spear/` → expect HTTP 200,
      operator login page
- [ ] In the panel: Settings → General → "Server base URL" → set to
      `https://<name>.t-alpha.ch`
- [ ] Create a one-recipient self-test campaign, click through, confirm
      capture appears on the dashboard
- [ ] Brief the engagement's customer-side sponsor: "if anyone reports
      the simulation URL, point them at the public landing — it
      identifies the platform"
- [ ] After the engagement, optionally sunset the alias (cleanup +
      blocks classifiers from cross-correlating with the next engagement)

---

*Maintained alongside the codebase. Update when the platform's
domain-agnosticism contract changes (`tests/RootLandingPageTest.php`
is the source of truth for what the public root guarantees).*
