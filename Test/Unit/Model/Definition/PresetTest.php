<?php

declare(strict_types=1);

namespace MageOS\AdvancedReport\Test\Unit\Model\Definition;

use MageOS\AdvancedReport\Model\Definition\Granularity;
use MageOS\AdvancedReport\Model\Definition\Preset;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Preset::class)]
final class PresetTest extends TestCase
{
    /**
     * @return void
     */
    public function testItResolvesAnExplicitChartWithItsOwnSort(): void
    {
        $preset = $this->preset(
            metrics: ['cart_value', 'items_in_carts'],
            dimensions: [['code' => 'cart_id', 'granularity' => null]],
            sort: [['metric' => 'cart_value', 'direction' => 'desc']],
            chart: [
                'type' => 'bar',
                'metrics' => ['cart_value', 'carts'],
                'dimensions' => [['code' => 'activity_date', 'granularity' => 'day']],
                'sortBy' => 'activity_date',
                'sortDirection' => 'asc',
            ],
        );

        self::assertSame(
            [
                'type' => 'bar',
                'metrics' => ['cart_value', 'carts'],
                'dimensions' => [['code' => 'activity_date', 'granularity' => 'day']],
                'sort' => [['by' => 'activity_date', 'direction' => 'asc']],
            ],
            $preset->resolveChart()
        );
    }

    /**
     * @return void
     */
    public function testItDefaultsToALineChartOfTheFirstMetricWhenTheFirstDimensionHasAGranularity(): void
    {
        $preset = $this->preset(
            metrics: ['orders', 'net_sales'],
            dimensions: [['code' => 'order_date', 'granularity' => Granularity::DAY]],
        );

        self::assertSame(
            [
                'type' => Preset::CHART_LINE,
                'metrics' => ['orders'],
                'dimensions' => [['code' => 'order_date', 'granularity' => 'day']],
                'sort' => [],
            ],
            $preset->resolveChart()
        );
    }

    /**
     * @return void
     */
    public function testItDefaultsToAHorizontalBarChartOfTheSortMetricWithoutAGranularity(): void
    {
        $preset = $this->preset(
            metrics: ['orders', 'net_sales'],
            dimensions: [['code' => 'payment_method', 'granularity' => null]],
            sort: [['metric' => 'net_sales', 'direction' => 'desc']],
        );

        self::assertSame(
            [
                'type' => Preset::CHART_HORIZONTAL_BAR,
                'metrics' => ['net_sales'],
                'dimensions' => [['code' => 'payment_method', 'granularity' => null]],
                'sort' => [['by' => 'net_sales', 'direction' => 'desc']],
            ],
            $preset->resolveChart()
        );
    }

    /**
     * @return void
     */
    public function testItResolvesNoMetricsForAPresetWithoutMetrics(): void
    {
        self::assertSame([], $this->preset(metrics: [], dimensions: [])->resolveChart()['metrics']);
    }

    /**
     * @param string[] $metrics
     * @param array<int,array{code:string,granularity:Granularity|null}> $dimensions
     * @param array<int,array{metric:string,direction:string}> $sort
     * @param array<string,mixed>|null $chart
     * @return Preset
     */
    protected function preset(array $metrics, array $dimensions, array $sort = [], ?array $chart = null): Preset
    {
        return new Preset(
            code: 'report',
            area: 'sales',
            label: 'Report',
            dataset: 'sales_orders',
            metrics: $metrics,
            dimensions: $dimensions,
            filters: [],
            sort: $sort,
            compareTo: 'none',
            dateRange: null,
            sources: ['mysql'],
            chart: $chart,
        );
    }
}
