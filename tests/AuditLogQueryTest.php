<?php

namespace TAPhish\Tests;

use PHPUnit\Framework\TestCase;

final class AuditLogQueryTest extends TestCase
{
    // ---- filter normalization -----------------------------------------

    public function testNormalizeRejectsUnknownKind(): void
    {
        $f = audit_log_normalize_filters(['kind' => 'NOPE']);
        self::assertNull($f['kind']);
    }

    public function testNormalizeAcceptsKnownKind(): void
    {
        foreach (['AUTH','CAMP','RECP','TMPL','SEND','SCAN','CAPT','ENGM','CLON','BEEF','SYS'] as $k) {
            $f = audit_log_normalize_filters(['kind' => $k]);
            self::assertSame($k, $f['kind'], "kind should pass: $k");
        }
    }

    public function testNormalizeRejectsUnknownSeverity(): void
    {
        self::assertNull(audit_log_normalize_filters(['severity' => 'critical'])['severity']);
    }

    public function testNormalizeAcceptsKnownSeverities(): void
    {
        foreach (['ok','warn','error'] as $s) {
            self::assertSame($s, audit_log_normalize_filters(['severity' => $s])['severity']);
        }
    }

    public function testNormalizeClampsLimit(): void
    {
        self::assertSame(1,   audit_log_normalize_filters(['limit' => 0])['limit']);
        self::assertSame(1,   audit_log_normalize_filters(['limit' => -5])['limit']);
        self::assertSame(500, audit_log_normalize_filters(['limit' => 5000])['limit']);
        self::assertSame(250, audit_log_normalize_filters(['limit' => 250])['limit']);
    }

    public function testNormalizeClampsOffset(): void
    {
        self::assertSame(0,   audit_log_normalize_filters(['offset' => -10])['offset']);
        self::assertSame(50,  audit_log_normalize_filters(['offset' => 50])['offset']);
    }

    public function testNormalizeRejectsMalformedDates(): void
    {
        $f = audit_log_normalize_filters(['date_from' => 'yesterday', 'date_to' => '2026/06/01']);
        self::assertNull($f['date_from']);
        self::assertNull($f['date_to']);
    }

    public function testNormalizeAcceptsIsoDate(): void
    {
        $f = audit_log_normalize_filters(['date_from' => '2026-06-01', 'date_to' => '2026-06-30']);
        self::assertSame('2026-06-01', $f['date_from']);
        self::assertSame('2026-06-30', $f['date_to']);
    }

    public function testNormalizeTrimsTextFields(): void
    {
        $f = audit_log_normalize_filters(['search' => '  hello  ', 'username' => '  admin  ']);
        self::assertSame('hello', $f['search']);
        self::assertSame('admin', $f['username']);
    }

    // ---- classifier filter --------------------------------------------

    public function testClassifierFilterKeepsRowsMatchingKind(): void
    {
        $rows = [
            ['log' => 'Account login'],
            ['log' => 'Campaign sent'],
            ['log' => 'Failed login'],
        ];
        $out = audit_log_apply_classifier_filter($rows, 'AUTH', null);
        self::assertCount(2, $out);
        foreach ($out as $r) self::assertSame('AUTH', $r['kind']);
    }

    public function testClassifierFilterKeepsRowsMatchingSeverity(): void
    {
        $rows = [
            ['log' => 'Account login'],         // AUTH/ok
            ['log' => 'Failed login'],          // AUTH/warn
            ['log' => 'Campaign sent'],         // CAMP/ok
        ];
        $out = audit_log_apply_classifier_filter($rows, null, 'warn');
        self::assertCount(1, $out);
        self::assertSame('warn', $out[0]['severity']);
    }

    public function testClassifierFilterAppliesBothFilters(): void
    {
        $rows = [
            ['log' => 'Account login'],
            ['log' => 'Failed login'],
            ['log' => 'Scanner hit on tracker XYZ'],
        ];
        $out = audit_log_apply_classifier_filter($rows, 'AUTH', 'warn');
        self::assertCount(1, $out);
        self::assertSame('AUTH', $out[0]['kind']);
        self::assertSame('warn', $out[0]['severity']);
    }

    public function testClassifierFilterPreservesOriginalFields(): void
    {
        $rows = [['log' => 'Account login', 'username' => 'admin', 'ip' => '1.2.3.4', 'date' => 'd']];
        $out = audit_log_apply_classifier_filter($rows, null, null);
        self::assertSame('admin',   $out[0]['username']);
        self::assertSame('1.2.3.4', $out[0]['ip']);
        self::assertSame('d',       $out[0]['date']);
        self::assertSame('AUTH',    $out[0]['kind']);
    }

    // ---- CSV ----------------------------------------------------------

    public function testCsvCarriesHeaderRow(): void
    {
        $csv = audit_log_rows_to_csv([]);
        self::assertStringContainsString('date,username,ip,kind,severity,log', $csv);
    }

    public function testCsvCarriesDataRows(): void
    {
        $csv = audit_log_rows_to_csv([[
            'date' => 'd', 'username' => 'admin', 'ip' => '1.2.3.4',
            'kind' => 'AUTH', 'severity' => 'ok', 'log' => 'Account login',
        ]]);
        self::assertStringContainsString('d,admin,1.2.3.4,AUTH,ok,"Account login"', $csv);
    }

    public function testCsvEscapesEmbeddedCommas(): void
    {
        $csv = audit_log_rows_to_csv([['log' => 'has, comma']]);
        self::assertStringContainsString('"has, comma"', $csv);
    }

    public function testCsvHandlesMissingFields(): void
    {
        $csv = audit_log_rows_to_csv([[]]);
        self::assertStringContainsString(",,,,,\n", $csv);
    }
}
