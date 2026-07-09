# Google Play Data Safety Draft

Use this as the working draft for Play Console. Verify against the current app behavior before submission.

## Data Collection Summary

The Android app is a Trusted Web Activity wrapper for `https://psilocybin-research.com/`. The web app can be used without creating an account.

### Data Types Collected

#### Personal info

- Email address
  - Collected only if the user subscribes to email alerts.
  - Purpose: app functionality, specifically alert subscription delivery and preference management.
  - Shared with third parties: no, except normal email delivery infrastructure used to send the message.
  - Optional: yes.

#### App activity

- Search/filter requests and visited URLs may appear in normal web server access logs.
  - Purpose: app functionality, security, diagnostics, abuse prevention.
  - Shared with third parties: no, except hosting/infrastructure processing.
  - Optional: the public app necessarily receives requests to serve pages and results.

#### Device or other IDs

- Web Push subscription endpoint and related push keys are stored only if the user enables push notifications.
  - Purpose: app functionality, specifically new-publication notifications.
  - Shared with third parties: push delivery necessarily uses browser/vendor push infrastructure.
  - Optional: yes.

## Security Practices

- Data is transmitted over HTTPS.
- The app does not use advertising SDKs.
- The app does not sell user data.
- The app does not include tracking pixels in alert emails.
- Alert management and unsubscribe links are available through token-based public preference pages.

## Account Deletion / Data Deletion

There is no general user account system.

For alert subscriptions, users can:

- Unsubscribe through the one-click unsubscribe/manage link in alert emails.
- Manage alert preferences through the token-based alert management page.
- Contact the operator using the privacy/data-protection contact route listed at `https://psilocybin-research.com/data-protection.php`.

## Play Console Wording Suggestions

When asked whether data is collected:

- Answer yes, because email alert subscriptions and push subscriptions collect user-provided/contact or device-related data when enabled.

When asked whether data is required:

- Most collected data is optional. Core publication browsing does not require email or push subscription.

When asked whether data is encrypted in transit:

- Answer yes.

When asked whether users can request deletion:

- Answer yes for alert subscription data via unsubscribe/manage/contact route.

## Important Caveat

If future analytics, crash reporting, ads, account login, payment, or third-party SDKs are added to the Android project or web app, this Data Safety draft must be updated before a Play release.

