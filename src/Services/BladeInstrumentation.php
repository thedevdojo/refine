<?php

namespace DevDojo\Refine\Services;

use Illuminate\Support\Facades\Blade;
use Illuminate\View\Compilers\BladeCompiler;

class BladeInstrumentation
{
    protected array $targetTags;
    protected string $attributeName;
    protected bool $instrumentComponents;

    public function __construct()
    {
        $this->targetTags = config('refine.instrumentation.target_tags', []);
        $this->attributeName = config('refine.instrumentation.attribute_name', 'data-source');
        $this->instrumentComponents = config('refine.instrumentation.instrument_components', true);
    }

    /**
     * Register the Blade compiler extension that injects source metadata.
     */
    public function register(): void
    {
        if (!config('refine.enabled')) {
            return;
        }

        // Hook into the Blade compiler to inject source attributes
        Blade::extend(function ($view, BladeCompiler $compiler) {
            return $this->instrumentBladeView($view, $compiler);
        });
    }

    /**
     * Instrument a Blade view by injecting data-source attributes.
     */
    protected function instrumentBladeView(string $value, BladeCompiler $compiler): string
    {
        // Get the current view path being compiled
        $viewPath = $this->getCurrentViewPath($compiler);

        if (!$viewPath) {
            return $value;
        }

        // Get the absolute file path for accurate line number calculation
        $absolutePath = $this->getAbsolutePath($compiler);
        $originalContent = $absolutePath && file_exists($absolutePath)
            ? file_get_contents($absolutePath)
            : null;

        // Instrument opening tags with source metadata
        $value = $this->instrumentOpeningTags($value, $viewPath, $originalContent);

        // Instrument component tags if enabled
        if ($this->instrumentComponents) {
            $value = $this->instrumentComponentTags($value, $viewPath, $originalContent);
        }

        return $value;
    }

    /**
     * Get the absolute file path from the compiler.
     */
    protected function getAbsolutePath(BladeCompiler $compiler): ?string
    {
        try {
            $reflection = new \ReflectionClass($compiler);
            $property = $reflection->getProperty('path');
            $property->setAccessible(true);
            return $property->getValue($compiler);
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Inject data-source attributes into HTML opening tags.
     */
    protected function instrumentOpeningTags(string $value, string $viewPath, ?string $originalContent = null): string
    {
        // Build a regex pattern for all target tags
        $tagPattern = implode('|', array_map(function($tag) {
            return preg_quote($tag, '/');
        }, $this->targetTags));

        // Match opening tags (including multi-line) and capture their position
        // The pattern properly handles > characters inside quoted attribute values
        // by matching quoted strings as units: "..." or '...'
        $pattern = '/<(' . $tagPattern . ')(\s+(?:[^>"\']|"[^"]*"|\'[^\']*\')*)?>/is';

        $offset = 0;
        while (preg_match($pattern, $value, $matches, PREG_OFFSET_CAPTURE, $offset)) {
            $fullTag = $matches[0][0];
            $tagPosition = $matches[0][1];
            $tagName = $matches[1][0];
            $attributes = isset($matches[2]) ? $matches[2][0] : '';

            // Check if this tag already has the data-source attribute
            if (strpos($attributes, $this->attributeName) !== false) {
                $offset = $tagPosition + strlen($fullTag);
                continue;
            }

            // Check if the matched tag contains Blade/PHP code that could break
            if ($this->containsBladeDirective($fullTag)) {
                $offset = $tagPosition + strlen($fullTag);
                continue;
            }

            // Calculate line number - use original content if available for accuracy
            $lineNumber = $this->calculateLineNumber($fullTag, $tagPosition, $value, $originalContent);
            $sourceRef = $this->encodeSourceReference($viewPath, $lineNumber);

            // Insert the data-source attribute right before the closing > or />
            // Handle self-closing tags
            if (str_ends_with(rtrim($fullTag), '/>')) {
                $tagWithoutClosing = rtrim(substr($fullTag, 0, -2));
                $newTag = $tagWithoutClosing . ' ' . $this->attributeName . '="' . $sourceRef . '" />';
            } else {
                $tagWithoutClosing = rtrim($fullTag, '>');
                $newTag = $tagWithoutClosing . ' ' . $this->attributeName . '="' . $sourceRef . '">';
            }

            // Replace in the value
            $value = substr_replace($value, $newTag, $tagPosition, strlen($fullTag));

            // Move offset forward
            $offset = $tagPosition + strlen($newTag);
        }

        return $value;
    }

    /**
     * Calculate the correct line number for a tag.
     * Uses original file content when available for accurate line numbers.
     */
    protected function calculateLineNumber(string $fullTag, int $tagPosition, string $value, ?string $originalContent): int
    {
        // If we have original content, find the tag there for accurate line number
        if ($originalContent !== null) {
            // Try to find this exact tag in the original content
            $tagStart = strpos($originalContent, $fullTag);
            if ($tagStart !== false) {
                return substr_count(substr($originalContent, 0, $tagStart), "\n") + 1;
            }

            // If exact match fails, try matching just the tag opening (first 100 chars)
            // This helps when attributes might have been modified
            $tagSignature = substr($fullTag, 0, min(100, strlen($fullTag)));
            $tagStart = strpos($originalContent, $tagSignature);
            if ($tagStart !== false) {
                return substr_count(substr($originalContent, 0, $tagStart), "\n") + 1;
            }
        }

        // Fallback: count newlines in the partial content
        return substr_count(substr($value, 0, $tagPosition), "\n") + 1;
    }

    /**
     * Instrument Blade component tags (e.g., <x-alert>).
     */
    protected function instrumentComponentTags(string $value, string $viewPath, ?string $originalContent = null): string
    {
        // Match component tags (including multi-line) and capture their position
        // The pattern properly handles > characters inside quoted attribute values
        $pattern = '/<(x-[\w\.\-]+)(\s+(?:[^>"\']|"[^"]*"|\'[^\']*\')*)?>/is';

        $offset = 0;
        while (preg_match($pattern, $value, $matches, PREG_OFFSET_CAPTURE, $offset)) {
            $fullTag = $matches[0][0];
            $tagPosition = $matches[0][1];
            $componentName = $matches[1][0];
            $attributes = isset($matches[2]) ? $matches[2][0] : '';

            // Check if this tag already has the data-source attribute
            if (strpos($attributes, $this->attributeName) !== false) {
                $offset = $tagPosition + strlen($fullTag);
                continue;
            }

            // Check if the matched tag contains Blade/PHP code that could break
            if ($this->containsBladeDirective($fullTag)) {
                $offset = $tagPosition + strlen($fullTag);
                continue;
            }

            // Calculate line number - use original content if available for accuracy
            $lineNumber = $this->calculateLineNumber($fullTag, $tagPosition, $value, $originalContent);
            $sourceRef = $this->encodeSourceReference($viewPath, $lineNumber);

            // Insert the data-source attribute right before the closing > or />
            // Handle self-closing tags
            if (str_ends_with(rtrim($fullTag), '/>')) {
                $tagWithoutClosing = rtrim(substr($fullTag, 0, -2));
                $newTag = $tagWithoutClosing . ' ' . $this->attributeName . '="' . $sourceRef . '" />';
            } else {
                $tagWithoutClosing = rtrim($fullTag, '>');
                $newTag = $tagWithoutClosing . ' ' . $this->attributeName . '="' . $sourceRef . '">';
            }

            // Replace in the value
            $value = substr_replace($value, $newTag, $tagPosition, strlen($fullTag));

            // Move offset forward
            $offset = $tagPosition + strlen($newTag);
        }

        return $value;
    }

    /**
     * Encode a source reference (view path + line number) into a safe string.
     */
    protected function encodeSourceReference(string $viewPath, int $lineNumber): string
    {
        return base64_encode(json_encode([
            'path' => $viewPath,
            'line' => $lineNumber,
        ]));
    }

    /**
     * Decode a source reference back into path and line number.
     */
    public static function decodeSourceReference(string $encoded): ?array
    {
        try {
            $decoded = json_decode(base64_decode($encoded), true);

            if (isset($decoded['path']) && isset($decoded['line'])) {
                return $decoded;
            }
        } catch (\Exception $e) {
            // Invalid encoding
        }

        return null;
    }

    /**
     * Get the current view path being compiled.
     */
    protected function getCurrentViewPath(BladeCompiler $compiler): ?string
    {
        // Use reflection to get the current file being compiled
        try {
            $reflection = new \ReflectionClass($compiler);
            $property = $reflection->getProperty('path');
            $property->setAccessible(true);
            $path = $property->getValue($compiler);

            if ($path) {
                // Convert absolute path to relative view path
                return $this->convertToViewPath($path);
            }
        } catch (\Exception $e) {
            // Fallback: couldn't determine path
        }

        return null;
    }

    /**
     * Convert an absolute file path to a Laravel view path.
     */
    protected function convertToViewPath(string $absolutePath): string
    {
        // Get all registered view paths
        $viewPaths = config('view.paths', [resource_path('views')]);

        foreach ($viewPaths as $viewPath) {
            $viewPath = rtrim($viewPath, '/');

            if (strpos($absolutePath, $viewPath) === 0) {
                $relativePath = substr($absolutePath, strlen($viewPath) + 1);
                $relativePath = str_replace('.blade.php', '', $relativePath);
                return str_replace('/', '.', $relativePath);
            }
        }

        // Fallback: return the filename
        return basename($absolutePath, '.blade.php');
    }

    /**
     * Convert a Laravel view path back to an absolute file path.
     */
    public static function resolveViewPath(string $viewPath): ?string
    {
        $viewPaths = config('view.paths', [resource_path('views')]);

        // Convert dot notation to file path
        $filePath = str_replace('.', '/', $viewPath) . '.blade.php';

        foreach ($viewPaths as $basePath) {
            $fullPath = rtrim($basePath, '/') . '/' . $filePath;

            if (file_exists($fullPath)) {
                return $fullPath;
            }
        }

        return null;
    }

    /**
     * Check if a tag contains Blade directives or PHP code that would break instrumentation.
     *
     * We're more permissive now - we only skip tags where Blade syntax could break
     * the HTML structure. Blade syntax inside attribute VALUES is fine.
     */
    protected function containsBladeDirective(string $tag): bool
    {
        // Only skip if there are structural issues that would break our injection

        // Check for PHP tags that aren't inside attribute values
        // These could break the tag structure
        if (preg_match('/<\?(?:php|=)/', $tag)) {
            return true;
        }

        // Check for Blade component dynamic attributes like :items="$data"
        // These are compiled to PHP and could break our attribute injection
        if (preg_match('/(?:^|\s):\w+\s*=/', $tag)) {
            return true;
        }

        // Check for dynamic attribute spreading {{ $attributes }}
        if (preg_match('/\{\{\s*\$attributes/', $tag)) {
            return true;
        }

        // Check for @class and @style directives which contain => arrows
        // The > in => can break our tag matching regex
        if (preg_match('/@(?:class|style)\s*\(/', $tag)) {
            return true;
        }

        // Blade echo syntax {{ }} and {!! !!} inside attribute values is FINE
        // We only care if it's in the tag structure itself (outside quotes)
        // For now, allow all tags with {{ }} since they're usually in attribute values

        return false;
    }
}
