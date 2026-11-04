/**
 * Builds the Chart.js configuration of a dashboard chart from an Advanced Report result.
 *
 * One value axis only: the metrics drawn together are the ones of the selected format group
 * (see groupMetrics). Categorical colors follow a fixed order; pie/doughnut slices past the
 * palette fold into "Other".
 */
define([
    'mage/translate',
    'MageOS_AdvancedReport/js/report/lib/format'
], function ($t, formatter) {
    'use strict';

    var PALETTE = ['#2a78d6', '#eb6834', '#1baf7a', '#eda100', '#e87ba4', '#008300', '#6250d6', '#e34948'],
        COMPARISON_COLOR = '#8f8f8f',
        GRID_COLOR = 'rgba(0, 0, 0, 0.06)',
        TEXT_COLOR = '#514943',
        MAX_SLICES = PALETTE.length - 1,
        ROUND_TYPES = ['pie', 'doughnut'];
    const COMPARISON_YEAR_COLOR = '#eda100';

    /**
     * @param {String} hex
     * @param {Number} alpha
     * @return {String}
     */
    function withAlpha(hex, alpha) {
        var value = parseInt(hex.slice(1), 16);

        return 'rgba(' + (value >> 16 & 255) + ', ' + (value >> 8 & 255) + ', ' + (value & 255) + ', ' + alpha + ')';
    }

    /**
     * @param {*} value
     * @return {Number|null}
     */
    function toNumber(value) {
        var number;

        if (value === null || value === undefined || value === '') {
            return null;
        }
        number = Number(value);

        return isNaN(number) ? null : number;
    }

    /**
     * Metric columns grouped by format, in column order: [{format, label, columns}].
     *
     * @param {Object} result
     * @return {Array}
     */
    function groupMetrics(result) {
        var groups = [];

        (result && result.columns || []).forEach(function (column) {
            var group;

            if (column.type !== 'metric') {
                return;
            }
            group = groups.find(function (candidate) {
                return candidate.format === column.format;
            });
            if (!group) {
                group = {format: column.format, columns: []};
                groups.push(group);
            }
            group.columns.push(column);
        });

        groups.forEach(function (group) {
            group.label = group.columns.map(function (column) {
                return column.label;
            }).join(' / ');
        });

        return groups;
    }

    /**
     * @param {Object} result
     * @return {Object|null}
     */
    function firstDimension(result) {
        return (result && result.columns || []).find(function (column) {
            return column.type === 'dimension';
        }) || null;
    }

    /**
     * @param {Object} result
     * @param {Object} dimension
     * @param {Boolean} isDate
     * @param {String} locale
     * @return {String[]}
     */
    function buildLabels(result, dimension, isDate, locale) {
        return result.rows.map(function (row) {
            var value = row.dims[dimension.code];

            if (value === null || value === undefined || value === '') {
                return $t('(none)');
            }

            return isDate ? formatter.formatDate(value, locale) : String(value);
        });
    }

    /**
     * @param {Object} result
     * @param {Object} column
     * @param {String[]} labels
     * @return {{labels: String[], data: Array}}
     */
    function foldSlices(result, column, labels) {
        var values = result.rows.map(function (row) {
                return toNumber(row.metrics[column.code]);
            }),
            other;

        if (values.length <= MAX_SLICES + 1) {
            return {labels: labels, data: values};
        }
        other = values.slice(MAX_SLICES).reduce(function (sum, value) {
            return sum + (value || 0);
        }, 0);

        return {
            labels: labels.slice(0, MAX_SLICES).concat([$t('Other')]),
            data: values.slice(0, MAX_SLICES).concat([other])
        };
    }

    /**
     * @param {Object} chart Chart definition from the view model.
     * @param {Object} result Advanced Report result.
     * @param {Object} group Selected metric group.
     * @param {String} locale
     * @return {Object|null} Chart.js configuration, null when there is nothing to draw.
     */
    function build(chart, result, group, locale) {
        var dimension = firstDimension(result),
            context = {currencyCode: result.currency_code, locale: locale},
            isRound = ROUND_TYPES.indexOf(chart.type) !== -1,
            isHorizontal = chart.type === 'horizontalBar',
            isLine = chart.type === 'line',
            isDate = dimension && dimension.format === 'date',
            labels,
            datasets,
            slices,
            valueAxis,
            categoryAxis,
            format;

        if (!dimension || !group || !result.rows.length) {
            return null;
        }
        format = function (value) {
            return formatter.format(value, group.format, context);
        };
        labels = buildLabels(result, dimension, isDate, locale);

        if (isRound) {
            slices = foldSlices(result, group.columns[0], labels);

            return {
                type: chart.type,
                data: {
                    labels: slices.labels,
                    datasets: [{
                        label: group.columns[0].label,
                        data: slices.data,
                        backgroundColor: slices.data.map(function (value, index) {
                            return PALETTE[index];
                        }),
                        borderColor: '#fff',
                        borderWidth: 2
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    cutout: chart.type === 'doughnut' ? '62%' : 0,
                    plugins: {
                        legend: {position: 'right', labels: {color: TEXT_COLOR, boxWidth: 12, boxHeight: 12}},
                        tooltip: {
                            callbacks: {
                                /**
                                 * @param {Object} item
                                 * @return {String}
                                 */
                                label: function (item) {
                                    return ' ' + item.label + ': ' + format(item.raw);
                                }
                            }
                        }
                    }
                }
            };
        }

        datasets = group.columns.map(function (column, index) {
            var color = PALETTE[index];

            return {
                label: column.label,
                data: result.rows.map(function (row) {
                    return toNumber(row.metrics[column.code]);
                }),
                borderColor: color,
                backgroundColor: isLine ? withAlpha(color, 0.08) : color,
                fill: isLine && group.columns.length === 1,
                borderWidth: 2,
                borderRadius: isLine ? 0 : 4,
                borderSkipped: 'start',
                pointRadius: isLine && result.rows.length > 31 ? 0 : 3,
                pointHoverRadius: 5,
                cubicInterpolationMode: 'monotone',
                maxBarThickness: 28
            };
        });

        if (isLine) {
            [
                {key: 'comparison', label: $t('previous period'), color: COMPARISON_COLOR},
                {key: 'comparison_year', label: $t('previous year'), color: COMPARISON_YEAR_COLOR}
            ].forEach(function (comparison) {
                group.columns.forEach(function (column) {
                    if (!result.rows.some(function (row) {
                        return row[comparison.key] && row[comparison.key][column.code];
                    })) {
                        return;
                    }
                    datasets.push({
                        label: column.label + ' (' + comparison.label + ')',
                        data: result.rows.map(function (row) {
                            const previous = row[comparison.key] && row[comparison.key][column.code];

                            return previous ? toNumber(previous.value) : null;
                        }),
                        borderColor: comparison.color,
                        backgroundColor: 'transparent',
                        borderDash: [5, 4],
                        borderWidth: 2,
                        pointRadius: 0,
                        pointHoverRadius: 4,
                        cubicInterpolationMode: 'monotone'
                    });
                });
            });
        }

        valueAxis = {
            beginAtZero: true,
            grid: {color: GRID_COLOR},
            border: {display: false},
            ticks: {
                color: TEXT_COLOR,
                maxTicksLimit: 6,

                /**
                 * @param {Number} value
                 * @return {String}
                 */
                callback: function (value) {
                    return format(value);
                }
            }
        };
        categoryAxis = {
            grid: {display: false},
            ticks: {
                color: TEXT_COLOR,
                autoSkip: true,
                maxRotation: 0,

                /**
                 * Truncates long category labels (SKUs, product names) on the axis; the tooltip keeps the full text.
                 *
                 * @param {Number} value
                 * @return {String}
                 */
                callback: function (value) {
                    var label = this.getLabelForValue(value);

                    return label.length > 24 ? label.slice(0, 23) + '…' : label;
                }
            }
        };

        return {
            type: isLine ? 'line' : 'bar',
            data: {labels: labels, datasets: datasets},
            options: {
                responsive: true,
                maintainAspectRatio: false,
                indexAxis: isHorizontal ? 'y' : 'x',
                interaction: {mode: 'index', intersect: false, axis: isHorizontal ? 'y' : 'x'},
                scales: isHorizontal ? {x: valueAxis, y: categoryAxis} : {x: categoryAxis, y: valueAxis},
                plugins: {
                    legend: {
                        display: datasets.length > 1,
                        position: 'bottom',
                        labels: {color: TEXT_COLOR, boxWidth: 12, boxHeight: 12}
                    },
                    tooltip: {
                        callbacks: {
                            /**
                             * @param {Object} item
                             * @return {String}
                             */
                            label: function (item) {
                                return ' ' + item.dataset.label + ': ' + format(item.raw);
                            }
                        }
                    }
                }
            }
        };
    }

    return {
        PALETTE: PALETTE,
        groupMetrics: groupMetrics,
        build: build
    };
});
