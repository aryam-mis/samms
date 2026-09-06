-- =====================================================================
--  SAMMS — Smart Asset & Maintenance Management System
--  Database schema and seed data
--
--  Target      : MySQL 8 / MariaDB 10.4+
--  Charset     : utf8mb4 / utf8mb4_unicode_ci
--  Entities    : Users, Assets, MaintenanceRequests, Maintenance,
--                RequestStatusLog
--
--  Bilingual columns
--  Columns suffixed with "En" hold the English equivalent of the
--  Arabic value. When empty, the application falls back to the Arabic
--  text rather than rendering an empty field.
--
--  Seed data
--  The dataset models an administrative building. All records are
--  fictitious and represent no real organisation.
-- =====================================================================

SET NAMES utf8mb4;
SET CHARACTER SET utf8mb4;

DROP DATABASE IF EXISTS samms;
CREATE DATABASE samms CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE samms;

-- =====================================================================
--  1. Users
-- =====================================================================
CREATE TABLE Users (
    UserID        INT AUTO_INCREMENT PRIMARY KEY,
    EmployeeNo    VARCHAR(20)  NOT NULL UNIQUE,
    FullName      VARCHAR(120) NOT NULL,
    FullNameEn    VARCHAR(120) NULL,
    Username      VARCHAR(50)  NOT NULL UNIQUE,
    PasswordHash  VARCHAR(255) NOT NULL,
    Email         VARCHAR(120) NOT NULL UNIQUE,
    Department    VARCHAR(100) NULL,
    DepartmentEn  VARCHAR(100) NULL,
    Role          ENUM('Admin','Employee','ITSupport','Manager') NOT NULL,
    IsActive      TINYINT(1)   NOT NULL DEFAULT 1,
    CreatedAt     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_users_role (Role)
) ENGINE=InnoDB;

-- =====================================================================
--  2. Assets
-- =====================================================================
CREATE TABLE Assets (
    AssetID            INT AUTO_INCREMENT PRIMARY KEY,
    AssetNumber        VARCHAR(30)  NOT NULL UNIQUE,
    DeviceName         VARCHAR(120) NOT NULL,
    DeviceNameEn       VARCHAR(120) NULL,
    DeviceType         ENUM('Desktop','Laptop','Printer','Monitor','NetworkDevice') NOT NULL,
    Brand              VARCHAR(60)  NOT NULL,
    Model              VARCHAR(60)  NOT NULL,
    SerialNumber       VARCHAR(80)  NOT NULL UNIQUE,
    AssignedUserID     INT          NULL,
    AssignedDate       DATE         NULL,          -- date handed over to the holder
    Location           VARCHAR(120) NULL,
    LocationEn         VARCHAR(120) NULL,
    PurchaseDate       DATE         NULL,
    WarrantyExpiryDate DATE         NULL,
    Status             ENUM('Active','UnderMaintenance','OutOfService','Retired') NOT NULL DEFAULT 'Active',
    CreatedAt          DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_assets_user FOREIGN KEY (AssignedUserID)
        REFERENCES Users(UserID) ON DELETE SET NULL,
    INDEX idx_assets_status (Status),
    INDEX idx_assets_type (DeviceType)
) ENGINE=InnoDB;

-- =====================================================================
--  3. MaintenanceRequests
-- =====================================================================
CREATE TABLE MaintenanceRequests (
    RequestID            INT AUTO_INCREMENT PRIMARY KEY,
    AssetID              INT  NOT NULL,
    EmployeeID           INT  NOT NULL,
    ProblemDescription   TEXT NOT NULL,
    ProblemDescriptionEn TEXT NULL,
    -- FaultType complements the free-text description. Free text cannot be
    -- aggregated, so recurrence of a fault on a device is not measurable
    -- without a closed classification.
    FaultType            ENUM('Hardware','Software','Network','Power','Consumable','Peripheral','Other')
                         NOT NULL DEFAULT 'Other',
    Priority             ENUM('Low','Medium','High') NOT NULL DEFAULT 'Medium',
    Status               ENUM('New','InProgress','Completed','Cancelled') NOT NULL DEFAULT 'New',
    RequestDate          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    ClosedAt             DATETIME NULL,
    -- RESTRICT rather than CASCADE. Cascading deletion would erase a
    -- device's maintenance history, which is the record this system exists
    -- to preserve. Deletion is refused at database level; the application
    -- offers a Retired state instead.
    CONSTRAINT fk_req_asset FOREIGN KEY (AssetID)
        REFERENCES Assets(AssetID) ON DELETE RESTRICT,
    CONSTRAINT fk_req_employee FOREIGN KEY (EmployeeID)
        REFERENCES Users(UserID),
    INDEX idx_req_status (Status),
    INDEX idx_req_fault (FaultType),
    INDEX idx_req_date (RequestDate)
) ENGINE=InnoDB;

-- =====================================================================
--  4. Maintenance
-- =====================================================================
CREATE TABLE Maintenance (
    MaintenanceID   INT AUTO_INCREMENT PRIMARY KEY,
    RequestID       INT      NOT NULL,
    ITSupportID     INT      NOT NULL,
    MaintenanceDate DATETIME NOT NULL,
    ActionTaken     TEXT     NOT NULL,
    ActionTakenEn   TEXT     NULL,
    PartsReplaced   VARCHAR(255) NULL,
    PartsReplacedEn VARCHAR(255) NULL,
    Notes           TEXT     NULL,
    NotesEn         TEXT     NULL,
    CompletionDate  DATETIME NULL,
    CONSTRAINT fk_maint_request FOREIGN KEY (RequestID)
        REFERENCES MaintenanceRequests(RequestID) ON DELETE CASCADE,
    CONSTRAINT fk_maint_tech FOREIGN KEY (ITSupportID)
        REFERENCES Users(UserID),
    INDEX idx_maint_tech (ITSupportID)
) ENGINE=InnoDB;

-- =====================================================================
--  5. RequestStatusLog
-- =====================================================================
CREATE TABLE RequestStatusLog (
    LogID      INT AUTO_INCREMENT PRIMARY KEY,
    RequestID  INT      NOT NULL,
    OldStatus  VARCHAR(20) NULL,
    NewStatus  VARCHAR(20) NOT NULL,
    ChangedBy  INT      NOT NULL,
    ChangedAt  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_log_request FOREIGN KEY (RequestID)
        REFERENCES MaintenanceRequests(RequestID) ON DELETE CASCADE,
    CONSTRAINT fk_log_user FOREIGN KEY (ChangedBy)
        REFERENCES Users(UserID)
) ENGINE=InnoDB;


-- =====================================================================
--  SEED DATA
--
--  Passwords are stored as bcrypt hashes. Credentials are held
--  separately and are not published in this repository.
-- =====================================================================

INSERT INTO Users (EmployeeNo, FullName, FullNameEn, Username, PasswordHash, Email, Department, DepartmentEn, Role) VALUES
('10001','نورة','Noura','admin',     '$2y$10$cArWsw/6meF6aSqY286tteKGX7yryoXuk9FbNgmOUgouL0EAfXMW.','admin@samms.local','تقنية المعلومات','Information Technology','Admin'),
('10002','خالد','Khalid','manager',  '$2y$10$FuTQKNblNwctlFZMvcyA7.CunzmbVdTuvMaDBJExLRmbhkRUQsY9e','manager@samms.local','تقنية المعلومات','Information Technology','Manager'),
('10003','سعد','Saad','itsupport',   '$2y$10$ajEYRUwkuip6U/g1IPQj3.YSQT0hwi.Ot37QXvjTdJYLzGtM5bSZm','it1@samms.local','تقنية المعلومات','Information Technology','ITSupport'),
('10004','ريم','Reem','itsupport2',  '$2y$10$ajEYRUwkuip6U/g1IPQj3.YSQT0hwi.Ot37QXvjTdJYLzGtM5bSZm','it2@samms.local','تقنية المعلومات','Information Technology','ITSupport'),
('10005','منى','Mona','employee',    '$2y$10$m8.2SPbX3NqG/9EaRc8uTuQqlpdYYzrcdGZhsvaychi90vhKg2Wlu','emp1@samms.local','الموارد البشرية','Human Resources','Employee'),
('10006','فهد','Fahad','employee2',  '$2y$10$m8.2SPbX3NqG/9EaRc8uTuQqlpdYYzrcdGZhsvaychi90vhKg2Wlu','emp2@samms.local','الشؤون المالية','Finance','Employee'),
('10007','هند','Hind','employee3',   '$2y$10$m8.2SPbX3NqG/9EaRc8uTuQqlpdYYzrcdGZhsvaychi90vhKg2Wlu','emp3@samms.local','المشتريات والعقود','Procurement & Contracts','Employee'),
('10008','عبدالله','Abdullah','employee4','$2y$10$m8.2SPbX3NqG/9EaRc8uTuQqlpdYYzrcdGZhsvaychi90vhKg2Wlu','emp4@samms.local','الشؤون الإدارية','Administrative Affairs','Employee'),
('10009','لمى','Lama','employee5',   '$2y$10$m8.2SPbX3NqG/9EaRc8uTuQqlpdYYzrcdGZhsvaychi90vhKg2Wlu','emp5@samms.local','الاتصالات المؤسسية','Corporate Communications','Employee');

INSERT INTO Assets (AssetNumber, DeviceName, DeviceNameEn, DeviceType, Brand, Model, SerialNumber, AssignedUserID, AssignedDate, Location, LocationEn, PurchaseDate, WarrantyExpiryDate, Status) VALUES
('AST-1001','جهاز مكتبي - الموارد البشرية 1','Desktop - Human Resources 1','Desktop','Dell','OptiPlex 7010','SN-DL-88201',5,'2023-02-26','الدور الأول - الموارد البشرية','Floor 1 - Human Resources','2023-02-14','2026-02-14','Active'),
('AST-1002','لابتوب - الموارد البشرية','Laptop - Human Resources','Laptop','HP','ProBook 450 G9','SN-HP-33417',5,'2023-06-11','الدور الأول - الموارد البشرية','Floor 1 - Human Resources','2023-06-01','2026-06-01','UnderMaintenance'),
('AST-1003','طابعة الشؤون المالية','Finance Printer','Printer','HP','LaserJet Pro M404','SN-HP-77120',6,'2022-10-02','الدور الثاني - الشؤون المالية','Floor 2 - Finance','2022-09-20','2025-09-20','Active'),
('AST-1004','شاشة - الشؤون المالية','Monitor - Finance','Monitor','Samsung','S24R650','SN-SM-11093',6,'2023-01-22','الدور الثاني - الشؤون المالية','Floor 2 - Finance','2023-01-10','2026-01-10','Active'),
('AST-1005','جهاز مكتبي - المشتريات','Desktop - Procurement','Desktop','Lenovo','ThinkCentre M70q','SN-LN-55302',7,'2024-03-17','الدور الثاني - المشتريات والعقود','Floor 2 - Procurement & Contracts','2024-03-05','2027-03-05','Active'),
('AST-1006','لابتوب - الشؤون الإدارية','Laptop - Administrative Affairs','Laptop','Dell','Latitude 5440','SN-DL-64118',8,'2024-05-29','الدور الثالث - الشؤون الإدارية','Floor 3 - Administrative Affairs','2024-05-18','2027-05-18','Active'),
('AST-1007','طابعة المشتريات والعقود','Procurement & Contracts Printer','Printer','Canon','imageCLASS LBP246','SN-CN-90244',7,'2022-12-12','الدور الثاني - المشتريات والعقود','Floor 2 - Procurement & Contracts','2022-11-30','2025-11-30','OutOfService'),
('AST-1008','راوتر الدور الثاني','Floor 2 Router','NetworkDevice','Cisco','RV340','SN-CS-20871',NULL,NULL,'غرفة الشبكة - الدور الثاني','Network Room - Floor 2','2023-08-08','2026-08-08','Active'),
('AST-1009','سويتش الدور الأول','Floor 1 Switch','NetworkDevice','Cisco','Catalyst 1000','SN-CS-20955',NULL,NULL,'غرفة الشبكة - الدور الأول','Network Room - Floor 1','2023-08-08','2026-08-08','Active'),
('AST-1010','شاشة - الاستقبال','Monitor - Reception','Monitor','LG','24MK600M','SN-LG-40218',5,'2022-07-24','الدور الأول - الاستقبال','Floor 1 - Reception','2022-07-12','2025-07-12','Active'),
('AST-1011','جهاز مكتبي - الاتصالات المؤسسية','Desktop - Corporate Communications','Desktop','HP','EliteDesk 800 G6','SN-HP-71336',9,'2024-02-04','الدور الثالث - الاتصالات المؤسسية','Floor 3 - Corporate Communications','2024-01-22','2027-01-22','UnderMaintenance'),
('AST-1012','لابتوب - الإدارة','Laptop - Management','Laptop','Apple','MacBook Air M2','SN-AP-10457',2,'2024-09-15','الدور الرابع - الإدارة','Floor 4 - Management','2024-09-01','2027-09-01','Active'),
('AST-1013','طابعة الإدارة','Management Printer','Printer','Brother','HL-L2375DW','SN-BR-58811',2,'2023-05-02','الدور الرابع - الإدارة','Floor 4 - Management','2023-04-19','2026-04-19','Active'),
('AST-1014','شاشة - الشؤون الإدارية','Monitor - Administrative Affairs','Monitor','Dell','P2422H','SN-DL-30766',8,'2023-10-16','الدور الثالث - الشؤون الإدارية','Floor 3 - Administrative Affairs','2023-10-03','2026-10-03','Active'),
('AST-1015','جهاز مكتبي - الموارد البشرية 2','Desktop - Human Resources 2','Desktop','Lenovo','ThinkCentre M75q','SN-LN-55488',5,'2024-06-25','الدور الأول - الموارد البشرية','Floor 1 - Human Resources','2024-06-11','2027-06-11','Active'),
('AST-1016','أكسس بوينت - الدور الثالث','Floor 3 Access Point','NetworkDevice','Ubiquiti','UniFi U6-Lite','SN-UB-77003',NULL,NULL,'الدور الثالث - الممر الرئيسي','Floor 3 - Main Corridor','2024-02-27','2027-02-27','Active'),
('AST-1017','لابتوب - الاتصالات المؤسسية','Laptop - Corporate Communications','Laptop','HP','ProBook 440 G10','SN-HP-33902',9,'2025-01-28','الدور الثالث - الاتصالات المؤسسية','Floor 3 - Corporate Communications','2025-01-15','2028-01-15','Active'),
('AST-1018','طابعة الشؤون الإدارية','Administrative Affairs Printer','Printer','Epson','EcoTank L3250','SN-EP-64200',8,'2023-03-20','الدور الثالث - الشؤون الإدارية','Floor 3 - Administrative Affairs','2023-03-08','2026-03-08','Active');

INSERT INTO MaintenanceRequests (AssetID, EmployeeID, ProblemDescription, ProblemDescriptionEn, FaultType, Priority, Status, RequestDate, ClosedAt) VALUES
(3, 6,'الطابعة لا تسحب الورق وتظهر رسالة انحشار متكررة عند طباعة المستخلصات.','The printer does not feed paper and repeatedly reports a jam when printing statements.','Hardware','Medium','Completed','2026-02-11 09:20:00','2026-02-12 13:40:00'),
(1, 5,'الجهاز بطيء جداً عند الإقلاع ويستغرق أكثر من خمس دقائق قبل فتح البريد.','The desktop is very slow to boot and takes over five minutes before email opens.','Software','Low','Completed','2026-02-24 08:05:00','2026-02-26 10:15:00'),
(7, 7,'الطابعة لا تعمل نهائياً ولا يوجد ضوء في لوحة التحكم.','The printer is completely dead with no light on the control panel.','Power','High','Completed','2026-03-09 11:30:00','2026-03-14 15:00:00'),
(3, 6,'نفس مشكلة انحشار الورق رجعت مرة ثانية.','The same paper jam problem has returned.','Hardware','High','Completed','2026-03-30 10:00:00','2026-04-02 12:20:00'),
(5, 7,'الشاشة تومض والجهاز يعيد التشغيل تلقائياً أثناء العمل على ملفات العقود.','The screen flickers and the machine restarts on its own while working on contract files.','Hardware','High','Completed','2026-04-15 13:45:00','2026-04-17 09:30:00'),
(10,5,'الشاشة فيها خطوط عمودية ثابتة.','The monitor shows fixed vertical lines.','Hardware','Medium','Completed','2026-05-06 09:10:00','2026-05-08 14:00:00'),
(3, 6,'جودة الطباعة ضعيفة والحبر باهت في المستندات الرسمية.','Print quality is poor and the toner looks faded on official documents.','Consumable','Low','Completed','2026-05-21 10:40:00','2026-05-22 11:10:00'),
(6, 8,'لوحة المفاتيح بعض أزرارها لا تستجيب.','Several keys on the keyboard do not respond.','Peripheral','Medium','Completed','2026-06-03 08:50:00','2026-06-05 16:25:00'),
(13,2,'الطابعة لا تتصل بالشبكة اللاسلكية ولا تظهر في قائمة الطابعات.','The printer will not join the wireless network and does not appear in the printer list.','Network','Medium','Completed','2026-06-18 14:00:00','2026-06-20 10:05:00'),
(18,8,'تسريب حبر داخل الطابعة ويظهر على الأوراق المطبوعة.','Ink is leaking inside the printer and marking the printed pages.','Consumable','High','Completed','2026-07-02 09:00:00','2026-07-06 13:30:00'),
(2, 5,'اللابتوب يسخن بشكل غير طبيعي ويطفئ فجأة أثناء الاجتماعات.','The laptop overheats and shuts down suddenly during meetings.','Hardware','High','InProgress','2026-07-28 10:20:00',NULL),
(11,9,'الجهاز لا يقلع ويصدر صوت تنبيه متكرر عند التشغيل.','The desktop will not boot and emits repeated beeps at startup.','Hardware','High','InProgress','2026-08-04 08:30:00',NULL),
(14,8,'الشاشة لا تستقبل إشارة من الجهاز رغم تبديل الكيبل.','The monitor receives no signal from the desktop even after swapping the cable.','Peripheral','Medium','New','2026-08-11 11:00:00',NULL),
(17,9,'بطارية اللابتوب لا تشحن ولا يعمل إلا موصولاً بالكهرباء.','The laptop battery will not charge and it only runs while plugged in.','Power','Medium','New','2026-08-13 09:45:00',NULL),
(15,5,'طلب تركيب برنامج إضافي — تم إلغاؤه لعدم الحاجة.','Request to install additional software — cancelled, no longer needed.','Software','Low','Cancelled','2026-08-06 12:00:00','2026-08-06 15:00:00');

INSERT INTO Maintenance (RequestID, ITSupportID, MaintenanceDate, ActionTaken, ActionTakenEn, PartsReplaced, PartsReplacedEn, Notes, NotesEn, CompletionDate) VALUES
(1, 3,'2026-02-12 09:00:00','تنظيف مسار الورق وضبط بكرة السحب.','Cleaned the paper path and realigned the pickup roller.','بكرة سحب','Pickup roller','تم اختبار 50 صفحة بدون انحشار.','Tested 50 pages with no jam.','2026-02-12 13:40:00'),
(2, 4,'2026-02-25 10:00:00','تنظيف برمجي وترقية القرص إلى SSD.','Software cleanup and upgraded the drive to an SSD.','قرص SSD 256GB','256GB SSD','تحسن زمن الإقلاع إلى أقل من 30 ثانية.','Boot time improved to under 30 seconds.','2026-02-26 10:15:00'),
(3, 3,'2026-03-10 09:30:00','فحص مزود الطاقة — تبيّن تلفه.','Tested the power supply — found faulty.','—','—','تم طلب قطعة بديلة.','Replacement part ordered.',NULL),
(3, 3,'2026-03-14 10:00:00','استبدال مزود الطاقة وإعادة التشغيل.','Replaced the power supply and restarted the unit.','مزود طاقة','Power supply','الجهاز تجاوز عمره الافتراضي — يُنصح بالاستبدال.','Device is past its service life — replacement recommended.','2026-03-14 15:00:00'),
(4, 3,'2026-04-01 11:00:00','استبدال بكرة السحب بالكامل وتنظيف عام.','Replaced the pickup roller assembly and did a general clean.','بكرة سحب + وسادة فصل','Pickup roller + separation pad','مشكلة متكررة على نفس الجهاز.','Recurring issue on the same device.','2026-04-02 12:20:00'),
(5, 4,'2026-04-16 09:00:00','استبدال كيبل الشاشة وإعادة تثبيت التعريفات.','Replaced the display cable and reinstalled drivers.','كيبل HDMI','HDMI cable','—','—','2026-04-17 09:30:00'),
(6, 3,'2026-05-07 10:30:00','فحص الشاشة — عطل في اللوحة الداخلية، تم الاستبدال بشاشة احتياطية.','Inspected the monitor — internal panel fault, swapped for a spare.','شاشة بديلة','Replacement monitor','—','—','2026-05-08 14:00:00'),
(7, 4,'2026-05-22 09:15:00','استبدال خرطوشة الحبر ومعايرة الطباعة.','Replaced the toner cartridge and recalibrated printing.','خرطوشة حبر','Toner cartridge','—','—','2026-05-22 11:10:00'),
(8, 3,'2026-06-04 13:00:00','استبدال لوحة المفاتيح الداخلية.','Replaced the internal keyboard.','لوحة مفاتيح','Keyboard','—','—','2026-06-05 16:25:00'),
(9, 4,'2026-06-19 10:00:00','إعادة ضبط إعدادات الشبكة وتحديث الفيرموير.','Reset the network settings and updated the firmware.','—','—','—','—','2026-06-20 10:05:00'),
(10,3,'2026-07-03 09:00:00','تنظيف داخلي شامل واستبدال خزان الحبر.','Full internal clean and replaced the ink tank.','خزان حبر','Ink tank','—','—','2026-07-06 13:30:00'),
(11,4,'2026-07-29 11:00:00','فك الجهاز وتنظيف المروحة وتغيير المعجون الحراري.','Opened the unit, cleaned the fan and reapplied thermal paste.','معجون حراري','Thermal paste','قيد المتابعة — تحت الاختبار.','Under follow-up — still being tested.',NULL),
(12,3,'2026-08-05 09:30:00','فحص الذاكرة — يُشتبه في تلف إحدى الشرائح.','Tested the memory — one module suspected faulty.','—','—','بانتظار وصول القطعة.','Waiting for the part to arrive.',NULL);

INSERT INTO RequestStatusLog (RequestID, OldStatus, NewStatus, ChangedBy, ChangedAt) VALUES
(1,NULL,'New',6,'2026-02-11 09:20:00'),(1,'New','InProgress',3,'2026-02-12 09:00:00'),(1,'InProgress','Completed',3,'2026-02-12 13:40:00'),
(2,NULL,'New',5,'2026-02-24 08:05:00'),(2,'New','InProgress',4,'2026-02-25 10:00:00'),(2,'InProgress','Completed',4,'2026-02-26 10:15:00'),
(3,NULL,'New',7,'2026-03-09 11:30:00'),(3,'New','InProgress',3,'2026-03-10 09:30:00'),(3,'InProgress','Completed',3,'2026-03-14 15:00:00'),
(4,NULL,'New',6,'2026-03-30 10:00:00'),(4,'New','InProgress',3,'2026-04-01 11:00:00'),(4,'InProgress','Completed',3,'2026-04-02 12:20:00'),
(5,NULL,'New',7,'2026-04-15 13:45:00'),(5,'New','InProgress',4,'2026-04-16 09:00:00'),(5,'InProgress','Completed',4,'2026-04-17 09:30:00'),
(6,NULL,'New',5,'2026-05-06 09:10:00'),(6,'New','InProgress',3,'2026-05-07 10:30:00'),(6,'InProgress','Completed',3,'2026-05-08 14:00:00'),
(7,NULL,'New',6,'2026-05-21 10:40:00'),(7,'New','InProgress',4,'2026-05-22 09:15:00'),(7,'InProgress','Completed',4,'2026-05-22 11:10:00'),
(8,NULL,'New',8,'2026-06-03 08:50:00'),(8,'New','InProgress',3,'2026-06-04 13:00:00'),(8,'InProgress','Completed',3,'2026-06-05 16:25:00'),
(9,NULL,'New',2,'2026-06-18 14:00:00'),(9,'New','InProgress',4,'2026-06-19 10:00:00'),(9,'InProgress','Completed',4,'2026-06-20 10:05:00'),
(10,NULL,'New',8,'2026-07-02 09:00:00'),(10,'New','InProgress',3,'2026-07-03 09:00:00'),(10,'InProgress','Completed',3,'2026-07-06 13:30:00'),
(11,NULL,'New',5,'2026-07-28 10:20:00'),(11,'New','InProgress',4,'2026-07-29 11:00:00'),
(12,NULL,'New',9,'2026-08-04 08:30:00'),(12,'New','InProgress',3,'2026-08-05 09:30:00'),
(13,NULL,'New',8,'2026-08-11 11:00:00'),
(14,NULL,'New',9,'2026-08-13 09:45:00'),
(15,NULL,'New',5,'2026-08-06 12:00:00'),(15,'New','Cancelled',5,'2026-08-06 15:00:00');
