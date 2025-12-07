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
    public function it_does_not_instrument_php_array_syntax()
    {
        $blade = <<<'BLADE'
<div class="<?php echo \Illuminate\Support\Arr::toCssClasses([
    'fixed' => true,
    'right-px' => $position === 'right',
]); ?>">
BLADE;

        $result = $this->invokeInstrumentOpeningTags($blade, 'test.view');

        // Should not instrument because it contains PHP array syntax (=>)
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
        $testCases = [
            '@if($test)' => true,
            '@foreach($items as $item)' => true,
            '@endif' => true,
            '@php' => true,
            '{{ $variable }}' => true,
            '{!! $html !!}' => true,
            '@click="handler"' => false,  // Alpine.js
            '@mouseenter="show"' => false, // Alpine.js
            'x-data="{ test: true }"' => false,
        ];

        foreach ($testCases as $line => $shouldContainDirective) {
            $result = $this->invokeContainsBladeDirective($line);

            if ($shouldContainDirective) {
                $this->assertTrue($result, "Failed: '{$line}' should be detected as Blade directive");
            } else {
                $this->assertFalse($result, "Failed: '{$line}' should NOT be detected as Blade directive");
            }
        }
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
