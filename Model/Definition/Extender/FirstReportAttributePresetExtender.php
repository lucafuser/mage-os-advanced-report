<?php

declare(strict_types=1);

namespace MageOS\AdvancedReport\Model\Definition\Extender;

use MageOS\AdvancedReport\Model\Config;
use MageOS\AdvancedReport\Model\Definition\Preset;
use MageOS\AdvancedReport\Model\Definition\PresetExtenderInterface;

/**
 * Replaces the `{first_report_attribute}` dimension placeholder with the synthesized
 * `attr_<code>` dimension of the first configured report attribute, dropping the presets
 * that use it when no report attribute is configured.
 */
class FirstReportAttributePresetExtender implements PresetExtenderInterface
{
    public const string PLACEHOLDER = '{first_report_attribute}';

    public function __construct(private readonly Config $config)
    {
    }

    public function extend(array $presets): array
    {
        $firstAttribute = $this->config->getReportAttributes()[0] ?? null;

        $extended = [];
        foreach ($presets as $code => $preset) {
            if (!$this->usesPlaceholder($preset)) {
                $extended[$code] = $preset;
                continue;
            }
            if ($firstAttribute !== null) {
                $extended[$code] = $this->resolve($preset, 'attr_' . $firstAttribute);
            }
        }

        return $extended;
    }

    private function usesPlaceholder(Preset $preset): bool
    {
        return \in_array(self::PLACEHOLDER, array_column($preset->dimensions, 'code'), true);
    }

    private function resolve(Preset $preset, string $dimensionCode): Preset
    {
        $dimensions = array_map(
            static fn (array $dimension): array => $dimension['code'] === self::PLACEHOLDER
                ? [...$dimension, 'code' => $dimensionCode]
                : $dimension,
            $preset->dimensions
        );

        return new Preset(
            $preset->code,
            $preset->area,
            $preset->label,
            $preset->dataset,
            $preset->metrics,
            $dimensions,
            $preset->filters,
            $preset->sort,
            $preset->compareTo,
            $preset->dateRange,
            $preset->sources,
            $preset->presetQuery,
            $preset->alternateDimension,
            $preset->description,
            $preset->chart,
        );
    }
}
