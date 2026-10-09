<?php
declare(strict_types=1);

const BILL_FIELDS = ['provider', 'category', 'account_no', 'billing_month', 'amount', 'due_date', 'status', 'paid_date', 'usage_value', 'usage_unit', 'notes'];

function bill_find(int $id): ?array
{
    $stmt = db()->prepare('SELECT * FROM bills WHERE id = ?');
    $stmt->execute([$id]);
    return $stmt->fetch() ?: null;
}

/** Validate and normalise one bill from the API. Returns [fields, error]. */
function bill_normalize(array $input, bool $isNew): array
{
    $out = [];
    foreach (BILL_FIELDS as $field) {
        if (!array_key_exists($field, $input)) continue;
        $value = $input[$field];
        $out[$field] = is_string($value) ? trim($value) : $value;
        if ($out[$field] === '') $out[$field] = null;
    }

    if (isset($out['amount'])) {
        if (!is_numeric($out['amount']) || (float)$out['amount'] < 0) return [null, 'amount must be a non-negative number'];
        $out['amount'] = (float)$out['amount'];
    }
    if (isset($out['usage_value'])) {
        if (!is_numeric($out['usage_value'])) return [null, 'usage_value must be a number'];
        $out['usage_value'] = (float)$out['usage_value'];
    }
    if (array_key_exists('status', $out)) {
        $out['status'] = strtolower((string)($out['status'] ?? 'unpaid'));
        if (!in_array($out['status'], ['paid', 'unpaid'], true)) return [null, 'status must be "paid" or "unpaid"'];
    }
    if (isset($out['billing_month']) && !preg_match('/^\d{4}-\d{2}$/', (string)$out['billing_month'])) {
        return [null, 'billing_month must be YYYY-MM'];
    }
    foreach (['due_date', 'paid_date'] as $dateField) {
        if (isset($out[$dateField])) {
            $time = strtotime((string)$out[$dateField]);
            if ($time === false) return [null, "$dateField is not a valid date"];
            $out[$dateField] = date('Y-m-d', $time);
        }
    }

    if ($isNew) {
        foreach (['provider', 'billing_month', 'amount'] as $required) {
            if (!isset($out[$required])) return [null, "$required is required"];
        }
        $out['category'] ??= 'Other';
        $out['status'] ??= 'unpaid';
    }
    if (($out['status'] ?? null) === 'paid' && empty($out['paid_date'])) $out['paid_date'] = today();
    if (($out['status'] ?? null) === 'unpaid') $out['paid_date'] = null;

    return [$out, null];
}

/**
 * Create or update a bill identified by external_id (defaults to provider|account|month).
 * Returns ['id' => int, 'external_id' => string, 'action' => created|updated|unchanged] or ['error' => string].
 */
function bill_upsert(array $input, string $source = 'api'): array
{
    $pdo = db();
    $externalId = trim((string)($input['external_id'] ?? ''));
    if ($externalId === '') {
        if (empty($input['provider']) || empty($input['billing_month'])) {
            return ['error' => 'external_id, or provider and billing_month, is required'];
        }
        $externalId = strtolower(implode('|', [trim((string)$input['provider']), trim((string)($input['account_no'] ?? '')), trim((string)$input['billing_month'])]));
    }

    $stmt = $pdo->prepare('SELECT * FROM bills WHERE external_id = ?');
    $stmt->execute([$externalId]);
    $existing = $stmt->fetch() ?: null;

    [$fields, $error] = bill_normalize($input, $existing === null);
    if ($error) return ['external_id' => $externalId, 'error' => $error];

    // A paid bill stays paid when n8n re-sends it before the provider has caught up.
    // Send "reopen": true to deliberately move it back to unpaid.
    if ($existing && $existing['status'] === 'paid' && ($fields['status'] ?? null) === 'unpaid' && empty($input['reopen'])) {
        unset($fields['status'], $fields['paid_date']);
    }

    if ($existing === null) {
        $fields['external_id'] = $externalId;
        $fields['source'] = $source;
        $columns = array_keys($fields);
        $stmt = $pdo->prepare('INSERT INTO bills (' . implode(',', $columns) . ') VALUES (' . implode(',', array_map(fn($c) => ":$c", $columns)) . ')');
        $stmt->execute(array_combine(array_map(fn($c) => ":$c", $columns), array_values($fields)));
        $bill = bill_find((int)$pdo->lastInsertId());
        notify('new_bill', bill_summary_line($bill, 'New bill'));
        if ($bill['status'] === 'paid') notify('bill_paid', bill_summary_line($bill, 'Bill paid'));
        return ['id' => (int)$bill['id'], 'external_id' => $externalId, 'action' => 'created', 'status' => $bill['status']];
    }

    $changed = array_filter($fields, fn($value, $key) => (string)($existing[$key] ?? '') !== (string)($value ?? ''), ARRAY_FILTER_USE_BOTH);
    if (!$changed) return ['id' => (int)$existing['id'], 'external_id' => $externalId, 'action' => 'unchanged', 'status' => $existing['status']];

    $sets = implode(',', array_map(fn($c) => "$c = :$c", array_keys($changed)));
    $stmt = $pdo->prepare("UPDATE bills SET $sets, updated_at = CURRENT_TIMESTAMP WHERE id = :id");
    $params = array_combine(array_map(fn($c) => ":$c", array_keys($changed)), array_values($changed));
    $params[':id'] = $existing['id'];
    $stmt->execute($params);

    $bill = bill_find((int)$existing['id']);
    if ($existing['status'] !== 'paid' && $bill['status'] === 'paid') notify('bill_paid', bill_summary_line($bill, 'Bill paid'));
    return ['id' => (int)$bill['id'], 'external_id' => $externalId, 'action' => 'updated', 'status' => $bill['status']];
}

/** Mark a bill paid/unpaid from the dashboard or a Telegram command. */
function bill_set_status(int $id, string $status, string $via): ?array
{
    $bill = bill_find($id);
    if (!$bill || $bill['status'] === $status) return $bill;

    $stmt = db()->prepare('UPDATE bills SET status = ?, paid_date = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?');
    $stmt->execute([$status, $status === 'paid' ? today() : null, $id]);
    $bill = bill_find($id);

    if ($status === 'paid') notify('bill_paid', bill_summary_line($bill, 'Bill paid') . " (via $via)");
    bill_callback($bill, $via);
    return $bill;
}

/** Tell n8n (or any webhook) that a bill's status was changed from the dashboard side. */
function bill_callback(array $bill, string $via): void
{
    $url = setting('bill_status_webhook_url');
    if (!$url) return;
    notify_attempt('bill_status_webhook', 'webhook', "Bill #{$bill['id']} {$bill['status']}", function () use ($url, $bill, $via) {
        [$status, , $error] = http_post_json($url, ['event' => 'bill_status_changed', 'via' => $via, 'bill' => bill_public($bill)], 10);
        if ($error) throw new RuntimeException($error);
        if ($status < 200 || $status >= 300) throw new RuntimeException("HTTP $status");
    });
}

function bill_public(array $bill): array
{
    return [
        'id' => (int)$bill['id'],
        'external_id' => $bill['external_id'],
        'provider' => $bill['provider'],
        'category' => $bill['category'],
        'account_no' => $bill['account_no'],
        'billing_month' => $bill['billing_month'],
        'amount' => (float)$bill['amount'],
        'due_date' => $bill['due_date'],
        'status' => $bill['status'],
        'paid_date' => $bill['paid_date'],
        'usage_value' => $bill['usage_value'] !== null ? (float)$bill['usage_value'] : null,
        'usage_unit' => $bill['usage_unit'],
        'notes' => $bill['notes'],
        'source' => $bill['source'],
        'updated_at' => $bill['updated_at'],
        // Display helpers for dashboards such as Homarr.
        'state' => $state = bill_state($bill),
        'state_label' => BILL_STATE_LABELS[$state],
        'color' => $state === 'paid' ? 'green' : ($state === 'due-soon' ? 'orange' : 'red'),
        'amount_text' => money((float)$bill['amount']),
    ];
}

const BILL_STATE_LABELS = ['paid' => 'Paid', 'overdue' => 'Overdue', 'due-soon' => 'Due soon', 'unpaid' => 'To pay'];

function bills_summary(): array
{
    $pdo = db();
    $unpaid = bills_unpaid();
    $unpaidTotal = array_sum(array_map(fn($b) => (float)$b['amount'], $unpaid));
    $stmt = $pdo->prepare('SELECT COALESCE(SUM(amount), 0) FROM bills WHERE status = "paid" AND paid_date >= ?');
    $stmt->execute([date('Y-m-01')]);
    $paidThisMonth = (float)$stmt->fetchColumn();
    return [
        'unpaid_count' => count($unpaid),
        'unpaid_total' => $unpaidTotal,
        'unpaid_total_text' => money($unpaidTotal),
        'overdue_count' => count(array_filter($unpaid, fn($b) => bill_state($b) === 'overdue')),
        'paid_this_month' => $paidThisMonth,
        'paid_this_month_text' => money($paidThisMonth),
        'telegram_users' => (int)$pdo->query('SELECT COUNT(*) FROM telegram_users')->fetchColumn(),
        'telegram_new_users' => (int)$pdo->query('SELECT COUNT(*) FROM telegram_users WHERE is_new = 1')->fetchColumn(),
    ];
}

/** paid | overdue | due-soon | unpaid */
function bill_state(array $bill): string
{
    if ($bill['status'] === 'paid') return 'paid';
    if ($bill['due_date'] && $bill['due_date'] < today()) return 'overdue';
    $days = (int)setting('reminder_days_before', '3');
    if ($bill['due_date'] && $bill['due_date'] <= date('Y-m-d', strtotime("+$days days"))) return 'due-soon';
    return 'unpaid';
}

function bill_summary_line(array $bill, string $prefix = ''): string
{
    $parts = [($prefix !== '' ? "$prefix: " : '') . "#{$bill['id']} {$bill['provider']}"];
    if ($bill['account_no']) $parts[] = "acc {$bill['account_no']}";
    $parts[] = $bill['billing_month'];
    $parts[] = money((float)$bill['amount']);
    if ($bill['status'] === 'paid') $parts[] = 'PAID' . ($bill['paid_date'] ? " {$bill['paid_date']}" : '');
    elseif ($bill['due_date']) $parts[] = (bill_state($bill) === 'overdue' ? 'OVERDUE since ' : 'due ') . $bill['due_date'];
    return implode(' · ', $parts);
}

/** @return list<array> */
function bills_unpaid(): array
{
    return db()->query('SELECT * FROM bills WHERE status != "paid" ORDER BY COALESCE(due_date, "9999-12-31"), id')->fetchAll();
}

/** Once a day, after the configured hour, send a list of overdue and soon-due bills. */
function bill_reminder_job(): void
{
    $hour = (int)setting('reminder_hour', '8');
    if ((int)date('G') < $hour || setting('reminder_last_sent') === today()) return;
    set_setting('reminder_last_sent', today());

    $due = array_filter(bills_unpaid(), fn($b) => in_array(bill_state($b), ['overdue', 'due-soon'], true));
    if (!$due) return;

    $total = array_sum(array_map(fn($b) => (float)$b['amount'], $due));
    $lines = array_map(fn($b) => '• ' . bill_summary_line($b), $due);
    notify('bill_reminder', "Bills to pay (" . money($total) . "):\n" . implode("\n", $lines));
}
