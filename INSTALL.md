# Refine Installation Guide

This guide walks you through installing and setting up Refine for the first time.

## Prerequisites

- Laravel 10.x or 11.x
- PHP 8.1 or higher
- Local development environment (APP_ENV=local)
- Google Chrome browser

## Quick Start (5 minutes)

### 1. Install via Composer

In your Laravel project root:

```bash
composer require devdojo/refine --dev
```

The package will auto-register. No need to manually add the service provider.

### 2. Create Extension Icons

You need three icon files. Here's the quickest way to create them:

**Option A: Use an online icon generator**
1. Go to https://favicon.io/favicon-generator/
2. Create a simple icon (letter "R" works great)
3. Download and extract
4. Rename three files to: `icon16.png`, `icon48.png`, `icon128.png`
5. Copy them to `vendor/devdojo/refine/extension/icons/`

**Option B: Use existing icons**
Copy any three PNG files you have and resize them to 16x16, 48x48, and 128x128 pixels.

**Option C: Create solid color placeholders**
Use any image editor to create three solid color squares at the required dimensions.

### 3. Load Extension in Chrome

1. Open Chrome
2. Go to `chrome://extensions/`
3. Toggle "Developer mode" ON (top-right corner)
4. Click "Load unpacked"
5. Navigate to your Laravel project
6. Select `vendor/devdojo/refine/extension`
7. Click "Select Folder"

You should see "Refine - Live Blade Editor" appear in your extensions list.

### 4. Verify Installation

**Check Laravel:**
```bash
# Start your dev server
php artisan serve
# or use Valet/Herd

# Visit the status endpoint
curl http://localhost:8000/refine/status
```

You should see:
```json
{
  "success": true,
  "data": {
    "enabled": true,
    "environment": "local",
    "version": "1.0.0"
  }
}
```

**Check Chrome:**
1. Visit your Laravel app in Chrome
2. Open DevTools (F12)
3. Check the Console tab
4. You should see: "Refine: Content script loaded and ready"

### 5. Test It Out

1. Visit any page in your Laravel app
2. Right-click on any element
3. Click "Edit in Refine"
4. You should see a floating code editor!

## Troubleshooting Installation

### "Extension invalid" error in Chrome

**Problem**: Missing or invalid manifest.json

**Solution**: Verify the manifest file exists at `vendor/devdojo/refine/extension/manifest.json`

### "Could not load icon" warning in Chrome

**Problem**: Missing icon files

**Solution**: Create the three required PNG files in `vendor/devdojo/refine/extension/icons/`:
- icon16.png
- icon48.png
- icon128.png

The extension will still work with this warning, but it's better to add the icons.

### 403 Forbidden on /refine/status

**Problem**: Not running in local environment

**Solution**: Check your `.env` file:
```env
APP_ENV=local
```

### "No source reference found" when right-clicking

**Problem**: Blade views aren't being instrumented

**Solutions**:
1. Clear view cache: `php artisan view:clear`
2. Hard refresh the page (Cmd/Ctrl + Shift + R)
3. Check config: `php artisan tinker` then `config('refine.enabled')`
4. Verify APP_ENV: `php artisan tinker` then `app()->environment()`

### Extension not appearing in context menu

**Problem**: Extension not loaded or content script not injecting

**Solutions**:
1. Check `chrome://extensions/` - is Refine enabled?
2. Check the extension details - any errors?
3. Reload the extension (click refresh icon)
4. Check browser console for errors
5. Verify you're on a localhost or .test domain

## Configuration

After installation, you can optionally publish the config file:

```bash
php artisan vendor:publish --tag=refine-config
```

This creates `config/refine.php` where you can customize:
- Which HTML tags get instrumented
- Backup behavior
- Route prefix
- Additional middleware

## Manual Service Provider Registration

If package auto-discovery is disabled in your project, manually register the provider in `config/app.php`:

```php
'providers' => [
    // ...
    DevDojo\Refine\RefineServiceProvider::class,
],
```

## Next Steps

- Read the [README.md](README.md) for usage instructions
- Customize settings in `config/refine.php`
- Try editing different Blade files
- Explore keyboard shortcuts (Cmd/Ctrl+S to save, Esc to cancel)

## Uninstalling

To remove Refine:

```bash
# Remove the package
composer remove devdojo/refine

# Remove published config (if you published it)
rm config/refine.php

# Remove the extension from Chrome
# Go to chrome://extensions/ and click "Remove"
```

## Getting Help

- Check the [README.md](README.md) for detailed documentation
- Review the Troubleshooting section above
- Check browser console for error messages
- Check Laravel logs in `storage/logs/`
