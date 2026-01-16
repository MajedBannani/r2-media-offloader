# Media Offloader for CF R2 - Architecture Documentation

## Overview

Media Offloader for CF R2 is a production-grade WordPress plugin that seamlessly integrates CF R2 (S3-compatible storage) as a media offloading solution. The plugin is designed with safety, reversibility, and scalability as core principles.

## Core Design Principles

### 1. Safety First
- **Never break uploads**: All upload hooks are non-blocking and fail gracefully
- **Explicit confirmation**: Destructive operations require user confirmation
- **Verification before deletion**: R2 object existence is verified before local file deletion
- **Preserve originals**: Original files are kept unless explicitly cleaned up
- **No database corruption**: All operations use WordPress APIs, never direct SQL

### 2. Reversibility
- **Full restore capability**: All offloaded media can be restored locally
- **No vendor lock-in**: Media can be moved away from R2 at any time
- **Metadata preservation**: All attachment metadata remains intact
- **URL rewrite fallback**: CDN URLs fall back to local URLs if R2 is unavailable

### 3. Scalability
- **Batch processing**: All bulk operations process in configurable batches
- **WP-CLI optimized**: Heavy operations are CLI-first for performance
- **Pagination**: All queries use pagination to prevent memory issues
- **Infinite loop prevention**: Hard guards prevent runaway processes

### 4. WordPress Compliance
- **WordPress APIs only**: Uses wp_get_image_editor(), update_post_meta(), etc.
- **No direct database access**: All data operations go through WordPress functions
- **Proper hooks**: Uses standard WordPress action/filter hooks
- **Security**: Nonces, capability checks, input sanitization

## Plugin Structure

```
r2-media-offloader/
├── r2-media-offloader.php          # Main bootstrap file
├── composer.json                    # Dependencies (AWS SDK)
├── vendor/                          # Bundled dependencies
├── includes/
│   ├── class-plugin.php            # Main plugin loader
│   ├── class-settings.php          # Settings management (Options API)
│   ├── class-r2-client.php         # CF R2 S3 client wrapper
│   ├── helpers.php                 # Shared helper functions
│   ├── admin/
│   │   └── settings-page.php       # Admin settings UI
│   ├── features/
│   │   ├── upload-new-media.php    # Auto-offload new uploads
│   │   ├── rewrite-urls.php        # CDN URL rewriting
│   │   ├── sync-existing-media.php # Bulk sync existing media
│   │   ├── delete-local-media.php  # Safe local cleanup
│   │   ├── restore-local-media.php # Restore from R2
│   │   ├── purge-r2-bucket.php     # R2 bucket purge
│   │   └── webp-conversion.php     # Auto WebP for new uploads
│   └── services/
│       └── class-url-rewriter.php   # URL replacement service
└── cli/
    └── class-sync-cli.php          # WP-CLI commands
```

## Feature Modules

### Upload & Offload (`upload-new-media.php`)
- **Hook**: `wp_handle_upload` (priority 20)
- **Purpose**: Automatically upload new media files to R2
- **Safety**: Never breaks upload pipeline, fails gracefully
- **Metadata**: Stores `_r2_offloaded` and `_r2_key` post meta

### URL Rewriting (`rewrite-urls.php`)
- **Hook**: `wp_get_attachment_url` (priority 20)
- **Purpose**: Rewrite attachment URLs to CDN URLs
- **Logic**: Uses offloaded R2 object key for the original file
- **Safety**: Always falls back to local URL if CDN unavailable

### Bulk Sync (`sync-existing-media.php`)
- **Purpose**: Upload existing media to R2
- **Method**: Batch processing via admin UI or WP-CLI
- **Safety**: Skips already-offloaded attachments, continues on errors

### Safe Cleanup (`delete-local-media.php`)
- **Purpose**: Delete local files after R2 verification
- **Safety**: 
  - Verifies object exists in R2 via HEAD request
  - Only deletes if `_r2_offloaded=true` and object verified
  - Never deletes attachment posts or metadata
  - Sets `_r2_local_deleted` meta to prevent re-processing

### Restore (`restore-local-media.php`)
- **Purpose**: Download files from R2 back to local storage
- **Safety**: 
  - Verifies object exists in R2
  - Skips files that already exist locally
  - Creates directories as needed
  - Never modifies database records

### Purge (`purge-r2-bucket.php`)
- **Purpose**: Delete all objects from R2 bucket
- **Safety**: 
  - Requires explicit confirmation (admin UI: type "PURGE", CLI: double confirmation)
  - Lists objects first to show what will be deleted
  - Never touches local files or database
  - Processes in batches (max 1000 per request)

### WebP Conversion (`webp-conversion.php`)
- **Hook**: `wp_handle_upload` (priority 15, before R2 upload)
- **Purpose**: Convert new image uploads to WebP
- **Safety**: Falls back to original if conversion fails
- **Behavior**: Replaces original file, updates MIME type in upload array

### URL Rewriter Service (`class-url-rewriter.php`)
- **Purpose**: Replace local URLs with CDN URLs across WordPress storage
- **Scope**: 
  - `post_content` (published posts)
  - `wp_options` (including serialized data)
  - `theme_mods_*` options
- **Safety**: Only replaces exact URL matches, preserves data structure

## WP-CLI Philosophy

### Command Design
- **Batch processing**: All commands process in configurable batches
- **Pagination**: Use `paged` parameter to prevent infinite loops
- **Safety guards**: Hard limits prevent runaway processes
- **Progress logging**: Clear output for each operation
- **Error isolation**: Each attachment processed in try/catch

### Command List
1. `wp r2 sync-existing` - Upload existing media to R2
2. `wp r2 delete-local` - Safe local file cleanup
3. `wp r2 restore-local` - Restore files from R2
4. `wp r2 purge` - Purge R2 bucket (with confirmation)

### Error Handling Strategy
- **Top-level try/catch**: Entire command wrapped in try/catch
- **Shutdown handler**: Catches fatal errors in CLI context
- **Per-item isolation**: Each attachment processed independently
- **Graceful degradation**: Continue processing on individual failures
- **Never use WP_CLI::error()**: Use warning/log instead to allow completion

## Error Handling Strategy

### Upload Pipeline
- **Non-blocking**: Upload hooks never throw fatal errors
- **Graceful fallback**: If R2 upload fails, WordPress upload continues normally
- **Logging**: All errors logged via `error_log()` for debugging

### Bulk Operations
- **Try/catch per item**: Each attachment processed in isolation
- **Continue on error**: Failed items don't stop batch processing
- **Error reporting**: Final summary includes failed count

### R2 Client
- **Defensive checks**: Verifies SDK availability before use
- **Exception handling**: Catches AwsException and generic Throwable
- **Clear error messages**: Returns user-friendly error strings

## Settings Management

### Storage
- **Single option key**: All settings stored in `r2mo_settings` option
- **Array structure**: Settings stored as associative array
- **Sanitization**: All fields sanitized via `Settings::sanitize()`

### Fields
- `account_id` - CF account ID
- `access_key` - R2 access key ID
- `secret_key` - R2 secret access key
- `bucket` - R2 bucket name
- `public_url` - Public CDN URL base
- `path_prefix` - Optional prefix for R2 object keys

## Metadata Schema

### Attachment Meta Keys
- `_r2_offloaded` (boolean) - Whether attachment is offloaded to R2
- `_r2_key` (string) - R2 object key for original file
- `_r2_local_deleted` (boolean) - Whether local file has been safely deleted

## Security Considerations

### Input Validation
- All user input sanitized via WordPress functions
- Nonces for all admin actions
- Capability checks (`manage_options`)

### File Operations
- Path validation: Ensures files are within uploads directory
- Permission checks: Verifies files are writable before deletion
- Safe unlink: Uses `@unlink()` with error suppression for cleanup only

### R2 Credentials
- Never exposed in UI (secret key uses password field)
- Stored securely in WordPress options table
- Preserved if field left blank during update

## Performance Optimizations

### Memory Management
- Batch processing prevents memory exhaustion
- `gc_collect_cycles()` called periodically
- Variables unset after use
- Queries use `fields => 'ids'` to minimize memory

### Query Optimization
- Targeted queries: Only fetch needed attachments
- Meta queries: Use `NOT EXISTS` to skip processed items
- Pagination: Process in manageable chunks
- Index-friendly: Queries use indexed fields (ID, post_type, meta_key)

## Testing Considerations

### Unit Testing
- All functions are pure or use dependency injection
- R2 client can be mocked via singleton pattern
- Settings can be overridden for testing

### Integration Testing
- WP-CLI commands can be tested in isolation
- Admin handlers can be tested via `admin-post.php` actions
- Upload hooks can be tested via `wp_handle_upload` filter

## Future Extensibility

### Adding New Features
- Create new file in `includes/features/`
- Load in `Plugin::load_dependencies()`
- Follow existing patterns for hooks and error handling

### Adding WP-CLI Commands
- Add method to `Sync_CLI` class
- Register in `Sync_CLI::register()`
- Follow existing docblock format for help output

### Adding Settings
- Add field to `Settings::sanitize()`
- Add UI field in `Settings_Page::register_fields()`
- Access via `Settings::get('field_name')`

## Maintenance Notes

### Code Style
- PSR-12 compatible
- WordPress Coding Standards
- Strict types enabled (`declare(strict_types=1)`)
- Namespace: `R2MO` for main, `R2MO\Admin` for admin, `R2MO\CLI` for CLI

### Dependencies
- AWS SDK v3 (bundled via Composer)
- PHP 8.0+ required
- WordPress 6.0+ required

### Compatibility
- Works with any S3-compatible storage (not just R2)
- Compatible with other media plugins (non-conflicting hooks)
- Safe for multisite installations
