<?php
declare(strict_types=1);
require __DIR__ . '/../../src/api.php';

// GET  /api/bills.php[?status=paid|unpaid]  -> summary + bills (also used by Homarr Custom API widgets)
// POST /api/bills.php  {bill} | [bill, ...] | {"bills": [...]}  -> create/update by external_id
api_require_token();

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $status = $_GET['status'] ?? '';
    if ($status !== '' && !in_array($status, ['paid', 'unpaid'], true)) api_respond(400, ['ok' => false, 'error' => 'status must be paid or unpaid']);
    $stmt = db()->prepare('SELECT * FROM bills' . ($status !== '' ? ' WHERE status = ?' : '') . ' ORDER BY COALESCE(due_date, "9999-12-31"), id');
    $stmt->execute($status !== '' ? [$status] : []);
    api_respond(200, ['ok' => true, 'summary' => bills_summary(), 'bills' => array_map('bill_public', $stmt->fetchAll())]);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') api_respond(405, ['ok' => false, 'error' => 'Use GET or POST']);

$body = api_json_body();
$bills = isset($body['bills']) && is_array($body['bills']) ? $body['bills'] : (array_is_list($body) ? $body : [$body]);
if (!$bills) api_respond(400, ['ok' => false, 'error' => 'No bills in request']);

$results = [];
foreach ($bills as $bill) {
    $results[] = is_array($bill) ? bill_upsert($bill, 'n8n') : ['error' => 'Each bill must be a JSON object'];
}
$failed = count(array_filter($results, fn($r) => isset($r['error'])));
api_respond($failed === count($results) ? 422 : 200, ['ok' => $failed === 0, 'results' => $results]);
