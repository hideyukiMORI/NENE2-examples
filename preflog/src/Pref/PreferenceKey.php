<?php

declare(strict_types=1);

namespace PrefLog\Pref;

enum PreferenceKey: string
{
    case Theme = 'theme';
    case Language = 'language';
    case NotificationsEnabled = 'notifications_enabled';
    case ItemsPerPage = 'items_per_page';
    case Timezone = 'timezone';

    public function defaultValue(): string
    {
        return match ($this) {
            self::Theme => 'light',
            self::Language => 'en',
            self::NotificationsEnabled => 'true',
            self::ItemsPerPage => '20',
            self::Timezone => 'UTC',
        };
    }

    public function validate(string $value): bool
    {
        return match ($this) {
            self::Theme => in_array($value, ['light', 'dark', 'system'], true),
            self::Language => preg_match('/^[a-z]{2}(-[A-Z]{2})?$/', $value) === 1,
            self::NotificationsEnabled => in_array($value, ['true', 'false'], true),
            self::ItemsPerPage => ctype_digit($value) && (int) $value >= 5 && (int) $value <= 100,
            self::Timezone => strlen($value) <= 64 && strlen($value) > 0,
        };
    }
}
