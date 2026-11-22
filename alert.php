<?php
declare(strict_types=1);

require_once __DIR__ . '/src/helpers.php';
WebRequestSecurity::protectPost(false);

$db = new Database();
$db->initialize();
$repo = new PublicationRepository($db);
$alerts = new AlertService($db, $repo);
$topics = $repo->topics();
$journals = $repo->journals();
$assetVersion = '20261101-network-ux-v287';

$token = request_value('token', '');
$message = null;
$error = null;

try {
    if (request_value('action') === 'confirm') {
        $subscription = $alerts->confirm((string)request_value('confirm', ''));
        if ($subscription) {
            $token = (string)$subscription['token'];
            $message = 'Email address confirmed. This alert is now active and future matching digests can be sent.';
        } else {
            $error = 'Confirmation link is invalid or has already been used.';
            $subscription = null;
        }
    } elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && request_value('action') === 'update') {
        $subscription = $alerts->updatePreferences(
            (string)$token,
            (string)request_value('frequency', 'daily'),
            request_array('substances'),
            request_value('keywords'),
            request_value('author'),
            AlertFilters::request('journal'),
            AlertFilters::request('topic'),
            request_value('cited_doi'),
            request_array('event_types')
        );
        $message = 'Alert preferences updated.';
    } elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && request_value('action') === 'pause') {
        if ($alerts->pause((string)$token)) {
            $message = 'Alert delivery paused. You can resume it here at any time.';
        } else {
            $error = 'Alert subscription not found.';
        }
        $subscription = $alerts->findByToken((string)$token);
    } elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && request_value('action') === 'resume') {
        $subscription = $alerts->resume((string)$token);
        if ($subscription) {
            $message = 'Alert delivery resumed.';
        } else {
            $error = 'This alert must be confirmed before delivery can resume.';
            $subscription = $alerts->findByToken((string)$token);
        }
    } elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && request_value('action') === 'resend_confirmation') {
        WebRequestSecurity::rateLimit([['alert-resend', (string)$token, 3, 3600]]);
        $subscription = $alerts->findByToken((string)$token);
        if ($subscription && empty($subscription['confirmed_at']) && $alerts->sendConfirmation($subscription)) {
            $message = 'Confirmation email sent again.';
            $subscription = $alerts->findByToken((string)$token);
        } elseif ($subscription && !empty($subscription['confirmed_at'])) {
            $message = 'This alert is already confirmed.';
        } else {
            $error = 'Could not send a confirmation email for this alert.';
        }
    } elseif (in_array(request_value('action'), ['unsubscribe', 'unenrol'], true)) {
        if ($alerts->unsubscribe((string)$token)) {
            $message = 'You are unsubscribed from this alert. No further digests will be generated unless you resume it.';
        } else {
            $error = 'Alert subscription not found.';
        }
        $subscription = $alerts->findByToken((string)$token);
    } elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && request_value('action') === 'delete') {
        if ($alerts->deleteSubscription((string)$token)) {
            $message = 'Alert deleted. The subscription and its delivery records were removed.';
            $subscription = null;
            $token = '';
        } else {
            $error = 'Alert subscription not found.';
            $subscription = null;
        }
    } else {
        $subscription = $alerts->findByToken((string)$token);
    }
} catch (Throwable $e) {
    if (!$e instanceof InvalidArgumentException) OperationalLogger::exception('web.request_failed', $e);
    $error = $e instanceof InvalidArgumentException ? $e->getMessage() : 'The request could not be completed. Please try again later.';
    $subscription = $alerts->findByToken((string)$token);
}

$substances = array_filter(explode(',', (string)($subscription['substances'] ?? 'psilocybin,psilocin')));
$isConfirmed = $subscription && !empty($subscription['confirmed_at']);
$isActive = $subscription && (int)($subscription['active'] ?? 0) === 1 && $isConfirmed;
$statusLabel = $subscription ? ($isActive ? 'Active' : ($isConfirmed ? 'Paused' : 'Needs confirmation')) : 'Unavailable';
$deliveryHistory = [];
if ($subscription) {
    $historyQuery = $db->pdo()->prepare('SELECT d.generated_at, d.frequency, p.id, p.title FROM alert_deliveries d JOIN publications p ON p.id = d.publication_id WHERE d.subscription_id = :id ORDER BY d.generated_at DESC, d.id DESC');
    $historyQuery->execute(['id' => (int)$subscription['id']]);
    $deliveryHistory = $historyQuery->fetchAll();
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="theme-color" content="#123c31">
  <meta name="color-scheme" content="light">
  <title>Manage Publication Alert | Psilocybin Research</title>
  <link rel="icon" href="assets/logo.png?v=20260906-loader-bar-v140">
  <link rel="stylesheet" href="<?= h(FrontendAssets::url('assets/styles.min.css')) ?>">
  <script>
    document.documentElement.classList.add('js');
    try {
      const compactNavigation = window.matchMedia('(max-width: 1180px)').matches;
      const navigationPreferenceKey = compactNavigation ? 'publicationTrackerNavSidebarCollapsedMobile' : 'publicationTrackerNavSidebarCollapsedDesktop';
      const navigationPreference = window.localStorage.getItem(navigationPreferenceKey);
      if ((navigationPreference === null && compactNavigation) || navigationPreference === '1') {
        document.documentElement.classList.add('nav-sidebar-collapsed');
      }
    } catch (error) {}
  </script>
</head>
<body class="alert-manage-page">
<div class="alert-vanta-bg" id="alert-vanta-bg" aria-hidden="true"></div>
<?= detail_page_header('alert') ?>

<main class="alert-manage-shell">
  <section class="panel alert-manage-card">
    <h1>Manage publication alert</h1>
    <?php if ($message): ?><div class="notice success"><?= h($message) ?></div><?php endif; ?>
    <?php if ($error): ?><div class="notice error"><?= h($error) ?></div><?php endif; ?>

    <?php if (!$subscription): ?>
      <div class="state">
        <strong>Alert link not found.</strong>
        <p>This manage link is invalid or no longer available. Create a new alert from the research database if needed.</p>
      </div>
    <?php else: ?>
      <div class="alert-manage-status">
        <div>
          <span>Alert UUID</span>
          <strong><?= h((string)($subscription['public_uuid'] ?? 'Unavailable')) ?></strong>
        </div>
        <div>
          <span>Status</span>
          <strong><?= h($statusLabel) ?></strong>
        </div>
        <div>
          <span>Recipient</span>
          <strong><?= h($subscription['email']) ?></strong>
        </div>
        <div>
          <span>Current filters</span>
          <strong><?= h($alerts->preferenceSummary($subscription)) ?></strong>
        </div>
      </div>

      <details class="push-device-settings"><summary>Publication delivery history</summary><div class="accordion-body">
        <?php if (!$deliveryHistory): ?><p>No publication records yet.</p><?php else: ?><ul>
        <?php foreach ($deliveryHistory as $entry): ?><li><a href="publication.php?id=<?= (int)$entry['id'] ?>"><?= h((string)$entry['title']) ?></a> · <?= h((string)$entry['generated_at']) ?> UTC</li><?php endforeach; ?>
        </ul><?php endif; ?><p class="field-note">Recorded publication entries; inbox delivery is not confirmed.</p></div></details>
      <p class="alert-manage-intro">Use this secure manage link from your email to adjust delivery frequency, research filters, pause delivery, unsubscribe, or delete the alert data.</p>

      <?php if (!$isConfirmed): ?>
        <div class="notice warning">This alert is waiting for email confirmation. You can adjust filters now, but no publication digests are sent until the confirmation link is opened.</div>
      <?php elseif (!$isActive): ?>
        <div class="notice warning">Delivery is paused. Your preferences are saved, but no digests are generated until you resume the alert.</div>
      <?php endif; ?>

      <form method="post" class="alert-form alert-manage-form">
        <input type="hidden" name="action" value="update">
        <input type="hidden" name="token" value="<?= h((string)$subscription['token']) ?>">
        <fieldset><legend>Frequency</legend>
          <label><input type="radio" name="frequency" value="daily" <?= $subscription['frequency'] === 'daily' ? 'checked' : '' ?>> Daily</label>
          <label><input type="radio" name="frequency" value="weekly" <?= $subscription['frequency'] === 'weekly' ? 'checked' : '' ?>> Weekly</label>
          <label><input type="radio" name="frequency" value="monthly" <?= $subscription['frequency'] === 'monthly' ? 'checked' : '' ?>> Monthly</label>
        </fieldset>
        <fieldset><legend>Substances</legend>
          <label><input type="checkbox" name="substances[]" value="psilocybin" <?= in_array('psilocybin', $substances, true) ? 'checked' : '' ?>> Psilocybin</label>
          <label><input type="checkbox" name="substances[]" value="psilocin" <?= in_array('psilocin', $substances, true) ? 'checked' : '' ?>> Psilocin</label>
        </fieldset>
        <label class="field"><span>Keywords</span><input type="text" name="keywords" value="<?= h((string)($subscription['keywords'] ?? '')) ?>"></label>
        <label class="field"><span>Author</span><input type="text" name="author" value="<?= h((string)($subscription['author'] ?? '')) ?>"></label>
        <?= AlertFilters::picker('journal', 'Journals', array_column($journals, 'journal'), $subscription['journal'] ?? null) ?>
        <?= AlertFilters::picker('topic', 'Topics', array_column($topics, 'name'), $subscription['topic'] ?? null) ?>
        <label class="field"><span>Cites DOI</span><input type="text" name="cited_doi" value="<?= h((string)($subscription['cited_doi'] ?? '')) ?>" placeholder="10.1038/..."></label>
        <p class="field-note">Citation alerts match publications whose available reference metadata contains this DOI.</p>
        <details class="alert-research-changes">
          <summary>Optional research-change notifications</summary>
          <fieldset><legend class="sr-only">Research-change notification types</legend><?php foreach(ResearchChanges::TYPES as $value=>$label): ?><label><input type="checkbox" name="event_types[]" value="<?= h($value) ?>" <?= isset($subscription)&&in_array($value,explode(',',(string)($subscription['event_types']??'')),true)?'checked':'' ?>> <?= h($label) ?></label><?php endforeach; ?><p class="field-note">Observed after signup: registry changes and newly linked result references. A registry reference may point to an older paper. Journal and citation filters apply to publications; changes use keywords, author/sponsor and topic filters.</p></fieldset>
        </details><button class="primary iconed" type="submit"><span>Update preferences</span></button>
      </form>

      <div class="alert-management-actions">
        <?php if (!$isConfirmed): ?>
          <form method="post">
            <input type="hidden" name="action" value="resend_confirmation">
            <input type="hidden" name="token" value="<?= h((string)$subscription['token']) ?>">
            <button class="secondary" type="submit">Resend confirmation email</button>
          </form>
        <?php elseif ($isActive): ?>
          <form method="post">
            <input type="hidden" name="action" value="pause">
            <input type="hidden" name="token" value="<?= h((string)$subscription['token']) ?>">
            <button class="secondary" type="submit">Pause delivery</button>
          </form>
        <?php else: ?>
          <form method="post">
            <input type="hidden" name="action" value="resume">
            <input type="hidden" name="token" value="<?= h((string)$subscription['token']) ?>">
            <button class="primary" type="submit">Resume delivery</button>
          </form>
        <?php endif; ?>
        <form method="post">
          <input type="hidden" name="action" value="unsubscribe">
          <input type="hidden" name="token" value="<?= h((string)$subscription['token']) ?>">
          <button class="secondary" type="submit">Unsubscribe</button>
        </form>
        <form method="post" onsubmit="return confirm('Delete this alert and delivery records? This cannot be undone.');">
          <input type="hidden" name="action" value="delete">
          <input type="hidden" name="token" value="<?= h((string)$subscription['token']) ?>">
          <button class="danger" type="submit">Delete alert data</button>
        </form>
      </div>

      <div class="privacy-note">
        <strong>Data protection notice.</strong>
        We store the email address and filter choices needed to generate this alert. Email addresses and alert access tokens are encrypted at rest. Digests are generated only after email confirmation. The data is used only for publication digests and duplicate-delivery prevention. The email template contains no tracking pixel. Pausing or unsubscribing disables delivery; deleting removes the alert and delivery records. See the <a href="data-protection.php">data protection notice</a>.
      </div>
    <?php endif; ?>
  </section>
</main>
<?= detail_scroll_top() ?>
<script src="assets/vendor/three.r134.min.js?v=<?= h($assetVersion) ?>"></script>
<script src="assets/vendor/vanta.net.min.js?v=<?= h($assetVersion) ?>"></script>
<script src="<?= h(FrontendAssets::url('assets/app.min.js')) ?>" defer></script>
</body>
</html>
