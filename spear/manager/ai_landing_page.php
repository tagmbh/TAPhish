<?php
/**
 * AI-assisted landing-page generator.
 *
 * Operator types a prose description of the landing page they want; the
 * Anthropic API returns raw HTML which the JS layer drops into the existing
 * CodeMirror editor on /spear/sniperhost/LandingPage.
 *
 * Design calls that drive the layout of this module:
 *  - The Anthropic API key lives only in the operator's browser
 *    localStorage. The PHP backend proxies the call but never persists
 *    the key — mirrors the Phase 3.8 Hunter.io OSINT pattern.
 *  - Default model is Claude 3.5 Haiku: cheap, fast, more than adequate
 *    for HTML scaffolding. Operator can override per request.
 *  - The system prompt clamps the model to outputting ONLY raw HTML so
 *    the JS layer doesn't have to parse markdown fences or commentary.
 *  - For authorized red-team engagements only; the modal copy says so
 *    explicitly and the system prompt does NOT claim the page is for a
 *    real third party — that's the operator's responsibility to direct.
 *
 * Pure prompt construction + response extraction is exercised by
 * tests/AiLandingPageTest.php. The live HTTP call lives at the bottom of
 * this file.
 */

if (!function_exists('ai_landing_default_model')) {
    function ai_landing_default_model(): string
    {
        return 'claude-haiku-4-5-20251001';
    }
}

if (!function_exists('ai_landing_allowed_models')) {
    /**
     * Whitelist of Claude model ids the operator can pick. Keep it short;
     * easier to expand on demand than to defend against a typo.
     *
     * @return string[]
     */
    function ai_landing_allowed_models(): array
    {
        return [
            'claude-haiku-4-5-20251001',
            'claude-sonnet-4-6',
            'claude-opus-4-8',
            'claude-sonnet-4-5',
            'claude-opus-4-5',
        ];
    }
}

if (!function_exists('ai_landing_normalize_model')) {
    /**
     * @param mixed $raw
     */
    function ai_landing_normalize_model($raw): string
    {
        if (is_string($raw) && in_array($raw, ai_landing_allowed_models(), true)) {
            return $raw;
        }
        return ai_landing_default_model();
    }
}

if (!function_exists('ai_landing_is_valid_api_key')) {
    /**
     * Anthropic keys are prefixed `sk-ant-` and tend to run 90+ chars.
     * Reject anything obviously not a key before issuing the request.
     */
    function ai_landing_is_valid_api_key(string $key): bool
    {
        $k = trim($key);
        if (strlen($k) < 40 || strlen($k) > 200) {
            return false;
        }
        return str_starts_with($k, 'sk-ant-');
    }
}

if (!function_exists('ai_landing_build_system_prompt')) {
    /**
     * Pure: assemble the system prompt sent to Claude. Centralized so
     * tests can pin its shape and a tweak doesn't drift across the
     * codebase.
     */
    function ai_landing_build_system_prompt(): string
    {
        return <<<EOT
You are an HTML landing-page generator for authorized red-team phishing
simulations. The operator using you has written permission to run
simulated phishing engagements against the targets they describe.

Output rules — these are absolute:

1. Output ONLY raw HTML for a single self-contained page. No markdown
   code fences. No commentary before or after. No explanation of what
   you generated.
2. The HTML must start with `<!DOCTYPE html>` and contain `<html>`,
   `<head>`, and `<body>` tags.
3. Inline all CSS in a single `<style>` block in `<head>`. Do not link
   to external stylesheets or fonts.
4. Do not include any JavaScript. The operator wires their own tracker.
5. Forms should POST to "#" — the operator rewrites the action when
   they save.
6. Do not include real third-party brand names, logos, or trademarks
   unless the operator explicitly names the brand in their prompt.
7. The page should be visually plausible and functional but generic
   enough that the operator can adapt it without losing the structure.

If the prompt is empty, ambiguous, or asks for something out of scope
(non-HTML content, multiple pages, real malicious payloads), reply
with a minimal placeholder HTML page whose visible content is the
single line: "Provide a more specific description of the landing page
you want."
EOT;
    }
}

if (!function_exists('ai_landing_extract_html')) {
    /**
     * Pure: pull a plausible HTML document out of the model's response.
     *
     * Claude usually obeys the system prompt and returns raw HTML, but
     * occasionally wraps it in markdown fences or prepends a one-line
     * commentary. We:
     *  - strip ```html ... ``` or ``` ... ``` fences if present
     *  - cut everything before the first `<!DOCTYPE html>` if found
     *  - trim leading/trailing whitespace
     */
    function ai_landing_extract_html(string $raw): string
    {
        $s = trim($raw);
        if ($s === '') {
            return '';
        }
        // Strip markdown fences.
        if (preg_match('/^```(?:html)?\s*\n(.*?)\n```\s*$/si', $s, $m)) {
            $s = $m[1];
        }
        // Cut to the first <!DOCTYPE> if there's any prelude.
        $pos = stripos($s, '<!DOCTYPE');
        if ($pos !== false && $pos > 0) {
            $s = substr($s, $pos);
        }
        return trim($s);
    }
}

if (!function_exists('ai_landing_parse_response')) {
    /**
     * Reduce the Anthropic /v1/messages response shape to
     * { ok, html?, model?, usage?, err? } for the JS layer.
     *
     * @param mixed $raw
     * @return array{
     *   ok: bool,
     *   html?: string,
     *   model?: string,
     *   input_tokens?: int,
     *   output_tokens?: int,
     *   err?: string,
     * }
     */
    function ai_landing_parse_response($raw): array
    {
        if (is_string($raw)) {
            $decoded = json_decode($raw, true);
            if (!is_array($decoded)) {
                return ['ok' => false, 'err' => 'Anthropic returned non-JSON response'];
            }
            $raw = $decoded;
        }
        if (!is_array($raw)) {
            return ['ok' => false, 'err' => 'Anthropic response was not an object'];
        }
        // API-level error envelope.
        if (isset($raw['type']) && $raw['type'] === 'error') {
            $msg = $raw['error']['message'] ?? 'unknown error';
            return ['ok' => false, 'err' => 'Anthropic: ' . (string) $msg];
        }
        if (!isset($raw['content']) || !is_array($raw['content'])) {
            return ['ok' => false, 'err' => 'Anthropic response missing content array'];
        }
        $textParts = [];
        foreach ($raw['content'] as $block) {
            if (is_array($block) && ($block['type'] ?? null) === 'text' && isset($block['text'])) {
                $textParts[] = (string) $block['text'];
            }
        }
        if ($textParts === []) {
            return ['ok' => false, 'err' => 'Anthropic returned no text blocks'];
        }
        $html = ai_landing_extract_html(implode("\n", $textParts));
        if ($html === '') {
            return ['ok' => false, 'err' => 'Anthropic response empty after HTML extraction'];
        }
        return [
            'ok'            => true,
            'html'          => $html,
            'model'         => (string) ($raw['model'] ?? ''),
            'input_tokens'  => (int) ($raw['usage']['input_tokens'] ?? 0),
            'output_tokens' => (int) ($raw['usage']['output_tokens'] ?? 0),
        ];
    }
}

if (!function_exists('ai_landing_generate')) {
    /**
     * Live call to Anthropic /v1/messages. Returns the same shape as the
     * parser, plus ok=false on transport / validation errors.
     */
    function ai_landing_generate(
        string $userPrompt,
        string $apiKey,
        string $model = '',
        int $maxTokens = 4096
    ): array {
        if (!ai_landing_is_valid_api_key($apiKey)) {
            return ['ok' => false, 'err' => 'Invalid Anthropic API key format'];
        }
        $userPrompt = trim($userPrompt);
        if ($userPrompt === '') {
            return ['ok' => false, 'err' => 'Prompt is empty'];
        }
        if (strlen($userPrompt) > 4000) {
            return ['ok' => false, 'err' => 'Prompt is too long (>4000 chars)'];
        }
        if (!function_exists('curl_init')) {
            return ['ok' => false, 'err' => 'ext-curl not available on this PHP runtime'];
        }
        $model = ai_landing_normalize_model($model);
        $maxTokens = max(256, min(8192, $maxTokens));

        $body = json_encode([
            'model'       => $model,
            'max_tokens'  => $maxTokens,
            'system'      => ai_landing_build_system_prompt(),
            'messages'    => [['role' => 'user', 'content' => $userPrompt]],
            'temperature' => 0.7,
        ], JSON_UNESCAPED_SLASHES);
        if ($body === false) {
            return ['ok' => false, 'err' => 'Failed to encode request body'];
        }

        $ch = curl_init('https://api.anthropic.com/v1/messages');
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT        => 60,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_USERAGENT      => (defined('BRAND_PRODUCT_NAME') ? BRAND_PRODUCT_NAME : 'TAPhish') . '/ai-landing',
            CURLOPT_HTTPHEADER     => [
                'x-api-key: ' . $apiKey,
                'anthropic-version: 2023-06-01',
                'content-type: application/json',
                'accept: application/json',
            ],
        ]);
        $resp   = curl_exec($ch);
        $errno  = curl_errno($ch);
        $errstr = curl_error($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        if ($errno !== 0 || $resp === false) {
            return ['ok' => false, 'err' => 'cURL error ' . $errno . ': ' . $errstr];
        }
        $parsed = ai_landing_parse_response($resp);
        if (!$parsed['ok'] && $status >= 400) {
            $parsed['err'] = ($parsed['err'] ?? '') . ' (HTTP ' . $status . ')';
        }
        return $parsed;
    }
}
