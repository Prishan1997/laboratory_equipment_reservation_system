# Laboratory Equipment Reservation System (LERS)

**Author:** Prishan Samarasinghe  
**Degree Program:** Master of Science in Information Technology (MSc IT)  
**Specification Date:** October 2026  
**Tech Stack:** Native PHP 8.x, MySQL 8.x (InnoDB ACID Engine), Semantic HTML5, Pure CSS3 (Flexbox & Grid).  
**Dependencies:** Framework-free, zero-dependency, no external CDNs or Composer required. Fully offline-compatible.

---

## 1. Project Directory Structure

```
laboratory_equipment_reservation_system/
├── assets/
│   ├── css/
│   │   ├── style.css             # Core application styles, CSS grid/flex, status badges
│   │   └── auth.css              # High-fidelity styling for Screen 01 Login & Registration
│   └── js/
│       └── app.js                # Interactive slot picker, demo quick-fill, and duration calculators
│
├── config/
│   ├── database.php              # Secure PDO singleton with UTF-8mb4 and exception handling
│   └── schema.sql                # Normalized 3NF MySQL schema with indexes and initial seed data
│
├── includes/
│   ├── auth.php                  # RBAC session management, CSRF tokens (NFR-3), access guards
│   ├── functions.php             # Collision detection, in-app notification engine, formatters
│   ├── header.php                # Semantic HTML5 header with security badge and dynamic navigation
│   └── footer.php                # Academic attribution footer with tech stack metadata
│
├── index.php                     # Gateway router: automatically routes guest vs authenticated user
├── login.php / Login.php         # Screen 01: Authentication & Role-Based Login Gateway
├── register.php                  # User Sign-in / Researcher Account Registration Portal
├── catalog.php                   # Screen 02: Equipment Catalog & Multi-Parameter Filter
├── reserve.php                   # Screen 03: Reservation Request & 24-Hour Slot Picker View
├── my_bookings.php               # Screen 04: Researcher Dashboard & In-App Notification Center
├── approvals.php                 # Screen 05: Supervisor Queue & Physical Check-Out/Return Lifecycle
├── maintenance.php               # Screen 06: Preventative Maintenance & Servicing Blackout Scheduler
├── reports.php                   # Screen 07: Administrative Utilization & RFC-4180 CSV Exporter
└── logout.php                    # Secure session termination and cookie destruction
```

---

## 2. Seeded User Accounts & Roles (For MSc IT Evaluation)

All seeded test accounts use the password: `Password123!`

| Role | Name | Institutional Email / ID | Safety Clearance | Initial View |
| :--- | :--- | :--- | :--- | :--- |
| **Student / Researcher** | Prishan Samarasinghe | `prishan.s@science.ac.lk` (or `ST-2026-081`) | Tier 3 (Bio/Laser/Vacuum) | `catalog.php` |
| **Lab Supervisor / Tech** | Dr. K. Jayasuriya | `supervisor@science.ac.lk` (or `SUP-4019`) | Tier 3 | `approvals.php` |
| **System Administrator** | Admin Director Perera | `admin@science.ac.lk` (or `ADM-0001`) | Tier 3 | `catalog.php` / `reports.php` |

*Note:* You can also click the **⚡ Quick-Fill Demo Profiles** buttons on the login screen to automatically populate credentials with 1 click.

---

## 3. Database Architecture (Normalized 3NF Relational Model)

- **`users`**: Role-based credentials with Bcrypt hashes, certification clearance tiers, and academic departmental affiliations.
- **`equipment`**: Laboratory apparatuses, model numbers, room locations, required certification tiers, and operational states (`operational`, `maintenance`, `decommissioned`).
- **`reservations`**: Scheduled time windows, research justifications, approval states, and physical check-out / condition audit logs. Protected by a composite index on `(equipment_id, start_time, end_time, status)` for `<50ms` collision lookups.
- **`maintenance_logs`**: Declared servicing blackouts with automated conflict resolution that cancels overlapping bookings and dispatches immediate notification alerts.
- **`notifications`**: Persistent in-app user notifications for booking confirmations, rejections, reminders, and blackout cancellations.

---

## 4. How to Run Locally

1. Start **Apache** and **MySQL** in your XAMPP Control Panel.
2. The database `lers_db` is pre-imported. If you ever need to reset it, run:
   ```powershell
   Get-Content config/schema.sql | C:\xampp\mysql\bin\mysql.exe -u root lers_db
   ```
3. Open your browser and navigate to:
   ```
   http://localhost/laboratory_equipment_reservation_system/
   ```
