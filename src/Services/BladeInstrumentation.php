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

        // Instrument opening tags with source metadata
        $value = $this->instrumentOpeningTags($value, $viewPath);

        // Instrument component tags if enabled
        if ($this->instrumentComponents) {
            $value = $this->instrumentComponentTags($value, $viewPath);
        }

        return $value;
    }

    /**
     * Inject data-source attributes into HTML opening tags.
     */
    protected function instrumentOpeningTags(string $value, string $viewPath): string
    {
        $lines = explode("\n", $value);
        $result = [];

        foreach ($lines as $lineNumber => $line) {
            $actualLineNumber = $lineNumber + 1;

            // Skip lines that contain Blade/PHP directives to avoid breaking them
            if ($this->containsBladeDirective($line)) {
                $result[] = $line;
                continue;
            }

            // Check if this line contains an opening tag we want to instrument
            foreach ($this->targetTags as $tag) {
                // Match opening tags like <div>, <div class="foo">, etc.
                $pattern = '/<(' . preg_quote($tag, '/') . ')(\s+[^>]*)?>/i';

                if (preg_match($pattern, $line, $matches)) {
                    $fullTag = $matches[0];
                    $tagName = $matches[1];
                    $attributes = $matches[2] ?? '';

                    // Don't add if the tag already has the attribute
                    if (strpos($attributes, $this->attributeName) === false) {
                        $sourceRef = $this->encodeSourceReference($viewPath, $actualLineNumber);

                        // Insert the data-source attribute
                        if (trim($attributes)) {
                            $newTag = "<{$tagName}{$attributes} {$this->attributeName}=\"{$sourceRef}\">";
                        } else {
                            $newTag = "<{$tagName} {$this->attributeName}=\"{$sourceRef}\">";
                        }

                        $line = str_replace($fullTag, $newTag, $line);
                    }

                    break; // Only instrument the first tag per line
                }
            }

            $result[] = $line;
        }

        return implode("\n", $result);
    }

    /**
     * Instrument Blade component tags (e.g., <x-alert>).
     */
    protected function instrumentComponentTags(string $value, string $viewPath): string
    {
        $lines = explode("\n", $value);
        $result = [];

        foreach ($lines as $lineNumber => $line) {
            $actualLineNumber = $lineNumber + 1;

            // Skip lines that contain Blade/PHP directives to avoid breaking them
            if ($this->containsBladeDirective($line)) {
                $result[] = $line;
                continue;
            }

            // Match component tags like <x-alert>, <x-card.header>, etc.
            $pattern = '/<(x-[\w\.\-]+)(\s+[^>]*)?>/i';

            if (preg_match($pattern, $line, $matches)) {
                $fullTag = $matches[0];
                $componentName = $matches[1];
                $attributes = $matches[2] ?? '';

                // Don't add if already has the attribute
                if (strpos($attributes, $this->attributeName) === false) {
                    $sourceRef = $this->encodeSourceReference($viewPath, $actualLineNumber);

                    if (trim($attributes)) {
                        $newTag = "<{$componentName}{$attributes} {$this->attributeName}=\"{$sourceRef}\">";
                    } else {
                        $newTag = "<{$componentName} {$this->attributeName}=\"{$sourceRef}\">";
                    }

                    $line = str_replace($fullTag, $newTag, $line);
                }
            }

            $result[] = $line;
        }

        return implode("\n", $result);
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
     * Check if a line contains Blade directives or PHP code that should not be instrumented.
     */
    protected function containsBladeDirective(string $line): bool
    {
        // Check for common Blade directives and PHP tags
        $patterns = [
            '/\{\{/',           // {{ Blade echo
            '/\{!!/',           // {!! Raw echo
            '/@\w+/',           // @directive
            '/<\?php/',         // <?php
            '/<\?=/',           // <?=
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $line)) {
                return true;
            }
        }

        return false;
    }
}
