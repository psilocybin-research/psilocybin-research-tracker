<?php
declare(strict_types=1);

require_once __DIR__ . '/src/helpers.php';

$db = new Database();
$db->initialize();
$repo = new PublicationRepository($db);
$papers = $repo->latest(5);

ob_start();
?>
<section class="publication-widget publication-widget-embed">
  <h2>Latest Psilocybin Research</h2>
  <ul>
    <?php foreach ($papers as $paper): ?>
      <li>
        <a href="<?= h($paper['source_url'] ?: '/') ?>"><?= h($paper['title']) ?></a>
        <span><?= h(($paper['journal'] ?: 'Unknown journal') . ' · ' . ($paper['publication_date'] ?: 'Unknown date')) ?></span>
      </li>
    <?php endforeach; ?>
  </ul>
  <a href="/">View publication tracker</a>
</section>
<?php
$html = ob_get_clean();

header('Content-Type: application/javascript; charset=utf-8');
header('Cache-Control: public, max-age=300');
?>
(function () {
  var current = document.currentScript;
  var targetId = current && current.getAttribute("data-target");
  var target = targetId ? document.getElementById(targetId) : null;
  if (!target && current) {
    target = document.createElement("div");
    current.parentNode.insertBefore(target, current);
  }
  if (target) {
    target.innerHTML = <?= json_encode($html, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
  }
}());
