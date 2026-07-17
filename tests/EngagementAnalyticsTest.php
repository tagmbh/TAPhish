<?php

namespace TAPhish\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Core engagement-analytics aggregation. Joins send/open (mailcamp_live),
 * click (webpage_visit) and credential/OTP (webform_submit) rows by rid into
 * the consolidated funnel + per-wave/cohort + recipient + repeat-offender views.
 */
final class EngagementAnalyticsTest extends TestCase
{
    /** Minimal fixture: 3 recipients in one K1/cohort-A campaign at various funnel depths. */
    private function fixture(): array
    {
        $sends = [
            ['campaign_id'=>'c1','rid'=>'r1','user_email'=>'a@x.test','user_name'=>'A A','sending_status'=>2,'mail_open_times'=>'["1"]','send_time'=>'100'],
            ['campaign_id'=>'c1','rid'=>'r2','user_email'=>'b@x.test','user_name'=>'B B','sending_status'=>2,'mail_open_times'=>'["1"]','send_time'=>'100'],
            ['campaign_id'=>'c1','rid'=>'r3','user_email'=>'c@x.test','user_name'=>'C C','sending_status'=>2,'mail_open_times'=>null,'send_time'=>'100'],
        ];
        $visits = [
            ['tracker_id'=>'t1','rid'=>'r1','time'=>'200'],
        ];
        $submits = [
            ['tracker_id'=>'t1','rid'=>'r1','page'=>'2','is_2fa_capture'=>1,'time'=>'300'],
            ['tracker_id'=>'t1','rid'=>'r2','page'=>'1','is_2fa_capture'=>0,'time'=>'250'],
        ];
        $campaignMap = ['c1'=>['wave'=>'K1','cohort'=>'A','slot'=>'S1']];
        return [$sends,$visits,$submits,$campaignMap];
    }

    public function testFunnelTotals(): void
    {
        [$s,$v,$w,$m] = $this->fixture();
        $r = taphish_analytics_build($s,$v,$w,$m);
        $f = $r['funnel'];
        // r1: delivered+opened+clicked(visit)+credentials(submit)+otp
        // r2: delivered+opened+clicked(submit)+credentials, no otp
        // r3: delivered only
        self::assertSame(3, $f['delivered']);
        self::assertSame(2, $f['opened']);
        self::assertSame(2, $f['clicked']);
        self::assertSame(2, $f['credentials']);
        self::assertSame(1, $f['otp']);
    }

    public function testFunnelRatesArePctOfDelivered(): void
    {
        [$s,$v,$w,$m] = $this->fixture();
        $rates = taphish_analytics_build($s,$v,$w,$m)['funnel']['rates'];
        self::assertEqualsWithDelta(66.7, $rates['opened'], 0.05);       // 2/3
        self::assertEqualsWithDelta(66.7, $rates['clicked'], 0.05);
        self::assertEqualsWithDelta(66.7, $rates['credentials'], 0.05);
        self::assertEqualsWithDelta(33.3, $rates['otp'], 0.05);          // 1/3
    }

    public function testByWaveAndByCohort(): void
    {
        [$s,$v,$w,$m] = $this->fixture();
        $r = taphish_analytics_build($s,$v,$w,$m);
        // whole fixture is one K1 / cohort-A campaign
        self::assertSame(3, $r['by_wave']['K1']['funnel']['delivered']);
        self::assertSame(1, $r['by_wave']['K1']['funnel']['otp']);
        self::assertSame(3, $r['by_cohort']['A']['funnel']['delivered']);
        self::assertSame(2, $r['by_cohort']['A']['funnel']['credentials']);
    }

    public function testEmptyDatasetZeroedNoDivByZero(): void
    {
        $r = taphish_analytics_build([],[],[],[]);
        self::assertSame(0, $r['funnel']['delivered']);
        self::assertSame(0.0, $r['funnel']['rates']['opened']);   // must not be NaN / division error
        self::assertSame([], $r['recipients']);
    }

    public function testRecipientDetailStages(): void
    {
        [$s,$v,$w,$m] = $this->fixture();
        $recs = taphish_analytics_build($s,$v,$w,$m)['recipients'];
        $byEmail = [];
        foreach ($recs as $r) { $byEmail[$r['email']] = $r; }
        self::assertTrue($byEmail['a@x.test']['otp']);              // r1 deepest
        self::assertSame('K1', $byEmail['a@x.test']['wave']);
        self::assertSame('A', $byEmail['a@x.test']['cohort']);
        self::assertTrue($byEmail['b@x.test']['credentials']);
        self::assertFalse($byEmail['b@x.test']['otp']);
        self::assertFalse($byEmail['c@x.test']['clicked']);        // r3 delivered only
        self::assertSame(200, $byEmail['a@x.test']['clicked_at']);  // earliest click evidence
    }

    public function testMultipleSubmitsPerRidCountOnce(): void
    {
        [$s,$v,$w,$m] = $this->fixture();
        $w[] = ['tracker_id'=>'t1','rid'=>'r1','page'=>'2','is_2fa_capture'=>1,'time'=>'305']; // duplicate submit for r1
        $f = taphish_analytics_build($s,$v,$w,$m)['funnel'];
        self::assertSame(2, $f['credentials']);   // still 2 distinct recipients, not 3
        self::assertSame(1, $f['otp']);
    }

    public function testOrphanSubmitUnknownRidIgnored(): void
    {
        [$s,$v,$w,$m] = $this->fixture();
        $w[] = ['tracker_id'=>'t1','rid'=>'ghost','page'=>'2','is_2fa_capture'=>1,'time'=>'999']; // no send row
        $f = taphish_analytics_build($s,$v,$w,$m)['funnel'];
        self::assertSame(3, $f['delivered']);     // phantom rid must not inflate anything
        self::assertSame(2, $f['credentials']);
    }

    public function testRepeatOffendersAcrossWaves(): void
    {
        // x@x.test clicked in K1 AND K3; y@x.test only K1
        $sends = [
            ['campaign_id'=>'c1','rid'=>'ra','user_email'=>'x@x.test','user_name'=>'X','sending_status'=>2,'mail_open_times'=>'["1"]','send_time'=>'100'],
            ['campaign_id'=>'c3','rid'=>'rb','user_email'=>'x@x.test','user_name'=>'X','sending_status'=>2,'mail_open_times'=>'["1"]','send_time'=>'400'],
            ['campaign_id'=>'c1','rid'=>'ry','user_email'=>'y@x.test','user_name'=>'Y','sending_status'=>2,'mail_open_times'=>'["1"]','send_time'=>'100'],
        ];
        $visits = [
            ['tracker_id'=>'t1','rid'=>'ra','time'=>'200'],
            ['tracker_id'=>'t3','rid'=>'rb','time'=>'500'],
            ['tracker_id'=>'t1','rid'=>'ry','time'=>'210'],
        ];
        $map = ['c1'=>['wave'=>'K1','cohort'=>'A','slot'=>'S1'],'c3'=>['wave'=>'K3','cohort'=>'A','slot'=>'S3']];
        $repeat = taphish_analytics_build($sends,$visits,[],$map)['repeat_offenders'];
        self::assertCount(1, $repeat);
        self::assertSame('x@x.test', $repeat[0]['email']);
        self::assertSame(['K1','K3'], $repeat[0]['waves']);
    }

    public function testTimelineEventsSortedByTime(): void
    {
        [$s,$v,$w,$m] = $this->fixture();
        $tl = taphish_analytics_build($s,$v,$w,$m)['timeline'];
        self::assertNotEmpty($tl);
        $times = array_column($tl, 'ts');
        $sorted = $times; sort($sorted);
        self::assertSame($sorted, $times);                         // ascending
        self::assertContains('click', array_column($tl, 'kind'));
    }
}
