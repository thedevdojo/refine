# Technical Documentation

This document provides deep technical details about Refine's architecture and implementation.

## Architecture Overview

Refine consists of two main components that work together:

1. **Laravel Package** - Backend instrumentation and API
2. **Chrome Extension** - Frontend UI and browser integration

## Laravel Package Deep Dive

### Blade Instrumentation System

The instrumentation system hooks into Laravel's Blade compiler to inject source metadata into rendered HTML.

#### How It Works

1. **Compiler Extension**: `BladeInstrumentation::register()` uses `Blade::extend()` to hook into the compilation process
2. **View Path Detection**: Uses reflection to access the `BladeCompiler::$path` property
3. **Line-by-Line Processing**: Splits the view into lines and processes each one
4. **Tag Matching**: Uses regex to find opening HTML tags that match configured targets
5. **Attribute Injection**: Inserts `data-source="base64_encoded_json"` into matched tags
6. **Encoding**: Source references are JSON-encoded then base64-encoded for safe HTML attribute storage

#### Source Reference Format

```php
// Original format (before encoding)
[
    'path' => 'components.alert',  // Laravel view path
    'line' => 42                     // Line number in source file
]

// After encoding
base64_encode(json_encode($data))
// Example: "eyJwYXRoIjoiY29tcG9uZW50cy5hbGVydCIsImxpbmUiOjQyfQ=="
```

#### Performance Considerations

- Instrumentation only runs during view compilation (cached after first render)
- Reflection used minimally (once per view compilation)
- Regex patterns are optimized for common HTML structures
- Only configured tags are instrumented to reduce overhead

### API Controller

#### Endpoint: GET /refine/fetch

**Flow:**
1. Receive base64-encoded source reference
2. Decode to get view path and line number
3. Resolve view path to absolute file path using `BladeInstrumentation::resolveViewPath()`
4. Read entire file contents
5. Calculate context window (±5 lines around target)
6. Return JSON with file data

**Path Resolution:**
```php
// View path: "components.alert"
// Checks: resources/views/components/alert.blade.php
// Also checks all registered view paths from config('view.paths')
```

#### Endpoint: POST /refine/save

**Flow:**
1. Receive source reference and new contents
2. Decode reference to get file path
3. Create timestamped backup in storage/refine/backups/
4. Write new contents to file
5. Clean up old backups (keep only N most recent)
6. Clear view cache via `Artisan::call('view:clear')`
7. Return success response

**Backup System:**
```php
// Backup naming convention
{original_filename}.{Y-m-d_H-i-s}.backup

// Example
alert.blade.php.2024-12-06_14-30-15.backup
```

### Security Layers

1. **Config Check**: `config('refine.enabled')` must be true
2. **Environment Check**: `app()->environment() === 'local'`
3. **Middleware**: `RefineMiddleware` enforces both checks on every request
4. **Route Registration**: Routes only register when enabled
5. **Service Provider**: Instrumentation only activates in local environment

## Chrome Extension Deep Dive

### Manifest V3 Architecture

Refine uses Manifest V3, the latest Chrome extension format.

**Key Differences from V2:**
- Background scripts → Service workers
- Persistent background → Event-driven
- `chrome.*` APIs → Mostly same, some async changes

### Background Service Worker

**File:** `background.js`

**Responsibilities:**
- Register context menu on installation
- Listen for context menu clicks
- Relay messages to content scripts

**Lifecycle:**
- Spins up when needed (menu click, extension install)
- Goes dormant after inactivity
- Cannot maintain long-term state

**Communication Flow:**
```
User right-clicks → Context menu appears
User clicks "Edit in Refine" → Service worker wakes up
Service worker → Sends message to content script
Content script → Opens editor
```

### Content Script

**File:** `content.js`

**Injection:**
- Runs on all localhost and *.test domains
- Executes at `document_end` (after DOM loaded, before window.onload)
- Has access to page DOM but isolated JavaScript context

#### DOM Traversal Algorithm

```javascript
function findNearestSourceElement(element) {
    let current = element;
    let depth = 0;

    while (current && depth < MAX_DEPTH) {
        if (current.hasAttribute(SOURCE_ATTRIBUTE)) {
            return current;
        }
        current = current.parentElement;
        depth++;
    }

    return null;
}
```

**Why This Works:**
- Clicks bubble up the DOM
- Parent elements often have source attributes
- Max depth prevents infinite loops
- Returns first match (deepest in tree)

#### Click Tracking

```javascript
// Track on mousedown (before context menu)
document.addEventListener('mousedown', (e) => {
    if (e.button === 2) { // Right-click
        clickedElement = e.target;
    }
}, true); // Capture phase to get event first
```

#### Editor UI Architecture

The editor is a fixed-position overlay with:
1. **Overlay** - Full-screen semi-transparent backdrop
2. **Container** - Centered modal-style box
3. **Header** - Title and action buttons
4. **Textarea** - Full source code editor
5. **Footer** - File metadata

**Styling Approach:**
- All styles are inline (no external CSS)
- Dark theme for reduced eye strain
- Monospace font for code editing
- Fixed positioning for overlay consistency

#### Save Flow

```
User clicks Save or presses Cmd/Ctrl+S
    ↓
Disable save button (prevent double-save)
    ↓
Send POST request to /refine/save
    ↓
Wait for response
    ↓
[Success] Show notification → Close editor → Reload page
[Failure] Show error → Re-enable save button
```

**Debouncing:**
Currently not implemented for saves (user-initiated only).
Future versions may add auto-save with debouncing.

## Communication Protocol

### Extension → Laravel

**Fetch Request:**
```http
GET /refine/fetch?ref=eyJwYXRoIjoiY29tcG9uZW50cy5hbGVydCIsImxpbmUiOjQyfQ==
Accept: application/json
```

**Save Request:**
```http
POST /refine/save
Content-Type: application/json
X-CSRF-TOKEN: {token}

{
  "ref": "eyJwYXRoIjoiY29tcG9uZW50cy5hbGVydCIsImxpbmUiOjQyfQ==",
  "contents": "full file contents here..."
}
```

### CSRF Token Resolution

The extension tries multiple methods to find Laravel's CSRF token:

```javascript
// Method 1: Meta tag (recommended)
<meta name="csrf-token" content="{{ csrf_token() }}">

// Method 2: Form input (fallback)
<input type="hidden" name="_token" value="{{ csrf_token() }}">
```

## File System Operations

### Path Resolution

```
View Path: "components.alert"
    ↓
Convert dots to slashes: "components/alert"
    ↓
Add extension: "components/alert.blade.php"
    ↓
Check registered view paths:
  - resources/views/components/alert.blade.php ✓
  - other/view/path/components/alert.blade.php ✗
    ↓
Return first match
```

### Write Safety

1. **Atomic Writes**: PHP's `file_put_contents()` is atomic on most systems
2. **Backups**: Always created before write (unless disabled)
3. **Permissions**: Relies on existing file permissions
4. **Encoding**: Preserves original file encoding

### Backup Management

```php
// Create backup
copy($original, $backup);

// Find all backups for file
glob($backupDir . '/' . $filename . '.*.backup');

// Sort by modification time
usort($backups, fn($a, $b) => filemtime($b) - filemtime($a));

// Keep only N most recent
array_slice($backups, 0, $maxBackups);

// Delete the rest
foreach ($toDelete as $file) {
    unlink($file);
}
```

## Performance Optimizations

### View Compilation

- **Cached**: Blade views are compiled once, then cached
- **Instrumentation**: Only runs during initial compilation
- **Production**: Automatically disabled, zero overhead

### Extension

- **Lazy Loading**: Service worker only runs when needed
- **Content Script**: Minimal overhead, only tracks clicks
- **DOM Queries**: Limited to click path traversal
- **Memory**: Editor destroyed on close, no memory leaks

### Network

- **Fetch**: Single request to load source
- **Save**: Single request to save changes
- **No Polling**: Event-driven, no background requests

## Error Handling

### Laravel Side

```php
try {
    file_put_contents($path, $contents);
} catch (\Exception $e) {
    return response()->json([
        'error' => 'Failed to write file: ' . $e->getMessage()
    ], 500);
}
```

### Extension Side

```javascript
fetch(url)
    .then(response => {
        if (!response.ok) {
            throw new Error(`HTTP ${response.status}`);
        }
        return response.json();
    })
    .catch(error => {
        showNotification('Error: ' + error.message, 'error');
    });
```

## Testing Considerations

### Manual Testing Checklist

- [ ] Right-click shows "Edit in Refine" option
- [ ] Clicking menu item opens editor
- [ ] Editor loads correct source code
- [ ] Line number matches clicked element
- [ ] Save writes to correct file
- [ ] Backup created before save
- [ ] View cache cleared after save
- [ ] Page reload shows changes
- [ ] Keyboard shortcuts work (Cmd/Ctrl+S, Esc)
- [ ] Nested components resolve correctly
- [ ] Extension disabled in production
- [ ] API endpoints return 403 in production

### Automated Testing

Currently no automated tests. Future versions should include:

- **PHP Unit Tests**: Controller endpoints, instrumentation logic
- **Browser Tests**: Extension functionality via Selenium
- **Integration Tests**: Full flow from click to save

## Browser Compatibility

### Supported

- Google Chrome (latest)
- Microsoft Edge (Chromium-based, latest)

### Not Supported

- Firefox (different extension API)
- Safari (different extension API)
- Older Chrome versions (Manifest V2)

## Known Limitations

1. **Single File Editing**: Cannot edit multiple files simultaneously
2. **No Syntax Highlighting**: Plain textarea, no code highlighting
3. **No Autocomplete**: No IntelliSense or autocompletion
4. **Page Reload Required**: Changes require full page reload
5. **CSRF Dependency**: Requires CSRF token in page
6. **Local Only**: Cannot work on remote servers

## Future Enhancement Ideas

### Short-term

- Syntax highlighting via lightweight library
- Line numbers in editor
- Basic find/replace
- Auto-save draft to localStorage

### Medium-term

- Multiple file tabs
- Diff view before saving
- Component tree view
- Hot reload without full page refresh

### Long-term

- IntelliSense / autocomplete
- Blade snippet library
- Version control integration
- Multi-cursor editing
