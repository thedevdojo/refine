<?php

namespace DevDojo\Refine\Tests;

use DevDojo\Refine\RefineServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app)
    {
        return [
            RefineServiceProvider::class,
        ];
    }

    protected function getEnvironmentSetUp($app)
    {
        // Set up test environment
        $app['config']->set('refine.enabled', true);
        $app['config']->set('refine.instrumentation.attribute_name', 'data-source');
        $app['config']->set('refine.instrumentation.target_tags', [
            'div', 'section', 'article', 'header', 'footer', 'main', 'aside',
            'nav', 'form', 'table', 'ul', 'ol', 'li', 'p', 'h1', 'h2', 'h3',
            'h4', 'h5', 'h6', 'button', 'a', 'span', 'input', 'textarea',
            'select', 'label'
        ]);
        $app['config']->set('refine.instrumentation.instrument_components', true);
    }
}
