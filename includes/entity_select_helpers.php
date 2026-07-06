<?php
/**
 * Helpers for searchable entity selects (clients, projects, contractors).
 */

if (!function_exists('entitySelectEscape')) {

function entitySelectEscape(?string $value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

/**
 * @param array<int, array<string, mixed>> $items
 */
function entitySelectOptionsHtml(array $items, array $config = []): string {
    $valueKey = $config['valueKey'] ?? 'id';
    $labelKey = $config['labelKey'] ?? 'name';
    $selected = $config['selected'] ?? null;
    $placeholder = $config['placeholder'] ?? null;
    $optgroupKey = $config['optgroupKey'] ?? null;
    $dataAttrs = $config['dataAttrs'] ?? [];
    $subtitleFn = $config['subtitleFn'] ?? null;

    $html = '';
    if ($placeholder !== null) {
        $html .= '<option value="">' . entitySelectEscape($placeholder) . '</option>';
    }

    $groups = [];
    foreach ($items as $item) {
        $group = $optgroupKey ? (string)($item[$optgroupKey] ?? 'Other') : '__flat__';
        $groups[$group][] = $item;
    }

    foreach ($groups as $groupName => $groupItems) {
        $renderGroup = function (array $rows) use ($valueKey, $labelKey, $selected, $dataAttrs, $subtitleFn) {
            $out = '';
            foreach ($rows as $item) {
                $value = $item[$valueKey] ?? '';
                $label = $item[$labelKey] ?? '';
                $isSelected = (string)$selected === (string)$value ? ' selected' : '';

                $attrs = '';
                foreach ($dataAttrs as $attr => $field) {
                    if (is_callable($field)) {
                        $attrValue = $field($item);
                    } else {
                        $attrValue = $item[$field] ?? '';
                    }
                    if ($attrValue !== '' && $attrValue !== null) {
                        $attrs .= ' data-' . entitySelectEscape($attr) . '="' . entitySelectEscape((string)$attrValue) . '"';
                    }
                }

                if ($subtitleFn) {
                    $subtitle = $subtitleFn($item);
                    if ($subtitle) {
                        $attrs .= ' data-subtitle="' . entitySelectEscape($subtitle) . '"';
                    }
                }

                $out .= '<option value="' . entitySelectEscape((string)$value) . '"' . $isSelected . $attrs . '>'
                    . entitySelectEscape($label)
                    . '</option>';
            }
            return $out;
        };

        if ($groupName === '__flat__') {
            $html .= $renderGroup($groupItems);
        } else {
            $html .= '<optgroup label="' . entitySelectEscape($groupName) . '">';
            $html .= $renderGroup($groupItems);
            $html .= '</optgroup>';
        }
    }

    return $html;
}

function entityProjectSubtitle(array $project): string {
    $parts = [];
    if (!empty($project['client_name'])) {
        $parts[] = $project['client_name'];
    }
    if (!empty($project['city'])) {
        $parts[] = $project['city'];
    }
    if (!empty($project['stage'])) {
        $parts[] = $project['stage'];
    }
    return implode(' · ', $parts);
}

function entityClientSubtitle(array $client): string {
    $parts = [];
    if (!empty($client['type'])) {
        $parts[] = $client['type'];
    }
    if (!empty($client['city'])) {
        $parts[] = $client['city'];
    }
    return implode(' · ', $parts);
}

}
