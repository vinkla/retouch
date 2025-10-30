<?php

/**
 * Copyright (c) Vincent Klaiber
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @see https://github.com/vinkla/retouch
 */

/**
 * Plugin Name: Retouch
 * Description: Automagically transforms uploaded images into WebP format.
 * Author: Vincent Klaiber
 * Author URI: https://github.com/vinkla
 * Version: 1.0
 * Plugin URI: https://github.com/vinkla/retouch
 * GitHub Plugin URI: vinkla/retouch
 */

declare(strict_types=1);

namespace Retouch;

use Exception;

// Prevent direct access
if (! defined('ABSPATH')) {
    exit();
}

// Check if running in a local development environment.
function is_local_environment(): bool
{
    return wp_get_environment_type() === 'local';
}

// Check if WP-Cron is properly disabled (only in production)
if (! is_local_environment() && (! defined('DISABLE_WP_CRON') || ! DISABLE_WP_CRON)) {
    add_action('admin_notices', __NAMESPACE__ . '\\display_cron_error');
    return; // Stop plugin execution
}

// Display error if WP-Cron is not disabled in production.
function display_cron_error(): void
{
    echo '<div class="notice notice-error"><p>';
    echo '<strong>Retouch:</strong> This plugin requires ';
    echo '<code>define(\'DISABLE_WP_CRON\', true);</code> in your wp-config.php with a real cron job set up. ';
    echo 'The plugin is currently disabled.';
    echo '</p></div>';
}

// Quality settings based on image dimensions.
const QUALITY_THUMBNAIL = 95; // <= 150px
const QUALITY_SMALL = 90; // < 300x300
const QUALITY_MEDIUM = 85; // < 707x707
const QUALITY_LARGE = 80; // >= 707x707

// Maximum number of conversions allowed in the queue at once.
const MAX_QUEUED_CONVERSIONS = 10;

// Conversion tracking timeout (in seconds).
const CONVERSION_TIMEOUT = 300; // 5 minutes

// Bootstrap the converter.
add_action('plugins_loaded', __NAMESPACE__ . '\\bootstrap');

// Bootstrap the converter.
function bootstrap(): void
{
    add_filter('wp_generate_attachment_metadata', __NAMESPACE__ . '\\convert_images', 10, 2);
    add_filter('wp_get_attachment_image_src', __NAMESPACE__ . '\\schedule_conversion', 10, 4);
    add_filter('wp_calculate_image_srcset', __NAMESPACE__ . '\\convert_srcset', 10, 5);
    add_action('retouch_convert_image', __NAMESPACE__ . '\\convert_existing_image', 10, 3);
}

// Log a message if WP_DEBUG and WP_DEBUG_LOG are enabled.
function log_message(string $message): void
{
    if (defined('WP_DEBUG') && WP_DEBUG && defined('WP_DEBUG_LOG') && WP_DEBUG_LOG) {
        error_log($message);
    }
}

// Track conversions in progress using transients.
function mark_conversion_in_progress(int $attachmentId, string $size): bool
{
    $key = "retouch_converting_{$attachmentId}_{$size}";
    // Set with timeout expiration in case process dies
    return set_transient($key, time(), CONVERSION_TIMEOUT);
}

// Check if conversion is currently in progress.
function is_conversion_in_progress(int $attachmentId, string $size): bool
{
    $key = "retouch_converting_{$attachmentId}_{$size}";
    return get_transient($key) !== false;
}

// Mark conversion as complete.
function mark_conversion_complete(int $attachmentId, string $size): void
{
    $key = "retouch_converting_{$attachmentId}_{$size}";
    delete_transient($key);
}

// Normalize size key for tracking.
function normalize_size_key(string|array $size): string
{
    if (is_array($size)) {
        return implode('_', $size);
    }
    return $size;
}

// Convert attachment images to WebP format.
function convert_images(array $metadata, int $attachmentId): array
{
    $uploadDir = wp_upload_dir();

    // Process scaled version if it exists
    if (isset($metadata['original_image'])) {
        process_scaled_image($metadata, $attachmentId, $uploadDir);
    }

    // Process all image subsizes
    if (isset($metadata['sizes']) && is_array($metadata['sizes'])) {
        process_image_sizes($metadata['sizes'], $uploadDir['path']);
    }

    return $metadata;
}

// Schedule conversion for existing images when requested.
function schedule_conversion(array|false $image, int|array $attachmentId, string|array $size, bool $icon): array|false
{
    // Skip if attachment ID is not an integer
    if (! is_int($attachmentId)) {
        return $image;
    }

    if (! should_process_image($image, $icon)) {
        return $image;
    }

    $imagePath = get_image_path_from_url($image[0]);

    if (! is_valid_image_path($imagePath)) {
        return $image;
    }

    $webpPath = generate_webp_path($imagePath);

    // If WebP already exists, return it
    if (file_exists($webpPath)) {
        return update_image_to_webp($image, $webpPath);
    }

    // Schedule conversion in background
    schedule_background_conversion($attachmentId, $size, [
        'width' => $image[1] ?? 0,
        'height' => $image[2] ?? 0,
    ]);

    return $image;
}

// Check if image should be processed.
function should_process_image(array|false $image, bool $icon): bool
{
    return $image && ! $icon;
}

// Get the file path from the image URL.
function get_image_path_from_url(string $imageUrl): string
{
    $uploadDir = wp_upload_dir();
    return str_replace($uploadDir['baseurl'], $uploadDir['basedir'], $imageUrl);
}

// Check if image path is valid for processing.
function is_valid_image_path(string $imagePath): bool
{
    $uploadDir = wp_upload_dir();

    // Normalize path to prevent traversal attacks
    $realImagePath = realpath($imagePath);
    $realUploadDir = realpath($uploadDir['basedir']);

    // Only process images in the uploads directory
    if (! $realImagePath || ! $realUploadDir || ! str_starts_with($realImagePath, $realUploadDir)) {
        return false;
    }

    // Skip WordPress core images (wp-content, wp-includes, wp-admin)
    if (str_contains($realImagePath, '/wp-content/') && ! str_contains($realImagePath, '/uploads/')) {
        return false;
    }

    if (str_contains($realImagePath, '/wp-includes/') || str_contains($realImagePath, '/wp-admin/')) {
        return false;
    }

    // Check if this is already a WebP
    if (str_ends_with($imagePath, '.webp')) {
        return false;
    }

    // Check if file exists and is readable
    return file_exists($imagePath) && is_readable($imagePath);
}

// Generate WebP path from original image path.
function generate_webp_path(string $imagePath): string
{
    $pathInfo = pathinfo($imagePath);
    return $pathInfo['dirname'] . '/' . $pathInfo['filename'] . '.webp';
}

// Update image array to point to WebP version.
function update_image_to_webp(array $image, string $webpPath): array
{
    $uploadDir = wp_upload_dir();
    $webpUrl = str_replace($uploadDir['basedir'], $uploadDir['baseurl'], $webpPath);
    $image[0] = $webpUrl;
    return $image;
}

// Schedule background conversion for an image.
function schedule_background_conversion(int $attachmentId, string|array $size, array $dimensions): void
{
    // Normalize size key for tracking
    $sizeKey = normalize_size_key($size);

    // Check if already being processed
    if (is_conversion_in_progress($attachmentId, $sizeKey)) {
        return;
    }

    // Check if we already have too many conversions queued
    if (get_pending_conversion_count() >= MAX_QUEUED_CONVERSIONS) {
        return;
    }

    // Don't schedule if already scheduled for this attachment/size
    if (! wp_next_scheduled('retouch_convert_image', [$attachmentId, $size])) {
        wp_schedule_single_event(time() + 1, 'retouch_convert_image', [$attachmentId, $size, $dimensions]);
    }
}

// Get the number of pending conversion jobs in the queue.
function get_pending_conversion_count(): int
{
    $crons = _get_cron_array();

    if (! is_array($crons)) {
        return 0;
    }

    $count = 0;

    foreach ($crons as $cronEvents) {
        if (isset($cronEvents['retouch_convert_image'])) {
            $count += count($cronEvents['retouch_convert_image']);
        }
    }

    return $count;
}

// Convert srcset images to WebP.
function convert_srcset(
    array $sources,
    array $sizeArray,
    string|null $imageUrl,
    array $imageMeta,
    int $attachmentId,
): array {
    $uploadDir = wp_upload_dir();

    foreach ($sources as &$srcsetSource) {
        if (! process_srcset_source($srcsetSource, $uploadDir, $attachmentId)) {
            continue;
        }
    }

    return $sources;
}

// Process a single srcset source.
function process_srcset_source(array &$srcsetSource, array $uploadDir, int $attachmentId): bool
{
    $imageUrl = $srcsetSource['url'];
    $imagePath = str_replace($uploadDir['baseurl'], $uploadDir['basedir'], $imageUrl);

    // Only process images in the uploads directory
    if (! str_starts_with($imagePath, $uploadDir['basedir'])) {
        return false;
    }

    // Skip if already WebP
    if (str_ends_with($imagePath, '.webp')) {
        return false;
    }

    // Check if file exists
    if (! file_exists($imagePath)) {
        return false;
    }

    $webpPath = generate_webp_path($imagePath);

    // If WebP already exists, use it
    if (file_exists($webpPath)) {
        $srcsetSource['url'] = str_replace($uploadDir['basedir'], $uploadDir['baseurl'], $webpPath);
        return true;
    }

    // Schedule conversion for this attachment (will convert all sizes)
    $dimensions = [
        'width' => $srcsetSource['value'] ?? 0,
        'height' => 0,
    ];

    schedule_background_conversion($attachmentId, 'srcset', $dimensions);

    return true;
}

// Convert existing image in background.
function convert_existing_image(int $attachmentId, string|array $size, array $dimensions): void
{
    // Normalize size key for tracking
    $sizeKey = normalize_size_key($size);

    // Mark conversion as in progress
    mark_conversion_in_progress($attachmentId, $sizeKey);

    $metadata = wp_get_attachment_metadata($attachmentId);

    if (! $metadata) {
        mark_conversion_complete($attachmentId, $sizeKey);
        return;
    }

    $uploadDir = wp_upload_dir();
    $updated = false;

    // Handle scaled version (only if it exists as a separate file)
    if (should_process_scaled_version($metadata, $uploadDir)) {
        $updated = process_existing_scaled_image($metadata, $attachmentId, $uploadDir) || $updated;
    }

    // Handle all sizes
    if (isset($metadata['sizes']) && is_array($metadata['sizes'])) {
        $updated = process_existing_image_sizes($metadata, $uploadDir) || $updated;
    }

    if ($updated) {
        wp_update_attachment_metadata($attachmentId, $metadata);
    }

    // Mark conversion as complete
    mark_conversion_complete($attachmentId, $sizeKey);
}

// Check if scaled version should be processed.
function should_process_scaled_version(array $metadata, array $uploadDir): bool
{
    if (! isset($metadata['original_image']) || ! isset($metadata['file'])) {
        return false;
    }

    $scaledFile = $uploadDir['basedir'] . '/' . $metadata['file'];

    return file_exists($scaledFile) && ! str_ends_with($scaledFile, '.webp');
}

// Process existing scaled image.
function process_existing_scaled_image(array &$metadata, int $attachmentId, array $uploadDir): bool
{
    $scaledFile = $uploadDir['basedir'] . '/' . $metadata['file'];
    $webpFile = generate_webp_path($scaledFile);

    $fullDimensions = [
        'width' => $metadata['width'] ?? 0,
        'height' => $metadata['height'] ?? 0,
    ];

    if (! convert_image($scaledFile, $webpFile, $fullDimensions)) {
        return false;
    }

    delete_original_file($scaledFile);

    $metadata['file'] = str_replace($uploadDir['basedir'] . '/', '', $webpFile);

    update_attached_file($attachmentId, $webpFile);

    return true;
}

// Process all existing image sizes.
function process_existing_image_sizes(array &$metadata, array $uploadDir): bool
{
    $updated = false;

    foreach ($metadata['sizes'] as &$imageSize) {
        if (str_ends_with($imageSize['file'], '.webp')) {
            continue;
        }

        $basePath = get_base_path_for_size($metadata, $uploadDir);
        $originalFile = $basePath . '/' . $imageSize['file'];

        if (! file_exists($originalFile)) {
            continue;
        }

        if (process_existing_size($imageSize, $originalFile)) {
            $updated = true;
        }
    }

    return $updated;
}

// Get base path for image size.
function get_base_path_for_size(array $metadata, array $uploadDir): string
{
    $basePath = $uploadDir['basedir'];

    if (isset($metadata['file'])) {
        $filePath = pathinfo($metadata['file']);
        if (isset($filePath['dirname']) && $filePath['dirname'] !== '.') {
            $basePath .= '/' . $filePath['dirname'];
        }
    }

    return $basePath;
}

// Process existing single size.
function process_existing_size(array &$imageSize, string $originalFile): bool
{
    $webpFile = generate_webp_path($originalFile);
    $pathInfo = pathinfo($originalFile);

    $sizeDimensions = [
        'width' => $imageSize['width'] ?? 0,
        'height' => $imageSize['height'] ?? 0,
    ];

    if (! convert_image($originalFile, $webpFile, $sizeDimensions)) {
        return false;
    }

    delete_original_file($originalFile);

    $imageSize['file'] = $pathInfo['filename'] . '.webp';
    $imageSize['mime-type'] = 'image/webp';

    return true;
}

// Process the scaled version of an image.
function process_scaled_image(array &$metadata, int $attachmentId, array $uploadDir): void
{
    $scaledFile = $uploadDir['basedir'] . '/' . $metadata['file'];

    if (! file_exists($scaledFile)) {
        return;
    }

    $pathInfo = pathinfo($scaledFile);
    $webpFile = $pathInfo['dirname'] . '/' . $pathInfo['filename'] . '.webp';

    $dimensions = [
        'width' => $metadata['width'] ?? 0,
        'height' => $metadata['height'] ?? 0,
    ];

    if (! convert_image($scaledFile, $webpFile, $dimensions)) {
        return;
    }

    delete_original_file($scaledFile);
    $metadata['file'] = str_replace($uploadDir['basedir'] . '/', '', $webpFile);
    update_attached_file($attachmentId, $webpFile);
}

// Process all image subsizes.
function process_image_sizes(array &$imageSizes, string $basePath): void
{
    foreach ($imageSizes as &$imageSize) {
        process_single_size($imageSize, $basePath);
    }
}

// Process a single image size.
function process_single_size(array &$imageSize, string $basePath): void
{
    $originalFile = $basePath . '/' . $imageSize['file'];

    if (! file_exists($originalFile)) {
        return;
    }

    $webpFile = generate_webp_path($originalFile);
    $pathInfo = pathinfo($originalFile);

    $dimensions = [
        'width' => $imageSize['width'] ?? 0,
        'height' => $imageSize['height'] ?? 0,
    ];

    if (! convert_image($originalFile, $webpFile, $dimensions)) {
        return;
    }

    delete_original_file($originalFile);

    $imageSize['file'] = $pathInfo['filename'] . '.webp';
    $imageSize['mime-type'] = 'image/webp';
}

// Convert an image to WebP format.
function convert_image(string $source, string $destination, array $dimensions): bool
{
    // Verify source exists and is readable
    if (! file_exists($source)) {
        log_message("Retouch: source file does not exist: {$source}");

        return false;
    }

    if (! is_readable($source)) {
        log_message("Retouch: source file is not readable: {$source}");

        return false;
    }

    $quality = calculate_quality($dimensions['width'], $dimensions['height']);

    // Try ImageMagick first
    if (convert_with_imagemagick($source, $destination, $quality)) {
        return validate_conversion($source, $destination);
    }

    // Fall back to GD
    if (convert_with_gd($source, $destination, $quality)) {
        return validate_conversion($source, $destination);
    }

    log_message("Retouch: failed to convert {$source} with both ImageMagick and GD");

    return false;
}

// Validate that the conversion was successful.
function validate_conversion(string $source, string $destination): bool
{
    // Verify the output file was created
    if (! file_exists($destination)) {
        log_message("Retouch: conversion failed - destination file not created: {$destination}");

        return false;
    }

    // Verify the output file has content
    if (filesize($destination) === 0) {
        log_message("Retouch: conversion failed - destination file is empty: {$destination}");

        unlink($destination);

        return false;
    }

    // Log successful conversion with size information
    $sourceSize = filesize($source);
    $destinationSize = filesize($destination);
    $reductionPercent = (1 - $destinationSize / $sourceSize) * 100;

    log_message(
        sprintf('Retouch: successfully converted %s (%.2f%% size reduction)', basename($source), $reductionPercent),
    );

    return true;
}

// Delete original file if allowed by filter.
function delete_original_file(string $originalFile): void
{
    if (apply_filters('retouch_delete_original', true)) {
        unlink($originalFile);
    }
}

// Calculate optimal quality based on image dimensions.
function calculate_quality(int $width, int $height): int
{
    $pixels = $width * $height;

    // Thumbnail/small images
    if ($width <= 150 || $height <= 150) {
        return QUALITY_THUMBNAIL;
    }

    // Small images (< 90,000 pixels ~= 300x300)
    if ($pixels < 90000) {
        return QUALITY_SMALL;
    }

    // Medium images (< 500,000 pixels ~= 707x707)
    if ($pixels < 500000) {
        return QUALITY_MEDIUM;
    }

    // Large images
    return QUALITY_LARGE;
}

// Convert image using ImageMagick.
function convert_with_imagemagick(string $source, string $destination, int $quality): bool
{
    if (! extension_loaded('imagick') || ! class_exists('Imagick')) {
        return false;
    }

    try {
        /** @phpstan-ignore-next-line */
        $image = new \Imagick($source);
        $image->setImageFormat('webp');
        $image->setImageCompressionQuality($quality);
        $image->stripImage();
        $result = $image->writeImage($destination);
        $image->destroy();

        return $result;
    } catch (Exception $exception) {
        log_message('Retouch: ImageMagick conversion failed: ' . $exception->getMessage());
        return false;
    }
}

// Convert image using GD library.
function convert_with_gd(string $source, string $destination, int $quality): bool
{
    $imageInfo = getimagesize($source);

    if ($imageInfo === false) {
        log_message("Retouch: GD failed to get image info for: {$source}");
        return false;
    }

    $image = match ($imageInfo['mime']) {
        'image/jpeg' => imagecreatefromjpeg($source),
        'image/png' => create_png_image($source),
        'image/webp' => null,
        default => null,
    };

    if ($image === null) {
        // If already WebP, just copy
        if ($imageInfo['mime'] === 'image/webp') {
            return copy($source, $destination);
        }
        log_message("Retouch: GD unsupported image type {$imageInfo['mime']} for: {$source}");
        return false;
    }

    $result = imagewebp($image, $destination, $quality);
    imagedestroy($image);

    if (! $result) {
        log_message("Retouch: GD imagewebp() failed for: {$source}");
    }

    return $result;
}

// Create PNG image with transparency preserved.
function create_png_image(string $source): \GdImage|false|null
{
    $image = imagecreatefrompng($source);

    if ($image === false) {
        return null;
    }

    imagepalettetotruecolor($image);
    imagealphablending($image, true);
    imagesavealpha($image, true);

    return $image;
}
