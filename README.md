# FitTala

**A Centralized Fitness Membership and Records Management System**

FitTala brings a gym's member profiles, memberships, payments and attendance into one database, so staff no longer depend on separate logbooks and spreadsheets. *"Fit"* is for people who work on their bodies; *"Tala"* is the Filipino word for a record.

> Finals Group Research Project, CCS110 – Information Management 1
> College of Computing Studies, University of Cabuyao (Pamantasan ng Cabuyao)

---

## Features

**Staff site (`staff.html`) for Admin and Staff**

| Area | What it does |
|---|---|
| Sign in / sign up | Username and password. New staff sign-ups stay *Pending* until an admin approves them. |
| Members | Register, edit and search members under unique IDs (M-001, M-002, …); reset a member's password to a temporary one. |
| Memberships & Plans | Enroll or renew a member; the end date is computed from the plan. Pay in full or in weekly/monthly installments. Admins add, edit, archive and restore plans. |
| Payments | Record Cash, GCash or Bank Transfer payments, verify online payments (accept or decline with a reason), and review the ledger. Admins can void payments, grant grace activation and waive balances. |
| Attendance | Check in / check out with a live Philippine-time clock. Check-in is blocked for expired, suspended or already-checked-in members. |
| Search & Filter | Find records by name, ID, email or contact; filter by status, plan and date range. |
| Dashboard | Income, unpaid balances, member status counts, plan distribution and monthly attendance. |
| Audit log | Voids, grace activations, waivers, password resets, plan edits and account approvals are logged with who and when. |

**Member site (`member.html`), designed for phones**

View membership status, days left, balance and installment schedule; browse and buy plans; submit GCash or Bank Transfer payments with a reference number (these stay *Pending* until staff verify them); see payment history and receipts; receive notifications; change password and switch light/dark mode.

Membership statuses (Active, Expiring, Queued, Pending, Suspended, Expired, Cancelled) are worked out from dates and payments rather than stored.

## Tech stack

- **Front end:** plain HTML, CSS and JavaScript (no build step)
- **Back end:** PHP (PDO, prepared statements, session-based login)
- **Database:** MySQL / MariaDB (10 tables, InnoDB, foreign keys)

## Project structure

```
fittala/
├── staff.html              Staff / admin site
├── member.html             Member site (phone layout)
├── api/
│   ├── auth.php            Staff sign in, sign up, approvals
│   ├── gym.php             Members, plans, memberships, payments, attendance, reports
│   └── member.php          Member site API
├── db.php                  Database connection and shared helpers
├── config.example.php      Settings template (copy to config.php)
├── create_admin.php        Command-line tool to create the first admin
├── seed.php                Loads sample data (admin or command line only)
├── database/
│   └── fittala_schema.sql  CREATE DATABASE / CREATE TABLE / starter plans
└── docs/
    └── FitTala_System_Documentation.pdf
```

## Database

Tables: `users`, `members`, `membership_plans`, `memberships`, `payments`, `attendance`, `blocked_checkins`, `notifications`, `override_void_log`, `id_counters`.

`memberships` is the junction table between `members` and `membership_plans` (a member can buy many plans over time and a plan can be bought by many members), and each membership has many `payments`. The full ER diagram and data dictionary are in [`docs/`](docs/FitTala_System_Documentation.pdf).

## Getting started

### Requirements

- Apache + PHP 8 (tested on PHP 8.3) with the `pdo_mysql` extension
- MySQL or MariaDB
- [XAMPP](https://www.apachefriends.org/) (or similar) is the easiest way to get all three on Windows

### Setup

1. **Get the code** into your web root, in a folder named `fittala` (for XAMPP: `C:\xampp\htdocs\fittala`).

   ```bash
   git clone <your-repository-url> fittala
   ```

2. **Create the database.** Start MySQL, then import the schema:

   ```bash
   mysql -u root -p < database/fittala_schema.sql
   ```

   (or use phpMyAdmin → Import → `database/fittala_schema.sql`). This creates the `fittala_app` database, the tables and three starter plans (Monthly ₱800, Quarterly ₱2,100, Annual ₱7,500).

3. **Create your settings file.** Copy `config.example.php` to `config.php` and enter your MySQL user and password.

   ```bash
   cp config.example.php config.php
   ```

   `config.php` is listed in `.gitignore` so your password is never committed.

4. **Create the first admin** (a new install has no accounts, and sign-ups need an admin to approve them):

   ```bash
   php create_admin.php admin YourStrongPassword Gym Owner
   ```

5. **(Optional) Load sample data** (7 members, memberships, payments and attendance):

   ```bash
   php seed.php
   ```

6. **Open the site** (Apache and MySQL running):

   - Staff: <http://localhost/fittala/staff.html>
   - Members: <http://localhost/fittala/member.html>

> Open the pages through `http://localhost/...`, not by double-clicking the HTML files, because they talk to the PHP API.

### Demo accounts (after running `seed.php`)

| Site | Sign in with | Password |
|---|---|---|
| Staff | `staff1` | `password123` |
| Admin | the account you created in step 4 | your password |
| Member | `M-001` to `M-007` (or the member's contact number) | `password123` |

These are for demonstration only. Change or remove them before real use.

## Security notes

- Passwords are stored as hashes (`password_hash`), and every query that takes user input uses prepared statements.
- Staff access is role-based (Admin or Staff); members can only see their own records.
- `seed.php` wipes member, membership and payment data, so it only runs from the command line or for a signed-in admin.
- `config.php` is not tracked by git. In `config.php`, set `DEBUG` to `false` for real use so error details are not shown on screen.

## Team

Ayes, Raphael Vinz · Carteciano, Adam · Escopete, Richmond · Gonzales, Guia Elaine · Laviña, Lharyza Joy T. · Rafanan, Mark Lennon

Instructor: Ms. Sairine C. Pregonero
