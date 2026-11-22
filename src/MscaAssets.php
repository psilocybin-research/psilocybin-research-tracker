<?php
declare(strict_types=1);

/** Content-based cache key shared by the MSCA page assets. */
function msca_asset_hash(): string
{
    static $hash;
    if ($hash === null) {
        $context = hash_init('sha256');
        foreach (['styles.min.css', 'app.min.js', 'credits.min.css', 'language-flags.svg', 'r-code-viewer.min.js', 'r-code-viewer.min.css', 'royal-society-gallery.min.css', 'royal-society-gallery.min.js', 'royal-society-event.min.css', 'royal-society-network.svg', 'spore2.webp', 'hero-science-poster-2026-09.webp', 'hero-science-poster-2026-09-720.webp', 'hero-science-poster-2026-09.avif', 'hero-science-poster-2026-09-720.avif'] as $file) {
            if (!hash_update_file($context, __DIR__ . '/../assets/msca-impact/' . $file)) {
                throw new RuntimeException('MSCA asset unavailable.');
            }
        }
        $hash = substr(hash_final($context), 0, 16);
    }
    return $hash;
}
