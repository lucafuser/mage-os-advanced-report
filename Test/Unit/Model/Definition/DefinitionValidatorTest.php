<?php

declare(strict_types=1);

namespace MageOS\AdvancedReport\Test\Unit\Model\Definition;

use Magento\Framework\Acl\AclResource\ProviderInterface;
use Magento\Framework\App\State;
use MageOS\AdvancedReport\Model\Definition\AggregateFunction;
use MageOS\AdvancedReport\Model\Definition\Dataset;
use MageOS\AdvancedReport\Model\Definition\DefinitionValidator;
use MageOS\AdvancedReport\Model\Definition\Dimension;
use MageOS\AdvancedReport\Model\Definition\Field;
use MageOS\AdvancedReport\Model\Definition\FieldType;
use MageOS\AdvancedReport\Model\Definition\FilterOperator;
use MageOS\AdvancedReport\Model\Definition\Granularity;
use MageOS\AdvancedReport\Model\Definition\InvalidDefinitionException;
use MageOS\AdvancedReport\Model\Definition\Join;
use MageOS\AdvancedReport\Model\Definition\Metric;
use MageOS\AdvancedReport\Model\Definition\Preset;
use MageOS\AdvancedReport\Model\Definition\SourceMapping;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

#[CoversClass(DefinitionValidator::class)]
final class DefinitionValidatorTest extends TestCase
{
    public function testItReportsAnAggregateMetricPointingToAnUnknownField(): void
    {
        $dataset = $this->dataset(
            fields: [$this->field('grand_total', FieldType::MONEY)],
            metrics: [$this->aggregateMetric('orders', 'missing_field')],
        );
        $validator = $this->developerValidator();

        $this->expectException(InvalidDefinitionException::class);
        $this->expectExceptionMessageMatches('/missing_field/');

        $validator->validate(['sales_orders' => $dataset], []);
    }

    public function testItReportsAnAggregateMetricWhoseConditionFieldIsNotBoolean(): void
    {
        $dataset = $this->dataset(
            fields: [
                $this->field('grand_total', FieldType::MONEY),
                $this->field('status', FieldType::STRING),
            ],
            metrics: [$this->aggregateMetric('orders', 'grand_total', 'status')],
        );
        $validator = $this->developerValidator();

        $this->expectException(InvalidDefinitionException::class);
        $this->expectExceptionMessageMatches('/condition field "status" is not of type boolean/');

        $validator->validate(['sales_orders' => $dataset], []);
    }

    public function testItRejectsAFieldAclThatIsNotADeclaredAclResource(): void
    {
        $dataset = $this->dataset(fields: [$this->field('birth_year', FieldType::INTEGER, acl: 'Magento_Sales::missing')]);
        $validator = $this->developerValidator();

        $this->expectException(InvalidDefinitionException::class);
        $this->expectExceptionMessageMatches('/birth_year.*Magento_Sales::missing/');

        $validator->validate(['sales_orders' => $dataset], []);
    }

    public function testItAcceptsAFieldAclThatIsADeclaredAclResource(): void
    {
        $dataset = $this->dataset(fields: [$this->field('birth_year', FieldType::INTEGER, acl: 'Magento_Sales::actions_view')]);

        $result = $this->developerValidator()->validate(['sales_orders' => $dataset], []);

        self::assertArrayHasKey('birth_year', $result['datasets']['sales_orders']->fields);
    }

    #[DataProvider('invalidDerivedMetricReferenceProvider')]
    public function testItReportsADerivedMetricReferencingAnUnknownMetric(
        Metric $referenced,
        string $expectedPattern,
    ): void {
        $dataset = $this->dataset(
            fields: [$this->field('grand_total', FieldType::MONEY)],
            metrics: [
                $referenced,
                $this->derivedMetric('aov', 'net_sales / orders'),
            ],
        );
        $validator = $this->developerValidator();

        $this->expectException(InvalidDefinitionException::class);
        $this->expectExceptionMessageMatches($expectedPattern);

        $validator->validate(['sales_orders' => $dataset], []);
    }

    /**
     * @return array<string,array{0:Metric,1:string}>
     */
    public static function invalidDerivedMetricReferenceProvider(): array
    {
        return [
            'unknown metric reference' => [
                new Metric(
                    code: 'orders',
                    label: 'Orders',
                    description: null,
                    format: 'integer',
                    hidden: false,
                    lowerIsBetter: false,
                    aggregate: AggregateFunction::COUNT,
                    fieldCode: 'grand_total',
                ),
                '/references unknown metric "net_sales"/',
            ],
            'virtual metric reference' => [
                new Metric(
                    code: 'net_sales',
                    label: 'Net sales',
                    description: null,
                    format: 'money',
                    hidden: false,
                    lowerIsBetter: false,
                    virtual: true,
                ),
                '/references virtual metric "net_sales"/',
            ],
        ];
    }

    public function testItReportsCircularDerivedMetricFormulas(): void
    {
        $dataset = $this->dataset(
            metrics: [
                $this->derivedMetric('a', 'b + 1'),
                $this->derivedMetric('b', 'a + 1'),
            ],
        );
        $validator = $this->developerValidator();

        $this->expectException(InvalidDefinitionException::class);
        $this->expectExceptionMessageMatches('/circular/');

        $validator->validate(['sales_orders' => $dataset], []);
    }

    public function testItReportsAFieldMappingWithoutExactlyOneOfExpressionOrExpressionProvider(): void
    {
        $dataset = $this->dataset(
            fields: [
                new Field(
                    code: 'grand_total',
                    label: 'Grand total',
                    type: FieldType::MONEY,
                    sourceMappings: [
                        'mysql' => new SourceMapping(expression: null, expressionProvider: null),
                    ],
                ),
            ],
        );
        $validator = $this->developerValidator();

        $this->expectException(InvalidDefinitionException::class);
        $this->expectExceptionMessageMatches(
            '/field "grand_total" mapping for source "mysql" must have exactly one of'
                . ' expression or expressionProvider/'
        );

        $validator->validate(['sales_orders' => $dataset], []);
    }

    public function testItReportsAJoinRequiringAnUnknownJoinAndAFieldUsingAnUnknownJoin(): void
    {
        $dataset = $this->dataset(
            joins: [$this->join('payment', requires: ['missing_join'])],
            fields: [$this->field('color', FieldType::STRING, joins: ['missing_join_2'])],
        );
        $validator = $this->developerValidator();

        $this->expectException(InvalidDefinitionException::class);

        try {
            $validator->validate(['sales_orders' => $dataset], []);
        } catch (InvalidDefinitionException $exception) {
            $message = $exception->getMessage();
            self::assertMatchesRegularExpression('/join "payment" requires unknown join "missing_join"/', $message);
            self::assertMatchesRegularExpression('/field "color".*unknown join "missing_join_2"/', $message);

            throw $exception;
        }
    }

    public function testItReportsADimensionOnAnUnknownField(): void
    {
        $dataset = $this->dataset(
            fields: [$this->field('grand_total', FieldType::MONEY)],
            dimensions: [$this->dimension('payment_method', 'missing_field')],
        );
        $validator = $this->developerValidator();

        $this->expectException(InvalidDefinitionException::class);
        $this->expectExceptionMessageMatches('/dimension "payment_method" uses unknown field "missing_field"/');

        $validator->validate(['sales_orders' => $dataset], []);
    }

    #[DataProvider('incoherentPresetProvider')]
    public function testItReportsPresetsReferencingMetricsDimensionsOrOperatorsNotValidForTheirDataset(
        Preset $preset,
        string $expectedPattern,
    ): void {
        $dataset = $this->dataset(
            fields: [
                $this->field('grand_total', FieldType::MONEY),
                $this->field('store_id', FieldType::ID),
            ],
            metrics: [$this->aggregateMetric('orders', 'grand_total')],
            dimensions: [$this->dimension('order_date', 'grand_total', [Granularity::DAY])],
        );
        $validator = $this->developerValidator();

        $this->expectException(InvalidDefinitionException::class);
        $this->expectExceptionMessageMatches($expectedPattern);

        $validator->validate(['sales_orders' => $dataset], ['sales_overview' => $preset]);
    }

    /**
     * @return array<string,array{0:Preset,1:string}>
     */
    public static function incoherentPresetProvider(): array
    {
        return [
            'unknown dataset' => [
                self::preset(dataset: 'unknown_dataset'),
                '/targets unknown or invalid dataset "unknown_dataset"/',
            ],
            'unknown metric' => [
                self::preset(metrics: ['unknown_metric']),
                '/uses metric "unknown_metric" not declared/',
            ],
            'unknown dimension' => [
                self::preset(dimensions: [['code' => 'unknown_dimension', 'granularity' => null]]),
                '/uses dimension "unknown_dimension" not declared/',
            ],
            'granularity not allowed' => [
                self::preset(dimensions: [['code' => 'order_date', 'granularity' => Granularity::YEAR]]),
                '/granularity "year" not allowed for dimension "order_date"/',
            ],
            'operator not allowed' => [
                self::preset(
                    metrics: ['orders'],
                    filters: [
                        ['field' => 'store_id', 'operator' => FilterOperator::BETWEEN, 'value' => ['1', '2']],
                    ],
                ),
                '/operator "between" not allowed for field "store_id"/',
            ],
            'sort target not selected' => [
                self::preset(metrics: ['orders'], sort: [['metric' => 'not_selected', 'direction' => 'desc']]),
                '/sorts by "not_selected" which is not a selected metric or dimension/',
            ],
        ];
    }

    public function testItReportsADatedDatasetWithoutACreatedAtDateField(): void
    {
        $dataset = $this->dataset(dateless: false);
        $validator = $this->developerValidator();

        $this->expectException(InvalidDefinitionException::class);
        $this->expectExceptionMessageMatches('/must declare a "created_at" field of type date/');

        $validator->validate(['sales_orders' => $dataset], []);
    }

    #[DataProvider('invalidDatasetProvider')]
    public function testItReportsADatasetMissingLabelAclMysqlSourceOrStatusField(
        Dataset $dataset,
        string $expectedPattern,
    ): void {
        $validator = $this->developerValidator();

        $this->expectException(InvalidDefinitionException::class);
        $this->expectExceptionMessageMatches($expectedPattern);

        $validator->validate(['sales_orders' => $dataset], []);
    }

    /**
     * @return array<string,array{0:Dataset,1:string}>
     */
    public static function invalidDatasetProvider(): array
    {
        return [
            'missing label' => [
                new Dataset(
                    code: 'sales_orders',
                    label: '',
                    aclResource: 'MageOS_AdvancedReport::sales',
                    area: 'sales',
                    dateless: true,
                    baseTable: 'sales_order',
                    baseAlias: 'main',
                    baseProvider: null,
                    implicitFilters: [],
                    fields: [],
                    joins: [],
                    metrics: [],
                    dimensions: [],
                ),
                '/has no label/',
            ],
            'missing acl' => [
                new Dataset(
                    code: 'sales_orders',
                    label: 'Orders',
                    aclResource: '',
                    area: 'sales',
                    dateless: true,
                    baseTable: 'sales_order',
                    baseAlias: 'main',
                    baseProvider: null,
                    implicitFilters: [],
                    fields: [],
                    joins: [],
                    metrics: [],
                    dimensions: [],
                ),
                '/has no acl resource/',
            ],
            'missing mysql source' => [
                new Dataset(
                    code: 'sales_orders',
                    label: 'Orders',
                    aclResource: 'MageOS_AdvancedReport::sales',
                    area: 'sales',
                    dateless: true,
                    baseTable: null,
                    baseAlias: null,
                    baseProvider: null,
                    implicitFilters: [],
                    fields: [],
                    joins: [],
                    metrics: [],
                    dimensions: [],
                ),
                '/has no mysql source/',
            ],
            'counted_status without status field' => [
                new Dataset(
                    code: 'sales_orders',
                    label: 'Orders',
                    aclResource: 'MageOS_AdvancedReport::sales',
                    area: 'sales',
                    dateless: true,
                    baseTable: 'sales_order',
                    baseAlias: 'main',
                    baseProvider: null,
                    implicitFilters: ['counted_status' => 'counted_status'],
                    fields: [],
                    joins: [],
                    metrics: [],
                    dimensions: [],
                ),
                '/uses the "counted_status" implicit filter and must declare a "status" field/',
            ],
            'non_empty_cart without items_count field' => [
                new Dataset(
                    code: 'carts',
                    label: 'Carts',
                    aclResource: 'MageOS_AdvancedReport::sales',
                    area: 'sales',
                    dateless: true,
                    baseTable: 'quote',
                    baseAlias: 'main',
                    baseProvider: null,
                    implicitFilters: ['non_empty_cart' => 'non_empty_cart'],
                    fields: [],
                    joins: [],
                    metrics: [],
                    dimensions: [],
                ),
                '/uses the "non_empty_cart" implicit filter and must declare a "items_count" field/',
            ],
        ];
    }

    /**
     * @return void
     * @throws InvalidDefinitionException
     */
    public function testItReportsAPresetChartMetricNotDeclaredOnTheDataset(): void
    {
        $dataset = $this->dataset(
            fields: [$this->field('grand_total', FieldType::MONEY)],
            metrics: [$this->aggregateMetric('orders', 'grand_total')],
        );
        $preset = self::preset(
            metrics: ['orders'],
            chart: [
                'type' => 'line',
                'metrics' => ['missing_metric'],
                'dimensions' => [],
                'sortBy' => null,
                'sortDirection' => null,
            ],
        );
        $validator = $this->developerValidator();

        $this->expectException(InvalidDefinitionException::class);
        $this->expectExceptionMessageMatches('/chart uses metric "missing_metric" not declared/');

        $validator->validate(['sales_orders' => $dataset], ['sales_overview' => $preset]);
    }

    public function testItReportsVirtualMetricsUsedOutsidePresetQueryPresets(): void
    {
        $dataset = $this->dataset(metrics: [$this->virtualMetric('coverage_days')]);
        $preset = $this->preset(metrics: ['coverage_days']);
        $validator = $this->developerValidator();

        $this->expectException(InvalidDefinitionException::class);
        $this->expectExceptionMessageMatches('/uses virtual metric "coverage_days" without declaring a presetQuery/');

        $validator->validate(['sales_orders' => $dataset], ['stock_overview' => $preset]);
    }

    public function testItReportsAVirtualMetricThatDeclaresAnAggregate(): void
    {
        $dataset = $this->dataset(
            fields: [$this->field('grand_total', FieldType::MONEY)],
            metrics: [
                new Metric(
                    code: 'coverage_days',
                    label: 'Coverage days',
                    description: null,
                    format: 'number',
                    hidden: false,
                    lowerIsBetter: false,
                    aggregate: AggregateFunction::SUM,
                    fieldCode: 'grand_total',
                    virtual: true,
                ),
            ],
        );
        $validator = $this->developerValidator();

        $this->expectException(InvalidDefinitionException::class);
        $this->expectExceptionMessageMatches(
            '/virtual metric "coverage_days" must not declare an aggregate, field or formula/'
        );

        $validator->validate(['sales_orders' => $dataset], []);
    }

    public function testItThrowsInDeveloperModeAndLogsAndDropsTheDefinitionInProductionMode(): void
    {
        $validDataset = $this->dataset(
            fields: [$this->field('grand_total', FieldType::MONEY)],
            metrics: [
                $this->aggregateMetric('orders', 'grand_total'),
                $this->aggregateMetric('bad_metric', 'missing_field'),
            ],
        );
        $invalidDataset = $this->dataset(dateless: false, code: 'catalog_stock');
        $presetOnInvalidDataset = $this->preset(code: 'stock_overview', dataset: 'catalog_stock');
        $datasets = ['sales_orders' => $validDataset, 'catalog_stock' => $invalidDataset];
        $presets = ['stock_overview' => $presetOnInvalidDataset];

        $developerValidator = new DefinitionValidator(
            $this->stateWithMode(State::MODE_DEVELOPER),
            $this->createStub(LoggerInterface::class),
            $this->aclProvider(),
        );
        $thrown = false;
        try {
            $developerValidator->validate($datasets, $presets);
        } catch (InvalidDefinitionException) {
            $thrown = true;
        }
        self::assertTrue($thrown, 'Expected InvalidDefinitionException in developer mode.');

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::atLeastOnce())->method('error');

        $productionValidator = new DefinitionValidator($this->stateWithMode(State::MODE_PRODUCTION), $logger, $this->aclProvider());
        $result = $productionValidator->validate($datasets, $presets);

        self::assertArrayHasKey('sales_orders', $result['datasets']);
        self::assertArrayHasKey('orders', $result['datasets']['sales_orders']->metrics);
        self::assertArrayNotHasKey('bad_metric', $result['datasets']['sales_orders']->metrics);
        self::assertArrayNotHasKey('catalog_stock', $result['datasets']);
        self::assertArrayNotHasKey('stock_overview', $result['presets']);
    }

    /**
     * @param Field[] $fields
     * @param Join[] $joins
     * @param Metric[] $metrics
     * @param Dimension[] $dimensions
     * @param array<string,string> $implicitFilters
     */
    private function dataset(
        array $fields = [],
        array $joins = [],
        array $metrics = [],
        array $dimensions = [],
        array $implicitFilters = [],
        bool $dateless = true,
        string $code = 'sales_orders',
    ): Dataset {
        return new Dataset(
            code: $code,
            label: 'Orders',
            aclResource: 'MageOS_AdvancedReport::sales',
            area: 'sales',
            dateless: $dateless,
            baseTable: 'sales_order',
            baseAlias: 'main',
            baseProvider: null,
            implicitFilters: $implicitFilters,
            fields: $this->keyByCode($fields),
            joins: $this->keyByCode($joins),
            metrics: $this->keyByCode($metrics),
            dimensions: $this->keyByCode($dimensions),
        );
    }

    /**
     * @param string[] $joins
     */
    private function field(
        string $code,
        FieldType $type,
        ?string $expression = 'main.' . 'field',
        ?string $expressionProvider = null,
        array $joins = [],
        ?string $acl = null,
    ): Field {
        return new Field(
            code: $code,
            label: ucfirst($code),
            type: $type,
            sourceMappings: [
                'mysql' => new SourceMapping(
                    expression: $expression,
                    expressionProvider: $expressionProvider,
                    joins: $joins,
                ),
            ],
            acl: $acl,
        );
    }

    private function aggregateMetric(string $code, ?string $fieldCode, ?string $conditionFieldCode = null): Metric
    {
        return new Metric(
            code: $code,
            label: ucfirst($code),
            description: null,
            format: 'number',
            hidden: false,
            lowerIsBetter: false,
            aggregate: AggregateFunction::SUM,
            fieldCode: $fieldCode,
            conditionFieldCode: $conditionFieldCode,
        );
    }

    private function derivedMetric(string $code, string $formula): Metric
    {
        return new Metric(
            code: $code,
            label: ucfirst($code),
            description: null,
            format: 'number',
            hidden: false,
            lowerIsBetter: false,
            formula: $formula,
        );
    }

    private function virtualMetric(string $code): Metric
    {
        return new Metric(
            code: $code,
            label: ucfirst($code),
            description: null,
            format: 'number',
            hidden: false,
            lowerIsBetter: false,
            virtual: true,
        );
    }

    /**
     * @param string[] $requires
     */
    private function join(string $code, array $requires = []): Join
    {
        return new Join(code: $code, table: $code . '_table', alias: $code, condition: '1=1', requires: $requires);
    }

    /**
     * @param Granularity[] $granularities
     */
    private function dimension(string $code, string $fieldCode, array $granularities = []): Dimension
    {
        return new Dimension(code: $code, label: ucfirst($code), fieldCode: $fieldCode, granularities: $granularities);
    }

    /**
     * @param string[] $metrics
     * @param array<int,array{code:string,granularity:Granularity|null}> $dimensions
     * @param array<int,array{field:string,operator:FilterOperator,value:mixed}> $filters
     * @param array<int,array{metric:string,direction:string}> $sort
     * @param array<string,mixed>|null $chart
     */
    private static function preset(
        string $code = 'sales_overview',
        string $dataset = 'sales_orders',
        array $metrics = [],
        array $dimensions = [],
        array $filters = [],
        array $sort = [],
        ?string $presetQuery = null,
        ?array $chart = null,
    ): Preset {
        return new Preset(
            code: $code,
            area: 'sales',
            label: 'Sales overview',
            dataset: $dataset,
            metrics: $metrics,
            dimensions: $dimensions,
            filters: $filters,
            sort: $sort,
            compareTo: 'none',
            dateRange: null,
            sources: ['mysql'],
            presetQuery: $presetQuery,
            chart: $chart,
        );
    }

    private function stateWithMode(string $mode): State
    {
        $state = $this->createStub(State::class);
        $state->method('getMode')->willReturn($mode);

        return $state;
    }

    private function developerValidator(): DefinitionValidator
    {
        return new DefinitionValidator(
            $this->stateWithMode(State::MODE_DEVELOPER),
            $this->createStub(LoggerInterface::class),
            $this->aclProvider(),
        );
    }

    private function aclProvider(): ProviderInterface
    {
        $provider = $this->createStub(ProviderInterface::class);
        $provider->method('getAclResources')->willReturn([
            ['id' => 'Magento_Backend::admin', 'children' => [
                ['id' => 'Magento_Sales::actions_view', 'children' => []],
            ]],
        ]);

        return $provider;
    }

    /**
     * @template T of Field|Join|Metric|Dimension
     * @param T[] $items
     * @return array<string,T>
     */
    private function keyByCode(array $items): array
    {
        $keyed = [];
        foreach ($items as $item) {
            $keyed[$item->code] = $item;
        }

        return $keyed;
    }
}
