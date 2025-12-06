# Changelog

All notable changes to Refine will be documented in this file.

## [1.0.0] - 2024-12-06

### Added
- Initial release of Refine
- Chrome extension with context menu integration
- Laravel Composer package with auto-discovery
- Automatic Blade view instrumentation with source metadata
- API endpoints for fetching and saving source code
- Floating code editor UI with dark theme
- Automatic file backup system
- View cache clearing after saves
- Support for nested Blade components and partials
- Keyboard shortcuts (Cmd/Ctrl+S to save, Esc to cancel)
- Dev-only middleware protection
- Comprehensive documentation and installation guide
- Icon generator utility

### Security
- Environment-based activation (local only)
- Multiple security layers to prevent production usage
- CSRF token validation on save operations
