<?php
declare(strict_types=1);

/** Frozen, aggregate-only companion to the dated research film. */
final class ResearchFilm
{
    public const PATH = 'research-in-motion/';
    public const ASSETS = 'assets/media/research-counts-2026-10-11/';
    public const MOBILE_ASSETS = 'assets/media/research-mobile-flow-2026-10-11/';
    public const SHARES = 'assets/media/research-share-counts-2026-10-11/';
    public const NAME = 'Psilocybin Research in Motion';
    public const TITLE = 'Psilocybin Research in Motion | Data Visualisation';
    public static function snapshotLabel(): string
    {
        return (new DateTimeImmutable(self::data()['snapshot_date']))->format('j F Y');
    }

    public static function description(): string
    {
        return 'Explore ' . number_format(self::data()['total']) . ' research records through an animated visualisation of sources, annual counts, topics and clinical trial registrations. Snapshot: ' . self::snapshotLabel() . '.';
    }

    public static function uploaded(): string { return self::data()['uploaded_utc']; }

    public static function data(): array
    {
        static $data = null;
        $data ??= json_decode((string)file_get_contents(dirname(__DIR__) . '/' . self::ASSETS . 'aggregates.json'), true, 512, JSON_THROW_ON_ERROR);
        return $data;
    }

    public static function url(string $path = self::PATH): string
    {
        return rtrim(Config::publicBaseUrl(), '/') . '/' . ltrim($path, '/');
    }

    public static function chapters(): array
    {
        return array_map(static fn(array $slide): array => ['name' => $slide['start'] === 0 ? 'Domain' : $slide['name'], 'startOffset' => $slide['start'], 'endOffset' => $slide['end']], self::data()['slides']);
    }

    public static function holdTime(int $start): float
    {
        foreach (self::data()['slides'] as $slide) {
            if ($slide['start'] === $start) return (float)$slide['hold'];
        }
        return (float)$start;
    }

    public static function player(bool $home = false): string
    {
        $media = self::url(self::ASSETS);
        $mobileMedia = self::url(self::MOBILE_ASSETS);
        $desktopFile = dirname(__DIR__) . '/' . self::ASSETS . 'psilocybin-research-desktop.mp4';
        $mobileFile = dirname(__DIR__) . '/' . self::MOBILE_ASSETS . 'psilocybin-research-mobile.mp4';
        $desktopBytes = is_file($desktopFile) ? (int)filesize($desktopFile) : 0;
        $mobileBytes = is_file($mobileFile) ? (int)filesize($mobileFile) : 0;
        ob_start(); ?>
        <figure class="research-film<?= $home ? ' research-film-home' : '' ?>" data-research-film>

          <dialog class="research-film-download-modal" id="film-download-confirmation" data-film-download-modal data-desktop-bytes="<?= $desktopBytes ?>" data-mobile-bytes="<?= $mobileBytes ?>" aria-labelledby="film-download-title">
            <div class="research-film-download-sheet">
              <h2 id="film-download-title">Download info video</h2>
              <p data-film-download-description>Landscape · 1920 × 1080 · 46 seconds · MP4<?= $desktopBytes > 0 ? ' · ' . number_format($desktopBytes / 1000000, 1) . ' MB' : '' ?></p>
              <p>Snapshot: <?= h(self::snapshotLabel()) ?>.</p>
              <div class="research-film-download-actions"><button type="button" class="secondary" data-film-download-cancel autofocus>Cancel</button><a class="primary" data-film-download-confirm href="<?= h($media) ?>psilocybin-research-desktop.mp4" download>Download MP4</a></div>
            </div>
          </dialog>
          <div class="research-film-surface">
            <div class="research-film-controls" role="group" aria-label="Video controls"><button type="button" class="research-film-toggle" data-film-toggle aria-label="Play research animation" hidden><i data-icon="play" aria-hidden="true"></i></button><button type="button" class="research-film-sound" data-film-sound aria-label="Enable original soundtrack" aria-pressed="false" hidden><i data-icon="volume-x" aria-hidden="true"></i></button><a class="research-film-download" data-film-download aria-haspopup="dialog" aria-controls="film-download-confirmation" href="<?= h($media) ?>psilocybin-research-desktop.mp4" download aria-label="Download info video" title="Download info video"><i data-icon="download" aria-hidden="true"></i></a><button type="button" class="research-film-fullscreen" data-film-fullscreen aria-label="Enter fullscreen" title="Enter fullscreen" hidden><i data-icon="maximize" aria-hidden="true"></i></button><button type="button" class="research-film-invert" data-film-invert aria-label="Invert video colours" title="Invert video colours" aria-pressed="false" hidden><svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.75" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 3a9 9 0 0 1 0 18z" fill="currentColor" stroke="none"/></svg></button><button type="button" class="research-film-share" data-film-share aria-label="Share video" hidden><i data-icon="share-2" aria-hidden="true"></i></button><details class="research-film-chapter-menu"><summary>Slide selection <i data-icon="chevron-down" aria-hidden="true"></i></summary><nav aria-label="Film slides">
<?php foreach (array_slice(self::chapters(), 0, -1) as $chapter): ?><a href="<?= h(self::url()) ?>#t=<?= (int)$chapter['startOffset'] ?>" data-film-time="<?= (int)$chapter['startOffset'] ?>" data-film-end="<?= (int)$chapter['endOffset'] ?>" data-film-stop="<?= h((string)self::holdTime((int)$chapter['startOffset'])) ?>"><span><?= h('0:' . str_pad((string)$chapter['startOffset'], 2, '0', STR_PAD_LEFT)) ?></span><?= h($chapter['name']) ?></a><?php endforeach; ?>
</nav></details></div>
          <div class="research-film-audio-meter" data-film-audio-meter hidden aria-hidden="true"><canvas width="344" height="12"></canvas></div>

            <span class="film-playback-progress" data-film-progress-indicator role="progressbar" aria-label="Video playback progress" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0" hidden><svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke-width="2" aria-hidden="true"><circle class="film-progress-track" cx="12" cy="12" r="10" stroke="#173e3226"/><circle class="film-progress-value" data-film-progress cx="12" cy="12" r="10" pathLength="100" stroke="#173e32"/></svg></span>
            <video muted playsinline preload="none" width="1920" height="1080" poster="<?= h($media) ?>desktop-poster.webp" data-desktop="<?= h($media) ?>psilocybin-research-desktop.mp4" data-mobile="<?= h($mobileMedia) ?>psilocybin-research-mobile.mp4" data-desktop-poster="<?= h($media) ?>desktop-poster.webp" data-mobile-poster="<?= h($media) ?>mobile-poster.webp" aria-label="Research in motion: <?= (int)self::data()['film_duration_seconds'] ?>-second data animation with optional instrumental soundtrack. Snapshot: <?= h(self::snapshotLabel()) ?>">
              <source src="<?= h($media) ?>psilocybin-research-desktop.mp4" type="video/mp4" media="(min-width: 1024px)">
              <source src="<?= h($mobileMedia) ?>psilocybin-research-mobile.mp4" type="video/mp4">
              <track kind="chapters" src="<?= h($media) ?>research-chapters.vtt" srclang="en" label="Research sequence">
            </video>

          </div>
          <div class="film-exploration">
            <details class="film-exploration-drawer" data-film-exploration>
              <summary><i data-icon="compass" aria-hidden="true"></i><span>Topics &amp; venues</span><i data-icon="chevron-down" aria-hidden="true"></i></summary>
              <div class="film-exploration-content">
                <div class="film-topic-browser" id="topic-counts">
                  <h2>Browse research topics</h2>
                  <ul class="film-topic-links">
<?php foreach (self::data()['topics'] as $topic): ?><li><a href="<?= h(self::url('?topic=' . rawurlencode($topic['name']) . '#papers')) ?>"><span><?= h($topic['name']) ?></span><strong><?= h(number_format($topic['count'])) ?></strong></a></li><?php endforeach; ?>
                  </ul>
                  <p class="film-exploration-note">N.B.: Derived screening tags overlap. A record can appear in more than one topic; the counts must not be summed.</p>
                </div>
                <div class="film-venue-browser" id="venue-directory">
                  <h2>Find a journal or venue</h2>
                  <label class="sr-only" for="film-venue-search">Filter venue names</label>
                  <input type="search" id="film-venue-search" data-film-venue-search placeholder="Search a journal or repository…" aria-controls="film-venue-list" aria-describedby="film-venue-description">
                  <p data-film-venue-count role="status"><?= h(number_format(count(self::data()['venue_labels']))) ?> venue labels</p>
                  <ul class="research-film-venues" id="film-venue-list" tabindex="0" aria-label="Stored venue labels">
<?php foreach (self::data()['venue_labels'] as $venue): ?><li><a href="<?= h(self::url('?journal=' . rawurlencode($venue['name']) . '#papers')) ?>"><?= h($venue['name']) ?></a><span><?= h(number_format($venue['count'])) ?></span></li><?php endforeach; ?>
                  </ul>
                  <p class="film-exploration-note" id="film-venue-description">Stored labels include journals, repositories, a trial registry and name variants; they are not unique journals. The homepage’s journal-label count excludes preprint and trial-registry venues.</p>
                </div>
                <p class="film-exploration-caption">Snapshot counts; links open the current catalogue.</p>
              </div>
            </details>
            <a class="film-trial-map" href="<?= h(self::url('trials-map.php')) ?>"><i data-icon="globe" aria-hidden="true"></i><span>Trial map</span></a>
          </div>
          <p class="research-film-status" role="status"></p>
          <noscript><p>Read the data or <a href="<?= h($media) ?>psilocybin-research-desktop.mp4">open the MP4 film</a>.</p></noscript>
        </figure>
        <?php return (string)ob_get_clean();
    }
}
