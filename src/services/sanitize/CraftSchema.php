<?php

namespace webhubworks\backup\services\sanitize;

use Craft;
use webhubworks\backup\exceptions\BackupFailedException;

/**
 * Facts about the running Craft install that differ between Craft 4, 5, and 6.
 *
 * shortcut: the Craft 6 branches follow the 6.0.0-beta.1 source and are untested; verify them when the plugin is ported to Craft 6.
 */
class CraftSchema
{
    public static function isCraft6(): bool
    {
        return class_exists(\CraftCms\Cms\Database\Backups::class);
    }

    /**
     * Craft 5 moved custom field content into `elements_sites.content` but
     * doesn't drop the Craft 4 `content` and `matrixcontent_*` tables on
     * upgrade. From Craft 5 on they're leftovers; on Craft 4 they're live.
     */
    public function hasLegacyContentTables(): bool
    {
        return version_compare(Craft::$app->getVersion(), '5.0', '>=');
    }

    public function useEmailAsUsername(): bool
    {
        if (self::isCraft6()) {
            return (bool) app(\CraftCms\Cms\Config\GeneralConfig::class)->useEmailAsUsername;
        }
        return Craft::$app->getConfig()->getGeneral()->useEmailAsUsername;
    }

    public function hashPassword(string $password): string
    {
        if (self::isCraft6()) {
            return \Illuminate\Support\Facades\Hash::make($password);
        }
        return Craft::$app->getSecurity()->hashPassword($password);
    }

    /**
     * Resolves field handles to the layout element UIDs that key
     * `elements_sites.content`. One handle can map to many UIDs: the same field
     * in several layouts, or another field that a layout gives this handle.
     *
     * @param array<string, mixed> $rulesByHandle
     * @return array{rules: array<string, mixed>, unknown: list<string>}
     */
    public function fieldRulesByUid(array $rulesByHandle): array
    {
        if ($rulesByHandle === []) {
            return ['rules' => [], 'unknown' => []];
        }

        if (version_compare(Craft::$app->getVersion(), '5.0', '<')) {
            throw new BackupFailedException('sanitize.fields needs Craft 5 or later. On Craft 4, custom fields live in the content table; use sanitize.tables for them.');
        }

        $layouts = self::isCraft6()
            ? \CraftCms\Cms\Support\Facades\Fields::getAllLayouts()
            : Craft::$app->getFields()->getAllLayouts();

        $rules = [];
        $found = [];
        foreach ($layouts as $layout) {
            foreach ($layout->getCustomFieldElements() as $element) {
                $handle = $element->attribute();
                if (array_key_exists($handle, $rulesByHandle)) {
                    $rules[$element->uid] = $rulesByHandle[$handle];
                    $found[$handle] = true;
                }
            }
        }

        return [
            'rules' => $rules,
            'unknown' => array_values(array_diff(array_keys($rulesByHandle), array_keys($found))),
        ];
    }
}
