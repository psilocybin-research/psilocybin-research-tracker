<?php
declare(strict_types=1);

/** The task structure shared by sidebar, page finder and research-tools hub. */
final class ResearchNavigation
{
    private static ?array $definition = null;
    private static ?array $catalog = null;

    public static function definition(): array
    {
        return self::$definition ??= require __DIR__ . '/../config/research-navigation.php';
    }

    public static function groups(): array
    {
        return self::definition()['groups'];
    }

    public static function primary(): array
    {
        return self::definition()['primary'];
    }

    public static function currentKey(string $page): string
    {
        return ['account-analysis' => 'r-analysis', 'research-api' => 'api', 'recruiting-psilocybin-clinical-trials' => 'recruiting-trials'][$page] ?? $page;
    }

    public static function catalog(): array
    {
        if (self::$catalog !== null) return self::$catalog;
        $metadata = require __DIR__ . '/../config/tool-search.php';
        $groups = ['Search' => ['search', ['Shortcuts' => self::primary()]]] + self::groups();
        foreach (self::definition()['extras'] as $title => $links) $groups[$title] = ['bell-plus', ['Settings' => $links]];
        $items = [];
        $seen = [];
        foreach ($groups as $group => [$groupIcon, $sections]) {
            foreach ($sections as $section => $links) {
                foreach ($links as [$key, $href, $label, $icon]) {
                    if (isset($seen[$key])) continue;
                    $seen[$key] = true;
                    [$keywords, $description] = $metadata[$key] ?? ['', ''];
                    $items[] = ['key' => $key, 'href' => $href, 'label' => $label, 'icon' => $icon, 'group' => $group,
                        'section' => count($sections) === 1 ? '' : $section, 'keywords' => $keywords, 'description' => $description];
                }
            }
        }
        return self::$catalog = $items;
    }
}
