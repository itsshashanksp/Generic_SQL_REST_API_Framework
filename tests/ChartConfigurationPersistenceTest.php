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
        ['id' => 'table', 'type' => 'table', 'title' => 'Rows'],
    ],
], JSON_PRETTY_PRINT));

try {
    $validator = new ChartConfigurationRequestValidator();
    $service = new ChartConfigurationService($directory);
    $initial = $service->colors('sales-dashboard');
    chartConfigurationAssert(count($initial) === 2, 'Only chart widgets were listed.');
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
    chartConfigurationAssert($stored['widgets'][1]['id'] === 'status-pie' && $stored['widgets'][2]['id'] === 'table', 'Unrelated widgets were overwritten.');
    $reloaded = (new ChartConfigurationService($directory))->colors('sales-dashboard');
    chartConfigurationAssert($reloaded[0]['seriesColors'] === ['Sales' => '#ABCDEF'], 'Saved colors did not survive service reload.');

    $pieRevision = $saved['revision'];
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
