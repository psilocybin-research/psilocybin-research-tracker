<?php
declare(strict_types=1);

final class PwaMetrics
{
    public const METRICS = [
        'pwa_install', 'standalone_launch', 'standalone_first_launch',
        'install_prompt_available', 'install_prompt_requested', 'install_prompt_shown',
        'install_prompt_accepted', 'install_prompt_dismissed', 'install_prompt_error',
        'install_invitation_shown', 'install_invitation_browser_day', 'install_invitation_first_seen',
        'install_invitation_install', 'install_invitation_later', 'install_invitation_never',
        'install_control_visible', 'install_control_clicked', 'install_help_shown', 'install_followup_launch', 'offline_setup_shown',
        'offline_catalogue_ready', 'offline_r_ready',
        'install_prompt_accepted_first', 'pwa_install_first',
    ];
    public const STRATEGIES = ['baseline-v1', 'contextual-v2'];
    public const PLATFORMS = ['android', 'ios', 'desktop', 'other', 'unknown'];
    public const SURFACES = ['automatic', 'manual', 'unknown'];

    public static function record(PDO $pdo, array $input): void
    {
        $metric = $input['metric'] ?? null;
        $date = $input['date'] ?? gmdate('Y-m-d');
        if (array_diff(array_keys($input), ['metric', 'date', 'event_id', 'strategy', 'platform', 'surface'])
            || !is_string($metric) || !in_array($metric, self::METRICS, true)
            || !is_string($date) || !preg_match('/^\d{4}-\d{2}-\d{2}$/D', $date)
            || $date < gmdate('Y-m-d', time() - 7 * 86400) || $date > gmdate('Y-m-d')
            || gmdate('Y-m-d', strtotime($date . ' UTC')) !== $date) {
            throw new InvalidArgumentException('Invalid aggregate metric.');
        }
        $eventId = $input['event_id'] ?? null;
        $strategy = $input['strategy'] ?? 'legacy';
        $platform = $input['platform'] ?? 'unknown';
        $surface = $input['surface'] ?? 'unknown';
        $versioned = $eventId !== null;
        if (($versioned && (!is_string($eventId) || !preg_match('/^[a-f0-9]{32}$/D', $eventId)
                || !in_array($strategy, self::STRATEGIES, true)))
            || (!$versioned && (isset($input['strategy']) || isset($input['platform']) || isset($input['surface'])))
            || !in_array($platform, self::PLATFORMS, true) || !in_array($surface, self::SURFACES, true)) {
            throw new InvalidArgumentException('Invalid metric dimensions.');
        }
        // One transaction acknowledges a retry without incrementing either counter twice.
        $pdo->beginTransaction();
        try {
            if ($versioned) {
                $pdo->exec("DELETE FROM pwa_metric_receipts WHERE received_at < datetime('now', '-8 days')");
                $receipt = $pdo->prepare("INSERT INTO pwa_metric_receipts(event_id, received_at) VALUES(?, datetime('now')) ON CONFLICT(event_id) DO NOTHING");
                $receipt->execute([$eventId]);
                if ($receipt->rowCount() === 0) {
                    $pdo->commit();
                    return;
                }
            }
            $stmt = $pdo->prepare('INSERT INTO engagement_daily_metrics(metric_date, metric, count) VALUES(?, ?, 1) ON CONFLICT(metric_date, metric) DO UPDATE SET count=count+1');
            $stmt->execute([$date, $metric]);
            $stmt = $pdo->prepare('INSERT INTO pwa_funnel_daily(metric_date, strategy, platform, surface, metric, count) VALUES(?, ?, ?, ?, ?, 1) ON CONFLICT(metric_date, strategy, platform, surface, metric) DO UPDATE SET count=count+1');
            $stmt->execute([$date, $strategy, $platform, $surface, $metric]);
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
    }
}
