<?php

declare(strict_types=1);

namespace MageOS\AdvancedReport\Model\Definition;

use Magento\Framework\Phrase;

/**
 * Builds the immutable {@see Dataset} and {@see Preset} value objects from the plain,
 * code-keyed arrays produced by {@see \MageOS\AdvancedReport\Model\Config\Converter}.
 *
 * Two mismatches between the converter output and the value objects are resolved here:
 * - `dataset.sources` is a map keyed by source name; this MVP only ever builds against
 *   the `mysql` source, so {@see Dataset::$baseTable}/`$baseAlias`/`$baseProvider` are
 *   populated from `sources['mysql']` (absent entirely when there is no mysql source).
 * - `preset.compareTo` is the raw XML string (or null when the attribute is absent);
 *   it is normalized to `'none'` when absent, matching the `none|previous_period|previous_year`
 *   contract used by the report request (task 011).
 */
class DefinitionFactory
{
    private const string DEFAULT_COMPARE_TO = 'none';

    /**
     * Builds every declared dataset, keyed by code.
     *
     * @param array<string,array<string,mixed>> $rawDatasets
     * @return array<string,Dataset>
     */
    public function createDatasets(array $rawDatasets): array
    {
        $datasets = [];
        foreach ($rawDatasets as $code => $data) {
            $datasets[$code] = $this->createDataset($data);
        }

        return $datasets;
    }

    /**
     * Builds a single dataset from its converted array representation.
     *
     * @param array<string,mixed> $data
     */
    public function createDataset(array $data): Dataset
    {
        $mysqlSource = $data['sources']['mysql'] ?? null;

        return new Dataset(
            code: $data['code'],
            label: $data['label'],
            aclResource: $data['aclResource'],
            area: $data['area'],
            dateless: $data['dateless'],
            baseTable: $mysqlSource['table'] ?? null,
            baseAlias: $mysqlSource['alias'] ?? null,
            baseProvider: $mysqlSource['provider'] ?? null,
            implicitFilters: $data['implicitFilters'],
            fields: $this->createFields($data['fields']),
            joins: $this->createJoins($data['joins']),
            metrics: $this->createMetrics($data['metrics']),
            dimensions: $this->createDimensions($data['dimensions']),
        );
    }

    /**
     * Builds every declared preset, keyed by code.
     *
     * @param array<string,array<string,mixed>> $rawPresets
     * @return array<string,Preset>
     */
    public function createPresets(array $rawPresets): array
    {
        $presets = [];
        foreach ($rawPresets as $code => $data) {
            $presets[$code] = $this->createPreset($data);
        }

        return $presets;
    }

    /**
     * Builds a single preset from its converted array representation.
     *
     * @param array<string,mixed> $data
     */
    public function createPreset(array $data): Preset
    {
        $compareTo = $data['compareTo'] ?? null;

        return new Preset(
            code: $data['code'],
            area: $data['area'],
            label: $data['label'],
            dataset: $data['dataset'],
            metrics: $data['metrics'],
            dimensions: $this->createPresetDimensions($data['dimensions']),
            filters: $this->createPresetFilters($data['filters']),
            sort: $data['sort'],
            compareTo: $compareTo !== null && $compareTo !== '' ? $compareTo : self::DEFAULT_COMPARE_TO,
            dateRange: $data['dateRange'],
            sources: $data['sources'],
            presetQuery: $data['presetQuery'],
            alternateDimension: $data['alternateDimension'],
            description: $data['description'] ?? '',
            chart: $data['chart'] ?? null,
        );
    }

    /**
     * Builds a dataset's declared fields, keyed by code.
     *
     * @param array<string,array<string,mixed>> $rawFields
     * @return array<string,Field>
     */
    private function createFields(array $rawFields): array
    {
        $fields = [];
        foreach ($rawFields as $code => $data) {
            $fields[$code] = new Field(
                code: $data['code'],
                label: $data['label'],
                type: FieldType::from($data['type']),
                sourceMappings: $this->createSourceMappings($data['sourceMappings']),
                description: $data['description'],
                acl: $data['acl'] ?? null,
            );
        }

        return $fields;
    }

    /**
     * Builds a field's per-source mappings, keyed by source name.
     *
     * @param array<string,array<string,mixed>> $rawMappings
     * @return array<string,SourceMapping>
     */
    private function createSourceMappings(array $rawMappings): array
    {
        $mappings = [];
        foreach ($rawMappings as $source => $data) {
            $mappings[$source] = new SourceMapping(
                expression: $data['expression'],
                expressionProvider: $data['expressionProvider'],
                joins: $data['joins'],
            );
        }

        return $mappings;
    }

    /**
     * Builds a dataset's declared joins, keyed by code.
     *
     * @param array<string,array<string,mixed>> $rawJoins
     * @return array<string,Join>
     */
    private function createJoins(array $rawJoins): array
    {
        $joins = [];
        foreach ($rawJoins as $code => $data) {
            $joins[$code] = new Join(
                code: $data['code'],
                table: $data['table'],
                alias: $data['alias'],
                condition: $data['condition'],
                requires: $data['requires'],
                cardinality: $this->createJoinCardinality($data, (string) $code),
            );
        }

        return $joins;
    }

    /**
     * Resolves a join's cardinality, defaulting to `one` for arrays cached before the attribute existed.
     *
     * @param array<string,mixed> $data
     * @param string $joinCode
     * @throws InvalidDefinitionException
     */
    private function createJoinCardinality(array $data, string $joinCode): JoinCardinality
    {
        $value = $data['cardinality'] ?? JoinCardinality::ONE->value;

        return JoinCardinality::tryFrom($value) ?? throw new InvalidDefinitionException(
            new Phrase('Join "%1" has unknown cardinality "%2".', [$joinCode, $value])
        );
    }

    /**
     * Builds a dataset's declared metrics, keyed by code.
     *
     * @param array<string,array<string,mixed>> $rawMetrics
     * @return array<string,Metric>
     */
    private function createMetrics(array $rawMetrics): array
    {
        $metrics = [];
        foreach ($rawMetrics as $code => $data) {
            $metrics[$code] = new Metric(
                code: $data['code'],
                label: $data['label'],
                description: $data['description'],
                format: $data['format'],
                hidden: $data['hidden'],
                lowerIsBetter: $data['lowerIsBetter'],
                aggregate: $data['aggregate'] !== null ? AggregateFunction::from($data['aggregate']) : null,
                fieldCode: $data['fieldCode'],
                conditionFieldCode: $data['conditionFieldCode'],
                formula: $data['formula'],
                virtual: $data['virtual'],
            );
        }

        return $metrics;
    }

    /**
     * Builds a dataset's declared dimensions, keyed by code.
     *
     * @param array<string,array<string,mixed>> $rawDimensions
     * @return array<string,Dimension>
     */
    private function createDimensions(array $rawDimensions): array
    {
        $dimensions = [];
        foreach ($rawDimensions as $code => $data) {
            $dimensions[$code] = new Dimension(
                code: $data['code'],
                label: $data['label'],
                fieldCode: $data['fieldCode'],
                granularities: array_map(Granularity::from(...), $data['granularities']),
                labelResolver: $data['labelResolver'],
                nonAdditive: $data['nonAdditive'],
            );
        }

        return $dimensions;
    }

    /**
     * Builds a preset's requested dimensions, resolving the raw granularity string.
     *
     * @param array<int,array{code:string,granularity:string|null}> $rawDimensions
     * @return array<int,array{code:string,granularity:Granularity|null}>
     */
    private function createPresetDimensions(array $rawDimensions): array
    {
        return array_map(
            static fn (array $dimension): array => [
                'code' => $dimension['code'],
                'granularity' => $dimension['granularity'] !== null
                    ? Granularity::from($dimension['granularity'])
                    : null,
            ],
            $rawDimensions,
        );
    }

    /**
     * Builds a preset's declared filters, resolving the raw operator string.
     *
     * @param array<int,array{field:string,operator:string,value:mixed}> $rawFilters
     * @return array<int,array{field:string,operator:FilterOperator,value:mixed}>
     */
    private function createPresetFilters(array $rawFilters): array
    {
        return array_map(
            static fn (array $filter): array => [
                'field' => $filter['field'],
                'operator' => FilterOperator::from($filter['operator']),
                'value' => $filter['value'],
            ],
            $rawFilters,
        );
    }
}
