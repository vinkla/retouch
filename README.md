# Retouch

> Automagically transforms uploaded images into WebP format.

> [!WARNING]  
> This work is in progress and has not yet been published on Packagist. It may or may not be published in the future.

## Installation

Require the package, with Composer, in the root directory of your project.

```bash
composer require vinkla/retouch
```

The plugin will be installed as a [must-use plugin](https://github.com/vinkla/wordplate#must-use-plugins).

### Installing from GitHub

If you want to install directly from GitHub without publishing to Packagist, add the following repository to your `composer.json`:

```json
{
  "repositories": [
    {
      "type": "vcs",
      "url": "https://github.com/vinkla/retouch"
    }
  ],
  "require": {
    "vinkla/retouch": "dev-main"
  }
}
```

Then run:

```bash
composer require vinkla/retouch:dev-main
```

## Features

### Image Conversion

- **Automatic WebP Conversion**: Converts uploaded images to WebP format on upload
- **All Image Sizes**: Processes all WordPress image sizes (thumbnail, medium, large, etc.)
- **Background Processing**: Existing images are converted on-demand via WP-Cron
- **Format Support**: Handles JPEG and PNG images

### Quality Optimization

The plugin uses adaptive quality settings based on image dimensions:

- **Thumbnail** (≤ 150px): 95% quality
- **Small** (< 300×300px): 90% quality
- **Medium** (< 707×707px): 85% quality
- **Large** (≥ 707×707px): 80% quality

### Performance Features

- **Dual Library Support**: Uses ImageMagick (if available) or falls back to GD library
- **Queue Management**: Limits concurrent conversions to prevent resource exhaustion
- **Conversion Tracking**: Prevents duplicate conversions with transient-based tracking
- **Timeout Protection**: Automatic cleanup of stalled conversions after 5 minutes

### Security Features

- **Path Validation**: Only processes images within the uploads directory
- **Traversal Protection**: Prevents directory traversal attacks

## Configuration

### WP-Cron Setup (Required for Production)

**Important:** The plugin will disable itself in production environments if WP-Cron is not properly configured.

For background conversion to work reliably in production, you must disable WordPress's built-in cron and set up a real cron job:

1. Add this to your `wp-config.php`:

```php
define('DISABLE_WP_CRON', true);
```

2. Set up a system cron job to run every minute:

```bash
* * * * * curl https://exempel.se/wordpress/wp-cron.php
```

> ![NOTE]
> This is not required for local development environments (localhost, .local, .test domains). The plugin will automatically detect local environments and skip this requirement.

### Filters

#### `retouch_delete_original`

Enable or disable deletion of original image files after WebP conversion.

```php
// Keep all original files
add_filter('retouch_delete_original', '__return_false');
```

## How It Works

1. **Upload**: When an image is uploaded, the plugin converts it to WebP format
2. **Conversion**: Uses ImageMagick (if available) or falls back to GD library
3. **Optimization**: Applies quality settings based on image dimensions
4. **Cleanup**: Optionally deletes original files (can be disabled with filter)
5. **Background Processing**: Existing images are converted when requested via the frontend

## Requirements

- PHP 8.3 or higher
- WordPress 6.8 or higher
- ImageMagick extension (recommended) or GD library
