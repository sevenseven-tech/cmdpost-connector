=== CmdPost Connector ===
Tags: monitoring, site management, updates, maintenance
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 0.6.3

Connect a WordPress site to CmdPost to monitor installed versions and apply individually approved updates.

== Description ==

CmdPost Connector connects a single-site WordPress installation to [CmdPost](https://cmdpost.sevenseven.tech/). A CmdPost account and pairing code are required. Once paired, the connector reports installed versions and available updates so you can review them in CmdPost.

A WordPress administrator can enable remote updates separately for plugins, themes, and WordPress core patches. Each update requires individual approval in CmdPost. The connector checks the installed version and the package WordPress offers before applying the update. Remote core updates are limited to patches in the installed release series that leave the database schema unchanged.

== Installation ==

1. Install and activate CmdPost Connector on a single-site WordPress installation.
2. In CmdPost, open the site and generate a WordPress pairing code.
3. In WordPress, go to **Plugins > Installed Plugins** and select **Settings** under CmdPost Connector. You can also open **Settings > CmdPost Connector**.
4. Enter the HTTPS CmdPost URL and the pairing code. The site URL in CmdPost must match the WordPress Site Address.
5. Review the site’s WordPress inventory in CmdPost. To send a fresh report, select **Send status now** in the connector settings.

== Frequently Asked Questions ==

= Can I use the connector for monitoring only? =

Yes. Pair the installation and leave remote updates disabled in WordPress.

= Where can I see queued and completed updates? =

Open the site’s **Remote WordPress updates** panel in CmdPost. When remote updates are enabled, the connector checks for work every five minutes when WP-Cron runs.

= How do I disconnect a site? =

Use **Disconnect WordPress** in CmdPost to revoke the connector credential and remove the latest inventory. Clear the saved connection in the connector settings in WordPress before pairing again.

== External service ==

The plugin contacts the HTTPS CmdPost instance you enter when you pair the site. Pairing sends the site address and a one-time code. If the site still needs ownership verification, CmdPost checks a temporary proof at the site’s public HTTPS address.

After pairing, reports include the site address, WordPress and PHP versions, the WordPress admin URL used for core verification, and plugin and theme identifiers, names, versions, active status, available updates, and update-check timestamps. WordPress update offer metadata includes whether a package is available. Reports run hourly when WP-Cron runs, and you can also send one manually.

When an administrator enables remote updates, the connector sends the enabled permission to CmdPost, checks for approved jobs, and reports job outcomes and observed versions. CmdPost’s remote queue uses packages offered by the WordPress updater, including existing plugin and theme update providers where applicable.

See the [CmdPost privacy policy](https://cmdpost.sevenseven.tech/privacy) for information about data use, retention, and deletion.

== Changelog ==

= 0.6.3 =
* Updated update-permission colors and the clear connection hover state.

= 0.6.2 =
* Updated the connector dashboard with CmdPost branding and clearer connection and update permission settings.

= 0.6.1 =
* Added a Settings shortcut to the CmdPost Connector row in Installed Plugins.

= 0.6.0 =
* Added information about background update availability and package availability to reports.
* Added update diagnostics for download, package validation, and installation failures.
