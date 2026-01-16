=== Media Offloader for CF R2 ===
Contributors: majedbannani
Tags: cloudflare, r2, media offload, cdn, webp
Requires at least: 6.0
Tested up to: 6.9
Requires PHP: 8.0
Stable tag: 1.0.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Offload WordPress media to Cloudflare R2 with CDN URL rewriting, safe cleanup, restore tools, and automatic WebP conversion for new uploads.

== Description ==

Media Offloader for CF R2 is a production-ready solution for moving WordPress media storage to Cloudflare R2 while keeping your site fast, safe, and fully reversible.

The plugin automatically offloads new media uploads to R2, rewrites media URLs to your CDN, and provides powerful WP-CLI commands for managing existing media at scale.

Built-in image optimization features include:

* Automatic WebP conversion for new uploads

All operations are designed with safety in mind. The plugin avoids broken links, prevents database corruption, and ensures that all destructive actions are explicitly triggered by the user.

== Features ==

* Automatic media offload to Cloudflare R2
* CDN URL rewriting (runtime and persistent)
* Bulk synchronization of existing media to R2
* Safe local media cleanup to recover disk space
* Safe restore from R2 back to local storage
* Full R2 bucket purge with confirmation
* Automatic WebP conversion for new uploads
* WP-CLI commands for all heavy operations
* No vendor lock-in – fully reversible at any time

== WP-CLI Commands ==

The plugin provides WP-CLI commands for advanced and large-scale operations:

* `wp r2 sync-existing`
* `wp r2 delete-local`
* `wp r2 restore-local`
* `wp r2 purge`

== Installation ==

1. Upload the plugin to the `/wp-content/plugins/` directory.
2. Activate the plugin through the WordPress admin panel.
3. Go to Settings → CF R2.
4. Enter your Cloudflare R2 credentials.
5. (Optional) Use WP-CLI commands for bulk operations.

== Frequently Asked Questions ==

= Is this plugin safe for production use? =
Yes. All destructive actions require explicit user confirmation and multiple safety checks.

= Can I restore media files back to local storage? =
Yes. Media files can be restored from Cloudflare R2 without modifying database URLs.

= Does the plugin delete original files automatically? =
No. Local files are only removed if the user explicitly runs the safe cleanup command.

== Changelog ==

= 1.0.4 =
* UI polish and admin UX improvements
* Safe SDK bundling and activation fallback
