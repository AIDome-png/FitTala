<?php
// Staff and admin accounts: sign in, sign up, sign out, change password, approvals.
require_once __DIR__ . '/../db.php';

try {
    $action = isset($_GET['action']) ? $_GET['action'] : '';

    switch ($action) {

        // Who is signed in right now?
        case 'me':
            body();
            $u = current_user();
            json_out($u ? ['ok' => true, 'user' => $u] : ['ok' => false]);
            break;

        case 'login':
            $b  = body();
            $un = strtolower(trim(isset($b['username']) ? (string)$b['username'] : ''));
            $pw = isset($b['password']) ? (string)$b['password'] : '';
            $st = db()->prepare('SELECT user_id, first_name, last_name, username, password, role, status FROM users WHERE username = ?');
            $st->execute([$un]);
            $row = $st->fetch();
            if (!$row || !password_verify($pw, $row['password'])) fail('Wrong username or password.');
            if ($row['status'] === 'Pending')  fail('Your account is waiting for admin approval.');
            if ($row['status'] === 'Disabled') fail('This account is disabled. Ask an admin.');
            start_session();
            session_regenerate_id(true);
            $_SESSION['user'] = [
                'id' => (int)$row['user_id'], 'u' => $row['username'],
                'f'  => $row['first_name'],   'l' => $row['last_name'], 'r' => $row['role'],
            ];
            json_out(['ok' => true, 'user' => $_SESSION['user']]);
            break;

        case 'signup':
            $b  = body();
            $f  = trim(isset($b['first']) ? (string)$b['first'] : '');
            $l  = trim(isset($b['last']) ? (string)$b['last'] : '');
            $un = strtolower(trim(isset($b['username']) ? (string)$b['username'] : ''));
            $pw = isset($b['password']) ? (string)$b['password'] : '';
            if (!valid_name($f) || !valid_name($l)) fail('Enter your first and last name, letters only.');
            if (!preg_match('/^[a-z0-9_.]{4,20}$/', $un)) fail('Username: 4 to 20 letters, numbers, dots or underscores.');
            if (strlen($pw) < 8)  fail('Password must be at least 8 characters.');
            if (strlen($pw) > 72) fail('Password must be 72 characters or fewer.');
            try {
                $st = db()->prepare("INSERT INTO users (first_name, last_name, username, password, role, status) VALUES (?, ?, ?, ?, 'Staff', 'Pending')");
                $st->execute([$f, $l, $un, password_hash($pw, PASSWORD_DEFAULT)]);
            } catch (PDOException $e) {
                if ($e->getCode() === '23000') fail('That username is already taken.');
                throw $e;
            }
            json_out(['ok' => true]);
            break;

        case 'logout':
            body();
            start_session();
            $_SESSION = [];
            session_destroy();
            json_out(['ok' => true]);
            break;

        case 'change_password':
            $me  = require_login();
            $b   = body();
            $cur = isset($b['current']) ? (string)$b['current'] : '';
            $new = isset($b['new']) ? (string)$b['new'] : '';
            $st  = db()->prepare('SELECT password FROM users WHERE user_id = ?');
            $st->execute([$me['id']]);
            $hash = $st->fetchColumn();
            if (!$hash || !password_verify($cur, $hash)) fail('Your current password is not right.');
            if (strlen($new) < 8)  fail('The new password needs at least 8 characters.');
            if (strlen($new) > 72) fail('The new password must be 72 characters or fewer.');
            if ($new === $cur)     fail('Choose a password different from the current one.');
            $st = db()->prepare('UPDATE users SET password = ? WHERE user_id = ?');
            $st->execute([password_hash($new, PASSWORD_DEFAULT), $me['id']]);
            json_out(['ok' => true]);
            break;

        // Admin only: all other accounts, pending ones first.
        case 'list_users':
            $me = require_admin();
            body();
            $st = db()->prepare("SELECT username AS u, first_name AS f, last_name AS l, role AS r, status AS st FROM users WHERE user_id <> ? ORDER BY (status = 'Pending') DESC, first_name, last_name");
            $st->execute([$me['id']]);
            json_out(['ok' => true, 'users' => $st->fetchAll()]);
            break;

        // Admin only: approve, reject, disable or re-enable an account.
        case 'set_status':
            $me     = require_admin();
            $b      = body();
            $un     = strtolower(trim(isset($b['username']) ? (string)$b['username'] : ''));
            $status = isset($b['status']) ? (string)$b['status'] : '';
            if (!in_array($status, ['Active', 'Disabled'], true)) fail('Unknown status.');
            if ($un === $me['u']) fail('You cannot change your own account.');
            $st = db()->prepare('SELECT user_id, status FROM users WHERE username = ?');
            $st->execute([$un]);
            $row = $st->fetch();
            if (!$row) fail('Account not found.');
            db()->beginTransaction();
            $st = db()->prepare('UPDATE users SET status = ? WHERE user_id = ?');
            $st->execute([$status, $row['user_id']]);
            $st = db()->prepare("INSERT INTO override_void_log (action, reason, performed_by) VALUES ('Account status changed', ?, ?)");
            $st->execute([$un . ': ' . $row['status'] . ' to ' . $status, $me['id']]);
            db()->commit();
            json_out(['ok' => true]);
            break;

        default:
            fail('Unknown action.', 400);
    }
} catch (Exception $e) {
    if (db_in_transaction()) db()->rollBack();
    error_log('FitTala auth error: ' . $e->getMessage());
    fail(DEBUG ? 'Server error: ' . $e->getMessage() : 'Something went wrong. Please try again.', 500);
}

function db_in_transaction() {
    try { return db()->inTransaction(); } catch (Exception $e) { return false; }
}
