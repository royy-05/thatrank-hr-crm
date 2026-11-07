# ThatRank HR Software

Comprehensive HR and Attendance Management Platform for ThatRank Digital Marketing Solution.

## Technology Stack
- Backend: PHP 8.1+ (Vanilla OOP / MVC architecture)
- Database: MySQL 8.0+ / MariaDB 10.4+
- Frontend: Vanilla JavaScript (ES6+), HTML5, CSS3, SVG charts
- PWA: Web App Manifest, Service Worker caching
- Web Server: Apache with mod_rewrite enabled (compatible with XAMPP, cPanel, and Hostinger)

## Scheduled Background Jobs (Crons)

Configure these cron jobs in your server crontab or hosting control panel (such as Hostinger hPanel or cPanel):

| Cron Script | Recommended Schedule | Cron Expression | Purpose |
| :--- | :--- | :--- | :--- |
| `cron/dispatch_notifications.php` | Every minute | `* * * * *` | Dispatches queued notifications to n8n webhook and SMTP email with exponential backoff retry. |
| `cron/celebrations.php` | Daily at 08:00 AM | `0 8 * * *` | Automatically generates community celebration posts for birthdays and work anniversaries. |
| `cron/backup_db.php` | Daily at 03:00 AM | `0 3 * * *` | Creates compressed database dump in storage/backups/ and removes backups older than 14 days. |
| `cron/auto_clockout.php` | Daily at 11:00 PM | `0 23 * * *` | Automatically clocks out unclosed shifts at target hours or cutoff time. |
| `cron/purge_selfies.php` | Daily at 02:00 AM | `0 2 * * *` | Permanently deletes punch selfies older than 90 days per privacy policy. |
| `cron/digests.php` | Weekdays at 07:00 PM | `0 19 * * 1-5` | Aggregates and queues daily/weekly attendance digests for managers. |

### Crontab Examples (Hostinger / Linux Server)
```bash
* * * * * /usr/bin/php /home/username/public_html/cron/dispatch_notifications.php >> /dev/null 2>&1
0 8 * * * /usr/bin/php /home/username/public_html/cron/celebrations.php >> /dev/null 2>&1
0 3 * * * /usr/bin/php /home/username/public_html/cron/backup_db.php >> /dev/null 2>&1
0 23 * * * /usr/bin/php /home/username/public_html/cron/auto_clockout.php >> /dev/null 2>&1
0 2 * * * /usr/bin/php /home/username/public_html/cron/purge_selfies.php >> /dev/null 2>&1
0 19 * * 1-5 /usr/bin/php /home/username/public_html/cron/digests.php >> /dev/null 2>&1
```

## Setup and Installation

1. Copy `.env.example` to `.env` and fill in database credentials and n8n webhook URL.
2. Import schema:
   ```bash
   mysql -u root -p thatrank_hr_software < database/schema.sql
   ```
3. Run migrations if updating an existing install:
   ```bash
   mysql -u root -p thatrank_hr_software < database/migration_002.sql
   mysql -u root -p thatrank_hr_software < database/migration_003.sql
   mysql -u root -p thatrank_hr_software < database/migration_005.sql
   ```
4. Configure Apache document root to point to the `public/` directory or place the project in the web root. Mod_rewrite must be enabled.

## Payroll and My Finances Modules

### Features
- **Admin Payroll (`data-screen="payroll"`)**:
  - **Run Payroll**: Pick month/year, generate draft payroll for active employees. Calculates real working days, paid days, and loss of pay (LOP) from actual attendance records and approved leave requests. Preview table allows per-employee LOP adjustments and one-off earnings/deductions.
  - **Finalize, Reopen & Paid**: Finalizing locks the payroll run and publishes payslips (enqueuing `payslip_published` in `notifications_queue`). Marking as paid sets `paid_on`. Reopening is allowed only before any payslip is marked as paid.
  - **Exports**: Bank transfer CSV (UTF-8 BOM), Payroll summary CSV (UTF-8 BOM), and a ZIP of all payslip PDFs generated on-demand.
  - **Salary Components**: Manage earning and deduction components with customizable calculation types (fixed, percent of basic, percent of gross), statutory flags (PF, ESI, PT, TDS), sort orders, and active toggles.
  - **Salary Structures**: Maintain employee CTC structures with effective date history. The latest effective structure on or before the payroll month is applied. Supports bulk assignment across multiple employees.
  - **Payroll Settings**: Every rate, ceiling, threshold, divisor, rounding mode, and label is stored in `company_settings` and editable via the UI.

- **My Finances (`data-screen="fin"`)**:
  - **Overview Cards**: Net pay for latest published month, Gross, Deductions, and Next payday with days remaining.
  - **Month Breakdown**: Dynamic earnings and deductions tables built from the payslip JSON, with month picker and on-demand Payslip PDF download.
  - **Payslips Archive**: Filtered by financial year according to the configured financial year start month setting.
  - **Tax Summary**: Tax deducted so far in the financial year and projected FY tax based on current monthly salary (labeled as an estimate).

- **Payslip PDF**:
  - Generated on-demand via Dompdf with company branding, legal address, employee metadata, itemized earnings and deductions, and net salary in words using Indian number grouping (Lakhs, Crores, and Paise).
  - Strict authorization: accessible by the payslip owner or admin only.

### Running Payroll Backend Tests
Run the isolated test runner (executes 10 suites in transactions without modifying real data):
```bash
php tests/payroll/run.php
```


