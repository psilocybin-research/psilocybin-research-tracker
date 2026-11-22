<?php
declare(strict_types=1);

final class PushPreferencesView
{
    public static function render(array $journals, array $topics): string
    {
        ob_start();
        ?>
    <dialog class="alert-modal push-preferences-modal" id="push-preferences" aria-labelledby="push-preferences-title">
      <form class="alert-form alert-sheet" id="push-preferences-form">
        <div class="advanced-filter-head">
          <div>
            <h2 id="push-preferences-title">Push notification preferences</h2>
            <p>Get notifications on this device when new research matches your interests. Choose your coverage and frequency below.</p>
          </div>
          <button type="button" class="advanced-filter-close" data-close-push-preferences aria-label="Close push notification preferences"><i data-icon="x" aria-hidden="true"></i></button>
        </div>
        <div class="push-status-panel">
          <p class="notification-status" data-push-summary role="status" aria-live="polite">Checking push status…</p>
          <button class="secondary" type="button" data-push-test disabled>Send a test notification</button>
          <p class="push-feedback" data-push-feedback role="status" aria-live="polite"></p>
        </div>
        <fieldset class="alert-mode"><legend>Alert coverage</legend>
          <label><input type="radio" name="push_scope" value="all" checked> Any new psilocybin / psilocin publication</label>
          <label><input type="radio" name="push_scope" value="targeted"> Only publications matching selected filters</label>
        </fieldset>
        <fieldset class="frequency-options"><legend>Digest frequency</legend>
          <label><input type="radio" name="push_frequency" value="daily" checked> Daily</label>
          <label><input type="radio" name="push_frequency" value="weekly"> Weekly</label>
          <label><input type="radio" name="push_frequency" value="monthly"> Monthly</label>
        </fieldset>
        <fieldset><legend>Substances</legend>
          <label><input type="checkbox" name="push_substances[]" value="psilocybin" checked> Psilocybin</label>
          <label><input type="checkbox" name="push_substances[]" value="psilocin" checked> Psilocin</label>
        </fieldset>
        <details class="alert-targeting">
          <summary>Research focus filters</summary>
          <div class="accordion-body">
            <label class="field"><span>Keywords</span><input type="text" name="push_keywords" placeholder="e.g. depression, microdosing, OCD"></label>
            <label class="field"><span>Author</span><input type="text" name="push_author" placeholder="e.g. Carhart-Harris"></label>
            <?= AlertFilters::picker('push_journal', 'Journals', $journals) ?>
            <?= AlertFilters::picker('push_topic', 'Topics', $topics) ?>
            <label class="field"><span>Cites DOI</span><input type="text" name="push_cited_doi" placeholder="10.1038/..." inputmode="url"></label>
            <p class="field-note">Citation notifications match new publications whose available reference metadata contains this DOI.</p>
          </div>
        </details>
        <details class="push-device-settings">
          <summary>Delivery and device check</summary>
          <div class="accordion-body">
            <fieldset><legend>On this device</legend>
              <label><input type="checkbox" name="push_sound" checked> Allow notification sound</label>
              <label><input type="checkbox" name="push_vibration" checked> Allow notification vibration</label>
              <label><input type="checkbox" name="push_badge" checked> Show a badge for unseen research updates</label>
              <label><input type="checkbox" name="push_haptics" checked> Gentle touch feedback in the app</label>
            </fieldset>
            <p class="field-note">Quiet delivery disables sound and vibration. Your phone controls banners, lock-screen visibility, sound and Focus / Do Not Disturb. Browser support varies. Changes here apply after saving.</p>
            <dl class="push-device-status" data-push-device-status></dl>
            <button class="secondary" type="button" data-push-read>Mark research updates as viewed</button>
          </div>
        </details>
        <details class="push-device-settings" data-push-history><summary>Received on this device</summary><div class="accordion-body" data-push-history-list>No received notifications recorded yet.</div></details>
        <p class="privacy-note compact">Your browser’s push endpoint and encryption keys are stored securely for delivery. Selected research filters match the notifications you requested. Device delivery choices stay in this browser. <a href="data-protection.php" target="_blank" rel="noopener">Data protection notice</a>.</p>
        <div class="alert-review"><i data-icon="calendar-clock" aria-hidden="true"></i><div><strong>Notification summary</strong><span data-push-review role="status" aria-live="polite"></span></div></div>

        <div class="advanced-filter-actions">
          <button class="secondary" type="button" data-close-push-preferences>Cancel</button>
          <button class="secondary push-unsubscribe" type="button" data-push-unsubscribe hidden>Stop push</button>
          <button class="primary iconed" type="submit"><i data-icon="bell-plus" aria-hidden="true"></i><span>Save push preferences</span></button>
        </div>
      </form>
    </dialog>

        <?php
        return (string)ob_get_clean();
    }
}
