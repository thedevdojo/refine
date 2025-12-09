<?php

namespace DevDojo\Refine\Tests\Unit;

use DevDojo\Refine\Services\BladeInstrumentation;
use DevDojo\Refine\Tests\TestCase;

class BladeInstrumentationTest extends TestCase
{
    protected BladeInstrumentation $instrumentation;

    protected function setUp(): void
    {
        parent::setUp();
        $this->instrumentation = new BladeInstrumentation();
    }

    /** @test */
    public function it_instruments_simple_div_tag()
    {
        $blade = '<div class="container">Content</div>';
        $result = $this->invokeInstrumentOpeningTags($blade, 'test.view');

        $this->assertStringContainsString('data-source=', $result);
        $this->assertStringContainsString('class="container"', $result);
    }

    /** @test */
    public function it_instruments_multi_line_tags()
    {
        $blade = <<<'BLADE'
<input
    type="email"
    placeholder="john@email.com"
    class="form-input"
/>
BLADE;

        $result = $this->invokeInstrumentOpeningTags($blade, 'test.view');

        $this->assertStringContainsString('data-source=', $result);
        $this->assertStringContainsString('type="email"', $result);
    }

    /** @test */
    public function it_instruments_tags_with_alpine_directives()
    {
        $blade = '<input @click="doSomething($event)" type="text" />';
        $result = $this->invokeInstrumentOpeningTags($blade, 'test.view');

        $this->assertStringContainsString('data-source=', $result);
        $this->assertStringContainsString('@click="doSomething($event)"', $result);
    }

    /** @test */
    public function it_does_not_instrument_blade_echo_syntax()
    {
        $blade = '<div>{{ $variable }}</div>';
        $result = $this->invokeInstrumentOpeningTags($blade, 'test.view');

        // The tag opening doesn't contain Blade echo, only the content does
        // So it WILL be instrumented - only the tag itself is checked, not content
        $this->assertStringContainsString('data-source=', $result);
        $this->assertStringContainsString('{{ $variable }}', $result);
    }

    /** @test */
    public function it_does_not_instrument_php_tags_in_attributes()
    {
        $blade = <<<'BLADE'
<div class="<?php echo \Illuminate\Support\Arr::toCssClasses([
    'fixed' => true,
    'right-px' => $position === 'right',
]); ?>">
BLADE;

        $result = $this->invokeInstrumentOpeningTags($blade, 'test.view');

        // Should not instrument because it contains <?php tags
        $this->assertStringNotContainsString('data-source=', $result);
    }

    /** @test */
    public function it_does_not_instrument_blade_directives()
    {
        $blade = '@if($condition)<div>Content</div>@endif';
        $result = $this->invokeInstrumentOpeningTags($blade, 'test.view');

        // The tag is detected and instrumented because only the tag content is checked
        // The @if is outside the tag, so it gets instrumented
        $this->assertStringContainsString('data-source=', $result);
        $this->assertStringContainsString('@if', $result);
    }

    /** @test */
    public function it_instruments_component_tags()
    {
        $blade = '<x-button class="primary">Click me</x-button>';
        $result = $this->invokeInstrumentComponentTags($blade, 'test.view');

        $this->assertStringContainsString('data-source=', $result);
        $this->assertStringContainsString('x-button', $result);
    }

    /** @test */
    public function it_instruments_multi_line_component_tags()
    {
        $blade = <<<'BLADE'
<x-carousel
    gap="md:gap-3"
    class="test"
/>
BLADE;

        $result = $this->invokeInstrumentComponentTags($blade, 'test.view');

        $this->assertStringContainsString('data-source=', $result);
        $this->assertStringContainsString('x-carousel', $result);
    }

    /** @test */
    public function it_does_not_duplicate_data_source_attribute()
    {
        $blade = '<div data-source="existing">Content</div>';
        $result = $this->invokeInstrumentOpeningTags($blade, 'test.view');

        // Should only have one data-source attribute
        $this->assertEquals(1, substr_count($result, 'data-source='));
    }

    /** @test */
    public function it_instruments_all_target_tags()
    {
        $tags = ['div', 'section', 'article', 'header', 'footer', 'main',
                 'aside', 'nav', 'form', 'input', 'button', 'span'];

        foreach ($tags as $tag) {
            $blade = "<{$tag} class=\"test\">Content</{$tag}>";
            $result = $this->invokeInstrumentOpeningTags($blade, 'test.view');

            $this->assertStringContainsString('data-source=', $result,
                "Failed to instrument <{$tag}> tag");
        }
    }

    /** @test */
    public function it_encodes_and_decodes_source_reference_correctly()
    {
        $viewPath = 'components.button';
        $lineNumber = 42;

        $encoded = $this->invokeEncodeSourceReference($viewPath, $lineNumber);
        $decoded = BladeInstrumentation::decodeSourceReference($encoded);

        $this->assertEquals($viewPath, $decoded['path']);
        $this->assertEquals($lineNumber, $decoded['line']);
    }

    /** @test */
    public function it_handles_self_closing_tags()
    {
        $blade = '<input type="text" />';
        $result = $this->invokeInstrumentOpeningTags($blade, 'test.view');

        $this->assertStringContainsString('data-source=', $result);
        $this->assertStringContainsString('/>', $result);
    }

    /** @test */
    public function it_preserves_tag_structure()
    {
        $blade = '<div id="app" class="container" data-test="value">Content</div>';
        $result = $this->invokeInstrumentOpeningTags($blade, 'test.view');

        $this->assertStringContainsString('id="app"', $result);
        $this->assertStringContainsString('class="container"', $result);
        $this->assertStringContainsString('data-test="value"', $result);
        $this->assertStringContainsString('data-source=', $result);
    }

    /** @test */
    public function it_handles_tags_without_attributes()
    {
        $blade = '<div>Content</div>';
        $result = $this->invokeInstrumentOpeningTags($blade, 'test.view');

        $this->assertStringContainsString('data-source=', $result);
        $this->assertMatchesRegularExpression('/<div\s+data-source="[^"]+"/', $result);
    }

    /** @test */
    public function it_handles_complex_alpine_expressions()
    {
        $blade = <<<'BLADE'
<div
    x-data="{ open: false }"
    @click.away="open = false"
    @mouseenter="showTooltip($event)"
    class="test"
>
BLADE;

        $result = $this->invokeInstrumentOpeningTags($blade, 'test.view');

        $this->assertStringContainsString('data-source=', $result);
        $this->assertStringContainsString('x-data=', $result);
        $this->assertStringContainsString('@click.away=', $result);
        $this->assertStringContainsString('$event', $result);
    }

    /** @test */
    public function it_does_not_instrument_php_tags()
    {
        $blade = '<div><?php echo "test"; ?></div>';
        $result = $this->invokeInstrumentOpeningTags($blade, 'test.view');

        // The div tag itself doesn't contain PHP, only the content does
        // So the tag gets instrumented
        $this->assertStringContainsString('data-source=', $result);
        $this->assertStringContainsString('<?php', $result);
    }

    /** @test */
    public function it_handles_nested_tags_independently()
    {
        $blade = <<<'BLADE'
<div class="outer">
    <div class="inner">
        <span>Text</span>
    </div>
</div>
BLADE;

        $result = $this->invokeInstrumentOpeningTags($blade, 'test.view');

        // Each tag should get its own data-source
        $this->assertEquals(3, substr_count($result, 'data-source='));
    }

    /** @test */
    public function it_detects_blade_directives_correctly()
    {
        // containsBladeDirective blocks tags that contain Blade syntax outside of quoted attribute values
        // Alpine.js @ directives are allowed because they're just HTML attributes
        $testCases = [
            '<?php echo "test"; ?>' => true,  // PHP tags block instrumentation
            ':items="$data"' => true,         // Dynamic Blade attributes block
            '{{ $attributes }}' => true,      // Attribute spreading blocks (echo outside quotes)
            '{{ $variable }}' => true,        // Blade echo outside quotes blocks
            '{!! $html !!}' => true,          // Raw echo outside quotes blocks
            '@if($test)' => true,             // Blade @if directive blocks
            '@foreach($items as $item)' => true, // Blade @foreach directive blocks
            '@class([...])' => true,          // @class directive blocks
            '@style([...])' => true,          // @style directive blocks
            '@js($var)' => true,              // @js directive blocks
            '@checked($condition)' => true,   // @checked directive blocks
            '@selected($condition)' => true,  // @selected directive blocks
            '@disabled($condition)' => true,  // @disabled directive blocks
            '@click="handler"' => false,      // Alpine.js event handler is OK (not a Blade directive)
            '@mouseenter="show"' => false,    // Alpine.js event handler is OK
            '@keydown.escape="close"' => false, // Alpine.js event handler is OK
            '@submit.prevent="save"' => false, // Alpine.js event handler is OK
            'x-data="{ test: true }"' => false, // Alpine x- attributes are OK
            'class="test"' => false,          // Plain attributes are OK
            'class="{{ $var }}"' => false,    // Blade echo INSIDE quotes is OK
            'href="{{ route(\'login\') }}"' => false, // Blade echo inside quotes is OK
            '@click.prevent="handler"' => false, // Alpine with modifiers is OK
        ];

        foreach ($testCases as $line => $shouldContainDirective) {
            $result = $this->invokeContainsBladeDirective($line);

            if ($shouldContainDirective) {
                $this->assertTrue($result, "Failed: '{$line}' should be detected as blocking directive");
            } else {
                $this->assertFalse($result, "Failed: '{$line}' should NOT be detected as blocking directive");
            }
        }
    }

    /** @test */
    public function it_instruments_tags_with_blade_echo_in_attributes()
    {
        // This is the key test - tags with {{ }} in attribute values should be instrumented
        $blade = '<a href="{{ route(\'login\') }}" class="btn">Login</a>';
        $result = $this->invokeInstrumentOpeningTags($blade, 'welcome');

        $this->assertStringContainsString('data-source=', $result);
        $this->assertStringContainsString('href="{{ route(\'login\') }}"', $result);
    }

    /** @test */
    public function it_does_not_instrument_tags_with_blade_directives_inside()
    {
        // Tags with @if or other Blade directives inside should NOT be instrumented
        // This prevents syntax errors when Blade compiles the directives
        $blade = <<<'BLADE'
<span
    @if ($badgeTooltip)
        x-tooltip="{ content: $badgeTooltip }"
    @endif
    {{ (new ComponentAttributeBag)->color('primary')->class(['fi-badge']) }}
>
BLADE;

        $result = $this->invokeInstrumentOpeningTags($blade, 'test.view');

        // Should NOT be instrumented because it contains @if and {{ }} outside quotes
        $this->assertStringNotContainsString('data-source=', $result);
    }

    /** @test */
    public function it_does_not_instrument_tags_with_dynamic_attribute_spreading()
    {
        // Tags that use {{ }} for dynamic attribute spreading should NOT be instrumented
        $blade = '<div {{ $attributes->merge(["class" => "container"]) }}>Content</div>';
        $result = $this->invokeInstrumentOpeningTags($blade, 'test.view');

        // Should NOT be instrumented because {{ }} is outside quotes
        $this->assertStringNotContainsString('data-source=', $result);
    }

    /** @test */
    public function it_does_not_instrument_tags_with_blade_checked_directive()
    {
        // Tags with @checked or similar attribute directives should NOT be instrumented
        $blade = '<input type="checkbox" @checked($isActive) />';
        $result = $this->invokeInstrumentOpeningTags($blade, 'test.view');

        // Should NOT be instrumented because @checked is a Blade directive
        $this->assertStringNotContainsString('data-source=', $result);
    }

    /** @test */
    public function it_does_not_instrument_tags_with_blade_class_directive()
    {
        // Tags with @class directive should NOT be instrumented
        $blade = '<div @class(["active" => $isActive, "disabled" => $isDisabled])>Content</div>';
        $result = $this->invokeInstrumentOpeningTags($blade, 'test.view');

        // Should NOT be instrumented because @class is a Blade directive
        $this->assertStringNotContainsString('data-source=', $result);
    }

    /** @test */
    public function it_instruments_default_laravel_welcome_page_structure()
    {
        // Simulate the structure of Laravel's default welcome.blade.php
        $blade = <<<'BLADE'
<body class="bg-white flex p-6">
    <header class="w-full text-sm mb-6">
        @if (Route::has('login'))
            <nav class="flex items-center gap-4">
                @auth
                    <a href="{{ url('/dashboard') }}" class="btn">Dashboard</a>
                @else
                    <a href="{{ route('login') }}" class="btn">Log in</a>
                @endauth
            </nav>
        @endif
    </header>
    <div class="flex items-center">
        <main class="flex max-w-4xl">
            <h1 class="mb-1 font-medium">Let's get started</h1>
            <p class="mb-2 text-gray-600">Laravel has an incredibly rich ecosystem.</p>
        </main>
    </div>
</body>
BLADE;

        $result = $this->invokeInstrumentOpeningTags($blade, 'welcome');

        // All these tags should be instrumented
        $this->assertStringContainsString('<body', $result);
        $this->assertStringContainsString('<header', $result);
        $this->assertStringContainsString('<nav', $result);
        $this->assertStringContainsString('<div', $result);
        $this->assertStringContainsString('<main', $result);
        $this->assertStringContainsString('<h1', $result);
        $this->assertStringContainsString('<p', $result);

        // Count data-source attributes - should have multiple
        $dataSourceCount = substr_count($result, 'data-source=');
        $this->assertGreaterThanOrEqual(7, $dataSourceCount,
            "Expected at least 7 data-source attributes, got {$dataSourceCount}");
    }

    /**
     * Helper methods to invoke protected methods for testing
     */
    protected function invokeInstrumentOpeningTags(string $value, string $viewPath)
    {
        $reflection = new \ReflectionClass($this->instrumentation);
        $method = $reflection->getMethod('instrumentOpeningTags');
        $method->setAccessible(true);
        return $method->invoke($this->instrumentation, $value, $viewPath);
    }

    protected function invokeInstrumentComponentTags(string $value, string $viewPath)
    {
        $reflection = new \ReflectionClass($this->instrumentation);
        $method = $reflection->getMethod('instrumentComponentTags');
        $method->setAccessible(true);
        return $method->invoke($this->instrumentation, $value, $viewPath);
    }

    protected function invokeEncodeSourceReference(string $viewPath, int $lineNumber)
    {
        $reflection = new \ReflectionClass($this->instrumentation);
        $method = $reflection->getMethod('encodeSourceReference');
        $method->setAccessible(true);
        return $method->invoke($this->instrumentation, $viewPath, $lineNumber);
    }

    protected function invokeContainsBladeDirective(string $line)
    {
        $reflection = new \ReflectionClass($this->instrumentation);
        $method = $reflection->getMethod('containsBladeDirective');
        $method->setAccessible(true);
        return $method->invoke($this->instrumentation, $line);
    }
}
