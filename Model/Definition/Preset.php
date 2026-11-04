<?php

declare(strict_types=1);

namespace MageOS\AdvancedReport\Model\Definition;

/**
 * A ready-to-run report configuration on top of a dataset: which metrics and
 * dimensions to show, default filters/sort/date range and supported sources.
 */
final readonly class Preset
{
    const string CHART_LINE = 'line';
    const string CHART_HORIZONTAL_BAR = 'horizontalBar';
    const array CHART_TYPES = ['line', 'bar', 'horizontalBar', 'doughnut', 'pie'];

    /**
     * @param string $code
     * @param string $area One of sales|catalog|customers|carts.
     * @param string $label
     * @param string $dataset Dataset code this preset queries.
     * @param string[] $metrics Metric codes.
     * @param array<int,array{code:string,granularity:Granularity|null}> $dimensions
     * @param array<int,array{field:string,operator:FilterOperator,value:mixed}> $filters
     * @param array<int,array{metric:string,direction:string}> $sort
     * @param string $compareTo One of none|previous_period|previous_year.
     * @param string|null $dateRange
     * @param string[] $sources Data source names this preset can run against (e.g. "mysql").
     * @param string|null $presetQuery
     * @param string|null $alternateDimension
     * @param string $description Short text shown on the report index page.
     * @param array{type:string|null,metrics:string[],dimensions:array<int,array{code:string,granularity:string|null}>,sortBy:string|null,sortDirection:string|null}|null $chart
     */
    public function __construct(
        public string $code,
        public string $area,
        public string $label,
        public string $dataset,
        public array $metrics,
        public array $dimensions,
        public array $filters,
        public array $sort,
        public string $compareTo,
        public ?string $dateRange,
        public array $sources,
        public ?string $presetQuery = null,
        public ?string $alternateDimension = null,
        public string $description = '',
        public ?array $chart = null,
    ) {
    }

    /**
     * @return array{type:string,metrics:string[],dimensions:array<int,array{code:string,granularity:string|null}>,sort:array<int,array{by:string,direction:string}>}
     */
    public function resolveChart(): array
    {
        $dimensions = $this->chart['dimensions'] ?? [];
        if ($dimensions === [] && isset($this->dimensions[0])) {
            $dimensions = [[
                'code' => $this->dimensions[0]['code'],
                'granularity' => $this->dimensions[0]['granularity']?->value,
            ]];
        }
        $metrics = $this->chart['metrics'] ?? [];
        if ($metrics === []) {
            $sortMetric = $this->sort[0]['metric'] ?? null;
            $metric = \in_array($sortMetric, $this->metrics, true) ? $sortMetric : ($this->metrics[0] ?? null);
            $metrics = $metric !== null ? [$metric] : [];
        }

        return [
            'type' => $this->chart['type']
                ?? (($dimensions[0]['granularity'] ?? null) !== null ? self::CHART_LINE : self::CHART_HORIZONTAL_BAR),
            'metrics' => $metrics,
            'dimensions' => $dimensions,
            'sort' => $this->resolveChartSort($metrics),
        ];
    }

    /**
     * @param string[] $metrics
     * @return array<int,array{by:string,direction:string}>
     */
    protected function resolveChartSort(array $metrics): array
    {
        if (!empty($this->chart['sortBy'])) {
            return [['by' => $this->chart['sortBy'], 'direction' => $this->chart['sortDirection'] ?? 'desc']];
        }
        $sort = [];
        foreach ($this->sort as $entry) {
            if (\in_array($entry['metric'], $metrics, true)) {
                $sort[] = ['by' => $entry['metric'], 'direction' => $entry['direction']];
            }
        }

        return $sort;
    }
}
