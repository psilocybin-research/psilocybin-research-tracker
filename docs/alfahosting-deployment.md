# Alfahosting connection verification

The GitHub Actions preflight in this change is deliberately **read-only**. It does not upload, synchronize, rename, delete, or modify remote files.

## Configure the protected environment

In **GitHub → repository Settings → Environments**, create an environment called `production`. Enable required reviewers and restrict the deployment branch to `main`, where supported by your GitHub plan. Validate these protections before adding credentials.

Add the following **environment secrets**, not repository files or workflow inputs:

| Name | Purpose |
| --- | --- |
| `ALFA_HOST` | Alfahosting SFTP hostname |
| `ALFA_USER` | SFTP account username |
| `ALFA_PASSWORD` | SFTP account password |
| `ALFA_HOST_KEY` | Independently verified OpenSSH known-hosts entry |
| `ALFA_DOCUMENT_ROOT` | Existing absolute website document-root path on SFTP |

Confirm the server's SSH host-key fingerprint through a trusted Alfahosting channel before configuring the known-hosts entry. Never accept an unverified key from the first connection.

## Run the check

Once this change is merged and protections are configured, use **Actions → Alfahosting connection preflight → Run workflow** on `main`. The check confirms authentication, the expected document root, and the presence of core application paths without writing anything to the server.

Production deployment is **not configured by this change**. Before enabling it, require an explicit release approval, a verified SQLite-safe backup and restore procedure, a strict deployment allowlist, a site-specific permissions review, and health checks. Never deploy runtime databases, `.env` files, keys, subscriptions, logs, private research directories, or unreviewed internal files.

See [Deployment](deployment.md) for the existing production safety requirements.
