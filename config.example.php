<?php
// FitTala settings. Copy this file to config.php and edit the values to match your MySQL server.
// config.php holds your database password and is ignored by git. Never commit it.

const DB_HOST = '127.0.0.1';
const DB_PORT = '3306';
const DB_NAME = 'fittala_app';
const DB_USER = 'root';
const DB_PASS = 'your_mysql_password';

// While you are building the site, DEBUG = true shows the real error message on screen.
// Set it to false when the site is used for real.
const DEBUG = true;

// Dates and times in the site (check-ins, payment dates, expiry) use Philippine time.
date_default_timezone_set('Asia/Manila');
