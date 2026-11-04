/**
 * Chart child of the exploration app. Draws with the shared chart configuration: the preset chart definition while
 * the report keeps its default dimensions, otherwise the first dimension and metric. The type can be changed from the
 * chart card for the current page.
 */
define([
    'jquery',
    'ko',
    'mage/translate',
    'chartjs/Chart.min',
    'MageOS_AdvancedReport/js/report/lib/api',
    'MageOS_AdvancedReport/js/report/lib/chart-config',
    'text!MageOS_AdvancedReport/template/report/results/chart.html'
], function ($, ko, $t, Chart, api, chartConfig, template) {
    'use strict';

    const DATE_SERIES_LIMIT = 366;

    /**
     * @param {Array} dimensions
     * @return {String}
     */
    function dimensionKeys(dimensions) {
        return (dimensions || []).map(function (dimension) {
            return dimension.code + '|' + (dimension.granularity || '');
        }).join(',');
    }

    ko.bindingHandlers.advancedreportChart = {
        /**
         * @param {HTMLElement} element
         * @param {Function} valueAccessor
         */
        update: function (element, valueAccessor) {
            const config = ko.unwrap(valueAccessor());

            if (element.chart) {
                element.chart.destroy();
                element.chart = null;
            }

            if (config) {
                element.chart = new Chart(element.getContext('2d'), config);
            }
        }
    };

    /**
     * @param {Object} app
     * @return {Object}
     */
    return function (app) {
        const preset = app.config.chart,
            selectedType = ko.observable(null),
            groupIndex = ko.observable(0),
            ownResult = ko.observable(null),
            usesPresetChart = ko.pureComputed(function () {
                return !!preset && dimensionKeys(app.state().dimensions) === dimensionKeys(preset.defaultDimensions);
            }),
            needsOwnRequest = ko.pureComputed(function () {
                return usesPresetChart() && dimensionKeys(preset.dimensions) !== dimensionKeys(preset.defaultDimensions);
            }),
            source = ko.pureComputed(function () {
                return needsOwnRequest() ? ownResult() : app.result();
            }),
            drawn = ko.pureComputed(function () {
                const result = source();
                let metrics;

                if (!result) {
                    return null;
                }
                metrics = result.columns.filter(function (column) {
                    return column.type === 'metric';
                });
                let wanted = usesPresetChart() ? metrics.filter(function (column) {
                    return preset.metrics.indexOf(column.code) !== -1;
                }) : [];

                if (!wanted.length) {
                    wanted = metrics.slice(0, 1);
                }

                return $.extend({}, result, {
                    columns: result.columns.filter(function (column) {
                        return column.type !== 'metric' || wanted.indexOf(column) !== -1;
                    })
                });
            }),
            groups = ko.pureComputed(function () {
                return chartConfig.groupMetrics(drawn());
            }),
            activeIndex = ko.pureComputed(function () {
                return groups()[groupIndex()] ? groupIndex() : 0;
            }),
            defaultType = ko.pureComputed(function () {
                const result = drawn(),
                    dimension = result && result.columns.find(function (column) {
                        return column.type === 'dimension';
                    });

                if (usesPresetChart()) {
                    return preset.type;
                }

                return dimension && dimension.format === 'date' ? 'line' : 'horizontalBar';
            }),
            type = ko.pureComputed({
                read: function () {
                    return selectedType() || defaultType();
                },
                write: selectedType
            }),
            config = ko.pureComputed(function () {
                const result = drawn(),
                    group = groups()[activeIndex()];

                return result && group ? chartConfig.build({type: type()}, result, group, app.locale) : null;
            });
        let inflight = null;

        function loadOwnResult() {
            const hasDates = preset.dimensions.some(function (dimension) {
                    return !!dimension.granularity;
                }),
                request = $.extend({}, app.state(), {
                    dimensions: preset.dimensions,
                    metrics: preset.metrics,
                    sort: preset.sort,
                    limit: hasDates ? DATE_SERIES_LIMIT : app.pageSize,
                    offset: 0
                });
            let call;

            if (inflight) {
                inflight.xhr.abort();
            }
            delete request.bypassCache;
            call = api.fetch(app.config.urls.data, request);
            inflight = call;
            call.promise.done(function (result) {
                ownResult(result);
            }).fail(function (failure) {
                if (!failure.aborted) {
                    ownResult(null);
                    app.notify($t(failure.message), 'error');
                }
            }).always(function () {
                if (inflight === call) {
                    inflight = null;
                }
            });
        }

        app.result.subscribe(function () {
            if (needsOwnRequest()) {
                loadOwnResult();
            }
        });

        return {
            html: template,
            type: type,
            typeOptions: [
                {value: 'line', label: $t('Line')},
                {value: 'bar', label: $t('Vertical bars')},
                {value: 'horizontalBar', label: $t('Horizontal bars')},
                {value: 'doughnut', label: $t('Doughnut')},
                {value: 'pie', label: $t('Pie')}
            ],
            labels: {chartType: $t('Chart type')},
            groups: groups,
            groupIndex: groupIndex,
            activeIndex: activeIndex,
            config: config,
            hasChart: ko.pureComputed(function () {
                return !!config();
            })
        };
    };
});
