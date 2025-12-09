<?php

namespace DevDojo\Refine\Tests\Integration;

use DevDojo\Refine\Services\BladeInstrumentation;
use DevDojo\Refine\Tests\TestCase;

class RealWorldScenariosTest extends TestCase
{
    protected BladeInstrumentation $instrumentation;

    protected function setUp(): void
    {
        parent::setUp();
        $this->instrumentation = new BladeInstrumentation();
    }

    /** @test */
    public function it_handles_katanaui_hero_section_input()
    {
        // Real example from katanaui hero section
        $blade = <<<'BLADE'
<input
    @mouseenter="showInputTooltip($event)"
    @mouseleave="hideInputTooltip()"
    @mousemove="currentInputMouseEvent = $event; moveTooltip($event, inputTooltip, $event.currentTarget)"
    type="email"
    placeholder="john@email.com"
    class="placeholder:text-stone-300 text-foreground bg-background"
/>
BLADE;

        $result = $this->invokeInstrumentOpeningTags($blade, 'home.sections.hero');

        // Should instrument despite Alpine.js directives with $event
        $this->assertStringContainsString('data-source=', $result);
        $this->assertStringContainsString('@mouseenter=', $result);
        $this->assertStringContainsString('$event', $result);
    }

    /** @test */
    public function it_does_not_instrument_blade_component_attributes()
    {
        $blade = <<<'BLADE'
<x-carousel :items="[
    ['title' => 'Slide 1', 'image' => '/images/carousel/mountains.jpg'],
    ['title' => 'Slide 2', 'image' => '/images/carousel/forest.jpg'],
]" gap="md:gap-3" />
BLADE;

        $result = $this->invokeInstrumentComponentTags($blade, 'home.sections.hero');

        // Should NOT instrument because it has :items (Blade component attribute)
        $this->assertStringNotContainsString('data-source=', $result);
        $this->assertStringContainsString('x-carousel', $result);
        $this->assertStringContainsString(':items=', $result);
    }

    /** @test */
    public function it_handles_laravel_class_helpers_correctly()
    {
        // Real example from katanaui that previously broke
        $blade = <<<'BLADE'
<div class="<?php echo \Illuminate\Support\Arr::toCssClasses([
    'fixed top-0 w-[42px]',
    'right-px border-l' => $position === 'right',
    'left-px border-r' => $position === 'left',
]); ?>">
BLADE;

        $result = $this->invokeInstrumentOpeningTags($blade, 'home.dot-matrix');

        // Should NOT instrument because it contains PHP array syntax
        $this->assertStringNotContainsString('data-source=', $result);
        // Should preserve the original structure
        $this->assertStringContainsString('=>', $result);
        $this->assertStringContainsString('Arr::toCssClasses', $result);
    }

    /** @test */
    public function it_handles_livewire_components()
    {
        $blade = <<<'BLADE'
<livewire:user-profile
    :user="$user"
    wire:key="profile-{{ $user->id }}"
/>
BLADE;

        $result = $this->invokeInstrumentOpeningTags($blade, 'components.profile');

        // Livewire tags are not x- components, so they won't be instrumented by componentTags
        // but the opening tag detection should handle them if 'livewire' was in target_tags
        // For now, this tests that it doesn't break
        $this->assertIsString($result);
    }

    /** @test */
    public function it_handles_complex_alpine_data_initialization()
    {
        $blade = <<<'BLADE'
<div x-data="{
    hoveredInput: false,
    hoveredButton: false,
    initTooltips() {
        this.inputTooltip = this.$refs.inputTooltip;
        gsap.set(this.inputTooltip, { autoAlpha: 0 });
    }
}"
x-init="initTooltips()">
BLADE;

        $result = $this->invokeInstrumentOpeningTags($blade, 'test.view');

        $this->assertStringContainsString('data-source=', $result);
        $this->assertStringContainsString('x-data=', $result);
        $this->assertStringContainsString('$refs', $result);
    }

    /** @test */
    public function it_handles_blade_foreach_with_html()
    {
        $blade = <<<'BLADE'
@foreach($items as $item)
    <div class="item">{{ $item->name }}</div>
@endforeach
BLADE;

        $result = $this->invokeInstrumentOpeningTags($blade, 'test.view');

        // The lines with @foreach/@endforeach should not be instrumented
        // But the div inside might be, depending on if it's on same line as directive
        $this->assertStringContainsString('data-source=', $result);
        $this->assertStringContainsString('@foreach', $result);
    }

    /** @test */
    public function it_handles_blade_if_conditions()
    {
        $blade = <<<'BLADE'
@if($condition)
    <div class="success">Success!</div>
@else
    <div class="error">Error!</div>
@endif
BLADE;

        $result = $this->invokeInstrumentOpeningTags($blade, 'test.view');

        // Lines with @if/@else/@endif should not be instrumented
        // But divs on separate lines should be
        $this->assertStringContainsString('data-source=', $result);
        $this->assertEquals(2, substr_count($result, 'data-source='));
    }

    /** @test */
    public function it_handles_slots_and_named_slots()
    {
        $blade = <<<'BLADE'
<x-card>
    <x-slot:header>
        <h2>Title</h2>
    </x-slot:header>

    <div class="content">
        Main content
    </div>
</x-card>
BLADE;

        $result = $this->invokeInstrumentComponentTags($blade, 'test.view');

        $this->assertStringContainsString('data-source=', $result);
        $this->assertStringContainsString('x-card', $result);
        $this->assertStringContainsString('x-slot:header', $result);
    }

    /** @test */
    public function it_preserves_javascript_in_attributes()
    {
        $blade = <<<'BLADE'
<button
    @click="
        if (confirm('Are you sure?')) {
            deleteItem($event.target.dataset.id);
        }
    "
    type="button"
>
</button>
BLADE;

        $result = $this->invokeInstrumentOpeningTags($blade, 'test.view');

        $this->assertStringContainsString('data-source=', $result);
        $this->assertStringContainsString("confirm('Are you sure?')", $result);
        $this->assertStringContainsString('deleteItem', $result);
    }

    /** @test */
    public function it_handles_multiple_components_in_single_file()
    {
        $blade = <<<'BLADE'
<x-alert variant="success" />
<x-button class="primary">Click</x-button>
<x-card>
    <x-card.header>Header</x-card.header>
    <x-card.body>Body</x-card.body>
</x-card>
BLADE;

        $result = $this->invokeInstrumentComponentTags($blade, 'test.view');

        // Should have instrumented all 5 component tags
        $this->assertEquals(5, substr_count($result, 'data-source='));
    }

    /** @test */
    public function it_handles_form_elements()
    {
        $blade = <<<'BLADE'
<form action="/submit" method="POST">
    <label for="email">Email</label>
    <input type="email" id="email" name="email" />

    <label for="message">Message</label>
    <textarea id="message" name="message"></textarea>

    <select name="country">
        <option value="us">United States</option>
    </select>

    <button type="submit">Submit</button>
</form>
BLADE;

        $result = $this->invokeInstrumentOpeningTags($blade, 'test.view');

        // All form elements should be instrumented
        $this->assertGreaterThanOrEqual(7, substr_count($result, 'data-source='));
        $this->assertStringContainsString('<form', $result);
        $this->assertStringContainsString('<label', $result);
        $this->assertStringContainsString('<input', $result);
        $this->assertStringContainsString('<textarea', $result);
        $this->assertStringContainsString('<select', $result);
        $this->assertStringContainsString('<button', $result);
    }

    /** @test */
    public function it_handles_filament_dropdown_item_component()
    {
        // This test replicates the exact structure that was causing the error:
        // A span tag with @if conditionals and {{ }} for dynamic attribute spreading
        // From: vendor/filament/components/dropdown/list/item.blade.php
        $blade = <<<'BLADE'
@if (filled($badge))
    @if ($badge instanceof \Illuminate\View\ComponentSlot)
        {{ $badge }}
    @else
        <span
            @if ($badgeTooltip)
                x-tooltip="{
                    content: @js($badgeTooltip),
                    theme: $store.theme,
                    allowHTML: @js($badgeTooltip instanceof \Illuminate\Contracts\Support\Htmlable),
                }"
            @endif
            {{ (new ComponentAttributeBag)->color(BadgeComponent::class, $badgeColor)->class(['fi-badge']) }}
        >
            {{ $badge }}
        </span>
    @endif
@endif
BLADE;

        $result = $this->invokeInstrumentOpeningTags($blade, 'vendor.filament.components.dropdown.list.item');

        // The span tag should NOT be instrumented because it contains @if and {{ }} outside quotes
        // This verifies we don't corrupt the PHP code that was causing "syntax error, unexpected token '='"
        $this->assertStringNotContainsString('data-source=', $result);

        // Ensure the original code is preserved unchanged
        $this->assertStringContainsString('@if ($badgeTooltip)', $result);
        $this->assertStringContainsString('{{ (new ComponentAttributeBag)->color(BadgeComponent::class, $badgeColor)->class([\'fi-badge\']) }}', $result);
    }

    /** @test */
    public function it_handles_filament_icon_generation_with_named_parameters()
    {
        // This tests the generate_icon_html call which uses PHP 8 named parameters
        // The code should not be corrupted by instrumentation
        $blade = <<<'BLADE'
@if ($icon)
    {{
        \Filament\Support\generate_icon_html($icon, $iconAlias, (new ComponentAttributeBag([
            'wire:loading.remove.delay.' . config('filament.livewire_loading_delay', 'default') => $hasLoadingIndicator,
            'wire:target' => $hasLoadingIndicator ? $loadingIndicatorTarget : false,
        ]))->color(IconComponent::class, $iconColor), size: $iconSize)
    }}
@endif
BLADE;

        $result = $this->invokeInstrumentOpeningTags($blade, 'vendor.filament.components.dropdown.list.item');

        // The Blade {{ }} blocks should not be modified
        // This verifies we preserve PHP named parameters like "size: $iconSize"
        $this->assertStringContainsString('size: $iconSize', $result);
        $this->assertStringContainsString('generate_icon_html', $result);
    }

    /** @test */
    public function it_handles_complex_filament_attribute_bag_usage()
    {
        // Tests complex attribute bag usage in Filament components
        $blade = <<<'BLADE'
<div
    {{
        $attributes
            ->when(
                $tag === 'form',
                fn (ComponentAttributeBag $attributes) => $attributes->except(['action', 'class', 'method']),
            )
            ->merge([
                'aria-disabled' => $disabled ? 'true' : null,
                'disabled' => $disabled && blank($tooltip),
            ], escape: false)
            ->class([
                'fi-dropdown-list-item',
                'fi-disabled' => $disabled,
            ])
            ->color(ItemComponent::class, $color)
    }}
>
BLADE;

        $result = $this->invokeInstrumentOpeningTags($blade, 'vendor.filament.components.item');

        // Should NOT be instrumented because it has {{ }} outside quotes
        $this->assertStringNotContainsString('data-source=', $result);

        // Should preserve PHP named parameters
        $this->assertStringContainsString('escape: false', $result);
    }

    /** @test */
    public function it_does_not_instrument_x_components_with_blade_conditionals()
    {
        // X-components with conditional attributes should not be instrumented
        $blade = <<<'BLADE'
<x-button
    @if ($showIcon)
        icon="heroicon-o-check"
    @endif
    {{ $attributes }}
>
    Click me
</x-button>
BLADE;

        $result = $this->invokeInstrumentComponentTags($blade, 'components.button');

        // Should NOT be instrumented because it has @if and {{ }} outside quotes
        $this->assertStringNotContainsString('data-source=', $result);
    }

    /** @test */
    public function it_instruments_simple_x_components()
    {
        // Simple X-components should still be instrumented
        $blade = '<x-button type="submit" class="btn-primary">Submit</x-button>';
        $result = $this->invokeInstrumentComponentTags($blade, 'components.form');

        // Should be instrumented - no Blade syntax outside quotes
        $this->assertStringContainsString('data-source=', $result);
    }

    /** @test */
    public function it_instruments_x_components_with_blade_in_quotes()
    {
        // X-components with Blade echo INSIDE quotes should still be instrumented
        $blade = '<x-button type="{{ $type }}" class="{{ $class }}">{{ $label }}</x-button>';
        $result = $this->invokeInstrumentComponentTags($blade, 'components.form');

        // Should be instrumented - Blade syntax is inside quotes
        $this->assertStringContainsString('data-source=', $result);
    }

    /**
     * Helper method to invoke protected method for testing
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
}
