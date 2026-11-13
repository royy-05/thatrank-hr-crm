<?php
// app/controllers/EmployeeController.php

namespace App\Controllers;

use App\Middleware\AuthMiddleware;
use App\Models\User;

class EmployeeController {
    public static function index(): void {
        $admin = AuthMiddleware::requireAdmin();
        $employees = User::getAll($admin);

        jsonResponse([
            'success'   => true,
            'employees' => $employees,
            'count'     => count($employees),
        ]);
    }

    public static function get(): void {
        $viewer = AuthMiddleware::requireLogin();
        $id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
        if ($id <= 0) {
            jsonResponse(['success' => false, 'error' => 'Invalid employee ID.'], 400);
        }

        // Only admin, direct manager, or employee themselves can view
        if ($viewer['role'] !== 'admin' && (int)$viewer['id'] !== $id) {
            $emp = User::findById($id);
            if (!$emp || (int)$emp['manager_id'] !== (int)$viewer['id']) {
                jsonResponse(['success' => false, 'error' => 'Access denied.'], 403);
            }
        }

        $employee = User::findById($id);
        if (!$employee) {
            jsonResponse(['success' => false, 'error' => 'Employee not found.'], 404);
        }
        unset($employee['password_hash']);
        jsonResponse(['success' => true, 'employee' => $employee]);
    }

    public static function create(): void {
        AuthMiddleware::requireCsrf();
        $admin = AuthMiddleware::requireAdmin();

        $name = trim($_POST['name'] ?? '');
        $email = trim($_POST['email'] ?? '');

        if ($name === '' || $email === '') {
            jsonResponse(['success' => false, 'error' => 'Name and email are required.'], 400);
        }

        if (User::findByEmail($email)) {
            jsonResponse(['success' => false, 'error' => 'An employee with this email already exists.'], 400);
        }

        $tempPassword = !empty($_POST['password']) && strlen(trim($_POST['password'])) >= 1
            ? trim($_POST['password'])
            : 'tr_' . substr(bin2hex(random_bytes(4)), 0, 8);
        $_POST['password'] = $tempPassword;

        $id = User::create($_POST, (int)$admin['id']);

        jsonResponse([
            'success'       => true,
            'id'            => $id,
            'temp_password' => $tempPassword,
            'message'       => 'Employee added successfully.'
        ]);
    }

    public static function update(): void {
        AuthMiddleware::requireCsrf();
        $admin = AuthMiddleware::requireAdmin();

        $id = (int)($_POST['id'] ?? 0);
        if (!$id) {
            jsonResponse(['success' => false, 'error' => 'Invalid employee ID.'], 400);
        }

        $res = User::update($id, $_POST, (int)$admin['id']);
        if (!$res) {
            jsonResponse(['success' => false, 'error' => 'Failed to update employee.'], 400);
        }

        jsonResponse(['success' => true, 'message' => 'Employee updated successfully.']);
    }

    public static function offboard(): void {
        AuthMiddleware::requireCsrf();
        $admin = AuthMiddleware::requireAdmin();

        $id = (int)($_POST['id'] ?? 0);
        if (!$id) {
            jsonResponse(['success' => false, 'error' => 'Invalid employee ID.'], 400);
        }

        if ($id === (int)$admin['id']) {
            jsonResponse(['success' => false, 'error' => 'Cannot offboard yourself.'], 400);
        }

        $res = User::offboard($id, (int)$admin['id']);
        if (!$res) {
            jsonResponse(['success' => false, 'error' => 'Failed to offboard employee.'], 400);
        }

        jsonResponse(['success' => true, 'message' => 'Employee offboarded successfully.']);
    }

    public static function importCsv(): void {
        AuthMiddleware::requireCsrf();
        $admin = AuthMiddleware::requireAdmin();

        $rows = [];
        if (!empty($_FILES['csv_file']['tmp_name'])) {
            $handle = fopen($_FILES['csv_file']['tmp_name'], 'r');
            if ($handle) {
                while (($data = fgetcsv($handle, 1000, ',')) !== false) {
                    $rows[] = $data;
                }
                fclose($handle);
            }
        } elseif (!empty($_POST['csv_data'])) {
            $lines = explode("\n", trim($_POST['csv_data']));
            foreach ($lines as $line) {
                if (trim($line) !== '') {
                    $rows[] = str_getcsv($line);
                }
            }
        }

        if (empty($rows)) {
            jsonResponse(['success' => false, 'error' => 'Please provide a valid CSV file or data.'], 400);
        }

        // Process header row
        $rawHeaders = array_shift($rows);
        $headers = array_map(function($h) {
            return strtolower(trim(str_replace([' ', '_', '-'], '', $h)));
        }, $rawHeaders);

        $nameIdx = array_search('name', $headers);
        $emailIdx = array_search('email', $headers);
        $phoneIdx = array_search('phone', $headers);
        $roleIdx = array_search('role', $headers);
        $teamIdx = array_search('team', $headers);
        $mgrIdx = array_search('manageremail', $headers);
        if ($mgrIdx === false) $mgrIdx = array_search('manager', $headers);
        $joinIdx = array_search('joiningdate', $headers);
        if ($joinIdx === false) $joinIdx = array_search('joineddate', $headers);
        $bdayIdx = array_search('birthday', $headers);
        if ($bdayIdx === false) $bdayIdx = array_search('dateofbirth', $headers);
        $camIdx = array_search('requirecamera', $headers);
        if ($camIdx === false) $camIdx = array_search('camera', $headers);
        if ($camIdx === false) $camIdx = array_search('requirecam', $headers);
        $locIdx = array_search('requirelocation', $headers);
        if ($locIdx === false) $locIdx = array_search('location', $headers);
        if ($locIdx === false) $locIdx = array_search('requireloc', $headers);

        if ($nameIdx === false || $emailIdx === false) {
            jsonResponse(['success' => false, 'error' => 'CSV must include name and email columns.'], 400);
        }

        $imported = [];
        $rejected = [];
        $seenEmails = [];
        $rowNum = 1;

        foreach ($rows as $r) {
            $rowNum++;
            if (empty(array_filter($r))) continue;

            $name = isset($r[$nameIdx]) ? trim($r[$nameIdx]) : '';
            $email = isset($r[$emailIdx]) ? trim($r[$emailIdx]) : '';
            $phone = ($phoneIdx !== false && isset($r[$phoneIdx])) ? trim($r[$phoneIdx]) : null;
            $role = ($roleIdx !== false && isset($r[$roleIdx])) ? strtolower(trim($r[$roleIdx])) : 'employee';
            $teamName = ($teamIdx !== false && isset($r[$teamIdx])) ? trim($r[$teamIdx]) : 'Product';
            $mgrEmail = ($mgrIdx !== false && isset($r[$mgrIdx])) ? trim($r[$mgrIdx]) : null;
            $joinDate = ($joinIdx !== false && isset($r[$joinIdx])) ? trim($r[$joinIdx]) : date('Y-m-d');
            $birthday = ($bdayIdx !== false && isset($r[$bdayIdx])) ? trim($r[$bdayIdx]) : null;

            // Validate name
            if ($name === '') {
                $rejected[] = ['row' => $rowNum, 'email' => $email, 'reason' => 'Name is missing.'];
                continue;
            }

            // Validate email
            if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $rejected[] = ['row' => $rowNum, 'email' => $email, 'reason' => 'Invalid email address.'];
                continue;
            }

            if (isset($seenEmails[$email])) {
                $rejected[] = ['row' => $rowNum, 'email' => $email, 'reason' => 'Duplicate email in CSV.'];
                continue;
            }
            $seenEmails[$email] = true;

            if (User::findByEmail($email)) {
                $rejected[] = ['row' => $rowNum, 'email' => $email, 'reason' => 'Email already registered in system.'];
                continue;
            }

            // Validate role
            if (!in_array($role, ['employee', 'manager', 'admin'], true)) {
                $role = 'employee';
            }

            // Lookup or create team
            $teamId = null;
            if ($teamName !== '') {
                $db = \App\Config\Database::getConnection();
                $tStmt = $db->prepare("SELECT id FROM teams WHERE name = :name LIMIT 1");
                $tStmt->execute([':name' => $teamName]);
                $tRow = $tStmt->fetch();
                if ($tRow) {
                    $teamId = (int)$tRow['id'];
                } else {
                    $teamId = \App\Models\Team::create($teamName, null, (int)$admin['id']);
                }
            }

            // Lookup manager ID
            $managerId = null;
            if (!empty($mgrEmail)) {
                $mgr = User::findByEmail($mgrEmail);
                if ($mgr) {
                    $managerId = (int)$mgr['id'];
                }
            }

            // Clean dates
            $validJoin = strtotime($joinDate) ? date('Y-m-d', strtotime($joinDate)) : date('Y-m-d');
            $validBday = (!empty($birthday) && strtotime($birthday)) ? date('Y-m-d', strtotime($birthday)) : null;

            $requireCamera = 1;
            if ($camIdx !== false && isset($r[$camIdx]) && trim($r[$camIdx]) !== '') {
                $rawCam = strtolower(trim($r[$camIdx]));
                $requireCamera = in_array($rawCam, ['0', 'false', 'no'], true) ? 0 : 1;
            }

            $requireLocation = 1;
            if ($locIdx !== false && isset($r[$locIdx]) && trim($r[$locIdx]) !== '') {
                $rawLoc = strtolower(trim($r[$locIdx]));
                $requireLocation = in_array($rawLoc, ['0', 'false', 'no'], true) ? 0 : 1;
            }

            try {
                $newId = User::create([
                    'name'             => $name,
                    'email'            => $email,
                    'phone'            => $phone,
                    'role'             => $role,
                    'department'       => $teamName ?: 'Product',
                    'team_id'          => $teamId,
                    'manager_id'       => $managerId,
                    'joined_date'      => $validJoin,
                    'date_of_birth'    => $validBday,
                    'work_anniversary' => $validJoin,
                    'require_camera'   => $requireCamera,
                    'require_location' => $requireLocation,
                    'password'         => 'password123',
                ], (int)$admin['id']);

                $imported[] = [
                    'row'   => $rowNum,
                    'id'    => $newId,
                    'name'  => $name,
                    'email' => $email,
                ];
            } catch (\Throwable $e) {
                $rejected[] = ['row' => $rowNum, 'email' => $email, 'reason' => $e->getMessage()];
            }
        }

        \App\Services\AuditService::log((int)$admin['id'], 'employee_csv_import', 'employee', null, null, [
            'imported_count' => count($imported),
            'rejected_count' => count($rejected),
        ]);

        jsonResponse([
            'success'        => true,
            'imported_count' => count($imported),
            'rejected_count' => count($rejected),
            'imported'       => $imported,
            'rejected'       => $rejected,
            'message'        => 'CSV import processed: ' . count($imported) . ' added, ' . count($rejected) . ' rejected.'
        ]);
    }
}

