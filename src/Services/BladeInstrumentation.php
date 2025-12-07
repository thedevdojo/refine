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
        // Build a regex pattern for all target tags
        $tagPattern = implode('|', array_map(function($tag) {
            return preg_quote($tag, '/');
        }, $this->targetTags));

        // Match opening tags (including multi-line) and capture their position
        $pattern = '/<(' . $tagPattern . ')(\s+[^>]*)?>/is';

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

            // Calculate line number by counting newlines before this position
            $lineNumber = substr_count(substr($value, 0, $tagPosition), "\n") + 1;
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
     * Instrument Blade component tags (e.g., <x-alert>).
     */
    protected function instrumentComponentTags(string $value, string $viewPath): string
    {
        // Match component tags (including multi-line) and capture their position
        $pattern = '/<(x-[\w\.\-]+)(\s+[^>]*)?>/is';

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

            // Calculate line number by counting newlines before this position
            $lineNumber = substr_count(substr($value, 0, $tagPosition), "\n") + 1;
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
     * Check if a line contains Blade directives or PHP code that should not be instrumented.
     */
    protected function containsBladeDirective(string $line): bool
    {
        // Check for Blade echo syntax and PHP tags
        $patterns = [
            '/\{\{/',           // {{ Blade echo
            '/\{!!/',           // {!! Raw echo
            '/<\?php/',         // <?php
            '/<\?=/',           // <?=
            '/=>/',             // PHP array arrow operator
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $line)) {
                return true;
            }
        }

        // Check for PHP variables, but NOT inside Alpine.js attributes (which start with @ or x-)
        // Match $variable only when it appears outside of quoted strings (crude check)
        if (preg_match('/\$\w+/', $line)) {
            // If it also has Alpine directives, it's likely Alpine JS, not PHP
            if (!preg_match('/@\w+="/', $line) && !preg_match('/x-\w+="/', $line)) {
                return true;
            }
        }

        // Check for Blade component attributes like :items="$data"
        if (preg_match('/:\w+="/', $line)) {
            return true;
        }

        // Check for Blade directives, but exclude Alpine.js @ directives (which are in quotes)
        // Match @directive only when it's NOT inside an attribute value
        if (preg_match('/@(if|else|elseif|endif|unless|endunless|isset|empty|auth|guest|production|env|hasSection|sectionMissing|yield|show|section|endsection|stop|overwrite|append|prepend|once|endonce|push|endpush|pushOnce|endPushOnce|props|aware|include|includeIf|includeWhen|includeUnless|includeFirst|each|php|endphp|verbatim|endverbatim|extends|stack|json|can|cannot|canany|error|enderror|use|vite|for|foreach|endfor|endforeach|while|endwhile|break|continue|switch|case|default|endswitch|csrf|method|dd|dump)\b/', $line)) {
            return true;
        }

        return false;
    }
}
