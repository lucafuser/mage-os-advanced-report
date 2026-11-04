<?php

declare(strict_types=1);

namespace MageOS\AdvancedReport\ViewModel;

use Magento\Backend\Model\Auth\Session;
use Magento\Backend\Model\UrlInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\AuthorizationInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Locale\ResolverInterface as LocaleResolverInterface;
use Magento\Framework\View\Element\Block\ArgumentInterface;
use MageOS\AdvancedReport\Model\Config;
use MageOS\AdvancedReport\Model\Definition\Dataset;
use MageOS\AdvancedReport\Model\Definition\DefinitionRegistry;
use MageOS\AdvancedReport\Model\Definition\Dimension;
use MageOS\AdvancedReport\Model\Definition\Field;
use MageOS\AdvancedReport\Model\Definition\FilterOperator;
use MageOS\AdvancedReport\Model\Definition\Granularity;
use MageOS\AdvancedReport\Model\Definition\Metric;
use MageOS\AdvancedReport\Model\Definition\NoSuchDefinitionException;
use MageOS\AdvancedReport\Model\Exploration\InitialStateResolver;
use MageOS\AdvancedReport\Model\Security\DatasetAccess;

/**
 * Bootstrap configuration of the exploration page JS app.
 *
 * JSON contract (framework-agnostic, reusable by a future storefront/Hyva UI):
 * - datasets: [{code, label, metrics: [{code, label, description, format, lowerIsBetter}],
 *   dimensions: [{code, label, granularities: string[], nonAdditive}],
 *   filters: [{code, label, type, operators: string[]}]}]; only datasets the admin may read;
 *   hidden and virtual metrics are omitted from the pickers.
 * - request: initial report request (preset defaults, saved definition or empty exploration).
 * - alternateDimension: null | {primary, alternate} dimension codes the preset toggles between (e.g. parent_sku / sku).
 * - chart: null | {type, metrics, dimensions, sort, defaultDimensions}.
 * - saved: null | {id, name, is_shared, is_owner}.
 * - urls: {data, save, delete, export, index, view, config}; data/save/delete carry `isAjax=true`; view holds an
 *   `__ID__` placeholder for the saved report id;
 *   config is the configuration page URL, null when the admin may not edit it.
 * - permissions: {canSave, canShare, canExport, canOverrideSource}.
 * - firstDayOfWeek: int (0 = Sunday).
 * - locale: admin interface locale (e.g. `it_IT`), used to format dates and numbers.
 */
class Exploration implements ArgumentInterface
{
    private const string ACL_SAVED_MANAGE = 'MageOS_AdvancedReport::saved_manage';
    private const string ACL_SAVED_SHARE = 'MageOS_AdvancedReport::saved_share';
    private const string ACL_EXPORT = 'MageOS_AdvancedReport::export';
    private const string ACL_CONFIG = 'MageOS_AdvancedReport::config';

    /**
     * @param DefinitionRegistry $registry
     * @param InitialStateResolver $initialStateResolver
     * @param AuthorizationInterface $authorization
     * @param Session $authSession
     * @param UrlInterface $urlBuilder
     * @param RequestInterface $request
     * @param Config $config
     * @param LocaleResolverInterface $localeResolver
     * @param DatasetAccess $datasetAccess
     */
    public function __construct(
        private readonly DefinitionRegistry $registry,
        private readonly InitialStateResolver $initialStateResolver,
        private readonly AuthorizationInterface $authorization,
        private readonly Session $authSession,
        private readonly UrlInterface $urlBuilder,
        private readonly RequestInterface $request,
        private readonly Config $config,
        private readonly LocaleResolverInterface $localeResolver,
        private readonly DatasetAccess $datasetAccess,
    ) {
    }

    /**
     * @var array{request:array<string,mixed>,saved:array<string,mixed>|null}|null
     */
    private ?array $state = null;

    /**
     * JSON config for `data-mage-init` (escape with escapeHtmlAttr) or a `text/x-magento-init` script.
     *
     * @throws NoSuchEntityException
     */
    public function getJsConfig(): string
    {
        $state = $this->resolveState();

        return (string) json_encode(
            [
                'datasets' => $this->getDatasets(),
                'request' => $state['request'],
                'alternateDimension' => $this->getAlternateDimension($state['request']['preset'] ?? null),
                'chart' => $this->getChart($state['request']['preset'] ?? null),
                'saved' => $state['saved'],
                'urls' => $this->getUrls(),
                'permissions' => $this->getPermissions(),
                'firstDayOfWeek' => $this->config->getFirstDayOfWeek(null),
                'locale' => $this->localeResolver->getLocale(),
            ],
            JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR
        );
    }

    /**
     * Translated description of the preset the page opens with; empty when there is none.
     */
    public function getPresetDescription(): string
    {
        try {
            $presetCode = $this->resolveState()['request']['preset'] ?? null;
        } catch (NoSuchEntityException) {
            return '';
        }
        if (!\is_string($presetCode) || $presetCode === '') {
            return '';
        }
        try {
            $description = $this->registry->getPreset($presetCode)->description;
        } catch (NoSuchDefinitionException) {
            return '';
        }

        return $description !== '' ? (string) __($description) : '';
    }

    /**
     * @throws NoSuchEntityException
     * @return array{request:array<string,mixed>,saved:array<string,mixed>|null}
     */
    private function resolveState(): array
    {
        return $this->state ??= $this->initialStateResolver->resolve(
            $this->getStringParam('preset'),
            $this->getStringParam('id') !== null ? (int) $this->getStringParam('id') : null,
            (int) $this->authSession->getUser()?->getId(),
        );
    }

    /**
     * @return array{primary:string,alternate:string}|null
     */
    private function getAlternateDimension(mixed $presetCode): ?array
    {
        if (!\is_string($presetCode) || $presetCode === '') {
            return null;
        }
        try {
            $preset = $this->registry->getPreset($presetCode);
        } catch (NoSuchDefinitionException) {
            return null;
        }
        $primary = $preset->dimensions[0]['code'] ?? null;

        return $preset->alternateDimension !== null && $primary !== null
            ? ['primary' => $primary, 'alternate' => $preset->alternateDimension]
            : null;
    }

    /**
     * @param mixed $presetCode
     * @return array<string,mixed>|null
     */
    protected function getChart(mixed $presetCode): ?array
    {
        if (!\is_string($presetCode) || $presetCode === '') {
            return null;
        }
        try {
            $preset = $this->registry->getPreset($presetCode);
        } catch (NoSuchDefinitionException) {
            return null;
        }

        return $preset->resolveChart() + [
            'defaultDimensions' => array_map(
                static fn (array $dimension): array => [
                    'code' => $dimension['code'],
                    'granularity' => $dimension['granularity']?->value,
                ],
                $preset->dimensions
            ),
        ];
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function getDatasets(): array
    {
        $datasets = [];
        foreach ($this->registry->getDatasets() as $dataset) {
            if (!$this->datasetAccess->isAllowed($dataset)) {
                continue;
            }
            $datasets[] = [
                'code' => $dataset->code,
                'label' => (string) __($dataset->label),
                'metrics' => $this->getMetrics($dataset),
                'dimensions' => array_values(array_map(
                    $this->dimensionToArray(...),
                    array_filter(
                        $dataset->dimensions,
                        fn (Dimension $dimension): bool => $this->isDimensionAllowed($dataset, $dimension)
                    )
                )),
                'filters' => array_values(array_map(
                    $this->fieldToArray(...),
                    array_filter($dataset->fields, $this->datasetAccess->isFieldAllowed(...))
                )),
            ];
        }

        return $datasets;
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function getMetrics(Dataset $dataset): array
    {
        $metrics = array_filter(
            $dataset->metrics,
            static fn (Metric $metric): bool => !$metric->hidden && !$metric->isVirtual()
        );

        return array_values(array_map(
            static fn (Metric $metric): array => [
                'code' => $metric->code,
                'label' => (string) __($metric->label),
                'description' => $metric->description !== null ? (string) __($metric->description) : null,
                'format' => $metric->format,
                'lowerIsBetter' => $metric->lowerIsBetter,
            ],
            $metrics
        ));
    }

    private function isDimensionAllowed(Dataset $dataset, Dimension $dimension): bool
    {
        $field = $dataset->fields[$dimension->fieldCode] ?? null;

        return $field === null || $this->datasetAccess->isFieldAllowed($field);
    }

    /**
     * @return array<string,mixed>
     */
    private function dimensionToArray(Dimension $dimension): array
    {
        return [
            'code' => $dimension->code,
            'label' => (string) __($dimension->label),
            'granularities' => array_map(
                static fn (Granularity $granularity): string => $granularity->value,
                $dimension->getGranularities()
            ),
            'nonAdditive' => $dimension->nonAdditive,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function fieldToArray(Field $field): array
    {
        return [
            'code' => $field->code,
            'label' => (string) __($field->label),
            'type' => $field->type->value,
            'operators' => array_map(
                static fn (FilterOperator $operator): string => $operator->value,
                $field->type->allowedOperators()
            ),
        ];
    }

    /**
     * @return array<string,string|null>
     */
    private function getUrls(): array
    {
        $ajax = ['_query' => ['isAjax' => 'true']];

        return [
            'data' => $this->urlBuilder->getUrl('advancedreport/report/data', $ajax),
            'save' => $this->urlBuilder->getUrl('advancedreport/report/save', $ajax),
            'delete' => $this->urlBuilder->getUrl('advancedreport/report/delete', $ajax),
            'export' => $this->urlBuilder->getUrl('advancedreport/report/export'),
            'index' => $this->urlBuilder->getUrl('advancedreport/report/index'),
            'view' => $this->urlBuilder->getUrl('advancedreport/report/view', ['id' => '__ID__']),
            'config' => $this->authorization->isAllowed(self::ACL_CONFIG)
                ? $this->urlBuilder->getUrl('adminhtml/system_config/edit', ['section' => 'mageos_advancedreport'])
                : null,
        ];
    }

    /**
     * @return array<string,bool>
     */
    private function getPermissions(): array
    {
        return [
            'canSave' => $this->authorization->isAllowed(self::ACL_SAVED_MANAGE),
            'canShare' => $this->authorization->isAllowed(self::ACL_SAVED_SHARE),
            'canExport' => $this->authorization->isAllowed(self::ACL_EXPORT),
            'canOverrideSource' => $this->authorization->isAllowed(self::ACL_CONFIG),
        ];
    }

    private function getStringParam(string $name): ?string
    {
        $value = $this->request->getParam($name);

        return \is_scalar($value) && (string) $value !== '' ? (string) $value : null;
    }
}
