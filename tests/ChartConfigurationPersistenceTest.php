<?php

require_once __DIR__ . '/../app/Requests/ChartConfigurationRequestValidator.php';
require_once __DIR__ . '/../app/Services/ChartConfigurationService.php';
require_once __DIR__ . '/../app/Middleware/ChartConfigurationAuthorizationMiddleware.php';
require_once __DIR__ . '/../app/Repositories/AuthorizationRepository.php';
require_once __DIR__ . '/../app/Services/AuthorizationService.php';
require_once __DIR__ . '/../app/Authorization/PrincipalContext.php';

function chartConfigurationAssert(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

$directory = sys_get_temp_dir() . '/generic-chart-colors-' . bin2hex(random_bytes(8));
mkdir($directory, 0700, true);
$path = $directory . '/sales.json';
file_put_contents($path, json_encode([
    'id' => 'sales-dashboard',
    'title' => 'Sales',
    'filters' => [],
    'widgets' => [
        ['id' => 'sales-series', 'type' => 'chart', 'title' => 'Sales', 'chartType' => 'bar', 'xField' => 'Month', 'yField' => 'Sales', 'chartOptions' => ['showGrid' => true]],
        ['id' => 'status-pie', 'type' => 'chart', 'title' => 'Status', 'chartType' => 'pie', 'xField' => 'Status', 'yField' => 'Count'],
        ['id' => 'legacy-funnel', 'type' => 'chart', 'title' => 'Legacy', 'chartType' => 'funnel', 'xField' => 'Stage', 'yField' => 'Count'],
        ['id' => 'table', 'type' => 'table', 'title' => 'Rows'],
    ],
], JSON_PRETTY_PRINT));

try {
    $validator = new ChartConfigurationRequestValidator();
    $service = new ChartConfigurationService($directory);
    $initial = $service->colors('sales-dashboard');
    chartConfigurationAssert(count($initial) === 3, 'Only chart widgets were listed.');
    chartConfigurationAssert($initial[2]['chartType'] === 'funnel', 'Legacy Funnel configuration was not returned safely.');
    $revision = $initial[0]['revision'];

    $request = $validator->validate([
        'action' => 'configuration.chartColors.update',
        'dashboardId' => 'sales-dashboard',
        'widgetId' => 'sales-series',
        'colors' => ['Sales' => '#abcdef'],
        'expectedRevision' => $revision,
    ]);
    $saved = $service->update($request['dashboardId'], $request['widgetId'], $request['colors'], $request['expectedRevision']);
    chartConfigurationAssert($saved['seriesColors'] === ['Sales' => '#ABCDEF'], 'Series color was not normalized and saved.');
    $stored = json_decode((string)file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    chartConfigurationAssert($stored['widgets'][0]['chartOptions']['showGrid'] === true, 'Unrelated chart options were overwritten.');
    chartConfigurationAssert($stored['widgets'][1]['id'] === 'status-pie' && $stored['widgets'][2]['id'] === 'legacy-funnel' && $stored['widgets'][3]['id'] === 'table', 'Unrelated widgets were overwritten.');
    $reloaded = (new ChartConfigurationService($directory))->colors('sales-dashboard');
    chartConfigurationAssert($reloaded[0]['seriesColors'] === ['Sales' => '#ABCDEF'], 'Saved colors did not survive service reload.');

    $customRequest = $validator->validate([
        'action' => 'configuration.chartColors.update',
        'dashboardId' => 'sales-dashboard',
        'widgetId' => 'sales-series',
        'chartType' => 'line',
        'seriesColors' => ['Sales' => '#102030'],
        'categoryColors' => [],
        'expectedRevision' => $saved['revision'],
    ]);
    $typed = $service->updateConfiguration('sales-dashboard', 'sales-series', $customRequest['chartType'], $customRequest['seriesColors'], $customRequest['categoryColors'], false, $customRequest['expectedRevision']);
    chartConfigurationAssert($typed['chartType'] === 'line', 'Chart type customization was not persisted.');
    chartConfigurationAssert($typed['authoredChartType'] === 'bar', 'Authored chart type was not preserved.');
    $typedStored = json_decode((string)file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    chartConfigurationAssert(!array_key_exists('orientation', $typedStored['widgets'][0]['chartCustomizationDefaults']), 'Absent authored orientation was serialized as null.');
    chartConfigurationAssert(!array_key_exists('numberFormat', $typedStored['widgets'][0]['chartCustomizationDefaults']), 'Absent authored number format was serialized as null.');
    $resetTyped = $service->updateConfiguration('sales-dashboard', 'sales-series', null, [], [], true, $typed['revision']);
    chartConfigurationAssert($resetTyped['chartType'] === 'bar' && $resetTyped['customized'] === false, 'Reset did not restore authored chart defaults.');

    $advancedRequest = $validator->validate([
        'action' => 'configuration.chartColors.update',
        'dashboardId' => 'sales-dashboard',
        'widgetId' => 'sales-series',
        'chartType' => 'area',
        'numberFormat' => 'full',
        'valueFormat' => 'currency',
        'seriesColors' => ['Sales' => '#224466'],
        'categoryColors' => [],
        'expectedRevision' => $resetTyped['revision'],
    ]);
    $advanced = $service->updateConfiguration('sales-dashboard', 'sales-series', $advancedRequest['chartType'], $advancedRequest['seriesColors'], $advancedRequest['categoryColors'], false, $advancedRequest['expectedRevision'], $advancedRequest['numberFormat'], $advancedRequest['valueFormat']);
    chartConfigurationAssert($advanced['chartType'] === 'area', 'Advanced chart type customization was not persisted.');
    chartConfigurationAssert($advanced['numberFormat'] === 'full' && $advanced['valueFormat'] === 'currency', 'Chart number presentation was not persisted.');
    $reloadedAdvanced = (new ChartConfigurationService($directory))->colors('sales-dashboard')[0];
    chartConfigurationAssert($reloadedAdvanced['chartType'] === 'area', 'Advanced chart type did not survive service reload.');
    chartConfigurationAssert($reloadedAdvanced['numberFormat'] === 'full' && $reloadedAdvanced['valueFormat'] === 'currency', 'Chart number presentation did not survive service reload.');
    $resetTyped = $service->updateConfiguration('sales-dashboard', 'sales-series', null, [], [], true, $advanced['revision']);
    chartConfigurationAssert($resetTyped['numberFormat'] === null && $resetTyped['valueFormat'] === null, 'Reset did not restore authored number presentation.');
    $orientationRequest = $validator->validate([
        'action' => 'configuration.chartColors.update',
        'dashboardId' => 'sales-dashboard',
        'widgetId' => 'sales-series',
        'chartType' => 'bar',
        'orientation' => 'horizontal',
        'seriesColors' => [],
        'categoryColors' => [],
        'expectedRevision' => $resetTyped['revision'],
    ]);
    $oriented = $service->updateConfiguration('sales-dashboard', 'sales-series', 'bar', [], [], false, $orientationRequest['expectedRevision'], null, null, $orientationRequest['orientation']);
    chartConfigurationAssert($oriented['orientation'] === 'horizontal', 'Bar orientation customization was not persisted.');
    chartConfigurationAssert((new ChartConfigurationService($directory))->colors('sales-dashboard')[0]['orientation'] === 'horizontal', 'Bar orientation did not survive service reload.');
    $resetTyped = $service->updateConfiguration('sales-dashboard', 'sales-series', null, [], [], true, $oriented['revision']);
    chartConfigurationAssert($resetTyped['orientation'] === null, 'Reset did not restore the authored bar orientation.');
    $radial = $service->updateConfiguration('sales-dashboard', 'sales-series', 'radial-bar', [], ['North' => '#336699'], false, $resetTyped['revision']);
    chartConfigurationAssert($radial['chartType'] === 'radial-bar' && $radial['categoryColors']['North'] === '#336699', 'Category-colored advanced chart configuration was not persisted.');
    $resetTyped = $service->updateConfiguration('sales-dashboard', 'sales-series', null, [], [], true, $radial['revision']);

    $doughnutRequest = $validator->validate([
        'action' => 'configuration.chartColors.update',
        'dashboardId' => 'sales-dashboard',
        'widgetId' => 'sales-series',
        'chartType' => 'doughnut',
        'seriesColors' => [],
        'categoryColors' => ['North' => '#445566'],
        'expectedRevision' => $resetTyped['revision'],
    ]);
    chartConfigurationAssert($doughnutRequest['chartType'] === 'doughnut', 'Doughnut was not accepted as an independent chart type.');
    try {
        $validator->validate([
            'action' => 'configuration.chartColors.update',
            'dashboardId' => 'sales-dashboard',
            'widgetId' => 'sales-series',
            'chartType' => 'bar',
            'orientation' => 'diagonal',
            'seriesColors' => [],
            'categoryColors' => [],
            'expectedRevision' => $resetTyped['revision'],
        ]);
        throw new RuntimeException('Unsupported chart orientation was accepted.');
    } catch (ApiRequestException $exception) {
        chartConfigurationAssert($exception->getErrorCode() === 'INVALID_CHART_CONFIGURATION_REQUEST', 'Invalid chart orientation returned the wrong error.');
    }
    try {
        $validator->validate([
            'action' => 'configuration.chartColors.update',
            'dashboardId' => 'sales-dashboard',
            'widgetId' => 'sales-series',
            'chartType' => 'bar',
            'numberFormat' => 'abbreviated',
            'valueFormat' => 'money',
            'seriesColors' => [],
            'categoryColors' => [],
            'expectedRevision' => $resetTyped['revision'],
        ]);
        throw new RuntimeException('Unsupported chart number presentation was accepted.');
    } catch (ApiRequestException $exception) {
        chartConfigurationAssert($exception->getErrorCode() === 'INVALID_CHART_CONFIGURATION_REQUEST', 'Invalid chart number presentation returned the wrong error.');
    }
    try {
        $validator->validate([
            'action' => 'configuration.chartColors.update',
            'dashboardId' => 'sales-dashboard',
            'widgetId' => 'legacy-funnel',
            'chartType' => 'funnel',
            'seriesColors' => [],
            'categoryColors' => [],
            'expectedRevision' => $resetTyped['revision'],
        ]);
        throw new RuntimeException('Funnel was accepted for a new customization save.');
    } catch (ApiRequestException $exception) {
        chartConfigurationAssert($exception->getErrorCode() === 'INVALID_CHART_CONFIGURATION_REQUEST', 'Legacy Funnel rejection returned the wrong error.');
    }

    $emptyColors = $validator->validate([
        'action' => 'configuration.chartColors.update',
        'dashboardId' => 'sales-dashboard',
        'widgetId' => 'sales-series',
        'colors' => [],
        'expectedRevision' => $resetTyped['revision'],
    ]);
    chartConfigurationAssert($emptyColors['colors'] === [], 'An empty JSON color object was rejected.');
    try {
        $validator->validate([
            'action' => 'configuration.chartColors.update',
            'dashboardId' => 'sales-dashboard',
            'widgetId' => 'sales-series',
            'chartType' => 'unsupported-chart',
            'seriesColors' => [],
            'categoryColors' => [],
            'expectedRevision' => $resetTyped['revision'],
        ]);
        throw new RuntimeException('An unsupported chart type was accepted.');
    } catch (ApiRequestException $exception) {
        chartConfigurationAssert($exception->getErrorCode() === 'INVALID_CHART_CONFIGURATION_REQUEST', 'Invalid chart type returned the wrong error.');
    }
    try {
        $tooMany = [];
        for ($index = 0; $index < 101; $index++) $tooMany['Category ' . $index] = '#123456';
        $validator->validate([
            'action' => 'configuration.chartColors.update',
            'dashboardId' => 'sales-dashboard',
            'widgetId' => 'sales-series',
            'chartType' => 'pie',
            'seriesColors' => [],
            'categoryColors' => $tooMany,
            'expectedRevision' => $resetTyped['revision'],
        ]);
        throw new RuntimeException('Excessive color overrides were accepted.');
    } catch (ApiRequestException $exception) {
        chartConfigurationAssert($exception->getErrorCode() === 'INVALID_CHART_CONFIGURATION_REQUEST', 'Excessive colors returned the wrong error.');
    }

    $pieRevision = $resetTyped['revision'];
    $pie = $service->update('sales-dashboard', 'status-pie', ['Open' => '#123456', 'Closed' => '#654321'], $pieRevision);
    chartConfigurationAssert($pie['categoryColors']['Open'] === '#123456', 'Pie category colors were not saved by stable value.');
    $reset = $service->update('sales-dashboard', 'status-pie', [], $pie['revision']);
    chartConfigurationAssert($reset['categoryColors'] === [], 'Reset did not remove the category override.');

    try {
        $service->update('sales-dashboard', 'sales-series', ['Other' => '#123456'], $reset['revision']);
        throw new RuntimeException('Invalid series key was accepted.');
    } catch (ApiRequestException $exception) {
        chartConfigurationAssert($exception->getErrorCode() === 'INVALID_CHART_COLOR_KEY', 'Invalid series key returned the wrong error.');
    }
    try {
        $service->update('sales-dashboard', 'unknown-chart', [], $reset['revision']);
        throw new RuntimeException('An unknown chart widget was accepted.');
    } catch (ApiRequestException $exception) {
        chartConfigurationAssert($exception->getErrorCode() === 'CHART_WIDGET_NOT_FOUND', 'Unknown chart returned the wrong error.');
    }
    try {
        $service->update('sales-dashboard', 'sales-series', ['Sales' => '#123456'], str_repeat('0', 64));
        throw new RuntimeException('A stale revision was accepted.');
    } catch (ApiRequestException $exception) {
        chartConfigurationAssert($exception->getErrorCode() === 'CHART_CONFIGURATION_CONFLICT', 'Stale update returned the wrong error.');
    }
    try {
        $validator->validate(['action' => 'configuration.chartColors.update', 'dashboardId' => '../sales', 'widgetId' => 'x', 'colors' => ['x' => 'red'], 'expectedRevision' => str_repeat('a', 64)]);
        throw new RuntimeException('Unsafe input was accepted.');
    } catch (ApiRequestException $exception) {
        chartConfigurationAssert($exception->getErrorCode() === 'INVALID_CHART_CONFIGURATION_REQUEST', 'Unsafe input returned the wrong error.');
    }

    $authorizationPath = $directory . '/authorization.json';
    JsonFileStore::save($authorizationPath, RuntimeConfiguration::authorizationDefaults());
    $middleware = new ChartConfigurationAuthorizationMiddleware(new AuthorizationService(new AuthorizationRepository($authorizationPath)));
    PrincipalContext::set(new Principal('viewer', 'Viewer', 'session', 'read-only', true, null, true, ['frontend.read']));
    $middleware->handle(['action' => 'configuration.chartColors.get']);
    try {
        $middleware->handle(['action' => 'configuration.chartColors.update']);
        throw new RuntimeException('A non-administrator was allowed to update chart colors.');
    } catch (ApiRequestException $exception) {
        chartConfigurationAssert($exception->getErrorCode() === 'AUTHORIZATION_DENIED', 'Unauthorized update returned the wrong error.');
    }
    PrincipalContext::set(new Principal('admin', 'Admin', 'session', null, true, 'application-administrator', true, ['frontend.read', 'frontend.users.manage']));
    $middleware->handle(['action' => 'configuration.chartColors.update']);
    PrincipalContext::clear();
} finally {
    foreach (glob($directory . '/*') ?: [] as $file) @unlink($file);
    @unlink($directory . '/.chart-configuration.lock');
    @rmdir($directory);
}

echo "Chart configuration persistence tests passed.\n";
