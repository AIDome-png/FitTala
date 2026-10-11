<?php
// Member Portal Backend API
require_once __DIR__ . '/../db.php';

try {
    $action = isset($_GET['action']) ? $_GET['action'] : '';
    $pdo = db();

    switch ($action) {

        // Who is signed in to the member portal right now?
        case 'me':
            body();
            $m = current_member();
            json_out($m ? ['ok' => true, 'member' => $m] : ['ok' => false]);
            break;

        case 'login':
            $b  = body();
            $un = strtolower(trim(isset($b['username']) ? (string)$b['username'] : ''));
            $cleanUn = str_replace([' ', '-', '(', ')'], '', $un);
            $pw = isset($b['password']) ? (string)$b['password'] : '';

            if (empty($un) || empty($pw)) fail('Enter your member ID or contact number and password.');

            $st = $pdo->prepare('SELECT * FROM members WHERE LOWER(member_id) = ? OR REPLACE(contact, " ", "") = ? OR REPLACE(contact, "-", "") = ?');
            $st->execute([$un, $cleanUn, $cleanUn]);
            $row = $st->fetch();

            if (!$row) fail('Wrong member ID, contact number or password.');

            // Verify password or check default password if empty
            $hash = $row['password'];
            if (!$hash) {
                if ($pw !== 'password123') fail('Wrong member ID, contact number or password.');
                // Upgrade/initialize password
                $newHash = password_hash($pw, PASSWORD_DEFAULT);
                $pdo->prepare('UPDATE members SET password = ? WHERE member_id = ?')->execute([$newHash, $row['member_id']]);
            } else {
                if (!password_verify($pw, $hash)) fail('Wrong member ID, contact number or password.');
            }

            start_session();
            session_regenerate_id(true);

            $memberSession = [
                'id'  => $row['member_id'],
                'n'   => $row['first_name'] . ' ' . $row['last_name'],
                'f'   => $row['first_name'],
                'l'   => $row['last_name'],
                'e'   => $row['email'],
                'c'   => $row['contact'],
                'b'   => $row['birthdate'] ?: '',
                'j'   => $row['joined_on'],
                'mcp' => (int)$row['must_change_password'],
                'nt'  => (int)$row['notifications_on'],
                'a'   => [
                    's' => $row['street_house_no'] ?: '-',
                    'b' => $row['barangay'] ?: '-',
                    'c' => $row['city_municipality'] ?: '-',
                    'p' => $row['province'] ?: '-'
                ]
            ];
            $_SESSION['member'] = $memberSession;

            json_out(['ok' => true, 'member' => $memberSession]);
            break;

        case 'logout':
            body();
            start_session();
            unset($_SESSION['member']);
            json_out(['ok' => true]);
            break;

        case 'get_data':
            $m = require_member();
            body();

            // Refresh member info from DB
            $stM = $pdo->prepare('SELECT * FROM members WHERE member_id = ?');
            $stM->execute([$m['id']]);
            $row = $stM->fetch();
            if ($row) {
                $m['f']   = $row['first_name'];
                $m['l']   = $row['last_name'];
                $m['n']   = $row['first_name'] . ' ' . $row['last_name'];
                $m['e']   = $row['email'];
                $m['c']   = $row['contact'];
                $m['b']   = $row['birthdate'] ?: '';
                $m['mcp'] = (int)$row['must_change_password'];
                $m['nt']  = (int)$row['notifications_on'];
                $m['a']   = [
                    's' => $row['street_house_no'] ?: '-',
                    'b' => $row['barangay'] ?: '-',
                    'c' => $row['city_municipality'] ?: '-',
                    'p' => $row['province'] ?: '-'
                ];
                $_SESSION['member'] = $m;
            }

            // 1. Available active plans
            $stP = $pdo->query("SELECT plan_id AS id, name AS n, days, CAST(price AS UNSIGNED) AS p, 1 AS a FROM membership_plans WHERE status = 'Active' ORDER BY plan_id");
            $plans = $stP->fetchAll();

            // 2. Member's orders
            $stO = $pdo->prepare("SELECT m.membership_id AS id, m.member_id AS m, p.name AS plan, m.paying_plan AS pp, m.pay_every AS freq, m.installment_count AS n, m.interval_days AS every, CAST(m.total_price AS UNSIGNED) AS price, m.duration_days AS days, m.purchased_on AS buy, m.start_date AS start, m.end_date AS end, IF(m.status = 'Cancelled', 1, 0) AS cx, m.is_grace AS grace FROM memberships m JOIN membership_plans p ON m.plan_id = p.plan_id WHERE m.member_id = ? ORDER BY m.purchased_on DESC, m.membership_id DESC");
            $stO->execute([$m['id']]);
            $orders = $stO->fetchAll();

            // 3. Member's payments
            $stPay = $pdo->prepare("SELECT py.payment_id AS id, py.membership_id AS o, ms.member_id AS m, CAST(py.amount AS UNSIGNED) AS a, py.paid_on AS dt, TIME_FORMAT(py.paid_time, '%H:%i') AS tm, py.method AS me, COALESCE(u.username, '') AS `by`, py.is_void AS v, py.status AS st, COALESCE(py.receipt_ref, '—') AS ref, LOWER(py.source) AS src, py.decline_reason AS why FROM payments py JOIN memberships ms ON py.membership_id = ms.membership_id LEFT JOIN users u ON py.received_by = u.user_id WHERE ms.member_id = ? ORDER BY py.paid_on DESC, py.paid_time DESC, py.payment_id DESC");
            $stPay->execute([$m['id']]);
            $pays = $stPay->fetchAll();

            // 4. Member's attendance history
            $stAtt = $pdo->prepare("SELECT member_id AS m, attendance_date AS d, TIME_FORMAT(time_in, '%H:%i') AS i, IFNULL(TIME_FORMAT(time_out, '%H:%i'), '') AS o FROM attendance WHERE member_id = ? ORDER BY attendance_date DESC, time_in DESC LIMIT 50");
            $stAtt->execute([$m['id']]);
            $att = $stAtt->fetchAll();

            // 5. Member's notifications
            $stN = $pdo->prepare("SELECT notification_id AS id, member_id AS m, type, payment_id AS pid, membership_id AS oid, message AS msg, DATE_FORMAT(created_at, '%Y-%m-%d %H:%i') AS at, is_read AS `read` FROM notifications WHERE member_id = ? ORDER BY created_at DESC");
            $stN->execute([$m['id']]);
            $notes = $stN->fetchAll();

            json_out([
                'ok'     => true,
                'me'     => $m,
                'plans'  => $plans,
                'orders' => $orders,
                'pays'   => $pays,
                'att'    => $att,
                'notes'  => $notes
            ]);
            break;

        case 'buy_plan':
            $m   = require_member();
            $b   = body();
            $pn  = trim(isset($b['plan']) ? (string)$b['plan'] : '');
            $pp  = trim(isset($b['paying_plan']) ? (string)$b['paying_plan'] : '');
            $freq = isset($b['freq']) ? (string)$b['freq'] : null;
            $n    = isset($b['n']) ? (int)$b['n'] : null;
            $ev   = isset($b['every']) ? (int)$b['every'] : null;
            $met  = trim(isset($b['method']) ? (string)$b['method'] : '');
            $ref  = trim(isset($b['ref']) ? (string)$b['ref'] : '');
            $amt  = (float)(isset($b['amount']) ? $b['amount'] : 0);

            if (!$pn || !$pp || !$met || !$ref || $amt <= 0) fail('Please fill in all payment and plan details.');
            if (!in_array($met, ['GCash', 'Bank Transfer'], true)) fail('Online payment must be via GCash or Bank Transfer.');

            $st = $pdo->prepare("SELECT * FROM membership_plans WHERE name = ? AND status = 'Active'");
            $st->execute([$pn]);
            $plan = $st->fetch();
            if (!$plan) fail('Plan not found.');

            if ($pp === 'Installment') {
                if (!$freq || $n < 2 || !$ev) fail('Invalid installment selection.');
            } else {
                $freq = null;
                $n = null;
                $ev = null;
            }

            $orderId = next_id('O');
            $payId = next_id('P');
            $today = date('Y-m-d');
            $nowTime = date('H:i:s');

            $insO = $pdo->prepare('INSERT INTO memberships (membership_id, member_id, plan_id, paying_plan, pay_every, installment_count, interval_days, total_price, duration_days, status, is_grace, purchased_on, start_date, end_date) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, "Open", 0, ?, NULL, NULL)');
            $insO->execute([$orderId, $m['id'], $plan['plan_id'], $pp, $freq, $n, $ev, $plan['price'], $plan['days'], $today]);

            $insP = $pdo->prepare("INSERT INTO payments (payment_id, membership_id, amount, method, paid_on, paid_time, receipt_ref, status, source, received_by, decided_at, is_void) VALUES (?, ?, ?, ?, ?, ?, ?, 'Pending', 'Member', NULL, NULL, 0)");
            $insP->execute([$payId, $orderId, $amt, $met, $today, $nowTime, $ref]);

            json_out([
                'ok'       => true,
                'order_id' => $orderId,
                'pay_id'   => $payId
            ]);
            break;

        case 'submit_payment':
            $m   = require_member();
            $b   = body();
            $oid = trim(isset($b['membership_id']) ? (string)$b['membership_id'] : '');
            $met = trim(isset($b['method']) ? (string)$b['method'] : '');
            $ref = trim(isset($b['ref']) ? (string)$b['ref'] : '');
            $amt = (float)(isset($b['amount']) ? $b['amount'] : 0);

            if (!$oid || !$met || !$ref || $amt <= 0) fail('Please fill in all payment details.');
            if (!in_array($met, ['GCash', 'Bank Transfer'], true)) fail('Online payment must be via GCash or Bank Transfer.');

            // Verify membership belongs to this member
            $st = $pdo->prepare('SELECT * FROM memberships WHERE membership_id = ? AND member_id = ?');
            $st->execute([$oid, $m['id']]);
            $o = $st->fetch();
            if (!$o) fail('Membership not found.');

            $payId = next_id('P');
            $today = date('Y-m-d');
            $nowTime = date('H:i:s');

            $insP = $pdo->prepare("INSERT INTO payments (payment_id, membership_id, amount, method, paid_on, paid_time, receipt_ref, status, source, received_by, decided_at, is_void) VALUES (?, ?, ?, ?, ?, ?, ?, 'Pending', 'Member', NULL, NULL, 0)");
            $insP->execute([$payId, $oid, $amt, $met, $today, $nowTime, $ref]);

            json_out(['ok' => true, 'pay_id' => $payId]);
            break;

        case 'change_password':
            $m   = require_member();
            $b   = body();
            $cur = isset($b['current']) ? (string)$b['current'] : '';
            $new = isset($b['new']) ? (string)$b['new'] : '';

            $st = $pdo->prepare('SELECT password FROM members WHERE member_id = ?');
            $st->execute([$m['id']]);
            $hash = $st->fetchColumn();

            if ($hash && !password_verify($cur, $hash)) fail('Current password is not correct.');
            if (strlen($new) < 8) fail('New password must have at least 8 characters.');
            if (strlen($new) > 72) fail('New password must be 72 characters or fewer.');
            if ($new === $cur) fail('Choose a password different from the current one.');

            $newHash = password_hash($new, PASSWORD_DEFAULT);
            $pdo->prepare('UPDATE members SET password = ?, must_change_password = 0 WHERE member_id = ?')->execute([$newHash, $m['id']]);

            json_out(['ok' => true]);
            break;

        case 'update_notifications':
            $m  = require_member();
            $b  = body();
            $on = !empty($b['notifications_on']) ? 1 : 0;

            $pdo->prepare('UPDATE members SET notifications_on = ? WHERE member_id = ?')->execute([$on, $m['id']]);
            $_SESSION['member']['nt'] = $on;

            json_out(['ok' => true]);
            break;

        case 'mark_notifications_read':
            $m = require_member();
            body();

            $pdo->prepare('UPDATE notifications SET is_read = 1 WHERE member_id = ?')->execute([$m['id']]);
            json_out(['ok' => true]);
            break;

        default:
            fail('Unknown member action.', 400);
    }
} catch (Exception $e) {
    if (DEBUG) fail('Server error: ' . $e->getMessage(), 500);
    fail('An unexpected error occurred.', 500);
}
