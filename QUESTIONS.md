# Refine - Questions & Answers

Common questions about how Refine works internally, architectural decisions, and implementation details.

---

## Table of Contents

1. [How are files saved? Does this happen through the composer package?](#q1-how-are-files-saved)

---

## Q1: How are files saved? Does this happen through the composer package?

**Short Answer:** Yes! File saving happens entirely through the **Laravel Composer package**, specifically the `RefineController`. The Chrome extension cannot write files (browser security restriction), so it sends the edited code via HTTP POST to Laravel, which writes to disk.

### Complete Save Flow (Step-by-Step)

#### 1. User Initiates Save (Browser)
```javascript
// In content.js - User clicks Save or presses Cmd/Ctrl+S
saveButton.onclick = () => {
    const newContents = textarea.value;

    // Extension sends POST request to Laravel
    saveSource(sourceRef, newContents)
};
```

#### 2. Extension Sends Data to Laravel
```javascript
// content.js sends this POST request
async function saveSource(sourceRef, contents) {
    const response = await fetch('/refine/save', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': getCSRFToken(),
        },
        body: JSON.stringify({
            ref: sourceRef,        // Encoded view path + line number
            contents: contents     // Full file contents (edited)
        }),
    });
}
```

#### 3. Laravel Receives Request
The request hits the route defined in `RefineServiceProvider.php`:

```php
// Routes automatically registered by the package
Route::post('/refine/save', [RefineController::class, 'save']);
```

#### 4. Controller Processes Save
`src/Http/Controllers/RefineController.php` handles everything:

```php
public function save(Request $request)
{
    // Get the data from the extension
    $sourceRef = $request->input('ref');
    $newContents = $request->input('contents');

    // Decode the reference to get file path
    $decoded = BladeInstrumentation::decodeSourceReference($sourceRef);
    // Example: ['path' => 'components.alert', 'line' => 42]

    // Resolve view path to absolute file path
    $filePath = BladeInstrumentation::resolveViewPath($decoded['path']);
    // Example: /var/www/resources/views/components/alert.blade.php

    // Create backup BEFORE overwriting
    if (config('refine.file_writing.create_backups', true)) {
        $this->createBackup($filePath);
    }

    // WRITE TO DISK - This is where the save happens!
    file_put_contents($filePath, $newContents);

    // Clear Laravel's view cache
    Artisan::call('view:clear');

    // Send success response back to extension
    return response()->json([
        'success' => true,
        'message' => 'File saved successfully'
    ]);
}
```

#### 5. Backup System (Before Write)

```php
protected function createBackup(string $filePath): void
{
    $backupDir = storage_path('refine/backups');

    // Create timestamped backup
    $fileName = basename($filePath);
    $timestamp = now()->format('Y-m-d_H-i-s');
    $backupPath = $backupDir . '/' . $fileName . '.' . $timestamp . '.backup';

    // Copy original file to backup location
    File::copy($filePath, $backupPath);

    // Example backup:
    // storage/refine/backups/alert.blade.php.2024-12-06_14-30-15.backup
}
```

#### 6. Cache Clearing

```php
protected function clearViewCache(): void
{
    // Clear compiled Blade views so changes are visible
    Artisan::call('view:clear');

    // This deletes files in storage/framework/views/
}
```

#### 7. Extension Receives Response & Reloads

```javascript
// Back in content.js
saveSource(sourceRef, newContents)
    .then(() => {
        showNotification('Saved successfully!', 'success');
        closeEditor();

        // Reload page to show changes
        setTimeout(() => {
            window.location.reload();
        }, 500);
    });
```

### Visual Flow Diagram

```
┌─────────────────────────────────────────────────────────────────┐
│ BROWSER (Chrome Extension)                                      │
├─────────────────────────────────────────────────────────────────┤
│ 1. User clicks Save                                             │
│ 2. content.js sends POST /refine/save with:                     │
│    - ref: "eyJwYXRoIjoi..." (encoded source reference)          │
│    - contents: "full edited file contents..."                   │
└────────────────────────┬────────────────────────────────────────┘
                         │ HTTP POST
                         │
                         ▼
┌─────────────────────────────────────────────────────────────────┐
│ LARAVEL (Composer Package: devdojo/refine)                      │
├─────────────────────────────────────────────────────────────────┤
│ 3. Request hits RefineMiddleware                                │
│    → Verifies APP_ENV=local ✓                                   │
│                                                                  │
│ 4. Request routed to RefineController::save()                   │
│                                                                  │
│ 5. Decode source reference                                      │
│    eyJwYXRoIjoi... → {path: "components.alert", line: 42}       │
│                                                                  │
│ 6. Resolve view path to absolute path                           │
│    "components.alert" → /var/www/resources/views/...blade.php   │
│                                                                  │
│ 7. Create backup                                                │
│    Copy to: storage/refine/backups/alert.blade.php.timestamp    │
│                                                                  │
│ 8. ★ WRITE FILE TO DISK ★                                       │
│    file_put_contents($filePath, $newContents)                   │
│                                                                  │
│ 9. Clear view cache                                             │
│    php artisan view:clear                                       │
│                                                                  │
│ 10. Return success JSON response                                │
└────────────────────────┬────────────────────────────────────────┘
                         │ HTTP 200 OK
                         │
                         ▼
┌─────────────────────────────────────────────────────────────────┐
│ BROWSER (Chrome Extension)                                      │
├─────────────────────────────────────────────────────────────────┤
│ 11. Show success notification                                   │
│ 12. Close editor                                                │
│ 13. Reload page → New code visible!                             │
└─────────────────────────────────────────────────────────────────┘
```

### Key Points

#### The Chrome Extension CANNOT Write Files
- Browser extensions have **no file system access** for security
- They can only send HTTP requests
- All file operations must happen server-side

#### The Laravel Package Does All File Writing
- **RefineController** receives the edited code
- Uses PHP's `file_put_contents()` to write directly to disk
- Has full file system access (server-side)
- Creates backups before overwriting

#### Why This Architecture?

1. **Security**: Browser can't access your file system
2. **Permissions**: Server already has write access to views
3. **Backups**: Server can create timestamped backups
4. **Cache Clearing**: Server can run Artisan commands
5. **Validation**: Server can validate paths, check permissions, etc.

### File Locations

**Before Save:**
```
resources/views/components/alert.blade.php
(Original content)
```

**During Save:**
```
1. Create backup:
   storage/refine/backups/alert.blade.php.2024-12-06_14-30-15.backup

2. Write new content:
   resources/views/components/alert.blade.php
   (Updated content from browser)

3. Clear cache:
   Delete: storage/framework/views/xyz123_alert.php
```

**After Save:**
```
✓ Original backed up
✓ New content written
✓ Cache cleared
✓ Page reload shows changes
```

**Summary:** 100% of file writing happens through the Composer package. The Chrome extension is just the UI that sends the data—all the heavy lifting (file writes, backups, cache clearing) is done by Laravel on the server side.

---

*Have more questions? Add them here!*
