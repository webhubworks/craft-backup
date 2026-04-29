<?php

namespace webhubworks\backup\twigextensions;

use Carbon\Carbon;
use Craft;
use DateTimeInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

class BackupTwigExtension extends AbstractExtension
{
    public function getFunctions(): array
    {
        return [
            new TwigFunction('diffForHumans', [$this, 'diffForHumans']),
        ];
    }

    public function diffForHumans(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        if ($value instanceof DateTimeInterface) {
            $carbon = Carbon::instance($value);
        } elseif (is_numeric($value)) {
            $carbon = Carbon::createFromTimestamp((int) $value);
        } else {
            $carbon = Carbon::parse((string) $value);
        }

        return $carbon->locale(Craft::$app->language ?: 'en')->diffForHumans();
    }
}
