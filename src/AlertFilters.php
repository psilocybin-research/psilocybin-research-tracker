<?php
declare(strict_types=1);

/** Multi-valued alert preferences, with existing single-value subscriptions retained. */
final class AlertFilters
{
    private static array $optionCatalog = [];
    public static function values(string|array|null $value): array
    {
        if (is_string($value)) {
            $decoded = str_starts_with(trim($value), '[') ? json_decode($value, true) : null;
            $value = is_array($decoded) ? $decoded : [$value];
        }
        $values = [];
        foreach ($value ?? [] as $item) {
            if (!is_string($item)) continue;
            $aliases = str_starts_with(trim($item), '[') ? json_decode($item, true) : null;
            if (is_array($aliases)) {
                foreach ($aliases as $alias) if (is_string($alias) && trim($alias) !== '') $values[] = mb_substr(trim($alias), 0, 300);
                continue;
            }
            $item = trim($item);
            if ($item !== '') $values[] = mb_substr($item, 0, 300);
        }
        $values = array_values(array_unique($values));
        sort($values, SORT_STRING);
        return array_slice($values, 0, 100);
    }

    public static function encode(string|array|null $value): ?string
    {
        $values = self::values($value);
        return !$values ? null : (count($values) === 1 ? $values[0] : json_encode($values, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }

    public static function request(string $name): array
    {
        $value = $_POST[$name] ?? $_GET[$name] ?? null;
        return self::values(is_array($value) || is_string($value) ? $value : null);
    }

    public static function displayNames(string|array|null $value): array
    {
        $names = [];
        foreach (self::values($value) as $raw) {
            $label = trim(preg_replace('/\s+/u', ' ', html_entity_decode($raw, ENT_QUOTES | ENT_HTML5, 'UTF-8')) ?? $raw);
            $names[mb_strtolower($label)] ??= $label;
        }
        return array_values($names);
    }

    public static function picker(string $name, string $label, array $options, string|array|null $selected = null, bool $deferOptions = false): string
    {
        $values = self::values($selected);
        $options = array_values(array_unique(array_merge($values, $options)));
        natcasesort($options);
        $any = match ($label) { 'Journals'=>'Any journal', 'Topics'=>'Any topic', default=>'Any source database' };
        $groups = [];
        foreach ($options as $option) {
            // Only case, HTML-entity and whitespace variants: no guessed title aliases.
            $display = trim(preg_replace('/\s+/u', ' ', html_entity_decode($option, ENT_QUOTES | ENT_HTML5, 'UTF-8')) ?? $option);
            $key = $label === 'Journals' ? mb_strtolower($display) : $option;
            if (!isset($groups[$key])) $groups[$key] = ['label'=>$display, 'aliases'=>[]];
            $groups[$key]['aliases'][] = $option;
        }
        $selectedLabels = [];
        foreach ($groups as $group) if (array_intersect($group['aliases'], $values)) $selectedLabels[] = $group['label'];
        $count = count($selectedLabels);
        $catalogKey = substr(hash('sha256', json_encode($groups, JSON_THROW_ON_ERROR)), 0, 20);
        if ($deferOptions) self::$optionCatalog[$catalogKey] = array_values(array_map(static fn(array $group): string|array => count($group['aliases']) === 1 && $group['aliases'][0] === $group['label'] ? $group['label'] : $group, $groups));
        $deferredAttributes = $deferOptions ? ' data-options-key="' . $catalogKey . '" data-options-name="' . h($name) . '" data-options-selected="' . h(json_encode($values, JSON_THROW_ON_ERROR)) . '"' : '';
        $html = '<details class="alert-multiselect" data-alert-multiselect' . $deferredAttributes . '><summary><span class="alert-selection-heading"><span>' . h($label) . '</span><span class="alert-selection-count" aria-hidden="true">' . ($count ? $count . ' selected' : 'All') . '</span><span class="alert-selection-chevron" aria-hidden="true"></span></span><span class="alert-selection-summary" data-empty-label="' . h($any) . '">' . h($selectedLabels ? implode(', ', $selectedLabels) : $any) . '</span></summary>';
        $html .= '<div class="alert-selection-body"><label class="field alert-selection-search"><span class="sr-only">Search ' . h(strtolower($label)) . '</span><input type="search" data-selection-search placeholder="Search ' . h(strtolower($label)) . '" autocomplete="off"></label>';
        $html .= '<div class="alert-selection-toolbar"><span data-selection-result role="status" aria-live="polite">' . count($groups) . ' available</span><button type="button" class="alert-selection-clear"' . (!$count ? ' disabled' : '') . '>Clear selection</button></div><div class="alert-selection-options" role="group" aria-label="' . h($label) . '">';
        foreach ($groups as $group) {
            $option = count($group['aliases']) === 1 ? $group['aliases'][0] : json_encode($group['aliases'], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            $checked = (bool)array_intersect($group['aliases'], $values);
            if ($deferOptions && !$checked) continue;
            $html .= '<label' . ($checked ? ' class="is-selected"' : '') . '><input type="checkbox" name="' . h($name) . '[]" value="' . h($option) . '"' . ($checked ? ' checked' : '') . '><span>' . h($group['label']) . '</span></label>';
        }
        return $html . '</div><p class="field-note alert-selection-help">Choose one or more. Leave empty for all ' . h(strtolower($label)) . '.</p><p class="alert-selection-empty" data-selection-empty hidden>No matches. Try a different search.</p></div></details>';
    }
    /** Public option names shared by filter/alert controls, with no active DOM until needed. */
    public static function optionCatalogScript(): string
    {
        if (!self::$optionCatalog) return '';
        return '<script type="application/json" id="alert-option-catalog">' . json_encode(self::$optionCatalog, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . '</script>';
    }

}
