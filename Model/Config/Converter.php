<?php

declare(strict_types=1);

namespace MageOS\AdvancedReport\Model\Config;

use Magento\Framework\Config\ConverterInterface;

/**
 * Converts the merged report_datasets.xml DOM into a plain, code-keyed array.
 *
 * Comma separated attributes (e.g. "requires", "joins", "granularities", filter
 * "value", preset "sources") are split into arrays; boolean attributes ("true"/
 * "false") are cast to bool. Array keys mirror the constructor argument names of
 * the corresponding `Model\Definition\*` value objects, so the definition
 * registry can map them directly.
 */
class Converter implements ConverterInterface
{
    /**
     * Converts the merged DOM into datasets and presets keyed by code.
     *
     * @param \DOMDocument $source
     * @return array<string,array<string,mixed>>
     */
    public function convert($source): array
    {
        $xpath = new \DOMXPath($source);

        return [
            'datasets' => $this->convertDatasets($xpath),
            'presets' => $this->convertPresets($xpath),
        ];
    }

    /**
     * Converts every top level `<dataset>` element into a keyed array.
     *
     * @param \DOMXPath $xpath
     * @return array<string,array<string,mixed>>
     */
    private function convertDatasets(\DOMXPath $xpath): array
    {
        $datasets = [];
        foreach ($xpath->query('/config/dataset') ?: [] as $node) {
            /** @var \DOMElement $node */
            $code = $this->requiredAttr($node, 'code');
            $datasets[$code] = [
                'code' => $code,
                'label' => $this->attr($node, 'label'),
                'aclResource' => $this->attr($node, 'acl'),
                'area' => $this->attr($node, 'area'),
                'dateless' => $this->bool($this->attr($node, 'dateless', 'false')),
                'sources' => $this->convertSources($node),
                'implicitFilters' => $this->convertImplicitFilters($node),
                'joins' => $this->convertJoins($node),
                'fields' => $this->convertFields($node),
                'metrics' => $this->convertMetrics($node),
                'dimensions' => $this->convertDimensions($node),
            ];
        }

        return $datasets;
    }

    /**
     * Converts a dataset's `<source>` children, keyed by source name.
     *
     * @param \DOMElement $datasetNode
     * @return array<string,array<string,mixed>>
     */
    private function convertSources(\DOMElement $datasetNode): array
    {
        $sources = [];
        foreach ($this->children($datasetNode, 'source') as $node) {
            $name = $this->requiredAttr($node, 'name');
            $sources[$name] = [
                'name' => $name,
                'table' => $this->attr($node, 'table'),
                'alias' => $this->attr($node, 'alias'),
                'provider' => $this->attr($node, 'provider'),
            ];
        }

        return $sources;
    }

    /**
     * Converts a dataset's `<implicitFilter>` children into a code map.
     *
     * @param \DOMElement $datasetNode
     * @return array<string,string>
     */
    private function convertImplicitFilters(\DOMElement $datasetNode): array
    {
        $implicitFilters = [];
        foreach ($this->children($datasetNode, 'implicitFilter') as $node) {
            $code = $this->requiredAttr($node, 'code');
            $implicitFilters[$code] = $code;
        }

        return $implicitFilters;
    }

    /**
     * Converts a dataset's `<join>` children, keyed by join code.
     *
     * @param \DOMElement $datasetNode
     * @return array<string,array<string,mixed>>
     */
    private function convertJoins(\DOMElement $datasetNode): array
    {
        $joins = [];
        foreach ($this->children($datasetNode, 'join') as $node) {
            $code = $this->requiredAttr($node, 'code');
            $joins[$code] = [
                'code' => $code,
                'table' => $this->attr($node, 'table'),
                'alias' => $this->attr($node, 'alias'),
                'type' => $this->attr($node, 'type', 'left'),
                'condition' => $this->attr($node, 'condition'),
                'requires' => $this->list($this->attr($node, 'requires', '')),
                'cardinality' => $this->attr($node, 'cardinality', 'one'),
            ];
        }

        return $joins;
    }

    /**
     * Converts a dataset's `<field>` children, keyed by field code.
     *
     * @param \DOMElement $datasetNode
     * @return array<string,array<string,mixed>>
     */
    private function convertFields(\DOMElement $datasetNode): array
    {
        $fields = [];
        foreach ($this->children($datasetNode, 'field') as $node) {
            $code = $this->requiredAttr($node, 'code');
            $fields[$code] = [
                'code' => $code,
                'label' => $this->attr($node, 'label'),
                'type' => $this->attr($node, 'type'),
                'description' => $this->attr($node, 'description'),
                'acl' => $this->attr($node, 'acl'),
                'sourceMappings' => $this->convertSourceMappings($node),
            ];
        }

        return $fields;
    }

    /**
     * Converts a field's per-source mapping children (e.g. `<mysql>`), keyed by source name.
     *
     * @param \DOMElement $fieldNode
     * @return array<string,array<string,mixed>>
     */
    private function convertSourceMappings(\DOMElement $fieldNode): array
    {
        $mappings = [];
        foreach ($fieldNode->childNodes as $node) {
            if ($node->nodeType !== XML_ELEMENT_NODE) {
                continue;
            }
            /** @var \DOMElement $node */
            $mappings[$node->nodeName] = [
                'expression' => $this->attr($node, 'expression'),
                'expressionProvider' => $this->attr($node, 'expressionProvider'),
                'joins' => $this->list($this->attr($node, 'joins', '')),
            ];
        }

        return $mappings;
    }

    /**
     * Converts a dataset's `<metric>` children, keyed by metric code.
     *
     * @param \DOMElement $datasetNode
     * @return array<string,array<string,mixed>>
     */
    private function convertMetrics(\DOMElement $datasetNode): array
    {
        $metrics = [];
        foreach ($this->children($datasetNode, 'metric') as $node) {
            $code = $this->requiredAttr($node, 'code');
            $metrics[$code] = [
                'code' => $code,
                'label' => $this->attr($node, 'label'),
                'description' => $this->attr($node, 'description'),
                'format' => $this->attr($node, 'format'),
                'hidden' => $this->bool($this->attr($node, 'hidden', 'false')),
                'lowerIsBetter' => $this->bool($this->attr($node, 'lowerIsBetter', 'false')),
                'aggregate' => $this->attr($node, 'aggregate'),
                'fieldCode' => $this->attr($node, 'field'),
                'conditionFieldCode' => $this->attr($node, 'condition'),
                'formula' => $this->attr($node, 'formula'),
                'virtual' => $this->bool($this->attr($node, 'virtual', 'false')),
            ];
        }

        return $metrics;
    }

    /**
     * Converts a dataset's `<dimension>` children, keyed by dimension code.
     *
     * @param \DOMElement $datasetNode
     * @return array<string,array<string,mixed>>
     */
    private function convertDimensions(\DOMElement $datasetNode): array
    {
        $dimensions = [];
        foreach ($this->children($datasetNode, 'dimension') as $node) {
            $code = $this->requiredAttr($node, 'code');
            $dimensions[$code] = [
                'code' => $code,
                'label' => $this->attr($node, 'label'),
                'fieldCode' => $this->attr($node, 'field'),
                'granularities' => $this->list($this->attr($node, 'granularities', '')),
                'labelResolver' => $this->attr($node, 'labelResolver'),
                'nonAdditive' => $this->bool($this->attr($node, 'nonAdditive', 'false')),
            ];
        }

        return $dimensions;
    }

    /**
     * Converts every top level `<preset>` element into a keyed array.
     *
     * @param \DOMXPath $xpath
     * @return array<string,array<string,mixed>>
     */
    private function convertPresets(\DOMXPath $xpath): array
    {
        $presets = [];
        foreach ($xpath->query('/config/preset') ?: [] as $node) {
            /** @var \DOMElement $node */
            $code = $this->requiredAttr($node, 'code');
            $presets[$code] = [
                'code' => $code,
                'area' => $this->attr($node, 'area'),
                'dataset' => $this->attr($node, 'dataset'),
                'label' => $this->attr($node, 'label'),
                'description' => $this->attr($node, 'description', ''),
                'sources' => $this->list($this->attr($node, 'sources', '')),
                'presetQuery' => $this->attr($node, 'presetQuery'),
                'compareTo' => $this->attr($node, 'compareTo'),
                'dateRange' => $this->attr($node, 'dateRange'),
                'alternateDimension' => $this->attr($node, 'alternateDimension'),
                'metrics' => $this->convertPresetMetrics($node),
                'dimensions' => $this->convertPresetDimensions($node),
                'filters' => $this->convertPresetFilters($node),
                'sort' => $this->convertPresetSort($node),
                'chart' => $this->convertPresetChart($node),
            ];
        }

        return $presets;
    }

    /**
     * Converts a preset's `<metric>` children into a list of metric codes.
     *
     * @param \DOMElement $presetNode
     * @return string[]
     */
    private function convertPresetMetrics(\DOMElement $presetNode): array
    {
        $metrics = [];
        foreach ($this->children($presetNode, 'metric') as $node) {
            $metrics[] = $this->requiredAttr($node, 'code');
        }

        return $metrics;
    }

    /**
     * Converts a preset's `<dimension>` children into a list of code/granularity pairs.
     *
     * @param \DOMElement $presetNode
     * @return array<int,array{code:string,granularity:string|null}>
     */
    private function convertPresetDimensions(\DOMElement $presetNode): array
    {
        $dimensions = [];
        foreach ($this->children($presetNode, 'dimension') as $node) {
            $dimensions[] = [
                'code' => $this->requiredAttr($node, 'code'),
                'granularity' => $this->attr($node, 'granularity'),
            ];
        }

        return $dimensions;
    }

    /**
     * Converts a preset's `<filter>` children into a list of field/operator/value triples.
     *
     * @param \DOMElement $presetNode
     * @return array<int,array{field:string,operator:string,value:string[]}>
     */
    private function convertPresetFilters(\DOMElement $presetNode): array
    {
        $filters = [];
        foreach ($this->children($presetNode, 'filter') as $node) {
            $filters[] = [
                'field' => $this->requiredAttr($node, 'field'),
                'operator' => $this->requiredAttr($node, 'operator'),
                'value' => $this->list($this->attr($node, 'value', '')),
            ];
        }

        return $filters;
    }

    /**
     * Converts a preset's `<sort>` children into a list of metric/direction pairs.
     *
     * @param \DOMElement $presetNode
     * @return array<int,array{metric:string,direction:string}>
     */
    private function convertPresetSort(\DOMElement $presetNode): array
    {
        $sort = [];
        foreach ($this->children($presetNode, 'sort') as $node) {
            $sort[] = [
                'metric' => $this->requiredAttr($node, 'by'),
                'direction' => $this->requiredAttr($node, 'direction', 'asc'),
            ];
        }

        return $sort;
    }

    /**
     * @param \DOMElement $presetNode
     * @return array{type:string|null,metrics:string[],dimensions:array<int,array{code:string,granularity:string|null}>,sortBy:string|null,sortDirection:string|null}|null
     */
    protected function convertPresetChart(\DOMElement $presetNode): ?array
    {
        $chartNode = $this->children($presetNode, 'chart')[0] ?? null;
        if ($chartNode === null) {
            return null;
        }

        return [
            'type' => $this->attr($chartNode, 'type'),
            'metrics' => $this->convertPresetMetrics($chartNode),
            'dimensions' => $this->convertPresetDimensions($chartNode),
            'sortBy' => $this->attr($chartNode, 'sortBy'),
            'sortDirection' => $this->attr($chartNode, 'sortDirection'),
        ];
    }

    /**
     * Direct element children of the given node matching a tag name.
     *
     * @param \DOMElement $node
     * @param string $tagName
     * @return \DOMElement[]
     */
    private function children(\DOMElement $node, string $tagName): array
    {
        $children = [];
        foreach ($node->childNodes as $child) {
            if ($child->nodeType === XML_ELEMENT_NODE && $child->nodeName === $tagName) {
                /** @var \DOMElement $child */
                $children[] = $child;
            }
        }

        return $children;
    }

    /**
     * Reads an attribute value, or the given default when absent.
     *
     * @param \DOMElement $node
     * @param string $name
     * @param string|null $default
     */
    private function attr(\DOMElement $node, string $name, ?string $default = null): ?string
    {
        $attribute = $node->attributes->getNamedItem($name);

        return $attribute !== null ? $attribute->nodeValue : $default;
    }

    /**
     * Reads an attribute the XSD declares as required (or that has a fallback), as a non-null string.
     *
     * @param \DOMElement $node
     * @param string $name
     * @param string $default
     */
    private function requiredAttr(\DOMElement $node, string $name, string $default = ''): string
    {
        return $this->attr($node, $name) ?? $default;
    }

    /**
     * Casts an XSD boolean literal ("true"/"1") to a native bool.
     *
     * @param string|null $value
     */
    private function bool(?string $value): bool
    {
        return $value === 'true' || $value === '1';
    }

    /**
     * Splits a comma separated attribute value into a trimmed, non-empty list.
     *
     * @param string|null $value
     * @return string[]
     */
    private function list(?string $value): array
    {
        if ($value === null || trim($value) === '') {
            return [];
        }

        $items = array_map(trim(...), explode(',', $value));

        return array_values(array_filter($items, static fn (string $item): bool => $item !== ''));
    }
}
