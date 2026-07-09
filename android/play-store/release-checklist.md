# Google Play Release Checklist

## Files Ready

- [ ] Upload AAB: `android/release/psilocybin-research-tracker-v1.aab`
- [ ] Keep local test APK only if needed: `android/release/psilocybin-research-tracker-v1.apk`
- [ ] Upload feature graphic: `android/play-store/graphics/feature-graphic-1024x500.png`
- [ ] Upload phone screenshots from `android/play-store/screenshots/phone/`
- [ ] Use listing copy from `android/play-store/listing.md`
- [ ] Use Data Safety draft from `android/play-store/data-safety.md`

## Play Console Setup

- [ ] Create new app in Play Console.
- [ ] App name: `Psilocybin Research Tracker`
- [ ] Default language: English.
- [ ] App or game: App.
- [ ] Free or paid: Free.
- [ ] Category: Education.
- [ ] Privacy policy URL: `https://psilocybin-research.com/data-protection.php`
- [ ] Upload `psilocybin-research-tracker-v1.aab` to an internal testing track first.
- [ ] Complete Content Rating as educational/scientific literature reference.
- [ ] Complete Data Safety accurately.
- [ ] Declare notification permission usage as app functionality for publication alerts.

## Digital Asset Links

- [ ] `https://psilocybin-research.com/.well-known/assetlinks.json` is deployed and reachable.
- [ ] After the app is created in Play Console, copy the **App signing key certificate SHA-256 fingerprint**.
- [ ] Add that Play App Signing fingerprint to `.well-known/assetlinks.json`.
- [ ] Deploy the updated assetlinks file.
- [ ] Confirm Play Console deep link / website association checks pass.

## Policy Framing

- [ ] Store listing describes the app as bibliographic/research tooling.
- [ ] No claim that the app treats, diagnoses, prevents, or cures disease.
- [ ] No dosing, self-administration, procurement, or recreational-use guidance.
- [ ] Include “not medical advice” language in long description.

## QA Before Production

- [ ] Install internal-test build from Play.
- [ ] Confirm app opens without browser address bar. If it opens as Custom Tab with browser UI, update assetlinks with the Play App Signing fingerprint.
- [ ] Confirm navigation to publications, advanced search, citation network, authors, and offline fallback.
- [ ] Confirm push permission prompt behavior is acceptable.
- [ ] Confirm privacy/data-protection link is reachable.

