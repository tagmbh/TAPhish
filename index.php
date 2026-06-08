<?php
/**
 * Public docroot landing for the TAPhish-hosted operator panel.
 *
 * Previously: 302 → /spear/, which advertised the bare domain as "operator
 * login here" to every passing crawler / DNS classifier / reputation
 * blocklist — exactly the signal Swisscom Internet Guard et al. flag as
 * phishing infrastructure. The /p/<slug>/ vanity URLs already worked
 * regardless of what the root served (.htaccess RewriteRules fire before
 * this PHP runs); flipping the root to a legitimate Security Awareness
 * Training homepage:
 *
 *   - removes the obvious "phishing CnC" signal
 *   - lets DNS classifiers see what the platform actually is (security
 *     awareness training operated by a Swiss consultancy under explicit
 *     customer contracts)
 *   - gives a real human who lands on the bare domain a clear, accurate
 *     explanation instead of an unexpected operator-login page
 *
 * Operator login stays one click away (footer + direct /spear/ URL).
 */

header('Content-Type: text/html; charset=UTF-8');
header('Cache-Control: public, max-age=300');
header('X-Robots-Tag: index, follow');
?><!doctype html>
<html lang="en">
<head>
   <meta charset="utf-8">
   <meta name="viewport" content="width=device-width,initial-scale=1">
   <title>T-Alpha Security Awareness Training</title>
   <meta name="description" content="Cybersecurity awareness training and phishing simulation platform operated by T-Alpha GmbH for the educational training of our customers' employees.">
   <meta name="robots" content="index, follow">
   <meta name="author" content="T-Alpha GmbH">
   <meta name="keywords" content="security awareness, cybersecurity training, phishing simulation, employee education, Switzerland, t-alpha">
   <link rel="canonical" href="https://ptbe.autodiscover.li/">
   <link rel="icon" type="image/png" href="spear/images/brand/favicon.png">

   <style>
      :root { --brand:#0071BB; --ink:#1a1a1a; --mute:#6b7785; --bg:#ffffff; --soft:#f5f7fa; --line:#e8ecf0; }
      * { box-sizing: border-box; }
      body { font-family: -apple-system, "Segoe UI", system-ui, "Inter", sans-serif; max-width: 760px; margin: 0 auto; padding: 56px 24px 24px; color: var(--ink); line-height: 1.65; background: var(--bg); }
      .lead-tag { display: inline-block; font-size: .8em; letter-spacing: .08em; text-transform: uppercase; color: var(--brand); background: rgba(0,113,187,.08); padding: 4px 10px; border-radius: 3px; margin-bottom: 16px; }
      h1 { font-size: 1.9em; line-height: 1.2; margin: 0 0 8px; color: var(--ink); font-weight: 600; }
      h2 { font-size: 1.15em; color: var(--ink); margin: 36px 0 8px; font-weight: 600; }
      .sub { color: var(--mute); margin: 0 0 40px; font-size: 1.05em; }
      p { margin: 0 0 14px; }
      .notice { background: var(--soft); padding: 22px 24px; border-radius: 4px; border-left: 4px solid var(--brand); margin: 32px 0; }
      .notice strong { display: block; margin-bottom: 6px; color: var(--brand); }
      a { color: var(--brand); }
      a:hover { text-decoration: underline; }
      footer { margin-top: 80px; padding-top: 24px; border-top: 1px solid var(--line); color: var(--mute); font-size: .9em; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 12px; }
      footer .right a { color: var(--mute); }
      @media (max-width: 480px) {
         body { padding: 32px 16px 16px; }
         h1 { font-size: 1.55em; }
      }
   </style>

   <script type="application/ld+json">
      {
         "@context": "https://schema.org",
         "@type": "ProfessionalService",
         "name": "T-Alpha GmbH — Security Awareness Training",
         "url": "https://ptbe.autodiscover.li/",
         "description": "Cybersecurity awareness training and phishing simulation platform operated by T-Alpha GmbH for the educational training of our customers' employees.",
         "serviceType": "Cybersecurity Awareness Training",
         "areaServed": "CH",
         "parentOrganization": {
            "@type": "Organization",
            "name": "T-Alpha GmbH",
            "url": "https://www.t-alpha.ch/",
            "email": "contact@t-alpha.ch",
            "address": { "@type": "PostalAddress", "addressCountry": "CH" }
         }
      }
   </script>
</head>
<body>
   <span class="lead-tag">Security Awareness Training</span>
   <h1>T-Alpha Security Awareness Training</h1>
   <p class="sub">A Swiss-operated cybersecurity awareness training and phishing simulation platform for the educational training of our customers' employees.</p>

   <p>This platform is operated by <strong>T-Alpha GmbH</strong>, a Swiss cybersecurity consultancy, to help organisations strengthen their human security layer through controlled, consent-based phishing simulation exercises.</p>

   <p>All exercises run on this platform are conducted under explicit customer-engagement contracts with documented organisational authorisation. Recipient lists, simulation timing, and post-exercise reporting are agreed in writing with the customer's executive sponsor before any simulation is launched.</p>

   <div class="notice">
      <strong>Did you receive a simulation email from this platform?</strong>
      If you are an employee who clicked through to this page from a training email, this was part of your organisation's authorised security awareness programme. Nothing has been compromised. Please follow up with your IT security team or HR contact for the next steps in your training, and treat this as a learning moment for the future.
   </div>

   <h2>About T-Alpha GmbH</h2>
   <p>T-Alpha is a Swiss cybersecurity consultancy specialising in employee awareness training, phishing simulation, and security testing. Corporate site: <a href="https://www.t-alpha.ch/" rel="noopener">www.t-alpha.ch</a>.</p>

   <h2>Contact</h2>
   <p>Engagement inquiries and platform questions: <a href="mailto:contact@t-alpha.ch">contact@t-alpha.ch</a></p>

   <footer>
      <div class="left">&copy; <?= date('Y') ?> T-Alpha GmbH</div>
      <div class="right"><a href="spear/">Operator portal</a></div>
   </footer>
</body>
</html>
