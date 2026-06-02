<?php

namespace TAPhish\Tests;

use PHPUnit\Framework\TestCase;

final class BrandConfigTest extends TestCase
{
    /**
     * @dataProvider brandConstants
     */
    public function testBrandConstantsAreDefinedAndNonEmpty(string $name): void
    {
        self::assertTrue(defined($name), "Brand constant {$name} is not defined");
        $value = constant($name);
        self::assertIsString($value, "Brand constant {$name} is not a string");
        self::assertNotSame('', trim($value), "Brand constant {$name} is empty");
    }

    public static function brandConstants(): array
    {
        return [
            ['BRAND_PRODUCT_NAME'],
            ['BRAND_COMPANY'],
            ['BRAND_TAGLINE'],
            ['BRAND_PRODUCT_VERSION'],
            ['BRAND_COPYRIGHT_YEAR'],
            ['BRAND_PRIMARY_COLOR'],
            ['BRAND_LOGO_ICON'],
            ['BRAND_LOGO_TEXT'],
            ['BRAND_FAVICON'],
        ];
    }

    public function testBrandHelpersReturnExpectedShape(): void
    {
        self::assertStringContainsString(BRAND_PRODUCT_NAME, brand_title());
        self::assertStringContainsString(BRAND_TAGLINE, brand_title());
        self::assertStringContainsString(BRAND_COMPANY, brand_copyright());
        self::assertSame(BRAND_PRODUCT_VERSION, brand_product_version());
    }

    public function testCopyrightLinksToOfficialCompanyUrl(): void
    {
        $cr = brand_copyright();
        self::assertStringContainsString(BRAND_COMPANY_URL, $cr);
        self::assertStringContainsString('target="_blank"', $cr);
        self::assertStringContainsString('rel="noopener noreferrer"', $cr);
        self::assertStringContainsString(BRAND_PRODUCT_NAME, $cr);
        self::assertStringContainsString(BRAND_PRODUCT_VERSION, $cr);
    }

    public function testFreshInstallStillCarriesTAPhishDefaults(): void
    {
        // Catches accidental rebrand drift in committed defaults.
        self::assertSame('TAPhish', BRAND_PRODUCT_NAME);
        self::assertSame('T-Alpha GmbH', BRAND_COMPANY);
        self::assertSame('https://www.t-alpha.ch', BRAND_COMPANY_URL);
    }
}
