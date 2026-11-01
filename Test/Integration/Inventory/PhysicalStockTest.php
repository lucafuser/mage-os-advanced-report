<?php

declare(strict_types=1);

namespace MageOS\AdvancedReport\Test\Integration\Inventory;

use Magento\Catalog\Test\Fixture\Product;
use Magento\InventoryApi\Test\Fixture\DeleteSourceItems;
use Magento\InventoryApi\Test\Fixture\SourceItems;
use Magento\TestFramework\Fixture\AppArea;
use Magento\TestFramework\Fixture\AppIsolation;
use Magento\TestFramework\Fixture\DataFixture;
use Magento\TestFramework\Fixture\DbIsolation;
use MageOS\AdvancedReport\Test\Integration\Fixture\Source;
use MageOS\AdvancedReport\Test\Integration\Helper\ReportTestRunner;
use PHPUnit\Framework\TestCase;

/**
 * Physical stock (`catalog_stock`, MSI) across enabled and disabled sources. Sources are not linked to any stock
 * (linking would create `inventory_stock_<id>` DDL and leak fixtures out of DbIsolation).
 *
 * Inputs: sources ar-ps-w1 and ar-ps-w2 (enabled), ar-ps-off (disabled); the default-source item of each product is
 * deleted.
 *  - ar-ps-a: price 20, cost 8; quantity 10 (w1), 5 (w2), 100 (off)
 *  - ar-ps-b: price 50, no cost; quantity 3 (w1)
 *
 * Expected values (disabled source ignored; a status-0 source item still counts its physical quantity):
 *  | report                                | value                                      |
 *  | qty_on_hand total (both SKUs)         | 18 = 10 + 5 + 3                            |
 *  | qty_on_hand by source_code            | w1 => 13 = 10 + 3, w2 => 5, no ar-ps-off   |
 *  | stock_value_cost total                | 120 = (10 + 5) * 8 + 3 * 0                 |
 *  | stock_value_retail total              | 450 = (10 + 5) * 20 + 3 * 50               |
 *  | never_sold preset qty by sku          | ar-ps-a => 15, ar-ps-b => 3                |
 * Cost basis: the global (store 0) `cost` attribute multiplied by the physical quantity, per source item.
 */
#[
    DbIsolation(true),
    AppIsolation(true),
    AppArea('adminhtml'),
    DataFixture(Product::class, ['sku' => 'ar-ps-a', 'price' => 20, 'cost' => 8], 'productA'),
    DataFixture(Product::class, ['sku' => 'ar-ps-b', 'price' => 50], 'productB'),
    DataFixture(
        DeleteSourceItems::class,
        [['sku' => 'ar-ps-a', 'source_code' => 'default'], ['sku' => 'ar-ps-b', 'source_code' => 'default']]
    ),
    DataFixture(Source::class, ['source_code' => 'ar-ps-w1', 'enabled' => true]),
    DataFixture(Source::class, ['source_code' => 'ar-ps-w2', 'enabled' => true]),
    DataFixture(Source::class, ['source_code' => 'ar-ps-off', 'enabled' => false]),
    DataFixture(
        SourceItems::class,
        [
            ['sku' => 'ar-ps-a', 'source_code' => 'ar-ps-w1', 'quantity' => 10, 'status' => 1],
            ['sku' => 'ar-ps-a', 'source_code' => 'ar-ps-w2', 'quantity' => 5, 'status' => 1],
            ['sku' => 'ar-ps-a', 'source_code' => 'ar-ps-off', 'quantity' => 100, 'status' => 1],
            ['sku' => 'ar-ps-b', 'source_code' => 'ar-ps-w1', 'quantity' => 3, 'status' => 1],
        ]
    ),
]
final class PhysicalStockTest extends TestCase
{
    private const array SKU_FILTER = [['field' => 'sku', 'operator' => 'in', 'value' => ['ar-ps-a', 'ar-ps-b']]];

    private ReportTestRunner $runner;

    protected function setUp(): void
    {
        $this->runner = new ReportTestRunner();
        $this->runner->login();
    }

    protected function tearDown(): void
    {
        $this->runner->logout();
    }

    public function testItSumsPhysicalQuantityAcrossEnabledSourcesOnly(): void
    {
        $result = $this->runner->run('catalog_stock', ['qty_on_hand'], [], self::SKU_FILTER);

        // 10 + 5 + 3; the 100 units of the disabled source are ignored
        $this->assertEqualsWithDelta(18.0, $this->runner->totals($result)['qty_on_hand'], 0.0001);
    }

    public function testItGroupsQuantityBySourceCode(): void
    {
        $result = $this->runner->run(
            'catalog_stock',
            ['qty_on_hand'],
            [['code' => 'source_code', 'granularity' => null]],
            self::SKU_FILTER
        );

        $bySource = [];
        foreach ($this->runner->rows($result) as $row) {
            $bySource[$row['source_code']] = (float) $row['qty_on_hand'];
        }
        ksort($bySource);

        $this->assertEquals(['ar-ps-w1' => 13.0, 'ar-ps-w2' => 5.0], $bySource);
    }

    public function testItValuesStockAtCostAsQuantityTimesGlobalCostAttribute(): void
    {
        $result = $this->runner->run('catalog_stock', ['stock_value_cost'], [], self::SKU_FILTER);

        // (10 + 5) * 8 = 120; ar-ps-b has no cost
        $this->assertEqualsWithDelta(120.0, $this->runner->totals($result)['stock_value_cost'], 0.0001);
    }

    public function testItValuesStockWithoutCostAsZeroCostValueAndFullRetailValue(): void
    {
        $result = $this->runner->run(
            'catalog_stock',
            ['stock_value_cost', 'stock_value_retail'],
            [['code' => 'sku', 'granularity' => null]],
            [['field' => 'sku', 'operator' => 'in', 'value' => ['ar-ps-b']]]
        );

        $rows = $this->runner->rows($result);
        $this->assertCount(1, $rows);
        // 3 * 0 and 3 * 50
        $this->assertEqualsWithDelta(0.0, (float) $rows[0]['stock_value_cost'], 0.0001);
        $this->assertEqualsWithDelta(150.0, (float) $rows[0]['stock_value_retail'], 0.0001);
    }

    public function testItExcludesDisabledSourcesFromStockPresets(): void
    {
        $result = $this->runner->runPreset('never_sold', '2026-01-01', '2026-01-31');

        $bySku = [];
        foreach ($this->runner->rows($result) as $row) {
            if (str_starts_with((string) $row['sku'], 'ar-ps-')) {
                $bySku[$row['sku']] = (float) $row['qty_on_hand'];
            }
        }
        ksort($bySku);

        // ar-ps-a: 10 + 5 (disabled 100 excluded); ar-ps-b: 3
        $this->assertEquals(['ar-ps-a' => 15.0, 'ar-ps-b' => 3.0], $bySku);
    }
}
