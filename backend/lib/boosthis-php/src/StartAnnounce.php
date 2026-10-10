<?php

declare(strict_types=1);

namespace Boosthis;

final class StartAnnounce
{
    private static bool $announced = false;
    private static bool $announcementPending = false;
    /** @var array<string,bool> */
    private static array $refused = [];
    /** @var list<string> */
    private static array $heldRefusals = [];

    public static function findProjectKeyProblem(mixed $raw): ?string
    {
        if (!is_string($raw) || trim($raw) === '') return null;
        $value = trim($raw);
        $lower = strtolower($value);
        foreach (['bearer', 'token', 'basic', 'apikey', 'api-key', 'key'] as $word) {
            if (str_starts_with($lower, $word) && preg_match('/^\s/u', substr($value, strlen($word)))) return 'authWord';
        }
        $chars = preg_split('//u', $value, -1, PREG_SPLIT_NO_EMPTY);
        if (!is_array($chars) || $chars === []) return 'wrongShape';
        $first = $chars[0]; $last = $chars[count($chars) - 1];
        if (in_array($first, ["\"", "'", '`', '‘', '’', '“', '”'], true)
            || in_array($last, ["\"", "'", '`', '‘', '’', '“', '”'], true)
            || ($first === '<' && $last === '>')) return 'quoted';
        if (str_ends_with($value, '...') || str_ends_with($value, '…')
            || (str_starts_with($lower, 'bk_') && count($chars) < 20)) return 'cutOff';
        if (count($chars) < 8 || preg_match('/^[A-Za-z0-9_-]+$/D', $value) !== 1) return 'wrongShape';
        return null;
    }

    private static function problemSentence(string $problem): string
    {
        return match ($problem) {
            'authWord' => 'it starts with an authorization word. Paste the key on its own, with nothing in front of it.',
            'quoted' => 'it is wrapped in quotes. Paste the key on its own, with no quotes around it.',
            'cutOff' => 'it looks cut off. Paste the whole key from your Boosthis Setup page.',
            default => 'it is not the shape of a project key. A project key is one unbroken value from your Boosthis Setup page.',
        };
    }
    public static function projectKeyRefusalLine(string $problem, string $source): string
    {
        return sprintf('[boosthis] Boosthis will not use the project key from %s: %s', $source, self::problemSentence($problem));
    }
    public static function projectKeyTail(?string $key): ?string
    {
        if ($key === null || trim($key) === '') return null;
        $chars = preg_split('//u', trim($key), -1, PREG_SPLIT_NO_EMPTY);
        return is_array($chars) ? implode('', array_slice($chars, -4)) : null;
    }
    public static function kitStartupLine(?string $tail, string $badgeState = 'visible'): string
    {
        $head = $tail !== null ? sprintf('[boosthis] Boosthis starting: project key ...%s. Registering next.', $tail) : '[boosthis] Boosthis starting: no project key. Nothing will register.';
        if ($badgeState === 'hidden-by-setting') return $head . ' Badge hidden by a setting; Boosthis is still running.';
        if ($badgeState === 'switched-off') return $head . ' Badge hidden: Boosthis is switched off.';
        return $head;
    }
    public static function beginStartAnnouncement(): void
    {
        if (!self::$announced) self::$announcementPending = true;
    }
    private static function sayRefusal(string $line): void
    {
        try { error_log($line); } catch (\Throwable) {}
    }
    public static function sayAfterStartupLine(string $line): void
    {
        if (self::$announcementPending) {
            self::$heldRefusals[] = $line;
            return;
        }
        self::sayRefusal($line);
    }
    public static function flushHeldRefusals(): void
    {
        self::$announcementPending = false;
        $lines = self::$heldRefusals;
        self::$heldRefusals = [];
        foreach ($lines as $line) self::sayRefusal($line);
    }
    public static function announceKitStart(?string $key, string $badgeState): void
    {
        if (self::$announced) return;
        self::$announced = true;
        try { error_log(self::kitStartupLine(self::projectKeyTail($key), $badgeState)); } catch (\Throwable) {}
        self::flushHeldRefusals();
    }
    public static function warnProjectKeyRefused(mixed $raw, string $source): bool
    {
        $problem = self::findProjectKeyProblem($raw);
        if ($problem === null) return false;
        if (!isset(self::$refused[$source])) {
            self::$refused[$source] = true;
            $line = self::projectKeyRefusalLine($problem, $source);
            if (self::$announcementPending) self::$heldRefusals[] = $line;
            else self::sayRefusal($line);
        }
        return true;
    }
    public static function resetForTests(): void
    {
        self::$announced = false;
        self::$announcementPending = false;
        self::$refused = [];
        self::$heldRefusals = [];
    }
}