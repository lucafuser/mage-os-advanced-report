<?php

declare(strict_types=1);

namespace MageOS\AdvancedReport\Test\Integration\Catalog;

use Magento\Catalog\Test\Fixture\Attribute;
use Magento\Catalog\Test\Fixture\Product;
use Magento\Eav\Test\Fixture\AttributeOption;
use Magento\Framework\App\Config\MutableScopeConfigInterface;
use Magento\InventoryApi\Test\Fixture\DeleteSourceItems;
use Magento\InventoryApi\Test\Fixture\SourceItems;
use Magento\TestFramework\Fixture\AppArea;
use Magento\TestFramework\Fixture\AppIsolation;
use Magento\TestFramework\Fixture\DataFixture;
use Magento\TestFramework\Fixture\DbIsolation;
use Magento\TestFramework\Helper\Bootstrap;
use MageOS\AdvancedReport\Model\Definition\DefinitionRegistry;
use MageOS\AdvancedReport\Test\Integration\Fixture\Source;
use MageOS\AdvancedReport\Test\Integration\Helper\ReportTestRunner;
use PHPUnit\Framework\TestCase;

/**
 * Configured report attribute (`ar_aj_shade`, a select attribute in the default set) on datasets with and without a `child` join.
 *
 * Inputs: option "ar-aj-red" of `color`; product ar-aj-a (color = that option) with 7 units in the unlinked enabled
 * source ar-aj-w1 (default-source item deleted).
 *
 * Expected values:
 *  | check                                | value                                              |
 *  | definition registry                  | builds; catalog_stock exposes the attr_ar_aj_shade dim   |
 *  | catalog_stock qty by attr_ar_aj_shade      | one row, raw option id => 7               |
 */
#[
    DbIsolation(true),
    AppIsolation(true),
    AppArea('adminhtml'),
    DataFixture(
        Attribute::class,
        ['attribute_code' => 'ar_aj_shade', 'frontend_input' => 'select', 'backend_type' => 'int'],
        'shade'
    ),
    DataFixture(
        AttributeOption::class,
        ['entity_type' => 4, 'attribute_code' => '$shade.attribute_code$', 'label' => 'ar-aj-red'],
        'red'
    ),
    DataFixture(Product::class, ['sku' => 'ar-aj-a', 'ar_aj_shade' => '$red.value$'], 'productA'),
    DataFixture(DeleteSourceItems::class, [['sku' => 'ar-aj-a', 'source_code' => 'default']]),
    DataFixture(Source::class, ['source_code' => 'ar-aj-w1', 'enabled' => true]),
    DataFixture(SourceItems::class, [['sku' => 'ar-aj-a', 'source_code' => 'ar-aj-w1', 'quantity' => 7, 'status' => 1]]),
]
final class ReportAttributeJoinTest extends TestCase
{
    private ReportTestRunner $runner;

    protected function setUp(): void
    {
        Bootstrap::getObjectManager()->get(MutableScopeConfigInterface::class)
            ->setValue('mageos_advancedreport/catalog/report_attributes', 'ar_aj_shade');
        $this->runner = new ReportTestRunner();
        $this->runner->login();
    }

    protected function tearDown(): void
    {
        $this->runner->logout();
    }

    public function testItBuildsTheDefinitionRegistryWithoutErrorsWhenAReportAttributeIsConfigured(): void
    {
        $registry = Bootstrap::getObjectManager()->get(DefinitionRegistry::class);

        $datasets = $registry->getDatasets();

        $this->assertArrayHasKey('catalog_stock', $datasets);
        $this->assertArrayHasKey('attr_ar_aj_shade', $datasets['catalog_stock']->dimensions);
    }

    public function testItGroupsCatalogStockByAConfiguredReportAttribute(): void
    {
        $result = $this->runner->run(
            'catalog_stock',
            ['qty_on_hand'],
            [['code' => 'attr_ar_aj_shade', 'granularity' => null]],
            [['field' => 'sku', 'operator' => 'in', 'value' => ['ar-aj-a']]]
        );

        $rows = $this->runner->rows($result);
        $this->assertCount(1, $rows);
        $this->assertEqualsWithDelta(7.0, (float) $rows[0]['qty_on_hand'], 0.0001);
        // raw option id (labels are resolved at presentation)
        $this->assertMatchesRegularExpression('/^\d+$/', (string) $rows[0]['attr_ar_aj_shade']);
    }
}
