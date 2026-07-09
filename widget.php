<?php
declare(strict_types=1);

require_once __DIR__ . '/src/helpers.php';

$db = new Database();
$db->initialize();
$repo = new PublicationRepository($db);
$papers = $repo->latest(5);
header('Content-Type: text/html; charset=utf-8');
?>
<section class="publication-widget">
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
