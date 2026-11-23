<?php

namespace webhubworks\backup\events;

use webhubworks\backup\services\targets\TargetInterface;
use yii\base\Event;

/**
 * Fired when target drivers are resolved. Add a driver, or replace a built-in one,
 * by setting `$drivers['name'] = MyTarget::class`. The class implements
 * TargetInterface and takes the target's config array and the backup `name`
 * as constructor arguments.
 */
class RegisterTargetDriversEvent extends Event
{
    /**
     * @var array<string, class-string<TargetInterface>>
     */
    public array $drivers = [];
}
