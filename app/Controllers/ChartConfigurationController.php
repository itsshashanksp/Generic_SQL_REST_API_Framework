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
        $this->success([$this->service->update($request['dashboardId'], $request['widgetId'], $request['colors'], $request['expectedRevision'])], 'Chart colors saved.');
    }
}
