<?php

require_once __DIR__ . '/ApiRequestException.php';

final class ChartConfigurationRequestValidator
{
    public const ACTIONS = ['configuration.chartColors.get', 'configuration.chartColors.update'];
    public const CHART_TYPES = ['bar', 'line', 'area', 'pie', 'doughnut', 'treemap', 'radial-bar'];

    public function validate(array $request): array
    {
        $action = $request['action'] ?? null;
        if (!is_string($action) || !in_array($action, self::ACTIONS, true)) $this->invalid();
        $allowed = $action === 'configuration.chartColors.get'
            ? ['action', 'dashboardId']
            : ['action', 'dashboardId', 'widgetId', 'colors', 'chartType', 'orientation', 'numberFormat', 'valueFormat', 'seriesColors', 'categoryColors', 'reset', 'expectedRevision'];
        $details = [];
        foreach (array_keys($request) as $key) {
            if (!in_array($key, $allowed, true)) $details[] = ['path' => $key, 'message' => 'Unknown property.'];
        }
        $dashboardId = $this->identifier($request['dashboardId'] ?? null, 'dashboardId', $details);
        $validated = ['action' => $action, 'dashboardId' => $dashboardId];
        if ($action === 'configuration.chartColors.update') {
            $validated['widgetId'] = $this->identifier($request['widgetId'] ?? null, 'widgetId', $details);
            $revision = $request['expectedRevision'] ?? null;
            if (!is_string($revision) || preg_match('/^[a-f0-9]{64}$/', $revision) !== 1) {
                $details[] = ['path' => 'expectedRevision', 'message' => 'A valid configuration revision is required.'];
            } else $validated['expectedRevision'] = $revision;
            $reset = $request['reset'] ?? false;
            if (!is_bool($reset)) $details[] = ['path' => 'reset', 'message' => 'Reset must be a boolean.'];
            $validated['reset'] = $reset === true;
            if (isset($request['chartType'])) {
                if (!is_string($request['chartType']) || !in_array($request['chartType'], self::CHART_TYPES, true)) {
                    $details[] = ['path' => 'chartType', 'message' => 'Chart type is not supported.'];
                } else $validated['chartType'] = $request['chartType'];
            }
            if (isset($request['orientation'])) {
                if (!is_string($request['orientation']) || !in_array($request['orientation'], ['vertical', 'horizontal'], true)) {
                    $details[] = ['path' => 'orientation', 'message' => 'Chart orientation is not supported.'];
                } else $validated['orientation'] = $request['orientation'];
            }
            if (isset($request['numberFormat'])) {
                if (!is_string($request['numberFormat']) || !in_array($request['numberFormat'], ['full', 'compact', 'compact-indian'], true)) {
                    $details[] = ['path' => 'numberFormat', 'message' => 'Number format is not supported.'];
                } else $validated['numberFormat'] = $request['numberFormat'];
            }
            if (isset($request['valueFormat'])) {
                if (!is_string($request['valueFormat']) || !in_array($request['valueFormat'], ['number', 'decimal', 'currency'], true)) {
                    $details[] = ['path' => 'valueFormat', 'message' => 'Value format is not supported.'];
                } else $validated['valueFormat'] = $request['valueFormat'];
            }
            foreach (['colors', 'seriesColors', 'categoryColors'] as $property) {
                if (!array_key_exists($property, $request)) continue;
                $validated[$property] = $this->colors($request[$property], $property, $details);
            }
            if (!$validated['reset'] && !isset($validated['colors'])
                && (!isset($validated['chartType']) || !isset($validated['seriesColors']) || !isset($validated['categoryColors']))) {
                $details[] = ['path' => 'chartType', 'message' => 'Chart type and both color maps are required unless reset is true.'];
            }
            $combined = count($validated['seriesColors'] ?? []) + count($validated['categoryColors'] ?? []);
            if ($combined > 100) $details[] = ['path' => 'seriesColors', 'message' => 'Series and category colors may contain at most 100 entries combined.'];
        }
        if ($details !== []) $this->invalid($details);
        return $validated;
    }

    private function colors(mixed $colors, string $path, array &$details): array
    {
        // json_decode(..., true) represents an empty JSON object as []; it is
        // the canonical reset/empty-map value and must not be rejected as a list.
        if (!is_array($colors) || ($colors !== [] && array_is_list($colors)) || count($colors) > 100) {
            $details[] = ['path' => $path, 'message' => 'Colors must be an object with no more than 100 entries.'];
            return [];
        }
        $validated = [];
        foreach ($colors as $key => $color) {
            if (!is_string($key) || trim($key) === '' || strlen($key) > 200) {
                $details[] = ['path' => $path, 'message' => 'Color keys must be non-empty strings of at most 200 characters.'];
            } elseif (!is_string($color) || preg_match('/^#[0-9a-fA-F]{6}$/', $color) !== 1) {
                $details[] = ['path' => $path . '.' . $key, 'message' => 'Colors must use six-digit hexadecimal notation.'];
            } else $validated[$key] = strtoupper($color);
        }
        return $validated;
    }

    private function identifier(mixed $value, string $path, array &$details): string
    {
        if (!is_string($value) || preg_match('/^[a-zA-Z0-9][a-zA-Z0-9._-]{0,127}$/', $value) !== 1) {
            $details[] = ['path' => $path, 'message' => 'A valid configuration identifier is required.'];
            return '';
        }
        return $value;
    }

    private function invalid(array $details = []): never
    {
        throw new ApiRequestException('Invalid chart configuration request.', 'INVALID_CHART_CONFIGURATION_REQUEST', $details);
    }
}
