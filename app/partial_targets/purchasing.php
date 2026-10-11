<?php
/** Slice 4's partial-update targets (app/partial_update.php): an agent's `any field of` on a draft purchase order's header and on an invoice's. */
return ['/purchasing/save.php' => ['purchase_orders', 'purchase_order', 'mcp_purchase_orders', 'purchase_order_id'], '/invoices/save.php' => ['supplier_invoices', 'invoice', 'mcp_supplier_invoices', 'invoice_id']];
