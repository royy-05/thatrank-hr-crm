# ThatRank HR: Scope of Work v1
Nov 6, 2026 · @Jaydeb Barman

## Overview
ThatRank HR v1 is a browser-based attendance, leave and team-updates app for a fully work-from-home team of about 15 people. Employees clock in, take breaks and clock out from any browser; managers see who is working and approve leave; admins set rules and run reports.

| Item | Decision |
| :--- | :--- |
| Stack | PHP 8.1+, MySQL 8, vanilla JS (no framework), HTML/CSS from the approved prototype |
| Hosting | Hostinger shared hosting, subdomain team.thatrank.com, HTTPS required |
| Database | New MySQL database, separate from the existing finance app |
| Cron | Hostinger hPanel cron for auto clock-out, selfie purge and digests |
| Email | SMTP (Hostinger mail) for password reset and alerts |
| Alerts | Webhooks to n8n on the ThatRank VPS (email or WhatsApp sent from there) |
| PDF | Dompdf for report and payslip exports |
| Design | Follow the approved HTML prototype pixel for pixel. Light theme default, dark theme toggle. ThatRank logo in sidebar and sign-in |
| Users | Employee, Manager, Admin (see Roles) |
| Devices | Desktop and mobile browsers. Installable as a PWA (home screen icon), no native app |

The prototype (pulse-hr-local.html) is the visual spec. Every screen in it is in scope for v1 except My finances and Payroll, which are v2.

## Roles and permissions
Three roles in v1. Permissions are fixed in code, not editable in the UI.

| Capability | Employee | Manager | Admin |
| :--- | :--- | :--- | :--- |
| Punch in, break, out | Yes | Yes | Yes |
| See own attendance, hours, leave | Yes | Yes | Yes |
| Apply for leave, request correction | Yes | Yes | Yes |
| Post, react, comment in Community | Yes | Yes | Yes |
| See own selfies and locations | Yes | Yes | Yes |
| Live board (team status) | No | Own team | All |
| Approve or reject leave and corrections | No | Own team | All |
| Reports and exports | No | Own team | All |
| View others' selfies and locations | No | Own team | All |
| Post official announcements, pin posts | No | No | Yes |
| Add, edit, offboard employees; set managers | No | No | Yes |
| Company settings (work rules, leave policy, privacy) | No | No | Yes |
| Audit log | No | No | Yes |
| Delete records | No | No | Yes |

A manager sees only employees whose manager field is set to them. An admin sees everyone. One user can hold only one role.

