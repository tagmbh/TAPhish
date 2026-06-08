<?php

namespace TAPhish\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Pure-helper tests for spear/manager/keyword_filter.php (taphish_filter_keywords).
 *
 * Pins the merge-token substitution contract that every campaign mail and
 * cloned-landing rewrite passes through. A regression here would either leave
 * raw `{{FNAME}}` text in recipient inboxes or, worse, silently corrupt HTML
 * around an interpolated value.
 */
final class KeywordFilterTest extends TestCase
{
    public function testKnownTokensAreSubstituted(): void
    {
        $out = taphish_filter_keywords(
            'Hi {{FNAME}} ({{EMAIL}}), open {{TRACKINGURL}}',
            ['{{FNAME}}' => 'Ivan', '{{EMAIL}}' => 'i@t.example', '{{TRACKINGURL}}' => 'https://example/tk?rid=1']
        );
        self::assertSame('Hi Ivan (i@t.example), open https://example/tk?rid=1', $out);
    }

    public function testMissingTokenValueBecomesEmptyString(): void
    {
        // A partially-populated row must NOT leak the literal `{{EMAIL}}`
        // text into the recipient's inbox.
        $out = taphish_filter_keywords('Hello {{FNAME}}, your email is {{EMAIL}}', ['{{FNAME}}' => 'Ivan']);
        self::assertSame('Hello Ivan, your email is ', $out);
    }

    public function testUnknownTokensArePreserved(): void
    {
        // Anything that's not in the known list AND not a {{RND…}} token must
        // pass through unchanged — operators sometimes drop ad-hoc markers in
        // a template.
        $out = taphish_filter_keywords('Status: {{CUSTOM_MARKER}} ok', []);
        self::assertSame('Status: {{CUSTOM_MARKER}} ok', $out);
    }

    public function testTokenMatchingIsCaseInsensitive(): void
    {
        $out = taphish_filter_keywords('{{fname}} {{FNAME}} {{Fname}}', ['{{FNAME}}' => 'Ivan']);
        self::assertSame('Ivan Ivan Ivan', $out);
    }

    public function testRndTokenDefaultsToFiveChars(): void
    {
        $stub = static fn (int $n): string => 'X' . $n . 'X'; // shape: <length-marker>
        $out = taphish_filter_keywords('id={{RND}}', [], $stub);
        self::assertSame('id=X5X', $out);
    }

    public function testRndTokenUsesExplicitLength(): void
    {
        $stub = static fn (int $n): string => str_pad('', $n, '#');
        $out = taphish_filter_keywords('a={{RND10}} b={{RND3}}', [], $stub);
        self::assertSame('a=########## b=###', $out);
    }

    public function testRndTokenIsCaseInsensitive(): void
    {
        $stub = static fn (int $n): string => 'R' . $n;
        $out = taphish_filter_keywords('{{rnd}}-{{RND}}-{{Rnd7}}', [], $stub);
        // {{rnd}} and {{RND}} are the SAME token; {{Rnd7}} is distinct.
        // (Production behaviour: str_ireplace replaces ALL ci occurrences of
        // the first match with one value, so the same length yields the same
        // injected value across that pass — this regression-locks that.)
        self::assertSame('R5-R5-R7', $out);
    }

    public function testRndTokensAreReplacedIndependentlyByLength(): void
    {
        // Distinct lengths should each get their OWN substitution call.
        $log = [];
        $stub = function (int $n) use (&$log): string { $log[] = $n; return "<{$n}>"; };
        taphish_filter_keywords('{{RND2}}{{RND5}}{{RND}}', [], $stub);
        sort($log);
        self::assertSame([2, 5, 5], $log); // {{RND}} defaults to 5, same length as {{RND5}} but DIFFERENT distinct token
    }

    public function testRndWithZeroOrNegativeDigitsClampsToOne(): void
    {
        $stub = static fn (int $n): string => "L{$n}";
        // RND0 must not call randStr(0) — defensive clamp to 1.
        $out = taphish_filter_keywords('{{RND0}}', [], $stub);
        self::assertSame('L1', $out);
    }

    public function testValuesAreInjectedRawNoHtmlEscaping(): void
    {
        // INTENTIONAL contract: the engine does NOT html-escape substituted
        // values. Operator-controlled inputs may legitimately include HTML
        // (e.g. a styled paragraph in {{NOTES}}). The XSS surface that
        // matters is the cloned landing page, not the mail body.
        $name = '<b>"&amp;</b>';
        $out  = taphish_filter_keywords('Hi {{FNAME}}', ['{{FNAME}}' => $name]);
        self::assertSame("Hi {$name}", $out);
    }

    public function testRepeatedTokenAllOccurrencesReplaced(): void
    {
        $out = taphish_filter_keywords('{{FNAME}} {{FNAME}} {{FNAME}}!', ['{{FNAME}}' => 'X']);
        self::assertSame('X X X!', $out);
    }

    public function testEmptyContentReturnsEmpty(): void
    {
        self::assertSame('', taphish_filter_keywords('', ['{{FNAME}}' => 'X']));
    }

    public function testKnownTokenListPinned(): void
    {
        // The known-token list is a contract with the dispatcher (which sets
        // each value). Adding/removing tokens here without coordinating the
        // dispatcher would silently break campaigns. Lock it.
        self::assertSame(
            [
                '{{RID}}', '{{MID}}', '{{NAME}}', '{{FNAME}}', '{{LNAME}}',
                '{{NOTES}}', '{{EMAIL}}', '{{FROM}}', '{{TRACKINGURL}}',
                '{{TRACKER}}', '{{BASEURL}}', '{{MUSERNAME}}', '{{MDOMAIN}}',
            ],
            taphish_filter_keyword_known_tokens()
        );
    }

    public function testTrackingUrlSubstitutionEndToEnd(): void
    {
        // Reflects the exact bug seen in production today: the pretext seed
        // body contains <a href="{{TRACKINGURL}}"> and the dispatcher provides
        // the real tracker URL. Pin that this works.
        $body = '<p><a href="{{TRACKINGURL}}" style="bg">Keep my password</a></p>';
        $out = taphish_filter_keywords($body, ['{{TRACKINGURL}}' => 'https://ptbe.autodiscover.li/spear/tmail?mid=abc&rid=42']);
        self::assertStringContainsString('href="https://ptbe.autodiscover.li/spear/tmail?mid=abc&rid=42"', $out);
        self::assertStringNotContainsString('{{TRACKINGURL}}', $out);
    }
}
