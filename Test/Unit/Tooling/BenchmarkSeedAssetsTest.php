<?php

declare(strict_types=1);

namespace MageOS\AdvancedReport\Test\Unit\Tooling;

use DOMDocument;
use DOMXPath;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class BenchmarkSeedAssetsTest extends TestCase
{
    private const string BENCHMARK_DIR = __DIR__ . '/../../../dev/benchmark';

    protected function setUp(): void
    {
        if (!is_dir(self::BENCHMARK_DIR)) {
            self::markTestSkipped('dev/benchmark is export-ignored: not part of the installed package.');
        }
    }

    /**
     * @return array<string, array{string, int}>
     */
    public static function profileOrdersProvider(): array
    {
        return [
            'small' => ['small', 1000],
            'medium' => ['medium', 50000],
            'large' => ['large', 500000],
        ];
    }

    /**
     * @return array<string, array{string}>
     */
    public static function profileProvider(): array
    {
        return [
            'small' => ['small'],
            'medium' => ['medium'],
            'large' => ['large'],
        ];
    }

    #[DataProvider('profileOrdersProvider')]
    public function testItShipsSmallMediumAndLargeProfilesWith1000And50000And500000Orders(
        string $profile,
        int $orders
    ): void {
        self::assertSame((string) $orders, $this->text($profile, '/config/profile/orders'));
    }

    #[DataProvider('profileProvider')]
    public function testItKeepsEveryProfileValidAgainstTheToolkitProfileStructure(string $profile): void
    {
        foreach (['admin_users', 'websites', 'store_views', 'simple_products', 'customers', 'orders'] as $node) {
            self::assertSame(
                1,
                $this->nodeCount($profile, '/config/profile/' . $node),
                $node . ' missing in ' . $profile
            );
            self::assertMatchesRegularExpression(
                '/^\d+$/',
                (string) $this->text($profile, '/config/profile/' . $node)
            );
        }
        self::assertSame(1, $this->nodeCount($profile, '/config/profile/sources'));
        self::assertSame(6, $this->nodeCount($profile, '/config/profile/*[local-name()="include"]'));
    }

    #[DataProvider('profileProvider')]
    public function testItShipsProfilesWithoutConfigOverridesOrIndexerModeChanges(string $profile): void
    {
        self::assertSame(0, $this->nodeCount($profile, '//configs'));
        self::assertSame(0, $this->nodeCount($profile, '//indexers'));
        self::assertSame('1', $this->text($profile, '/config/profile/admin_users'));
    }

    public function testItSpreadsOrderDatesDeterministicallyWithoutRandomFunctions(): void
    {
        $sql = $this->spreadSql();

        self::assertStringNotContainsStringIgnoringCase('RAND(', $sql);
        self::assertStringContainsString('SET @anchor = CURDATE();', $sql);
        self::assertStringContainsString('entity_id * 7919', $sql);
    }

    public function testItAppliesTheSameCreatedAtToOrdersItemsAndTheOrderGrid(): void
    {
        $sql = $this->spreadSql();

        self::assertStringContainsString('UPDATE sales_order' . "\n" . 'SET created_at', $sql);
        self::assertMatchesRegularExpression(
            '/UPDATE sales_order_item i\s+INNER JOIN sales_order o ON o\.entity_id = i\.order_id\s+SET i\.created_at = o\.created_at/',
            $sql
        );
        self::assertMatchesRegularExpression(
            '/UPDATE sales_order_grid g\s+INNER JOIN sales_order o ON o\.entity_id = g\.entity_id\s+SET g\.created_at = o\.created_at/',
            $sql
        );
    }

    public function testItAssignsRegisteredRepeatCustomersToADeterministicShareOfTheOrders(): void
    {
        $sql = $this->spreadSql();

        self::assertStringContainsString('customer_entity', $sql);
        self::assertStringContainsString('o.entity_id MOD @customer_count', $sql);
        self::assertStringContainsString('WHERE o.entity_id MOD 10 < 6', $sql);
        self::assertStringContainsString('o.customer_is_guest = 0', $sql);
        self::assertStringContainsString('g.customer_id = o.customer_id', $sql);
    }

    private function spreadSql(): string
    {
        return (string) file_get_contents(self::BENCHMARK_DIR . '/spread-orders.sql');
    }

    private function text(string $profile, string $xpath): string
    {
        return (string) $this->xpath($profile)->evaluate('string(' . $xpath . ')');
    }

    private function nodeCount(string $profile, string $xpath): int
    {
        return (int) $this->xpath($profile)->evaluate('count(' . $xpath . ')');
    }

    /**
     * Parsed without XInclude processing: the module's own vendor has no setup/ directory.
     */
    private function xpath(string $profile): DOMXPath
    {
        $document = new DOMDocument();
        self::assertTrue($document->load(self::BENCHMARK_DIR . '/profiles/' . $profile . '.xml'));

        return new DOMXPath($document);
    }
}
