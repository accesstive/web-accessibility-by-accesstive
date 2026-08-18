=== Accesstive App ===
Contributors: accesstive
Tags: accessibility, a11y, widget, admin, accesstive
Requires at least: 5.8
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Connect WordPress to Accesstive — manage accessibility from your admin and install the visitor toolkit in one click.

== Description ==

**Accesstive App** brings the Accesstive dashboard into WordPress admin so site owners can set up accessibility tools without leaving wp-admin.

= Features =

* Accesstive admin UI embedded in WordPress (Accesstive App menu).
* One-click **Auto install** of the Accesstive toolkit on your public site.
* Manual install option with a copy-paste script tag.
* Token verification against the Accesstive API before saving.
* Frontend injection of `assistance.js` from cdn.accesstive.com after install.

= Who is this for? =

Administrators who use [Accesstive](https://accesstive.com/) and want a native WordPress setup flow for the accessibility widget.

= External services =

This plugin connects to Accesstive services when an **administrator** opens the Accesstive App screen or installs the toolkit:

* [app.accesstive.com](https://app.accesstive.com/) — admin UI assets (HTML/CSS/JS), allowlisted and cached on your server.
* [dashboard.accesstive.com](https://dashboard.accesstive.com/) — token verification API.
* [cdn.accesstive.com](https://cdn.accesstive.com/) — public `assistance.js` widget (frontend only, after install).
* fonts.googleapis.com / fonts.gstatic.com — fonts referenced by the Accesstive UI.

Anonymous visitors do not load Accesstive admin UI assets. The public site only loads the widget script after an admin installs a verified token.

Service terms and privacy:

* [Terms of Service](https://accesstive.com/terms/)
* [Privacy Policy](https://accesstive.com/privacy-policy/)
* [Trust Center](https://accesstive.com/trust-center/)

== Installation ==

1. Upload the `web-accessibility-by-accesstive` folder to `/wp-content/plugins/` or install the plugin through the WordPress plugins screen.
2. Activate **Accesstive App** through the **Plugins** screen.
3. Go to **Accesstive App** in the admin menu.
4. Sign in with your Accesstive account and follow the setup steps.
5. Use **Auto install** to add the toolkit to your site, or copy the manual script if you prefer.

= Requirements =

* A WordPress site with outbound HTTPS access to Accesstive servers.
* An Accesstive account.
* `manage_options` capability (administrator) to open the app and install the toolkit.

== Frequently Asked Questions ==

= Do I need an Accesstive account? =

Yes. The embedded admin UI is the Accesstive web application. Create an account at [accesstive.com](https://accesstive.com/) if you do not have one.

= What data does WordPress send to Accesstive? =

When you open Accesstive App, your server fetches UI assets from app.accesstive.com. During setup, your site URL is passed so Accesstive can associate the install with your site. When you auto-install the toolkit, a widget token is verified with dashboard.accesstive.com and stored locally in WordPress options.

= Auto install says "Host did not respond in time" — what should I do? =

Hard-refresh the Accesstive App page, wait for the UI to finish loading, then try again. If it persists, use **Manual** install or check that JavaScript is not blocked in wp-admin.

== Changelog ==

= 1.0.0 =
* Initial release.
* Accesstive admin UI embedded in WordPress admin via secure AJAX loader.
* One-click toolkit install with capability checks, nonces, and API token verification.
* Frontend widget injection from stored token with CDN host allowlist.
