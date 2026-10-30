<?php

require_once __DIR__ . '/../../core/JsonFileStore.php';
require_once __DIR__ . '/../Requests/ApiRequestException.php';
require_once __DIR__ . '/../Security/SecurityConfiguration.php';

final class ChartConfigurationService
{
    private const CHART_TYPES = ['bar', 'line', 'area', 'pie', 'doughnut', 'treemap', 'radial-bar'];
    private const PERSISTED_CHART_TYPES = ['bar', 'line', 'area', 'pie', 'doughnut', 'treemap', 'radial-bar', 'funnel'];
    private const CATEGORY_COLOR_TYPES = ['pie', 'doughnut', 'treemap', 'radial-bar'];
    private string $directory;

    public function __construct(?string $directory = null)
    {
        $configured = $directory ?? getenv('GENERIC_FRONTEND_DASHBOARD_CONFIG_DIR');
        if ((!is_string($configured) || trim($configured) === '') && !SecurityConfiguration::isProduction()) {
            $configured = dirname(ROOT_PATH) . '/Frontend/Generic-Reporting-Framework/src/config/dashboards';
        }
        if (!is_string($configured) || trim($configured) === '') {
            throw new ApiRequestException('Chart configuration storage is unavailable.', 'CHART_CONFIGURATION_UNAVAILABLE', [], 503);
        }
        $this->directory = rtrim($configured, '/\\');
        if (!is_dir($this->directory)) {
            throw new ApiRequestException('Chart configuration storage is unavailable.', 'CHART_CONFIGURATION_UNAVAILABLE', [], 503);
        }
    }

    public function colors(string $dashboardId): array
    {
        [$path, $dashboard] = $this->dashboard($dashboardId);
        $revision = $this->revision($path);
        return array_values(array_filter(array_map(
            fn ($widget) => $this->chartRecord($dashboardId, $widget, $revision),
            is_array($dashboard['widgets'] ?? null) ? $dashboard['widgets'] : []
        )));
    }

    public function update(string $dashboardId, string $widgetId, array $colors, string $expectedRevision): array
    {
        [$path, $dashboard] = $this->dashboard($dashboardId);
        foreach ($dashboard['widgets'] ?? [] as $widget) {
            if (is_array($widget) && ($widget['id'] ?? null) === $widgetId) {
                $type = $widget['chartType'] ?? 'bar';
                $options = is_array($widget['chartOptions'] ?? null) ? $widget['chartOptions'] : [];
                return $this->updateConfiguration(
                    $dashboardId,
                    $widgetId,
                    $type,
                    in_array($type, self::CATEGORY_COLOR_TYPES, true) ? ($options['seriesColors'] ?? []) : $colors,
                    in_array($type, self::CATEGORY_COLOR_TYPES, true) ? $colors : ($options['categoryColors'] ?? []),
                    false,
                    $expectedRevision,
                    is_string($options['numberFormat'] ?? null) ? $options['numberFormat'] : null,
                    is_string($options['valueFormat'] ?? null) ? $options['valueFormat'] : null,
                    is_string($options['orientation'] ?? null) ? $options['orientation'] : null
                );
            }
        }
        throw new ApiRequestException('Chart widget was not found.', 'CHART_WIDGET_NOT_FOUND', [], 404);
    }

    public function updateConfiguration(
        string $dashboardId,
        string $widgetId,
        ?string $chartType,
        array $seriesColors,
        array $categoryColors,
        bool $reset,
        string $expectedRevision,
        ?string $numberFormat = null,
        ?string $valueFormat = null,
        ?string $orientation = null
    ): array
    {
        $lockPath = $this->directory . DIRECTORY_SEPARATOR . '.chart-configuration.lock';
        $lock = @fopen($lockPath, 'c');
        if ($lock === false || !flock($lock, LOCK_EX)) {
            if (is_resource($lock)) fclose($lock);
            throw new ApiRequestException('Chart configuration is temporarily unavailable.', 'CHART_CONFIGURATION_UNAVAILABLE', [], 503);
        }
        try {
            [$path, $dashboard] = $this->dashboard($dashboardId);
            if (!hash_equals($this->revision($path), $expectedRevision)) {
                throw new ApiRequestException('The dashboard configuration changed. Reload it before saving.', 'CHART_CONFIGURATION_CONFLICT', [], 409);
            }
            $index = null;
            foreach ($dashboard['widgets'] ?? [] as $widgetIndex => $widget) {
                if (is_array($widget) && ($widget['id'] ?? null) === $widgetId) { $index = $widgetIndex; break; }
            }
            if ($index === null) throw new ApiRequestException('Chart widget was not found.', 'CHART_WIDGET_NOT_FOUND', [], 404);
            $widget = $dashboard['widgets'][$index];
            if (($widget['type'] ?? null) !== 'chart' || !in_array($widget['chartType'] ?? 'bar', self::PERSISTED_CHART_TYPES, true)) {
                throw new ApiRequestException('The target widget is not a supported chart.', 'CHART_WIDGET_NOT_SUPPORTED', [], 400);
            }
            $currentType = $widget['chartType'] ?? 'bar';
            $targetType = $chartType ?? $currentType;
            if (!in_array($targetType, self::CHART_TYPES, true)) {
                throw new ApiRequestException('The requested chart type is unsupported.', 'INVALID_CHART_TYPE', [], 400);
            }
            $seriesKey = $widget['yField'] ?? null;
            if (!is_string($seriesKey) || array_diff(array_keys($seriesColors), [$seriesKey]) !== []) {
                throw new ApiRequestException('The chart series color key is invalid.', 'INVALID_CHART_COLOR_KEY', [], 400);
            }
            $options = is_array($widget['chartOptions'] ?? null) ? $widget['chartOptions'] : [];
            $defaults = is_array($widget['chartCustomizationDefaults'] ?? null)
                ? $widget['chartCustomizationDefaults']
                : null;
            if ($reset) {
                if ($defaults !== null) {
                    $widget['chartType'] = $defaults['chartType'] ?? 'bar';
                    $defaultSeries = is_array($defaults['seriesColors'] ?? null) ? $defaults['seriesColors'] : [];
                    $defaultCategories = is_array($defaults['categoryColors'] ?? null) ? $defaults['categoryColors'] : [];
                    if ($defaultSeries === []) unset($options['seriesColors']); else $options['seriesColors'] = $defaultSeries;
                    if ($defaultCategories === []) unset($options['categoryColors']); else $options['categoryColors'] = $defaultCategories;
                    foreach (['orientation', 'numberFormat', 'valueFormat'] as $formatProperty) {
                        if (isset($defaults[$formatProperty]) && is_string($defaults[$formatProperty])) $options[$formatProperty] = $defaults[$formatProperty];
                        else unset($options[$formatProperty]);
                    }
                    unset($widget['chartCustomizationDefaults']);
                }
            } else {
                if ($defaults === null) {
                    $authoredDefaults = [
                        'chartType' => $currentType,
                        'seriesColors' => is_array($options['seriesColors'] ?? null) ? $options['seriesColors'] : [],
                        'categoryColors' => is_array($options['categoryColors'] ?? null) ? $options['categoryColors'] : [],
                    ];
                    foreach (['orientation', 'numberFormat', 'valueFormat'] as $presentationProperty) {
                        if (is_string($options[$presentationProperty] ?? null)) $authoredDefaults[$presentationProperty] = $options[$presentationProperty];
                    }
                    $widget['chartCustomizationDefaults'] = $authoredDefaults;
                }
                $widget['chartType'] = $targetType;
                if ($seriesColors === []) unset($options['seriesColors']); else $options['seriesColors'] = $seriesColors;
                if ($categoryColors === []) unset($options['categoryColors']); else $options['categoryColors'] = $categoryColors;
                if ($numberFormat === null) unset($options['numberFormat']); else $options['numberFormat'] = $numberFormat;
                if ($valueFormat === null) unset($options['valueFormat']); else $options['valueFormat'] = $valueFormat;
                if ($targetType === 'bar') {
                    if ($orientation === null) unset($options['orientation']); else $options['orientation'] = $orientation;
                } else unset($options['orientation']);
            }
            if ($options === []) unset($widget['chartOptions']); else $widget['chartOptions'] = $options;
            $dashboard['widgets'][$index] = $widget;
            JsonFileStore::save($path, $dashboard);
            $record = $this->chartRecord($dashboardId, $widget, $this->revision($path));
            if ($record === null) throw new RuntimeException('Saved chart configuration could not be read.');
            return $record;
        } finally {
            @flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function dashboard(string $dashboardId): array
    {
        foreach (glob($this->directory . DIRECTORY_SEPARATOR . '*.json') ?: [] as $path) {
            try { $value = JsonFileStore::load($path); } catch (Throwable) { continue; }
            if (($value['id'] ?? null) === $dashboardId) return [$path, $value];
        }
        throw new ApiRequestException('Dashboard configuration was not found.', 'DASHBOARD_CONFIGURATION_NOT_FOUND', [], 404);
    }

    private function revision(string $path): string
    {
        $revision = @hash_file('sha256', $path);
        if (!is_string($revision)) throw new ApiRequestException('Dashboard configuration could not be read.', 'CHART_CONFIGURATION_UNAVAILABLE', [], 503);
        return $revision;
    }

    private function chartRecord(string $dashboardId, mixed $widget, string $revision): ?array
    {
        if (!is_array($widget) || ($widget['type'] ?? null) !== 'chart' || !is_string($widget['id'] ?? null)) return null;
        $options = is_array($widget['chartOptions'] ?? null) ? $widget['chartOptions'] : [];
        $defaults = is_array($widget['chartCustomizationDefaults'] ?? null) ? $widget['chartCustomizationDefaults'] : null;
        return [
            'dashboardId' => $dashboardId,
            'widgetId' => $widget['id'],
            'chartType' => $widget['chartType'] ?? 'bar',
            'authoredChartType' => $defaults['chartType'] ?? ($widget['chartType'] ?? 'bar'),
            'revision' => $revision,
            'seriesColors' => is_array($options['seriesColors'] ?? null) ? $options['seriesColors'] : [],
            'categoryColors' => is_array($options['categoryColors'] ?? null) ? $options['categoryColors'] : [],
            'numberFormat' => is_string($options['numberFormat'] ?? null) ? $options['numberFormat'] : null,
            'valueFormat' => is_string($options['valueFormat'] ?? null) ? $options['valueFormat'] : null,
            'orientation' => is_string($options['orientation'] ?? null) ? $options['orientation'] : null,
            'authoredSeriesColors' => is_array($defaults['seriesColors'] ?? null) ? $defaults['seriesColors'] : (is_array($options['seriesColors'] ?? null) ? $options['seriesColors'] : []),
            'authoredCategoryColors' => is_array($defaults['categoryColors'] ?? null) ? $defaults['categoryColors'] : (is_array($options['categoryColors'] ?? null) ? $options['categoryColors'] : []),
            'authoredNumberFormat' => is_string($defaults['numberFormat'] ?? null) ? $defaults['numberFormat'] : (is_string($options['numberFormat'] ?? null) ? $options['numberFormat'] : null),
            'authoredValueFormat' => is_string($defaults['valueFormat'] ?? null) ? $defaults['valueFormat'] : (is_string($options['valueFormat'] ?? null) ? $options['valueFormat'] : null),
            'authoredOrientation' => is_string($defaults['orientation'] ?? null) ? $defaults['orientation'] : (is_string($options['orientation'] ?? null) ? $options['orientation'] : null),
            'customized' => $defaults !== null,
        ];
    }
}
