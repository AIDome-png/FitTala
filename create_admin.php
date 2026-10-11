<?php
// Creates the first Admin account. Command line only (it cannot be opened in a browser).
//
//   php create_admin.php <username> <password> [FirstName] [LastName]
//
// Example: php create_admin.php admin MyStrongPass123 Gym Owner
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Run this from the command line.\n");
}
require_once __DIR__ . '/db.php';

$un = strtolower(trim(isset($argv[1]) ? $argv[1] : ''));
$pw = isset($argv[2]) ? $argv[2] : '';
$f  = isset($argv[3]) ? $argv[3] : 'Admin';
$l  = isset($argv[4]) ? $argv[4] : 'User';

if (!preg_match('/^[a-z0-9_.]{4,20}$/', $un)) exit("Username: 4 to 20 letters, numbers, dots or underscores.\n");
if (strlen($pw) < 8 || strlen($pw) > 72)       exit("Password must be 8 to 72 characters.\n");
if (!valid_name($f) || !valid_name($l))        exit("First and last name: letters only.\n");

try {
    $st = db()->prepare("INSERT INTO users (first_name, last_name, username, password, role, status) VALUES (?, ?, ?, ?, 'Admin', 'Active')");
    $st->execute([$f, $l, $un, password_hash($pw, PASSWORD_DEFAULT)]);
    echo "Admin account '$un' created. You can now sign in at staff.html.\n";
} catch (PDOException $e) {
    if ($e->getCode() === '23000') exit("That username already exists.\n");
    echo "Error: " . $e->getMessage() . "\n";
    exit(1);
}
