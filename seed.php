<?php
// FitTala Database Seeder
require_once __DIR__ . '/db.php';

// seed.php wipes all member, membership and payment data, so only a signed-in
// admin (or the command line) may run it.
if (PHP_SAPI !== 'cli') {
    header('Content-Type: text/plain; charset=utf-8');
    $u = current_user();
    if (!$u || $u['r'] !== 'Admin') {
        http_response_code(403);
        exit("Only a signed-in admin can reseed the database.\n");
    }
}

function d($dateStr, $days) {
    $dt = new DateTime($dateStr);
    if ($days >= 0) {
        $dt->modify("+$days days");
    } else {
        $dt->modify("$days days");
    }
    return $dt->format('Y-m-d');
}

try {
    $pdo = new PDO('mysql:host='.DB_HOST.';port='.DB_PORT.';dbname='.DB_NAME.';charset=utf8mb4', DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]);

    echo "Seeding FitTala database...\n";

    // 1. Ensure Staff user exists
    $pwHash = password_hash('password123', PASSWORD_DEFAULT);
    $st = $pdo->prepare("SELECT user_id FROM users WHERE username = 'staff1'");
    $st->execute();
    if (!$st->fetch()) {
        $pdo->prepare("INSERT INTO users (first_name, last_name, username, password, role, status) VALUES ('Staff', 'Member', 'staff1', ?, 'Staff', 'Active')")->execute([$pwHash]);
        echo "Created sample staff account (staff1 / password123)\n";
    }

    $adminId = (int)$pdo->query("SELECT user_id FROM users WHERE role = 'Admin' LIMIT 1")->fetchColumn();
    $staffId = (int)$pdo->query("SELECT user_id FROM users WHERE username = 'staff1' LIMIT 1")->fetchColumn();
    if (!$staffId) $staffId = $adminId;

    // Fetch plan IDs
    $plansByName = [];
    foreach ($pdo->query("SELECT plan_id, name FROM membership_plans")->fetchAll() as $row) {
        $plansByName[$row['name']] = (int)$row['plan_id'];
    }

    // 2. Clear transactional tables in proper FK order
    $pdo->exec("SET FOREIGN_KEY_CHECKS = 0");
    $pdo->exec("TRUNCATE TABLE override_void_log");
    $pdo->exec("TRUNCATE TABLE notifications");
    $pdo->exec("TRUNCATE TABLE blocked_checkins");
    $pdo->exec("TRUNCATE TABLE attendance");
    $pdo->exec("TRUNCATE TABLE payments");
    $pdo->exec("TRUNCATE TABLE memberships");
    $pdo->exec("TRUNCATE TABLE members");
    $pdo->exec("SET FOREIGN_KEY_CHECKS = 1");

    // Today's date reference
    $today = date('Y-m-d');

    // 3. Insert Members (M-001 to M-007)
    $memberData = [
        ['M-001', 'Juan', 'Dela Cruz', '0917 555 0101', 'juan.delacruz@email.com', '1995-05-15', '12 Rizal St.', 'Poblacion', 'Majayjay', 'Laguna', d($today, -200)],
        ['M-002', 'Maria', 'Santos', '0918 555 0102', 'maria.santos@email.com', '1998-09-22', 'Purok 3', 'Bukal', 'Majayjay', 'Laguna', d($today, -150)],
        ['M-003', 'Paolo', 'Reyes', '0919 555 0103', 'paolo.reyes@email.com', '1992-12-03', '45 Mabini St.', 'Taytay', 'Majayjay', 'Laguna', d($today, -90)],
        ['M-004', 'Andrea', 'Lim', '0920 555 0104', 'andrea.lim@email.com', '2000-03-18', 'Lot 7 Sampaguita', 'Oobi', 'Majayjay', 'Laguna', d($today, -10)],
        ['M-005', 'Carlo', 'Mendoza', '0921 555 0105', 'carlo.mendoza@email.com', '1989-07-29', '8 Del Pilar St.', 'Banti', 'Majayjay', 'Laguna', d($today, -60)],
        ['M-006', 'Bea', 'Villanueva', '0922 555 0106', 'bea.villanueva@email.com', '1996-11-11', 'Purok 1', 'Poblacion', 'Majayjay', 'Laguna', d($today, -87)],
        ['M-007', 'Nico', 'Garcia', '0923 555 0107', 'nico.garcia@email.com', '1994-01-25', '22 Luna St.', 'Bukal', 'Majayjay', 'Laguna', d($today, -24)],
    ];

    $insMem = $pdo->prepare("INSERT INTO members (member_id, first_name, last_name, contact, email, birthdate, street_house_no, barangay, city_municipality, province, joined_on, password, notifications_on, must_change_password) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, 0)");
    foreach ($memberData as $m) {
        $insMem->execute([$m[0], $m[1], $m[2], $m[3], $m[4], $m[5], $m[6], $m[7], $m[8], $m[9], $m[10], $pwHash]);
    }
    echo "Inserted 7 members (default password: password123)\n";

    // 4. Insert Memberships (O-001 to O-008)
    // O-001: M-001, Monthly, Full, price 800, buy -5d, start -5d, end +25d (Active)
    // O-002: M-002, Quarterly, Installment 3 monthly every 30, price 2100, buy -20d, start -20d, end +70d (Active)
    // O-003: M-003, Monthly, Full, price 800, buy -26d, start -26d, end +4d (Expiring)
    // O-004: M-003, Monthly, Full, price 800, buy -1d, start +5d, end +35d (Queued)
    // O-005: M-004, Annual, Full, price 7500, buy -10d, start -10d, end +355d (Active)
    // O-006: M-005, Monthly, Full, price 800, buy -48d, start -48d, end -18d (Expired)
    // O-007: M-006, Quarterly, Full, price 2100, buy -87d, start -87d, end +3d (Expiring)
    // O-008: M-007, Quarterly, Installment 12 weekly every 7, price 2100, buy -24d, start -24d, end +66d (Suspended)
    $membershipData = [
        ['O-001', 'M-001', $plansByName['Monthly'],   'Full',        null,      null, null,  800.00,  30, 'Open', 0, d($today, -5),  d($today, -5),  d($today, 25)],
        ['O-002', 'M-002', $plansByName['Quarterly'], 'Installment', 'Monthly', 3,    30,   2100.00,  90, 'Open', 0, d($today, -20), d($today, -20), d($today, 70)],
        ['O-003', 'M-003', $plansByName['Monthly'],   'Full',        null,      null, null,  800.00,  30, 'Open', 0, d($today, -26), d($today, -26), d($today, 4)],
        ['O-004', 'M-003', $plansByName['Monthly'],   'Full',        null,      null, null,  800.00,  30, 'Open', 0, d($today, -1),  d($today, 5),   d($today, 35)],
        ['O-005', 'M-004', $plansByName['Annual'],    'Full',        null,      null, null, 7500.00, 365, 'Open', 0, d($today, -10), d($today, -10), d($today, 355)],
        ['O-006', 'M-005', $plansByName['Monthly'],   'Full',        null,      null, null,  800.00,  30, 'Open', 0, d($today, -48), d($today, -48), d($today, -18)],
        ['O-007', 'M-006', $plansByName['Quarterly'], 'Full',        null,      null, null, 2100.00,  90, 'Open', 0, d($today, -87), d($today, -87), d($today, 3)],
        ['O-008', 'M-007', $plansByName['Quarterly'], 'Installment', 'Weekly',  12,   7,    2100.00,  90, 'Open', 0, d($today, -24), d($today, -24), d($today, 66)],
    ];

    $insMs = $pdo->prepare("INSERT INTO memberships (membership_id, member_id, plan_id, paying_plan, pay_every, installment_count, interval_days, total_price, duration_days, status, is_grace, purchased_on, start_date, end_date) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    foreach ($membershipData as $ms) {
        $insMs->execute($ms);
    }
    echo "Inserted 8 memberships\n";

    // 5. Insert Payments
    // Confirmed payments:
    // P-099: O-007, 2100, Cash, buy -87d
    // P-101: O-001, 800, GCash GC4807, buy -5d
    // P-102: O-005, 7500, Cash, buy -10d
    // P-103: O-002, 700, Bank BK2210, buy -20d (Installment 1 of 3)
    // P-104: O-004, 800, Cash, buy -1d
    // P-105: O-008, 175, GCash GC4905, buy -24d (Installment 1 of 12)
    // P-106: O-008, 175, GCash GC4919, buy -17d (Installment 2 of 12; installment 3 was due on -10d -> overdue!)
    // P-108: O-003, 800, Cash, buy -26d
    // P-109: O-006, 800, Cash, buy -48d
    // PLUS 1 Pending Payment from Member for O-002 installment 2:
    // P-110: O-002, 700, GCash GC8821, today (Pending member payment waiting for review!)
    $paymentData = [
        ['P-099', 'O-007', 2100.00, 'Cash',          d($today, -87), '09:00:00', null,     'Confirmed', 'Staff',  $staffId, d($today, -87).' 09:00:00'],
        ['P-101', 'O-001',  800.00, 'GCash',         d($today, -5),  '09:00:00', 'GC4807', 'Confirmed', 'Staff',  $staffId, d($today, -5).' 09:00:00'],
        ['P-102', 'O-005', 7500.00, 'Cash',          d($today, -10), '09:00:00', null,     'Confirmed', 'Staff',  $staffId, d($today, -10).' 09:00:00'],
        ['P-103', 'O-002',  700.00, 'Bank Transfer', d($today, -20), '09:00:00', 'BK2210', 'Confirmed', 'Staff',  $adminId, d($today, -20).' 09:00:00'],
        ['P-104', 'O-004',  800.00, 'Cash',          d($today, -1),  '09:00:00', null,     'Confirmed', 'Staff',  $staffId, d($today, -1).' 09:00:00'],
        ['P-105', 'O-008',  175.00, 'GCash',         d($today, -24), '09:00:00', 'GC4905', 'Confirmed', 'Staff',  $staffId, d($today, -24).' 09:00:00'],
        ['P-106', 'O-008',  175.00, 'GCash',         d($today, -17), '09:00:00', 'GC4919', 'Confirmed', 'Staff',  $staffId, d($today, -17).' 09:00:00'],
        ['P-108', 'O-003',  800.00, 'Cash',          d($today, -26), '09:00:00', null,     'Confirmed', 'Staff',  $staffId, d($today, -26).' 09:00:00'],
        ['P-109', 'O-006',  800.00, 'Cash',          d($today, -48), '09:00:00', null,     'Confirmed', 'Staff',  $staffId, d($today, -48).' 09:00:00'],
        ['P-110', 'O-002',  700.00, 'GCash',         $today,         '10:30:00', 'GC8821', 'Pending',   'Member', null,     null],
    ];

    $insPay = $pdo->prepare("INSERT INTO payments (payment_id, membership_id, amount, method, paid_on, paid_time, receipt_ref, status, source, received_by, decided_at, is_void) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0)");
    foreach ($paymentData as $p) {
        $insPay->execute($p);
    }
    echo "Inserted 10 payments (9 confirmed + 1 pending online request)\n";

    // 6. Insert Attendance logs
    $attData = [
        ['M-001', $today,         '06:15:00', '07:40:00'],
        ['M-003', $today,         '08:05:00', null],
        ['M-004', $today,         '09:30:00', null],
        ['M-002', d($today, -1),  '07:00:00', '08:10:00'],
        ['M-001', d($today, -1),  '06:20:00', '07:30:00'],
        ['M-004', d($today, -2),  '18:00:00', '19:15:00'],
    ];

    $insAtt = $pdo->prepare("INSERT INTO attendance (member_id, attendance_date, time_in, time_out) VALUES (?, ?, ?, ?)");
    foreach ($attData as $a) {
        $insAtt->execute($a);
    }
    echo "Inserted 6 attendance logs\n";

    // 7. Insert Blocked check-in
    $pdo->prepare("INSERT INTO blocked_checkins (member_id, member_status, attempted_at) VALUES ('M-005', 'Expired', ?)")->execute([d($today, -1).' 14:22:00']);
    echo "Inserted 1 blocked checkin\n";

    // 8. Insert Notifications
    $insNotif = $pdo->prepare("INSERT INTO notifications (member_id, type, payment_id, membership_id, message, created_at, is_read) VALUES (?, ?, ?, ?, ?, ?, ?)");
    $insNotif->execute(['M-001', 'Payment confirmed', 'P-101', 'O-001', 'Staff confirmed your ₱800 GCash payment (P-101) for Monthly.', d($today, -5).' 09:00:00', 1]);
    $insNotif->execute(['M-003', 'Plan ending soon', null, 'O-003', 'Your Monthly plan ends in 4 days. Renew from Plans. The new plan starts right after this one.', d($today, -3).' 08:00:00', 0]);
    $insNotif->execute(['M-004', 'Payment confirmed', 'P-102', 'O-005', 'Staff confirmed your ₱7,500 Cash payment (P-102) for Annual.', d($today, -10).' 09:00:00', 1]);
    echo "Inserted sample member notifications\n";

    // 9. Insert Override / Void audit log entries
    $insLog = $pdo->prepare("INSERT INTO override_void_log (action, plan_id, membership_id, payment_id, member_id, reason, performed_by, performed_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
    $insLog->execute(['Payment confirmed', null, 'O-001', 'P-101', 'M-001', 'Checked against the GCash record', $staffId, d($today, -5).' 09:00:00']);
    $insLog->execute(['Payment confirmed', null, 'O-005', 'P-102', 'M-004', 'Over the counter cash payment', $staffId, d($today, -10).' 09:00:00']);
    echo "Inserted sample audit logs\n";

    // 10. Update ID counters
    $pdo->prepare("UPDATE id_counters SET last_number = 7 WHERE name = 'M'")->execute();
    $pdo->prepare("UPDATE id_counters SET last_number = 8 WHERE name = 'O'")->execute();
    $pdo->prepare("UPDATE id_counters SET last_number = 110 WHERE name = 'P'")->execute();
    echo "Updated sequence counters: M=7, O=8, P=110\n";

    echo "DATABASE SEEDING COMPLETE!\n";
} catch (Exception $e) {
    echo "SEEDING ERROR: " . $e->getMessage() . "\n";
    exit(1);
}
