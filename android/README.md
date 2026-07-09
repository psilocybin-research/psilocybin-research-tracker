# Psilocybin Research Tracker Android Release

This directory contains the Android Trusted Web Activity package for publishing the PWA on Google Play.

## App Identity

- App name: Psilocybin Research Tracker
- Launcher label: PsiloTrack
- Package name: `com.psilocybinresearch.tracker`
- Web origin: `https://psilocybin-research.com/`
- Start URL: `https://psilocybin-research.com/?source=pwa`
- Version name: `1`
- Version code: `1`
- Min SDK: 21
- Target SDK: 35
- Compile SDK: 36

## Release Artifacts

Upload this file to Google Play:

- `android/release/psilocybin-research-tracker-v1.aab`

For local device testing only:

- `android/release/psilocybin-research-tracker-v1.apk`

Checksums:

- `android/release/psilocybin-research-tracker-v1.aab.sha256`
- `android/release/psilocybin-research-tracker-v1.apk.sha256`

## Signing

The release is signed with the local upload keystore:

- Keystore: `android/keystore/psilocybin-research-upload.jks`
- Signing properties: `android/keystore/signing.properties`
- Alias: `upload`

The keystore and signing properties are intentionally ignored by `android/.gitignore`. Back them up securely. Losing the upload key can block future updates unless Google Play upload-key reset is used.

Current upload certificate SHA-256:

`EB:3A:1D:CF:22:3E:B8:2B:2E:3D:23:90:94:6F:A6:E1:2F:8B:A9:E4:6F:13:FB:E7:C9:E3:F2:2C:DB:FD:12:B4`

## Digital Asset Links

The site-side association file is:

- `.well-known/assetlinks.json`

It currently contains the local upload certificate fingerprint. For Google Play installs with Play App Signing, open Play Console after the app is created and copy the **App signing key certificate SHA-256 fingerprint**. Add that fingerprint to `.well-known/assetlinks.json` as well, then deploy. The upload-key fingerprint is enough for locally installed APKs signed by this keystore, but Play-distributed builds are normally re-signed by Google.

## Rebuild

Prerequisites installed on this workstation:

- JDK 17
- Android command-line SDK at `~/.android-sdk`
- Android platform 35 and 36
- Build tools 35.0.0

Run:

```bash
cd /home/user/Desktop/christopher-germann.de/live-site/psilocybin-research.com/publication-tracker
bash android/build-release.sh
```

## Store Assets

Use the prepared Play Console assets in:

- `android/play-store/listing.md`
- `android/play-store/data-safety.md`
- `android/play-store/release-checklist.md`
- `android/play-store/screenshots/phone/`
- `android/play-store/graphics/feature-graphic-1024x500.png`

