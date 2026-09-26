<?php
require 'config.php';

$activeTab = $_GET['tab'] ?? 'dashboard';
if (!in_array($activeTab, ['dashboard', 'installment', 'interest', 'rent', 'moi'], true)) {
    $activeTab = 'dashboard';
}

$tables = [
    'installment_customers' => ['id','name','village','mobile','principal','outstanding_amount','amount_due','days_count','payment_schedule','payment_date'],
    'interest_loans' => ['id','name','village','mobile','principal_amount','remaining_principal','interest_rate','loan_date','installments','total_interest_collected'],
    'house_rent' => ['id','name','village','mobile','rental_amount','contract_date','rent_collection_date','updated_at','status'],
    'moi_records' => ['id','name','name_tamil','name_english','village_name','village_tamil','village_english','mobile','city','record_date','income','expenses','additional_info','alternate_mobile','updated_details'],
];

$editableFields = [
    'installment_customers' => ['name','village','mobile','principal','payment_schedule','payment_date'],
    'interest_loans' => ['name','village','mobile','interest_rate','loan_date'],
    'house_rent' => ['name','village','mobile','rental_amount','contract_date'],
    'moi_records' => ['name','name_tamil','name_english','village_name','village_tamil','village_english','mobile','city','record_date','income','expenses','additional_info','alternate_mobile','updated_details'],
];

function jsonResponse($payload, $status = 200){
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

function saveRecord($pdo, $table, $values){
    $columns = array_keys($values);
    $placeholders = array_map(function($column){ return ':' . $column; }, $columns);
    $sql = "INSERT INTO `$table` (`" . implode('`,`', $columns) . "`) VALUES (" . implode(',', $placeholders) . ")";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($values);
    return $pdo->lastInsertId();
}

function updateRecord($pdo, $table, $id, $values){
    if (!$values) {
        throw new InvalidArgumentException('No editable fields were submitted.');
    }
    $sets = array_map(function($column){ return "`$column` = :$column"; }, array_keys($values));
    $values['record_id'] = $id;
    $stmt = $pdo->prepare("UPDATE `$table` SET " . implode(', ', $sets) . ' WHERE id = :record_id');
    $stmt->execute($values);
    if (!$stmt->rowCount()) {
        $check = $pdo->prepare("SELECT id FROM `$table` WHERE id = ?");
        $check->execute([$id]);
        if (!$check->fetch()) {
            throw new RuntimeException('Record not found.');
        }
    }
}

function fetchPaymentHistory($pdo, $table, $id){
    $stmt = $pdo->prepare('SELECT id, payment_type, amount, payment_date FROM financial_payments WHERE record_type = ? AND record_id = ? ORDER BY payment_date DESC, id DESC');
    $stmt->execute([$table, $id]);
    return $stmt->fetchAll();
}

function handleDashboardAction($pdo, $tables, $editableFields, $input){
    $action = $input['action'] ?? '';
    $table = $input['table'] ?? '';
    $id = filter_var($input['id'] ?? null, FILTER_VALIDATE_INT);
    if (!isset($tables[$table])) {
        throw new InvalidArgumentException('Unknown record type.');
    }

    if ($action === 'update') {
        if (!$id) {
            throw new InvalidArgumentException('Invalid record ID.');
        }
        $values = [];
        foreach ($editableFields[$table] as $column) {
            if (array_key_exists($column, $input['values'] ?? [])) {
                $values[$column] = trim((string)$input['values'][$column]);
            }
        }
        if ($table === 'moi_records') {
            if (array_key_exists('name_tamil', $values) || array_key_exists('name_english', $values)) {
                $values['name'] = $values['name_english'] ?: $values['name_tamil'];
            }
            if (array_key_exists('village_tamil', $values) || array_key_exists('village_english', $values)) {
                $values['village_name'] = $values['village_english'] ?: $values['village_tamil'];
            }
        }
        updateRecord($pdo, $table, $id, $values);
        return ['success' => true];
    }

    if ($action !== 'pay' || !$id) {
        throw new InvalidArgumentException('Unsupported action or invalid record ID.');
    }
    $amount = filter_var($input['amount'] ?? null, FILTER_VALIDATE_FLOAT);
    if ($amount === false || $amount <= 0) {
        throw new InvalidArgumentException('Enter a payment amount greater than zero.');
    }
    if (!in_array($table, ['installment_customers', 'interest_loans', 'house_rent'], true)) {
        throw new InvalidArgumentException('Payments are not available for this record type.');
    }
    $paymentDate = $input['payment_date'] ?? date('Y-m-d');
    $parsedDate = DateTimeImmutable::createFromFormat('!Y-m-d', $paymentDate);
    if (!$parsedDate || $parsedDate->format('Y-m-d') !== $paymentDate) {
        throw new InvalidArgumentException('Enter a valid payment date.');
    }

    $paymentType = $table === 'installment_customers' ? 'installment' : ($table === 'house_rent' ? 'rent' : ($input['payment_type'] ?? 'principal'));
    if ($table === 'interest_loans' && !in_array($paymentType, ['interest', 'principal'], true)) {
        throw new InvalidArgumentException('Choose interest or principal collection.');
    }

    $pdo->beginTransaction();
    try {
        if ($table === 'installment_customers') {
                $stmt = $pdo->prepare('UPDATE installment_customers SET outstanding_amount = outstanding_amount - :amount WHERE id = :id AND :limit_amount <= outstanding_amount');
                $stmt->execute(['amount' => $amount, 'limit_amount' => $amount, 'id' => $id]);
        } elseif ($table === 'interest_loans') {
            if ($paymentType === 'interest') {
                $stmt = $pdo->prepare('UPDATE interest_loans SET total_interest_collected = total_interest_collected + :amount, installments = installments + 1 WHERE id = :id');
                $stmt->execute(['amount' => $amount, 'id' => $id]);
            } else {
                $stmt = $pdo->prepare('UPDATE interest_loans SET remaining_principal = remaining_principal - :amount, installments = installments + 1 WHERE id = :id AND :limit_amount <= remaining_principal');
                $stmt->execute(['amount' => $amount, 'limit_amount' => $amount, 'id' => $id]);
            }
        } else {
            $rent = $pdo->prepare('SELECT rental_amount, status FROM house_rent WHERE id = ? FOR UPDATE');
            $rent->execute([$id]);
            $rentRecord = $rent->fetch();
            if (!$rentRecord) {
                throw new RuntimeException('Rent record not found.');
            }
            $paid = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) FROM financial_payments WHERE record_type = 'house_rent' AND record_id = ?");
            $paid->execute([$id]);
            $totalPaid = (float)$paid->fetchColumn();
            if (strtolower((string)$rentRecord['status']) === 'paid') {
                $totalPaid = (float)$rentRecord['rental_amount'];
            }
            $rentBalance = max(0, (float)$rentRecord['rental_amount'] - $totalPaid);
            if ($amount > $rentBalance) {
                throw new InvalidArgumentException('The payment cannot exceed the remaining rent balance.');
            }
            $newStatus = $amount >= $rentBalance ? 'paid' : 'partial';
            $stmt = $pdo->prepare('UPDATE house_rent SET status = :status, rent_collection_date = :payment_date, updated_at = CURRENT_TIMESTAMP WHERE id = :id');
            $stmt->execute(['status' => $newStatus, 'payment_date' => $paymentDate, 'id' => $id]);
        }

        if ($table !== 'house_rent' && !$stmt->rowCount()) {
            throw new RuntimeException('Payment was not applied. Check the record balance and try again.');
        }
        $payment = $pdo->prepare('INSERT INTO financial_payments (record_type, record_id, payment_type, amount, payment_date) VALUES (?, ?, ?, ?, ?)');
        $payment->execute([$table, $id, $paymentType, $amount, $paymentDate]);
        $pdo->commit();
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $error;
    }
    return ['success' => true, 'payment_id' => $pdo->lastInsertId()];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_GET['ajax_action'])) {
    $input = json_decode(file_get_contents('php://input'), true) ?: $_POST;
    try {
        jsonResponse(handleDashboardAction($pdo, $tables, $editableFields, $input));
    } catch (Throwable $error) {
        jsonResponse(['error' => $error->getMessage()], 400);
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $addActions = [
        'save_installment' => 'installment_customers',
        'submit_print_installment' => 'installment_customers',
        'save_interest' => 'interest_loans',
        'submit_print_interest' => 'interest_loans',
        'save_rent' => 'house_rent',
        'submit_print_rent' => 'house_rent',
        'save_moi' => 'moi_records',
    ];
    $action = null;
    foreach ($addActions as $button => $table) {
        if (isset($_POST[$button])) {
            $action = $button;
            break;
        }
    }
    if ($action !== null) {
        $table = $addActions[$action];
        try {
            $values = [];
            foreach ($_POST as $column => $value) {
                if ($column !== 'id' && in_array($column, $tables[$table], true) && $column !== 'updated_at' && $column !== 'rent_collection_date' && $column !== 'status') {
                    $values[$column] = is_string($value) ? trim($value) : $value;
                }
            }
            if ($table === 'installment_customers') {
                $principal = (float)($values['principal'] ?? 0);
                $schedule = $values['payment_schedule'] ?? '';
                $periods = ['100_days' => 100, '10_weeks' => 10, '5_months' => 5];
                $values['outstanding_amount'] = $principal;
                $values['amount_due'] = isset($periods[$schedule]) ? round($principal / $periods[$schedule], 2) : $principal;
                $values['days_count'] = ['100_days' => 100, '10_weeks' => 70, '5_months' => 150][$schedule] ?? 0;
            } elseif ($table === 'interest_loans') {
                $values['remaining_principal'] = (float)($values['principal_amount'] ?? 0);
                $values['installments'] = 0;
                $values['total_interest_collected'] = 0;
            } elseif ($table === 'house_rent') {
                $values['status'] = 'pending';
            } elseif ($table === 'moi_records') {
                $values['name'] = $values['name_english'] ?: $values['name_tamil'];
                $values['village_name'] = $values['village_english'] ?: $values['village_tamil'];
            }
            $recordId = saveRecord($pdo, $table, $values);
            if (strpos($action, 'submit_print_') === 0) {
                $recordStatement = $pdo->prepare("SELECT * FROM `$table` WHERE id = ?");
                $recordStatement->execute([$recordId]);
                $record = $recordStatement->fetch();
                $returnUrl = 'dashboard.php?tab=' . urlencode($activeTab) . '&saved=1';
                ?>
                <!doctype html>
                <html lang="en">
                <head>
                    <meta charset="UTF-8">
                    <meta name="viewport" content="width=device-width, initial-scale=1">
                    <title>Record Receipt</title>
                    <style>body{font:15px Arial,sans-serif;margin:32px;color:#172c35}h1{font-size:22px}table{border-collapse:collapse;width:100%;margin:22px 0}th,td{border:1px solid #dbe3e7;padding:9px;text-align:left}th{width:35%}.return-link{color:#087f65}</style>
                </head>
                <body data-return-url="<?= htmlspecialchars($returnUrl, ENT_QUOTES, 'UTF-8') ?>">
                    <h1>Gram Finance - <?= htmlspecialchars(ucwords(str_replace('_', ' ', $table))) ?> Receipt</h1>
                    <table>
                        <?php foreach ($record as $column => $value): ?>
                            <tr><th><?= htmlspecialchars(ucwords(str_replace('_', ' ', $column))) ?></th><td><?= htmlspecialchars((string)($value ?? '-')) ?></td></tr>
                        <?php endforeach; ?>
                    </table>
                    <a class="return-link" href="dashboard.php?tab=<?= urlencode($activeTab) ?>&saved=1">Return to dashboard</a>
                    <script>
                        window.addEventListener('afterprint', () => window.location.replace(document.body.dataset.returnUrl));
                        window.addEventListener('load', () => window.print());
                    </script>
                </body>
                </html>
                <?php
                exit;
            }
            header('Location: dashboard.php?tab=' . urlencode($activeTab) . '&saved=1');
            exit;
        } catch (Throwable $error) {
            $formError = $error->getMessage();
        }
    }
}

function totalRows($pdo, $table){
    $stmt = $pdo->query("SELECT COUNT(*) as total FROM $table");
    return $stmt->fetch()['total'];
}

function fetchAllTable($pdo, $table){
    $sql = "SELECT * FROM $table ORDER BY id DESC";
    $stmt = $pdo->query($sql);
    return $stmt->fetchAll();
}

// AJAX record lookup and search
if (isset($_GET['ajax']) && $_GET['ajax'] == 1) {
    $table = $_GET['table'] ?? '';
    if (!isset($tables[$table])) {
        jsonResponse([]);
    }

    if (isset($_GET['history'])) {
        $id = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT);
        if (!$id || !in_array($table, ['installment_customers', 'interest_loans', 'house_rent'], true)) {
            jsonResponse([]);
        }
        jsonResponse(fetchPaymentHistory($pdo, $table, $id));
    }

    if (isset($_GET['record'])) {
        $id = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT);
        $stmt = $pdo->prepare("SELECT * FROM `$table` WHERE id = ?");
        $stmt->execute([$id]);
        $record = $stmt->fetch();
        if (!$record) jsonResponse([]);
        if (in_array($table, ['installment_customers', 'interest_loans', 'house_rent'], true)) {
            $stats = $pdo->prepare('SELECT COUNT(*) AS payment_count, COALESCE(SUM(amount), 0) AS paid_amount FROM financial_payments WHERE record_type = ? AND record_id = ?');
            $stats->execute([$table, $id]);
            $record = array_merge($record, $stats->fetch());
            if ($table === 'house_rent' && strtolower((string)$record['status']) === 'paid' && (int)$record['payment_count'] === 0) {
                $record['paid_amount'] = (float)$record['rental_amount'];
            }
        }
        jsonResponse($record);
    }

    $q = trim($_GET['q'] ?? '');
    $cols = $tables[$table];
    $where = [];
    foreach ($cols as $col) {
        $where[] = "$col LIKE :q";
    }
    $sql = "SELECT * FROM $table WHERE " . implode(" OR ", $where) . " ORDER BY id DESC LIMIT 100";
    $stmt = $pdo->prepare($sql);
    $stmt->execute(['q' => "%$q%"]);
    $rows = $stmt->fetchAll();
    if ($table === 'installment_customers' && $rows) {
        $ids = array_column($rows, 'id');
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $payments = $pdo->prepare("SELECT record_id, COUNT(*) AS payment_count FROM financial_payments WHERE record_type = 'installment_customers' AND record_id IN ($placeholders) GROUP BY record_id");
        $payments->execute($ids);
        $counts = [];
        foreach ($payments->fetchAll() as $paymentRow) {
            $counts[$paymentRow['record_id']] = (int)$paymentRow['payment_count'];
        }
        foreach ($rows as &$row) {
            $row['payment_count'] = $counts[$row['id']] ?? 0;
        }
        unset($row);
    }
    jsonResponse($rows);
}

$installments = fetchAllTable($pdo, 'installment_customers');
$interests = fetchAllTable($pdo, 'interest_loans');
$rents = fetchAllTable($pdo, 'house_rent');
$moi = fetchAllTable($pdo, 'moi_records');
$totalPrincipal = array_sum(array_column($installments, 'principal')) + array_sum(array_column($interests, 'principal_amount'));
$totalOutstanding = array_sum(array_column($installments, 'outstanding_amount')) + array_sum(array_column($interests, 'remaining_principal'));
$interestCollected = array_sum(array_column($interests, 'total_interest_collected'));
$borrowerPending = array_sum(array_column($installments, 'amount_due'));
$paymentAggregate = $pdo->query('SELECT record_type, record_id, COUNT(*) AS payment_count, COALESCE(SUM(amount), 0) AS paid_amount FROM financial_payments GROUP BY record_type, record_id')->fetchAll();
$paymentCountsByRecord = [];
$paymentAmountsByRecord = [];
foreach ($paymentAggregate as $paymentRow) {
    $paymentCountsByRecord[$paymentRow['record_type']][$paymentRow['record_id']] = (int)$paymentRow['payment_count'];
    $paymentAmountsByRecord[$paymentRow['record_type']][$paymentRow['record_id']] = (float)$paymentRow['paid_amount'];
}
$rentPending = 0;
$rentCollected = 0;
foreach ($rents as $rentRow) {
    $recordId = $rentRow['id'];
    $recordPaid = $paymentAmountsByRecord['house_rent'][$recordId] ?? 0;
    if (strtolower((string)($rentRow['status'] ?? '')) === 'paid' && !isset($paymentCountsByRecord['house_rent'][$recordId])) {
        $recordPaid = (float)$rentRow['rental_amount'];
    }
    $recordPaid = min((float)$rentRow['rental_amount'], $recordPaid);
    $rentCollected += $recordPaid;
    $rentPending += max(0, (float)$rentRow['rental_amount'] - $recordPaid);
}
$activeBorrowers = count(array_filter($installments, function($row){ return (float)$row['outstanding_amount'] > 0; }));
$borrowerCollected = array_sum(array_map(function($row){ return max(0, (float)$row['principal'] - (float)$row['outstanding_amount']); }, $installments));
$paidRents = array_filter($rents, function($row){ return strtolower((string)($row['status'] ?? '')) === 'paid'; });
$rentPaidCount = count($paidRents);
$moiVillages = count(array_unique(array_filter(array_map(function($row){ return trim((string)$row['village_name']); }, $moi))));
$moiIncome = array_sum(array_column($moi, 'income'));
$moiExpenses = array_sum(array_column($moi, 'expenses'));

function formatINR($amount){
    $parts = explode('.', number_format((float)$amount, 2, '.', ''));
    $whole = $parts[0];
    if (strlen($whole) > 3) {
        $lastThree = substr($whole, -3);
        $remaining = substr($whole, 0, -3);
        $whole = preg_replace('/\B(?=(\d{2})+(?!\d))/', ',', $remaining) . ',' . $lastThree;
    }
    return '₹' . $whole . '.' . $parts[1];
}

function renderSummaryMetric($icon, $label, $value, $tone = 'green'){
    echo '<div class="metric"><span class="metric-icon metric-icon-' . htmlspecialchars($tone, ENT_QUOTES, 'UTF-8') . '"><i class="' . htmlspecialchars($icon, ENT_QUOTES, 'UTF-8') . '"></i></span><span><span class="metric-label">' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</span><strong class="metric-value">' . htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8') . '</strong></span></div>';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Gram Finance | Dashboard</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <style>
        :root { --canvas: #f6f8fa; --surface: #fff; --ink: #172c35; --muted: #687984; --line: #e1e8ec; --green: #087f65; --green-soft: #e7f4ef; }
        body { background: var(--canvas); color: var(--ink); font-family: Arial, sans-serif; }
        .topbar { background: var(--surface); border-bottom: 1px solid var(--line); }
        .topbar-inner { max-width: 900px; min-height: 58px; margin: 0 auto; padding: 8px 12px; display: flex; align-items: center; gap: 24px; }
        .brand { display: flex; align-items: center; gap: 10px; color: var(--ink); text-decoration: none; min-width: 220px; }
        .brand-mark { display: grid; place-items: center; width: 30px; height: 30px; color: white; background: var(--green); border-radius: 8px; }
        .brand-name { display: block; font-size: 14px; font-weight: 700; line-height: 1.2; }
        .brand-subtitle { display: block; color: var(--muted); font-size: 10px; }
        .primary-nav { display: flex; flex: 1; align-items: center; justify-content: center; gap: 4px; }
        .primary-nav a { color: var(--muted); text-decoration: none; border-radius: 7px; padding: 8px 10px; font-size: 12px; font-weight: 600; white-space: nowrap; }
        .primary-nav a:hover, .primary-nav a.active { color: white; background: var(--green); }
        .theme-toggle { border: 1px solid var(--line); background: var(--surface); color: var(--muted); width: 34px; height: 34px; border-radius: 8px; }
        .page-shell { max-width: 900px; }
        .nav-tabs .nav-link.active { background: var(--green); color: white; }
        .card { border-radius: 10px; box-shadow: 0 2px 6px rgba(23,44,53,.06); }
        .table th, .table td { vertical-align: middle; }
        .btn { border-radius: 8px; }
        .status-paid { color: green; font-weight: bold; }
        .status-pending { color: orange; font-weight: bold; }
        .status-badge { display: inline-block; border: 1px solid var(--line); border-radius: 14px; padding: 3px 8px; font-size: 10px; line-height: 1.2; }
        .status-badge.status-paid { color: #087f65; border-color: #9fe1ca; background: #effbf6; }
        .status-badge.status-partial { color: #a76600; border-color: #f4d68b; background: #fff9e9; }
        .status-badge.status-pending { color: #536779; background: #f6f8fa; }
        .search-box { max-width: 350px; }
        .modal-lg { max-width: 900px; }
        .modal-backdrop.show { opacity: .2; backdrop-filter: blur(4px); }
        .modal-content { border: 1px solid var(--line); border-radius: 12px; background: var(--surface); color: var(--ink); box-shadow: 0 18px 60px rgba(20,35,45,.18); overflow: hidden; }
        .modal-header { align-items: flex-start; padding: 17px 20px 8px; border: 0; }
        .modal-header .modal-title { color: var(--ink); font-size: 15px; font-weight: 700; }
        .modal-body { padding: 10px 20px 18px; }
        .modal-footer { gap: 7px; padding: 12px 20px; background: color-mix(in srgb, var(--canvas) 72%, var(--surface)); border-color: var(--line); }
        .modal .form-label, .modal label { display: block; margin-bottom: 5px; color: var(--ink); font-size: 11px; font-weight: 600; }
        .modal .form-control, .modal .form-select { min-height: 36px; color: var(--ink); background-color: var(--surface); border-color: var(--line); border-radius: 8px; font-size: 12px; }
        .modal textarea.form-control { min-height: 70px; }
        .modal .btn { font-size: 11px; }
        .modal .btn-primary, .modal .btn-success { background: var(--green); border-color: var(--green); }
        .modal-description { margin: 3px 0 0; color: var(--muted); font-size: 11px; line-height: 1.45; }
        .record-details-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 0 18px; margin: 2px 0 0; padding: 14px 16px; border: 1px solid var(--line); border-radius: 11px; background: color-mix(in srgb, var(--canvas) 60%, var(--surface)); }
        .record-detail { min-width: 0; padding: 5px 0 8px; }
        .record-detail dt { margin-bottom: 3px; color: var(--green); font-size: 9px; font-weight: 700; text-transform: uppercase; }
        .record-detail dd { margin: 0; overflow-wrap: anywhere; color: var(--ink); font-size: 12px; }
        .record-tabs { display: flex; gap: 4px; margin: 0 0 12px; padding: 3px; overflow-x: auto; border-radius: 8px; background: color-mix(in srgb, var(--canvas) 82%, var(--surface)); }
        .record-tabs button { flex: 1 0 auto; min-height: 29px; padding: 4px 10px; border: 0; border-radius: 7px; color: var(--muted); background: transparent; font-size: 10px; white-space: nowrap; }
        .record-tabs button.active { color: var(--ink); background: var(--surface); box-shadow: 0 1px 3px rgba(20,35,45,.12); }
        .record-history { padding: 4px 0; color: var(--muted); font-size: 11px; }
        .record-history h6 { color: var(--ink); font-size: 11px; font-weight: 700; }
        .record-history table { width: 100%; margin-top: 8px; font-size: 11px; }
        .record-history th, .record-history td { padding: 8px; border-bottom: 1px solid var(--line); }
        .record-details-actions { display: flex; flex-wrap: wrap; gap: 7px; padding: 0 20px 16px; }
        .record-details-actions .btn { font-size: 11px; }
        .payment-subtitle { margin: 0 0 12px; color: var(--muted); font-size: 11px; }
        .payment-context { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 10px; margin-bottom: 16px; padding: 12px 14px; border: 1px solid var(--line); border-radius: 10px; background: color-mix(in srgb, var(--canvas) 60%, var(--surface)); }
        .payment-context:empty { display: none; }
        .payment-context-item span { display: block; margin-bottom: 3px; color: var(--green); font-size: 9px; font-weight: 700; text-transform: uppercase; }
        .payment-context-item strong { color: var(--ink); font-size: 11px; font-weight: 500; }
        .overview-hero { padding: 22px 24px; border: 1px solid var(--line); border-radius: 13px; background: linear-gradient(115deg, #eefaf5 0%, #fffdf4 100%); box-shadow: 0 2px 5px rgba(23,44,53,.05); }
        .overview-date { display: inline-flex; align-items: center; gap: 6px; color: var(--muted); background: rgba(255,255,255,.8); border: 1px solid var(--line); border-radius: 16px; padding: 4px 9px; font-size: 10px; }
        .overview-hero h1 { margin: 10px 0 3px; color: var(--ink); font-size: 25px; font-weight: 750; }
        .overview-hero p { max-width: 570px; margin: 0; color: var(--muted); font-size: 12px; line-height: 1.55; }
        .metric-grid { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 10px; margin: 20px 0; }
        .metric { min-width: 0; display: flex; align-items: center; gap: 10px; padding: 12px; background: var(--surface); border: 1px solid var(--line); border-radius: 10px; box-shadow: 0 2px 5px rgba(23,44,53,.04); }
        .metric-icon, .section-icon { flex: 0 0 auto; display: grid; place-items: center; width: 34px; height: 34px; border-radius: 9px; color: var(--green); background: var(--green-soft); }
        .metric-icon-orange { color: #bd681f; background: #fff6e8; }
        .metric-icon-slate { color: #536779; background: #eef2f6; }
        .metric-label { color: var(--muted); font-size: 9px; line-height: 1.2; text-transform: uppercase; }
        .metric-value { display: block; margin-top: 4px; color: var(--ink); font-size: 14px; font-weight: 700; white-space: nowrap; }
        .section-grid { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 12px; }
        .section-card { min-height: 208px; display: flex; flex-direction: column; padding: 16px; color: var(--ink); background: var(--surface); border: 1px solid var(--line); border-radius: 12px; box-shadow: 0 2px 5px rgba(23,44,53,.05); text-decoration: none; transition: transform .16s ease, box-shadow .16s ease; }
        .section-card:hover { color: var(--ink); transform: translateY(-2px); box-shadow: 0 6px 14px rgba(23,44,53,.09); }
        .section-card-top { display: flex; align-items: center; justify-content: space-between; }
        .section-card-top > i { color: var(--muted); font-size: 12px; }
        .section-card h2 { margin: 12px 0 3px; font-size: 13px; font-weight: 700; }
        .section-card .tamil-label { color: var(--muted); font-size: 10px; }
        .section-card p { margin: 11px 0 12px; color: var(--muted); font-size: 11px; line-height: 1.45; }
        .section-card-footer { display: flex; justify-content: space-between; align-items: center; gap: 8px; margin-top: auto; padding-top: 10px; border-top: 1px solid var(--line); font-size: 10px; }
        .section-card-footer strong { color: var(--ink); font-size: 11px; white-space: nowrap; }
        .theme-dark { --canvas: #172126; --surface: #202d33; --ink: #edf5f3; --muted: #a9b8bd; --line: #34444b; --green-soft: #21483f; }
        .theme-dark .overview-hero { background: linear-gradient(115deg, #213a33 0%, #37372c 100%); }
        .theme-dark .overview-date { background: var(--surface); }
        @media print {
            .topbar, .module-heading, .module-metrics, .ledger-toolbar, .modal { display: none !important; }
            body { background: #fff; }
            .ledger-panel .table-responsive { overflow: visible; border: 0; box-shadow: none; }
            .ledger-panel .table { min-width: 0; font-size: 9px; white-space: normal; }
        }
        .module-heading { margin: 4px 0 16px; }
        .module-heading h1 { margin: 0 0 4px; color: var(--ink); font-size: 23px; font-weight: 750; }
        .module-heading .tamil-label { margin-left: 5px; color: var(--muted); font-size: 14px; font-weight: 400; }
        .module-heading p { max-width: 650px; margin: 0; color: var(--muted); font-size: 12px; line-height: 1.45; }
        .module-metrics { margin: 0 0 16px; }
        .ledger-panel { padding: 0 !important; background: transparent; border: 0; box-shadow: none; }
        .ledger-panel > .d-flex:first-child { margin: 0 0 14px !important; padding: 10px 12px; background: var(--surface); border: 1px solid var(--line); border-radius: 11px; box-shadow: 0 2px 5px rgba(23,44,53,.04); }
        .ledger-panel > .d-flex:first-child h4 { display: none; }
        .ledger-toolbar { min-width: 0; flex: 1; display: flex; align-items: center; gap: 8px; }
        .ledger-toolbar .form-control, .ledger-toolbar .form-select { min-width: 0; height: 34px; font-size: 11px; }
        .ledger-toolbar .form-control { flex: 1; }
        .ledger-toolbar .form-select { flex: 0 0 145px; }
        .ledger-toolbar .btn { height: 34px; padding: 5px 10px; font-size: 11px; white-space: nowrap; }
        .voice-search { flex: 0 0 34px; width: 34px; padding: 0 !important; }
        .ledger-panel .table-responsive { overflow: auto; background: var(--surface); border: 1px solid var(--line); border-radius: 11px; box-shadow: 0 2px 5px rgba(23,44,53,.04); }
        .ledger-panel .table { min-width: 760px; margin: 0; color: var(--ink); font-size: 11px; white-space: nowrap; }
        .ledger-panel .table > :not(caption) > * > * { padding: 9px 8px; border-color: var(--line); }
        .ledger-panel .table thead th, .theme-dark .ledger-panel .table thead th { position: sticky; top: 0; z-index: 1; color: var(--ink); background: var(--surface); font-weight: 600; }
        .ledger-panel .table-striped > tbody > tr:nth-of-type(odd) > * { color: var(--ink); background: color-mix(in srgb, var(--surface) 96%, var(--canvas)); }
        .ledger-panel .table .btn { padding: 3px 6px; font-size: 10px; }
        @media (max-width: 760px) {
            .topbar-inner { flex-wrap: wrap; gap: 8px; }
            .brand { flex: 1; }
            .primary-nav { order: 3; flex-basis: 100%; justify-content: flex-start; overflow-x: auto; }
            .primary-nav a { padding: 7px 9px; }
            .metric-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
            .section-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
        }
        @media (max-width: 420px) {
            .overview-hero { padding: 18px; }
            .overview-hero h1 { font-size: 22px; }
            .section-card { min-height: 205px; padding: 13px; }
            .module-heading h1 { font-size: 21px; }
            .ledger-panel > .d-flex:first-child { align-items: stretch !important; }
            .ledger-toolbar { flex-wrap: wrap; }
            .ledger-toolbar .form-control { flex: 1 1 100%; }
            .ledger-toolbar .form-select { flex: 1; }
            .record-details-grid, .payment-context { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>

<nav class="topbar">
    <div class="topbar-inner">
        <a class="brand" href="?tab=dashboard">
            <span class="brand-mark"><i class="fa-solid fa-landmark"></i></span>
            <span><span class="brand-name">Gram Finance</span><span class="brand-subtitle">நிதி மேலாண்மை</span></span>
        </a>
        <div class="primary-nav" aria-label="Main navigation">
            <a class="<?= $activeTab === 'dashboard' ? 'active' : '' ?>" href="?tab=dashboard"><i class="fa-solid fa-table-cells-large"></i> Dashboard</a>
            <a class="<?= $activeTab === 'interest' ? 'active' : '' ?>" href="?tab=interest"><i class="fa-solid fa-indian-rupee-sign"></i> Interest</a>
            <a class="<?= $activeTab === 'installment' ? 'active' : '' ?>" href="?tab=installment"><i class="fa-solid fa-hand-holding-dollar"></i> Borrower</a>
            <a class="<?= $activeTab === 'rent' ? 'active' : '' ?>" href="?tab=rent"><i class="fa-solid fa-house"></i> House Rent</a>
            <a class="<?= $activeTab === 'moi' ? 'active' : '' ?>" href="?tab=moi"><i class="fa-regular fa-address-card"></i> MOI</a>
        </div>
        <button class="theme-toggle" id="themeToggle" type="button" aria-label="Toggle dark mode" title="Toggle dark mode"><i class="fa-regular fa-moon"></i></button>
    </div>
</nav>

<main class="container py-4 page-shell">
    <?php if (!empty($formError)): ?>
        <div class="alert alert-danger" role="alert"><?= htmlspecialchars($formError) ?></div>
    <?php elseif (isset($_GET['saved'])): ?>
        <div class="alert alert-success" role="alert">Record added successfully.</div>
    <?php endif; ?>
    <?php if ($activeTab === 'dashboard'): ?>
        <section class="overview-hero">
            <span class="overview-date"><i class="fa-regular fa-calendar"></i> <?= date('j M Y') ?></span>
            <h1>Management Dashboard</h1>
            <p>வட்டி கணக்கு, கடன் தவணைகள், வீட்டு வாடகை மற்றும் MOI பதிவுகளை ஒரே இடத்தில் நிர்வகிக்கவும்.</p>
        </section>

        <section class="metric-grid" aria-label="Financial summary">
            <div class="metric"><span class="metric-icon"><i class="fa-solid fa-wallet"></i></span><span><span class="metric-label">Total Principal</span><strong class="metric-value"><?= formatINR($totalPrincipal) ?></strong></span></div>
            <div class="metric"><span class="metric-icon" style="color:#bd681f;background:#fff6e8"><i class="fa-solid fa-arrow-trend-down"></i></span><span><span class="metric-label">Outstanding Balance</span><strong class="metric-value"><?= formatINR($totalOutstanding) ?></strong></span></div>
            <div class="metric"><span class="metric-icon"><i class="fa-solid fa-chart-line"></i></span><span><span class="metric-label">Interest Collected</span><strong class="metric-value"><?= formatINR($interestCollected) ?></strong></span></div>
            <div class="metric"><span class="metric-icon" style="color:#536779;background:#eef2f6"><i class="fa-solid fa-building-columns"></i></span><span><span class="metric-label">Borrower + Rent Pending</span><strong class="metric-value"><?= formatINR($borrowerPending + $rentPending) ?></strong></span></div>
        </section>

        <section class="section-grid" aria-label="Finance sections">
            <a class="section-card" href="?tab=interest">
                <span class="section-card-top"><span class="section-icon"><i class="fa-solid fa-indian-rupee-sign"></i></span><i class="fa-solid fa-arrow-right"></i></span>
                <h2>Interest</h2><span class="tamil-label">வட்டி கணக்கு</span>
                <p>Money lending ledger with principal, interest collection and printed receipts.</p>
                <span class="section-card-footer"><span><?= count($interests) ?> records</span><strong><?= formatINR(array_sum(array_column($interests, 'principal_amount'))) ?></strong></span>
            </a>
            <a class="section-card" href="?tab=installment">
                <span class="section-card-top"><span class="section-icon"><i class="fa-solid fa-hand-holding-dollar"></i></span><i class="fa-solid fa-arrow-right"></i></span>
                <h2>Borrower</h2><span class="tamil-label">தவணை கடன்</span>
                <p>100-day, 10-week and monthly installment collection with due tracking.</p>
                <span class="section-card-footer"><span><?= count($installments) ?> records</span><strong><?= formatINR($borrowerPending) ?></strong></span>
            </a>
            <a class="section-card" href="?tab=rent">
                <span class="section-card-top"><span class="section-icon"><i class="fa-solid fa-house"></i></span><i class="fa-solid fa-arrow-right"></i></span>
                <h2>House Rent</h2><span class="tamil-label">வாடகை ஒப்பந்தம்</span>
                <p>Rental agreements, collection dates and paid or pending status.</p>
                <span class="section-card-footer"><span><?= count($rents) ?> records</span><strong><?= formatINR($rentPending) ?></strong></span>
            </a>
            <a class="section-card" href="?tab=moi">
                <span class="section-card-top"><span class="section-icon"><i class="fa-regular fa-address-card"></i></span><i class="fa-solid fa-arrow-right"></i></span>
                <h2>MOI / Details</h2><span class="tamil-label">மொய் விவரம்</span>
                <p>Bilingual contact directory with income, expenses and inline editing.</p>
                <span class="section-card-footer"><span><?= count($moi) ?> contacts</span><strong>Directory</strong></span>
            </a>
        </section>
    <?php elseif ($activeTab === 'installment'): ?>
        <header class="module-heading">
            <h1>Borrower Ledger <span class="tamil-label">தவணை கடன்</span></h1>
            <p>Installment borrowers on 100-day, 10-week or monthly plans, with collected balances and upcoming dues.</p>
        </header>
        <section class="metric-grid module-metrics" aria-label="Borrower summary">
            <?php renderSummaryMetric('fa-solid fa-users', 'Records', count($installments), 'slate'); ?>
            <?php renderSummaryMetric('fa-solid fa-hand-holding-dollar', 'Active Borrowers', $activeBorrowers); ?>
            <?php renderSummaryMetric('fa-solid fa-landmark', 'Total Collected', formatINR($borrowerCollected)); ?>
            <?php renderSummaryMetric('fa-regular fa-clock', 'Pending', formatINR($borrowerPending), 'orange'); ?>
        </section>
        <div class="card p-3 ledger-panel">
            <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
                <h4><i class="fa-solid fa-money-bill-wave"></i> Installment</h4>
                <div class="ledger-toolbar">
                    <input type="text" id="installmentSearch" class="form-control" placeholder="Search ID, name, village, mobile, principal...">
                    <button class="btn btn-outline-secondary voice-search" type="button" aria-label="Voice search" title="Voice search"><i class="fa-solid fa-microphone"></i></button>
                    <select class="form-select village-filter" aria-label="Filter by village"><option value="">All Villages</option></select>
                    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#installmentAddModal">
                        <i class="fa-solid fa-plus"></i> New Borrower Record
                    </button>
                </div>
            </div>

            <div class="table-responsive">
                <table class="table table-bordered table-striped">
                    <thead class="table-dark">
                        <tr>
                            <th>ID</th>
                            <th>Name</th>
                            <th>Village</th>
                            <th>Mobile</th>
                            <th>Principal</th>
                            <th>Balance (Collected)</th>
                            <th>Due Count</th>
                            <th>Day Count</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody id="installmentTableBody">
                        <?php foreach ($installments as $row): ?>
                            <tr>
                                <td><?= $row['id'] ?></td>
                                <td><?= htmlspecialchars($row['name']) ?></td>
                                <td><?= htmlspecialchars($row['village']) ?></td>
                                <td><?= htmlspecialchars($row['mobile']) ?></td>
                                <td><?= number_format($row['principal'], 2) ?></td>
                                <td><?= number_format(max(0, (float)$row['principal'] - (float)$row['outstanding_amount']), 2) ?></td>
                                <td><?= $paymentCountsByRecord['installment_customers'][$row['id']] ?? 0 ?></td>
                                <td><?= $row['days_count'] ?></td>
                                <td>
                                    <button class="btn btn-sm btn-info text-white" onclick="viewInstallment(<?= $row['id'] ?>)">
                                        <i class="fa-solid fa-eye"></i> View
                                    </button>
                                    <button class="btn btn-sm btn-success" <?= ($row['outstanding_amount'] <= 0) ? 'disabled' : '' ?> onclick="payInstallment(<?= $row['id'] ?>)">
                                        <i class="fa-solid fa-wallet"></i> Pay
                                    </button>
                                    <button class="btn btn-sm btn-secondary" onclick="printInstallment(<?= $row['id'] ?>)">
                                        <i class="fa-solid fa-print"></i> Print
                                    </button>
                                    <button class="btn btn-sm btn-warning" onclick="editInstallment(<?= $row['id'] ?>)">Edit</button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Add Modal -->
        <div class="modal fade" id="installmentAddModal" tabindex="-1">
            <div class="modal-dialog modal-lg">
                <div class="modal-content">
                    <div class="modal-header">
                        <div><h5 class="modal-title">New Borrower Record</h5><p class="modal-description">Choose a collection plan; the suggested installment is calculated from the principal and schedule.</p></div>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <form method="POST" action="">
                        <div class="modal-body row g-3">
                            <div class="col-md-6">
                                <label>Name</label>
                                <input type="text" name="name" class="form-control" required>
                            </div>
                            <div class="col-md-6">
                                <label>Village</label>
                                <input type="text" name="village" class="form-control" required>
                            </div>
                            <div class="col-md-6">
                                <label>Mobile</label>
                                <input type="text" name="mobile" class="form-control" required>
                            </div>
                            <div class="col-md-6">
                                <label>Principal Amount</label>
                                <input type="number" step="0.01" name="principal" class="form-control" required>
                            </div>
                            <div class="col-md-6">
                                <label>Payment Schedule</label>
                                <select class="form-select" name="payment_schedule">
                                    <option value="100_days">100 Days</option>
                                    <option value="10_weeks">10 Weeks</option>
                                    <option value="5_months">5 Months</option>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label>Date</label>
                                <input type="date" name="payment_date" class="form-control" required>
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                            <button type="submit" class="btn btn-primary" name="save_installment">Add</button>
                            <button type="submit" class="btn btn-success" name="submit_print_installment">Submit & Print</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <script>
            document.getElementById('installmentSearch').addEventListener('input', function () {
                const q = this.value.trim();
                fetch(`dashboard.php?ajax=1&table=installment_customers&q=${encodeURIComponent(q)}`)
                    .then(r => r.json())
                    .then(rows => {
                        const tbody = document.getElementById('installmentTableBody');
                        tbody.innerHTML = '';
                        rows.forEach(row => {
                            tbody.innerHTML += `
                                <tr>
                                    <td>${row.id}</td>
                                    <td>${row.name}</td>
                                    <td>${row.village}</td>
                                    <td>${row.mobile}</td>
                                    <td>${Number(row.principal).toFixed(2)}</td>
                                    <td>${Math.max(0, Number(row.principal) - Number(row.outstanding_amount)).toFixed(2)}</td>
                                    <td>${Number(row.payment_count || 0)}</td>
                                    <td>${row.days_count}</td>
                                    <td>
                                        <button class="btn btn-sm btn-info text-white" onclick="viewInstallment(${row.id})">View</button>
                                        <button class="btn btn-sm btn-success" ${Number(row.outstanding_amount) <= 0 ? 'disabled' : ''} onclick="payInstallment(${row.id})">Pay</button>
                                        <button class="btn btn-sm btn-secondary" onclick="printInstallment(${row.id})">Print</button>
                                        <button class="btn btn-sm btn-warning" onclick="editInstallment(${row.id})">Edit</button>
                                    </td>
                                </tr>
                            `;
                        });
                        applyLedgerFilters();
                    });
            });

            function viewInstallment(id) { viewRecord('installment_customers', id); }
            function payInstallment(id) { openPayment('installment_customers', id); }
            function printInstallment(id) { printRecord('installment_customers', id); }
            function editInstallment(id) { openEditor('installment_customers', id); }
        </script>

    <?php elseif ($activeTab === 'interest'): ?>
        <header class="module-heading">
            <h1>Interest Ledger <span class="tamil-label">வட்டி கணக்கு</span></h1>
            <p>Moneylending accounts with interest and principal collection, receipts and payment history.</p>
        </header>
        <section class="metric-grid module-metrics" aria-label="Interest summary">
            <?php renderSummaryMetric('fa-solid fa-users', 'Records', count($interests), 'slate'); ?>
            <?php renderSummaryMetric('fa-solid fa-wallet', 'Total Principal', formatINR(array_sum(array_column($interests, 'principal_amount')))); ?>
            <?php renderSummaryMetric('fa-solid fa-building-columns', 'Outstanding Balance', formatINR(array_sum(array_column($interests, 'remaining_principal'))), 'orange'); ?>
            <?php renderSummaryMetric('fa-solid fa-money-bill-transfer', 'Interest Collected', formatINR($interestCollected)); ?>
        </section>
        <div class="card p-3 ledger-panel">
            <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
                <h4><i class="fa-solid fa-percent"></i> Interest</h4>
                <div class="ledger-toolbar">
                    <input type="text" id="interestSearch" class="form-control" placeholder="Search ID, name, village, mobile, principal...">
                    <button class="btn btn-outline-secondary voice-search" type="button" aria-label="Voice search" title="Voice search"><i class="fa-solid fa-microphone"></i></button>
                    <select class="form-select village-filter" aria-label="Filter by village"><option value="">All Villages</option></select>
                    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#interestAddModal">
                        <i class="fa-solid fa-plus"></i> New Interest Record
                    </button>
                </div>
            </div>

            <div class="table-responsive">
                <table class="table table-bordered table-striped">
                    <thead class="table-dark">
                        <tr>
                            <th>ID</th>
                            <th>Name</th>
                            <th>Village</th>
                            <th>Mobile</th>
                            <th>Principal</th>
                            <th>Principal Balance</th>
                            <th>Rate %</th>
                            <th>Transaction Date</th>
                            <th>No. of Payments</th>
                            <th>Total Interest Collected</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody id="interestTableBody">
                        <?php foreach ($interests as $row): ?>
                            <tr>
                                <td><?= $row['id'] ?></td>
                                <td><?= htmlspecialchars($row['name']) ?></td>
                                <td><?= htmlspecialchars($row['village']) ?></td>
                                <td><?= htmlspecialchars($row['mobile']) ?></td>
                                <td><?= number_format($row['principal_amount'], 2) ?></td>
                                <td><?= number_format($row['remaining_principal'], 2) ?></td>
                                <td><?= number_format($row['interest_rate'], 2) ?>%</td>
                                <td><?= $row['loan_date'] ?></td>
                                <td><?= $row['installments'] ?></td>
                                <td><?= number_format($row['total_interest_collected'], 2) ?></td>
                                <td>
                                    <button class="btn btn-sm btn-info text-white" onclick="viewInterest(<?= $row['id'] ?>)">View</button>
                                    <button class="btn btn-sm btn-success" <?= ($row['remaining_principal'] <= 0) ? 'disabled' : '' ?> onclick="payInterest(<?= $row['id'] ?>)">Pay</button>
                                    <button class="btn btn-sm btn-secondary" onclick="printInterest(<?= $row['id'] ?>)">Print</button>
                                    <button class="btn btn-sm btn-warning" onclick="editInterest(<?= $row['id'] ?>)">Edit</button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="modal fade" id="interestAddModal" tabindex="-1">
            <div class="modal-dialog modal-lg">
                <div class="modal-content">
                    <div class="modal-header">
                        <div><h5 class="modal-title">New Interest Record</h5><p class="modal-description">Create an account with its principal, interest rate and transaction date.</p></div>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <form method="POST" action="">
                        <div class="modal-body row g-3">
                            <div class="col-md-6"><label>Name</label><input type="text" name="name" class="form-control" required></div>
                            <div class="col-md-6"><label>Village</label><input type="text" name="village" class="form-control" required></div>
                            <div class="col-md-6"><label>Mobile</label><input type="text" name="mobile" class="form-control" required></div>
                            <div class="col-md-6"><label>Principal Amount</label><input type="number" step="0.01" name="principal_amount" class="form-control" required></div>
                            <div class="col-md-6"><label>Interest Rate</label><input type="number" step="0.01" name="interest_rate" class="form-control" required></div>
                            <div class="col-md-6"><label>Transaction Date</label><input type="date" name="loan_date" class="form-control" required></div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                            <button type="submit" class="btn btn-primary" name="save_interest">Save</button>
                            <button type="submit" class="btn btn-success" name="submit_print_interest">Submit & Print</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <script>
            document.getElementById('interestSearch').addEventListener('input', function () {
                const q = this.value.trim();
                fetch(`dashboard.php?ajax=1&table=interest_loans&q=${encodeURIComponent(q)}`)
                    .then(r => r.json())
                    .then(rows => {
                        const tbody = document.getElementById('interestTableBody');
                        tbody.innerHTML = '';
                        rows.forEach(row => {
                            tbody.innerHTML += `
                                <tr>
                                    <td>${row.id}</td>
                                    <td>${row.name}</td>
                                    <td>${row.village}</td>
                                    <td>${row.mobile}</td>
                                    <td>${Number(row.principal_amount).toFixed(2)}</td>
                                    <td>${Number(row.remaining_principal).toFixed(2)}</td>
                                    <td>${Number(row.interest_rate).toFixed(2)}%</td>
                                    <td>${row.loan_date}</td>
                                    <td>${row.installments}</td>
                                    <td>${Number(row.total_interest_collected).toFixed(2)}</td>
                                    <td>
                                        <button class="btn btn-sm btn-info text-white" onclick="viewInterest(${row.id})">View</button>
                                        <button class="btn btn-sm btn-success" ${Number(row.remaining_principal) <= 0 ? 'disabled' : ''} onclick="payInterest(${row.id})">Pay</button>
                                        <button class="btn btn-sm btn-secondary" onclick="printInterest(${row.id})">Print</button>
                                        <button class="btn btn-sm btn-warning" onclick="editInterest(${row.id})">Edit</button>
                                    </td>
                                </tr>
                            `;
                        });
                        applyLedgerFilters();
                    });
            });

            function viewInterest(id) { viewRecord('interest_loans', id); }
            function payInterest(id) { openPayment('interest_loans', id); }
            function printInterest(id) { printRecord('interest_loans', id); }
            function editInterest(id) { openEditor('interest_loans', id); }
        </script>

    <?php elseif ($activeTab === 'rent'): ?>
        <header class="module-heading">
            <h1>House Rent <span class="tamil-label">வாடகை ஒப்பந்தம்</span></h1>
            <p>Rental agreements with payment tracking. Record rent collection to update paid status.</p>
        </header>
        <section class="metric-grid module-metrics" aria-label="House rent summary">
            <?php renderSummaryMetric('fa-solid fa-house', 'Agreements', count($rents), 'slate'); ?>
            <?php renderSummaryMetric('fa-regular fa-circle-check', 'Fully Paid', $rentPaidCount); ?>
            <?php renderSummaryMetric('fa-solid fa-wallet', 'Collected', formatINR($rentCollected)); ?>
            <?php renderSummaryMetric('fa-regular fa-clipboard', 'Pending Rent', formatINR($rentPending), 'orange'); ?>
        </section>
        <div class="card p-3 ledger-panel">
            <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
                <h4><i class="fa-solid fa-house-user"></i> House Rent</h4>
                <div class="ledger-toolbar">
                    <input type="text" id="rentSearch" class="form-control" placeholder="Search ID, name, village, mobile, rent...">
                    <button class="btn btn-outline-secondary voice-search" type="button" aria-label="Voice search" title="Voice search"><i class="fa-solid fa-microphone"></i></button>
                    <select class="form-select village-filter" aria-label="Filter by village"><option value="">All Villages</option></select>
                    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#rentAddModal">
                        <i class="fa-solid fa-plus"></i> New Rent Record
                    </button>
                </div>
            </div>

            <div class="table-responsive">
                <table class="table table-bordered table-striped">
                    <thead class="table-dark">
                        <tr>
                            <th>ID</th>
                            <th>Name</th>
                            <th>Village</th>
                            <th>Mobile</th>
                            <th>Rent Amount</th>
                            <th>Agreement Date</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody id="rentTableBody">
                        <?php foreach ($rents as $row): ?>
                            <tr>
                                <td><?= $row['id'] ?></td>
                                <td><?= htmlspecialchars($row['name']) ?></td>
                                <td><?= htmlspecialchars($row['village']) ?></td>
                                <td><?= htmlspecialchars($row['mobile']) ?></td>
                                <td><?= number_format($row['rental_amount'], 2) ?></td>
                                <td><?= $row['contract_date'] ?></td>
                                <td>
                                    <?php $rentStatus = strtolower((string)($row['status'] ?? 'pending')); ?>
                                    <span class="status-badge status-<?= in_array($rentStatus, ['paid', 'partial', 'pending'], true) ? $rentStatus : 'pending' ?>">
                                        <?= htmlspecialchars(ucfirst($rentStatus)) ?>
                                    </span>
                                </td>
                                <td>
                                    <button class="btn btn-sm btn-warning" onclick="editRent(<?= $row['id'] ?>)">Edit</button>
                                    <button class="btn btn-sm btn-info text-white" onclick="viewRent(<?= $row['id'] ?>)">View</button>
                                    <button class="btn btn-sm btn-success" <?= $row['status'] === 'paid' ? 'disabled' : '' ?> onclick="payRent(<?= $row['id'] ?>)">Pay Rent</button>
                                    <button class="btn btn-sm btn-secondary" onclick="printRent(<?= $row['id'] ?>)">Print</button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="modal fade" id="rentAddModal" tabindex="-1">
            <div class="modal-dialog modal-lg">
                <div class="modal-content">
                    <div class="modal-header">
                        <div><h5 class="modal-title">New Rent Record</h5><p class="modal-description">Register a rental agreement. New agreements begin with pending status.</p></div>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <form method="POST" action="">
                        <div class="modal-body row g-3">
                            <div class="col-md-6"><label>Name</label><input type="text" name="name" class="form-control" required></div>
                            <div class="col-md-6"><label>Village</label><input type="text" name="village" class="form-control" required></div>
                            <div class="col-md-6"><label>Mobile</label><input type="text" name="mobile" class="form-control" required></div>
                            <div class="col-md-6"><label>Rental Amount</label><input type="number" step="0.01" name="rental_amount" class="form-control" required></div>
                            <div class="col-md-6"><label>Contract Date</label><input type="date" name="contract_date" class="form-control" required></div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                            <button type="submit" class="btn btn-primary" name="save_rent">Save</button>
                            <button type="submit" class="btn btn-success" name="submit_print_rent">Submit & Print</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <script>
            document.getElementById('rentSearch').addEventListener('input', function () {
                const q = this.value.trim();
                fetch(`dashboard.php?ajax=1&table=house_rent&q=${encodeURIComponent(q)}`)
                    .then(r => r.json())
                    .then(rows => {
                        const tbody = document.getElementById('rentTableBody');
                        tbody.innerHTML = '';
                        rows.forEach(row => {
                            const rentStatus = ['paid', 'partial', 'pending'].includes(String(row.status || '').toLowerCase()) ? String(row.status).toLowerCase() : 'pending';
                            tbody.innerHTML += `
                                <tr>
                                    <td>${row.id}</td>
                                    <td>${row.name}</td>
                                    <td>${row.village}</td>
                                    <td>${row.mobile}</td>
                                    <td>${Number(row.rental_amount).toFixed(2)}</td>
                                    <td>${row.contract_date}</td>
                                    <td><span class="status-badge status-${rentStatus}">${rentStatus.charAt(0).toUpperCase() + rentStatus.slice(1)}</span></td>
                                    <td>
                                        <button class="btn btn-sm btn-warning" onclick="editRent(${row.id})">Edit</button>
                                        <button class="btn btn-sm btn-info text-white" onclick="viewRent(${row.id})">View</button>
                                        <button class="btn btn-sm btn-success" ${row.status === 'paid' ? 'disabled' : ''} onclick="payRent(${row.id})">Pay Rent</button>
                                        <button class="btn btn-sm btn-secondary" onclick="printRent(${row.id})">Print</button>
                                    </td>
                                </tr>
                            `;
                        });
                        applyLedgerFilters();
                    });
            });

            function editRent(id) { openEditor('house_rent', id); }
            function viewRent(id) { viewRecord('house_rent', id); }
            function payRent(id) { openPayment('house_rent', id); }
            function printRent(id) { printRecord('house_rent', id); }
        </script>

    <?php else: ?>
        <header class="module-heading">
            <h1>MOI / Details <span class="tamil-label">மொய் விவரம்</span></h1>
            <p>Bilingual contact and event directory with income, expenses and additional details.</p>
        </header>
        <section class="metric-grid module-metrics" aria-label="MOI summary">
            <?php renderSummaryMetric('fa-regular fa-address-card', 'Contacts', count($moi), 'slate'); ?>
            <?php renderSummaryMetric('fa-solid fa-location-dot', 'Villages', $moiVillages); ?>
            <?php renderSummaryMetric('fa-solid fa-arrow-trend-up', 'Total Income', formatINR($moiIncome)); ?>
            <?php renderSummaryMetric('fa-solid fa-arrow-trend-down', 'Total Expenditure', formatINR($moiExpenses), 'orange'); ?>
        </section>
        <div class="card p-3 ledger-panel">
            <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
                <h4><i class="fa-solid fa-user"></i> Moi</h4>
                <div class="ledger-toolbar">
                    <input type="text" id="moiSearch" class="form-control" placeholder="Search all fields, village, mobile, details...">
                    <button class="btn btn-outline-secondary voice-search" type="button" aria-label="Voice search" title="Voice search"><i class="fa-solid fa-microphone"></i></button>
                    <select class="form-select village-filter" aria-label="Filter by village"><option value="">All Villages</option></select>
                    <button class="btn btn-outline-secondary" type="button" onclick="window.print()"><i class="fa-solid fa-print"></i> Print PDF</button>
                    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#moiAddModal">
                        <i class="fa-solid fa-plus"></i> New Record
                    </button>
                </div>
            </div>

            <div class="table-responsive">
                <table class="table table-bordered table-striped">
                    <thead class="table-dark">
                        <tr>
                            <th>ID</th>
                            <th>Name (Tamil)</th>
                            <th>Name (English)</th>
                            <th>Village (Tamil)</th>
                            <th>Village (English)</th>
                            <th>Mobile</th>
                            <th>Alternate Mobile</th>
                            <th>City</th>
                            <th>Income</th>
                            <th>Expenditure</th>
                            <th>Information</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody id="moiTableBody">
                        <?php foreach ($moi as $row): ?>
                            <tr>
                                <td><?= $row['id'] ?></td>
                                <td><?= htmlspecialchars($row['name_tamil'] ?? '') ?: '-' ?></td>
                                <td><?= htmlspecialchars($row['name_english'] ?? $row['name']) ?></td>
                                <td><?= htmlspecialchars($row['village_tamil'] ?? '') ?: '-' ?></td>
                                <td><?= htmlspecialchars($row['village_english'] ?? $row['village_name']) ?></td>
                                <td><?= htmlspecialchars($row['mobile']) ?></td>
                                <td><?= htmlspecialchars($row['alternate_mobile'] ?? '') ?: '-' ?></td>
                                <td><?= htmlspecialchars($row['city']) ?></td>
                                <td><?= number_format($row['income'], 2) ?></td>
                                <td><?= number_format($row['expenses'], 2) ?></td>
                                <td><?= htmlspecialchars($row['additional_info']) ?></td>
                                <td>
                                    <button class="btn btn-sm btn-info text-white" onclick="viewMoi(<?= $row['id'] ?>)">View</button>
                                    <button class="btn btn-sm btn-warning" onclick="editMoi(<?= $row['id'] ?>)">Edit</button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="modal fade" id="moiAddModal" tabindex="-1">
            <div class="modal-dialog modal-lg">
                <div class="modal-content">
                    <div class="modal-header">
                        <div><h5 class="modal-title">New MOI Record</h5><p class="modal-description">Add contact, income and expense details to the directory.</p></div>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <form method="POST" action="">
                        <div class="modal-body row g-3">
                            <div class="col-md-6"><label>Name (Tamil)</label><input type="text" name="name_tamil" class="form-control" placeholder="e.g. ஆனந்த் பிரியன்"></div>
                            <div class="col-md-6"><label>Name (English)</label><input type="text" name="name_english" class="form-control" placeholder="e.g. Anand Priyan" required></div>
                            <div class="col-md-6"><label>Village (Tamil)</label><input type="text" name="village_tamil" class="form-control" placeholder="e.g. கோவில்பட்டி"></div>
                            <div class="col-md-6"><label>Village (English)</label><input type="text" name="village_english" class="form-control" placeholder="e.g. Kovilpatti" required></div>
                            <div class="col-md-6"><label>Mobile</label><input type="text" name="mobile" class="form-control" placeholder="10-digit mobile" required></div>
                            <div class="col-md-6"><label>City</label><input type="text" name="city" class="form-control" required></div>
                            <div class="col-md-6"><label>Date</label><input type="date" name="record_date" class="form-control" required></div>
                            <div class="col-md-6"><label>Income</label><input type="number" step="0.01" name="income" class="form-control" required></div>
                            <div class="col-md-6"><label>Expenses</label><input type="number" step="0.01" name="expenses" class="form-control" required></div>
                            <div class="col-md-6"><label>Alternate Mobile</label><input type="text" name="alternate_mobile" class="form-control"></div>
                            <div class="col-12"><label>Additional Information</label><textarea name="additional_info" class="form-control"></textarea></div>
                            <div class="col-12"><label>Updated Details</label><textarea name="updated_details" class="form-control"></textarea></div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                            <button type="submit" class="btn btn-primary" name="save_moi">Save</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <script>
            document.getElementById('moiSearch').addEventListener('input', function () {
                const q = this.value.trim();
                fetch(`dashboard.php?ajax=1&table=moi_records&q=${encodeURIComponent(q)}`)
                    .then(r => r.json())
                    .then(rows => {
                        const tbody = document.getElementById('moiTableBody');
                        tbody.innerHTML = '';
                        rows.forEach(row => {
                            tbody.innerHTML += `
                                <tr>
                                    <td>${row.id}</td>
                                    <td>${row.name_tamil || ''}</td>
                                    <td>${row.name_english || row.name || ''}</td>
                                    <td>${row.village_tamil || ''}</td>
                                    <td>${row.village_english || row.village_name || ''}</td>
                                    <td>${row.mobile}</td>
                                    <td>${row.alternate_mobile || '-'}</td>
                                    <td>${row.city}</td>
                                    <td>${Number(row.income).toFixed(2)}</td>
                                    <td>${Number(row.expenses).toFixed(2)}</td>
                                    <td>${row.additional_info || ''}</td>
                                    <td>
                                        <button class="btn btn-sm btn-info text-white" onclick="viewMoi(${row.id})">View</button>
                                        <button class="btn btn-sm btn-warning" onclick="editMoi(${row.id})">Edit</button>
                                    </td>
                                </tr>
                            `;
                        });
                        applyLedgerFilters();
                    });
            });

            function editMoi(id) { openEditor('moi_records', id); }
            function viewMoi(id) { viewRecord('moi_records', id); }
        </script>
    <?php endif; ?>

    <div class="modal fade" id="recordDetailsModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header"><div><h5 class="modal-title" id="recordDetailsTitle">Record details</h5><p class="modal-description" id="recordDetailsSubtitle">Record profile and current account information.</p></div><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
                <div class="modal-body"><div id="recordDetailsTabs"></div><div id="recordDetailsBody"></div></div>
                <div class="record-details-actions" id="recordDetailsActions"></div>
            </div>
        </div>
    </div>
    <div class="modal fade" id="recordEditModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-scrollable">
            <form class="modal-content" id="recordEditForm">
                <div class="modal-header"><div><h5 class="modal-title" id="recordEditTitle">Edit record</h5><p class="modal-description">Update the fields below and save your changes.</p></div><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
                <div class="modal-body row g-3" id="recordEditBody"></div>
                <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button><button type="submit" class="btn btn-primary">Save changes</button></div>
            </form>
        </div>
    </div>
    <div class="modal fade" id="recordPaymentModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog">
            <form class="modal-content" id="recordPaymentForm">
                <div class="modal-header"><div><h5 class="modal-title" id="recordPaymentTitle">Record payment</h5><p class="payment-subtitle" id="paymentSubtitle"></p></div><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
                <div class="modal-body">
                    <div class="payment-context" id="paymentContext"></div>
                    <div id="paymentAmountWrap">
                        <label class="form-label" for="paymentAmount" id="paymentAmountLabel">Amount (₹)</label>
                        <input class="form-control" type="number" min="0.01" step="0.01" id="paymentAmount" name="amount" required>
                    </div>
                    <div class="mt-3 d-none" id="interestPaymentTypeWrap">
                        <label class="form-label" for="interestPaymentType">Payment applies to</label>
                        <select class="form-select" id="interestPaymentType" name="payment_type"><option value="principal">Principal</option><option value="interest">Interest</option></select>
                    </div>
                    <p class="mb-0 d-none" id="rentPaymentNotice">Rent status updates automatically to Partial or Paid based on the total collected.</p>
                    <div class="mt-3">
                        <label class="form-label" for="paymentDate">Date</label>
                        <input class="form-control" type="date" id="paymentDate" name="payment_date" value="<?= date('Y-m-d') ?>" required>
                    </div>
                </div>
                <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button><button type="submit" class="btn btn-outline-success" id="paymentSubmitButton" value="save">Save</button><button type="submit" class="btn btn-success" id="paymentPrintButton" value="print">Submit &amp; Print</button></div>
            </form>
        </div>
    </div>
</main>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
    const themeToggle = document.getElementById('themeToggle');
    const savedTheme = localStorage.getItem('gram-finance-theme');
    if (savedTheme === 'dark') document.body.classList.add('theme-dark');
    themeToggle.innerHTML = document.body.classList.contains('theme-dark')
        ? '<i class="fa-regular fa-sun"></i>'
        : '<i class="fa-regular fa-moon"></i>';
    themeToggle.addEventListener('click', () => {
        document.body.classList.toggle('theme-dark');
        const isDark = document.body.classList.contains('theme-dark');
        localStorage.setItem('gram-finance-theme', isDark ? 'dark' : 'light');
        themeToggle.innerHTML = isDark ? '<i class="fa-regular fa-sun"></i>' : '<i class="fa-regular fa-moon"></i>';
    });

    const villageFilter = document.querySelector('.village-filter');
    function applyLedgerFilters() {
        const tableBody = document.querySelector('.ledger-panel tbody');
        if (!tableBody) return;
        const selectedVillage = villageFilter?.value.trim().toLowerCase() || '';
        Array.from(tableBody.rows).forEach(row => {
            const village = row.cells[2]?.textContent.trim().toLowerCase() || '';
            row.hidden = Boolean(selectedVillage && village !== selectedVillage);
        });
    }
    if (villageFilter) {
        const tableBody = document.querySelector('.ledger-panel tbody');
        const villages = new Set(Array.from(tableBody?.rows || []).map(row => row.cells[2]?.textContent.trim()).filter(Boolean));
        Array.from(villages).sort((left, right) => left.localeCompare(right)).forEach(village => {
            const option = document.createElement('option');
            option.value = village;
            option.textContent = village;
            villageFilter.append(option);
        });
        villageFilter.addEventListener('change', applyLedgerFilters);
    }
    document.querySelectorAll('.voice-search').forEach(button => {
        button.addEventListener('click', () => {
            const SpeechRecognition = window.SpeechRecognition || window.webkitSpeechRecognition;
            if (!SpeechRecognition) {
                alert('Voice search is not supported by this browser.');
                return;
            }
            const searchInput = button.closest('.ledger-toolbar').querySelector('input[type="text"]');
            const recognition = new SpeechRecognition();
            recognition.lang = 'en-IN';
            recognition.onresult = event => {
                searchInput.value = event.results[0][0].transcript;
                searchInput.dispatchEvent(new Event('input', { bubbles: true }));
            };
            recognition.onerror = () => alert('Voice search could not be completed.');
            recognition.start();
        });
    });

    const recordLabels = {
        installment_customers: 'Installment',
        interest_loans: 'Interest loan',
        house_rent: 'House rent',
        moi_records: 'Moi record'
    };
    const recordFields = {
        installment_customers: ['name','village','mobile','principal','payment_schedule','payment_date'],
        interest_loans: ['name','village','mobile','interest_rate','loan_date'],
        house_rent: ['name','village','mobile','rental_amount','contract_date'],
        moi_records: ['name_tamil','name_english','village_tamil','village_english','mobile','city','record_date','income','expenses','additional_info','alternate_mobile','updated_details']
    };
    const dateFields = new Set(['payment_date','loan_date','contract_date','record_date']);
    const numericFields = new Set(['principal','interest_rate','rental_amount','income','expenses']);

    function escapeHtml(value) {
        return String(value ?? '').replace(/[&<>"']/g, char => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[char]));
    }
    function fieldLabel(field) {
        return field.replaceAll('_', ' ').replace(/\b\w/g, char => char.toUpperCase());
    }
    function formatMoney(value) {
        return new Intl.NumberFormat('en-IN', { style: 'currency', currency: 'INR', minimumFractionDigits: 2 }).format(Number(value) || 0);
    }
    function formatDisplayDate(value) {
        const match = String(value ?? '').match(/^(\d{4})-(\d{2})-(\d{2})/);
        if (!match) return value || '-';
        return new Intl.DateTimeFormat('en-GB', { day: '2-digit', month: 'short', year: 'numeric', timeZone: 'UTC' })
            .format(new Date(Date.UTC(Number(match[1]), Number(match[2]) - 1, Number(match[3]))));
    }
    function detailGrid(fields) {
        return `<dl class="record-details-grid">${fields.map(([label, value]) =>
            `<div class="record-detail"><dt>${escapeHtml(label)}</dt><dd>${escapeHtml(value ?? '-') || '-'}</dd></div>`
        ).join('')}</dl>`;
    }
    function paymentHistoryTable(payments, types = null) {
        const visiblePayments = types ? payments.filter(payment => types.includes(payment.payment_type)) : payments;
        if (!visiblePayments.length) {
            return '<table><thead><tr><th>Date</th><th>Type</th><th class="text-end">Amount</th></tr></thead><tbody><tr><td colspan="3">No payments have been recorded yet.</td></tr></tbody></table>';
        }
        return `<table><thead><tr><th>Date</th><th>Type</th><th class="text-end">Amount</th></tr></thead><tbody>${visiblePayments.map(payment =>
            `<tr><td>${escapeHtml(formatDisplayDate(payment.payment_date))}</td><td>${escapeHtml(fieldLabel(payment.payment_type))}</td><td class="text-end">${escapeHtml(formatMoney(payment.amount))}</td></tr>`
        ).join('')}</tbody></table>`;
    }
    function renderRecordDetails(table, record, payments = [], tab = 'profile') {
        const identity = [record.name, record.village || record.village_name, record.mobile].filter(Boolean).join(' · ');
        const tabs = table === 'interest_loans'
            ? [['profile', 'Customer Details'], ['interest', 'Interest Collection'], ['principal', 'Principal Collection']]
            : table === 'installment_customers'
                ? [['profile', 'Customer Details'], ['history', 'Transaction History']]
                : [];
        document.getElementById('recordDetailsTabs').innerHTML = tabs.length
            ? `<div class="record-tabs" role="tablist">${tabs.map(([key, label]) => `<button type="button" role="tab" data-details-tab="${key}" class="${tab === key ? 'active' : ''}">${label}</button>`).join('')}</div>`
            : '';
        document.getElementById('recordDetailsTabs').querySelectorAll('[data-details-tab]').forEach(button => {
            button.addEventListener('click', () => renderRecordDetails(table, record, payments, button.dataset.detailsTab));
        });

        let content = '';
        if (table === 'interest_loans') {
            if (tab === 'interest') {
                content = detailGrid([['Principal', formatMoney(record.principal_amount)], ['Rate of Interest', `${record.interest_rate}%`], ['Total Interest Collected', formatMoney(record.total_interest_collected)], ['Remaining Principal', formatMoney(record.remaining_principal)]]) + `<div class="record-history mt-3"><h6>Interest Collection</h6>${paymentHistoryTable(payments, ['interest'])}</div>`;
            } else if (tab === 'principal') {
                content = detailGrid([['Original Principal', formatMoney(record.principal_amount)], ['Remaining Principal', formatMoney(record.remaining_principal)], ['No. of Payments', record.installments]]) + `<div class="record-history mt-3"><h6>Principal Collection</h6>${paymentHistoryTable(payments, ['principal'])}</div>`;
            } else {
                content = detailGrid([['Name', record.name], ['Village', record.village], ['Mobile', record.mobile], ['Rate of Interest', `${record.interest_rate}%`], ['Principal', formatMoney(record.principal_amount)], ['Remaining Principal', formatMoney(record.remaining_principal)], ['Transaction Date', formatDisplayDate(record.loan_date)], ['No. of Payments', record.installments], ['Total Interest Collected', formatMoney(record.total_interest_collected)]]);
            }
        } else if (table === 'installment_customers') {
            if (tab === 'history') {
                content = `<div class="record-history"><h6>Transaction History</h6>${paymentHistoryTable(payments, ['installment'])}</div>`;
            } else {
                const collected = Number(record.principal) - Number(record.outstanding_amount);
                const scheduleLabels = { '100_days': '100 Days', '10_weeks': '10 Weeks', '5_months': '5 Months' };
                content = detailGrid([['Name', record.name], ['Village', record.village], ['Mobile', record.mobile], ['Payment Schedule', scheduleLabels[record.payment_schedule] || record.payment_schedule], ['Principal', formatMoney(record.principal)], ['Collected', formatMoney(collected)], ['Pending', formatMoney(record.outstanding_amount)], ['Suggested Installment', formatMoney(record.amount_due)], ['Transaction Date', formatDisplayDate(record.payment_date)], ['Day Count', record.days_count]]);
            }
        } else if (table === 'house_rent') {
            content = detailGrid([['Name', record.name], ['Village', record.village], ['Mobile', record.mobile], ['Rent Amount', formatMoney(record.rental_amount)], ['Agreement Date', formatDisplayDate(record.contract_date)], ['Status', record.status], ['Total Paid', formatMoney(record.paid_amount)], ['Balance', formatMoney(Math.max(0, Number(record.rental_amount) - Number(record.paid_amount)))]] ) + `<div class="record-history mt-3"><h6>Payment History</h6>${paymentHistoryTable(payments, ['rent'])}</div>`;
        } else {
            content = detailGrid([['Name (Tamil)', record.name_tamil], ['Name (English)', record.name_english || record.name], ['Village (Tamil)', record.village_tamil], ['Village (English)', record.village_english || record.village_name], ['Mobile', record.mobile], ['Alternate Mobile', record.alternate_mobile], ['City', record.city], ['Income', formatMoney(record.income)], ['Expenditure', formatMoney(record.expenses)], ['Information', record.additional_info], ['Updated Details', record.updated_details]]);
        }
        document.getElementById('recordDetailsBody').innerHTML = content;
        document.getElementById('recordDetailsSubtitle').textContent = identity || `Record #${record.id}`;

        const actions = table === 'interest_loans'
            ? `<button class="btn btn-outline-secondary" type="button" onclick="printRecord('${table}', ${Number(record.id)})"><i class="fa-solid fa-print"></i> Print Details</button><button class="btn btn-success" type="button" onclick="openPayment('${table}', ${Number(record.id)}, 'interest')">Interest Collection</button><button class="btn btn-outline-secondary" type="button" onclick="openPayment('${table}', ${Number(record.id)}, 'principal')">Principal Collection</button>`
            : table === 'installment_customers'
                ? `<button class="btn btn-outline-secondary" type="button" onclick="printRecord('${table}', ${Number(record.id)})"><i class="fa-solid fa-print"></i> Print Details</button><button class="btn btn-success" type="button" onclick="openPayment('${table}', ${Number(record.id)})">Pay Due</button>`
                : table === 'house_rent'
                    ? `<button class="btn btn-outline-secondary" type="button" onclick="printRecord('${table}', ${Number(record.id)})"><i class="fa-solid fa-print"></i> Print</button><button class="btn btn-success" type="button" ${String(record.status).toLowerCase() === 'paid' ? 'disabled' : ''} onclick="openPayment('${table}', ${Number(record.id)})">Pay Rent</button>`
                    : `<button class="btn btn-outline-secondary" type="button" onclick="printRecord('${table}', ${Number(record.id)})"><i class="fa-solid fa-print"></i> Print Details</button><button class="btn btn-success" type="button" onclick="openEditor('${table}', ${Number(record.id)})">Edit</button>`;
        document.getElementById('recordDetailsActions').innerHTML = actions;
    }
    async function fetchRecord(table, id) {
        const response = await fetch(`dashboard.php?ajax=1&record=1&table=${encodeURIComponent(table)}&id=${encodeURIComponent(id)}`);
        if (!response.ok) throw new Error('Unable to load this record.');
        const record = await response.json();
        if (!record.id) throw new Error('Record not found.');
        return record;
    }
    async function fetchPaymentHistory(table, id) {
        if (!['installment_customers', 'interest_loans', 'house_rent'].includes(table)) return [];
        const response = await fetch(`dashboard.php?ajax=1&history=1&table=${encodeURIComponent(table)}&id=${encodeURIComponent(id)}`);
        if (!response.ok) throw new Error('Unable to load payment history.');
        return response.json();
    }
    function showActionError(error) {
        alert(error.message || 'The action could not be completed.');
    }
    async function viewRecord(table, id) {
        try {
            const record = await fetchRecord(table, id);
            const payments = await fetchPaymentHistory(table, id);
            document.getElementById('recordDetailsTitle').textContent = table === 'house_rent' ? 'Rent Details' : table === 'installment_customers' ? 'Borrower Details' : table === 'interest_loans' ? 'Customer Details' : 'MOI Details';
            renderRecordDetails(table, record, payments);
            bootstrap.Modal.getOrCreateInstance(document.getElementById('recordDetailsModal')).show();
        } catch (error) { showActionError(error); }
    }
    async function openEditor(table, id) {
        try {
            const record = await fetchRecord(table, id);
            const body = document.getElementById('recordEditBody');
            body.innerHTML = recordFields[table].map(field => {
                const value = escapeHtml(record[field]);
                const inputType = dateFields.has(field) ? 'date' : numericFields.has(field) ? 'number' : 'text';
                const step = numericFields.has(field) ? ' step="0.01"' : '';
                if (field === 'additional_info' || field === 'updated_details') {
                    return `<div class="col-12"><label class="form-label" for="edit_${field}">${fieldLabel(field)}</label><textarea class="form-control" id="edit_${field}" name="${field}">${value}</textarea></div>`;
                }
                if (field === 'payment_schedule') {
                    return `<div class="col-md-6"><label class="form-label" for="edit_${field}">${fieldLabel(field)}</label><select class="form-select" id="edit_${field}" name="${field}"><option value="100_days" ${record[field] === '100_days' ? 'selected' : ''}>100 Days</option><option value="10_weeks" ${record[field] === '10_weeks' ? 'selected' : ''}>10 Weeks</option><option value="5_months" ${record[field] === '5_months' ? 'selected' : ''}>5 Months</option></select></div>`;
                }
                return `<div class="col-md-6"><label class="form-label" for="edit_${field}">${fieldLabel(field)}</label><input class="form-control" id="edit_${field}" name="${field}" type="${inputType}"${step} value="${value}"></div>`;
            }).join('');
            document.getElementById('recordEditTitle').textContent = `Edit ${recordLabels[table]} #${record.id}`;
            const form = document.getElementById('recordEditForm');
            form.dataset.table = table;
            form.dataset.id = id;
            bootstrap.Modal.getOrCreateInstance(document.getElementById('recordEditModal')).show();
        } catch (error) { showActionError(error); }
    }
    document.getElementById('recordEditForm').addEventListener('submit', async event => {
        event.preventDefault();
        const form = event.currentTarget;
        const values = Object.fromEntries(new FormData(form).entries());
        try {
            const response = await fetch('dashboard.php?ajax_action=1', {
                method: 'POST', headers: {'Content-Type': 'application/json'},
                body: JSON.stringify({action: 'update', table: form.dataset.table, id: form.dataset.id, values})
            });
            const result = await response.json();
            if (!response.ok) throw new Error(result.error || 'Unable to save changes.');
            window.location.reload();
        } catch (error) { showActionError(error); }
    });
    async function openPayment(table, id, paymentType = null) {
        const form = document.getElementById('recordPaymentForm');
        form.dataset.table = table;
        form.dataset.id = id;
        const isRent = table === 'house_rent';
        try {
            const record = await fetchRecord(table, id);
            const person = [record.name, record.village || record.village_name, record.mobile].filter(Boolean).join(' · ');
            const selectedPaymentType = paymentType || (table === 'interest_loans' ? 'interest' : 'principal');
            const amountInput = document.getElementById('paymentAmount');
            const remainingRent = Math.max(0, Number(record.rental_amount || 0) - Number(record.paid_amount || 0));
            const maximumPayment = table === 'installment_customers'
                ? Number(record.outstanding_amount || 0)
                : table === 'interest_loans' && selectedPaymentType === 'principal'
                    ? Number(record.remaining_principal || 0)
                    : table === 'house_rent' ? remainingRent : null;
            amountInput.required = true;
            amountInput.max = maximumPayment === null ? '' : maximumPayment.toFixed(2);
            amountInput.value = table === 'installment_customers'
                ? Math.min(Number(record.amount_due || 0), Number(record.outstanding_amount || 0)).toFixed(2)
                : table === 'interest_loans' && selectedPaymentType === 'interest'
                    ? (Number(record.principal_amount || 0) * Number(record.interest_rate || 0) / 100).toFixed(2)
                    : table === 'house_rent' ? remainingRent.toFixed(2) : '';
            document.getElementById('interestPaymentTypeWrap').classList.toggle('d-none', table !== 'interest_loans');
            document.getElementById('interestPaymentType').value = selectedPaymentType;
            document.getElementById('rentPaymentNotice').classList.toggle('d-none', !isRent);
            document.getElementById('recordPaymentTitle').textContent = table === 'installment_customers' ? 'Pay Due' : table === 'interest_loans' ? (selectedPaymentType === 'interest' ? 'Interest Collection' : 'Principal Collection') : 'Pay Rent';
            document.getElementById('paymentAmountLabel').textContent = table === 'installment_customers' ? 'Due Amount (₹)' : 'Amount (₹)';
            document.getElementById('paymentSubmitButton').textContent = 'Save';
            document.getElementById('paymentSubtitle').textContent = person || `Record #${record.id}`;
            const contextFields = table === 'installment_customers'
                ? [['Principal', formatMoney(record.principal)], ['Collected', formatMoney(Number(record.principal) - Number(record.outstanding_amount))], ['Pending', formatMoney(record.outstanding_amount)], ['Suggested Installment', formatMoney(record.amount_due)]]
                : table === 'interest_loans'
                    ? [['Principal', formatMoney(record.principal_amount)], ['Rate of Interest', `${record.interest_rate}%`], ['Remaining Principal', formatMoney(record.remaining_principal)], ['Interest Collected', formatMoney(record.total_interest_collected)]]
                    : [['Rent Amount', formatMoney(record.rental_amount)], ['Collected', formatMoney(record.paid_amount)], ['Balance', formatMoney(remainingRent)], ['Agreement Date', formatDisplayDate(record.contract_date)]];
            document.getElementById('paymentContext').innerHTML = contextFields.map(([label, value]) =>
                `<div class="payment-context-item"><span>${escapeHtml(label)}</span><strong>${escapeHtml(value)}</strong></div>`
            ).join('');
            const today = new Date();
            today.setMinutes(today.getMinutes() - today.getTimezoneOffset());
            document.getElementById('paymentDate').value = today.toISOString().slice(0, 10);
            bootstrap.Modal.getOrCreateInstance(document.getElementById('recordPaymentModal')).show();
        } catch (error) { showActionError(error); }
    }
    document.getElementById('interestPaymentType').addEventListener('change', async event => {
        const form = document.getElementById('recordPaymentForm');
        if (form.dataset.table !== 'interest_loans') return;
        const record = await fetchRecord(form.dataset.table, form.dataset.id);
        const isInterest = event.currentTarget.value === 'interest';
        const paymentAmount = document.getElementById('paymentAmount');
        document.getElementById('recordPaymentTitle').textContent = isInterest ? 'Interest Collection' : 'Principal Collection';
        paymentAmount.max = isInterest ? '' : Number(record.remaining_principal || 0).toFixed(2);
        paymentAmount.value = isInterest
            ? (Number(record.principal_amount || 0) * Number(record.interest_rate || 0) / 100).toFixed(2)
            : '';
    });
    document.getElementById('recordPaymentForm').addEventListener('submit', async event => {
        event.preventDefault();
        const form = event.currentTarget;
        const shouldPrint = event.submitter?.value === 'print';
        const printWindow = shouldPrint ? window.open('', '_blank') : null;
        if (shouldPrint && !printWindow) alert('Allow pop-ups to print the receipt. The payment will still be saved.');
        const payload = {
            action: 'pay',
            table: form.dataset.table,
            id: form.dataset.id,
            amount: document.getElementById('paymentAmount').value,
            payment_date: document.getElementById('paymentDate').value
        };
        if (form.dataset.table === 'interest_loans') payload.payment_type = document.getElementById('interestPaymentType').value;
        try {
            const response = await fetch('dashboard.php?ajax_action=1', {
                method: 'POST', headers: {'Content-Type': 'application/json'}, body: JSON.stringify(payload)
            });
            const result = await response.json();
            if (!response.ok) throw new Error(result.error || 'Unable to apply payment.');
            if (printWindow) {
                const paymentType = payload.payment_type || (payload.table === 'house_rent' ? 'rent' : 'installment');
                printWindow.document.write(`<!doctype html><html><head><title>Payment Receipt</title><style>body{font:15px sans-serif;margin:32px;color:#172c35}h1{font-size:22px}table{border-collapse:collapse;width:100%;margin-top:24px}th,td{border:1px solid #dbe3e7;padding:10px;text-align:left}th{width:35%}</style></head><body><h1>Gram Finance - Payment Receipt</h1><table><tr><th>Receipt No.</th><td>${escapeHtml(result.payment_id)}</td></tr><tr><th>Account</th><td>${escapeHtml(recordLabels[payload.table])}</td></tr><tr><th>Customer</th><td>${escapeHtml(document.getElementById('paymentSubtitle').textContent)}</td></tr><tr><th>Payment Type</th><td>${escapeHtml(fieldLabel(paymentType))}</td></tr><tr><th>Amount</th><td>${escapeHtml(formatMoney(payload.amount))}</td></tr><tr><th>Date</th><td>${escapeHtml(formatDisplayDate(payload.payment_date))}</td></tr></table></body></html>`);
                printWindow.document.close();
                printWindow.focus();
                printWindow.print();
            }
            window.location.reload();
        } catch (error) { showActionError(error); }
    });
    async function printRecord(table, id) {
        const printWindow = window.open('', '_blank');
        if (!printWindow) { alert('Allow pop-ups to print this record.'); return; }
        try {
            const record = await fetchRecord(table, id);
            const rows = Object.entries(record).map(([key, value]) => `<tr><th>${escapeHtml(fieldLabel(key))}</th><td>${escapeHtml(value) || '-'}</td></tr>`).join('');
            printWindow.document.write(`<!doctype html><html><head><title>${escapeHtml(recordLabels[table])} #${record.id}</title><style>body{font:16px sans-serif;margin:32px}table{border-collapse:collapse;width:100%}th,td{border:1px solid #bbb;padding:8px;text-align:left}th{width:35%}</style></head><body><h1>${escapeHtml(recordLabels[table])} #${record.id}</h1><table>${rows}</table></body></html>`);
            printWindow.document.close();
            printWindow.focus();
            printWindow.print();
        } catch (error) { printWindow.close(); showActionError(error); }
    }
</script>
</body>
</html>