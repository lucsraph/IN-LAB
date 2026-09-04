-- ============================================================
--  RFID Inventory Management System - Updated Schema
--  Supports item stacking: multiple RFID UIDs per item type
--
--  MIGRATION GUIDE (if you already have data):
--  Run the migration block at the bottom of this file.
--  For fresh installs, just run everything top to bottom.
-- ============================================================

CREATE DATABASE IF NOT EXISTS rfid_inventory
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE rfid_inventory;

-- ─── Item Types Table ─────────────────────────────────────────
-- One row per item TYPE (e.g. "Arduino Uno Kit")
CREATE TABLE IF NOT EXISTS item_types (
  id              INT AUTO_INCREMENT PRIMARY KEY,
  item_name       VARCHAR(100) NOT NULL,
  description     TEXT,
  category        VARCHAR(50)  DEFAULT 'General',
  location        VARCHAR(100) DEFAULT 'Storage Room',
  total_units     INT          DEFAULT 0,
  units_available INT          DEFAULT 0,
  added_at        DATETIME     DEFAULT CURRENT_TIMESTAMP,
  updated_at      DATETIME     DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- ─── Item Units Table ─────────────────────────────────────────
-- One row per PHYSICAL UNIT — each has its own RFID UID
CREATE TABLE IF NOT EXISTS item_units (
  id           INT AUTO_INCREMENT PRIMARY KEY,
  item_type_id INT          NOT NULL,
  rfid_uid     VARCHAR(50)  NOT NULL UNIQUE,
  unit_label   VARCHAR(50)  DEFAULT '',
  status       ENUM('available','borrowed','maintenance','retired') DEFAULT 'available',
  added_at     DATETIME     DEFAULT CURRENT_TIMESTAMP,
  updated_at   DATETIME     DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (item_type_id) REFERENCES item_types(id) ON DELETE CASCADE
);

-- ─── Users Table ──────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS users (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  rfid_uid    VARCHAR(50)   NOT NULL UNIQUE,
  full_name   VARCHAR(100)  NOT NULL,
  student_id  VARCHAR(30),
  department  VARCHAR(100),
  email       VARCHAR(100),
  phone       VARCHAR(20),
  role        ENUM('student','staff','admin') DEFAULT 'student',
  is_active   TINYINT(1)    DEFAULT 1,
  created_at  DATETIME      DEFAULT CURRENT_TIMESTAMP
);

-- ─── Transactions Table ───────────────────────────────────────
CREATE TABLE IF NOT EXISTS transactions (
  id             INT AUTO_INCREMENT PRIMARY KEY,
  item_unit_id   INT          NOT NULL,
  item_type_id   INT          NOT NULL,
  user_id        INT,
  action         ENUM('borrow','return','flag') NOT NULL,
  borrow_date    DATETIME,
  return_date    DATETIME,
  due_date       DATETIME,
  notes          TEXT,
  status         ENUM('active','completed','overdue','cancelled') DEFAULT 'active',
  created_at     DATETIME     DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (item_unit_id) REFERENCES item_units(id),
  FOREIGN KEY (item_type_id) REFERENCES item_types(id),
  FOREIGN KEY (user_id)      REFERENCES users(id)
);

-- ─── System Logs Table ────────────────────────────────────────
CREATE TABLE IF NOT EXISTS system_logs (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  rfid_uid    VARCHAR(50),
  action      VARCHAR(50),
  result      ENUM('success','error','warning'),
  message     TEXT,
  ip_address  VARCHAR(45),
  logged_at   DATETIME DEFAULT CURRENT_TIMESTAMP
);

-- ─── Triggers: keep unit counts in sync ───────────────────────
DELIMITER $$

DROP TRIGGER IF EXISTS after_unit_insert$$
CREATE TRIGGER after_unit_insert AFTER INSERT ON item_units
FOR EACH ROW BEGIN
  UPDATE item_types SET
    total_units     = (SELECT COUNT(*) FROM item_units WHERE item_type_id = NEW.item_type_id),
    units_available = (SELECT COUNT(*) FROM item_units WHERE item_type_id = NEW.item_type_id AND status = 'available')
  WHERE id = NEW.item_type_id;
END$$

DROP TRIGGER IF EXISTS after_unit_update$$
CREATE TRIGGER after_unit_update AFTER UPDATE ON item_units
FOR EACH ROW BEGIN
  UPDATE item_types SET
    total_units     = (SELECT COUNT(*) FROM item_units WHERE item_type_id = NEW.item_type_id),
    units_available = (SELECT COUNT(*) FROM item_units WHERE item_type_id = NEW.item_type_id AND status = 'available')
  WHERE id = NEW.item_type_id;
END$$

DROP TRIGGER IF EXISTS after_unit_delete$$
CREATE TRIGGER after_unit_delete AFTER DELETE ON item_units
FOR EACH ROW BEGIN
  UPDATE item_types SET
    total_units     = (SELECT COUNT(*) FROM item_units WHERE item_type_id = OLD.item_type_id),
    units_available = (SELECT COUNT(*) FROM item_units WHERE item_type_id = OLD.item_type_id AND status = 'available')
  WHERE id = OLD.item_type_id;
END$$

DELIMITER ;

-- ─── Views ────────────────────────────────────────────────────
CREATE OR REPLACE VIEW stack_summary AS
SELECT
  it.id            AS type_id,
  it.item_name,
  it.category,
  it.location,
  it.total_units,
  it.units_available,
  (it.total_units - it.units_available) AS units_borrowed,
  GROUP_CONCAT(
    CONCAT(iu.rfid_uid,' [',iu.status,']')
    ORDER BY iu.id SEPARATOR ' | '
  ) AS unit_uids
FROM item_types it
LEFT JOIN item_units iu ON iu.item_type_id = it.id
GROUP BY it.id;

CREATE OR REPLACE VIEW active_borrows AS
SELECT
  t.id           AS transaction_id,
  it.item_name,
  iu.rfid_uid    AS item_uid,
  iu.unit_label,
  u.full_name    AS borrower,
  u.student_id,
  t.borrow_date,
  t.due_date,
  CASE WHEN t.due_date < NOW() THEN 'overdue' ELSE 'active' END AS borrow_status
FROM transactions t
JOIN item_units iu ON t.item_unit_id = iu.id
JOIN item_types it ON t.item_type_id = it.id
LEFT JOIN users u  ON t.user_id = u.id
WHERE t.action = 'borrow' AND t.status = 'active';

-- ─── Sample Data ──────────────────────────────────────────────
INSERT INTO item_types (item_name, description, category, location) VALUES
('Arduino Uno Kit',    'Arduino Uno with breadboard and jumper wires', 'Electronics', 'Cabinet A'),
('Oscilloscope',       'Rigol DS1054Z Digital Oscilloscope',           'Instruments', 'Lab Bench 1'),
('Soldering Iron',     'Hakko FX-888D Soldering Station',              'Tools',       'Workbench'),
('Multimeter',         'Fluke 117 True-RMS Multimeter',                'Instruments', 'Cabinet B'),
('Raspberry Pi 4 Kit', 'RPi 4 4GB with power supply and SD card',      'Electronics', 'Cabinet A');

-- Arduino Uno Kit — 3 units
INSERT INTO item_units (item_type_id, rfid_uid, unit_label) VALUES
(1, 'A1:B2:C3:D4', 'Arduino #1'),
(1, 'A1:B2:C3:D5', 'Arduino #2'),
(1, 'A1:B2:C3:D6', 'Arduino #3');

-- Oscilloscope — 2 units
INSERT INTO item_units (item_type_id, rfid_uid, unit_label) VALUES
(2, 'E5:F6:G7:H8', 'Oscilloscope #1'),
(2, 'E5:F6:G7:H9', 'Oscilloscope #2');

-- Soldering Iron — 2 units
INSERT INTO item_units (item_type_id, rfid_uid, unit_label) VALUES
(3, 'I9:J0:K1:L2', 'Soldering Iron #1'),
(3, 'I9:J0:K1:L3', 'Soldering Iron #2');

-- Multimeter — 3 units
INSERT INTO item_units (item_type_id, rfid_uid, unit_label) VALUES
(4, 'M3:N4:O5:P6', 'Multimeter #1'),
(4, 'M3:N4:O5:P7', 'Multimeter #2'),
(4, 'M3:N4:O5:P8', 'Multimeter #3');

-- Raspberry Pi 4 Kit — 2 units
INSERT INTO item_units (item_type_id, rfid_uid, unit_label) VALUES
(5, 'Q7:R8:S9:T0', 'RPi4 Kit #1'),
(5, 'Q7:R8:S9:T1', 'RPi4 Kit #2');

-- Sample Users
INSERT INTO users (rfid_uid, full_name, student_id, department, email) VALUES
('U1:V2:W3:X4', 'Juan Dela Cruz', '2021-00001', 'Computer Engineering',    'juan@school.edu'),
('Y5:Z6:A7:B8', 'Maria Santos',   '2021-00002', 'Electronics Engineering', 'maria@school.edu'),
('C9:D0:E1:F2', 'Pedro Reyes',    '2022-00015', 'Computer Science',        'pedro@school.edu');

-- ============================================================
--  MIGRATION BLOCK — run ONLY if upgrading from old schema
-- ============================================================
/*
INSERT INTO item_types (item_name, description, category, location)
SELECT DISTINCT item_name, description, category, location FROM items;

INSERT INTO item_units (item_type_id, rfid_uid, unit_label, status)
SELECT it.id, i.rfid_uid, CONCAT(i.item_name, ' #1'), i.status
FROM items i
JOIN item_types it ON it.item_name = i.item_name;
*/
