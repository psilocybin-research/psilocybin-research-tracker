# Controlled Alfahosting release

This is an **explicitly requested** SFTP workflow (manual GitHub Actions dispatch or commit-pinned owner PR comment). It is not activated until its pull requests are reviewed and merged, the production environment is protected, and environment secrets are configured.

## Protection requirements

In **Settings → Environments → production**, enable required reviewers and restrict use to the protected `main` branch. GitHub must actually enforce these protections for the repository plan. Confirm this manually before configuring secrets. Never place production credentials in this repository, chat, issue comments, or workflow inputs.

Configure these **environment secrets**:

| Secret | Meaning |
| --- | --- |
| `ALFA_HOST` | Alfahosting SFTP hostname |
| `ALFA_USER` | Alfahosting SFTP username |
| `ALFA_PRIVATE_KEY` | Dedicated Ed25519 deployment private key (preferred; never use your personal key) |
| `ALFA_PRIVATE_KEY_PASSPHRASE` | Optional passphrase for that dedicated private key |
| `ALFA_PASSWORD` | Password, only if explicitly choosing the locked password method |
| `ALFA_ALLOW_PASSWORD_AUTH` | Optional explicit password method unlock, exactly `yes` |
| `ALFA_HOST_KEY` | Independently verified OpenSSH known-hosts entry |
| `ALFA_DOCUMENT_ROOT` | Existing SFTP document root for the domain |
| `ALFA_PRIVATE_RELEASE_ROOT` | Existing writable directory **outside** the web document root for retained code backups |
| `ALFA_PRODUCTION_DEPLOY_ENABLED` | Optional lock: set to exactly `yes` only after review, preflight, and backup drills |

Verify the host key fingerprint using an independent Alfahosting channel. Do not accept an unverified first-use fingerprint. Use a dedicated, narrowly scoped deployment account if the hosting plan permits it; otherwise recognize that the main SFTP login is highly privileged.


Default to `ALFA_AUTH_METHOD=key` (a GitHub **environment variable**, or leave unset). Supply `ALFA_PRIVATE_KEY` and optionally its passphrase; do not configure `ALFA_PASSWORD` simultaneously. The dedicated Ed25519 private key is parsed only in memory. Agent and local-key discovery are disabled. The server key is checked against the exact SSH hostname with Paramiko's reject-unknown-host policy.

**Password alternative:** Only when key enrollment is unavailable, set the environment variable `ALFA_AUTH_METHOD=password`, set the environment secrets `ALFA_PASSWORD` and `ALFA_ALLOW_PASSWORD_AUTH=yes`, and leave `ALFA_PRIVATE_KEY` unset. A failed key attempt cannot silently fall back to password login.

The existing local user's successful key-only SSH command demonstrates that public-key login works for that *local* account, but does not independently authenticate the server's pinned host key. Before storing `ALFA_HOST_KEY`, obtain independent verification of the precise SSH fingerprint. Do not use FileZilla TLS certificate fingerprints in its place.

The SFTP path `ALFA_PRIVATE_RELEASE_ROOT` must exist before the deploy run. It must not equal, contain, or be contained by the web document root. Use private directory permissions. Ensure available disk quota and inodes for the changed-file snapshots.

## Review and release sequence

1. Review both the read-only connection-preflight pull request and this release-workflow pull request.
2. Run the established PHP tests, generated-asset checks, and newly added manifest tests. Confirm the GitHub Actions `CI` result is green.
3. Enable the protected GitHub environment and configure only the verified environment secrets.
4. Run **Alfahosting connection preflight** manually from `main`. It only reads remote files.
5. Run **Alfahosting controlled release** with mode `plan` to check the allowlist locally.
6. Run the same workflow in `verify` mode to compare the release manifest with the live website, without writing anything. Review the list and count of files that differ.
7. Create and **restore-test** a recent SQLite-consistent database backup using the procedures in [Operations](operations.md). Store the backup outside the web root. Independently back up private server configuration, scheduled jobs, and site-only sections.
8. Only after the correct code version and paths are verified, launch `deploy`, enter the literal `DEPLOY`, check `backup_confirmed`, and satisfy GitHub environment approval. The environment secret `ALFA_PRODUCTION_DEPLOY_ENABLED` must also be `yes`.
9. Check the application, research catalogue, citation explorer, exports, alerts and update cron, as well as the automated homepage and health endpoint smoke checks.

The workflow never sends `data/`, `.env`, secrets, logs, private sections, Git metadata, tests, or local documentation. The public `config/` directory is denied by `config/.htaccess`. Only explicitly allowed tracked site files are eligible. It does not delete unrelated production files. Thus removed or renamed application paths require a separate, manually reviewed cleanup.

## Recovery boundaries

The deployment stages changed files and copies old versions to a private release directory, verifies backup file hashes, replaces files through the server's SFTP POSIX rename extension, and checks two HTTPS endpoints. If the update fails, it attempts to restore previously replaced files.

**This is not an atomic multi-file deployment**, and automated rollback is best-effort: a network failure, disk exhaustion, file-locking conflict, or server crash can interrupt recovery. Preserved snapshots can help an administrator restore files, but the workflow does not restore or migrate SQLite automatically. Do not deploy breaking schema changes or destructive migrations through this workflow without a separate tested plan. No production database or private runtime state is altered or backed up by the SFTP release helper.

The workflow refuses to proceed if the site markers are unexpected, paths overlap, the host key cannot be verified, or mandatory approval conditions are absent. Avoid enabling `ALFA_PRODUCTION_DEPLOY_ENABLED` until you've confirmed that the verified comparison matches your intended release.


## Release requests from ChatGPT via GitHub comments

After both deployment pull requests are reviewed, merged into `main`, and the protected environment is configured, the connected GitHub integration can submit a **top-level comment on PR #38**. The GitHub Actions workflow on `main` recognizes exactly these commands:

`/release verify <40-character-current-main-SHA>`

`/release deploy <40-character-current-main-SHA>`

Only comments created on PR #38 by repository owner `psilocybin-research` are considered. The authorization job rejects nonmatching commands, stale SHA values, and commenters without owner association. The deployment job checks that `main` still points to the approved SHA after the environment-review hold. It then runs tests and proceeds to read-only verification or deployment.

**Deployment has two additional independent locks:**

- The GitHub protected `production` environment must approve the job. A GitHub account permitted to approve must be configured, and bypass must be disabled.
- The environment secret `ALFA_BACKUP_ATTESTED_SHA` must equal the requested commit SHA. Set it *only after* confirming a fresh SQLite-safe backup has actually been created and restore-tested. This value must be updated for each distinct release commit. The normal `ALFA_PRODUCTION_DEPLOY_ENABLED` secret must also be exactly `yes`.

A comment alone cannot publish without all required protections. **Do not add secrets to comments.** This workflow does not enable releases by itself: it is part of a draft PR and is inactive until reviewed and merged to the default branch.

The GitHub integration can add comments to PRs, but does not expose a direct Actions `workflow_dispatch` invocation. Use only PR #38 for this automated trigger; the separate issue titled "Release control" has no active release trigger.
