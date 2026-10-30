<?php require_once __DIR__ . '/../_init.php';
admin_required();
require_permission('reports');

$title = 'Accounting & Financial Control Hub';
$activeTab = trim((string)($_GET['tab'] ?? 'overview'));

$from = (string)($_GET['from'] ?? date('Y-m-01'));
$to   = (string)($_GET['to'] ?? date('Y-m-d'));

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) $from = date('Y-m-01');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $to))   $to = date('Y-m-d');

$start  = $from . ' 00:00:00';
$end    = $to . ' 23:59:59';
$params = [$start, $end];

// Immutable core system accounts list
$coreAccounts = ['1010', '1020', '1030', '1040', '2010', '3010', '3030', '4010', '4020', '5010', '6040'];

// --- CSV EXPORT HANDLERS ---
if (isset($_GET['export']) && $_GET['export'] === 'pnl_csv') {
    $filename = 'pnl-statement-' . $from . '-to-' . $to . '.csv';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');

    $pnlStmt = db()->prepare("
        SELECT 
            COALESCE(SUM(o.subtotal), 0) AS gross_sales,
            COALESCE(SUM(o.discount_amount), 0) AS total_discounts,
            COALESCE(SUM(o.shipping_amount), 0) AS shipping_collected,
            COALESCE(SUM(o.total), 0) AS net_revenue,
            COALESCE(SUM(cogs.total_cogs), 0) AS total_cogs
        FROM orders o
        LEFT JOIN (
            SELECT order_id, SUM(quantity * cost_price) AS total_cogs
            FROM order_items
            GROUP BY order_id
        ) cogs ON cogs.order_id = o.id
        WHERE o.created_at BETWEEN ? AND ? AND o.status NOT IN ('cancelled', 'returned')
    ");
    $pnlStmt->execute($params);
    $p = $pnlStmt->fetch(PDO::FETCH_ASSOC);

    $expStmt = db()->prepare("SELECT COALESCE(SUM(amount), 0) FROM accounting_expenses WHERE expense_date BETWEEN ? AND ?");
    $expStmt->execute([$from, $to]);
    $totalExp = (float)$expStmt->fetchColumn();

    $gSales  = (float)$p['gross_sales'];
    $disc    = (float)$p['total_discounts'];
    $ship    = (float)$p['shipping_collected'];
    $nRev    = (float)$p['net_revenue'];
    $cogsVal = (float)$p['total_cogs'];
    $gProfit = $nRev - $cogsVal;
    $nProfit = $gProfit - $totalExp;

    $out = fopen('php://output', 'w');
    fputcsv($out, ['Line Item', 'Amount (Tk)']);
    fputcsv($out, ['Gross Product Sales', number_format($gSales, 2)]);
    fputcsv($out, ['Less Promotional Discounts', '-' . number_format($disc, 2)]);
    fputcsv($out, ['Add Shipping Collected', '+' . number_format($ship, 2)]);
    fputcsv($out, ['Net Operating Revenue', number_format($nRev, 2)]);
    fputcsv($out, ['Less Cost of Goods Sold (COGS)', '-' . number_format($cogsVal, 2)]);
    fputcsv($out, ['Gross Operating Profit', number_format($gProfit, 2)]);
    fputcsv($out, ['Less General Operating Expenses (OPEX)', '-' . number_format($totalExp, 2)]);
    fputcsv($out, ['Final Net Operating Profit', number_format($nProfit, 2)]);
    fclose($out);
    exit;
}

if (isset($_GET['export']) && $_GET['export'] === 'ap_csv') {
    $filename = 'vendor-ap-ledger-' . date('Y-m-d') . '.csv';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');

    $out = fopen('php://output', 'w');
    fputcsv($out, ['Invoice #', 'Vendor Name', 'Company', 'Issue Date', 'Due Date', 'Total Amount', 'Paid Amount', 'Due Balance', 'Status']);

    $stmt = db()->query('
        SELECT vi.*, v.name as vendor_name, v.company_name 
        FROM vendor_invoices vi 
        JOIN vendors v ON v.id = vi.vendor_id 
        ORDER BY vi.id DESC
    ');

    while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $bal = (float)$r['total_amount'] - (float)$r['paid_amount'];
        fputcsv($out, [
            $r['invoice_number'],
            $r['vendor_name'],
            $r['company_name'] ?: 'N/A',
            $r['issue_date'],
            $r['due_date'] ?: 'N/A',
            number_format((float)$r['total_amount'], 2),
            number_format((float)$r['paid_amount'], 2),
            number_format($bal, 2),
            strtoupper($r['status'])
        ]);
    }
    fclose($out);
    exit;
}

// --- POST ACTIONS ROUTER ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = (string)($_POST['action'] ?? '');

    // 1. Add New Vendor
    if ($action === 'add_vendor') {
        $vName    = trim((string)($_POST['name'] ?? ''));
        $vCompany = trim((string)($_POST['company_name'] ?? ''));
        $vPhone   = trim((string)($_POST['phone'] ?? ''));
        $vEmail   = trim((string)($_POST['email'] ?? ''));
        $vAddress = trim((string)($_POST['address'] ?? ''));
        $vNotes   = trim((string)($_POST['notes'] ?? ''));

        if ($vName !== '' && $vPhone !== '') {
            $stmt = db()->prepare('INSERT INTO vendors (name, company_name, phone, email, address, notes) VALUES (?, ?, ?, ?, ?, ?)');
            $stmt->execute([$vName, $vCompany, $vPhone, $vEmail, $vAddress, $vNotes]);
            log_admin_activity('accounting', 'add_vendor', "Added vendor {$vName} ({$vCompany})");
            flash('success', "Vendor '{$vName}' saved.");
        } else {
            flash('error', 'Vendor name and phone number are required.');
        }
        redirect('admin/system/accounting?tab=vendors');
    }

    // 2. Record Vendor Bill / Invoice
    if ($action === 'add_vendor_invoice') {
        $vendorId  = (int)($_POST['vendor_id'] ?? 0);
        $invNumber = trim((string)($_POST['invoice_number'] ?? ''));
        $issueDate = (string)($_POST['issue_date'] ?? date('Y-m-d'));
        $dueDate   = (string)($_POST['due_date'] ?? null) ?: null;
        $amount    = max(0.0, (float)($_POST['total_amount'] ?? 0.0));
        $notes     = trim((string)($_POST['notes'] ?? ''));

        if ($vendorId > 0 && $invNumber !== '' && $amount > 0) {
            $pdo = db();
            $pdo->beginTransaction();

            $vName = $pdo->query("SELECT name FROM vendors WHERE id = " . (int)$vendorId)->fetchColumn() ?: 'Vendor';

            $stmt = $pdo->prepare('INSERT INTO vendor_invoices (vendor_id, invoice_number, issue_date, due_date, total_amount, notes, created_by) VALUES (?, ?, ?, ?, ?, ?, ?)');
            $stmt->execute([$vendorId, $invNumber, $issueDate, $dueDate, $amount, $notes, $_SESSION['admin_id'] ?? null]);
            $invId = (int)$pdo->lastInsertId();

            if (function_exists('post_journal_entry')) {
                $jLines = [
                    ['account_code' => '1040', 'debit' => $amount, 'credit' => 0.0, 'description' => "Purchases / Stock Inward from {$vName} (#{$invNumber})"],
                    ['account_code' => '2010', 'debit' => 0.0, 'credit' => $amount, 'description' => "Payable to {$vName} (#{$invNumber})"]
                ];
                post_journal_entry('vendor_bill', $invId, $issueDate, "Vendor Bill #{$invNumber} ({$vName})", $jLines, $_SESSION['admin_id'] ?? null);
            }

            $pdo->commit();
            log_admin_activity('accounting', 'add_vendor_invoice', "Recorded Vendor Bill #{$invNumber} for Amount: " . money($amount));
            flash('success', "Vendor Invoice #{$invNumber} recorded and journaled into Inventory & AP.");
        } else {
            flash('error', 'Select a vendor, enter invoice #, and enter a valid amount.');
        }
        redirect('admin/system/accounting?tab=vendors');
    }

    // 3. Make Payment to Vendor
    if ($action === 'pay_vendor_invoice') {
        $invoiceId = (int)($_POST['invoice_id'] ?? 0);
        $payDate   = (string)($_POST['payment_date'] ?? date('Y-m-d'));
        $payAmount = max(0.0, (float)($_POST['amount'] ?? 0.0));
        $payMethod = in_array($_POST['payment_method'] ?? '', ['bank', 'cash'], true) ? $_POST['payment_method'] : 'bank';
        $refNo     = trim((string)($_POST['reference_no'] ?? ''));
        $payNotes  = trim((string)($_POST['notes'] ?? ''));

        if ($invoiceId > 0 && $payAmount > 0) {
            $pdo = db();
            $pdo->beginTransaction();

            $invStmt = $pdo->prepare('
                SELECT vi.*, v.name as vendor_name 
                FROM vendor_invoices vi 
                JOIN vendors v ON v.id = vi.vendor_id 
                WHERE vi.id = ? FOR UPDATE
            ');
            $invStmt->execute([$invoiceId]);
            $inv = $invStmt->fetch(PDO::FETCH_ASSOC);

            if ($inv) {
                $remBalance = (float)$inv['total_amount'] - (float)$inv['paid_amount'];
                if ($payAmount > $remBalance) {
                    $payAmount = $remBalance;
                }

                $newPaid   = (float)$inv['paid_amount'] + $payAmount;
                $newStatus = ($newPaid >= (float)$inv['total_amount']) ? 'paid' : 'partially_paid';

                $pdo->prepare('INSERT INTO vendor_payments (invoice_id, vendor_id, payment_date, amount, payment_method, reference_no, notes, recorded_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
                    ->execute([$invoiceId, $inv['vendor_id'], $payDate, $payAmount, $payMethod, $refNo, $payNotes, $_SESSION['admin_id'] ?? null]);
                $payId = (int)$pdo->lastInsertId();

                $pdo->prepare('UPDATE vendor_invoices SET paid_amount = ?, status = ? WHERE id = ?')
                    ->execute([$newPaid, $newStatus, $invoiceId]);

                if (function_exists('post_journal_entry')) {
                    $creditCode = ($payMethod === 'bank') ? '1010' : '1020';
                    $methodLabel = ($payMethod === 'bank') ? 'Bank Account' : 'Cash on Hand (Petty Cash)';
                    $pLines = [
                        ['account_code' => '2010', 'debit' => $payAmount, 'credit' => 0.0, 'description' => "Settlement for Bill #{$inv['invoice_number']} to {$inv['vendor_name']}"],
                        ['account_code' => $creditCode, 'debit' => 0.0, 'credit' => $payAmount, 'description' => "Disbursed via {$methodLabel}"]
                    ];
                    post_journal_entry('vendor_payment', $payId, $payDate, "Payment for Bill #{$inv['invoice_number']} via {$methodLabel}", $pLines, $_SESSION['admin_id'] ?? null);
                }

                $pdo->commit();
                log_admin_activity('accounting', 'pay_vendor_invoice', "Recorded payment of " . money($payAmount) . " ({$payMethod}) for Invoice #{$inv['invoice_number']}");
                flash('success', "Payment of " . money($payAmount) . " recorded via " . strtoupper($payMethod) . ".");
            } else {
                $pdo->rollBack();
                flash('error', 'Invoice record not found.');
            }
        }
        redirect('admin/system/accounting?tab=vendors');
    }

    // 4. Record Operational / Petty Cash Expense
    if ($action === 'add_expense') {
        $accCode   = trim((string)($_POST['account_code'] ?? ''));
        $titleExp  = trim((string)($_POST['title'] ?? ''));
        $expAmount = max(0.0, (float)($_POST['amount'] ?? 0.0));
        $paySource = in_array($_POST['payment_source'] ?? '', ['bank', 'petty_cash'], true) ? $_POST['payment_source'] : 'petty_cash';
        $expDate   = (string)($_POST['expense_date'] ?? date('Y-m-d'));
        $receipt   = trim((string)($_POST['receipt_reference'] ?? ''));
        $expNotes  = trim((string)($_POST['notes'] ?? ''));

        if ($accCode !== '' && $titleExp !== '' && $expAmount > 0) {
            $stmt = db()->prepare('INSERT INTO accounting_expenses (account_code, title, amount, payment_source, expense_date, receipt_reference, notes, recorded_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
            $stmt->execute([$accCode, $titleExp, $expAmount, $paySource, $expDate, $receipt, $expNotes, $_SESSION['admin_id'] ?? null]);
            $expId = (int)db()->lastInsertId();

            if (function_exists('post_journal_entry')) {
                $creditAccount = ($paySource === 'bank') ? '1010' : '1020';
                $jLines = [
                    ['account_code' => $accCode, 'debit' => $expAmount, 'credit' => 0.0, 'description' => $titleExp],
                    ['account_code' => $creditAccount, 'debit' => 0.0, 'credit' => $expAmount, 'description' => "Paid via " . ($paySource === 'bank' ? 'Bank' : 'Petty Cash')]
                ];
                post_journal_entry('expense', $expId, $expDate, "Expense: {$titleExp}", $jLines, $_SESSION['admin_id'] ?? null);
            }

            log_admin_activity('accounting', 'add_expense', "Logged Expense: {$titleExp} (" . money($expAmount) . ")");
            flash('success', "Expense '{$titleExp}' saved and posted to General Ledger.");
        } else {
            flash('error', 'Fill in all mandatory expense fields.');
        }
        redirect('admin/system/accounting?tab=expenses');
    }

    // 5. Courier COD Remittance Settlement
    if ($action === 'record_courier_settlement') {
        $courierName = trim((string)($_POST['courier_name'] ?? 'Steadfast Courier'));
        $settleRef   = trim((string)($_POST['settlement_ref'] ?? ''));
        $settleDate  = (string)($_POST['settlement_date'] ?? date('Y-m-d'));
        $grossCol    = max(0.0, (float)($_POST['gross_collected'] ?? 0.0));
        $courierFee  = max(0.0, (float)($_POST['courier_fees'] ?? 0.0));
        $destAcc     = in_array($_POST['destination_account'] ?? '', ['bank', 'petty_cash'], true) ? $_POST['destination_account'] : 'bank';
        $setNotes    = trim((string)($_POST['notes'] ?? ''));

        $netReceived = max(0.0, $grossCol - $courierFee);

        if ($settleRef !== '' && $grossCol > 0) {
            $pdo = db();
            $pdo->beginTransaction();

            $stmt = $pdo->prepare('
                INSERT INTO accounting_courier_settlements (
                    settlement_ref, courier_name, settlement_date, gross_collected, 
                    courier_fees, net_received, destination_account, notes, recorded_by
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
            ');
            $stmt->execute([$settleRef, $courierName, $settleDate, $grossCol, $courierFee, $netReceived, $destAcc, $setNotes, $_SESSION['admin_id'] ?? null]);
            $settleId = (int)$pdo->lastInsertId();

            if ($courierFee > 0) {
                $expStmt = $pdo->prepare('
                    INSERT INTO accounting_expenses (account_code, title, amount, payment_source, expense_date, receipt_reference, notes, recorded_by)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                ');
                $expStmt->execute([
                    '6040',
                    "Courier Fee deduction ({$courierName} - Ref: {$settleRef})",
                    $courierFee,
                    'bank',
                    $settleDate,
                    $settleRef,
                    'Auto-recorded from courier remittance settlement',
                    $_SESSION['admin_id'] ?? null
                ]);
            }

            if (function_exists('post_journal_entry')) {
                $destCode = ($destAcc === 'bank') ? '1010' : '1020';
                $sLines = [
                    ['account_code' => $destCode, 'debit' => $netReceived, 'credit' => 0.0, 'description' => "Net Remittance {$courierName} ({$settleRef})"],
                    ['account_code' => '1030', 'debit' => 0.0, 'credit' => $grossCol, 'description' => "Cleared COD Receivable ({$settleRef})"]
                ];
                if ($courierFee > 0) {
                    $sLines[] = ['account_code' => '6040', 'debit' => $courierFee, 'credit' => 0.0, 'description' => "Courier Service Fees ({$settleRef})"];
                }
                post_journal_entry('courier_settlement', $settleId, $settleDate, "Courier Remittance: {$courierName} #{$settleRef}", $sLines, $_SESSION['admin_id'] ?? null);
            }

            $pdo->commit();
            log_admin_activity('accounting', 'courier_settlement', "Settled Remittance Ref: {$settleRef} from {$courierName} (Net: " . money($netReceived) . ")");
            flash('success', "Courier Remittance #{$settleRef} reconciled and journaled.");
        } else {
            flash('error', 'Provide settlement reference and valid gross collected amount.');
        }
        redirect('admin/system/accounting?tab=courier');
    }

    // 6. Add Stakeholder / Partner
    if ($action === 'add_stakeholder') {
        $sName   = trim((string)($_POST['name'] ?? ''));
        $sRole   = trim((string)($_POST['role'] ?? 'Partner'));
        $sEquity = max(0.0, min(100.0, (float)($_POST['equity_percentage'] ?? 0.0)));
        $sCap    = max(0.0, (float)($_POST['total_contributed'] ?? 0.0));
        $sPhone  = trim((string)($_POST['phone'] ?? ''));
        $sEmail  = trim((string)($_POST['email'] ?? ''));

        if ($sName !== '') {
            $stmt = db()->prepare('INSERT INTO accounting_stakeholders (name, role, equity_percentage, total_contributed, phone, email) VALUES (?, ?, ?, ?, ?, ?)');
            $stmt->execute([$sName, $sRole, $sEquity, $sCap, $sPhone, $sEmail]);
            log_admin_activity('accounting', 'add_stakeholder', "Added Stakeholder: {$sName} ({$sEquity}%)");
            flash('success', "Stakeholder {$sName} saved.");
        }
        redirect('admin/system/accounting?tab=stakeholders');
    }

    // 7. Manual Adjusting Journal Entry
    if ($action === 'create_manual_journal') {
        $jDate    = (string)($_POST['entry_date'] ?? date('Y-m-d'));
        $memo     = trim((string)($_POST['memo'] ?? 'Adjusting Journal Entry'));
        $accounts = $_POST['account_code'] ?? [];
        $debits   = $_POST['debit'] ?? [];
        $credits  = $_POST['credit'] ?? [];

        $lines = [];
        foreach ($accounts as $idx => $code) {
            $code = trim((string)$code);
            $d = max(0.0, (float)($debits[$idx] ?? 0));
            $c = max(0.0, (float)($credits[$idx] ?? 0));
            if ($code !== '' && ($d > 0 || $c > 0)) {
                $lines[] = [
                    'account_code' => $code,
                    'debit'        => $d,
                    'credit'       => $c,
                    'description'  => $memo
                ];
            }
        }

        if (count($lines) >= 2) {
            try {
                if (function_exists('post_journal_entry')) {
                    post_journal_entry('manual', null, $jDate, $memo, $lines, $_SESSION['admin_id'] ?? null);
                    log_admin_activity('accounting', 'create_journal', "Posted manual adjusting journal: {$memo}");
                    flash('success', 'Journal entry posted successfully.');
                }
            } catch (Throwable $e) {
                flash('error', $e->getMessage());
            }
        } else {
            flash('error', 'A journal entry requires at least 2 balancing lines.');
        }
        redirect('admin/system/accounting?tab=ledger');
    }

    // 8. Add New Custom Account to Chart of Accounts (COA)
    if ($action === 'add_coa_account') {
        $code    = trim((string)($_POST['code'] ?? ''));
        $name    = trim((string)($_POST['name'] ?? ''));
        $type    = trim((string)($_POST['type'] ?? 'expense'));
        $subType = trim((string)($_POST['sub_type'] ?? 'opex'));
        $desc    = trim((string)($_POST['description'] ?? ''));

        if ($code !== '' && $name !== '') {
            $chk = db()->prepare('SELECT id FROM accounting_coa WHERE code = ?');
            $chk->execute([$code]);
            if ($chk->fetch()) {
                flash('error', "Account code {$code} already exists. Please choose a different code.");
            } else {
                $stmt = db()->prepare('INSERT INTO accounting_coa (code, name, type, sub_type, description, is_active) VALUES (?, ?, ?, ?, ?, 1)');
                $stmt->execute([$code, $name, $type, $subType, $desc]);
                log_admin_activity('accounting', 'add_coa', "Created Account {$code} - {$name}");
                flash('success', "Account '{$code} - {$name}' created successfully.");
            }
        }
        redirect('admin/system/accounting?tab=settings');
    }

    // 9. Update Non-Core Account in Chart of Accounts (COA)
    if ($action === 'update_coa_account') {
        $id      = (int)($_POST['id'] ?? 0);
        $name    = trim((string)($_POST['name'] ?? ''));
        $type    = trim((string)($_POST['type'] ?? 'expense'));
        $subType = trim((string)($_POST['sub_type'] ?? 'opex'));
        $desc    = trim((string)($_POST['description'] ?? ''));

        $acc = db()->prepare('SELECT code FROM accounting_coa WHERE id = ?');
        $acc->execute([$id]);
        $targetCode = $acc->fetchColumn();

        if ($targetCode && in_array($targetCode, $coreAccounts, true)) {
            flash('error', "Security Restriction: Account {$targetCode} is an immutable core system account and cannot be modified.");
        } elseif ($id > 0 && $name !== '') {
            $stmt = db()->prepare('UPDATE accounting_coa SET name = ?, type = ?, sub_type = ?, description = ? WHERE id = ?');
            $stmt->execute([$name, $type, $subType, $desc, $id]);
            log_admin_activity('accounting', 'update_coa', "Updated Account ID {$id} ({$name})");
            flash('success', "Account '{$name}' updated successfully.");
        }
        redirect('admin/system/accounting?tab=settings');
    }

    // 10. Toggle Active / Hidden Status for Non-Core COA Account
    if ($action === 'toggle_coa_status') {
        $id = (int)($_POST['id'] ?? 0);
        $curr = db()->prepare('SELECT code, is_active FROM accounting_coa WHERE id = ?');
        $curr->execute([$id]);
        $row = $curr->fetch(PDO::FETCH_ASSOC);

        if ($row) {
            if (in_array($row['code'], $coreAccounts, true)) {
                flash('error', "Security Restriction: Core system account {$row['code']} cannot be hidden.");
            } else {
                $newStatus = (int)$row['is_active'] ? 0 : 1;
                db()->prepare('UPDATE accounting_coa SET is_active = ? WHERE id = ?')->execute([$newStatus, $id]);
                $msg = $newStatus ? "Account {$row['code']} is now Active (Visible)." : "Account {$row['code']} is now Hidden from select options.";
                flash('success', $msg);
            }
        }
        redirect('admin/system/accounting?tab=settings');
    }

    // 11. Delete Non-Core Account from Chart of Accounts
    if ($action === 'delete_coa_account') {
        $id = (int)($_POST['id'] ?? 0);
        $code = db()->query("SELECT code FROM accounting_coa WHERE id = {$id}")->fetchColumn();

        if ($code) {
            if (in_array($code, $coreAccounts, true)) {
                flash('error', "Security Restriction: Account {$code} is an essential core system account and cannot be deleted.");
            } else {
                $used = db()->query("SELECT COUNT(*) FROM accounting_journal_lines WHERE account_code = '{$code}'")->fetchColumn();
                if ($used > 0) {
                    flash('error', "Cannot delete account {$code} because it has financial journal transactions posted to it. You can Hide it instead.");
                } else {
                    db()->prepare('DELETE FROM accounting_coa WHERE id = ?')->execute([$id]);
                    flash('success', "Account {$code} deleted.");
                }
            }
        }
        redirect('admin/system/accounting?tab=settings');
    }

    // 12. Add New Courier Service
    if ($action === 'add_courier') {
        $cName = trim((string)($_POST['name'] ?? ''));
        if ($cName !== '') {
            db()->prepare('INSERT IGNORE INTO courier_services (name, is_active) VALUES (?, 1)')->execute([$cName]);
            flash('success', "Courier '{$cName}' added.");
        }
        redirect('admin/system/accounting?tab=settings');
    }

    // 13. Update Existing Courier Service
    if ($action === 'update_courier') {
        $id    = (int)($_POST['id'] ?? 0);
        $cName = trim((string)($_POST['name'] ?? ''));
        if ($id > 0 && $cName !== '') {
            db()->prepare('UPDATE courier_services SET name = ? WHERE id = ?')->execute([$cName, $id]);
            flash('success', "Courier updated to '{$cName}'.");
        }
        redirect('admin/system/accounting?tab=settings');
    }

    // 14. Toggle Active / Hidden Status for Courier Service
    if ($action === 'toggle_courier_status') {
        $id = (int)($_POST['id'] ?? 0);
        $curr = db()->prepare('SELECT name, is_active FROM courier_services WHERE id = ?');
        $curr->execute([$id]);
        $row = $curr->fetch(PDO::FETCH_ASSOC);

        if ($row) {
            $newStatus = (int)$row['is_active'] ? 0 : 1;
            db()->prepare('UPDATE courier_services SET is_active = ? WHERE id = ?')->execute([$newStatus, $id]);
            $msg = $newStatus ? "Courier '{$row['name']}' is now Active." : "Courier '{$row['name']}' is now Hidden.";
            flash('success', $msg);
        }
        redirect('admin/system/accounting?tab=settings');
    }

    // 15. Delete Courier Service
    if ($action === 'delete_courier') {
        $id = (int)($_POST['id'] ?? 0);
        db()->prepare('DELETE FROM courier_services WHERE id = ?')->execute([$id]);
        flash('success', 'Courier service removed.');
        redirect('admin/system/accounting?tab=settings');
    }
}

// --- DATA QUERIES ---

// A. Sales & COGS Data
$pnlStmt = db()->prepare("
    SELECT 
        COALESCE(SUM(o.subtotal), 0) AS gross_sales,
        COALESCE(SUM(o.discount_amount), 0) AS total_discounts,
        COALESCE(SUM(o.shipping_amount), 0) AS shipping_collected,
        COALESCE(SUM(o.total), 0) AS net_revenue,
        COALESCE(SUM(cogs.total_cogs), 0) AS total_cogs
    FROM orders o
    LEFT JOIN (
        SELECT order_id, SUM(quantity * cost_price) AS total_cogs
        FROM order_items
        GROUP BY order_id
    ) cogs ON cogs.order_id = o.id
    WHERE o.created_at BETWEEN ? AND ? AND o.status NOT IN ('cancelled', 'returned')
");
$pnlStmt->execute($params);
$pnl = $pnlStmt->fetch(PDO::FETCH_ASSOC);

$grossSales  = (float)$pnl['gross_sales'];
$discounts   = (float)$pnl['total_discounts'];
$shipping    = (float)$pnl['shipping_collected'];
$netRevenue  = (float)$pnl['net_revenue'];
$cogs        = (float)$pnl['total_cogs'];
$grossProfit = $netRevenue - $cogs;

// B. Operational Expenses Data
$expStmt = db()->prepare("
    SELECT COALESCE(SUM(amount), 0) AS total_opex 
    FROM accounting_expenses 
    WHERE expense_date BETWEEN ? AND ?
");
$expStmt->execute([$from, $to]);
$totalOpex = (float)$expStmt->fetchColumn();

// Net Operating Profit
$netOperatingProfit = $grossProfit - $totalOpex;
$netMarginPct = $netRevenue > 0 ? round(($netOperatingProfit / $netRevenue) * 100, 1) : 0.0;

// C. Balance Sheet Snapshot Variables
$inventoryValuation = (float)db()->query('SELECT COALESCE(SUM(stock * cost_price), 0) FROM products WHERE active = 1')->fetchColumn();
$unpaidVendorBills  = (float)db()->query("SELECT COALESCE(SUM(total_amount - paid_amount), 0) FROM vendor_invoices WHERE status != 'paid'")->fetchColumn();
$totalCapitalInvest = (float)db()->query('SELECT COALESCE(SUM(total_contributed), 0) FROM accounting_stakeholders')->fetchColumn();

$deliveredCodTotal = (float)db()->query("SELECT COALESCE(SUM(total), 0) FROM orders WHERE status = 'delivered'")->fetchColumn();
$settledCourierTotal = (float)db()->query("SELECT COALESCE(SUM(gross_collected), 0) FROM accounting_courier_settlements")->fetchColumn();
$pendingCourierCash = max(0.0, $deliveredCodTotal - $settledCourierTotal);

// Only ACTIVE Couriers (Shown in selection dropdowns)
$couriersList = db()->query('SELECT * FROM courier_services WHERE is_active = 1 ORDER BY name ASC')->fetchAll(PDO::FETCH_ASSOC);

admin_header($title); ?>

<style>
    .nav-tabs {
        display: flex;
        gap: 8px;
        border-bottom: 2px solid #cbd5e1;
        margin-bottom: 24px;
        flex-wrap: wrap;
    }

    .tab-btn {
        padding: 10px 18px;
        text-decoration: none;
        font-weight: bold;
        font-size: 14px;
        color: #475569;
        border-radius: 6px 6px 0 0;
        background: #f1f5f9;
    }

    .tab-btn.active {
        color: #fff;
        background: #2563eb;
    }

    .grid-kpi {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(190px, 1fr));
        gap: 15px;
        margin-bottom: 22px;
    }

    .kpi-card {
        background: #fff;
        border: 1px solid #cbd5e1;
        border-radius: 8px;
        padding: 18px;
    }

    .kpi-card h4 {
        margin: 0 0 5px 0;
        font-size: 11px;
        text-transform: uppercase;
        color: #64748b;
    }

    .kpi-card .val {
        font-size: 22px;
        font-weight: 800;
        color: #0f172a;
    }

    .modal-overlay {
        position: fixed;
        top: 0;
        left: 0;
        right: 0;
        bottom: 0;
        background: rgba(15, 23, 42, 0.6);
        display: none;
        align-items: center;
        justify-content: center;
        z-index: 9999;
    }

    .modal-window {
        background: #fff;
        border-radius: 8px;
        max-width: 480px;
        width: 90%;
        padding: 24px;
        box-shadow: 0 10px 25px rgba(0, 0, 0, 0.2);
    }

    @media print {
        body * {
            visibility: hidden;
        }

        #printable-voucher,
        #printable-voucher *,
        #printable-pnl,
        #printable-pnl * {
            visibility: visible;
        }

        #printable-voucher,
        #printable-pnl {
            position: absolute;
            left: 0;
            top: 0;
            width: 100%;
            margin: 0;
            padding: 15px;
            font-family: Arial, sans-serif;
        }

        .no-print {
            display: none !important;
        }
    }
</style>

<!-- Primary Tab Navigation -->
<div class="nav-tabs no-print">
    <a href="<?= e(url('admin/system/accounting?tab=overview')) ?>" class="tab-btn <?= $activeTab === 'overview' ? 'active' : '' ?>">📊 Operating P&L</a>
    <a href="<?= e(url('admin/system/accounting?tab=balancesheet')) ?>" class="tab-btn <?= $activeTab === 'balancesheet' ? 'active' : '' ?>">⚖️ Balance Sheet</a>
    <a href="<?= e(url('admin/system/accounting?tab=courier')) ?>" class="tab-btn <?= $activeTab === 'courier' ? 'active' : '' ?>">🚚 Courier Remittance (AR)</a>
    <a href="<?= e(url('admin/system/accounting?tab=vendors')) ?>" class="tab-btn <?= $activeTab === 'vendors' ? 'active' : '' ?>">🧾 Vendors & Payables (AP)</a>
    <a href="<?= e(url('admin/system/accounting?tab=expenses')) ?>" class="tab-btn <?= $activeTab === 'expenses' ? 'active' : '' ?>">💸 Daily Expenses & Petty Cash</a>
    <a href="<?= e(url('admin/system/accounting?tab=stakeholders')) ?>" class="tab-btn <?= $activeTab === 'stakeholders' ? 'active' : '' ?>">📈 Capital & Stakeholders</a>
    <a href="<?= e(url('admin/system/accounting?tab=ledger')) ?>" class="tab-btn <?= $activeTab === 'ledger' ? 'active' : '' ?>">📖 General Ledger & Trial Balance</a>
    <a href="<?= e(url('admin/system/accounting?tab=settings')) ?>" class="tab-btn <?= $activeTab === 'settings' ? 'active' : '' ?>">⚙️ COA & Settings</a>
</div>

<!-- ================= TAB 1: OPERATING P&L ================= -->
<?php if ($activeTab === 'overview'): ?>
    <div class="panel no-print" style="margin-bottom:22px;">
        <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:12px;">
            <h2>Financial Statement & Comprehensive P&L</h2>
            <div style="display:flex; gap:10px; align-items:center; flex-wrap:wrap;">
                <form method="get" class="inline-form" style="margin:0;">
                    <input type="hidden" name="tab" value="overview">
                    <input type="date" name="from" value="<?= e($from) ?>">
                    <input type="date" name="to" value="<?= e($to) ?>">
                    <button class="button">Run Statement</button>
                </form>
                <a href="<?= e(url('admin/system/accounting?tab=overview&export=pnl_csv&from=' . urlencode($from) . '&to=' . urlencode($to))) ?>" class="button secondary" style="padding:7px 12px; font-size:13px; font-weight:bold;">
                    📥 Export P&L CSV
                </a>
                <button onclick="window.print()" class="button" style="padding:7px 14px; font-size:13px; background:#475569; border-color:#334155;">
                    🖨️ Print / Save PDF
                </button>
            </div>
        </div>
    </div>

    <div class="grid-kpi no-print">
        <div class="kpi-card">
            <h4>Net Revenue</h4>
            <div class="val" style="color:#2563eb;"><?= money($netRevenue) ?></div>
        </div>
        <div class="kpi-card">
            <h4>Total COGS</h4>
            <div class="val" style="color:#dc2626;"><?= money($cogs) ?></div>
        </div>
        <div class="kpi-card">
            <h4>Gross Operating Profit</h4>
            <div class="val" style="color:#16a34a;"><?= money($grossProfit) ?></div>
        </div>
        <div class="kpi-card">
            <h4>Operating Expenses (OPEX)</h4>
            <div class="val" style="color:#ea580c;"><?= money($totalOpex) ?></div>
        </div>
        <div class="kpi-card">
            <h4>Net Clean Profit</h4>
            <div class="val" style="color:<?= $netOperatingProfit >= 0 ? '#16a34a' : '#dc2626' ?>;"><?= money($netOperatingProfit) ?></div>
        </div>
    </div>

    <div class="panel" id="printable-pnl" style="max-width:750px; margin:0 auto; padding:25px; border:1px solid #cbd5e1;">
        <div style="border-bottom:2px solid #0f172a; padding-bottom:8px; margin-bottom:12px; display:flex; justify-content:space-between; align-items:flex-end;">
            <div>
                <h3 style="margin:0;"><?= e(store_name()) ?></h3>
                <span class="muted small">Income Statement (Profit & Loss)</span>
            </div>
            <div style="text-align:right; font-size:12px;" class="muted">
                Period: <b><?= date('d M, Y', strtotime($from)) ?></b> to <b><?= date('d M, Y', strtotime($to)) ?></b>
            </div>
        </div>

        <table style="width:100%; border-collapse:collapse; margin-top:15px; font-size:14px;">
            <tr style="border-bottom:1px solid #e2e8f0;">
                <td style="padding:10px 0;">Gross Store Sales</td>
                <td style="text-align:right; font-weight:bold;"><?= money($grossSales) ?></td>
            </tr>
            <tr style="border-bottom:1px solid #e2e8f0; color:#dc2626;">
                <td style="padding:10px 0;">Less: Promotional Discounts</td>
                <td style="text-align:right; font-weight:bold;">- <?= money($discounts) ?></td>
            </tr>
            <tr style="border-bottom:1px solid #e2e8f0;">
                <td style="padding:10px 0;">Add: Shipping Fees Collected</td>
                <td style="text-align:right; font-weight:bold;">+ <?= money($shipping) ?></td>
            </tr>
            <tr style="border-bottom:2px solid #0f172a; background:#f8fafc; font-weight:bold;">
                <td style="padding:12px 10px;">Net Operating Revenue</td>
                <td style="padding:12px 10px; text-align:right; color:#2563eb;"><?= money($netRevenue) ?></td>
            </tr>
            <tr style="border-bottom:1px solid #e2e8f0; color:#dc2626;">
                <td style="padding:10px 0;">Less: Cost of Goods Sold (COGS)</td>
                <td style="text-align:right; font-weight:bold;">- <?= money($cogs) ?></td>
            </tr>
            <tr style="border-bottom:2px solid #cbd5e1; background:#f8fafc; font-weight:bold;">
                <td style="padding:12px 10px;">Gross Operating Profit</td>
                <td style="padding:12px 10px; text-align:right; color:#16a34a;"><?= money($grossProfit) ?></td>
            </tr>
            <tr style="border-bottom:1px solid #e2e8f0; color:#dc2626;">
                <td style="padding:10px 0;">Less: Total General Operating Expenses (OPEX)</td>
                <td style="text-align:right; font-weight:bold;">- <?= money($totalOpex) ?></td>
            </tr>
            <tr style="border-bottom:3px double #0f172a; background:#f0fdf4; font-size:16px; font-weight:bold;">
                <td style="padding:14px 10px; color:#166534;">FINAL NET OPERATING PROFIT</td>
                <td style="padding:14px 10px; text-align:right; color:#166534;"><?= money($netOperatingProfit) ?></td>
            </tr>
        </table>
        <div style="margin-top:15px; text-align:center; padding:10px; background:#eff6ff; font-weight:bold; color:#1d4ed8; border-radius:6px;">
            Net Profit Margin: <?= $netMarginPct ?>%
        </div>
    </div>
<?php endif; ?>

<!-- ================= TAB 2: BALANCE SHEET SNAPSHOT ================= -->
<?php if ($activeTab === 'balancesheet'):
    $totalAssets = $inventoryValuation + $pendingCourierCash;
    $totalLiabilities = $unpaidVendorBills;
    $totalEquity = $totalCapitalInvest + $netOperatingProfit;
?>
    <div class="panel" style="max-width:850px; margin:0 auto; padding:25px; border:1px solid #cbd5e1;">
        <h2 style="margin-top:0; border-bottom:2px solid #0f172a; padding-bottom:8px;">Balance Sheet Snapshot</h2>
        <p class="muted small">As of <?= date('d F, Y') ?> (Accounting Formula: Assets = Liabilities + Equity)</p>

        <div style="display:grid; grid-template-columns: 1fr 1fr; gap:25px; margin-top:20px;">
            <div style="border:1px solid #e2e8f0; padding:15px; border-radius:8px; background:#fafafa;">
                <h3 style="margin-top:0; color:#2563eb; border-bottom:1px solid #cbd5e1; padding-bottom:6px;">Current Assets</h3>
                <div style="display:flex; justify-content:space-between; padding:8px 0; border-bottom:1px solid #eee;">
                    <span>📦 Inventory Valuation (At Cost):</span>
                    <b><?= money($inventoryValuation) ?></b>
                </div>
                <div style="display:flex; justify-content:space-between; padding:8px 0; border-bottom:1px solid #eee;">
                    <span>🚚 Courier COD Pending (AR):</span>
                    <b><?= money($pendingCourierCash) ?></b>
                </div>
                <div style="display:flex; justify-content:space-between; padding:12px 0; font-size:16px; font-weight:800; border-top:2px solid #2563eb; margin-top:15px; color:#2563eb;">
                    <span>TOTAL RECORDED ASSETS:</span>
                    <span><?= money($totalAssets) ?></span>
                </div>
            </div>

            <div style="border:1px solid #e2e8f0; padding:15px; border-radius:8px; background:#fafafa;">
                <h3 style="margin-top:0; color:#dc2626; border-bottom:1px solid #cbd5e1; padding-bottom:6px;">Current Liabilities</h3>
                <div style="display:flex; justify-content:space-between; padding:8px 0; border-bottom:1px solid #eee;">
                    <span>Accounts Payable (Vendor Bills Due):</span>
                    <b style="color:#dc2626;"><?= money($unpaidVendorBills) ?></b>
                </div>

                <h3 style="margin-top:20px; color:#16a34a; border-bottom:1px solid #cbd5e1; padding-bottom:6px;">Stakeholders' Equity</h3>
                <div style="display:flex; justify-content:space-between; padding:8px 0; border-bottom:1px solid #eee;">
                    <span>Total Contributed Capital:</span>
                    <b><?= money($totalCapitalInvest) ?></b>
                </div>
                <div style="display:flex; justify-content:space-between; padding:8px 0; border-bottom:1px solid #eee;">
                    <span>Period Retained Earnings:</span>
                    <b style="color:<?= $netOperatingProfit >= 0 ? '#16a34a' : '#dc2626' ?>;"><?= money($netOperatingProfit) ?></b>
                </div>
                <div style="display:flex; justify-content:space-between; padding:12px 0; font-size:16px; font-weight:800; border-top:2px solid #16a34a; margin-top:15px; color:#16a34a;">
                    <span>TOTAL LIABILITIES & EQUITY:</span>
                    <span><?= money($totalLiabilities + $totalEquity) ?></span>
                </div>
            </div>
        </div>
    </div>
<?php endif; ?>

<!-- ================= TAB 3: COURIER COD REMITTANCES ================= -->
<?php if ($activeTab === 'courier'):
    $settlements = db()->query('SELECT * FROM accounting_courier_settlements ORDER BY settlement_date DESC, id DESC LIMIT 50')->fetchAll(PDO::FETCH_ASSOC);
?>
    <div style="display:grid; grid-template-columns: 1fr 2fr; gap:20px;">
        <div class="panel">
            <h3>Reconcile Courier Remittance</h3>
            <form method="post">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="record_courier_settlement">
                <div class="field">
                    <label>Courier Service *</label>
                    <select name="courier_name" required style="width:100%; padding:8px;">
                        <?php foreach ($couriersList as $cs): ?>
                            <option value="<?= e($cs['name']) ?>"><?= e($cs['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="field"><label>Settlement / Invoice Ref # *</label><input type="text" name="settlement_ref" required placeholder="e.g. ST-PAY-202610"></div>
                <div class="field"><label>Gross Cash Collected (৳) *</label><input type="number" step="0.01" name="gross_collected" required placeholder="Raw COD collected from customers"></div>
                <div class="field"><label>Courier Delivery Fees Deducted (৳)</label><input type="number" step="0.01" name="courier_fees" value="0.00" placeholder="Delivery charge deducted by courier"></div>
                <div class="field">
                    <label>Transferred To Account</label>
                    <select name="destination_account" style="width:100%; padding:8px;">
                        <option value="bank">Primary Operating Bank Account</option>
                        <option value="petty_cash">Cash on Hand (Petty Cash)</option>
                    </select>
                </div>
                <div class="field"><label>Remittance Date</label><input type="date" name="settlement_date" value="<?= date('Y-m-d') ?>" required></div>
                <button class="button" style="width:100%; background:#16a34a; border-color:#15803d;">Reconcile & Deposit Funds</button>
            </form>
        </div>

        <div class="panel">
            <h3>Courier COD Disbursement History</h3>
            <div class="table-wrap">
                <table style="width:100%; border-collapse:collapse; font-size:13px;">
                    <thead>
                        <tr style="background:#f8fafc; border-bottom:1px solid #cbd5e1; text-align:left;">
                            <th style="padding:8px;">Date</th>
                            <th style="padding:8px;">Courier</th>
                            <th style="padding:8px;">Reference #</th>
                            <th style="padding:8px; text-align:right;">Gross COD</th>
                            <th style="padding:8px; text-align:right;">Courier Fees</th>
                            <th style="padding:8px; text-align:right;">Net Deposited</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($settlements as $st): ?>
                            <tr style="border-bottom:1px solid #f1f5f9;">
                                <td style="padding:8px;"><?= date('M d, Y', strtotime($st['settlement_date'])) ?></td>
                                <td style="padding:8px;"><b><?= e($st['courier_name']) ?></b></td>
                                <td style="padding:8px; font-family:monospace;"><?= e($st['settlement_ref']) ?></td>
                                <td style="padding:8px; text-align:right; font-weight:bold;"><?= money($st['gross_collected']) ?></td>
                                <td style="padding:8px; text-align:right; color:#dc2626;">- <?= money($st['courier_fees']) ?></td>
                                <td style="padding:8px; text-align:right; font-weight:bold; color:#16a34a;"><?= money($st['net_received']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (!$settlements): ?>
                            <tr>
                                <td colspan="6" style="text-align:center; padding:15px;" class="muted">No courier settlements logged yet.</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
<?php endif; ?>

<!-- ================= TAB 4: VENDORS & PAYABLES (AP) ================= -->
<?php if ($activeTab === 'vendors'):
    $vendorFilterId = (int)($_GET['vendor_id'] ?? 0);
    $vendors = db()->query('SELECT * FROM vendors ORDER BY name ASC')->fetchAll(PDO::FETCH_ASSOC);

    $invSql = '
        SELECT vi.*, v.name as vendor_name, v.company_name 
        FROM vendor_invoices vi 
        JOIN vendors v ON v.id = vi.vendor_id 
    ';
    $invParams = [];
    if ($vendorFilterId > 0) {
        $invSql .= ' WHERE vi.vendor_id = ?';
        $invParams[] = $vendorFilterId;
    }
    $invSql .= ' ORDER BY vi.id DESC LIMIT 50';

    $invoicesStmt = db()->prepare($invSql);
    $invoicesStmt->execute($invParams);
    $invoices = $invoicesStmt->fetchAll(PDO::FETCH_ASSOC);

    $recentPayments = db()->query('
        SELECT vp.*, vi.invoice_number, v.name as vendor_name, v.company_name
        FROM vendor_payments vp
        JOIN vendor_invoices vi ON vi.id = vp.invoice_id
        JOIN vendors v ON v.id = vp.vendor_id
        ORDER BY vp.id DESC LIMIT 20
    ')->fetchAll(PDO::FETCH_ASSOC);
?>
    <div style="display:grid; grid-template-columns: 1fr 2fr; gap:20px; margin-bottom:25px;">
        <div>
            <div class="panel" style="margin-bottom:20px;">
                <h3>Add New Vendor / Supplier</h3>
                <form method="post">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="add_vendor">
                    <div class="field"><label>Contact Person *</label><input type="text" name="name" required></div>
                    <div class="field"><label>Company / Factory Name</label><input type="text" name="company_name"></div>
                    <div class="field"><label>Phone *</label><input type="text" name="phone" required></div>
                    <div class="field"><label>Email</label><input type="email" name="email"></div>
                    <div class="field"><label>Address</label><textarea name="address" rows="2"></textarea></div>
                    <button class="button" style="width:100%;">Save Vendor</button>
                </form>
            </div>

            <div class="panel">
                <h3>Record Supplier Bill / Invoice</h3>
                <form method="post">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="add_vendor_invoice">
                    <div class="field">
                        <label>Select Vendor *</label>
                        <select name="vendor_id" required style="width:100%; padding:8px;">
                            <option value="">-- Choose Vendor --</option>
                            <?php foreach ($vendors as $vd): ?>
                                <option value="<?= $vd['id'] ?>"><?= e($vd['name']) ?> (<?= e($vd['company_name'] ?: 'No Company') ?>)</option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="field"><label>Invoice / Bill Number *</label><input type="text" name="invoice_number" required placeholder="e.g. INV-9921"></div>
                    <div class="field"><label>Issue Date</label><input type="date" name="issue_date" value="<?= date('Y-m-d') ?>" required></div>
                    <div class="field"><label>Bill Amount (৳) *</label><input type="number" step="0.01" name="total_amount" required></div>
                    <div class="field"><label>Notes / Memo</label><input type="text" name="notes" placeholder="Bulk purchase memo"></div>
                    <button class="button secondary" style="width:100%;">Record Vendor Bill</button>
                </form>
            </div>
        </div>

        <div class="panel">
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:12px; flex-wrap:wrap; gap:8px;">
                <h3 style="margin:0;">Accounts Payable (Vendor Invoices Ledger)</h3>
                <div style="display:flex; gap:8px; align-items:center;">
                    <form method="get" class="inline-form" style="margin:0;">
                        <input type="hidden" name="tab" value="vendors">
                        <select name="vendor_id" onchange="this.form.submit()" style="padding:6px 10px; font-size:12px;">
                            <option value="0">All Vendors</option>
                            <?php foreach ($vendors as $vd): ?>
                                <option value="<?= $vd['id'] ?>" <?= $vendorFilterId === (int)$vd['id'] ? 'selected' : '' ?>>
                                    <?= e($vd['name']) ?> (<?= e($vd['company_name'] ?: 'No Company') ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </form>
                    <a href="<?= e(url('admin/system/accounting?export=ap_csv')) ?>" class="button secondary" style="padding:6px 10px; font-size:12px; font-weight:bold;">
                        📥 Export AP CSV
                    </a>
                </div>
            </div>

            <div class="table-wrap">
                <table style="width:100%; border-collapse:collapse; font-size:13px;">
                    <thead>
                        <tr style="background:#f8fafc; border-bottom:1px solid #cbd5e1; text-align:left;">
                            <th style="padding:8px;">Invoice #</th>
                            <th style="padding:8px;">Vendor</th>
                            <th style="padding:8px;">Date</th>
                            <th style="padding:8px; text-align:right;">Amount</th>
                            <th style="padding:8px; text-align:right;">Paid</th>
                            <th style="padding:8px; text-align:center;">Status</th>
                            <th style="padding:8px; text-align:center;">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($invoices as $inv):
                            $unpaidAmt = (float)$inv['total_amount'] - (float)$inv['paid_amount'];
                        ?>
                            <tr style="border-bottom:1px solid #f1f5f9;">
                                <td style="padding:8px;"><b><?= e($inv['invoice_number']) ?></b></td>
                                <td style="padding:8px;"><?= e($inv['vendor_name']) ?></td>
                                <td style="padding:8px;"><?= date('M d, Y', strtotime($inv['issue_date'])) ?></td>
                                <td style="padding:8px; text-align:right; font-weight:bold;"><?= money($inv['total_amount']) ?></td>
                                <td style="padding:8px; text-align:right; color:#16a34a;"><?= money($inv['paid_amount']) ?></td>
                                <td style="padding:8px; text-align:center;">
                                    <span class="status" style="padding:2px 6px; font-weight:bold; font-size:11px; background:<?= $inv['status'] === 'paid' ? '#dcfce7; color:#15803d;' : ($inv['status'] === 'partially_paid' ? '#fef3c7; color:#92400e;' : '#fee2e2; color:#b91c1c;') ?>">
                                        <?= strtoupper($inv['status']) ?>
                                    </span>
                                </td>
                                <td style="padding:8px; text-align:center;">
                                    <?php if ($inv['status'] !== 'paid'): ?>
                                        <button onclick="openPayDialog(<?= $inv['id'] ?>, '<?= e($inv['invoice_number']) ?>', <?= $unpaidAmt ?>)" class="button" style="padding:3px 8px; font-size:11px;">Pay</button>
                                    <?php else: ?>
                                        <span class="muted small">Settled</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (!$invoices): ?>
                            <tr>
                                <td colspan="7" style="text-align:center; padding:15px;" class="muted">No vendor bills recorded yet.</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Vendor Payments Log & Receipt Generator -->
    <div class="panel">
        <h3>Disbursed Vendor Payments & Payment Vouchers</h3>
        <p class="muted small">Click "Receipt" to print or download an official payment acknowledgement voucher for any vendor payment.</p>
        <div class="table-wrap">
            <table style="width:100%; border-collapse:collapse; font-size:13px;">
                <thead>
                    <tr style="background:#f8fafc; border-bottom:1px solid #cbd5e1; text-align:left;">
                        <th style="padding:8px;">Voucher #</th>
                        <th style="padding:8px;">Date</th>
                        <th style="padding:8px;">Vendor</th>
                        <th style="padding:8px;">Bill Reference</th>
                        <th style="padding:8px;">Method</th>
                        <th style="padding:8px; text-align:right;">Amount Paid</th>
                        <th style="padding:8px; text-align:center;">Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($recentPayments as $vp): ?>
                        <tr style="border-bottom:1px solid #f1f5f9;">
                            <td style="padding:8px; font-family:monospace; font-weight:bold;">VCH-P<?= $vp['id'] ?></td>
                            <td style="padding:8px;"><?= date('M d, Y', strtotime($vp['payment_date'])) ?></td>
                            <td style="padding:8px;"><b><?= e($vp['vendor_name']) ?></b> (<?= e($vp['company_name'] ?: 'N/A') ?>)</td>
                            <td style="padding:8px;">Bill #<?= e($vp['invoice_number']) ?></td>
                            <td style="padding:8px;">
                                <span class="status" style="font-size:11px;">
                                    <?= $vp['payment_method'] === 'bank' ? '🏦 Bank Account' : '💵 Cash on Hand' ?>
                                </span>
                            </td>
                            <td style="padding:8px; text-align:right; font-weight:bold; color:#16a34a;"><?= money($vp['amount']) ?></td>
                            <td style="padding:8px; text-align:center;">
                                <button type="button" class="button secondary" style="padding:3px 8px; font-size:11px;" onclick="printVoucher('<?= 'VCH-P' . $vp['id'] ?>', '<?= date('d M, Y', strtotime($vp['payment_date'])) ?>', '<?= addslashes(e($vp['vendor_name'])) ?>', '<?= addslashes(e($vp['company_name'] ?: '')) ?>', '<?= addslashes(e($vp['invoice_number'])) ?>', '<?= money($vp['amount']) ?>', '<?= $vp['payment_method'] === 'bank' ? 'BANK ACCOUNT' : 'CASH ON HAND' ?>', '<?= addslashes(e($vp['reference_no'] ?: 'None')) ?>')">
                                    🧾 Receipt
                                </button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$recentPayments): ?>
                        <tr>
                            <td colspan="7" class="muted" style="text-align:center; padding:15px;">No payments disbursed yet.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Payment Entry Modal Window -->
    <div class="modal-overlay" id="payModalOverlay">
        <div class="modal-window">
            <h3 style="margin-top:0; border-bottom:1px solid #cbd5e1; padding-bottom:10px;">
                💳 Record Vendor Bill Payment
            </h3>
            <p class="muted small" id="payModalInvoiceHeader" style="margin-bottom:15px; font-weight:bold;"></p>
            <form method="post" id="payModalForm">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="pay_vendor_invoice">
                <input type="hidden" name="invoice_id" id="modal_invoice_id">

                <div class="field" style="margin-bottom:12px;">
                    <label>Payment Source / Method *</label>
                    <select name="payment_method" required style="width:100%; padding:8px; font-weight:bold;">
                        <option value="bank">🏦 Bank Account (Commercial Account)</option>
                        <option value="cash">💵 Cash on Hand (Petty Cash Drawer)</option>
                    </select>
                </div>

                <div class="field" style="margin-bottom:12px;">
                    <label>Amount (৳) *</label>
                    <input type="number" step="0.01" min="0.01" name="amount" id="modal_pay_amount" required style="width:100%; font-size:16px; font-weight:bold;">
                </div>

                <div class="field" style="margin-bottom:12px;">
                    <label>Date of Payment *</label>
                    <input type="date" name="payment_date" value="<?= date('Y-m-d') ?>" required style="width:100%;">
                </div>

                <div class="field" style="margin-bottom:12px;">
                    <label>Cheque / Transaction / bKash Reference (Optional)</label>
                    <input type="text" name="reference_no" placeholder="e.g. Wire Ref #9921 or Cheque #1024" style="width:100%;">
                </div>

                <div class="field" style="margin-bottom:18px;">
                    <label>Payment Memo / Note</label>
                    <input type="text" name="notes" placeholder="Settlement note" style="width:100%;">
                </div>

                <div style="display:flex; justify-content:flex-end; gap:8px;">
                    <button type="button" onclick="closePayDialog()" class="button secondary">Cancel</button>
                    <button type="submit" class="button" style="background:#16a34a; border-color:#15803d;">Confirm & Disburse</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Hidden Printable Payment Voucher Container -->
    <div id="printable-voucher" style="display:none; max-width:650px; margin:0 auto; padding:25px; border:1px solid #333; border-radius:6px; background:#fff;">
        <div style="border-bottom:2px solid #0f172a; padding-bottom:12px; margin-bottom:15px; display:flex; justify-content:space-between; align-items:center;">
            <div>
                <h2 style="margin:0; font-size:20px;"><?= e(store_name()) ?></h2>
                <span style="font-size:12px; color:#555;">Official Vendor Payment Voucher</span>
            </div>
            <div style="text-align:right;">
                <b style="font-size:15px;" id="v-voucher-no">VCH-000</b><br>
                <span style="font-size:12px;" id="v-date">Date</span>
            </div>
        </div>

        <table style="width:100%; border-collapse:collapse; margin-bottom:20px; font-size:13px;">
            <tr>
                <td style="padding:6px 0; width:30%;"><b>Paid To (Vendor):</b></td>
                <td style="padding:6px 0;" id="v-vendor-name">—</td>
            </tr>
            <tr>
                <td style="padding:6px 0;"><b>Company / Business:</b></td>
                <td style="padding:6px 0;" id="v-vendor-comp">—</td>
            </tr>
            <tr>
                <td style="padding:6px 0;"><b>Against Bill / Invoice:</b></td>
                <td style="padding:6px 0;" id="v-bill-no">—</td>
            </tr>
            <tr>
                <td style="padding:6px 0;"><b>Payment Method:</b></td>
                <td style="padding:6px 0;" id="v-method">—</td>
            </tr>
            <tr>
                <td style="padding:6px 0;"><b>Cheque / Transaction Ref:</b></td>
                <td style="padding:6px 0;" id="v-ref">—</td>
            </tr>
            <tr style="border-top:1px solid #000; font-size:16px;">
                <td style="padding:10px 0; font-weight:bold;">Total Amount Disbursed:</td>
                <td style="padding:10px 0; font-weight:bold; color:#16a34a;" id="v-amount">৳0.00</td>
            </tr>
        </table>

        <div style="display:flex; justify-content:space-between; margin-top:50px; padding-top:15px; font-size:12px;">
            <div style="text-align:center; border-top:1px solid #777; width:200px;">
                Authorized Signature (Accounts)
            </div>
            <div style="text-align:center; border-top:1px solid #777; width:200px;">
                Received By (Vendor Signature)
            </div>
        </div>
    </div>

    <script>
        function openPayDialog(invId, invNo, remBal) {
            document.getElementById('modal_invoice_id').value = invId;
            document.getElementById('modal_pay_amount').value = remBal.toFixed(2);
            document.getElementById('modal_pay_amount').max = remBal.toFixed(2);
            document.getElementById('payModalInvoiceHeader').textContent = 'Paying Bill #' + invNo + ' (Due Outstanding: ৳' + remBal.toFixed(2) + ')';
            document.getElementById('payModalOverlay').style.display = 'flex';
        }

        function closePayDialog() {
            document.getElementById('payModalOverlay').style.display = 'none';
        }

        function printVoucher(vNo, vDate, vName, vComp, vBill, vAmt, vMethod, vRef) {
            document.getElementById('v-voucher-no').textContent = vNo;
            document.getElementById('v-date').textContent = 'Date: ' + vDate;
            document.getElementById('v-vendor-name').textContent = vName;
            document.getElementById('v-vendor-comp').textContent = vComp || 'N/A';
            document.getElementById('v-bill-no').textContent = '#' + vBill;
            document.getElementById('v-method').textContent = vMethod;
            document.getElementById('v-ref').textContent = vRef || 'None';
            document.getElementById('v-amount').textContent = vAmt;

            const printBox = document.getElementById('printable-voucher');
            printBox.style.display = 'block';
            window.print();
            printBox.style.display = 'none';
        }
    </script>
<?php endif; ?>

<!-- ================= TAB 5: DAILY EXPENSES & PETTY CASH ================= -->
<?php if ($activeTab === 'expenses'):
    $coaExpenses = db()->query("SELECT code, name FROM accounting_coa WHERE type = 'expense' AND sub_type = 'opex' AND is_active = 1 ORDER BY code ASC")->fetchAll(PDO::FETCH_ASSOC);
    $expenseLogs = db()->query('
        SELECT ae.*, coa.name AS account_name 
        FROM accounting_expenses ae 
        JOIN accounting_coa coa ON coa.code = ae.account_code 
        ORDER BY ae.expense_date DESC, ae.id DESC 
        LIMIT 50
    ')->fetchAll(PDO::FETCH_ASSOC);
?>
    <div style="display:grid; grid-template-columns: 1fr 2fr; gap:20px;">
        <div class="panel">
            <h3>Log Operating Expense</h3>
            <form method="post">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="add_expense">
                <div class="field">
                    <label>Expense Category *</label>
                    <select name="account_code" required style="width:100%; padding:8px;">
                        <option value="">-- Choose Category --</option>
                        <?php foreach ($coaExpenses as $ce): ?>
                            <option value="<?= $ce['code'] ?>"><?= $ce['code'] ?> - <?= e($ce['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="field"><label>Expense Description *</label><input type="text" name="title" required placeholder="e.g. Meta Facebook Ads or Packaging Tape"></div>
                <div class="field"><label>Amount (৳) *</label><input type="number" step="0.01" name="amount" required></div>
                <div class="field">
                    <label>Payment Source</label>
                    <select name="payment_source" style="width:100%; padding:8px;">
                        <option value="petty_cash">Cash on Hand (Petty Cash)</option>
                        <option value="bank">Bank Account</option>
                    </select>
                </div>
                <div class="field"><label>Date</label><input type="date" name="expense_date" value="<?= date('Y-m-d') ?>" required></div>
                <div class="field"><label>Receipt / Voucher Ref #</label><input type="text" name="receipt_reference" placeholder="VCH-102"></div>
                <button class="button" style="width:100%;">Record Expense Voucher</button>
            </form>
        </div>

        <div class="panel">
            <h3>Operational Expenses & Petty Cash Journal</h3>
            <div class="table-wrap">
                <table style="width:100%; border-collapse:collapse; font-size:13px;">
                    <thead>
                        <tr style="background:#f8fafc; border-bottom:1px solid #cbd5e1; text-align:left;">
                            <th style="padding:8px;">Date</th>
                            <th style="padding:8px;">Category</th>
                            <th style="padding:8px;">Description</th>
                            <th style="padding:8px;">Source</th>
                            <th style="padding:8px; text-align:right;">Amount</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($expenseLogs as $el): ?>
                            <tr style="border-bottom:1px solid #f1f5f9;">
                                <td style="padding:8px;"><?= date('M d, Y', strtotime($el['expense_date'])) ?></td>
                                <td style="padding:8px;"><span class="status" style="font-size:11px;"><?= e($el['account_name']) ?></span></td>
                                <td style="padding:8px; font-weight:600;"><?= e($el['title']) ?></td>
                                <td style="padding:8px;"><?= $el['payment_source'] === 'bank' ? '🏦 Bank' : '💵 Petty Cash' ?></td>
                                <td style="padding:8px; text-align:right; font-weight:bold; color:#dc2626;"><?= money($el['amount']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (!$expenseLogs): ?>
                            <tr>
                                <td colspan="5" style="text-align:center; padding:15px;" class="muted">No operational expenses logged yet.</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
<?php endif; ?>

<!-- ================= TAB 6: CAPITAL & STAKEHOLDERS ================= -->
<?php if ($activeTab === 'stakeholders'):
    $stakeholders = db()->query('SELECT * FROM accounting_stakeholders ORDER BY equity_percentage DESC')->fetchAll(PDO::FETCH_ASSOC);
?>
    <div style="display:grid; grid-template-columns: 1fr 2fr; gap:20px;">
        <div class="panel">
            <h3>Add Stakeholder / Partner</h3>
            <form method="post">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="add_stakeholder">
                <div class="field"><label>Full Name *</label><input type="text" name="name" required placeholder="Partner or Founder Name"></div>
                <div class="field"><label>Role / Title</label><input type="text" name="role" value="Shareholder"></div>
                <div class="field"><label>Equity Ownership (%) *</label><input type="number" step="0.01" min="0" max="100" name="equity_percentage" required placeholder="e.g. 50"></div>
                <div class="field"><label>Total Capital Contributed (৳)</label><input type="number" step="0.01" name="total_contributed" value="0.00"></div>
                <div class="field"><label>Phone</label><input type="text" name="phone"></div>
                <div class="field"><label>Email</label><input type="email" name="email"></div>
                <button class="button" style="width:100%;">Save Stakeholder</button>
            </form>
        </div>

        <div class="panel">
            <h3>Stakeholder Equity & Capital Ownership</h3>
            <div class="table-wrap">
                <table style="width:100%; border-collapse:collapse; font-size:13px;">
                    <thead>
                        <tr style="background:#f8fafc; border-bottom:1px solid #cbd5e1; text-align:left;">
                            <th style="padding:8px;">Stakeholder</th>
                            <th style="padding:8px;">Role</th>
                            <th style="padding:8px; text-align:center;">Equity Share</th>
                            <th style="padding:8px; text-align:right;">Capital Contributed</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($stakeholders as $sh): ?>
                            <tr style="border-bottom:1px solid #f1f5f9;">
                                <td style="padding:8px;"><b><?= e($sh['name']) ?></b></td>
                                <td style="padding:8px;"><?= e($sh['role']) ?></td>
                                <td style="padding:8px; text-align:center; font-weight:bold; color:#2563eb;"><?= number_format((float)$sh['equity_percentage'], 2) ?>%</td>
                                <td style="padding:8px; text-align:right; font-weight:bold; color:#16a34a;"><?= money($sh['total_contributed']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (!$stakeholders): ?>
                            <tr>
                                <td colspan="4" style="text-align:center; padding:15px;" class="muted">No stakeholders registered yet.</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
<?php endif; ?>

<!-- ================= TAB 7: GENERAL LEDGER & TRIAL BALANCE ================= -->
<?php if ($activeTab === 'ledger'):
    $coaList = db()->query("SELECT code, name, type FROM accounting_coa WHERE is_active = 1 ORDER BY code ASC")->fetchAll(PDO::FETCH_ASSOC);

    $tbStmt = db()->query("
        SELECT 
            c.code, 
            c.name, 
            c.type,
            COALESCE(SUM(l.debit), 0) AS total_debit,
            COALESCE(SUM(l.credit), 0) AS total_credit
        FROM accounting_coa c
        LEFT JOIN accounting_journal_lines l ON l.account_code = c.code
        GROUP BY c.code, c.name, c.type
        HAVING total_debit > 0 OR total_credit > 0
        ORDER BY c.code ASC
    ");
    $trialBalance = $tbStmt->fetchAll(PDO::FETCH_ASSOC);

    $sumDebit  = array_sum(array_column($trialBalance, 'total_debit'));
    $sumCredit = array_sum(array_column($trialBalance, 'total_credit'));

    $recentJournals = db()->query("
        SELECT j.*, 
            (SELECT COUNT(*) FROM accounting_journal_lines WHERE journal_id = j.id) as line_count,
            (SELECT SUM(debit) FROM accounting_journal_lines WHERE journal_id = j.id) as total_amount
        FROM accounting_journals j
        ORDER BY j.entry_date DESC, j.id DESC
        LIMIT 50
    ")->fetchAll(PDO::FETCH_ASSOC);
?>
    <div style="display:grid; grid-template-columns: 1fr 1fr; gap:20px; margin-bottom:25px;">
        <div class="panel">
            <h3>Record Adjusting Journal Entry</h3>
            <p class="muted small">Create manual balancing debit & credit adjustments.</p>
            <form method="post" id="journalForm">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="create_manual_journal">

                <div class="form-grid" style="margin-bottom:12px;">
                    <div class="field"><label>Date *</label><input type="date" name="entry_date" value="<?= date('Y-m-d') ?>" required></div>
                    <div class="field"><label>Memo / Description *</label><input type="text" name="memo" placeholder="e.g. Month-end depreciation or audit fix" required></div>
                </div>

                <div class="table-wrap" style="margin-bottom:10px;">
                    <table style="width:100%; border-collapse:collapse; font-size:12px;" id="journalLinesTable">
                        <thead>
                            <tr style="background:#f8fafc; border-bottom:1px solid #cbd5e1; text-align:left;">
                                <th style="padding:6px;">Account</th>
                                <th style="padding:6px; width:100px;">Debit (৳)</th>
                                <th style="padding:6px; width:100px;">Credit (৳)</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td style="padding:6px;">
                                    <select name="account_code[]" style="width:100%; padding:4px;" required>
                                        <option value="">-- Choose Account --</option>
                                        <?php foreach ($coaList as $c): ?>
                                            <option value="<?= $c['code'] ?>"><?= $c['code'] ?> - <?= e($c['name']) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </td>
                                <td style="padding:6px;"><input type="number" step="0.01" min="0" name="debit[]" value="0.00" style="width:100%; padding:4px;"></td>
                                <td style="padding:6px;"><input type="number" step="0.01" min="0" name="credit[]" value="0.00" style="width:100%; padding:4px;"></td>
                            </tr>
                            <tr>
                                <td style="padding:6px;">
                                    <select name="account_code[]" style="width:100%; padding:4px;" required>
                                        <option value="">-- Choose Account --</option>
                                        <?php foreach ($coaList as $c): ?>
                                            <option value="<?= $c['code'] ?>"><?= $c['code'] ?> - <?= e($c['name']) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </td>
                                <td style="padding:6px;"><input type="number" step="0.01" min="0" name="debit[]" value="0.00" style="width:100%; padding:4px;"></td>
                                <td style="padding:6px;"><input type="number" step="0.01" min="0" name="credit[]" value="0.00" style="width:100%; padding:4px;"></td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <button class="button" style="width:100%; background:#2563eb; border-color:#1d4ed8;">Post Double-Entry Journal</button>
            </form>
        </div>

        <div class="panel">
            <h3>Trial Balance Verification</h3>
            <p class="muted small">Checks that all system debits match all credits.</p>
            <div class="table-wrap">
                <table style="width:100%; border-collapse:collapse; font-size:13px;">
                    <thead>
                        <tr style="background:#f8fafc; border-bottom:1px solid #cbd5e1; text-align:left;">
                            <th style="padding:6px;">Account</th>
                            <th style="padding:6px; text-align:right;">Debit</th>
                            <th style="padding:6px; text-align:right;">Credit</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($trialBalance as $tb): ?>
                            <tr style="border-bottom:1px solid #f1f5f9;">
                                <td style="padding:6px;"><?= $tb['code'] ?> - <?= e($tb['name']) ?></td>
                                <td style="padding:6px; text-align:right;"><?= $tb['total_debit'] > 0 ? money($tb['total_debit']) : '—' ?></td>
                                <td style="padding:6px; text-align:right;"><?= $tb['total_credit'] > 0 ? money($tb['total_credit']) : '—' ?></td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (!$trialBalance): ?>
                            <tr>
                                <td colspan="3" class="muted" style="padding:15px; text-align:center;">No double-entry transactions posted yet.</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                    <tfoot>
                        <tr style="border-top:2px solid #0f172a; font-weight:800; background:#f8fafc;">
                            <td style="padding:8px;">TOTALS:</td>
                            <td style="padding:8px; text-align:right; color:#2563eb;"><?= money($sumDebit) ?></td>
                            <td style="padding:8px; text-align:right; color:#2563eb;"><?= money($sumCredit) ?></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
            <div style="margin-top:10px; text-align:center; padding:8px; border-radius:4px; font-weight:bold; font-size:12px; background:<?= round($sumDebit, 2) === round($sumCredit, 2) ? '#dcfce7; color:#15803d;' : '#fee2e2; color:#b91c1c;' ?>">
                <?= round($sumDebit, 2) === round($sumCredit, 2) ? '✓ Books are balanced (Debits = Credits)' : '⚠ Discrepancy detected between Debits and Credits' ?>
            </div>
        </div>
    </div>

    <div class="panel">
        <h3>General Ledger Journal Log</h3>
        <div class="table-wrap">
            <table style="width:100%; border-collapse:collapse; font-size:13px;">
                <thead>
                    <tr style="background:#f8fafc; border-bottom:1px solid #cbd5e1; text-align:left;">
                        <th style="padding:8px;">Entry #</th>
                        <th style="padding:8px;">Date</th>
                        <th style="padding:8px;">Type</th>
                        <th style="padding:8px;">Memo / Description</th>
                        <th style="padding:8px; text-align:center;">Lines</th>
                        <th style="padding:8px; text-align:right;">Amount</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($recentJournals as $rj): ?>
                        <tr style="border-bottom:1px solid #f1f5f9;">
                            <td style="padding:8px; font-family:monospace; font-weight:bold;"><?= e($rj['entry_number']) ?></td>
                            <td style="padding:8px;"><?= date('M d, Y', strtotime($rj['entry_date'])) ?></td>
                            <td style="padding:8px;"><span class="status" style="font-size:11px;"><?= strtoupper(e($rj['reference_type'])) ?></span></td>
                            <td style="padding:8px;"><?= e($rj['memo']) ?></td>
                            <td style="padding:8px; text-align:center;"><?= (int)$rj['line_count'] ?></td>
                            <td style="padding:8px; text-align:right; font-weight:bold;"><?= money($rj['total_amount']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$recentJournals): ?>
                        <tr>
                            <td colspan="6" style="text-align:center; padding:15px;" class="muted">No journal records found.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
<?php endif; ?>

<!-- ================= TAB 8: COA & COURIER SETTINGS ================= -->
<?php if ($activeTab === 'settings'):
    $allCoa = db()->query('SELECT * FROM accounting_coa ORDER BY code ASC')->fetchAll(PDO::FETCH_ASSOC);
    $allCouriers = db()->query('SELECT * FROM courier_services ORDER BY name ASC')->fetchAll(PDO::FETCH_ASSOC);
?>
    <div style="display:grid; grid-template-columns: 1fr 2fr; gap:20px; margin-bottom:25px;">
        <!-- Add New Account to Chart of Accounts -->
        <div class="panel">
            <h3>Add New Account / Category</h3>
            <p class="muted small">Create new expense types, bank accounts, or asset buckets.</p>
            <form method="post">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="add_coa_account">

                <div class="field" style="margin-bottom:10px;">
                    <label>Account Code * (e.g. 6080)</label>
                    <input type="text" name="code" required placeholder="Unique 4-digit code">
                </div>

                <div class="field" style="margin-bottom:10px;">
                    <label>Account Name *</label>
                    <input type="text" name="name" required placeholder="e.g. Legal Fees, Influencer Payouts">
                </div>

                <div class="field" style="margin-bottom:10px;">
                    <label>Account Class / Type *</label>
                    <select name="type" required style="width:100%; padding:8px;">
                        <option value="expense">Expense (OPEX)</option>
                        <option value="asset">Asset (Bank / Cash / Receivable)</option>
                        <option value="liability">Liability (Payable / Loan)</option>
                        <option value="equity">Equity (Capital / Partner Share)</option>
                        <option value="revenue">Revenue (Sales / Income)</option>
                    </select>
                </div>

                <div class="field" style="margin-bottom:10px;">
                    <label>Sub-Type</label>
                    <select name="sub_type" style="width:100%; padding:8px;">
                        <option value="opex">Operational Expense (OPEX)</option>
                        <option value="cogs">Cost of Goods Sold (COGS)</option>
                        <option value="bank">Bank Account</option>
                        <option value="cash">Petty Cash</option>
                        <option value="payable">Accounts Payable</option>
                        <option value="receivable">Accounts Receivable</option>
                    </select>
                </div>

                <div class="field" style="margin-bottom:15px;">
                    <label>Description / Usage Note</label>
                    <input type="text" name="description" placeholder="Brief explanation">
                </div>

                <button class="button" style="width:100%; background:#2563eb; border-color:#1d4ed8;">Create Account</button>
            </form>
        </div>

        <!-- Master Chart of Accounts Table -->
        <div class="panel">
            <h3>Chart of Accounts (COA) Directory</h3>
            <div class="table-wrap">
                <table style="width:100%; border-collapse:collapse; font-size:13px;">
                    <thead>
                        <tr style="background:#f8fafc; border-bottom:1px solid #cbd5e1; text-align:left;">
                            <th style="padding:8px;">Code</th>
                            <th style="padding:8px;">Account Name</th>
                            <th style="padding:8px;">Type</th>
                            <th style="padding:8px;">Visibility</th>
                            <th style="padding:8px; text-align:center;">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($allCoa as $ca):
                            $isCore = in_array($ca['code'], $coreAccounts, true);
                            $isActive = (int)($ca['is_active'] ?? 1);
                        ?>
                            <tr style="border-bottom:1px solid #f1f5f9; <?= !$isActive ? 'background:#f8fafc; opacity:0.65;' : '' ?>">
                                <td style="padding:8px; font-weight:bold; font-family:monospace;"><?= e($ca['code']) ?></td>
                                <td style="padding:8px;">
                                    <b><?= e($ca['name']) ?></b>
                                    <?php if (!empty($ca['description'])): ?>
                                        <div class="muted small"><?= e($ca['description']) ?></div>
                                    <?php endif; ?>
                                </td>
                                <td style="padding:8px;"><span class="status" style="font-size:11px;"><?= strtoupper(e($ca['type'])) ?></span></td>
                                <td style="padding:8px;">
                                    <?php if ($isCore): ?>
                                        <span class="status" style="font-size:11px; background:#e0e7ff; color:#3730a3;">🔒 CORE SYSTEM</span>
                                    <?php else: ?>
                                        <span class="status" style="font-size:11px; background:<?= $isActive ? '#dcfce7; color:#15803d;' : '#f1f5f9; color:#64748b;' ?>">
                                            <?= $isActive ? '● VISIBLE' : '○ HIDDEN' ?>
                                        </span>
                                    <?php endif; ?>
                                </td>
                                <td style="padding:8px; text-align:center; white-space:nowrap;">
                                    <?php if ($isCore): ?>
                                        <span class="muted small" style="color:#94a3b8; font-style:italic;">Protected (Immutable)</span>
                                    <?php else: ?>
                                        <!-- Edit Button for Custom Accounts -->
                                        <button type="button" class="button secondary" style="padding:2px 7px; font-size:11px; margin-right:4px;"
                                            onclick="openEditCoaModal(<?= $ca['id'] ?>, '<?= e($ca['code']) ?>', '<?= addslashes(e($ca['name'])) ?>', '<?= e($ca['type']) ?>', '<?= e($ca['sub_type'] ?? 'opex') ?>', '<?= addslashes(e($ca['description'] ?? '')) ?>')">
                                            ✏️ Edit
                                        </button>

                                        <!-- Hide / Show Toggle for Custom Accounts -->
                                        <form method="post" style="display:inline;" onsubmit="return confirm('<?= $isActive ? 'Hide' : 'Unhide' ?> account <?= e($ca['code']) ?>?');">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="action" value="toggle_coa_status">
                                            <input type="hidden" name="id" value="<?= $ca['id'] ?>">
                                            <button style="background:none; border:none; color:<?= $isActive ? '#d97706' : '#2563eb' ?>; cursor:pointer; font-size:12px; margin-right:4px;">
                                                <?= $isActive ? 'Hide' : 'Show' ?>
                                            </button>
                                        </form>

                                        <!-- Delete Button for Custom Accounts -->
                                        <form method="post" style="display:inline;" onsubmit="return confirm('Delete account <?= e($ca['code']) ?> completely?');">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="action" value="delete_coa_account">
                                            <input type="hidden" name="id" value="<?= $ca['id'] ?>">
                                            <button style="background:none; border:none; color:#dc2626; cursor:pointer; font-size:12px;">Delete</button>
                                        </form>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Manage Courier Services -->
    <div style="display:grid; grid-template-columns: 1fr 2fr; gap:20px;">
        <div class="panel">
            <h3>Add Courier Partner</h3>
            <form method="post">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="add_courier">
                <div class="field" style="margin-bottom:12px;">
                    <label>Courier Name *</label>
                    <input type="text" name="name" required placeholder="e.g. Delhivery, SA Paribahan">
                </div>
                <button class="button" style="width:100%; background:#16a34a; border-color:#15803d;">Add Courier Service</button>
            </form>
        </div>

        <div class="panel">
            <h3>Registered Delivery Couriers</h3>
            <div class="table-wrap">
                <table style="width:100%; border-collapse:collapse; font-size:13px;">
                    <thead>
                        <tr style="background:#f8fafc; border-bottom:1px solid #cbd5e1; text-align:left;">
                            <th style="padding:8px;">Courier Name</th>
                            <th style="padding:8px;">Visibility</th>
                            <th style="padding:8px; text-align:center;">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($allCouriers as $cr):
                            $cActive = (int)($cr['is_active'] ?? 1);
                        ?>
                            <tr style="border-bottom:1px solid #f1f5f9; <?= !$cActive ? 'background:#f8fafc; opacity:0.65;' : '' ?>">
                                <td style="padding:8px;"><b><?= e($cr['name']) ?></b></td>
                                <td style="padding:8px;">
                                    <span class="status" style="font-size:11px; background:<?= $cActive ? '#dcfce7; color:#15803d;' : '#f1f5f9; color:#64748b;' ?>">
                                        <?= $cActive ? '● VISIBLE' : '○ HIDDEN' ?>
                                    </span>
                                </td>
                                <td style="padding:8px; text-align:center; white-space:nowrap;">
                                    <!-- Edit Courier Name -->
                                    <button type="button" class="button secondary" style="padding:2px 7px; font-size:11px; margin-right:4px;"
                                        onclick="openEditCourierModal(<?= $cr['id'] ?>, '<?= addslashes(e($cr['name'])) ?>')">
                                        ✏️ Edit
                                    </button>

                                    <!-- Hide / Show Toggle -->
                                    <form method="post" style="display:inline;">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="toggle_courier_status">
                                        <input type="hidden" name="id" value="<?= $cr['id'] ?>">
                                        <button style="background:none; border:none; color:<?= $cActive ? '#d97706' : '#2563eb' ?>; cursor:pointer; font-size:12px; margin-right:4px;">
                                            <?= $cActive ? 'Hide' : 'Show' ?>
                                        </button>
                                    </form>

                                    <!-- Delete Button -->
                                    <form method="post" style="display:inline;" onsubmit="return confirm('Remove courier <?= addslashes(e($cr['name'])) ?>?');">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="delete_courier">
                                        <input type="hidden" name="id" value="<?= $cr['id'] ?>">
                                        <button style="background:none; border:none; color:#dc2626; cursor:pointer; font-size:12px;">Remove</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Edit Custom COA Account Modal -->
    <div class="modal-overlay" id="editCoaModal">
        <div class="modal-window">
            <h3 style="margin-top:0; border-bottom:1px solid #cbd5e1; padding-bottom:10px;">
                ✏️ Edit Account: <span id="modal_coa_code_display" style="font-family:monospace; color:#2563eb;"></span>
            </h3>
            <form method="post">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="update_coa_account">
                <input type="hidden" name="id" id="modal_edit_coa_id">

                <div class="field" style="margin-bottom:12px;">
                    <label>Account Name *</label>
                    <input type="text" name="name" id="modal_edit_coa_name" required style="width:100%;">
                </div>

                <div class="field" style="margin-bottom:12px;">
                    <label>Account Class / Type *</label>
                    <select name="type" id="modal_edit_coa_type" required style="width:100%; padding:8px;">
                        <option value="expense">Expense (OPEX)</option>
                        <option value="asset">Asset (Bank / Cash / Receivable)</option>
                        <option value="liability">Liability (Payable / Loan)</option>
                        <option value="equity">Equity (Capital / Partner Share)</option>
                        <option value="revenue">Revenue (Sales / Income)</option>
                    </select>
                </div>

                <div class="field" style="margin-bottom:12px;">
                    <label>Sub-Type</label>
                    <select name="sub_type" id="modal_edit_coa_subtype" style="width:100%; padding:8px;">
                        <option value="opex">Operational Expense (OPEX)</option>
                        <option value="cogs">Cost of Goods Sold (COGS)</option>
                        <option value="bank">Bank Account</option>
                        <option value="cash">Petty Cash</option>
                        <option value="payable">Accounts Payable</option>
                        <option value="receivable">Accounts Receivable</option>
                    </select>
                </div>

                <div class="field" style="margin-bottom:18px;">
                    <label>Description</label>
                    <input type="text" name="description" id="modal_edit_coa_desc" style="width:100%;">
                </div>

                <div style="display:flex; justify-content:flex-end; gap:8px;">
                    <button type="button" onclick="closeEditCoaModal()" class="button secondary">Cancel</button>
                    <button type="submit" class="button">Save Changes</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Edit Courier Modal -->
    <div class="modal-overlay" id="editCourierModal">
        <div class="modal-window">
            <h3 style="margin-top:0; border-bottom:1px solid #cbd5e1; padding-bottom:10px;">
                ✏️ Edit Courier Service
            </h3>
            <form method="post">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="update_courier">
                <input type="hidden" name="id" id="modal_edit_courier_id">

                <div class="field" style="margin-bottom:18px;">
                    <label>Courier Name *</label>
                    <input type="text" name="name" id="modal_edit_courier_name" required style="width:100%;">
                </div>

                <div style="display:flex; justify-content:flex-end; gap:8px;">
                    <button type="button" onclick="closeEditCourierModal()" class="button secondary">Cancel</button>
                    <button type="submit" class="button">Save Changes</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        function openEditCoaModal(id, code, name, type, subType, desc) {
            document.getElementById('modal_edit_coa_id').value = id;
            document.getElementById('modal_coa_code_display').textContent = code;
            document.getElementById('modal_edit_coa_name').value = name;
            document.getElementById('modal_edit_coa_type').value = type;
            document.getElementById('modal_edit_coa_subtype').value = subType;
            document.getElementById('modal_edit_coa_desc').value = desc;
            document.getElementById('editCoaModal').style.display = 'flex';
        }

        function closeEditCoaModal() {
            document.getElementById('editCoaModal').style.display = 'none';
        }

        function openEditCourierModal(id, name) {
            document.getElementById('modal_edit_courier_id').value = id;
            document.getElementById('modal_edit_courier_name').value = name;
            document.getElementById('editCourierModal').style.display = 'flex';
        }

        function closeEditCourierModal() {
            document.getElementById('editCourierModal').style.display = 'none';
        }
    </script>
<?php endif; ?>

<?php admin_footer(); ?>