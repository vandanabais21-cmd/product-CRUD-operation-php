<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';

function documents(): array
{
    ensure_storage();
    if (!file_exists(JSON_STORE)) { file_put_contents(JSON_STORE, '[]', LOCK_EX); }
    $items = json_decode((string) file_get_contents(JSON_STORE), true);
    return is_array($items) ? $items : [];
}

function save_documents(array $items): void
{
    ensure_storage();
    $json = json_encode(array_values($items), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    if (file_put_contents(JSON_STORE, $json, LOCK_EX) === false) {
        throw new RuntimeException('Could not save the document store.');
    }
}

function document_upsert(array $product): void
{
    $items = documents(); $found = false;
    foreach ($items as &$item) { if ((int)$item['id'] === (int)$product['id']) { $item = $product; $found = true; break; } }
    if (!$found) { $items[] = $product; }
    save_documents($items);
}

function document_delete(int $id): void
{
    save_documents(array_filter(documents(), fn(array $item) => (int)$item['id'] !== $id));
}
