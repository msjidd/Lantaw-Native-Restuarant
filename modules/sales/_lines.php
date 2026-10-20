<?php
/**
 * modules/sales/_lines.php : shared parsing/validation for posted order lines.
 * Used by create.php and edit.php so both accept exactly the same input.
 */

declare(strict_types=1);

/**
 * @param array $itemsPost  $_POST['items'] as posted by the line editor.
 * @param bool  $requireAvailable  reject items that are no longer available.
 * @return array{0: list<array<string,mixed>>, 1: list<string>}  [lines, errors]
 */
function lantaw_parse_lines(array $itemsPost, bool $requireAvailable): array
{
    $lines = [];
    $errors = [];

    if (!$itemsPost) {
        return [[], ['Add at least one order line.']];
    }
    if (count($itemsPost) > 100) {
        return [[], ['An order cannot have more than 100 lines.']];
    }

    foreach ($itemsPost as $idx => $it) {
        $it = (array)$it;
        $n = (int)$idx + 1;

        $menuId   = (int)($it['menu_item_id'] ?? 0);
        $qtyRaw   = (int)($it['quantity'] ?? 0);
        $priceRaw = trim((string)($it['unit_price'] ?? ''));
        $nameRaw  = mb_substr(trim((string)($it['item_name'] ?? '')), 0, 120);
        $catRaw   = mb_substr(trim((string)($it['category'] ?? '')), 0, 40);

        $menu = null;
        if ($menuId > 0) {
            $menu = row('SELECT item_name, category, unit_price, is_available FROM menu_items WHERE id = ? LIMIT 1', [$menuId]);
            if (!$menu) {
                $errors[] = 'Line ' . $n . ': that menu item no longer exists.';
                continue;
            }
            if ($requireAvailable && (int)$menu['is_available'] !== 1) {
                $errors[] = 'Line ' . $n . ': "' . $menu['item_name'] . '" is no longer available.';
                continue;
            }
            /* the menu item is the source of truth for name and category */
            $nameRaw = (string)$menu['item_name'];
            $catRaw  = (string)$menu['category'];
        }

        if ($menuId <= 0 && $nameRaw === '') {
            $errors[] = 'Line ' . $n . ': choose a menu item or type an item name.';
            continue;
        }

        if ($qtyRaw < 1 || $qtyRaw > 9999) {
            $errors[] = 'Line ' . $n . ': quantity must be between 1 and 9,999.';
            continue;
        }

        if ($priceRaw === '') {
            $priceRaw = (string)($menu['unit_price'] ?? '');
        }
        if ($priceRaw === '' || !is_numeric($priceRaw)) {
            $errors[] = 'Line ' . $n . ': unit price must be a number.';
            continue;
        }
        $price = round((float)$priceRaw, 2);
        if ($price <= 0 || $price > 1000000) {
            $errors[] = 'Line ' . $n . ': unit price must be greater than 0 and not over 1,000,000.';
            continue;
        }

        $lines[] = [
            'menu_item_id' => $menuId > 0 ? $menuId : null,
            'item_name'    => $nameRaw !== '' ? $nameRaw : 'Unnamed item',
            'category'     => $catRaw !== '' ? $catRaw : 'Other',
            'unit_price'   => number_format($price, 2, '.', ''),
            'quantity'     => $qtyRaw,
            'line_total'   => number_format(round($price * (float)$qtyRaw, 2), 2, '.', ''),
        ];
    }

    if (!$errors && !$lines) {
        $errors[] = 'Add at least one order line.';
    }

    return [$lines, $errors];
}

/** Rebuilds a printable item name list for audit details. */
function lantaw_lines_summary(array $lines): string
{
    $parts = [];
    foreach ($lines as $l) {
        $parts[] = $l['quantity'] . ' x ' . $l['item_name'];
    }
    return mb_substr(implode(', ', $parts), 0, 250);
}
