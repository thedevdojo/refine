# Refine Tests

This directory contains automated tests for the Refine package to ensure the Blade instrumentation logic works correctly.

## Running Tests

```bash
# Run all tests
composer test

# Run tests with coverage report
composer test-coverage

# Run specific test file
vendor/bin/phpunit tests/Unit/BladeInstrumentationTest.php

# Run specific test method
vendor/bin/phpunit --filter it_instruments_multi_line_tags
```

## Test Structure

### Unit Tests (`tests/Unit/`)

Tests for individual methods and components in isolation:

- **BladeInstrumentationTest.php** - Core instrumentation logic tests
  - Basic tag instrumentation
  - Multi-line tag handling
  - Alpine.js compatibility
  - Blade directive detection
  - Component tag instrumentation
  - Source reference encoding/decoding

### Integration Tests (`tests/Integration/`)

Tests for real-world scenarios and complex use cases:

- **RealWorldScenariosTest.php** - Real examples from actual projects
  - KatanaUI hero section patterns
  - Laravel class helpers (Arr::toCssClasses)
  - Complex Alpine.js expressions
  - Form elements
  - Livewire components
  - Nested components

## Test Coverage

The test suite covers:

1. ✅ Standard HTML tags (`div`, `section`, `button`, etc.)
2. ✅ Form elements (`input`, `textarea`, `select`, `label`)
3. ✅ Multi-line tags
4. ✅ Self-closing tags (`<input />`, `<x-component />`)
5. ✅ Blade component tags (`<x-button>`, `<x-alert>`)
6. ✅ Alpine.js directives (`@click`, `x-data`, `$event`)
7. ✅ Blade echo syntax (`{{ }}`, `{!! !!}`)
8. ✅ Blade directives (`@if`, `@foreach`, etc.)
9. ✅ Blade component attributes (`:items`, `:class`)
10. ✅ PHP array syntax (`=>`)
11. ✅ Nested component structures
12. ✅ Mixed content (Blade + Alpine + HTML)

## Writing New Tests

When adding new features or fixing bugs, add corresponding tests:

```php
/** @test */
public function it_describes_what_the_test_does()
{
    $blade = '<div class="example">Content</div>';
    $result = $this->invokeInstrumentOpeningTags($blade, 'test.view');

    $this->assertStringContainsString('data-source=', $result);
}
```

### Test Naming Convention

- Use descriptive method names starting with `it_`
- Group related tests together
- Use `/** @test */` annotation

### Helper Methods

The test classes provide helper methods to test protected methods:

- `invokeInstrumentOpeningTags()` - Test HTML tag instrumentation
- `invokeInstrumentComponentTags()` - Test component tag instrumentation
- `invokeEncodeSourceReference()` - Test source encoding
- `invokeContainsBladeDirective()` - Test Blade directive detection

## Key Test Scenarios

### What SHOULD be instrumented:
- Plain HTML tags
- Tags with Alpine.js directives
- Multi-line tags
- Self-closing tags
- Component tags without Blade attributes

### What should NOT be instrumented:
- Tags containing Blade echo syntax within the tag itself
- Tags with PHP array operators (`=>`)
- Tags with Blade component attributes (`:items`, `:class`)
- Tags containing `<?php` tags

## Continuous Integration

These tests should be run:
- Before committing changes
- In CI/CD pipeline
- Before releasing new versions

## Dependencies

- PHPUnit 10.5+
- Orchestra Testbench (for Laravel package testing)
