<?php

require_once __DIR__ . '/ApiRequestException.php';

final class ChartConfigurationRequestValidator
{
    public const ACTIONS = ['configuration.chartColors.get', 'configuration.chartColors.update'];

    public function validate(array $request): array
    {
        $action = $request['action'] ?? null;
        if (!is_string($action) || !in_array($action, self::ACTIONS, true)) $this->invalid();
        $allowed = $action === 'configuration.chartColors.get'
            ? ['action', 'dashboardId']
            : ['action', 'dashboardId', 'widgetId', 'colors', 'expectedRevision'];
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
            $colors = $request['colors'] ?? null;
            if (!is_array($colors) || array_is_list($colors) || count($colors) > 100) {
                $details[] = ['path' => 'colors', 'message' => 'Colors must be an object with no more than 100 entries.'];
                $colors = [];
            }
            $validatedColors = [];
            foreach ($colors as $key => $color) {
                if (!is_string($key) || trim($key) === '' || strlen($key) > 200) {
                    $details[] = ['path' => 'colors', 'message' => 'Color keys must be non-empty strings of at most 200 characters.'];
                } elseif (!is_string($color) || preg_match('/^#[0-9a-fA-F]{6}$/', $color) !== 1) {
                    $details[] = ['path' => 'colors.' . $key, 'message' => 'Colors must use six-digit hexadecimal notation.'];
                } else $validatedColors[$key] = strtoupper($color);
            }
            $validated['colors'] = $validatedColors;
        }
        if ($details !== []) $this->invalid($details);
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
