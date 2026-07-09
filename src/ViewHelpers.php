<?php
declare(strict_types=1);

function active_range(string $value, string $current): string
{
    return $value === $current ? 'checked' : '';
}

function query_with(array $changes): string
{
    $query = canonical_query_params($_GET);
    foreach ($changes as $key => $value) {
        if ($value === null) {
            unset($query[$key]);
        } else {
            $query[$key] = $value;
        }
    }
    $query = canonical_query_params($query);
    return '?' . http_build_query($query);
}

function canonical_query_params(array $query): array
{
    if (isset($query['q']) && !isset($query['search'])) {
        $query['search'] = $query['q'];
    }
    unset($query['q']);
    return $query;
}

function source_filter_url(string $sourceName): string
{
    return '?' . http_build_query([
        'sources' => [$sourceName],
        'range' => 'all',
        'page' => 1,
    ]) . '#papers';
}

function tracker_query_url(array $params, string $fragment = 'papers'): string
{
    $params = canonical_query_params(array_merge(['range' => 'all', 'page' => 1], $params));
    return './?' . http_build_query($params) . ($fragment !== '' ? '#' . $fragment : '');
}

function chip_link(string $label, array $params, string $class = 'soft'): string
{
    $label = trim($label);
    if ($label === '') {
        return '';
    }
    return '<a class="' . h(trim('tag-link ' . $class)) . '" href="' . h(tracker_query_url($params)) . '">' . h($label) . '</a>';
}

function split_tag_values(?string $value, int $limit = 0): array
{
    $items = array_values(array_unique(array_filter(array_map('trim', explode(',', (string)$value)), static fn(string $item): bool => $item !== '')));
    return $limit > 0 ? array_slice($items, 0, $limit) : $items;
}

function detail_page_header(string $current = ''): string
{
    $items = [
        ['key' => 'publications', 'href' => './#papers', 'icon' => 'book-marked', 'label' => 'Publications'],
        ['key' => 'evidence', 'href' => 'evidence.php', 'icon' => 'grid-3x3', 'label' => 'Evidence'],
        ['key' => 'trials', 'href' => 'trials.php', 'icon' => 'clipboard-list', 'label' => 'Trials'],
        ['key' => 'authors', 'href' => 'authors.php', 'icon' => 'users', 'label' => 'Authors'],
        ['key' => 'citation-network', 'href' => 'citation-network.php', 'icon' => 'network', 'label' => 'Citation Network'],
        ['key' => 'analytics', 'href' => './#analytics', 'icon' => 'network', 'label' => 'Analytics'],
        ['key' => 'alerts', 'href' => './#alerts', 'icon' => 'bell-plus', 'label' => 'Alerts'],
        ['key' => 'export', 'href' => 'export.php?format=json', 'icon' => 'download', 'label' => 'Export data', 'disabled' => true, 'disabled_title' => 'Use export from a publication results list so current filters can be included.'],
        ['key' => 'api', 'href' => 'api.php', 'icon' => 'braces', 'label' => 'API', 'disabled' => true, 'disabled_title' => 'Use API links from a publication results list so current filters can be included.'],
        ['key' => 'github', 'href' => 'https://github.com/psilocybin-research/psilocybin-research-tracker', 'icon' => 'github', 'label' => 'GitHub', 'external' => true],
        ['key' => 'about', 'href' => 'about.php', 'icon' => 'circle-alert', 'label' => 'About'],
        ['key' => 'data-protection', 'href' => 'data-protection.php', 'icon' => 'shield', 'label' => 'Data protection'],
    ];
    $nav = '';
    foreach ($items as $item) {
        $icon = '<i data-icon="' . h($item['icon']) . '" aria-hidden="true"></i><span>' . h($item['label']) . '</span>';
        if (!empty($item['disabled'])) {
            $nav .= '<span class="is-disabled" aria-disabled="true" title="' . h((string)($item['disabled_title'] ?? 'Unavailable on this page.')) . '">' . $icon . '</span>';
            continue;
        }
        $active = $current === $item['key'] ? ' aria-current="location"' : '';
        $external = !empty($item['external']) ? ' target="_blank" rel="noopener me"' : '';
        $nav .= '<a href="' . h($item['href']) . '"' . $external . $active . '>' . $icon . '</a>';
    }
    return '<header class="topbar">'
        . '<button class="nav-sidebar-toggle" id="nav-sidebar-toggle" type="button" aria-expanded="true" aria-controls="primary-sidebar-content" title="Collapse sidebar">'
        . '<i data-icon="chevron-left" aria-hidden="true"></i>'
        . '<span class="sr-only">Collapse sidebar</span>'
        . '</button>'
        . '<div class="primary-sidebar-content" id="primary-sidebar-content">'
        . '<a class="brand brand-lockup" href="./" aria-label="Psilocybin-Research.com publication tracker">'
        . '<img class="brand-icon brand-icon-mushroom" src="assets/mushroom-brand-mark.webp" alt="" width="46" height="46">'
        . '<span class="brand-text"><strong>Psilocybin-Research.com</strong><em>Searchable psilocybin and psilocin bibliometric database.</em></span>'
        . '</a>'
        . '<nav aria-label="Research sections">' . $nav . '</nav>'
        . '<div class="top-actions">'
        . '<button class="install-app" id="install-app" type="button" hidden><i data-icon="download" aria-hidden="true"></i><span>Install</span></button>'
        . '<button class="push-app" id="push-app" type="button" hidden><i data-icon="bell-plus" aria-hidden="true"></i><span>Push</span></button>'
        . '</div>'
        . '</div>'
        . '</header>';
}

function detail_scroll_top(): string
{
    return '<button class="scroll-top" id="scroll-top" type="button" aria-label="Scroll to top"><i data-icon="arrow-up" aria-hidden="true"></i><span>Top</span></button>';
}

function hidden_query_inputs(array $exclude = []): string
{
    $html = '';
    $exclude = array_fill_keys($exclude, true);
    $append = function (string $name, mixed $value) use (&$html, &$append): void {
        if (is_array($value)) {
            foreach ($value as $key => $childValue) {
                $append($name . '[' . $key . ']', $childValue);
            }
            return;
        }
        $html .= '<input type="hidden" name="' . h($name) . '" value="' . h((string)$value) . '">' . "\n";
    };
    foreach (canonical_query_params($_GET) as $key => $value) {
        if (isset($exclude[(string)$key])) {
            continue;
        }
        $append((string)$key, $value);
    }
    return $html;
}

function publication_status_label(?string $status): string
{
    $status = PublicationRepository::normalizePublicationStatus($status);
    return match ($status) {
        'preprint' => 'PREPRINT (not peer reviewed)',
        'clinical trial' => 'CLINICAL TRIAL',
        'protocol' => 'PROTOCOL',
        'review' => 'REVIEW',
        default => 'Published',
    };
}

function publication_status_class(?string $status): string
{
    return 'status-' . str_replace(' ', '-', PublicationRepository::normalizePublicationStatus($status));
}

function publication_recency_badge(?string $publicationDate): string
{
    $publicationDate = trim((string)$publicationDate);
    if ($publicationDate === '') {
        return '';
    }
    try {
        $published = new DateTimeImmutable(substr($publicationDate, 0, 10), new DateTimeZone('UTC'));
        $today = new DateTimeImmutable('today', new DateTimeZone('UTC'));
    } catch (Throwable) {
        return '';
    }
    $days = (int)$published->diff($today)->format('%r%a');
    if ($days < 0 || $days > 31) {
        return '';
    }
    $label = $days <= 7 ? 'New this week' : 'New this month';
    $class = $days <= 7 ? 'recency-week' : 'recency-month';
    return '<span class="recency-badge ' . h($class) . '" data-publication-date="' . h($publicationDate) . '">' . h($label) . '</span>';
}
