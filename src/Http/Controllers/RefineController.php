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

        return response()->json([
            'success' => true,
            'message' => 'File saved successfully',
            'data' => [
                'file_path' => $filePath,
                'view_path' => $viewPath,
            ],
        ]);
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
}
