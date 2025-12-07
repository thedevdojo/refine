# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

Refine is a Chrome extension for live-editing Laravel Blade templates directly in the browser. It provides a Monaco editor overlay that allows developers to edit source files while viewing their rendered output.

## Architecture

### Extension Components

- **manifest.json** - Chrome Extension Manifest V3 configuration
- **background.js** - Service worker that registers context menu and handles message passing
- **content.js** - Content script injected into pages; handles UI rendering, editor state management, and Laravel API communication
- **monaco-init.js** - Monaco editor initialization in iframe; handles keyboard shortcuts and custom theming
- **monaco-editor.html** - iframe container for Monaco editor (required for CSP compliance)

### Communication Flow

1. User right-clicks element → background.js receives context menu click
2. background.js sends `refine-open-editor` message to content.js
3. content.js finds nearest `data-source` attribute on DOM element
4. content.js fetches source from Laravel via `/refine/fetch?ref=<sourceRef>`
5. Monaco editor opens in iframe, communicates via postMessage
6. On save, content.js POSTs to `/refine/save` and reloads page

### Key postMessage Types (content.js ↔ monaco-init.js)

- `INIT_EDITOR` - Initialize Monaco with content and config
- `GET_VALUE` / `VALUE_RESPONSE` - Get editor content for saving
- `SAVE` - Trigger save from CMD+S
- `ESCAPE` - Handle escape key (minimize/un-maximize)
- `FOCUS` - Focus editor after restore

### Editor States

- **Normal** - 55vh height, 7px padding
- **Maximized** - Full height, 3px padding
- **Minimized** - Header only visible

## Testing

Open `tests/test-runner.html` in browser with extension installed. Tests are in:
- `tests/refine.test.js` - Core functionality tests
- `tests/monaco-keyboard.test.js` - Keyboard shortcut tests

## Laravel Integration

The extension expects a Laravel app with Refine package installed, providing:
- `data-source` attributes on rendered elements (format: `path:line:col`)
- `GET /refine/fetch` - Returns file contents and metadata
- `POST /refine/save` - Saves updated file contents

## Host Permissions

Extension only runs on local development URLs:
- `http://localhost/*`
- `http://127.0.0.1/*`
- `http://*.test/*`
- `https://*.test/*`
