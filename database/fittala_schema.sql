-- FitTala database schema. Import once (phpMyAdmin > Import, or: mysql -u root -p < database/fittala_schema.sql)
-- Then create the first admin from the command line:  php create_admin.php <username> <password>
-- and (optionally) load sample data with:  php seed.php   (or press "reset demo" in the staff page while signed in as admin).

CREATE DATABASE IF NOT EXISTS fittala_app CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE fittala_app;

CREATE TABLE IF NOT EXISTS users (
  user_id     INT AUTO_INCREMENT PRIMARY KEY,
  first_name  VARCHAR(50)  NOT NULL,
  last_name   VARCHAR(50)  NOT NULL,
  username    VARCHAR(20)  NOT NULL UNIQUE,
  password    VARCHAR(255) NOT NULL,
  role        ENUM('Admin','Staff') NOT NULL DEFAULT 'Staff',
  status      ENUM('Pending','Active','Disabled') NOT NULL DEFAULT 'Pending',
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS members (
  member_id            VARCHAR(10)  NOT NULL PRIMARY KEY,
  first_name           VARCHAR(50)  NOT NULL,
  last_name            VARCHAR(50)  NOT NULL,
  contact              VARCHAR(30)  NOT NULL UNIQUE,
  email                VARCHAR(120) NOT NULL UNIQUE,
  birthdate            DATE NULL,
  street_house_no      VARCHAR(120) NOT NULL DEFAULT '-',
  barangay             VARCHAR(80)  NOT NULL DEFAULT '-',
  city_municipality    VARCHAR(80)  NOT NULL DEFAULT '-',
  province             VARCHAR(80)  NOT NULL DEFAULT '-',
  joined_on            DATE NOT NULL,
  password             VARCHAR(255) NULL,
  notifications_on     TINYINT(1) NOT NULL DEFAULT 1,
  must_change_password TINYINT(1) NOT NULL DEFAULT 0
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS membership_plans (
  plan_id INT AUTO_INCREMENT PRIMARY KEY,
  name    VARCHAR(60) NOT NULL UNIQUE,
  days    INT NOT NULL,
  price   DECIMAL(10,2) NOT NULL,
  status  ENUM('Active','Archived') NOT NULL DEFAULT 'Active'
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS memberships (
  membership_id     VARCHAR(10) NOT NULL PRIMARY KEY,
  member_id         VARCHAR(10) NOT NULL,
  plan_id           INT NOT NULL,
  paying_plan       ENUM('Full','Installment') NOT NULL DEFAULT 'Full',
  pay_every         VARCHAR(20) NULL,
  installment_count INT NULL,
  interval_days     INT NULL,
  total_price       DECIMAL(10,2) NOT NULL,
  duration_days     INT NOT NULL,
  status            ENUM('Open','Cancelled') NOT NULL DEFAULT 'Open',
  is_grace          TINYINT(1) NOT NULL DEFAULT 0,
  purchased_on      DATE NOT NULL,
  start_date        DATE NULL,
  end_date          DATE NULL,
  KEY idx_ms_member (member_id),
  CONSTRAINT fk_ms_member FOREIGN KEY (member_id) REFERENCES members(member_id),
  CONSTRAINT fk_ms_plan   FOREIGN KEY (plan_id)   REFERENCES membership_plans(plan_id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS payments (
  payment_id     VARCHAR(10) NOT NULL PRIMARY KEY,
  membership_id  VARCHAR(10) NOT NULL,
  amount         DECIMAL(10,2) NOT NULL,
  method         VARCHAR(20) NOT NULL,
  paid_on        DATE NOT NULL,
  paid_time      TIME NOT NULL,
  receipt_ref    VARCHAR(60) NULL,
  status         ENUM('Pending','Confirmed','Declined') NOT NULL DEFAULT 'Pending',
  source         ENUM('Staff','Member') NOT NULL DEFAULT 'Staff',
  received_by    INT NULL,
  decided_at     DATETIME NULL,
  is_void        TINYINT(1) NOT NULL DEFAULT 0,
  decline_reason VARCHAR(255) NULL,
  KEY idx_pay_ms (membership_id),
  CONSTRAINT fk_pay_ms FOREIGN KEY (membership_id) REFERENCES memberships(membership_id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS attendance (
  attendance_id   INT AUTO_INCREMENT PRIMARY KEY,
  member_id       VARCHAR(10) NOT NULL,
  attendance_date DATE NOT NULL,
  time_in         TIME NOT NULL,
  time_out        TIME NULL,
  KEY idx_att_member (member_id, attendance_date)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS blocked_checkins (
  blocked_id     INT AUTO_INCREMENT PRIMARY KEY,
  member_id      VARCHAR(10) NOT NULL,
  member_status  VARCHAR(20) NOT NULL,
  attempted_at   DATETIME NOT NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS notifications (
  notification_id INT AUTO_INCREMENT PRIMARY KEY,
  member_id       VARCHAR(10) NOT NULL,
  type            VARCHAR(40) NOT NULL,
  payment_id      VARCHAR(10) NULL,
  membership_id   VARCHAR(10) NULL,
  message         TEXT NOT NULL,
  created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  is_read         TINYINT(1) NOT NULL DEFAULT 0,
  KEY idx_note_member (member_id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS override_void_log (
  log_id        INT AUTO_INCREMENT PRIMARY KEY,
  action        VARCHAR(60) NOT NULL,
  plan_id       INT NULL,
  membership_id VARCHAR(10) NULL,
  payment_id    VARCHAR(10) NULL,
  member_id     VARCHAR(10) NULL,
  reason        VARCHAR(255) NULL,
  performed_by  INT NULL,
  performed_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- Running numbers for M-001, O-001, P-001 style IDs.
CREATE TABLE IF NOT EXISTS id_counters (
  name        VARCHAR(5) NOT NULL PRIMARY KEY,
  last_number INT NOT NULL DEFAULT 0
) ENGINE=InnoDB;

INSERT IGNORE INTO id_counters (name, last_number) VALUES ('M', 0), ('O', 0), ('P', 0);

-- Starter plans (the sample data in seed.php uses these three names).
INSERT IGNORE INTO membership_plans (name, days, price, status) VALUES
  ('Monthly',   30,  800.00, 'Active'),
  ('Quarterly', 90, 2100.00, 'Active'),
  ('Annual',   365, 7500.00, 'Active');
