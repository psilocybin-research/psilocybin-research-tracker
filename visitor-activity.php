<?php
declare(strict_types=1);
require_once __DIR__ . '/src/helpers.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, max-age=0');

try {
    $activity = (new VisitorStatisticsService())->currentActivity();
    if (empty($activity['available'])) throw new RuntimeException('Live access logs are unavailable.');
    echo json_encode(['online' => (int)$activity['online'], 'active_sessions' => (int)$activity['active_sessions'], 'page_views' => (int)$activity['page_views'], 'countries' => array_values($activity['countries'] ?? []), 'window_seconds' => 300, 'updated_at' => gmdate('c')]);
} catch (Throwable $e) {
    http_response_code(503);
    OperationalLogger::exception('visitor_activity.endpoint_failed', $e);
    echo json_encode(['online' => null]);
}
