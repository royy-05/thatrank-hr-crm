<?php
// app/services/MailService.php

namespace App\Services;

use App\Config\Database;
use PDO;
use Throwable;

class MailService {
    /**
     * Send email via direct SSL socket connection to SMTP (Hostinger / configured server)
     */
    public static function send(array|string $to, string $subject, string $htmlBody): bool {
        $host = $_ENV['SMTP_HOST'] ?? 'smtp.hostinger.com';
        $port = (int)($_ENV['SMTP_PORT'] ?? 465);
        $user = $_ENV['SMTP_USER'] ?? 'noreply@thatrank.com';
        $pass = $_ENV['SMTP_PASS'] ?? '';
        $from = $_ENV['SMTP_FROM'] ?? 'noreply@thatrank.com';
        $fromName = $_ENV['SMTP_NAME'] ?? 'ThatRank HR';

        $recipients = is_array($to) ? $to : [$to];
        $validRecipients = [];
        foreach ($recipients as $r) {
            $r = trim((string)$r);
            if (filter_var($r, FILTER_VALIDATE_EMAIL)) {
                $validRecipients[] = $r;
            }
        }
        $validRecipients = array_values(array_unique($validRecipients));

        if (empty($validRecipients)) {
            error_log("[MailService] No valid recipient addresses provided.");
            return false;
        }

        try {
            $remoteAddress = ($port === 465 ? 'ssl://' : '') . $host;
            $context = stream_context_create([
                'ssl' => [
                    'verify_peer'       => false,
                    'verify_peer_name'  => false,
                    'allow_self_signed' => true,
                ]
            ]);

            $socket = @stream_socket_client($remoteAddress . ':' . $port, $errno, $errstr, 8, STREAM_CLIENT_CONNECT, $context);
            if (!$socket) {
                error_log("[MailService] Socket connection failed to {$remoteAddress}:{$port} - {$errstr} ({$errno})");
                return false;
            }

            stream_set_timeout($socket, 8);

            $read = function () use ($socket) {
                $response = '';
                while ($line = fgets($socket, 515)) {
                    $response .= $line;
                    if (substr($line, 3, 1) === ' ') {
                        break;
                    }
                }
                return $response;
            };

            $sendCmd = function (string $cmd) use ($socket, $read) {
                fputs($socket, $cmd . "\r\n");
                return $read();
            };

            $greeting = $read();
            if (strpos($greeting, '220') !== 0) {
                error_log("[MailService] Invalid greeting: {$greeting}");
                fclose($socket);
                return false;
            }

            $sendCmd("EHLO localhost");

            $authResp = $sendCmd("AUTH LOGIN");
            if (strpos($authResp, '334') !== 0) {
                error_log("[MailService] AUTH LOGIN rejected: {$authResp}");
                fclose($socket);
                return false;
            }

            $sendCmd(base64_encode($user));
            $passResp = $sendCmd(base64_encode($pass));
            if (strpos($passResp, '235') !== 0) {
                error_log("[MailService] SMTP authentication failed: {$passResp}");
                fclose($socket);
                return false;
            }

            $mailFromResp = $sendCmd("MAIL FROM:<{$from}>");
            if (strpos($mailFromResp, '250') !== 0) {
                error_log("[MailService] MAIL FROM rejected: {$mailFromResp}");
                fclose($socket);
                return false;
            }

            $accepted = 0;
            foreach ($validRecipients as $rcpt) {
                $rcptResp = $sendCmd("RCPT TO:<{$rcpt}>");
                if (strpos($rcptResp, '250') === 0 || strpos($rcptResp, '251') === 0) {
                    $accepted++;
                } else {
                    error_log("[MailService] RCPT TO rejected for {$rcpt}: {$rcptResp}");
                }
            }

            if ($accepted === 0) {
                $sendCmd("QUIT");
                fclose($socket);
                return false;
            }

            $dataResp = $sendCmd("DATA");
            if (strpos($dataResp, '354') !== 0) {
                error_log("[MailService] DATA command rejected: {$dataResp}");
                fclose($socket);
                return false;
            }

            $dateHeader = date('r');
            $encodedSubject = '=?UTF-8?B?' . base64_encode($subject) . '?=';
            $encodedFromName = '=?UTF-8?B?' . base64_encode($fromName) . '?=';
            $toHeader = implode(', ', $validRecipients);

            $headers = [
                "Date: {$dateHeader}",
                "From: {$encodedFromName} <{$from}>",
                "To: {$toHeader}",
                "Subject: {$encodedSubject}",
                "MIME-Version: 1.0",
                "Content-Type: text/html; charset=UTF-8",
                "Content-Transfer-Encoding: base64",
                "X-Mailer: ThatRank HR Notification System",
            ];

            $payload = implode("\r\n", $headers) . "\r\n\r\n" . chunk_split(base64_encode($htmlBody)) . "\r\n.\r\n";
            fputs($socket, $payload);
            $finalResp = $read();

            $sendCmd("QUIT");
            fclose($socket);

            return (strpos($finalResp, '250') === 0);
        } catch (Throwable $t) {
            error_log("[MailService] Exception during mail send: " . $t->getMessage());
            return false;
        }
    }

    /**
     * Resolve recipients (team manager and active admins), excluding the triggering user
     */
    public static function getNotificationRecipients(int $userId): array {
        $db = Database::getConnection();

        // 1. Get the acting user
        $uStmt = $db->prepare("SELECT id, name, email, role, manager_id FROM users WHERE id = :uid LIMIT 1");
        $uStmt->execute([':uid' => $userId]);
        $actor = $uStmt->fetch(PDO::FETCH_ASSOC);
        if (!$actor) {
            return [];
        }

        $recipients = [];

        // 2. If actor has a manager and actor is not that manager, add manager
        if (!empty($actor['manager_id']) && (int)$actor['manager_id'] !== (int)$userId) {
            $mStmt = $db->prepare("SELECT email FROM users WHERE id = :mid AND status = 'active' LIMIT 1");
            $mStmt->execute([':mid' => $actor['manager_id']]);
            $mgrEmail = $mStmt->fetchColumn();
            if (!empty($mgrEmail)) {
                $recipients[] = strtolower(trim($mgrEmail));
            }
        }

        // 3. Add all active admins from database
        $aStmt = $db->query("SELECT email FROM users WHERE role = 'admin' AND status = 'active'");
        while ($adminEmail = $aStmt->fetchColumn()) {
            if (!empty($adminEmail)) {
                $recipients[] = strtolower(trim($adminEmail));
            }
        }

        // 4. Also support explicit ADMIN_EMAIL configured in .env (single or comma-separated)
        if (!empty($_ENV['ADMIN_EMAIL'])) {
            $envEmails = explode(',', $_ENV['ADMIN_EMAIL']);
            foreach ($envEmails as $envMail) {
                $clean = strtolower(trim($envMail));
                if (filter_var($clean, FILTER_VALIDATE_EMAIL)) {
                    $recipients[] = $clean;
                }
            }
        }

        // 5. Exclude the actor's own email so they don't get alerted for their own action
        $actorEmail = strtolower(trim($actor['email'] ?? ''));
        $recipients = array_filter(array_unique($recipients), function ($email) use ($actorEmail) {
            return $email !== '' && $email !== $actorEmail;
        });

        // 5. If recipients is still empty (e.g. sole admin acting), fallback to admin email
        if (empty($recipients) && !empty($actorEmail) && $actor['role'] === 'admin') {
            $recipients[] = $actorEmail;
        }

        return array_values($recipients);
    }

    /**
     * Build branded ThatRank HR HTML notification email
     */
    public static function buildHtmlTemplate(string $badgeText, string $badgeColor, string $headline, array $rows, ?string $actionUrl = null, string $actionLabel = 'Open ThatRank HR'): string {
        $appUrl = rtrim($_ENV['APP_URL'] ?? 'http://localhost/thatrank-hr-software', '/');
        if (!$actionUrl) {
            $actionUrl = $appUrl;
        }

        $rowsHtml = '';
        foreach ($rows as $label => $val) {
            $labelSafe = htmlspecialchars($label);
            $valSafe = nl2br(htmlspecialchars((string)$val));
            $rowsHtml .= "
                <tr>
                    <td style=\"padding:10px 14px;border-bottom:1px solid #eef2f6;color:#64748b;font-size:13px;font-weight:600;width:140px;vertical-align:top;\">{$labelSafe}</td>
                    <td style=\"padding:10px 14px;border-bottom:1px solid #eef2f6;color:#0f172a;font-size:13.5px;font-weight:500;\">{$valSafe}</td>
                </tr>";
        }

        return "
<!DOCTYPE html>
<html>
<head>
<meta charset=\"utf-8\">
<meta name=\"viewport\" content=\"width=device-width, initial-scale=1.0\">
<title>{$headline}</title>
</head>
<body style=\"margin:0;padding:0;background-color:#f8fafc;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;color:#0f172a;-webkit-font-smoothing:antialiased;\">
  <table width=\"100%\" cellpadding=\"0\" cellspacing=\"0\" style=\"background-color:#f8fafc;padding:32px 16px;\">
    <tr>
      <td align=\"center\">
        <table width=\"100%\" cellpadding=\"0\" cellspacing=\"0\" style=\"max-width:580px;background-color:#ffffff;border-radius:12px;border:1px solid #e2e8f0;overflow:hidden;box-shadow:0 4px 12px rgba(15,23,42,0.04);\">
          
          <!-- Header -->
          <tr>
            <td style=\"padding:24px 28px;background:linear-gradient(135deg,#1d4ed8 0%,#2563eb 100%);color:#ffffff;\">
              <table width=\"100%\" cellpadding=\"0\" cellspacing=\"0\">
                <tr>
                  <td>
                    <div style=\"font-size:18px;font-weight:800;letter-spacing:-0.02em;color:#ffffff;\">ThatRank HR</div>
                    <div style=\"font-size:12px;color:#bfdbfe;margin-top:2px;font-weight:500;\">Digital Marketing Solution</div>
                  </td>
                  <td align=\"right\">
                    <span style=\"display:inline-block;padding:4px 10px;background:rgba(255,255,255,0.18);color:#ffffff;border-radius:999px;font-size:11px;font-weight:700;letter-spacing:0.04em;text-transform:uppercase;\">{$badgeText}</span>
                  </td>
                </tr>
              </table>
            </td>
          </tr>

          <!-- Main Content -->
          <tr>
            <td style=\"padding:28px;\">
              <h2 style=\"margin:0 0 16px;font-size:18px;font-weight:700;color:#0f172a;line-height:1.4;\">{$headline}</h2>
              <table width=\"100%\" cellpadding=\"0\" cellspacing=\"0\" style=\"background:#f8fafc;border-radius:8px;border:1px solid #e2e8f0;border-collapse:collapse;margin-bottom:24px;\">
                {$rowsHtml}
              </table>

              <div style=\"text-align:center;margin-top:24px;\">
                <a href=\"{$actionUrl}\" style=\"display:inline-block;padding:12px 24px;background-color:#2563eb;color:#ffffff;text-decoration:none;font-size:13.5px;font-weight:600;border-radius:8px;box-shadow:0 2px 6px rgba(37,99,235,0.25);\">{$actionLabel}</a>
              </div>
            </td>
          </tr>

          <!-- Footer -->
          <tr>
            <td style=\"padding:16px 28px;background:#f8fafc;border-top:1px solid #e2e8f0;text-align:center;font-size:12px;color:#94a3b8;\">
              This is an automated notification from ThatRank HR. Please do not reply directly to this email.
            </td>
          </tr>

        </table>
      </td>
    </tr>
  </table>
</body>
</html>";
    }

    /**
     * Send Leave Request alert to Team Manager and Admin(s)
     */
    public static function notifyLeaveRequest(int $userId, array $leaveData): bool {
        $db = Database::getConnection();
        $uStmt = $db->prepare("SELECT name, email, department, job_title FROM users WHERE id = :uid LIMIT 1");
        $uStmt->execute([':uid' => $userId]);
        $user = $uStmt->fetch(PDO::FETCH_ASSOC) ?: ['name' => 'Team Member', 'department' => 'Staff'];

        $recipients = self::getNotificationRecipients($userId);
        if (empty($recipients)) {
            return false;
        }

        $leaveName = ucfirst(str_replace('_', ' ', $leaveData['leave_type'] ?? 'Leave'));
        $subject = "[ThatRank HR] Leave Request: {$user['name']} ({$leaveName})";
        $headline = "{$user['name']} has submitted a new leave request";

        $rows = [
            'Employee'   => $user['name'] . ' (' . ($user['job_title'] ?: $user['department'] ?: 'Employee') . ')',
            'Department' => $user['department'] ?: 'General',
            'Leave Type' => $leaveName,
            'Dates'      => ($leaveData['start_date'] ?? date('Y-m-d')) . ' to ' . ($leaveData['end_date'] ?? date('Y-m-d')),
            'Duration'   => ($leaveData['days_count'] ?? 1) . ' day(s) (' . ucfirst(str_replace('_', ' ', $leaveData['duration_type'] ?? 'full_day')) . ')',
            'Reason'     => $leaveData['reason'] ?? 'Not specified',
            'Submitted'  => date('l, d M Y at h:i A'),
        ];

        $html = self::buildHtmlTemplate('LEAVE REQUEST', '#2563eb', $headline, $rows);
        return self::send($recipients, $subject, $html);
    }

    /**
     * Send Late Arrival alert to Team Manager and Admin(s)
     */
    public static function notifyLateArrival(int $userId, array $punchData): bool {
        $db = Database::getConnection();
        $uStmt = $db->prepare("SELECT name, email, department, job_title FROM users WHERE id = :uid LIMIT 1");
        $uStmt->execute([':uid' => $userId]);
        $user = $uStmt->fetch(PDO::FETCH_ASSOC) ?: ['name' => 'Team Member', 'department' => 'Staff'];

        $recipients = self::getNotificationRecipients($userId);
        if (empty($recipients)) {
            return false;
        }

        $lateMins = (int)($punchData['late_minutes'] ?? 0);
        $subject = "[ThatRank HR] Late Arrival: {$user['name']} (" . ($user['department'] ?: 'Team') . ")";
        $headline = "{$user['name']} clocked in late today";

        $rows = [
            'Employee'      => $user['name'] . ' (' . ($user['job_title'] ?: $user['department'] ?: 'Employee') . ')',
            'Department'    => $user['department'] ?: 'General',
            'Clock-in Time' => $punchData['clock_in_time'] ?? date('h:i:s A'),
            'Minutes Late'  => $lateMins > 0 ? "{$lateMins} minutes late" : 'Late arrival marked',
            'Date'          => date('l, d M Y'),
        ];

        $html = self::buildHtmlTemplate('LATE ARRIVAL', '#ef4444', $headline, $rows);
        return self::send($recipients, $subject, $html);
    }

    /**
     * Send Break Started alert to Team Manager and Admin(s)
     */
    public static function notifyBreakStarted(int $userId, array $breakData): bool {
        $db = Database::getConnection();
        $uStmt = $db->prepare("SELECT name, email, department, job_title FROM users WHERE id = :uid LIMIT 1");
        $uStmt->execute([':uid' => $userId]);
        $user = $uStmt->fetch(PDO::FETCH_ASSOC) ?: ['name' => 'Team Member', 'department' => 'Staff'];

        $recipients = self::getNotificationRecipients($userId);
        if (empty($recipients)) {
            return false;
        }

        $subject = "[ThatRank HR] Break Started: {$user['name']} (" . ($user['department'] ?: 'Team') . ")";
        $headline = "{$user['name']} has started a break";

        $rows = [
            'Employee'   => $user['name'] . ' (' . ($user['job_title'] ?: $user['department'] ?: 'Employee') . ')',
            'Department' => $user['department'] ?: 'General',
            'Break Time' => $breakData['break_time'] ?? date('h:i:s A'),
            'Date'       => date('l, d M Y'),
        ];

        $html = self::buildHtmlTemplate('BREAK STARTED', '#f59e0b', $headline, $rows);
        return self::send($recipients, $subject, $html);
    }

    /**
     * Send Leave Decision alert to the employee when Admin/Manager approves or rejects
     */
    public static function notifyLeaveDecision(int $requestId, string $status, int $actorId, ?string $reason = null): bool {
        $db = Database::getConnection();
        $stmt = $db->prepare("
            SELECT lr.*, u.name as employee_name, u.email as employee_email, a.name as approver_name
            FROM leave_requests lr
            JOIN users u ON lr.user_id = u.id
            LEFT JOIN users a ON a.id = :aid
            WHERE lr.id = :id
            LIMIT 1
        ");
        $stmt->execute([':id' => $requestId, ':aid' => $actorId]);
        $req = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$req || empty($req['employee_email'])) {
            return false;
        }

        $isApproved = ($status === 'approved');
        $badgeText = $isApproved ? 'LEAVE APPROVED' : 'LEAVE REJECTED';
        $badgeColor = $isApproved ? '#16a34a' : '#dc2626';
        $leaveType = ucfirst(str_replace('_', ' ', $req['leave_type']));
        $subject = "[ThatRank HR] Your Leave Request has been " . ucfirst($status);
        $headline = "Your leave request for {$leaveType} has been " . strtolower($status);

        $rows = [
            'Leave Type' => $leaveType,
            'Dates'      => $req['start_date'] . ' to ' . $req['end_date'],
            'Duration'   => $req['days_count'] . ' day(s)',
            'Status'     => ucfirst($status),
            'Decided By' => $req['approver_name'] ?: 'Management',
            'Date'       => date('l, d M Y'),
        ];
        if (!empty($reason)) {
            $rows['Note'] = $reason;
        }

        $html = self::buildHtmlTemplate($badgeText, $badgeColor, $headline, $rows);
        return self::send($req['employee_email'], $subject, $html);
    }
}

