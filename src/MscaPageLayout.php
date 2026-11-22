<?php
declare(strict_types=1);

// Shared branding; the story footer uses its selected locale, companion pages default to English.
function msca_page_brand(): string
{
    return <<<'HTML'
<a class="brand" href="https://psilocybin-research.com/" lang="en" dir="ltr" translate="no" aria-label="Psilocybin-Research.com home">
  Psilocybin-Research.com
  <span>Bibliometrics, context and science communication</span>
</a>
HTML;
}

function msca_page_footer(string $locale = 'en'): string
{
    $html = <<<'HTML'
<footer class="footer" lang="en" dir="ltr" translate="no">
    <div class="footer-vanta" id="footer-vanta" aria-hidden="true"></div>
    <div class="footer-inner">
      <div class="footer-copy">
        <a class="footer-msca-lockup" href="https://marie-sklodowska-curie-actions.ec.europa.eu/" target="_blank" rel="noopener noreferrer" aria-label="Marie Skłodowska-Curie Actions">
          <img src="/assets/msca-impact/marie-curie.svg" alt="Marie Skłodowska-Curie Actions" width="88" height="119" loading="eager" decoding="sync">
        </a>
      </div>
      <nav class="footer-links" aria-label="MSCA page footer links">
        <a href="mailto:christopher-germann@uni-wh.de"><svg class="ui-icon" aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><rect width="20" height="16" x="2" y="4" rx="2"/><path d="m22 7-10 6L2 7"/></svg><span>christopher-germann@uni-wh.de</span></a>
        <a href="https://marie-sklodowska-curie-actions.ec.europa.eu/" target="_blank" rel="noopener noreferrer"><svg class="ui-icon" aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M15 3h6v6"/><path d="M10 14 21 3"/><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/></svg><span>MSCA homepage</span></a>
        <a href="https://research-and-innovation.ec.europa.eu/strategy/strategy-research-and-innovation/our-digital-future/open-science_en" target="_blank" rel="noopener noreferrer"><svg class="ui-icon" aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="10" rx="2"/><path d="M7 11V7a5 5 0 0 1 9.9-1"/><circle cx="12" cy="16" r="1.15"/><path d="M12 17.15V19"/></svg><span>Open science</span></a>
        <a href="https://ukri.psilocybin-research.com/msca-impact/data-protection.php"><svg class="ui-icon" aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M20 13c0 5-3.5 7.5-8 9-4.5-1.5-8-4-8-9V5l8-3 8 3z"/></svg><span>Data protection</span></a>
        <a href="https://ukri.psilocybin-research.com/msca-impact/credits.php" hreflang="en"><svg class="ui-icon" aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="m11 17 2 2a1 1 0 1 0 3-3" /><path d="m14 14 2.5 2.5a1 1 0 1 0 3-3l-3.88-3.88a3 3 0 0 0-4.24 0l-.88.88a1 1 0 1 1-3-3l2.81-2.81a5.79 5.79 0 0 1 7.06-.87l.47.28a2 2 0 0 0 1.42.25L21 4" /><path d="m21 3 1 11h-2" /><path d="M3 3 2 14l6.5 6.5a1 1 0 1 0 3-3" /><path d="M3 4h8" /></svg><span lang="en">Credits</span></a>
        <a href="https://ukri.psilocybin-research.com/msca-impact/official-sources.php" hreflang="en"><svg class="ui-icon" aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M10 13a5 5 0 0 0 7 0l3-3a5 5 0 0 0-7-7l-2 2"/><path d="M14 11a5 5 0 0 0-7 0l-3 3a5 5 0 0 0 7 7l2-2"/></svg><span>Official sources</span></a>
      </nav>
    </div>
  </footer>
HTML;
    if ($locale !== 'en') {
        $config = msca_locales()[$locale] ?? msca_locales()['en'];
        $html = str_replace('<footer class="footer" lang="en" dir="ltr" translate="no">', '<footer class="footer" lang="' . h($config['lang']) . '" dir="' . h($config['dir']) . '">', $html);
        $html = str_replace('<span lang="en">Credits</span>', '<span>Credits</span>', $html);
        foreach (['MSCA homepage', 'Open science', 'Data protection', 'Credits', 'Official sources'] as $label) {
            $html = str_replace('>' . $label . '<', '>' . h(msca_t($locale, $label)) . '<', $html);
        }
        $html = str_replace('aria-label="MSCA page footer links"', 'aria-label="' . h(msca_t($locale, 'MSCA page footer links')) . '"', $html);
    }
    return $html;
}

function msca_page_secondary_header(): string
{
    return '<header class="site-header"><div class="msca-simple-header">'
        . msca_page_brand()
        . '<nav class="nav-links" aria-label="Return navigation"><a class="msca-back-link" data-history-back href="https://ukri.psilocybin-research.com/msca-impact/" dir="ltr" translate="no"><svg class="ui-icon" aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="m12 19-7-7 7-7"/><path d="M19 12H5"/></svg><span>Back</span></a></nav>'
        . '</div></header>';
}
