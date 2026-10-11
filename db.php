<?php
// Shared helpers used by every PHP file in FitTala.
require_once __DIR__ . '/config.php';

// One connection to MySQL, created the first time it is needed.
function db() {
    static $pdo = null;
    if ($pdo === null) {
        $dsn = 'mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';dbname=' . DB_NAME . ';charset=utf8mb4';
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
            // Make MySQL's NOW() agree with PHP's date() (Philippine time).
            PDO::MYSQL_ATTR_INIT_COMMAND => "SET time_zone = '+08:00'",
        ]);
    }
    return $pdo;
}

// Starts the login session. The cookie cannot be read by JavaScript.
function start_session() {
    if (session_status() === PHP_SESSION_NONE) {
        session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'httponly' => true, 'samesite' => 'Lax']);
        session_start();
    }
}

// Sends a JSON answer to the web page and stops.
function json_out($data, $code = 200) {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data);
    exit;
}

function fail($message, $code = 200) {
    json_out(['ok' => false, 'error' => $message], $code);
}

// Reads the JSON the page sent. Only POST requests with JSON are accepted.
function body() {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') fail('POST only.', 405);
    $type = isset($_SERVER['CONTENT_TYPE']) ? $_SERVER['CONTENT_TYPE'] : '';
    if (stripos($type, 'application/json') === false) fail('Bad request.', 400);
    $data = json_decode(file_get_contents('php://input'), true);
    return is_array($data) ? $data : [];
}

function current_user() {
    start_session();
    return isset($_SESSION['user']) ? $_SESSION['user'] : null;
}

function require_login() {
    $u = current_user();
    if (!$u) fail('Please sign in again.', 401);
    return $u;
}

function require_admin() {
    $u = require_login();
    if ($u['r'] !== 'Admin') fail('Only admins can do this.', 403);
    return $u;
}

// Checks a first or last name: letters, spaces, dots, hyphens and apostrophes.
function valid_name($s) {
    return is_string($s) && preg_match("/^[\\p{L}.'\\- ]{2,50}$/u", trim($s)) === 1;
}

// Atomically get the next sequential ID (M-001, O-001, P-001)
function next_id($prefix) {
    $pdo = db();
    $st = $pdo->prepare('UPDATE id_counters SET last_number = last_number + 1 WHERE name = ?');
    $st->execute([$prefix]);
    $st = $pdo->prepare('SELECT last_number FROM id_counters WHERE name = ?');
    $st->execute([$prefix]);
    $n = (int)$st->fetchColumn();
    return $prefix . '-' . str_pad($n, 3, '0', STR_PAD_LEFT);
}

function current_member() {
    start_session();
    return isset($_SESSION['member']) ? $_SESSION['member'] : null;
}

function require_member() {
    $m = current_member();
    if (!$m) fail('Please sign in again.', 401);
    return $m;
}

function log_audit($action, $opts = []) {
    $pdo = db();
    $planId = isset($opts['plan_id']) ? $opts['plan_id'] : null;
    $membershipId = isset($opts['membership_id']) ? $opts['membership_id'] : null;
    $paymentId = isset($opts['payment_id']) ? $opts['payment_id'] : null;
    $memberId = isset($opts['member_id']) ? $opts['member_id'] : null;
    $reason = isset($opts['reason']) ? $opts['reason'] : null;
    $u = current_user();
    $performedBy = $u ? $u['id'] : null;

    $st = $pdo->prepare('INSERT INTO override_void_log (action, plan_id, membership_id, payment_id, member_id, reason, performed_by, performed_at) VALUES (?, ?, ?, ?, ?, ?, ?, NOW())');
    $st->execute([$action, $planId, $membershipId, $paymentId, $memberId, $reason, $performedBy]);
}

function check_and_activate_membership($membershipId) {
    $pdo = db();
    $st = $pdo->prepare('SELECT * FROM memberships WHERE membership_id = ?');
    $st->execute([$membershipId]);
    $ms = $st->fetch();
    if (!$ms || $ms['start_date']) return false;

    // Sum confirmed, non-void payments
    $stP = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) FROM payments WHERE membership_id = ? AND status = 'Confirmed' AND is_void = 0");
    $stP->execute([$membershipId]);
    $paid = (float)$stP->fetchColumn();

    $needed = (float)$ms['total_price'];
    if ($ms['paying_plan'] === 'Installment') {
        $count = (int)$ms['installment_count'];
        if ($count > 0) {
            $needed = (float)floor($ms['total_price'] / $count);
        }
    }

    if ($paid >= $needed) {
        $today = date('Y-m-d');
        // Find latest end date of any other active membership for this member
        $stLatest = $pdo->prepare("SELECT MAX(end_date) FROM memberships WHERE member_id = ? AND membership_id <> ? AND start_date IS NOT NULL AND status <> 'Cancelled'");
        $stLatest->execute([$ms['member_id'], $membershipId]);
        $latestEnd = $stLatest->fetchColumn();

        $startDate = $today;
        if ($latestEnd && $latestEnd >= $today) {
            $d = new DateTime($latestEnd);
            $d->modify('+1 day');
            $startDate = $d->format('Y-m-d');
        }

        $dEnd = new DateTime($startDate);
        $days = (int)$ms['duration_days'];
        $dEnd->modify("+$days days");
        $endDate = $dEnd->format('Y-m-d');

        $stUp = $pdo->prepare("UPDATE memberships SET start_date = ?, end_date = ? WHERE membership_id = ?");
        $stUp->execute([$startDate, $endDate, $membershipId]);
        return true;
    }
    return false;
}

function check_and_deactivate_membership($membershipId) {
    $pdo = db();
    $st = $pdo->prepare('SELECT * FROM memberships WHERE membership_id = ?');
    $st->execute([$membershipId]);
    $ms = $st->fetch();
    if (!$ms || !$ms['start_date'] || $ms['is_grace']) return false;

    $stP = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) FROM payments WHERE membership_id = ? AND status = 'Confirmed' AND is_void = 0");
    $stP->execute([$membershipId]);
    $paid = (float)$stP->fetchColumn();

    $needed = (float)$ms['total_price'];
    if ($ms['paying_plan'] === 'Installment') {
        $count = (int)$ms['installment_count'];
        if ($count > 0) {
            $needed = (float)floor($ms['total_price'] / $count);
        }
    }

    if ($paid < $needed) {
        $stUp = $pdo->prepare("UPDATE memberships SET start_date = NULL, end_date = NULL WHERE membership_id = ?");
        $stUp->execute([$membershipId]);
        return true;
    }
    return false;
}

