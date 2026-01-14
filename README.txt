=== Media Offloader for CF R2 ===
Contributors: majedbannani
Tags: cloudflare, r2, media offload, cdn, webp
Requires at least: 6.0
Tested up to: 6.9
Requires PHP: 8.0
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Offload WordPress media to CF R2 with CDN rewriting, safe cleanup, restore, purge, and automatic WebP optimization.

== Description ==

Media Offloader for CF R2 is a production-grade solution to move WordPress media storage to CF R2 while keeping your site fast, safe, and fully reversible.

The plugin automatically offloads new uploads, rewrites URLs to your CDN, and provides powerful WP-CLI tools for managing existing media at scale.

It also includes built-in image optimization:
– Automatic WebP conversion for new uploads  
– Bulk WebP optimization for existing images  

All operations are designed with safety first: no broken links, no database corruption, and no irreversible actions.

== Features ==

* Automatic media offload to CF R2
* CDN URL rewrite (runtime + persistent)
* Bulk sync existing media to R2
* Safe local media cleanup (disk space recovery)
* Safe restore from R2 back to local storage
* Full R2 bucket purge (with confirmation)
* Automatic WebP conversion for new uploads
* Bulk WebP optimization for existing images
* WP-CLI commands for all heavy operations
* No vendor lock-in – fully reversible

== WP-CLI Commands ==

* `wp r2 sync-existing`
* `wp r2 delete-local`
* `wp r2 restore-local`
* `wp r2 purge`
* `wp r2 optimize-webp`

== Installation ==

1. Upload the plugin to `/wp-content/plugins/`
2. Activate the plugin
3. Go to Settings → CF R2
4. Enter your CF R2 credentials
5. (Optional) Run WP-CLI commands for bulk operations

== Frequently Asked Questions ==

= Is this plugin safe for production? =
Yes. All destructive actions require explicit confirmation and safety checks.

= Can I restore media back locally? =
Yes. Files can be restored from R2 without database changes.

= Does the plugin delete originals? =
Only if the user explicitly runs the safe cleanup command.

== Changelog ==

= 1.0.0 =
* Initial release
