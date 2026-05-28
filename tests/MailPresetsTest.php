<?php

namespace TAPhish\Tests;

use PHPUnit\Framework\TestCase;

final class MailPresetsTest extends TestCase
{
    public function testPresetsListIsNonEmptyAndShaped(): void
    {
        $presets = taphish_known_mail_presets();
        self::assertNotEmpty($presets);
        foreach ($presets as $p) {
            self::assertIsArray($p);
            self::assertArrayHasKey('name', $p);
            self::assertArrayHasKey('info', $p);
            self::assertArrayHasKey('content', $p);
            self::assertNotSame('', $p['name']);
        }
    }

    public function testPresetNamesAreUnique(): void
    {
        $names = array_column(taphish_known_mail_presets(), 'name');
        self::assertSame(count($names), count(array_unique($names)));
    }

    public function testEachPresetHasValidJsonInfoAndContent(): void
    {
        foreach (taphish_known_mail_presets() as $p) {
            $info = json_decode($p['info'], true);
            self::assertIsArray($info, "info must be JSON-decodable for {$p['name']}");
            self::assertArrayHasKey('dsn_type', $info);
            self::assertNotSame('', $info['dsn_type']);

            $content = json_decode($p['content'], true);
            self::assertIsArray($content, "content must be JSON-decodable for {$p['name']}");
            self::assertArrayHasKey('smtp', $content);
            self::assertArrayHasKey('value', $content['smtp']);
        }
    }

    public function testIncludesHostpointSslPreset(): void
    {
        $names = array_column(taphish_known_mail_presets(), 'name');
        self::assertContains('Hostpoint (hostpoint.ch) - SSL', $names);
        self::assertContains('Hostpoint (hostpoint.ch) - TLS', $names);
    }

    public function testIncludesInfomaniakPresets(): void
    {
        $names = array_column(taphish_known_mail_presets(), 'name');
        self::assertContains('Infomaniak (infomaniak.com) - SSL', $names);
        self::assertContains('Infomaniak (infomaniak.com) - TLS', $names);
    }

    public function testIncludesMicrosoft365CustomDomainPreset(): void
    {
        $names = array_column(taphish_known_mail_presets(), 'name');
        self::assertContains('Microsoft 365 - Custom domain (SMTP AUTH)', $names);
    }

    public function testInfomaniakSslPointsAtCorrectServer(): void
    {
        $ssl = $this->findPreset('Infomaniak (infomaniak.com) - SSL');
        $content = json_decode($ssl['content'], true);
        self::assertSame('mail.infomaniak.com:465', $content['smtp']['value']);
    }

    public function testInfomaniakTlsPointsAtCorrectServer(): void
    {
        $tls = $this->findPreset('Infomaniak (infomaniak.com) - TLS');
        $content = json_decode($tls['content'], true);
        self::assertSame('mail.infomaniak.com:587', $content['smtp']['value']);
    }

    public function testMicrosoft365CustomDomainPointsAtOffice365(): void
    {
        $m365 = $this->findPreset('Microsoft 365 - Custom domain (SMTP AUTH)');
        $content = json_decode($m365['content'], true);
        self::assertSame('smtp.office365.com:587', $content['smtp']['value']);
    }

    public function testHostpointSslPointsAtCorrectServerAndPort(): void
    {
        $ssl = $this->findPreset('Hostpoint (hostpoint.ch) - SSL');
        $content = json_decode($ssl['content'], true);
        self::assertSame('asmtp.mail.hostpoint.ch:465', $content['smtp']['value']);
        self::assertStringContainsString('imap.hostpoint.ch:993', $content['mailbox']['value']);
    }

    public function testHostpointTlsPointsAtCorrectServerAndPort(): void
    {
        $tls = $this->findPreset('Hostpoint (hostpoint.ch) - TLS');
        $content = json_decode($tls['content'], true);
        self::assertSame('asmtp.mail.hostpoint.ch:587', $content['smtp']['value']);
    }

    public function testHostpointPresetsUseCustomDsnType(): void
    {
        foreach (['Hostpoint (hostpoint.ch) - SSL', 'Hostpoint (hostpoint.ch) - TLS'] as $name) {
            $info = json_decode($this->findPreset($name)['info'], true);
            self::assertSame('custom', $info['dsn_type']);
        }
    }

    /** @return array{name: string, info: string, content: string} */
    private function findPreset(string $name): array
    {
        foreach (taphish_known_mail_presets() as $p) {
            if ($p['name'] === $name) {
                return $p;
            }
        }
        self::fail("Preset not found: $name");
    }
}
