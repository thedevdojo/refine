<?php

namespace DevDojo\Refine\Http\Controllers;

use DevDojo\Refine\Services\BladeInstrumentation;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;

class RefineController extends Controller
{
    /**
     * Fetch the source code for a given reference.
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function fetch(Request $request)
    {
        $sourceRef = $request->input('ref');

        if (!$sourceRef) {
            return response()->json(['error' => 'Missing source reference'], 400);
        }

        // Decode the source reference
        $decoded = BladeInstrumentation::decodeSourceReference($sourceRef);

        if (!$decoded) {
            return response()->json(['error' => 'Invalid source reference'], 400);
        }

        $viewPath = $decoded['path'];
        $lineNumber = $decoded['line'];

        // Resolve the view path to an absolute file path
        $filePath = BladeInstrumentation::resolveViewPath($viewPath);

        if (!$filePath || !file_exists($filePath)) {
            return response()->json(['error' => 'File not found: ' . $viewPath], 404);
        }

        // Read the entire file
        $contents = file_get_contents($filePath);
        $lines = explode("\n", $contents);

        // Calculate the region to edit
        // For now, we'll return a small context window around the target line
        $contextLines = 5;
        $startLine = max(0, $lineNumber - $contextLines - 1);
        $endLine = min(count($lines), $lineNumber + $contextLines);

        $regionLines = array_slice($lines, $startLine, $endLine - $startLine);
        $region = implode("\n", $regionLines);

        // Also return the specific target line for highlighting
        $targetLineContent = $lines[$lineNumber - 1] ?? '';

        return response()->json([
            'success' => true,
            'data' => [
                'file_path' => $filePath,
                'view_path' => $viewPath,
                'line_number' => $lineNumber,
                'full_contents' => $contents,
                'region' => $region,
                'region_start_line' => $startLine + 1,
                'region_end_line' => $endLine,
                'target_line' => $targetLineContent,
                'total_lines' => count($lines),
            ],
        ]);
    }

    /**
     * Save updated source code back to the file.
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function save(Request $request)
    {
        $sourceRef = $request->input('ref');
        $newContents = $request->input('contents');

        if (!$sourceRef) {
            return response()->json(['error' => 'Missing source reference'], 400);
        }

        if ($newContents === null) {
            return response()->json(['error' => 'Missing contents'], 400);
        }

        // Decode the source reference
        $decoded = BladeInstrumentation::decodeSourceReference($sourceRef);

        if (!$decoded) {
            return response()->json(['error' => 'Invalid source reference'], 400);
        }

        $viewPath = $decoded['path'];

        // Resolve the view path to an absolute file path
        $filePath = BladeInstrumentation::resolveViewPath($viewPath);

        if (!$filePath || !file_exists($filePath)) {
            return response()->json(['error' => 'File not found: ' . $viewPath], 404);
        }

        // Create a backup if enabled
        if (config('refine.file_writing.create_backups', true)) {
            $this->createBackup($filePath);
        }

        // Write the new contents to the file
        try {
            file_put_contents($filePath, $newContents);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Failed to write file: ' . $e->getMessage()], 500);
        }

        // Clear the view cache to ensure the changes are reflected
        $this->clearViewCache();

        // Clear response cache if present (for apps using response caching middleware)
        $this->clearResponseCache();

        return response()->json([
            'success' => true,
            'message' => 'File saved successfully',
            'data' => [
                'file_path' => $filePath,
                'view_path' => $viewPath,
                'cache_cleared' => true,
            ],
        ])->header('Cache-Control', 'no-cache, no-store, must-revalidate')
          ->header('Pragma', 'no-cache')
          ->header('Expires', '0');
    }

    /**
     * Create a backup of the file before overwriting.
     *
     * @param string $filePath
     * @return void
     */
    protected function createBackup(string $filePath): void
    {
        $backupDir = storage_path(config('refine.file_writing.backup_path', 'refine/backups'));

        if (!File::exists($backupDir)) {
            File::makeDirectory($backupDir, 0755, true);
        }

        // Create a timestamped backup
        $fileName = basename($filePath);
        $timestamp = now()->format('Y-m-d_H-i-s');
        $backupPath = $backupDir . '/' . $fileName . '.' . $timestamp . '.backup';

        File::copy($filePath, $backupPath);

        // Clean up old backups
        $this->cleanupOldBackups($backupDir, $fileName);
    }

    /**
     * Remove old backups, keeping only the most recent ones.
     *
     * @param string $backupDir
     * @param string $fileName
     * @return void
     */
    protected function cleanupOldBackups(string $backupDir, string $fileName): void
    {
        $maxBackups = config('refine.file_writing.max_backups', 10);

        // Get all backups for this file
        $backups = File::glob($backupDir . '/' . $fileName . '.*.backup');

        // Sort by modification time (newest first)
        usort($backups, function ($a, $b) {
            return filemtime($b) - filemtime($a);
        });

        // Delete old backups beyond the max limit
        $backupsToDelete = array_slice($backups, $maxBackups);

        foreach ($backupsToDelete as $backup) {
            File::delete($backup);
        }
    }

    /**
     * Clear the compiled view cache.
     *
     * @return void
     */
    protected function clearViewCache(): void
    {
        try {
            Artisan::call('view:clear');
        } catch (\Exception $e) {
            // Silently fail if view:clear doesn't work
        }
    }

    /**
     * Clear response cache (if the app uses response caching).
     *
     * @return void
     */
    protected function clearResponseCache(): void
    {
        try {
            // Clear cache using Cache facade
            if (class_exists(\Illuminate\Support\Facades\Cache::class)) {
                // Try to clear all response cache keys
                \Illuminate\Support\Facades\Cache::flush();
            }
        } catch (\Exception $e) {
            // Silently fail if cache clearing doesn't work
        }
    }

    /**
     * Get metadata about the Refine installation.
     *
     * This endpoint helps the extension verify connectivity.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function status()
    {
        return response()->json([
            'success' => true,
            'data' => [
                'enabled' => config('refine.enabled'),
                'environment' => app()->environment(),
                'version' => '1.0.0',
            ],
        ]);
    }

    /**
     * Get the history/backups for a file.
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function history(Request $request)
    {
        $sourceRef = $request->input('ref');

        if (!$sourceRef) {
            return response()->json(['error' => 'Missing source reference'], 400);
        }

        // Decode the source reference
        $decoded = BladeInstrumentation::decodeSourceReference($sourceRef);

        if (!$decoded) {
            return response()->json(['error' => 'Invalid source reference'], 400);
        }

        $viewPath = $decoded['path'];

        // Resolve the view path to an absolute file path
        $filePath = BladeInstrumentation::resolveViewPath($viewPath);

        if (!$filePath || !file_exists($filePath)) {
            return response()->json(['error' => 'File not found: ' . $viewPath], 404);
        }

        $fileName = basename($filePath);
        $backupDir = storage_path(config('refine.file_writing.backup_path', 'refine/backups'));

        $versions = [];

        // Add current version first
        $currentStats = stat($filePath);
        $versions[] = [
            'id' => 'current',
            'label' => 'Current Version',
            'timestamp' => $currentStats['mtime'],
            'date' => date('M j, Y', $currentStats['mtime']),
            'time' => date('g:i A', $currentStats['mtime']),
            'relative' => $this->getRelativeTime($currentStats['mtime']),
            'size' => $this->formatFileSize($currentStats['size']),
            'is_current' => true,
        ];

        // Get all backups for this file
        if (File::exists($backupDir)) {
            $backups = File::glob($backupDir . '/' . $fileName . '.*.backup');

            // Sort by modification time (newest first)
            usort($backups, function ($a, $b) {
                return filemtime($b) - filemtime($a);
            });

            foreach ($backups as $index => $backupPath) {
                $stats = stat($backupPath);
                $backupName = basename($backupPath);

                // Extract timestamp from filename (format: filename.blade.php.2024-12-07_15-30-45.backup)
                preg_match('/\.(\d{4}-\d{2}-\d{2}_\d{2}-\d{2}-\d{2})\.backup$/', $backupName, $matches);
                $timestampStr = $matches[1] ?? '';

                $versions[] = [
                    'id' => base64_encode($backupPath),
                    'label' => 'Version ' . (count($backups) - $index),
                    'timestamp' => $stats['mtime'],
                    'date' => date('M j, Y', $stats['mtime']),
                    'time' => date('g:i A', $stats['mtime']),
                    'relative' => $this->getRelativeTime($stats['mtime']),
                    'size' => $this->formatFileSize($stats['size']),
                    'is_current' => false,
                ];
            }
        }

        return response()->json([
            'success' => true,
            'data' => [
                'file_path' => $filePath,
                'view_path' => $viewPath,
                'versions' => $versions,
            ],
        ]);
    }

    /**
     * Get the contents of a specific backup version.
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function getVersion(Request $request)
    {
        $versionId = $request->input('version_id');

        if (!$versionId) {
            return response()->json(['error' => 'Missing version ID'], 400);
        }

        if ($versionId === 'current') {
            return response()->json(['error' => 'Use fetch endpoint for current version'], 400);
        }

        // Decode the backup path
        $backupPath = base64_decode($versionId);

        if (!$backupPath || !file_exists($backupPath)) {
            return response()->json(['error' => 'Backup not found'], 404);
        }

        // Verify it's in the backup directory for security
        $backupDir = storage_path(config('refine.file_writing.backup_path', 'refine/backups'));
        if (strpos(realpath($backupPath), realpath($backupDir)) !== 0) {
            return response()->json(['error' => 'Invalid backup path'], 403);
        }

        $contents = file_get_contents($backupPath);

        return response()->json([
            'success' => true,
            'data' => [
                'contents' => $contents,
            ],
        ]);
    }

    /**
     * Get a human-readable relative time string.
     *
     * @param int $timestamp
     * @return string
     */
    protected function getRelativeTime(int $timestamp): string
    {
        $diff = time() - $timestamp;

        if ($diff < 60) {
            return 'Just now';
        } elseif ($diff < 3600) {
            $mins = floor($diff / 60);
            return $mins . ' min' . ($mins > 1 ? 's' : '') . ' ago';
        } elseif ($diff < 86400) {
            $hours = floor($diff / 3600);
            return $hours . ' hour' . ($hours > 1 ? 's' : '') . ' ago';
        } elseif ($diff < 604800) {
            $days = floor($diff / 86400);
            return $days . ' day' . ($days > 1 ? 's' : '') . ' ago';
        } else {
            $weeks = floor($diff / 604800);
            return $weeks . ' week' . ($weeks > 1 ? 's' : '') . ' ago';
        }
    }

    /**
     * Format file size in human readable format.
     *
     * @param int $bytes
     * @return string
     */
    protected function formatFileSize(int $bytes): string
    {
        if ($bytes < 1024) {
            return $bytes . ' B';
        } elseif ($bytes < 1048576) {
            return round($bytes / 1024, 1) . ' KB';
        } else {
            return round($bytes / 1048576, 1) . ' MB';
        }
    }
}
