<?php

require_once __DIR__ . '/BaseController.php';
require_once __DIR__ . '/../Services/ChartConfigurationService.php';

final class ChartConfigurationController extends BaseController
{
    public function __construct(private ?ChartConfigurationService $service = null) { $this->service ??= new ChartConfigurationService(); }
    public function dispatch(array $request): void
    {
        if ($request['action'] === 'configuration.chartColors.get') {
            $this->success($this->service->colors($request['dashboardId']), 'Chart colors loaded.');
        }
        if (isset($request['colors'])) {
            $record = $this->service->update($request['dashboardId'], $request['widgetId'], $request['colors'], $request['expectedRevision']);
        } else {
            $record = $this->service->updateConfiguration(
                $request['dashboardId'],
                $request['widgetId'],
                $request['chartType'] ?? null,
                $request['seriesColors'] ?? [],
                $request['categoryColors'] ?? [],
                $request['reset'],
                $request['expectedRevision'],
                $request['numberFormat'] ?? null,
                $request['valueFormat'] ?? null,
                $request['orientation'] ?? null
            );
        }
        $this->success([$record], $request['reset'] ? 'Chart customization reset.' : 'Chart customization saved.');
    }
}
