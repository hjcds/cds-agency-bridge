# Deploying and updating CDS Agency Bridge

## One-time: the repo

Create a **public** GitHub repo — `cds-agency-bridge` — containing this folder
structure:

```
cds-agency-bridge/
  cds-agency-bridge.php
release.sh
README-deployment.md
```

Public is fine and is the recommended choice. The plugin contains no secrets;
security rests entirely on per-site WordPress Application Passwords, which are
revocable individually. A private repo would require embedding an access token
in the plugin file on every client site — anyone gaining file access to any one
client site would then hold read access to your repo. That is a worse trade.

Then set the repo name in the plugin (or per site in `wp-config.php`):

```php
define( 'CDS_BRIDGE_GITHUB_REPO', 'your-org/cds-agency-bridge' );
```

## Per site, once

1. **Delete any mu-plugin copies first** — `cds-agency-bridge.php` and
   `cds-agency-bridge-archives.php` in `wp-content/mu-plugins/`. Two copies
   loading at once causes a fatal error on redeclared functions.
2. Plugins → Add New → Upload Plugin → the release zip → Activate.
3. Purge cache (LiteSpeed and opcache).
4. Confirm: `GET /wp-json/cds/v1/update-status`

From then on the site updates itself. Auto-updates are on by default; disable
on a given site with `define( 'CDS_BRIDGE_AUTOUPDATE', false );`.

## Shipping a new version

```bash
./release.sh 1.2.0 "Added taxonomy SEO routes"
```

That bumps the version in both places, zips, commits, tags, and publishes a
GitHub release with the zip attached. Every site sees it within six hours.

## Notes

- The updater reads GitHub's public releases API, which allows 60 unauthenticated
  requests per hour **per IP**. Sites are on different hosts so this is not a
  practical limit, but several sites on one shared host share an outbound IP.
  The six-hour cache and the one-hour error backoff keep this well clear.
- Tags may be `v1.2.0` or `1.2.0`; the leading `v` is stripped.
- If no `.zip` asset is attached to a release, the updater falls back to GitHub's
  zipball and renames the extracted folder so the update still applies in place.
- `GET /cds/v1/update-status` reports installed vs available version per site —
  use it to audit the portfolio rather than guessing.
