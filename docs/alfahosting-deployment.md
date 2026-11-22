# Alfahosting connection verification

The GitHub Actions preflight in this change is deliberately **read-only**. It does not upload, synchronize, rename, delete, or modify remote files.

## Configure the protected environment

In **GitHub → repository Settings → Environments**, create an environment called `production`. Enable required reviewers and restrict the deployment branch to `main`, where supported by your GitHub plan. Validate these protections before adding credentials.

Use a **new, dedicated Ed25519 deployment key** by default, not the existing local personal SSH key. Install the new public key on the hosting account only if Alfahosting confirms that key enrollment is supported. Never paste the private key into a chat or commit it.

Configure these GitHub **environment secrets**, not repository files:

| Name | Purpose |
| --- | --- |
| `ALFA_HOST` | Verified SFTP hostname |
| `ALFA_USER` | SFTP account username |
| `ALFA_HOST_KEY` | Independently verified OpenSSH known-hosts entry for the exact SFTP hostname |
| `ALFA_DOCUMENT_ROOT` | Existing absolute document-root path |
| `ALFA_PRIVATE_KEY` | Dedicated Ed25519 **private** key; store only in protected environment secrets |
| `ALFA_PRIVATE_KEY_PASSPHRASE` | Optional passphrase for the dedicated deployment key |

The default authentication method is `key`. Leave the environment variable `ALFA_AUTH_METHOD` unset or set it to `key`. The private key is parsed in memory and is not written to a temporary file. SSH agent and local-key discovery are disabled. Unknown server host keys are rejected.

**Explicit password alternative:** If Alfahosting does not permit dedicated SSH keys, set the production environment *variable* `ALFA_AUTH_METHOD=password`, and separately set the *environment secrets* `ALFA_PASSWORD` and `ALFA_ALLOW_PASSWORD_AUTH=yes`. Leave `ALFA_PRIVATE_KEY` and its passphrase unset. The workflow does not silently fall back from a failed key to a password. Review this choice before adding a password.

The SSH key already saved in a local `known_hosts` file confirms previous acceptance, **not independent host identity verification**. Compare the exact host-key fingerprint with an independently trusted Alfahosting source before copying its full public key into GitHub's `ALFA_HOST_KEY` secret. A FileZilla TLS certificate fingerprint is *not* an SSH host-key fingerprint.

## Run the check

Once this change is merged and protections are configured, use **Actions → Alfahosting connection preflight → Run workflow** on `main`. The check confirms authentication, the expected document root, and the presence of core application paths without writing anything to the server.

Production deployment is **not configured by this change**. Before enabling it, require an explicit release approval, a verified SQLite-safe backup and restore procedure, a strict deployment allowlist, a site-specific permissions review, and health checks. Never deploy runtime databases, `.env` files, keys, subscriptions, logs, private research directories, or unreviewed internal files.

See [Deployment](deployment.md) for the existing production safety requirements.
