=== NIBWOZ Better Img ===
Contributors: brinsleyparagao
Tags: image optimization, webp, avif, compress images, media library
Requires at least: 6.4
Tested up to: 7.1
Requires PHP: 8.3
Stable tag: 1.3.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Non-destructive WebP and AVIF optimization for the Media Library: originals stay intact, copies are validated and delivery is optional.

== Description ==

NIBWOZ Better Img creates optimized copies of your JPEG, PNG, WebP and AVIF images without ever touching the original files or the Media Library metadata. Each copy is written to a temporary file, checked (MIME type, dimensions and SHA-256) and only then published, so a failed conversion never leaves a broken image behind.

**Main features**

* WebP by default, with AVIF or "keep original format" (recompression) as alternatives.
* Separate quality per mode, optional maximum width and height with proportional resizing, and a rule for copies that come out larger than the source.
* Automatic optimization of new uploads, a per-image button in the Media Library, a bulk action and a manual "optimize the whole library" action.
* Persistent background queue with 1 to 4 images processed at the same time, a live progress monitor and a safe stop button.
* Optional public delivery: when turned on, WordPress image URLs, `srcset` included, point to the optimized copy that exists; everything else falls back to the original. Off by default, no `.htaccess` rules.
* Works with pull CDNs that keep the image path, such as the Bunny CDN plugin, and can recognize CDN domains that rewrite image addresses.
* Diagnostics of GD and Imagick based on a real encoding test, plus a history of recent processing rounds.
* Verified cleanup: delete every optimized copy at any time, and optionally all plugin data on uninstall.

Optimized copies are stored in `wp-content/uploads/nwz-better-img/`, mirroring the year/month structure of your uploads.

= Privacy and external requests =

NIBWOZ Better Img does not collect personal data, does not use cookies and sends no telemetry. It makes no requests to external services.

The only HTTP requests it makes are loopback requests from your server to its own `wp-admin/admin-post.php`, used to start the background workers. Each request carries a short-lived signature generated with your site's salts and contains no personal data.

The About screen links to the NIBWOZ website, knowledge base and privacy policy; these links only open when you click them.

== Installation ==

1. Upload the plugin through Plugins > Add New > Upload Plugin, or copy the `nibwoz-better-img` folder to `wp-content/plugins/`.
2. Activate it from the Plugins screen.
3. Open Tools > NWZ Better Img and check the Diagnostics screen.
4. Review the Optimization settings and save them.
5. On the Processing screen, click "Optimize the whole library" to convert existing images. New uploads are optimized automatically.
6. When you are happy with the result, turn on "Show optimized images to visitors" and clear your page and CDN caches.

== Frequently Asked Questions ==

= Are my original images changed? =

No. Originals and their metadata are never modified, resized or deleted by the plugin. Optimized copies live in a separate folder.

= Do visitors see the optimized images right away? =

Only after you turn on "Show optimized images to visitors". Until then the plugin only creates and validates the copies.

= Which image engine is used? =

The native WordPress engines, GD and Imagick. Automatic mode picks the engine that passed a real encoding test for the chosen format on your server.

= How do I undo everything? =

Turn off delivery, then use "Delete all optimized images" on the Processing screen. To also remove the plugin's settings and tables, turn on "Delete all data on uninstall" on the About screen before deleting the plugin.

= Does it work with page caches and CDNs? =

Yes. After turning delivery on or off, clear the page cache and the CDN cache. Each optimized URL carries a version parameter (`?ver=`) that changes whenever the copy changes.

= Is it translated? =

The plugin ships with a Brazilian Portuguese (pt_BR) translation and a POT file for other languages.

== Changelog ==

= 1.3.0 =
* Interface: updated to NIBWOZ UI 1.2.0. Confirmations (settings saved, images queued, queue stopped and others) appear as a short message in the center of the screen that fades out; errors stay as fixed notices. Busy buttons show a spinning update icon.
* The result parameters are removed from the address after display, so reloading the page does not repeat the message.

= 1.2.1 =
* Plugin Check: documented the remaining direct writes to the plugin's own table and the use of the core `https_local_ssl_verify` filter. No functional changes.

= 1.2.0 =
* Prepared for the WordPress.org plugin directory: English source strings, bundled pt_BR translation and POT file, GPLv2 license file.
* New interface based on the NIBWOZ UI kit 1.1.0, with a single Save button in the sidebar and an unsaved-changes indicator.
* Database queries use prepared identifiers, file operations use the WordPress filesystem functions and all output is escaped.
* The plugin starts on `init`, so translations load at the right time.
* Fewer queries: the library scan and the inventory prime the attachment metadata cache, and the temporary-file sweep uses a single query.

= 1.1.23 =
* Accessible field names, no extra queries on public pages and faster delivery.

== Upgrade Notice ==

= 1.3.0 =
Interface update (NIBWOZ UI 1.2.0). No change to optimization or delivery.

= 1.2.1 =
Code-standards maintenance release. No functional changes.

= 1.2.0 =
New interface and full translation support. Settings, optimized copies and the queue are kept.
