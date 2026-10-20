<?php
/**
 * modules/menu/save.php : creates or updates a menu item.
 * Server-side validation; CSRF protected; PDO prepared statements.
 */

declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/includes/init.php';
require_login();
require_post();
csrf_verify();

$id          = (int)($_POST['id'] ?? 0);
$name        = input_str('item_name', 120);
$description = input_str('description', 255);
$category    = input_str('category', 40);
$priceRaw    = trim((string)($_POST['unit_price'] ?? ''));
$available   = isset($_POST['is_available']) ? 1 : 0;
$errors      = [];

/* ---------------- server-side validation ---------------- */
if (mb_strlen($name) < 2) {
    $errors['item_name'] = 'Item name must be at least 2 characters.';
} elseif (mb_strlen($name) > 120) {
    $errors['item_name'] = 'Item name must not exceed 120 characters.';
} elseif (!preg_match('/^[\p{L}\p{N} \'\-\.\(\)&,]+$/u', $name)) {
    $errors['item_name'] = 'Item name contains unsupported characters.';
}

if (!in_array($category, menu_categories(), true)) {
    $errors['category'] = 'Please choose a valid category.';
}

$price = 0.0;
if ($priceRaw === '' || !is_numeric($priceRaw)) {
    $errors['unit_price'] = 'Unit price is required and must be numeric.';
} else {
    $price = round((float)$priceRaw, 2);
    if ($price <= 0) {
        $errors['unit_price'] = 'Unit price must be greater than 0.';
    } elseif ($price > 1000000) {
        $errors['unit_price'] = 'Unit price must not exceed 1,000,000.';
    } elseif (substr_count($priceRaw, '.') === 1 && strlen(substr(strrchr($priceRaw, '.'), 1)) > 2) {
        $errors['unit_price'] = 'Unit price can have at most 2 decimal places.';
    }
}

if ($description !== '' && mb_strlen($description) > 255) {
    $errors['description'] = 'Description must not exceed 255 characters.';
}

/* duplicate name check (case-insensitive, excluding self) */
if (!$errors) {
    $dupe = row(
        'SELECT id FROM menu_items WHERE LOWER(item_name) = :n AND id <> :id LIMIT 1',
        ['n' => mb_strtolower($name), 'id' => $id]
    );
    if ($dupe) {
        $errors['item_name'] = 'A menu item with this name already exists.';
    }
}

if ($errors) {
    flash_set('danger', reset($errors));
    redirect(APP_URL . '/modules/menu/index.php');
}

/* ---------------- insert / update ---------------- */
try {
    if ($id > 0) {
        $existing = row('SELECT id, item_name FROM menu_items WHERE id = ? LIMIT 1', [$id]);
        if (!$existing) {
            flash_set('danger', 'Menu item not found.');
            redirect(APP_URL . '/modules/menu/index.php');
        }
        q(
            'UPDATE menu_items SET item_name = :name, description = :descr, category = :cat,
                    unit_price = :price, is_available = :avail WHERE id = :id',
            ['name' => $name, 'descr' => $description, 'cat' => $category,
             'price' => number_format($price, 2, '.', ''), 'avail' => $available, 'id' => $id]
        );
        log_audit('menu_update', 'menu_items', $id, sprintf(
            'Updated "%s": price %s, category %s, available %s',
            $name, number_format($price, 2), $category, $available ? 'yes' : 'no'
        ));
        flash_set('success', 'Menu item "' . $name . '" updated.');
    } else {
        q(
            'INSERT INTO menu_items (item_name, description, category, unit_price, is_available, is_sample)
             VALUES (:name, :descr, :cat, :price, :avail, 0)',
            ['name' => $name, 'descr' => $description, 'cat' => $category,
             'price' => number_format($price, 2, '.', ''), 'avail' => $available]
        );
        $newId = (int)db()->lastInsertId();
        log_audit('menu_create', 'menu_items', $newId,
            sprintf('Created "%s" (%s, %s)', $name, $category, number_format($price, 2)));
        flash_set('success', 'Menu item "' . $name . '" added.');
    }
} catch (PDOException $pe) {
    error_log('[menu/save] ' . $pe->getMessage());
    if ((string)$pe->getCode() === '23000') {
        flash_set('danger', 'Duplicate item name detected.');
    } else {
        flash_set('danger', 'Could not save the menu item.');
    }
}

redirect(APP_URL . '/modules/menu/index.php');
