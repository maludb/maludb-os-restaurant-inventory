<?php
declare(strict_types=1);
/** /suppliers/new and /suppliers/{id}/edit (screens `supplier-add`, `supplier-edit`). suppliers.write; people only. */
require_once dirname(__DIR__, 2) . '/app/features/catalog/handler.php';
require_login();
require_right('suppliers.write');
require_human();
$pdo = db();
$id = request_integer('id') ?? request_integer('supplier');
$cur = $id === null ? null : catalog_supplier_or_404($pdo, $id);
$screen = $cur === null ? 'supplier-add' : 'supplier-edit';
log_screen_view($pdo, $screen);
if (wants_json()) { respond_screen(['supplier' => $cur === null ? null : present_supplier($cur), 'kinds' => array_keys(SUPPLIER_KINDS), 'order_methods' => array_keys(ORDER_METHODS), 'sites' => held_sites()]); }
render_screen($cur === null ? 'New supplier' : 'Change ' . $cur['name'], view('catalog/supplier-form.php', ['cur' => $cur, 'sites' => held_sites(), 'here' => here_url()]), ['activeNav' => 'supplier-list', 'screen' => $screen, 'entity' => 'supplier', 'recordId' => $id === null ? '' : (string) $id]);
