<?php
declare(strict_types=1);

final class ResearchModelPage
{
    public static function render(array $config): void
    {
        $assetVersion = (string)($config['asset_version'] ?? '20261007-research-v176');
        $title = (string)$config['title'];
        $pageTitle = $title . ' | Psilocybin Research';
        $description = (string)$config['description'];
        $path = (string)$config['path'];
        $current = (string)$config['current'];
        $icon = (string)$config['icon'];
        $kind = (string)$config['kind'];
        $baseUrl = rtrim(Config::publicBaseUrl(), '/') . '/';
        $schema = [
            '@context' => 'https://schema.org',
            '@type' => 'WebPage',
            'name' => $title,
            'description' => $description,
            'url' => $baseUrl . $path,
            'isPartOf' => [
                '@type' => 'WebSite',
                'name' => 'Psilocybin Research',
                'url' => $baseUrl,
            ],
            'about' => [
                '@type' => 'Thing',
                'name' => (string)$config['about'],
            ],
        ];
        ?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= h($pageTitle) ?></title>
  <?= detail_page_seo($pageTitle, $description, $path, ['robots' => 'noindex, follow, noarchive']) ?>
  <link rel="preload" href="assets/preloader-mushroom-desktop.webp" as="image" media="(min-width: 701px)" fetchpriority="high">
  <link rel="preload" href="assets/preloader-mushroom-mobile.webp" as="image" media="(max-width: 700px)" fetchpriority="high">
  <link rel="stylesheet" href="<?= h(FrontendAssets::url('assets/styles.min.css')) ?>">
  <script src="<?= h(FrontendAssets::url('assets/app.min.js')) ?>" defer></script>
  <?= json_ld_script($schema) ?>
  <script>document.documentElement.classList.add('js', 'is-loading');</script>
</head>
<body class="detail-page research-model-page research-model-<?= h($kind) ?>">
<?= detail_preloader('Preparing ' . mb_strtolower($title, 'UTF-8') . '…', 'Loading the ' . mb_strtolower($title, 'UTF-8') . ' preview') ?>
<?= detail_page_header($current) ?>
<main class="detail-shell research-model-shell" id="main">
  <section class="panel model-preview-hero">
    <div class="model-preview-copy">
      <span class="model-status">Research module · Coming soon</span>
      <p class="eyebrow"><?= h((string)$config['eyebrow']) ?></p>
      <h1><?= h($title) ?></h1>
      <p class="model-preview-lede"><?= h($description) ?></p>
      <div class="model-preview-actions">
        <a class="primary iconed" href="./#papers"><i data-icon="book-marked" aria-hidden="true"></i><span>Explore publications</span></a>
        <a class="secondary iconed" href="trials-map.php"><i data-icon="map-pin" aria-hidden="true"></i><span>Open trials map</span></a>
      </div>
    </div>
    <div class="model-preview-visual" data-model-kind="<?= h($kind) ?>" aria-hidden="true">
      <span class="model-orbit orbit-one"></span><span class="model-orbit orbit-two"></span>
      <i class="model-visual-icon" data-icon="<?= h($icon) ?>"></i>
    </div>
  </section>

</main>
<?= detail_footer() ?>
<?= detail_scroll_top() ?>
</body>
</html>
        <?php
    }
}
