# Refine Quick Start Guide

Get up and running with Refine in under 5 minutes.

## Installation (3 steps)

### 1. Install the Package
```bash
composer require devdojo/refine --dev
```

### 2. Generate Icons
Open `vendor/devdojo/refine/extension/icon-generator.html` in your browser, then download all three icons and save them to `vendor/devdojo/refine/extension/icons/`.

### 3. Load Extension
1. Open Chrome → `chrome://extensions/`
2. Enable "Developer mode"
3. Click "Load unpacked"
4. Select `vendor/devdojo/refine/extension`

Done! Refine is now active.

## Basic Usage

1. Right-click any element on your page
2. Select "Edit in Refine" from the menu
3. Edit the code in the popup
4. Press `Cmd/Ctrl + S` or click "Save"
5. Page reloads automatically with your changes

## Keyboard Shortcuts

- `Cmd/Ctrl + S` - Save changes
- `Escape` - Close without saving
- `Tab` - Insert 4 spaces

## Troubleshooting

### "No source reference found"
**Fix:** Make sure `APP_ENV=local` in your `.env`, then run:
```bash
php artisan view:clear
```
Hard refresh your browser (Cmd/Ctrl + Shift + R).

### Extension not working
**Fix:**
1. Check `chrome://extensions/` - is Refine enabled?
2. Check browser console for errors
3. Reload the extension

### Changes not appearing
**Fix:**
```bash
php artisan view:clear
```
Hard refresh (Cmd/Ctrl + Shift + R).

## Configuration

Publish config file (optional):
```bash
php artisan vendor:publish --tag=refine-config
```

Edit `config/refine.php` to customize:
- Which HTML tags get source attributes
- Backup settings
- Route prefix

## Verify Installation

Check Laravel status:
```bash
curl http://localhost:8000/refine/status
```

Should return:
```json
{"success": true, "data": {"enabled": true, "environment": "local", "version": "1.0.0"}}
```

## Tips

- Works best with clearly structured Blade files
- Right-click directly on the element you want to edit
- The editor will find the nearest parent with source info
- Backups are auto-created in `storage/refine/backups/`
- Use `Cmd/Ctrl + S` to save without clicking

## Next Steps

- Read [README.md](README.md) for detailed docs
- Check [TECHNICAL.md](TECHNICAL.md) for architecture details
- Review [INSTALL.md](INSTALL.md) for troubleshooting

## Common Workflows

### Edit a Component
1. Right-click the component
2. Edit in Refine
3. Save and see changes instantly

### Fix a Layout Issue
1. Inspect element to find the section
2. Right-click → Edit in Refine
3. Adjust markup
4. Save and verify

### Update Copy/Text
1. Right-click near the text
2. Edit the Blade source
3. Save and reload

That's it! You're ready to use Refine.
