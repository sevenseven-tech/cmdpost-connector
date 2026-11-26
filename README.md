# CmdPost Connector

CmdPost Connector reports WordPress and PHP versions, installed plugins and
themes, and available updates from a single-site WordPress installation to
CmdPost. It reads the same update offers WordPress uses in its dashboard,
including offers from vendors that integrate with the WordPress updater.

The plugin reports update availability and can apply individually approved
plugin updates, theme updates, and core patches when a WordPress administrator opts in.

## Build an installable ZIP

WordPress 6.5 or later and PHP 7.4 or later are required to install the plugin.
Run these commands from the parent directory that contains `cmdpost-connector/`.

```sh
mkdir -p dist
zip -X dist/cmdpost-connector.zip cmdpost-connector/*.php cmdpost-connector/readme.txt cmdpost-connector/LICENSE cmdpost-connector/assets/*
```

The ZIP should contain one top-level directory, `cmdpost-connector/`. The plugin
has no build dependencies or generated code.

## Pair a site

1. In WordPress admin, upload the ZIP through **Plugins → Add New Plugin →
   Upload Plugin**, then activate it.
2. Open the site’s detail page in CmdPost and select **Connect WordPress** or
   **Generate pairing code**. The code is single-use and expires after 15 minutes.
3. In WordPress, open **Settings → CmdPost Connector**. Paste the CmdPost URL and
   code, then select **Connect to CmdPost**. The monitored URL in CmdPost must
   match the WordPress Site Address, including any subdirectory.
4. On unverified sites, CmdPost checks a temporary challenge at the site’s
   public HTTPS address. If CmdPost cannot reach it, use one of the manual
   verification methods in CmdPost first.
5. Check the WordPress panel in CmdPost for the report. **Send status now** is
   available in WordPress for a manual check-in.

CmdPost must be reachable from WordPress over HTTPS. Reports run hourly through
WP-Cron. For low-traffic sites, configure a system cron to trigger WP-Cron.
CmdPost labels the connection stale after three hours without a report and
falls back to public estimates when available. Reports include the timestamps
of the last WordPress update checks. Opening **Dashboard → Updates** in
WordPress can help when update data is missing or old.

Use **Disconnect WordPress** in CmdPost to revoke the credential and remove the
last inventory. To reconnect, clear the saved connection in WordPress and
generate a new pairing code. Deactivating the plugin stops scheduled reports.
Deleting it removes local configuration.

## Run an approved plugin or theme update

1. Open **Settings → CmdPost Connector** and enable remote plugin updates,
   remote theme updates, or both.
2. In CmdPost, select **Update** next to a plugin or theme in the installed inventory.
   Confirm the versions and make sure you have a backup or restore point.
3. Let WP-Cron run the `cmdpost_connector_check_updates` event. The connector
   checks for jobs every five minutes. The **Remote WordPress updates** panel shows the
   outcome and the new external reachability check automatically.

The connector checks the installed version, the current update offer, and the
unpacked package before WordPress replaces files. Remote updating supports
standard folder-based plugins and themes with matching package layouts, direct
background file access, and a single-site WordPress installation. Theme packages must
retain the installed theme’s parent relationship. Update the connector itself through
WordPress admin.

Connector 0.6.0 reports whether WordPress has disabled background updates or
offered a component version without a download package. If a job fails, CmdPost
shows the detected step in **Remote WordPress updates**, such as a rejected
download or an incompatible package. After fixing the issue, click **Retry**
when the same offer is available, then confirm the update again.

Disabling a type of remote update in WordPress prevents new jobs of that type from running.
If a job has already started, check the outcome in CmdPost. An update without a
reported outcome blocks new requests until the owner checks the site and
acknowledges it.

## Run an approved core patch

1. Test a restore of both WordPress files and the database on staging. Prepare a
   current backup before approving the patch.
2. Open **Settings → CmdPost Connector → Enable remote core patches**. Check
   **Dashboard → Updates**, then send a fresh status report from CmdPost Connector.
3. In CmdPost, choose **Update WordPress core** and approve the offered patch.
   A patch must be within the installed version series, come from WordPress’s
   official update service, and leave the database schema unchanged.
4. Check **Remote WordPress updates** for the observed version and site and admin
   reachability results. The connector confirms the version in a new WordPress
   request after the upgrade. Unconfirmed results hold the queue for review.

Core patches require a publicly reachable HTTPS site and a WordPress admin URL
on the same origin. Keep backup and restore operations with your hosting tools.

## CmdPost API

These routes are HTTPS POST requests with `Content-Type: application/json`.

- `/api/wordpress/v1/pair` accepts the pairing `code` and WordPress `siteUrl`.
  A verified site receives its site-scoped `token` immediately. For an unverified
  site, CmdPost returns a short-lived challenge. The plugin publishes the proof
  and repeats the request with `verify: true` so CmdPost can check the site.
- `/api/wordpress/v1/report` accepts an inventory with an
  `Authorization: Bearer <token>` header. WordPress update-check times are Unix
  seconds or null. The connector sends component identifiers, names, versions,
  and active status. Credentials for vendor update services, download URLs, and
  site content are omitted.
- The bearer-authenticated `/api/wordpress/v1/updates/` endpoints register
  WordPress opt-in (`permission` for plugins, `theme-permission` for themes, and
  `core-permission` for core patches),
  claim a single owner-approved update (`claim`), and report the outcome and
  installed version (`result`). Failures may include a fixed diagnostic code.

The folder can move to its own repository as-is. Keep the versioned API
contract in sync with the CmdPost app when releasing a connector update.

## GitHub releases

Run `bash scripts/package.sh` from this repository to create
`/tmp/cmdpost-connector.zip` (or pass an output file path). The package contains
only plugin PHP files, assets, readme, and license under `cmdpost-connector/`.
Requires Python 3.

Attach `cmdpost-connector.zip` to each stable GitHub release and mark that
release as latest. Keep the asset filename unchanged so this app download
link always resolves to the latest published package:

https://github.com/sevenseven-tech/cmdpost-connector/releases/latest/download/cmdpost-connector.zip
