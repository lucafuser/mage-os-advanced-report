<?php

declare(strict_types=1);

namespace MageOS\AdvancedReport\Model\Definition;

use Magento\Framework\Acl\AclResource\ProviderInterface;
use Magento\Framework\App\State;
use Magento\Framework\Phrase;
use MageOS\AdvancedReport\Model\DataSource\Mysql\ImplicitFilterPool;
use MageOS\AdvancedReport\Model\Definition\Formula\Formula;
use MageOS\AdvancedReport\Model\Definition\Formula\FormulaException;
use Psr\Log\LoggerInterface;

/**
 * Validates the semantic layer (datasets and presets) at load time.
 *
 * In developer mode, the first batch of errors found throws {@see InvalidDefinitionException}.
 * In any other mode, invalid definitions are dropped and logged instead of failing the
 * whole request:
 * - a dataset-level error (missing label/acl/mysql source, missing reserved fields) drops
 *   the whole dataset, and every preset that targets it (a preset without its dataset makes
 *   no sense);
 * - a join- or field-level error drops just that join/field, since other, unrelated fields
 *   and metrics of the same dataset may still be valid;
 * - a metric-level error (unknown/virtual reference, non-boolean condition field, circular
 *   formula, malformed virtual metric) drops just that metric;
 * - a preset-level error (unknown dataset/metric/dimension/operator, sort target not
 *   selected, virtual metric used without a presetQuery) drops the whole preset, since a
 *   preset is a single, atomic report configuration.
 */
class DefinitionValidator
{
    /** @var array<string,true>|null */
    private ?array $declaredAclIds = null;

    /**
     * @param State $appState
     * @param LoggerInterface $logger
     * @param ProviderInterface $aclResourceProvider
     */
    public function __construct(
        private readonly State $appState,
        private readonly LoggerInterface $logger,
        private readonly ProviderInterface $aclResourceProvider,
    ) {
    }

    /**
     * Validates the given datasets and presets, dropping invalid definitions.
     *
     * In developer mode, throws on the first batch of errors found instead of dropping.
     *
     * @param array<string,Dataset> $datasets
     * @param array<string,Preset> $presets
     * @throws InvalidDefinitionException
     * @return array{datasets: array<string,Dataset>, presets: array<string,Preset>}
     */
    public function validate(array $datasets, array $presets): array
    {
        $errors = [];

        $datasets = $this->validateDatasets($datasets, $errors);
        $presets = $this->validatePresets($presets, $datasets, $errors);

        if ($errors !== []) {
            $this->report($errors);
        }

        return ['datasets' => $datasets, 'presets' => $presets];
    }

    /**
     * Validates every dataset, dropping the ones with a dataset-level error.
     *
     * @param array<string,Dataset> $datasets
     * @param string[] $errors
     * @return array<string,Dataset>
     */
    private function validateDatasets(array $datasets, array &$errors): array
    {
        $valid = [];
        foreach ($datasets as $code => $dataset) {
            $datasetErrors = $this->collectDatasetErrors($dataset);
            if ($datasetErrors !== []) {
                array_push($errors, ...$datasetErrors);
                continue;
            }

            $valid[$code] = $this->validateDatasetMembers($dataset, $errors);
        }

        return $valid;
    }

    /**
     * Dataset-level mandatory attribute and reserved-field checks.
     *
     * A failure here drops the whole dataset (and, in validatePresets(), every preset
     * that targets it).
     *
     * @param Dataset $dataset
     * @return string[]
     */
    private function collectDatasetErrors(Dataset $dataset): array
    {
        $errors = [];

        if ($dataset->label === '') {
            $errors[] = \sprintf('Dataset "%s" has no label.', $dataset->code);
        }

        if ($dataset->aclResource === '') {
            $errors[] = \sprintf('Dataset "%s" has no acl resource.', $dataset->code);
        }

        if (($dataset->baseTable === null || $dataset->baseAlias === null) && $dataset->baseProvider === null) {
            $errors[] = \sprintf('Dataset "%s" has no mysql source (table/alias or provider).', $dataset->code);
        }

        if (isset($dataset->implicitFilters['counted_status']) && !$dataset->hasField(Dataset::FIELD_STATUS)) {
            $errors[] = \sprintf(
                'Dataset "%s" uses the "counted_status" implicit filter and must declare a "%s" field.',
                $dataset->code,
                Dataset::FIELD_STATUS
            );
        }

        if (isset($dataset->implicitFilters[ImplicitFilterPool::NON_EMPTY_CART])
            && !$dataset->hasField(ImplicitFilterPool::ITEMS_COUNT_FIELD)
        ) {
            $errors[] = \sprintf(
                'Dataset "%s" uses the "non_empty_cart" implicit filter and must declare a "%s" field.',
                $dataset->code,
                ImplicitFilterPool::ITEMS_COUNT_FIELD
            );
        }

        if (!$dataset->dateless
            && (!$dataset->hasField(Dataset::FIELD_CREATED_AT)
                || $dataset->getField(Dataset::FIELD_CREATED_AT)->type !== FieldType::DATE)
        ) {
            $errors[] = \sprintf(
                'Dataset "%s" is not dateless and must declare a "%s" field of type date.',
                $dataset->code,
                Dataset::FIELD_CREATED_AT
            );
        }

        return $errors;
    }

    /**
     * Validates the joins, fields and metrics of an already dataset-level-valid dataset.
     *
     * Drops only the individual invalid members instead of the whole dataset.
     *
     * @param Dataset $dataset
     * @param string[] $errors
     */
    private function validateDatasetMembers(Dataset $dataset, array &$errors): Dataset
    {
        $joins = $this->validateJoins($dataset, $errors);
        $fields = $this->validateFields($dataset, $joins, $errors);

        $metrics = $dataset->metrics;
        foreach ($metrics as $code => $metric) {
            $metricErrors = $this->collectMetricErrors($dataset, $metric, $fields);
            if ($metricErrors !== []) {
                array_push($errors, ...$metricErrors);
                unset($metrics[$code]);
            }
        }

        $metrics = $this->dropCyclicDerivedMetrics($dataset, $metrics, $errors);
        $dimensions = $this->validateDimensions($dataset, $fields, $errors);

        return new Dataset(
            code: $dataset->code,
            label: $dataset->label,
            aclResource: $dataset->aclResource,
            area: $dataset->area,
            dateless: $dataset->dateless,
            baseTable: $dataset->baseTable,
            baseAlias: $dataset->baseAlias,
            baseProvider: $dataset->baseProvider,
            implicitFilters: $dataset->implicitFilters,
            fields: $fields,
            joins: $joins,
            metrics: $metrics,
            dimensions: $dimensions,
        );
    }

    /**
     * Drops dimensions whose field code is not a declared (surviving) field of the dataset.
     *
     * @param Dataset $dataset
     * @param array<string,Field> $fields
     * @param string[] $errors
     * @return array<string,Dimension>
     */
    private function validateDimensions(Dataset $dataset, array $fields, array &$errors): array
    {
        $valid = [];
        foreach ($dataset->dimensions as $code => $dimension) {
            if (!isset($fields[$dimension->fieldCode])) {
                $errors[] = \sprintf(
                    'Dataset "%s" dimension "%s" uses unknown field "%s".',
                    $dataset->code,
                    $code,
                    $dimension->fieldCode
                );
                continue;
            }
            $valid[$code] = $dimension;
        }

        return $valid;
    }

    /**
     * Drops joins whose "requires" list references an unknown join code.
     *
     * @param Dataset $dataset
     * @param string[] $errors
     * @return array<string,Join>
     */
    private function validateJoins(Dataset $dataset, array &$errors): array
    {
        $valid = [];
        foreach ($dataset->joins as $code => $join) {
            $unknown = array_diff($join->requires, array_keys($dataset->joins));
            if ($unknown !== []) {
                $errors[] = \sprintf(
                    'Dataset "%s" join "%s" requires unknown join "%s".',
                    $dataset->code,
                    $code,
                    implode('", "', $unknown)
                );
                continue;
            }
            $valid[$code] = $join;
        }

        return $valid;
    }

    /**
     * Drops fields whose source mapping references an unknown or dropped join.
     *
     * @param Dataset $dataset
     * @param array<string,Join> $joins
     * @param string[] $errors
     * @return array<string,Field>
     */
    private function validateFields(Dataset $dataset, array $joins, array &$errors): array
    {
        $valid = [];
        foreach ($dataset->fields as $code => $field) {
            $fieldErrors = $this->collectFieldErrors($dataset, $field, $joins);
            if ($fieldErrors !== []) {
                array_push($errors, ...$fieldErrors);
                continue;
            }
            $valid[$code] = $field;
        }

        return $valid;
    }

    /**
     * Collects errors for a single field.
     *
     * Each source mapping must have exactly one of expression/expressionProvider, and
     * reference only known joins.
     *
     * @param Dataset $dataset
     * @param Field $field
     * @param array<string,Join> $joins
     * @return string[]
     */
    private function collectFieldErrors(Dataset $dataset, Field $field, array $joins): array
    {
        $errors = [];
        foreach ($field->sourceMappings as $source => $mapping) {
            $hasExpression = $mapping->expression !== null;
            $hasProvider = $mapping->expressionProvider !== null;
            if ($hasExpression === $hasProvider) {
                $errors[] = \sprintf(
                    'Dataset "%s" field "%s" mapping for source "%s" must have exactly one of'
                        . ' expression or expressionProvider.',
                    $dataset->code,
                    $field->code,
                    $source
                );
                continue;
            }

            $unknownJoins = array_diff($mapping->joins, array_keys($joins));
            if ($unknownJoins !== []) {
                $errors[] = \sprintf(
                    'Dataset "%s" field "%s" mapping for source "%s" uses unknown join "%s".',
                    $dataset->code,
                    $field->code,
                    $source,
                    implode('", "', $unknownJoins)
                );
            }
        }

        if ($field->acl !== null && !isset($this->getDeclaredAclIds()[$field->acl])) {
            $errors[] = \sprintf(
                'Dataset "%s" field "%s" acl "%s" is not a declared ACL resource.',
                $dataset->code,
                $field->code,
                $field->acl
            );
        }

        return $errors;
    }

    /**
     * Every ACL resource id declared by the installed modules, read once.
     *
     * @return array<string,true>
     */
    private function getDeclaredAclIds(): array
    {
        return $this->declaredAclIds ??= $this->collectAclIds($this->aclResourceProvider->getAclResources());
    }

    /**
     * @param array<int,array<string,mixed>> $nodes
     * @param array<string,true> $ids
     * @return array<string,true>
     */
    private function collectAclIds(array $nodes, array $ids = []): array
    {
        foreach ($nodes as $node) {
            $ids[(string) $node['id']] = true;
            $ids = $this->collectAclIds($node['children'] ?? [], $ids);
        }

        return $ids;
    }

    /**
     * Collects errors for a single metric, depending on its kind.
     *
     * @param Dataset $dataset
     * @param Metric $metric
     * @param array<string,Field> $fields
     * @return string[]
     */
    private function collectMetricErrors(Dataset $dataset, Metric $metric, array $fields): array
    {
        if ($metric->isVirtual()) {
            return $this->collectVirtualMetricErrors($dataset, $metric);
        }

        if ($metric->isDerived()) {
            return $this->collectDerivedMetricReferenceErrors($dataset, $metric);
        }

        if (!$metric->isAggregate()) {
            return [];
        }

        return $this->collectAggregateMetricErrors($dataset, $metric, $fields);
    }

    /**
     * A virtual metric must not declare an aggregate, field or formula.
     *
     * Its value is produced only by a preset query.
     *
     * @param Dataset $dataset
     * @param Metric $metric
     * @return string[]
     */
    private function collectVirtualMetricErrors(Dataset $dataset, Metric $metric): array
    {
        if ($metric->aggregate !== null || $metric->fieldCode !== null || $metric->formula !== null) {
            return [
                \sprintf(
                    'Dataset "%s" virtual metric "%s" must not declare an aggregate, field or formula.',
                    $dataset->code,
                    $metric->code
                ),
            ];
        }

        return [];
    }

    /**
     * An aggregate metric must reference a known field with a known boolean condition.
     *
     * @param Dataset $dataset
     * @param Metric $metric
     * @param array<string,Field> $fields
     * @return string[]
     */
    private function collectAggregateMetricErrors(Dataset $dataset, Metric $metric, array $fields): array
    {
        $errors = [];

        if ($metric->fieldCode === null || !isset($fields[$metric->fieldCode])) {
            $errors[] = \sprintf(
                'Dataset "%s" metric "%s" aggregates unknown field "%s".',
                $dataset->code,
                $metric->code,
                (string) $metric->fieldCode
            );
        }

        if ($metric->conditionFieldCode !== null) {
            $conditionField = $fields[$metric->conditionFieldCode] ?? null;
            if ($conditionField === null) {
                $errors[] = \sprintf(
                    'Dataset "%s" metric "%s" has an unknown condition field "%s".',
                    $dataset->code,
                    $metric->code,
                    $metric->conditionFieldCode
                );
            } elseif ($conditionField->type !== FieldType::BOOLEAN) {
                $errors[] = \sprintf(
                    'Dataset "%s" metric "%s" condition field "%s" is not of type boolean.',
                    $dataset->code,
                    $metric->code,
                    $metric->conditionFieldCode
                );
            }
        }

        return $errors;
    }

    /**
     * Validates that a derived metric's formula only references known, non-virtual metrics.
     *
     * Cycle detection happens separately in {@see dropCyclicDerivedMetrics()}, once every
     * individual reference is known to be resolvable.
     *
     * @param Dataset $dataset
     * @param Metric $metric
     * @return string[]
     */
    private function collectDerivedMetricReferenceErrors(Dataset $dataset, Metric $metric): array
    {
        try {
            $references = Formula::fromString((string) $metric->formula)->getReferences();
        } catch (FormulaException $exception) {
            return [
                \sprintf(
                    'Dataset "%s" metric "%s" has an invalid formula: %s',
                    $dataset->code,
                    $metric->code,
                    $exception->getMessage()
                ),
            ];
        }

        $errors = [];
        foreach ($references as $reference) {
            $referenced = $dataset->metrics[$reference] ?? null;
            if ($referenced === null) {
                $errors[] = \sprintf(
                    'Dataset "%s" derived metric "%s" references unknown metric "%s".',
                    $dataset->code,
                    $metric->code,
                    $reference
                );
            } elseif ($referenced->isVirtual()) {
                $errors[] = \sprintf(
                    'Dataset "%s" derived metric "%s" references virtual metric "%s".',
                    $dataset->code,
                    $metric->code,
                    $reference
                );
            }
        }

        return $errors;
    }

    /**
     * DFS-based cycle detection over derived metric formulas.
     *
     * Every metric that participates in a cycle is dropped and reported.
     *
     * @param Dataset $dataset
     * @param array<string,Metric> $metrics
     * @param string[] $errors
     * @return array<string,Metric>
     */
    private function dropCyclicDerivedMetrics(Dataset $dataset, array $metrics, array &$errors): array
    {
        $graph = [];
        foreach ($metrics as $code => $metric) {
            if ($metric->isDerived()) {
                try {
                    $graph[$code] = Formula::fromString((string) $metric->formula)->getReferences();
                } catch (FormulaException) {
                    $graph[$code] = [];
                }
            }
        }

        $cyclic = [];
        $visitState = [];
        foreach (array_keys($graph) as $code) {
            $this->detectCycle($code, $graph, $visitState, [], $cyclic);
        }

        foreach (array_unique($cyclic) as $code) {
            $errors[] = \sprintf(
                'Dataset "%s" derived metric "%s" is part of a circular formula reference.',
                $dataset->code,
                $code
            );
            unset($metrics[$code]);
        }

        return $metrics;
    }

    /**
     * Depth-first traversal of the derived-metric reference graph.
     *
     * Collects every metric code that is part of a cycle into $cyclic.
     *
     * @param string $code
     * @param array<string,string[]> $graph
     * @param array<string,int> $visitState 0 = unvisited, 1 = in progress, 2 = done.
     * @param string[] $path Codes visited so far on the current DFS branch.
     * @param string[] $cyclic
     */
    private function detectCycle(string $code, array $graph, array &$visitState, array $path, array &$cyclic): void
    {
        $state = $visitState[$code] ?? 0;
        if ($state === 2) {
            return;
        }
        if ($state === 1) {
            $cycleStart = array_search($code, $path, true);
            array_push($cyclic, ...\array_slice($path, $cycleStart === false ? 0 : (int) $cycleStart));

            return;
        }

        $visitState[$code] = 1;
        $path[] = $code;
        foreach ($graph[$code] ?? [] as $reference) {
            if (isset($graph[$reference])) {
                $this->detectCycle($reference, $graph, $visitState, $path, $cyclic);
            }
        }
        $visitState[$code] = 2;
    }

    /**
     * Validates every preset, dropping the ones with a preset-level error.
     *
     * This includes presets that target a dataset dropped by {@see validateDatasets()}.
     *
     * @param array<string,Preset> $presets
     * @param array<string,Dataset> $datasets
     * @param string[] $errors
     * @return array<string,Preset>
     */
    private function validatePresets(array $presets, array $datasets, array &$errors): array
    {
        $valid = [];
        foreach ($presets as $code => $preset) {
            $presetErrors = $this->collectPresetErrors($preset, $datasets);
            if ($presetErrors !== []) {
                array_push($errors, ...$presetErrors);
                continue;
            }
            $valid[$code] = $preset;
        }

        return $valid;
    }

    /**
     * Collects every incoherence of a single preset against its dataset.
     *
     * Covers unknown dataset/metrics/dimensions, disallowed granularities/operators and
     * unselected sort targets.
     *
     * @param Preset $preset
     * @param array<string,Dataset> $datasets
     * @return string[]
     */
    private function collectPresetErrors(Preset $preset, array $datasets): array
    {
        $dataset = $datasets[$preset->dataset] ?? null;
        if ($dataset === null) {
            return [
                \sprintf(
                    'Preset "%s" targets unknown or invalid dataset "%s".',
                    $preset->code,
                    $preset->dataset
                ),
            ];
        }

        return [
            ...$this->collectPresetMetricErrors($preset, $dataset),
            ...$this->collectPresetDimensionErrors($preset, $dataset),
            ...$this->collectPresetFilterErrors($preset, $dataset),
            ...$this->collectPresetSortErrors($preset),
            ...$this->collectPresetChartErrors($preset, $dataset),
        ];
    }

    /**
     * A preset's metrics must be declared on its dataset.
     *
     * A virtual metric may only be selected by a preset that declares a presetQuery.
     *
     * @param Preset $preset
     * @param Dataset $dataset
     * @return string[]
     */
    private function collectPresetMetricErrors(Preset $preset, Dataset $dataset): array
    {
        $errors = [];
        foreach ($preset->metrics as $metricCode) {
            $metric = $dataset->metrics[$metricCode] ?? null;
            if ($metric === null) {
                $errors[] = \sprintf(
                    'Preset "%s" uses metric "%s" not declared on dataset "%s".',
                    $preset->code,
                    $metricCode,
                    $dataset->code
                );
                continue;
            }

            if ($metric->isVirtual() && $preset->presetQuery === null) {
                $errors[] = \sprintf(
                    'Preset "%s" uses virtual metric "%s" without declaring a presetQuery.',
                    $preset->code,
                    $metricCode
                );
            }
        }

        return $errors;
    }

    /**
     * A preset's dimensions must be declared on its dataset.
     *
     * A requested granularity (if any) must be one the dimension allows.
     *
     * @param Preset $preset
     * @param Dataset $dataset
     * @return string[]
     */
    private function collectPresetDimensionErrors(Preset $preset, Dataset $dataset): array
    {
        $errors = [];
        foreach ($preset->dimensions as $requested) {
            $dimension = $dataset->dimensions[$requested['code']] ?? null;
            if ($dimension === null) {
                $errors[] = \sprintf(
                    'Preset "%s" uses dimension "%s" not declared on dataset "%s".',
                    $preset->code,
                    $requested['code'],
                    $dataset->code
                );
                continue;
            }

            $granularity = $requested['granularity'];
            if ($granularity !== null && !\in_array($granularity, $dimension->getGranularities(), true)) {
                $errors[] = \sprintf(
                    'Preset "%s" uses granularity "%s" not allowed for dimension "%s".',
                    $preset->code,
                    $granularity->value,
                    $requested['code']
                );
            }
        }

        return $errors;
    }

    /**
     * A preset's filters must target a known field.
     *
     * The filter operator must be one the field's type allows.
     *
     * @param Preset $preset
     * @param Dataset $dataset
     * @return string[]
     */
    private function collectPresetFilterErrors(Preset $preset, Dataset $dataset): array
    {
        $errors = [];
        foreach ($preset->filters as $filter) {
            $field = $dataset->fields[$filter['field']] ?? null;
            if ($field === null) {
                $errors[] = \sprintf(
                    'Preset "%s" filters on unknown field "%s" of dataset "%s".',
                    $preset->code,
                    $filter['field'],
                    $dataset->code
                );
                continue;
            }

            if (!\in_array($filter['operator'], $field->type->allowedOperators(), true)) {
                $errors[] = \sprintf(
                    'Preset "%s" uses operator "%s" not allowed for field "%s" of type "%s".',
                    $preset->code,
                    $filter['operator']->value,
                    $filter['field'],
                    $field->type->value
                );
            }
        }

        return $errors;
    }

    /**
     * A preset's sort target must be one of its own selected metrics or dimensions.
     *
     * @param Preset $preset
     * @return string[]
     */
    private function collectPresetSortErrors(Preset $preset): array
    {
        $errors = [];
        $selected = [
            ...$preset->metrics,
            ...array_map(static fn (array $dimension): string => $dimension['code'], $preset->dimensions),
        ];

        foreach ($preset->sort as $sort) {
            if (!\in_array($sort['metric'], $selected, true)) {
                $errors[] = \sprintf(
                    'Preset "%s" sorts by "%s" which is not a selected metric or dimension.',
                    $preset->code,
                    $sort['metric']
                );
            }
        }

        return $errors;
    }

    /**
     * @param Preset $preset
     * @param Dataset $dataset
     * @return string[]
     */
    protected function collectPresetChartErrors(Preset $preset, Dataset $dataset): array
    {
        if ($preset->chart === null) {
            return [];
        }

        $errors = [];
        $type = $preset->chart['type'] ?? null;
        if ($type !== null && !\in_array($type, Preset::CHART_TYPES, true)) {
            $errors[] = \sprintf('Preset "%s" uses unknown chart type "%s".', $preset->code, $type);
        }

        $metrics = $preset->chart['metrics'] ?? [];
        foreach ($metrics as $metricCode) {
            if (!isset($dataset->metrics[$metricCode])) {
                $errors[] = \sprintf(
                    'Preset "%s" chart uses metric "%s" not declared on dataset "%s".',
                    $preset->code,
                    $metricCode,
                    $dataset->code
                );
            }
        }

        $dimensions = $preset->chart['dimensions'] ?? [];
        foreach ($dimensions as $requested) {
            $dimension = $dataset->dimensions[$requested['code']] ?? null;
            if ($dimension === null) {
                $errors[] = \sprintf(
                    'Preset "%s" chart uses dimension "%s" not declared on dataset "%s".',
                    $preset->code,
                    $requested['code'],
                    $dataset->code
                );
                continue;
            }

            $granularity = $requested['granularity'] ?? null;
            if ($granularity !== null
                && !\in_array(Granularity::tryFrom($granularity), $dimension->getGranularities(), true)
            ) {
                $errors[] = \sprintf(
                    'Preset "%s" chart uses granularity "%s" not allowed for dimension "%s".',
                    $preset->code,
                    $granularity,
                    $requested['code']
                );
            }
        }

        $sortBy = $preset->chart['sortBy'] ?? null;
        if ($sortBy !== null && !\in_array($sortBy, [...$metrics, ...array_column($dimensions, 'code')], true)) {
            $errors[] = \sprintf(
                'Preset "%s" chart sorts by "%s" which is not one of its metrics or dimensions.',
                $preset->code,
                $sortBy
            );
        }

        return $errors;
    }

    /**
     * Throws in developer mode; otherwise logs every error and lets the caller drop.
     *
     * @param string[] $errors
     * @throws InvalidDefinitionException
     */
    private function report(array $errors): void
    {
        if ($this->appState->getMode() === State::MODE_DEVELOPER) {
            throw new InvalidDefinitionException(new Phrase(implode("\n", $errors)));
        }

        foreach ($errors as $error) {
            $this->logger->error($error);
        }
    }
}
