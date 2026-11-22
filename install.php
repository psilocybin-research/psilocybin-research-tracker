<?php
declare(strict_types=1);
require_once __DIR__ . '/src/helpers.php';
$title = 'Install the Psilocybin Research App';
$description = 'Install the free Psilocybin Research progressive web app on your phone or computer. Download the full publication catalogue for offline research and automatic synchronisation.';
$installIcon = static function (string $name): string {
    $paths = [
        'phone' => '<rect x="6" y="2" width="12" height="20" rx="2.5"/><path d="M10 18h4"/>',
        'computer' => '<rect x="3" y="3" width="18" height="13" rx="2"/><path d="M12 16v5M8 21h8"/>',
        'laptop' => '<path d="M5 17V5a1 1 0 0 1 1-1h12a1 1 0 0 1 1 1v12M2 17h20l-2 3H4z"/>',
        'download' => '<path d="M12 3v12m-4-4 4 4 4-4M4 16v4h16v-4"/>',
        'offline' => '<path d="M2 2l20 20M8 16a6 6 0 0 1 8 0M5 12a11 11 0 0 1 2-1.4M12 9a11 11 0 0 1 7 3M2 8a16 16 0 0 1 2.3-1.6M8 5a16 16 0 0 1 14 3"/><circle cx="12" cy="20" r="1"/>',
        'sync' => '<path d="M20 5v5h-5M4 19v-5h5M4.5 9a8 8 0 0 1 13-4L20 8M4 16l2.5 3a8 8 0 0 0 13-4"/>',
    ];
    return '<svg class="install-line-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $paths[$name] . '</svg>';
};
$installVersion = substr(hash_file('sha256', __DIR__ . '/assets/install-app.css') . hash_file('sha256', __DIR__ . '/assets/install-app.js'), 0, 16);
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title><?= h($title) ?> | Psilocybin Research</title>
<?= detail_page_seo($title, $description, 'install.php') ?>
<link rel="icon" href="/assets/logo.png">
<link rel="apple-touch-icon" href="/assets/pwa/apple-touch-icon.png">
<link rel="manifest" href="/manifest.webmanifest">
<link rel="stylesheet" href="<?= h(FrontendAssets::url('assets/styles.min.css')) ?>">
<link rel="stylesheet" href="/assets/install-app.min.css?v=<?= h($installVersion) ?>">
<script>try{if(matchMedia('(max-width:1180px)').matches)document.documentElement.classList.add('nav-sidebar-collapsed')}catch(e){}</script>
<script type="application/ld+json"><?= json_encode(['@context'=>'https://schema.org','@type'=>'WebPage','name'=>$title,'url'=>Config::publicBaseUrl().'install.php','description'=>$description,'about'=>['@type'=>'WebApplication','name'=>'Psilocybin Research Tracker','url'=>Config::publicBaseUrl(),'applicationCategory'=>'EducationalApplication','operatingSystem'=>'Android, iOS, Windows, macOS, Linux','isAccessibleForFree'=>true]], JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP) ?></script>
</head>
<body class="install-page">
<a class="install-skip" href="#install-content">Skip to installation</a>
<?= detail_page_header() ?>
<main class="detail-shell install-main" id="install-content">
  <section class="install-hero" aria-labelledby="install-title">
    <div class="install-icon"><img src="/assets/pwa/icon-192.png" width="80" height="80" alt="" fetchpriority="high"></div>
    <p class="install-eyebrow">Your research, one tap away</p>
    <h1 id="install-title">Keep the research<br>close at hand.</h1>
    <p class="install-lead">Install Psilocybin Research on your phone or computer. The same searchable database, in its own app window—with the full publication catalogue available offline.</p>
    <div class="install-actions">
      <button class="primary" type="button" id="install-page-button" hidden><i data-icon="download" aria-hidden="true"></i><span>Install free app</span></button>
      <a class="secondary" href="/">Open the database <span aria-hidden="true">↗</span></a>
    </div>
    <fieldset class="install-component-choice" aria-describedby="install-component-note">
      <legend>Optional after installation</legend>
      <label><input type="checkbox" data-pwa-offline-webr> <span><strong>webR</strong><small>Run R analyses without an internet connection</small></span></label>
      <label><input type="checkbox" data-pwa-offline-latex> <span><strong>LaTeX</strong><small>Create PDF reports offline</small></span></label>
    </fieldset>
    <p class="install-caption" id="install-component-note">These optional downloads are selected now and start only after the app is installed. The app and its catalogue remain available without them.</p>
    <p class="install-caption" data-install-page-status role="status" aria-live="polite">Free · No app-store account needed</p>
    <noscript><p>Follow the installation steps below to add the app from your browser.</p></noscript>
  </section>

  <section class="install-guide" aria-labelledby="install-guide-title">
    <div class="install-section-heading"><p class="install-eyebrow">A few simple steps</p><h2 id="install-guide-title">Add it to your device</h2><p>Choose your device. Installation stays in your browser.</p></div>
    <div class="install-device-list">
      <details data-install-device="ios" open><summary><span class="install-device-name"><?= $installIcon('phone') ?>iPhone &amp; iPad</span><span class="install-device-browser">Safari</span></summary><div class="install-device-body"><ol><li>Open this page in <strong>Safari</strong> and tap <strong>Share</strong> <svg class="install-share-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true"><path d="M12 16V3m-4 4 4-4 4 4M7 11H4v10h16V11h-3"/></svg>.</li><li>Choose <strong>Add to Home Screen</strong>. Scroll down the actions if needed.</li><li>Keep <strong>Open as Web App</strong> enabled if shown, then tap <strong>Add</strong>.</li></ol><a href="https://support.apple.com/guide/iphone/iphea86e5236/ios" target="_blank" rel="noopener noreferrer">Apple’s installation guide ↗</a></div></details>
      <details data-install-device="android"><summary><span class="install-device-name"><?= $installIcon('phone') ?>Android</span><span class="install-device-browser">Chrome &amp; supported browsers</span></summary><div class="install-device-body"><ol><li>Tap <strong>Install free app</strong> above when your browser offers installation.</li><li>Or open the browser menu <strong>⋮</strong> and choose <strong>Install app</strong> or <strong>Add to Home screen</strong>.</li><li>Confirm, then launch Psilocybin Research from your new app icon.</li></ol><p>Using a browser inside another app? Open this page in Chrome first.</p></div></details>
      <details data-install-device="desktop"><summary><span class="install-device-name"><?= $installIcon('computer') ?>Computer</span><span class="install-device-browser">Chrome &amp; Edge</span></summary><div class="install-device-body"><ol><li>Click <strong>Install free app</strong> above when installation is available.</li><li>Alternatively, use the install icon in the address bar or the browser’s app-installation menu.</li><li>Confirm and open the app from your launcher or desktop.</li></ol><a href="https://support.google.com/chrome/answer/9658361" target="_blank" rel="noopener noreferrer">Chrome’s installation guide ↗</a></div></details>
      <details data-install-device="mac"><summary><span class="install-device-name"><?= $installIcon('laptop') ?>Mac</span><span class="install-device-browser">Safari</span></summary><div class="install-device-body"><ol><li>Open the database in Safari.</li><li>Choose <strong>File → Add to Dock</strong>, or <strong>Share → Add to Dock</strong>.</li><li>Confirm the name and click <strong>Add</strong>.</li></ol><a href="https://support.apple.com/104996" target="_blank" rel="noopener noreferrer">Apple’s Mac web-app guide ↗</a></div></details>
    </div>
    <p class="install-browser-note">If your browser has no installation option, you can use the full database on the web or open this page in a supported browser.</p>
  </section>

  <section class="install-offline" aria-labelledby="install-offline-title">
    <div class="install-section-heading"><p class="install-eyebrow">Research without a connection</p><h2 id="install-offline-title">The full catalogue.<br>Ready offline.</h2><p>Download the complete publication database and app pages to your device—not just the records you have already opened. Search and filter the saved catalogue, build a reading list, save searches and export references without an internet connection.</p></div>
    <ol class="install-offline-steps"><li><span class="install-step-icon"><?= $installIcon('download') ?></span><div><h3>Download once</h3><p>Open <strong>Offline library</strong> and choose <strong>Download offline app</strong> while connected. Keep the app open until the download finishes.</p></div></li><li><span class="install-step-icon"><?= $installIcon('offline') ?></span><div><h3>Work offline</h3><p>Open the app and use your downloaded catalogue wherever you are. Your reading list and saved searches stay on this device.</p></div></li><li><span class="install-step-icon"><?= $installIcon('sync') ?></span><div><h3>Stay in sync</h3><p>Your downloaded copy checks for updates when you open the app, reconnect or return to it. Keep the app open to complete a sync; Offline library shows its status.</p></div></li></ol>
    <div class="install-actions"><button class="primary" type="button" data-install-offline hidden><?= $installIcon('offline') ?>Open Offline library</button><a href="/database.php">Download SQLite database <span aria-hidden="true">↓</span></a></div>
    <p class="install-caption">Offline download covers the catalogue and app pages. Videos, audio, external papers and live services use an internet connection.</p>
  </section>

</main>
<?= detail_footer() ?>
<?= detail_scroll_top() ?>
<script src="<?= h(FrontendAssets::url('assets/app.min.js')) ?>" defer></script>
<script src="/assets/install-app.min.js?v=<?= h($installVersion) ?>" defer></script>
</body>
</html>
