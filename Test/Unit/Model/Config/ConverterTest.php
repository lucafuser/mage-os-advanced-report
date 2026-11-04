<?php

declare(strict_types=1);

namespace MageOS\AdvancedReport\Test\Unit\Model\Config;

use MageOS\AdvancedReport\Model\Config\Converter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Converter::class)]
final class ConverterTest extends TestCase
{
    public function testItConvertsADatasetWithFieldsJoinsMetricsAndDimensionsIntoAKeyedArray(): void
    {
        $result = (new Converter())->convert($this->loadFixture('dataset_fields_joins_metrics_dimensions.xml'));

        self::assertSame(
            [
                'code' => 'sales_orders',
                'label' => 'Orders',
                'aclResource' => 'MageOS_AdvancedReport::sales',
                'area' => 'sales',
                'dateless' => false,
                'sources' => [
                    'mysql' => ['name' => 'mysql', 'table' => 'sales_order', 'alias' => 'main', 'provider' => null],
                ],
                'implicitFilters' => [],
                'joins' => [
                    'payment' => [
                        'code' => 'payment',
                        'table' => 'sales_order_payment',
                        'alias' => 'payment',
                        'type' => 'left',
                        'condition' => 'payment.parent_id = main.entity_id',
                        'requires' => [],
                        'cardinality' => 'one',
                    ],
                ],
                'fields' => [
                    'grand_total' => [
                        'code' => 'grand_total',
                        'label' => 'Grand Total',
                        'type' => 'money',
                        'description' => null,
                        'acl' => null,
                        'sourceMappings' => [
                            'mysql' => [
                                'expression' => 'main.base_grand_total',
                                'expressionProvider' => null,
                                'joins' => [],
                            ],
                        ],
                    ],
                ],
                'metrics' => [
                    'orders' => [
                        'code' => 'orders',
                        'label' => 'Orders',
                        'description' => 'Number of orders',
                        'format' => 'integer',
                        'hidden' => false,
                        'lowerIsBetter' => false,
                        'aggregate' => 'count',
                        'fieldCode' => 'grand_total',
                        'conditionFieldCode' => null,
                        'formula' => null,
                        'virtual' => false,
                    ],
                ],
                'dimensions' => [
                    'payment_method' => [
                        'code' => 'payment_method',
                        'label' => 'Payment method',
                        'fieldCode' => 'payment_method',
                        'granularities' => [],
                        'labelResolver' => null,
                        'nonAdditive' => false,
                    ],
                ],
            ],
            $result['datasets']['sales_orders']
        );
    }

    public function testItConvertsJoinCardinalityManyFromXml(): void
    {
        $result = (new Converter())->convert($this->loadFixture('join_cardinality_many.xml'));

        self::assertSame('many', $result['datasets']['sales_items']['joins']['category']['cardinality']);
    }

    public function testItParsesAnOptionalAclAttributeOnFields(): void
    {
        $result = (new Converter())->convert($this->loadFixture('field_acl.xml'));

        $fields = $result['datasets']['sales_orders']['fields'];
        self::assertSame('Magento_Sales::actions_view', $fields['birth_year']['acl']);
        self::assertNull($fields['status']['acl']);
    }

    public function testItDefaultsJoinCardinalityToOneWhenAttributeIsMissing(): void
    {
        $result = (new Converter())->convert($this->loadFixture('dataset_fields_joins_metrics_dimensions.xml'));

        self::assertSame('one', $result['datasets']['sales_orders']['joins']['payment']['cardinality']);
    }

    public function testItConvertsPresetsWithMetricsDimensionsFiltersSortAndCompareSettings(): void
    {
        $result = (new Converter())->convert($this->loadFixture('preset.xml'));

        self::assertSame(
            [
                'code' => 'sales_overview',
                'area' => 'sales',
                'dataset' => 'sales_orders',
                'label' => 'Sales overview',
                'description' => '',
                'sources' => ['mysql'],
                'presetQuery' => null,
                'compareTo' => 'previous_period',
                'dateRange' => 'last_30_days',
                'alternateDimension' => null,
                'metrics' => ['orders'],
                'dimensions' => [
                    ['code' => 'order_date', 'granularity' => 'day'],
                ],
                'filters' => [
                    ['field' => 'store_id', 'operator' => 'in', 'value' => ['1', '2']],
                ],
                'sort' => [
                    ['metric' => 'orders', 'direction' => 'desc'],
                ],
                'chart' => null,
            ],
            $result['presets']['sales_overview']
        );
    }

    /**
     * @return void
     */
    public function testItConvertsThePresetChartWithoutMixingItsMetricsAndDimensionsWithThePresetOnes(): void
    {
        $preset = (new Converter())->convert($this->loadFixture('preset_chart.xml'))['presets']['recent_carts'];

        self::assertSame(['cart_value', 'items_in_carts'], $preset['metrics']);
        self::assertSame([['code' => 'cart_id', 'granularity' => null]], $preset['dimensions']);
        self::assertSame(
            [
                'type' => 'bar',
                'metrics' => ['cart_value', 'carts'],
                'dimensions' => [['code' => 'activity_date', 'granularity' => 'day']],
                'sortBy' => 'activity_date',
                'sortDirection' => 'asc',
            ],
            $preset['chart']
        );
    }

    public function testItConvertsPresetDescriptionAttribute(): void
    {
        $result = (new Converter())->convert($this->loadFixture('preset_description.xml'));

        self::assertSame('Revenue and orders over time', $result['presets']['sales_overview']['description']);
    }

    public function testItDefaultsPresetDescriptionToEmptyStringWhenAttributeIsMissing(): void
    {
        $result = (new Converter())->convert($this->loadFixture('preset.xml'));

        self::assertSame('', $result['presets']['sales_overview']['description']);
    }

    public function testItSplitsCommaSeparatedAttributesIntoArraysAndCastsBooleans(): void
    {
        $result = (new Converter())->convert($this->loadFixture('comma_lists_and_booleans.xml'));

        $dataset = $result['datasets']['catalog_stock'];
        self::assertTrue($dataset['dateless']);
        self::assertSame(['product', 'website'], $dataset['joins']['stock_item']['requires']);
        self::assertSame(['stock_item', 'website'], $dataset['fields']['qty']['sourceMappings']['mysql']['joins']);
        self::assertTrue($dataset['metrics']['low_stock']['hidden']);
        self::assertTrue($dataset['metrics']['low_stock']['virtual']);
        self::assertTrue($dataset['metrics']['low_stock']['lowerIsBetter']);
        self::assertTrue($dataset['dimensions']['stock_date']['nonAdditive']);
        self::assertSame(['day', 'week', 'month'], $dataset['dimensions']['stock_date']['granularities']);

        $preset = $result['presets']['stock_overview'];
        self::assertSame(['mysql', 'opensearch'], $preset['sources']);
        self::assertSame(['1', '2', '3'], $preset['filters'][0]['value']);
    }

    public function testItConvertsExpressionProviderMappingsVirtualAndLowerIsBetterMetricsAndPresetAlternateDimensions(): void
    {
        $result = (new Converter())->convert(
            $this->loadFixture('expression_provider_virtual_lower_is_better.xml')
        );

        $dataset = $result['datasets']['sales_orders'];
        self::assertSame(
            'first_order',
            $dataset['fields']['is_first_order']['sourceMappings']['mysql']['expressionProvider']
        );
        self::assertNull($dataset['fields']['is_first_order']['sourceMappings']['mysql']['expression']);
        self::assertTrue($dataset['metrics']['coverage_days']['virtual']);
        self::assertNull($dataset['metrics']['coverage_days']['aggregate']);
        self::assertNull($dataset['metrics']['coverage_days']['formula']);
        self::assertTrue($dataset['metrics']['returns']['lowerIsBetter']);

        self::assertSame('payment_method', $result['presets']['sales_overview']['alternateDimension']);
    }

    private function loadFixture(string $fileName): \DOMDocument
    {
        $document = new \DOMDocument();
        $document->load(__DIR__ . '/_files/' . $fileName);

        return $document;
    }
}
