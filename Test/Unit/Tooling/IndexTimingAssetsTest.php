<?php

declare(strict_types=1);

namespace MageOS\AdvancedReport\Test\Unit\Tooling;

use PHPUnit\Framework\TestCase;

final class IndexTimingAssetsTest extends TestCase
{
    private const string MODULE_ROOT = __DIR__ . '/../../..';

    protected function setUp(): void
    {
        if (!is_dir(self::MODULE_ROOT . '/dev/benchmark')) {
            self::markTestSkipped('dev/benchmark is export-ignored: not part of the installed package.');
        }
    }

    public function testItMeasuresIndexBuildTimeOnTableCopiesWithoutAlteringNativeTables(): void
    {
        $sql = (string) file_get_contents(self::MODULE_ROOT . '/dev/benchmark/index-timing.sql');
        $xml = simplexml_load_string((string) file_get_contents(self::MODULE_ROOT . '/etc/db_schema.xml'));
        self::assertNotFalse($xml);

        // Only the copies are ever altered, dropped or filled.
        self::assertSame(
            [],
            array_values(array_filter(
                $this->tablesOf('/\b(?:ALTER\s+TABLE|DROP\s+TABLE(?:\s+IF\s+EXISTS)?|INSERT\s+INTO)\s+`?(\w+)`?/i', $sql),
                static fn (string $table): bool => !str_starts_with($table, 'mageos_ar_idx_copy')
            ))
        );
        self::assertStringContainsString('CREATE TABLE mageos_ar_idx_copy', $sql);
        self::assertStringContainsString('LIKE sales_order', $sql);
        self::assertStringContainsString('LIKE sales_order_item', $sql);
        self::assertMatchesRegularExpression('/DROP TABLE IF EXISTS mageos_ar_idx_copy\w*;\s*DROP TABLE IF EXISTS/', $sql);

        // Copied module indexes are dropped before each one is timed with an online ADD INDEX.
        foreach ($xml->xpath('//table[@resource="sales"]/index') ?: [] as $index) {
            $name = (string) $index['referenceId'];
            $dropAt = strpos($sql, 'DROP INDEX ' . $name);
            $addAt = strpos($sql, 'ADD INDEX ' . $name);
            self::assertNotFalse($dropAt, $name . ' drop');
            self::assertNotFalse($addAt, $name . ' add');
            self::assertLessThan($addAt, $dropAt, $name);
        }
        self::assertStringContainsString('ALGORITHM=INPLACE, LOCK=NONE', $sql);
        self::assertStringContainsString('TIMESTAMPDIFF(MICROSECOND', $sql);
    }

    /**
     * @return string[]
     */
    private function tablesOf(string $pattern, string $sql): array
    {
        preg_match_all($pattern, $sql, $matches);

        return $matches[1];
    }
}
