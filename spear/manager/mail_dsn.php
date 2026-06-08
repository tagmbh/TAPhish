<?php
/**
 * Symfony Mailer DSN composer — pure helper.
 *
 * Extracted from common_functions.php::getMailerDSN() (which now delegates here)
 * so the per-provider DSN shape can be pinned by unit tests. The function takes
 * username + password as ALREADY URL-ENCODED strings — that escaping is the
 * caller's job (shootMail() wraps both in urlencode()) and stays the operator-
 * facing contract: a raw `:` or `@` in a plaintext password would otherwise
 * confuse Symfony Mailer's DSN parser and either misauth or leak the secret as
 * a hostname.
 *
 * Provider DSN shapes (verified against Symfony Mailer ^6 docs):
 *   amazon_ses          ses+smtp://USER:PASS@default
 *   gmail               gmail+smtp://USER:PASS@default
 *   mailchimp_mandrill  mandrill+smtp://USER:PASS@default
 *   mailgun             mailgun+smtp://USER:PASS@default
 *   mailjet             mailjet+smtp://ACCESS_KEY:SECRET_KEY@default
 *   postmark            postmark+smtp://ID@default            (password-as-token only)
 *   sendgrid            sendgrid+smtp://KEY@default           (password-as-token only)
 *   sendinblue          sendinblue+smtp://USER:PASS@default
 *   mailpace            mailpace+api://API_TOKEN@default      (password-as-token only)
 *   default / unknown   smtp://USER:PASS@HOST                 (custom SMTP relay — e.g. Hostpoint asmtp.mail.hostpoint.ch:587)
 */

if (!function_exists('taphish_mailer_dsn')) {
    /**
     * @param string $dsn_type        provider key (case-insensitive); unknown → custom SMTP
     * @param string $sender_username already-urlencoded SMTP user (used as DSN user)
     * @param string $sender_pwd      already-urlencoded SMTP password / API key (used as DSN password — or as the only token for postmark/sendgrid/mailpace)
     * @param string $smtp_server     host:port for the default (custom-SMTP) branch only; ignored for managed providers
     * @param int    $verify_peer     0|1 — emitted as ?verify_peer=N on every branch
     */
    function taphish_mailer_dsn(string $dsn_type, string $sender_username, string $sender_pwd, string $smtp_server, int $verify_peer = 0): string
    {
        $type = strtolower($dsn_type);
        $q = '?verify_peer=' . $verify_peer;
        switch ($type) {
            case 'amazon_ses':         return 'ses+smtp://'        . $sender_username . ':' . $sender_pwd . '@default' . $q;
            case 'gmail':              return 'gmail+smtp://'      . $sender_username . ':' . $sender_pwd . '@default' . $q;
            case 'mailchimp_mandrill': return 'mandrill+smtp://'   . $sender_username . ':' . $sender_pwd . '@default' . $q;
            case 'mailgun':            return 'mailgun+smtp://'    . $sender_username . ':' . $sender_pwd . '@default' . $q;
            case 'mailjet':            return 'mailjet+smtp://'    . $sender_username . ':' . $sender_pwd . '@default' . $q;
            case 'postmark':           return 'postmark+smtp://'   . $sender_pwd      . '@default' . $q;
            case 'sendgrid':           return 'sendgrid+smtp://'   . $sender_pwd      . '@default' . $q;
            case 'sendinblue':         return 'sendinblue+smtp://' . $sender_username . ':' . $sender_pwd . '@default' . $q;
            case 'mailpace':           return 'mailpace+api://'    . $sender_pwd      . '@default' . $q;
            default:                   return 'smtp://'            . $sender_username . ':' . $sender_pwd . '@' . $smtp_server . $q;
        }
    }
}
