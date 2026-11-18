<?php

require_once __DIR__ . '/Unit/FakeTarget.php';

// The test app doesn't install the plugin, so register its translation category by hand.
// In a hook, because Pest.php loads before Craft boots in parallel runs.
uses()->beforeEach(function() {
    Craft::$app->getI18n()->translations['backup'] = [
        'class' => craft\i18n\PhpMessageSource::class,
        'sourceLanguage' => 'en',
        'basePath' => dirname(__DIR__) . '/src/translations',
    ];
})->in('Unit', 'Integration');

uses(markhuot\craftpest\test\TestCase::class)->in('Integration');
