<?php
declare(strict_types=1);
require_once __DIR__ . '/src/helpers.php';
$gallery = json_decode((string)file_get_contents(__DIR__ . '/config/fungi-gallery.json'), true, 32, JSON_THROW_ON_ERROR);
$first = $gallery['slides'][0];
$variant = $first['variants'][array_key_last($first['variants'])];
$srcset = static fn(array $slide): string => implode(', ', array_map(static fn(array $v): string => $v['url'] . ' ' . $v['width'] . 'w', $slide['variants']));
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
<title>Photographs by Jürgen Liebherr | Psilocybin Research</title>
<meta name="description" content="A quiet photographic journey through the forest floor. Mushroom photography by Jürgen Liebherr, presented with his permission.">
<link rel="canonical" href="https://psilocybin-research.com/fungi-gallery.php">
<meta name="theme-color" content="#101612"><meta name="color-scheme" content="dark">
<meta property="og:type" content="website"><meta property="og:title" content="Photographs by Jürgen Liebherr">
<meta property="og:description" content="A quiet photographic journey through the forest floor.">
<meta property="og:url" content="https://psilocybin-research.com/fungi-gallery.php">
<meta property="og:image" content="https://psilocybin-research.com<?= h($variant['url']) ?>">
<meta property="og:image:alt" content="<?= h($first['alt']) ?>">
<link rel="icon" href="/favicon.ico">
<link rel="stylesheet" href="assets/fungi-gallery.css?v=<?= h(substr(hash_file('sha256', __DIR__ . '/assets/fungi-gallery.css'), 0, 12)) ?>">
<script src="assets/fungi-gallery.js?v=<?= h(substr(hash_file('sha256', __DIR__ . '/assets/fungi-gallery.js'), 0, 12)) ?>" defer></script>
</head>
<body>
<main class="gallery fill" id="gallery" aria-label="Photographic slideshow">
  <div class="photograph active" aria-hidden="true"><img class="backdrop" src="<?= h($variant['url']) ?>" alt=""><img class="picture" style="object-position:<?= h($first['position'] ?? '50% 50%') ?>" src="<?= h($variant['url']) ?>" srcset="<?= h($srcset($first)) ?>" sizes="100vw" alt=""></div>
  <div class="photograph" aria-hidden="true"><img class="backdrop" alt=""><img class="picture" alt=""></div>
  <header class="gallery-header chrome">
    <a class="return-link" href="/" aria-label="Return to Psilocybin-Research.com"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M19 12H5m6-6-6 6 6 6"/></svg><span>Psilocybin-Research.com</span></a>
    <button class="icon-button" id="fullscreen" type="button" aria-label="Enter full screen" title="Full screen" hidden><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M8 3H3v5m13-5h5v5m0 8v5h-5M3 16v5h5"/></svg></button>
  </header>
  <div class="gallery-caption chrome"><p class="credit">Photography by <a href="<?= h($gallery['profile']) ?>" target="_blank" rel="noopener">Jürgen Liebherr</a> <span class="credit-dot" aria-hidden="true">·</span> Used with permission <span class="credit-dot" aria-hidden="true">·</span> <a id="source-link" href="<?= h($first['source']) ?>" target="_blank" rel="noopener">View on Pixabay ↗</a></p></div>
  <div class="gallery-controls chrome" role="group" aria-label="Slideshow controls">
    <button class="icon-button" id="previous" type="button" aria-label="Previous photograph" title="Previous photograph"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="m14 5-7 7 7 7"/></svg></button>
    <button class="icon-button play-button" id="play" type="button" aria-label="Pause slideshow" title="Pause slideshow"><svg viewBox="0 0 24 24" aria-hidden="true"><path id="play-path" d="M8 5v14M16 5v14"/></svg></button>
    <button class="icon-button" id="next" type="button" aria-label="Next photograph" title="Next photograph"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="m10 5 7 7-7 7"/></svg></button>
    <span class="control-divider" aria-hidden="true"></span>
    <button class="icon-button" id="browse" type="button" aria-label="Browse photographs" aria-expanded="false" aria-controls="thumbnails" title="Browse photographs"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 3h7v7H3zm11 0h7v7h-7zM3 14h7v7H3zm11 0h7v7h-7z"/></svg></button>
  </div>
  <nav class="thumbnails chrome" id="thumbnails" aria-label="Choose a photograph" hidden>
    <?php foreach ($gallery['slides'] as $index => $slide): ?><button type="button" data-index="<?= $index ?>" aria-label="Photograph <?= $index + 1 ?>: <?= h($slide['alt']) ?>" aria-current="<?= $index === 0 ? 'true' : 'false' ?>"><img src="<?= h($slide['thumbnail']) ?>" alt="" loading="lazy"></button><?php endforeach; ?>
  </nav>
  <p class="sr-only" id="announcement" aria-live="polite" aria-atomic="true"></p>
  <p class="load-error chrome" id="load-error" role="status" hidden>The photograph could not be loaded. Please try another.</p>
  <noscript><style>.gallery-controls,#fullscreen{display:none!important}</style><p class="noscript chrome">Enable JavaScript to play the slideshow. <a href="<?= h($gallery['profile']) ?>">Browse the photographer’s portfolio ↗</a></p></noscript>
</main>
<script type="application/json" id="gallery-data"><?= json_encode($gallery, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?></script>
</body>
</html>
