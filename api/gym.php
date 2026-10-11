<?php
// Gym Operations API for Staff & Admin Portal
require_once __DIR__ . '/../db.php';

try {
    $me = require_login();
    $action = isset($_GET['action']) ? $_GET['action'] : '';
    $pdo = db();

    switch ($action) {

        // Returns full live operational state for staff dashboard
        case 'get_data':
            body();
            $today = date('Y-m-d');

            // 1. Plans
            $st = $pdo->query("SELECT plan_id AS id, name AS n, days, CAST(price AS UNSIGNED) AS p, IF(status = 'Active', 1, 0) AS a FROM membership_plans ORDER BY plan_id");
            $plans = $st->fetchAll();

            // 2. Members
            $st = $pdo->query("SELECT member_id AS id, CONCAT(first_name, ' ', last_name) AS n, first_name AS f, last_name AS l, email AS e, contact AS c, birthdate AS b, joined_on AS j, must_change_password AS mcp, street_house_no AS s, barangay AS br, city_municipality AS ct, province AS pv FROM members ORDER BY member_id");
            $rawMembers = $st->fetchAll();
            $members = [];
            foreach ($rawMembers as $m) {
                $members[] = [
                    'id'  => $m['id'],
                    'n'   => $m['n'],
                    'f'   => $m['f'],
                    'l'   => $m['l'],
                    'e'   => $m['e'],
                    'c'   => $m['c'],
                    'b'   => $m['b'] ?: '',
                    'j'   => $m['j'],
                    'mcp' => (int)$m['mcp'],
                    'a'   => [
                        's'  => $m['s'] ?: '-',
                        'b'  => $m['br'] ?: '-',
                        'c'  => $m['ct'] ?: '-',
                        'p'  => $m['pv'] ?: '-'
                    ]
                ];
            }

            // 3. Orders (Memberships)
            $st = $pdo->query("SELECT m.membership_id AS id, m.member_id AS m, p.name AS plan, m.paying_plan AS pp, m.pay_every AS freq, m.installment_count AS n, m.interval_days AS every, CAST(m.total_price AS UNSIGNED) AS price, m.duration_days AS days, m.purchased_on AS buy, m.start_date AS start, m.end_date AS end, IF(m.status = 'Cancelled', 1, 0) AS cx, m.is_grace AS grace FROM memberships m JOIN membership_plans p ON m.plan_id = p.plan_id ORDER BY m.membership_id");
            $orders = $st->fetchAll();

            // 4. Payments
            $st = $pdo->query("SELECT py.payment_id AS id, py.membership_id AS o, ms.member_id AS m, CAST(py.amount AS UNSIGNED) AS a, py.paid_on AS dt, TIME_FORMAT(py.paid_time, '%H:%i') AS tm, py.method AS me, COALESCE(u.username, '') AS `by`, py.is_void AS v, py.status AS st, COALESCE(py.receipt_ref, '—') AS ref, LOWER(py.source) AS src, py.decline_reason AS why FROM payments py JOIN memberships ms ON py.membership_id = ms.membership_id LEFT JOIN users u ON py.received_by = u.user_id ORDER BY py.paid_on DESC, py.paid_time DESC, py.payment_id DESC");
            $pays = $st->fetchAll();

            // 5. Attendance
            $st = $pdo->query("SELECT member_id AS m, attendance_date AS d, TIME_FORMAT(time_in, '%H:%i') AS i, IFNULL(TIME_FORMAT(time_out, '%H:%i'), '') AS o FROM attendance ORDER BY attendance_date DESC, time_in DESC");
            $att = $st->fetchAll();

            // 6. Blocked checkins (flags)
            $st = $pdo->query("SELECT member_id AS m, DATE_FORMAT(attempted_at, '%Y-%m-%d') AS d, TIME_FORMAT(attempted_at, '%H:%i') AS t, member_status AS s FROM blocked_checkins ORDER BY attempted_at DESC");
            $flags = $st->fetchAll();

            // 7. Audit log
            $st = $pdo->query("SELECT DATE_FORMAT(l.performed_at, '%Y-%m-%d %H:%i') AS t, COALESCE(u.username, 'system') AS who, l.action AS act, COALESCE(l.membership_id, l.payment_id, l.member_id, '') AS ref, COALESCE(l.reason, '') AS why FROM override_void_log l LEFT JOIN users u ON l.performed_by = u.user_id ORDER BY l.performed_at DESC");
            $audit = $st->fetchAll();

            // 8. Notifications
            $st = $pdo->query("SELECT notification_id AS id, member_id AS m, type, payment_id AS pid, membership_id AS oid, message AS msg, DATE_FORMAT(created_at, '%Y-%m-%d %H:%i') AS at, is_read AS `read` FROM notifications ORDER BY created_at DESC");
            $notes = $st->fetchAll();

            // 9. Sequence numbers
            $seqP = (int)$pdo->query("SELECT last_number FROM id_counters WHERE name = 'P'")->fetchColumn();
            $seqO = (int)$pdo->query("SELECT last_number FROM id_counters WHERE name = 'O'")->fetchColumn();
            $seqM = (int)$pdo->query("SELECT last_number FROM id_counters WHERE name = 'M'")->fetchColumn();

            json_out([
                'ok'      => true,
                'plans'   => $plans,
                'members' => $members,
                'orders'  => $orders,
                'pays'    => $pays,
                'att'     => $att,
                'flags'   => $flags,
                'audit'   => $audit,
                'notes'   => $notes,
                'seq'     => ['P' => $seqP, 'O' => $seqO, 'M' => $seqM, 'E' => 0, 'N' => count($notes)]
            ]);
            break;

        case 'add_member':
            $b  = body();
            $f  = trim(isset($b['first']) ? (string)$b['first'] : '');
            $l  = trim(isset($b['last']) ? (string)$b['last'] : '');
            $c  = trim(isset($b['contact']) ? (string)$b['contact'] : '');
            $e  = strtolower(trim(isset($b['email']) ? (string)$b['email'] : ''));
            $bd = trim(isset($b['birthdate']) ? (string)$b['birthdate'] : '');
            $s  = trim(isset($b['street']) ? (string)$b['street'] : '');
            $br = trim(isset($b['barangay']) ? (string)$b['barangay'] : '');
            $ct = trim(isset($b['city']) ? (string)$b['city'] : '');
            $pv = trim(isset($b['province']) ? (string)$b['province'] : '');

            if (!valid_name($f) || !valid_name($l)) fail('Enter first and last name, letters only.');
            if (empty($c)) fail('Contact number is required.');
            if (empty($e) || !filter_var($e, FILTER_VALIDATE_EMAIL)) fail('A valid email address is required.');

            // Check contact and email uniqueness
            $st = $pdo->prepare('SELECT COUNT(*) FROM members WHERE contact = ? OR email = ?');
            $st->execute([$c, $e]);
            if ($st->fetchColumn() > 0) fail('A member with this contact number or email already exists.');

            $newId = next_id('M');
            $today = date('Y-m-d');
            $pwHash = password_hash('password123', PASSWORD_DEFAULT);

            $ins = $pdo->prepare('INSERT INTO members (member_id, first_name, last_name, contact, email, birthdate, street_house_no, barangay, city_municipality, province, joined_on, password, notifications_on, must_change_password) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, 0)');
            $ins->execute([$newId, $f, $l, $c, $e, $bd ?: null, $s ?: '-', $br ?: '-', $ct ?: '-', $pv ?: '-', $today, $pwHash]);

            json_out(['ok' => true, 'id' => $newId]);
            break;

        case 'reset_member_password':
            $b   = body();
            $mid = trim(isset($b['member_id']) ? (string)$b['member_id'] : '');
            if (!$mid) fail('Member ID is required.');

            $pwHash = password_hash('password123', PASSWORD_DEFAULT);
            $st = $pdo->prepare('UPDATE members SET password = ?, must_change_password = 1 WHERE member_id = ?');
            $st->execute([$pwHash, $mid]);

            log_audit('Password reset', ['member_id' => $mid, 'reason' => 'Reset by staff']);
            json_out(['ok' => true]);
            break;

        case 'enroll':
            $b    = body();
            $mid  = trim(isset($b['member_id']) ? (string)$b['member_id'] : '');
            $pn   = trim(isset($b['plan']) ? (string)$b['plan'] : '');
            $pp   = trim(isset($b['paying_plan']) ? (string)$b['paying_plan'] : '');
            $freq = isset($b['freq']) ? (string)$b['freq'] : null;
            $n    = isset($b['n']) ? (int)$b['n'] : null;
            $ev   = isset($b['every']) ? (int)$b['every'] : null;

            if (!$mid || !$pn || !$pp) fail('Missing required membership details.');

            $st = $pdo->prepare("SELECT * FROM membership_plans WHERE name = ? AND status = 'Active'");
            $st->execute([$pn]);
            $plan = $st->fetch();
            if (!$plan) fail('Invalid or inactive plan.');

            if ($pp === 'Installment') {
                if (!$freq || $n < 2 || !$ev) fail('Invalid installment settings.');
            } else {
                $freq = null;
                $n = null;
                $ev = null;
            }

            $newId = next_id('O');
            $today = date('Y-m-d');

            $ins = $pdo->prepare('INSERT INTO memberships (membership_id, member_id, plan_id, paying_plan, pay_every, installment_count, interval_days, total_price, duration_days, status, is_grace, purchased_on, start_date, end_date) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, "Open", 0, ?, NULL, NULL)');
            $ins->execute([$newId, $mid, $plan['plan_id'], $pp, $freq, $n, $ev, $plan['price'], $plan['days'], $today]);

            json_out(['ok' => true, 'id' => $newId]);
            break;

        case 'cancel_membership':
            $b   = body();
            $oid = trim(isset($b['membership_id']) ? (string)$b['membership_id'] : '');
            if (!$oid) fail('Membership ID is required.');

            $st = $pdo->prepare('SELECT * FROM memberships WHERE membership_id = ?');
            $st->execute([$oid]);
            $o = $st->fetch();
            if (!$o) fail('Membership not found.');
            if ($o['start_date']) fail('Cannot cancel an already active membership.');

            $stP = $pdo->prepare("SELECT COUNT(*) FROM payments WHERE membership_id = ? AND status = 'Confirmed' AND is_void = 0");
            $stP->execute([$oid]);
            if ($stP->fetchColumn() > 0) fail('Cannot cancel a membership with confirmed payments.');

            $stUp = $pdo->prepare("UPDATE memberships SET status = 'Cancelled' WHERE membership_id = ?");
            $stUp->execute([$oid]);

            log_audit('Membership cancelled', ['membership_id' => $oid, 'reason' => 'Cancelled before payment']);
            json_out(['ok' => true]);
            break;

        case 'add_payment':
            $b   = body();
            $oid = trim(isset($b['membership_id']) ? (string)$b['membership_id'] : '');
            $amt = (float)(isset($b['amount']) ? $b['amount'] : 0);
            $met = trim(isset($b['method']) ? (string)$b['method'] : 'Cash');
            $ref = isset($b['ref']) && trim($b['ref']) !== '-' && trim($b['ref']) !== '—' ? trim($b['ref']) : null;

            if (!$oid || $amt <= 0) fail('Valid membership ID and amount are required.');
            if (!in_array($met, ['Cash', 'GCash', 'Bank Transfer'], true)) fail('Invalid payment method.');

            $st = $pdo->prepare('SELECT * FROM memberships WHERE membership_id = ?');
            $st->execute([$oid]);
            $o = $st->fetch();
            if (!$o) fail('Membership not found.');

            $newId = next_id('P');
            $today = date('Y-m-d');
            $nowTime = date('H:i:s');

            $ins = $pdo->prepare("INSERT INTO payments (payment_id, membership_id, amount, method, paid_on, paid_time, receipt_ref, status, source, received_by, decided_at, is_void) VALUES (?, ?, ?, ?, ?, ?, ?, 'Confirmed', 'Staff', ?, NOW(), 0)");
            $ins->execute([$newId, $oid, $amt, $met, $today, $nowTime, $ref, $me['id']]);

            check_and_activate_membership($oid);

            log_audit('Payment confirmed', ['membership_id' => $oid, 'payment_id' => $newId, 'member_id' => $o['member_id'], 'reason' => "Recorded by staff ($met)"]);
            json_out(['ok' => true, 'id' => $newId]);
            break;

        case 'decide_payment':
            $b    = body();
            $pid  = trim(isset($b['payment_id']) ? (string)$b['payment_id'] : '');
            $ok   = !empty($b['approved']);
            $why  = trim(isset($b['reason']) ? (string)$b['reason'] : '');

            if (!$pid) fail('Payment ID is required.');

            $st = $pdo->prepare('SELECT p.*, m.member_id, m.plan_id, pl.name AS plan_name FROM payments p JOIN memberships m ON p.membership_id = m.membership_id JOIN membership_plans pl ON m.plan_id = pl.plan_id WHERE p.payment_id = ?');
            $st->execute([$pid]);
            $p = $st->fetch();
            if (!$p) fail('Payment not found.');
            if ($p['status'] !== 'Pending') fail('This payment has already been decided.');

            $status = $ok ? 'Confirmed' : 'Declined';
            $up = $pdo->prepare('UPDATE payments SET status = ?, received_by = ?, decided_at = NOW(), decline_reason = ? WHERE payment_id = ?');
            $up->execute([$status, $me['id'], $ok ? null : $why, $pid]);

            if ($ok) {
                check_and_activate_membership($p['membership_id']);
                $msg = "Staff confirmed your ₱" . number_format($p['amount']) . " {$p['method']} payment ({$pid}) for {$p['plan_name']}.";
                $notifType = 'Payment confirmed';
            } else {
                $msg = "Staff could not confirm your ₱" . number_format($p['amount']) . " {$p['method']} payment ({$pid}) for {$p['plan_name']}." . ($why ? " Reason: $why." : '') . " Check the reference number, then pay again.";
                $notifType = 'Payment declined';
            }

            // Create notification for member
            $insN = $pdo->prepare('INSERT INTO notifications (member_id, type, payment_id, membership_id, message, created_at, is_read) VALUES (?, ?, ?, ?, ?, NOW(), 0)');
            $insN->execute([$p['member_id'], $notifType, $pid, $p['membership_id'], $msg]);

            log_audit($ok ? 'Payment confirmed' : 'Payment declined', [
                'membership_id' => $p['membership_id'],
                'payment_id'    => $pid,
                'member_id'     => $p['member_id'],
                'reason'        => $why ?: 'Checked against ' . $p['method'] . ' record'
            ]);

            json_out(['ok' => true]);
            break;

        case 'void_payment':
            require_admin();
            $b   = body();
            $pid = trim(isset($b['payment_id']) ? (string)$b['payment_id'] : '');
            $why = trim(isset($b['reason']) ? (string)$b['reason'] : '');
            if (!$pid) fail('Payment ID is required.');

            $st = $pdo->prepare('SELECT * FROM payments WHERE payment_id = ?');
            $st->execute([$pid]);
            $p = $st->fetch();
            if (!$p) fail('Payment not found.');
            if ($p['is_void']) fail('Payment is already void.');

            $up = $pdo->prepare('UPDATE payments SET is_void = 1 WHERE payment_id = ?');
            $up->execute([$pid]);

            check_and_deactivate_membership($p['membership_id']);

            log_audit('Payment voided', ['membership_id' => $p['membership_id'], 'payment_id' => $pid, 'reason' => $why ?: 'Voided by admin']);
            json_out(['ok' => true]);
            break;

        case 'override_membership':
            require_admin();
            $b   = body();
            $oid = trim(isset($b['membership_id']) ? (string)$b['membership_id'] : '');
            $type = trim(isset($b['type']) ? (string)$b['type'] : '');
            $why = trim(isset($b['reason']) ? (string)$b['reason'] : '');

            if (!$oid || !in_array($type, ['grace', 'waive'], true)) fail('Invalid override request.');

            $st = $pdo->prepare('SELECT * FROM memberships WHERE membership_id = ?');
            $st->execute([$oid]);
            $o = $st->fetch();
            if (!$o) fail('Membership not found.');

            if ($type === 'waive') {
                // Set total price equal to already confirmed payments
                $stP = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) FROM payments WHERE membership_id = ? AND status = 'Confirmed' AND is_void = 0");
                $stP->execute([$oid]);
                $paid = (float)$stP->fetchColumn();
                $pdo->prepare('UPDATE memberships SET total_price = ? WHERE membership_id = ?')->execute([$paid, $oid]);
            }

            // For grace or waive, activate membership if not active
            $today = date('Y-m-d');
            if (!$o['start_date']) {
                $stLatest = $pdo->prepare("SELECT MAX(end_date) FROM memberships WHERE member_id = ? AND membership_id <> ? AND start_date IS NOT NULL AND status <> 'Cancelled'");
                $stLatest->execute([$o['member_id'], $oid]);
                $latestEnd = $stLatest->fetchColumn();
                $startDate = ($latestEnd && $latestEnd >= $today) ? date('Y-m-d', strtotime("$latestEnd +1 day")) : $today;
                $endDate = date('Y-m-d', strtotime("$startDate +{$o['duration_days']} days"));
                $isGrace = ($type === 'grace') ? 1 : 0;
                $pdo->prepare('UPDATE memberships SET start_date = ?, end_date = ?, is_grace = ? WHERE membership_id = ?')->execute([$startDate, $endDate, $isGrace, $oid]);
            } else if ($type === 'grace') {
                $pdo->prepare('UPDATE memberships SET is_grace = 1 WHERE membership_id = ?')->execute([$oid]);
            }

            log_audit($type === 'grace' ? 'Grace activation' : 'Balance waived', ['membership_id' => $oid, 'reason' => $why ?: 'Admin override']);
            json_out(['ok' => true]);
            break;

        case 'add_plan':
            require_admin();
            $b    = body();
            $name = trim(isset($b['name']) ? (string)$b['name'] : '');
            $days = (int)(isset($b['days']) ? $b['days'] : 30);
            $p    = (float)(isset($b['price']) ? $b['price'] : 1000);

            if (!$name) fail('Plan name is required.');
            if ($days <= 0 || $p <= 0) fail('Days and price must be greater than zero.');

            $ins = $pdo->prepare("INSERT INTO membership_plans (name, days, price, status) VALUES (?, ?, ?, 'Active')");
            $ins->execute([$name, $days, $p]);
            $planId = (int)$pdo->lastInsertId();

            log_audit('Plan edited', ['plan_id' => $planId, 'reason' => "Created plan $name"]);
            json_out(['ok' => true, 'id' => $planId]);
            break;

        case 'save_plan':
            require_admin();
            $b    = body();
            $pid  = (int)(isset($b['id']) ? $b['id'] : 0);
            $name = trim(isset($b['name']) ? (string)$b['name'] : '');
            $days = (int)(isset($b['days']) ? $b['days'] : 0);
            $p    = (float)(isset($b['price']) ? $b['price'] : 0);

            if (!$pid || !$name || $days <= 0 || $p <= 0) fail('Invalid plan data.');

            $up = $pdo->prepare('UPDATE membership_plans SET name = ?, days = ?, price = ? WHERE plan_id = ?');
            $up->execute([$name, $days, $p, $pid]);

            log_audit('Plan edited', ['plan_id' => $pid, 'reason' => "Updated to $name, $days days, ₱$p"]);
            json_out(['ok' => true]);
            break;

        case 'toggle_plan':
            require_admin();
            $b   = body();
            $pid = (int)(isset($b['id']) ? $b['id'] : 0);
            if (!$pid) fail('Plan ID is required.');

            $st = $pdo->prepare('SELECT status FROM membership_plans WHERE plan_id = ?');
            $st->execute([$pid]);
            $cur = $st->fetchColumn();
            if (!$cur) fail('Plan not found.');

            $newSt = ($cur === 'Active') ? 'Archived' : 'Active';
            if ($newSt === 'Archived') {
                $actCount = (int)$pdo->query("SELECT COUNT(*) FROM membership_plans WHERE status = 'Active'")->fetchColumn();
                if ($actCount <= 1) fail('Keep at least one active plan.');
            }

            $pdo->prepare('UPDATE membership_plans SET status = ? WHERE plan_id = ?')->execute([$newSt, $pid]);
            log_audit('Plan edited', ['plan_id' => $pid, 'reason' => "Status changed to $newSt"]);
            json_out(['ok' => true, 'status' => $newSt]);
            break;

        case 'checkin':
            $b   = body();
            $mid = trim(isset($b['member_id']) ? (string)$b['member_id'] : '');
            if (!$mid) fail('Member ID is required.');

            $today = date('Y-m-d');
            $nowTime = date('H:i:s');

            // Determine member membership status
            // Active memberships: status='Open', start_date <= today, end_date >= today
            $st = $pdo->prepare("SELECT * FROM memberships WHERE member_id = ? AND status = 'Open' ORDER BY start_date ASC");
            $st->execute([$mid]);
            $orders = $st->fetchAll();

            $status = 'No plan';
            $activeOrder = null;
            if (!empty($orders)) {
                foreach ($orders as $o) {
                    if ($o['start_date'] && $o['start_date'] <= $today && $o['end_date'] >= $today) {
                        // Check if suspended due to overdue installment
                        if ($o['paying_plan'] === 'Installment') {
                            $stP = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) FROM payments WHERE membership_id = ? AND status = 'Confirmed' AND is_void = 0");
                            $stP->execute([$o['membership_id']]);
                            $c = (float)$stP->fetchColumn();

                            $bAmt = floor($o['total_price'] / $o['installment_count']);
                            $cum = 0;
                            $isOverdue = false;
                            for ($i = 1; $i <= $o['installment_count']; $i++) {
                                $dueAmt = ($i === (int)$o['installment_count']) ? ($o['total_price'] - $bAmt * ($o['installment_count'] - 1)) : $bAmt;
                                $cum += $dueAmt;
                                $dueDate = date('Y-m-d', strtotime("{$o['purchased_on']} +" . ($i * $o['interval_days']) . " days"));
                                if ($c < $cum && $dueDate < $today) {
                                    $isOverdue = true;
                                    break;
                                }
                            }
                            if ($isOverdue) {
                                $status = 'Suspended';
                                $activeOrder = $o;
                                break;
                            }
                        }

                        $d7 = date('Y-m-d', strtotime("$today +7 days"));
                        $status = ($o['end_date'] <= $d7) ? 'Expiring' : 'Active';
                        $activeOrder = $o;
                        break;
                    } elseif ($o['start_date'] && $o['start_date'] > $today) {
                        if ($status === 'No plan') $status = 'Queued';
                    } elseif (!$o['start_date']) {
                        if ($status === 'No plan') $status = 'Pending';
                    } elseif ($o['end_date'] < $today) {
                        if ($status === 'No plan') $status = 'Expired';
                    }
                }
            }

            if ($status !== 'Active' && $status !== 'Expiring') {
                $insB = $pdo->prepare('INSERT INTO blocked_checkins (member_id, member_status, attempted_at) VALUES (?, ?, NOW())');
                $insB->execute([$mid, $status]);
                json_out(['ok' => false, 'blocked' => true, 'status' => $status]);
            }

            // Check if already checked in today without time out
            $stA = $pdo->prepare('SELECT COUNT(*) FROM attendance WHERE member_id = ? AND attendance_date = ? AND time_out IS NULL');
            $stA->execute([$mid, $today]);
            if ($stA->fetchColumn() > 0) fail('Member is already checked in.');

            $insA = $pdo->prepare('INSERT INTO attendance (member_id, attendance_date, time_in, time_out) VALUES (?, ?, ?, NULL)');
            $insA->execute([$mid, $today, $nowTime]);

            json_out(['ok' => true]);
            break;

        case 'checkout':
            $b   = body();
            $mid = trim(isset($b['member_id']) ? (string)$b['member_id'] : '');
            if (!$mid) fail('Member ID is required.');

            $today = date('Y-m-d');
            $nowTime = date('H:i:s');

            $stA = $pdo->prepare('SELECT attendance_id FROM attendance WHERE member_id = ? AND attendance_date = ? AND time_out IS NULL ORDER BY attendance_id DESC LIMIT 1');
            $stA->execute([$mid, $today]);
            $attId = $stA->fetchColumn();
            if (!$attId) fail('Member is not checked in.');

            $upA = $pdo->prepare('UPDATE attendance SET time_out = ? WHERE attendance_id = ?');
            $upA->execute([$nowTime, $attId]);

            json_out(['ok' => true]);
            break;

        default:
            fail('Unknown gym action.', 400);
    }
} catch (Exception $e) {
    if (DEBUG) fail('Server error: ' . $e->getMessage(), 500);
    fail('An unexpected error occurred.', 500);
}
