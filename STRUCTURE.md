# Refine - Complete File Structure

```
devdojo/refine/
│
├── extension/                          # Chrome Extension (load this folder)
│   ├── manifest.json                  # Extension configuration
│   ├── background.js                  # Service worker for context menu
│   ├── content.js                     # Content script with UI and logic
│   ├── icon-generator.html           # Utility to create extension icons
│   └── icons/                         # Extension icon files
│       ├── .gitkeep                   # Placeholder (add your PNGs here)
│       ├── icon16.png                 # 16x16 icon (to be created)
│       ├── icon48.png                 # 48x48 icon (to be created)
│       └── icon128.png                # 128x128 icon (to be created)
│
├── src/                                # Laravel Package Source
│   ├── RefineServiceProvider.php     # Auto-registered service provider
│   │
│   ├── Http/
│   │   ├── Controllers/
│   │   │   └── RefineController.php  # API endpoints (fetch, save, status)
│   │   │
│   │   └── Middleware/
│   │       └── RefineMiddleware.php  # Dev-only access control
│   │
│   └── Services/
│       └── BladeInstrumentation.php  # Blade compiler hook for source injection
│
├── config/
│   └── refine.php                     # Package configuration
│
├── composer.json                       # Composer package definition
├── .gitignore                         # Git ignore rules
├── LICENSE                            # MIT License
│
├── README.md                          # Main documentation
├── INSTALL.md                         # Installation guide
├── QUICK-START.md                     # 5-minute quick start
├── TECHNICAL.md                       # Architecture deep-dive
├── CHANGELOG.md                       # Version history
└── STRUCTURE.md                       # This file

```

## File Purposes

### Extension Files

| File | Purpose | Lines |
|------|---------|-------|
| `manifest.json` | Chrome extension configuration, permissions, and metadata | 30 |
| `background.js` | Service worker that handles context menu registration | 25 |
| `content.js` | Main extension logic: DOM traversal, API calls, UI rendering | 400+ |
| `icon-generator.html` | Browser-based tool to create extension icons | 150 |

### Laravel Package Files

| File | Purpose | Lines |
|------|---------|-------|
| `RefineServiceProvider.php` | Registers routes, config, and instrumentation | 60 |
| `RefineController.php` | Handles API requests (fetch/save source code) | 180 |
| `RefineMiddleware.php` | Ensures dev-only access | 25 |
| `BladeInstrumentation.php` | Injects source metadata into Blade views | 250 |
| `refine.php` | Configuration options | 65 |

### Documentation Files

| File | Purpose |
|------|---------|
| `README.md` | Complete feature documentation and usage guide |
| `INSTALL.md` | Step-by-step installation instructions |
| `QUICK-START.md` | Get started in 5 minutes |
| `TECHNICAL.md` | Architecture, algorithms, and internals |
| `CHANGELOG.md` | Version history |
| `STRUCTURE.md` | This file - project structure overview |

## Key Concepts

### Data Flow

```
1. User right-clicks element
        ↓
2. Extension captures click target
        ↓
3. Extension traverses DOM to find data-source attribute
        ↓
4. Extension sends GET /refine/fetch?ref={encoded}
        ↓
5. Laravel decodes ref, loads file, returns contents
        ↓
6. Extension renders floating editor with source
        ↓
7. User edits and saves (Cmd/Ctrl+S)
        ↓
8. Extension sends POST /refine/save
        ↓
9. Laravel backs up file, writes changes, clears cache
        ↓
10. Extension reloads page to show changes
```

### Instrumentation Example

**Before (Original Blade):**
```blade
<div class="alert">
    {{ $message }}
</div>
```

**After (Runtime HTML):**
```html
<div class="alert" data-source="eyJwYXRoIjoiY29tcG9uZW50cy5hbGVydCIsImxpbmUiOjF9">
    Hello World
</div>
```

**Decoded data-source:**
```json
{
    "path": "components.alert",
    "line": 1
}
```

## Installation Locations

After running `composer require devdojo/refine --dev` in your Laravel project, the package will be installed at:

```
your-laravel-project/
├── vendor/
│   └── devdojo/
│       └── refine/          ← Load this in Chrome
│           ├── extension/   ← Load this specific folder
│           ├── src/
│           └── config/
```

## Routes Registered

When Refine is enabled (APP_ENV=local), these routes are automatically registered:

- `GET /refine/status` - Check if Refine is active
- `GET /refine/fetch` - Fetch source code for a reference
- `POST /refine/save` - Save updated source code

All routes are protected by `RefineMiddleware` which enforces local-only access.

## Configuration After Publishing

If you run `php artisan vendor:publish --tag=refine-config`, the config file is copied to:

```
your-laravel-project/
├── config/
│   └── refine.php    ← Customizable settings
```

## Storage (Auto-Created)

Refine creates backup directories automatically:

```
your-laravel-project/
├── storage/
│   └── refine/
│       └── backups/         ← Timestamped file backups
│           ├── alert.blade.php.2024-12-06_14-30-15.backup
│           ├── alert.blade.php.2024-12-06_14-45-22.backup
│           └── ...
```

## Total Line Count

- **PHP Code**: ~580 lines
- **JavaScript Code**: ~425 lines
- **Documentation**: ~1,200 lines
- **Configuration**: ~100 lines

**Total**: ~2,300 lines of production-ready code and documentation

## No Build Required

This package requires **zero build tools**:
- ✅ No npm/yarn
- ✅ No webpack/vite
- ✅ No transpilation
- ✅ No compilation
- ✅ Just load and go

## Browser Support

- Google Chrome (latest) ✅
- Microsoft Edge (Chromium) ✅
- Firefox ❌ (different API)
- Safari ❌ (different API)

## Laravel Support

- Laravel 10.x ✅
- Laravel 11.x ✅
- PHP 8.1+ ✅
