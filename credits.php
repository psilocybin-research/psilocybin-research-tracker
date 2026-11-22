<?php
declare(strict_types=1);

require_once __DIR__ . '/src/helpers.php';

$baseUrl = rtrim(Config::publicBaseUrl(), '/') . '/';
$canonicalUrl = $baseUrl . 'credits.php';
$pageTitle = 'Credits and Licences | Psilocybin Research';
$description = 'Software, fonts, icons, photography, maps, research data sources and production tools used by the Psilocybin Research database and tracker.';
$external = static fn(string $url, string $label): string => '<a href="' . h($url) . '" target="_blank" rel="noopener">' . h($label) . '</a>';
$notice = static fn(string $file, string $label): string => '<a href="/assets/licenses/' . h($file) . '">' . h($label) . '</a>';
$browserSoftware = [
    ['Chart.js 4.5.1', 'Chart.js contributors', 'https://www.chartjs.org/', 'Charts and statistical visualisations', 'chartjs-MIT.txt', 'MIT'],
    ['@kurkle/color 0.3.2', 'Jukka Kurkela', 'https://github.com/kurkle/color', 'Colour utilities bundled with Chart.js', 'kurkle-color-MIT.txt', 'MIT'],
    ['Three.js r134', 'Three.js authors and contributors', 'https://github.com/mrdoob/three.js/tree/r134', '3D rendering for the alert page’s network background', 'three-r134-MIT.txt', 'MIT'],
    ['Vanta.NET 0.5.24', 'Teng Bao and contributors', 'https://github.com/tengbao/vanta', 'Animated network background on the alert page', 'vanta-MIT.txt', 'MIT'],
];
$rPackages = [
    'pacman' => 'GPL-2', 'DBI' => 'LGPL ≥ 2.1', 'RSQLite' => 'LGPL ≥ 2.1',
    'dplyr' => 'MIT', 'tidyr' => 'MIT', 'stringr' => 'MIT', 'lubridate' => 'MIT',
    'httr2' => 'MIT', 'jsonlite' => 'MIT', 'purrr' => 'MIT', 'readr' => 'MIT',
    'ggplot2' => 'MIT', 'igraph' => 'GPL ≥ 2', 'visNetwork' => 'MIT',
    'htmlwidgets' => 'MIT', 'htmltools' => 'GPL ≥ 2', 'DT' => 'MIT',
    'webshot2' => 'MIT', 'digest' => 'GPL ≥ 2',
];
$structuredData = [
    '@context' => 'https://schema.org', '@graph' => [
        ['@type' => 'WebPage', '@id' => $canonicalUrl . '#page', 'name' => $pageTitle,
            'description' => $description, 'url' => $canonicalUrl, 'dateModified' => '2026-11-09',
            'isPartOf' => ['@type' => 'WebSite', 'name' => 'Psilocybin Research', 'url' => $baseUrl]],
        ['@type' => 'BreadcrumbList', 'itemListElement' => [
            ['@type' => 'ListItem', 'position' => 1, 'name' => 'Psilocybin Research', 'item' => $baseUrl],
            ['@type' => 'ListItem', 'position' => 2, 'name' => 'Credits and licences', 'item' => $canonicalUrl],
        ]],
    ],
];
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= h($pageTitle) ?></title>
  <?= detail_page_seo($pageTitle, $description, 'credits.php') ?>
  <?= json_ld_script($structuredData) ?>
  <link rel="stylesheet" href="<?= h(FrontendAssets::url('assets/styles.min.css')) ?>">
  <script src="<?= h(FrontendAssets::url('assets/app.min.js')) ?>" defer></script>
</head>
<body class="detail-page tracker-credits-page">
<?= detail_page_header('about') ?>
<main class="detail-shell tracker-credits">
  <section class="panel detail-hero">
    <span class="eyebrow">Acknowledgements and attribution</span>
    <h1>Credits and licences</h1>
    <p>Psilocybin Research builds on the work of software developers, photographers, designers and research-data providers. This page covers the publication tracker, its research tools, downloads and research film.</p>
    <p>The independently presented <a href="https://ukri.psilocybin-research.com/msca-impact/">MSCA impact story</a> maintains its own <a href="https://ukri.psilocybin-research.com/msca-impact/credits.php">credits and licences</a>, including its scientific hero videos, portraits and campaign artwork.</p>
    <p>Last reviewed <time datetime="2026-11-09">9 November 2026</time>.</p>
  </section>

  <nav class="panel credits-index" aria-label="Credits sections">
    <a href="#project">Project</a><a href="#software">Website software</a><a href="#design">Fonts and icons</a><a href="#images">Images and media</a><a href="#data">Data providers</a><a href="#production">Production tools</a><a href="#r-toolkit">R toolkit</a><a href="#r-package">R package</a><a href="#shinylive">Shinylive</a><a href="#notices">Licence notices</a>
  </nav>

  <section class="panel" id="project" aria-labelledby="project-heading">
    <h2 id="project-heading">Project and maintenance</h2>
    <p>Created and maintained by <a href="/editorial-policy.php#author">Dr. Christopher B. Germann</a>. The project’s application code is available in the <?= $external('https://github.com/psilocybin-research/psilocybin-research-tracker', 'public GitHub repository') ?> under the <?= $notice('tracker-MIT.txt', 'MIT licence') ?>. Historical support is acknowledged in the footer; the <a href="/about.php">about page</a> and <a href="/editorial-policy.php">editorial policy</a> describe the project and its research responsibilities.</p>
    <p>Software licences, dataset reuse terms and third-party image rights apply separately. The project’s MIT licence does not relicense photographs, institutional marks, source publications or third-party software.</p>
  </section>

  <section class="panel" id="software" aria-labelledby="software-heading">
    <h2 id="software-heading">Website software</h2>
    <ul class="credits-list">
      <?php foreach ($browserSoftware as [$name, $author, $url, $use, $file, $licence]): ?>
      <li><strong><?= $external($url, $name) ?></strong> — <?= h($author) ?>. <?= h($use) ?>. <?= $notice($file, $licence . ' licence and copyright notice') ?>.</li>
      <?php endforeach; ?>
      <li><strong><?= $external('https://gsap.com/', 'GSAP 3.15.0') ?></strong> — GreenSock / Jack Doyle / Webflow. Animation on the research film page. Distributed under the <?= $external('https://gsap.com/community/standard-license/', 'GSAP Standard “No Charge” Licence') ?>; this is a separate licence from MIT. The copyright and licence link remain in the distributed script.</li>
    </ul>
    <p>The tracker’s search, maps, offline library, service worker and read-only MCP server use project code and native browser/server interfaces. The browser libraries above are loaded where their features are needed.</p>
    <p>Parquet and Feather dataset files are generated with <a href="https://arrow.apache.org/">Apache Arrow / PyArrow</a> (Apache License 2.0), using Zstandard compression.</p>
    <p>The server application uses <?= $external('https://www.php.net/license/', 'PHP and PDO (PHP licence)') ?>, <?= $external('https://www.sqlite.org/copyright.html', 'SQLite (public domain)') ?> for the catalogue database, and <?= $external('https://ecma-international.org/publications-and-standards/standards/ecma-404/', 'JSON (ECMA-404)') ?> for public APIs, exports and structured metadata. It also uses <?= $external('https://curl.se/docs/copyright.html', 'cURL / libcurl (curl licence)') ?> and <?= $external('https://www.openssl.org/source/license.html', 'OpenSSL (version-specific upstream licence)') ?>. Hosting is provided by <?= $external('https://alfahosting.de/', 'Alfahosting') ?> using <?= $external('https://nginx.org/LICENSE', 'nginx (BSD-style licence)') ?> and <?= $external('https://www.apache.org/licenses/LICENSE-2.0', 'Apache HTTP Server (Apache 2.0)') ?>.</p>
  </section>

  <section class="panel" id="design" aria-labelledby="design-heading">
    <h2 id="design-heading">Fonts and interface icons</h2>
    <ul class="credits-list">
      <li><strong>Roboto</strong> — the Roboto Project Authors; designed by Christian Robertson, with contributions from ParaType and Font Bureau. The tracker’s locally hosted Latin and extended-Latin webfonts are Roboto 3.015 from <?= $external('https://github.com/googlefonts/roboto-classic', 'Roboto Classic') ?>, distributed under the <?= $notice('roboto-OFL.txt', 'SIL Open Font Licence 1.1') ?>. System fonts are used as fallbacks.</li>
      <li><strong>Interface glyphs</strong> combine project SVG drawings and adaptations from <?= $external('https://lucide.dev/', 'Lucide') ?> (<?= $notice('lucide-LICENSE.txt', 'ISC and inherited Feather notices') ?>), <?= $external('https://feathericons.com/', 'Feather') ?> (<?= $notice('feather-MIT.txt', 'MIT') ?>) and <?= $external('https://tabler.io/icons', 'Tabler Icons') ?> (<?= $notice('tabler-icons-MIT.txt', 'MIT') ?>). Changes include stroke, size and interface-specific combinations.</li>
    </ul>
  </section>

  <section class="panel" id="images" aria-labelledby="images-heading">
    <h2 id="images-heading">Photography, maps, marks and media</h2>
    <ul class="credits-list">
      <li><strong>Mushroom photograph</strong> — Jürgen Liebherr (<?= $external('https://pixabay.com/users/jggrz-7998824/', 'jggrz') ?>), <?= $external('https://pixabay.com/photos/mushroom-helmling-moss-tap-forest-8512804/', 'original photograph on Pixabay') ?>. Used with the photographer’s direct permission. <a href="/fungi-gallery.php">Fungi photographic gallery</a>. <a href="/assets/licenses/mushroom-photo-source.txt">Source and permission information</a>.</li>
      <li><strong>World map geometry</strong> — <?= $external('https://www.naturalearthdata.com/', 'Natural Earth') ?>, Tom Patterson, Nathaniel Vaughn Kelso and contributors. Public-domain 1:110m Admin 0 Countries, version 5.1.1. The local GeoJSON selects country names/codes and rounds coordinates for a compact interactive map. <?= $notice('natural-earth-source.txt', 'Source, modifications and terms') ?>.</li>
      <li><strong>European Union and research-programme marks</strong> — European Union emblem and European Commission / Marie Curie Actions artwork from official institutional material; CogNovo mark from <?= $external('https://cognovo.eu/', 'CogNovo') ?>. Used for historical funding acknowledgement. These marks retain their owners’ rights and do not imply institutional endorsement. <?= $external('https://commission.europa.eu/resources/european-commission-visual-identity_en', 'European Commission visual identity and reuse information') ?>.</li>
      <li><strong>MCAA report cover on the research film page</strong> — <cite>Impact of the Marie Skłodowska-Curie Actions (MSCA): Driving Knowledge, Innovation, and EU Competitiveness</cite> (2025), Tereza Szybisty, Mostafa Moonir Shawrav and Gian Maria Greco; © Marie Curie Alumni Association. <?= $external('https://doi.org/10.56647/HWZO5676', 'Original report') ?>, <?= $external('https://creativecommons.org/licenses/by/4.0/', 'CC BY 4.0') ?>. The cover was converted to responsive WebP images.</li>
      <li><strong>Research film, charts and procedural soundtrack</strong> — developed for Psilocybin Research from the project’s dated aggregate data and custom animation, rendering and audio scripts. The soundtrack is procedurally synthesised; it does not use stock music or sampled field recordings. Production tools are credited below. Scientific illustrations and motion graphics are explanatory and should be read with their accompanying data and limitations.</li>
    </ul>
  </section>

  <section class="panel" id="data" aria-labelledby="data-heading">
    <h2 id="data-heading">Research data providers</h2>
    <p>The publication catalogue combines records and identifiers supplied by these services and their contributing authors, publishers and registries:</p>
    <ul class="credits-list">
      <li><?= $external('https://pubmed.ncbi.nlm.nih.gov/', 'PubMed — US National Library of Medicine / NCBI') ?>.</li>
      <li><?= $external('https://www.crossref.org/', 'Crossref') ?> and participating publishers.</li>
      <li><?= $external('https://europepmc.org/', 'Europe PMC') ?> and its partner organisations.</li>
      <li><?= $external('https://openalex.org/', 'OpenAlex — OurResearch') ?>.</li>
      <li><?= $external('https://www.biorxiv.org/', 'bioRxiv') ?> and <?= $external('https://www.medrxiv.org/', 'medRxiv') ?> preprint services.</li>
      <li><?= $external('https://osf.io/preprints/psyarxiv', 'PsyArXiv / Open Science Framework — Center for Open Science') ?>.</li>
      <li><?= $external('https://clinicaltrials.gov/', 'ClinicalTrials.gov — US National Library of Medicine') ?> and study sponsors submitting registry records.</li>
    </ul>
    <p>Additional research tools use <?= $external('https://datacite.org/', 'DataCite') ?> for dataset discovery and the European Commission’s <?= $external('https://cordis.europa.eu/', 'CORDIS') ?> for EU-funded research projects. <?= $external('https://zenodo.org/', 'Zenodo — CERN / OpenAIRE') ?> hosts the project’s archived dataset releases; GitHub hosts its public software and documentation.</p>
    <p>Authors, publishers and source services retain their respective rights. Metadata availability does not grant permission to redistribute protected abstracts or full texts. See <a href="/data-protection.php#public-data-rights">public-data rights</a>, <a href="/editorial-policy.php">source validation and methodology</a>, and <a href="/research-dataset.php">version-specific dataset licences and citation</a>.</p>
  </section>

  <section class="panel" id="production" aria-labelledby="production-heading">
    <h2 id="production-heading">Build, rendering and audio tools</h2>
    <p>These development tools produce the website’s scripts, styles and original media; their licence terms apply to the tools themselves.</p>
    <ul class="credits-list">
      <li><strong>Website builds:</strong> <?= $external('https://esbuild.github.io/', 'esbuild 0.25.8 — Evan Wallace') ?> (<?= $notice('esbuild-MIT.txt', 'MIT') ?>) and <?= $external('https://postcss.org/', 'PostCSS 8.5.32 — Andrey Sitnik and contributors') ?> (<?= $notice('postcss-MIT.txt', 'MIT') ?>), running on <?= $external('https://nodejs.org/', 'Node.js') ?>. PostCSS includes Nano ID (<?= $notice('nanoid-MIT.txt', 'MIT') ?>), picocolors (<?= $notice('picocolors-ISC.txt', 'ISC') ?>) and source-map-js (<?= $notice('source-map-js-BSD-3-Clause.txt', 'BSD 3-Clause') ?>).</li>
      <li><strong>3D animation and encoding:</strong> <?= $external('https://www.blender.org/about/license/', 'Blender — Blender Foundation and contributors (GNU GPL)') ?> and <?= $external('https://ffmpeg.org/legal.html', 'FFmpeg — FFmpeg developers (LGPL/GPL, depending on build configuration)') ?>.</li>
      <li><strong>Rendering, image processing and analysis:</strong> <?= $external('https://www.python.org/psf/license/', 'Python — Python Software Foundation') ?>; <?= $external('https://numpy.org/doc/stable/license.html', 'NumPy (BSD 3-Clause)') ?>; <?= $external('https://scipy.org/', 'SciPy (BSD 3-Clause)') ?>; <?= $external('https://pillow.readthedocs.io/en/stable/about.html#license', 'Pillow (MIT-CMU / HPND notices)') ?>; <?= $external('https://matplotlib.org/stable/project/license.html', 'Matplotlib (PSF-based licence)') ?>. Rendered typography uses Roboto and <?= $external('https://dejavu-fonts.github.io/License.html', 'DejaVu Sans (Bitstream Vera / DejaVu terms)') ?>.</li>
      <li><strong>Procedural audio:</strong> <?= $external('https://python-soundfile.readthedocs.io/', 'SoundFile (BSD 3-Clause)') ?> with <?= $external('https://libsndfile.github.io/libsndfile/', 'libsndfile (LGPL)') ?>, and <?= $external('https://github.com/spotify/pedalboard', 'Pedalboard — Spotify (GPL-3.0)') ?>, alongside NumPy and SciPy.</li>
    </ul>
  </section>

  <section class="panel" id="r-toolkit" aria-labelledby="r-heading">
    <h2 id="r-heading">Optional R analysis toolkit</h2>
    <p>The downloadable bibliometrics toolkit and reproducible examples use <?= $external('https://www.r-project.org/', 'R — R Core Team and contributors (GPL-2 / GPL-3)') ?> and the packages below. Each linked CRAN entry identifies the package’s authors, maintainers, licence and citation. Package versions and additional dependencies depend on the user’s R installation; use <code>citation("package")</code> and <code>sessionInfo()</code> when reporting an analysis.</p>
    <ul class="credits-package-list">
      <?php foreach ($rPackages as $name => $licence): ?>
      <li><?= $external('https://cran.r-project.org/package=' . rawurlencode($name), $name) ?> <span>(<?= h($licence) ?>)</span></li>
      <?php endforeach; ?>
    </ul>
    <p>Packages may incorporate further components, including vis.js in visNetwork and DataTables in DT. Their bundled notices and dependency licences remain applicable to generated HTML output. These optional R packages are not dependencies for browsing the tracker.</p>
  </section>

  <section class="panel" id="webr" aria-labelledby="webr-heading">
    <h2 id="webr-heading">WebR analysis workspace</h2>
    <p>The openly accessible <a href="/r-analysis/workspace/">WebR analysis workspace</a> provides browser-side R execution and downloads without sign-in; an account is needed only for online saving and cross-device retrieval. It uses <a href="https://docs.r-wasm.org/webr/v0.6.0/">webR 0.6.0</a>, developed by George Stagg, Lionel Henry and webR contributors, to run <a href="https://www.r-project.org/">R (R Core Team and contributors)</a> in a browser worker. The self-hosted runtime includes R, its standard packages and numerical libraries. <a href="/assets/account-webr/LICENSE.md">Bundled licence notices</a> and the <a href="https://github.com/r-wasm/webr/releases/tag/v0.6.0">upstream release and source</a> describe the component licences. The custom research interface, dataset verification, account storage, staged runtime loader and reproducibility exports are developed for Psilocybin-Research.com by Christopher B. Germann. Offline LaTeX archives use <a href="https://github.com/101arrowz/fflate">fflate</a> (<a href="/assets/licenses/fflate-MIT.txt">MIT licence</a>). The code editor uses <a href="https://codemirror.net/">CodeMirror 6</a> and its R language mode. <a href="/assets/licenses/codemirror-notices.txt">Editor licence notices</a> are provided. Runtime download progress reflects transferred bytes; computation runs in a webR browser worker. Additional packages retain their own authorship, citation and licensing requirements; saved analyses include <code>sessionInfo()</code>.</p>
  <p>Optional offline PDF reports use <a href="https://github.com/SiglumProject/siglum">Siglum 0.2.2</a> (MIT) with the unmodified <a href="https://github.com/SiglumProject/busytex">BusyTeX</a> WebAssembly build of pdfTeX / TeX Live 2026. TeX components and bundled libraries retain their individual licences, including GPL and LGPL components; LaTeX packages retain LPPL or other declared licences. Reports use KOMA-Script, Latin Modern fonts, geometry, graphicx, xcolor, booktabs, longtable, array, ragged2e, scrlayer-scrpage, hyperref and bookmark. <a href="/assets/account-latex/LICENSE-Siglum-MIT.txt">SDK licence</a> · <a href="/assets/account-latex/LICENSE-TeX-Live.txt">TeX licence notice</a> · <a href="/assets/account-latex/UPSTREAM.json">Runtime provenance</a> · <a href="/assets/account-latex/source/texlive-source-f26cc5e.tar.gz">TeX Live corresponding source</a> · <a href="/assets/account-latex/source/busytex-a8028d0.tar.gz">BusyTeX build source</a> · <a href="/assets/account-latex/source/fontconfig-2.13.96.tar.gz">Fontconfig source</a> · <a href="/assets/account-latex/source/expat-2.5.0.tar.gz">Expat source</a>.</p>
<h3 id="r-package">psilocybinresearch R package</h3><p>The <a href="https://github.com/psilocybin-research/psilocybinresearch">psilocybinresearch R package</a> is developed by Christopher B. Germann (MIT) and uses jsonlite (Jeroen Ooms and contributors) and digest (Dirk Eddelbuettel and contributors). Cite the package and the exact dataset snapshot. <a href="/r-analysis/#r-package">Installation and usage</a>.</p><h3 id="shinylive">Shinylive research explorer</h3><p>The <a href="/shinyapp-live">alternative research explorer</a> uses <a href="https://posit-dev.github.io/r-shinylive/">Shinylive</a> and <a href="https://shiny.posit.co/">Shiny</a>, developed by Posit and contributors, with a separately exported webR runtime. <a href="/apps/research-explorer/THIRD-PARTY-LICENSES.txt">Bundled licence notices and upstream source links</a> are available. The app source, snapshot preparation and export instructions are provided in the <a href="https://github.com/psilocybin-research/psilocybinresearch/tree/main/examples/shinylive">reproducible example</a>. The optional authors-and-citations example uses <a href="https://datastorm-open.github.io/visNetwork/">visNetwork 2.1.4</a> (Benoit Thieurmel, R interface; Almende B.V. and vis.js contributors, JavaScript network visualisation), with htmlwidgets, htmltools and jsonlite. <a href="/assets/licenses/visnetwork-notices.txt">Component notices</a> identify the MIT and dual MIT/Apache-2.0 licensing. Packages load on request. Graph exports record the actual runtime versions, seed paper, snapshot and citation retrieval time. Author-name labels are not disambiguated identities, and observed citation links are incomplete. These tools provide bibliographic exploration, not an independent effect-size database.</p>
<p>The optional full offline Shiny download also includes visNetwork and its browser-compatible dependencies from <a href="https://repo.r-wasm.org/">the r-wasm package repository</a>, together with a dated public citation snapshot. Package authorship and licences remain in each package; <a href="/apps/research-explorer/OFFLINE-PACKAGE-SOURCES.json">version-specific source links and checksums</a> identify corresponding source archives. Downloaded citation links are incomplete bibliographic metadata and do not update while offline.</p>
</section>

  <section class="panel" id="latex-reports" aria-labelledby="latex-reports-heading"><h2 id="latex-reports-heading">LaTeX analysis reports</h2><p>PDF exports use <a href="https://tectonic-typesetting.github.io/">Tectonic 0.17.0</a>, its XeTeX-based typesetting engine and cached TeX resources, with <a href="https://ctan.org/pkg/koma-script">KOMA-Script</a> for document layout, <a href="https://ctan.org/pkg/booktabs">booktabs</a> and <a href="https://ctan.org/pkg/longtable">longtable</a> for tables, and the standard fontspec, graphicx, geometry, ragged2e, scrlayer-scrpage, hyperref and bookmark packages. The source can also be compiled with LuaLaTeX. These components retain their respective upstream licences; source and licence information is available through TeX Live and the linked CTAN entries.</p><p><a href="https://dejavu-fonts.github.io/">DejaVu fonts</a> are included in source archives for portable typography. <a href="/assets/account-report-fonts/LICENSE.txt">Font copyright and licence notices</a> are included with each archive. Christopher B. Germann developed the research report template and export integration. Reports contain user-authored outputs and are not independently reviewed research publications.</p></section>
<section class="panel" id="notices" aria-labelledby="notices-heading">
    <h2 id="notices-heading">Licence notices and corrections</h2>
    <p><a href="/assets/licenses/third-party-notices.txt">Download the collected software, font and map notices</a>. Individual notices are linked above; upstream links provide the separate GSAP, image, institutional and production-tool terms.</p>
    <p>Please report missing or incorrect attribution to <a href="mailto:<?= h(Config::contactEmail()) ?>"><?= h(Config::contactEmail()) ?></a>, including the asset or component, original source and relevant licence. Credits are reviewed when dependencies or media change.</p>
  </section>
</main>
<?= detail_footer() ?>
<?= detail_scroll_top() ?>
</body>
</html>
