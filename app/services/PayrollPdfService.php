<?php
// app/services/PayrollPdfService.php

namespace App\Services;

use Dompdf\Dompdf;
use Dompdf\Options;
use App\Models\Setting;

class PayrollPdfService {
    /**
     * Generate PDF binary string for a payslip.
     */
    public static function generatePdfBinary(array $payslip, ?array $settings = null): string {
        if ($settings === null) {
            $settings = Setting::getAllAsMap();
        }

        $html = self::renderHtml($payslip, $settings);

        $options = new Options();
        $options->set('isHtml5ParserEnabled', true);
        $options->set('isRemoteEnabled', true);
        $options->set('defaultFont', 'Helvetica');

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html);
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();

        return $dompdf->output();
    }

    /**
     * Stream PDF directly to browser.
     */
    public static function streamPdf(string $filename, array $payslip, ?array $settings = null): void {
        $pdfBinary = self::generatePdfBinary($payslip, $settings);

        if (!headers_sent()) {
            header('Content-Type: application/pdf');
            header('Content-Disposition: attachment; filename="' . $filename . '"');
            header('Content-Length: ' . strlen($pdfBinary));
            header('Cache-Control: private, max-age=0, must-revalidate');
            header('Pragma: public');
        }

        echo $pdfBinary;
        exit;
    }

    /**
     * Render HTML template for the payslip.
     */
    public static function renderHtml(array $payslip, array $settings): string {
        $legalName = htmlspecialchars($settings['company_legal_name'] ?? 'ThatRank Digital Marketing Solutions Pvt. Ltd.');
        $address = nl2br(htmlspecialchars($settings['company_address'] ?? 'Plot 42, Cyber City, Chandigarh 160101, India'));
        $footerText = htmlspecialchars($settings['payslip_footer_text'] ?? 'This is a computer-generated payslip and does not require a physical signature.');
        $currency = htmlspecialchars($settings['currency_symbol'] ?? 'INR ');
        $logo = $settings['payroll_logo'] ?? '';

        $monthName = date('F Y', strtotime(sprintf('%04d-%02d-01', $payslip['year'], $payslip['month'])));
        $empName = htmlspecialchars($payslip['employee_name'] ?? '');
        $empId = 'EMP-' . str_pad((string)$payslip['user_id'], 4, '0', STR_PAD_LEFT);
        $jobTitle = htmlspecialchars($payslip['job_title'] ?? 'Team Member');
        $teamName = htmlspecialchars($payslip['team_name'] ?? 'General');
        $joinedDate = !empty($payslip['joined_date']) ? date('d M Y', strtotime($payslip['joined_date'])) : 'N/A';

        $workingDays = number_format((float)($payslip['working_days'] ?? 0), 1);
        $paidDays = number_format((float)($payslip['paid_days'] ?? 0), 1);
        $lopDays = number_format((float)($payslip['lop_days'] ?? 0), 1);
        $lopAmount = (float)($payslip['lop_amount'] ?? 0);

        $earnings = is_array($payslip['earnings']) ? $payslip['earnings'] : [];
        $deductions = is_array($payslip['deductions']) ? $payslip['deductions'] : [];
        $adjustments = is_array($payslip['adjustments']) ? $payslip['adjustments'] : [];

        // Separate adjustments
        $adjEarnings = [];
        $adjDeductions = [];
        foreach ($adjustments as $adj) {
            if (($adj['type'] ?? 'earning') === 'earning') {
                $adjEarnings[] = $adj;
            } else {
                $adjDeductions[] = $adj;
            }
        }

        $gross = (float)($payslip['gross'] ?? 0);
        $totalDeductions = (float)($payslip['total_deductions'] ?? 0);
        $netPay = (float)($payslip['net_pay'] ?? 0);
        $netInWords = PayrollService::numberToWordsIndian($netPay);

        // Logo image HTML
        $logoHtml = '';
        if (!empty($logo)) {
            $logoHtml = '<img src="' . htmlspecialchars($logo) . '" style="max-height: 48px; max-width: 160px; object-fit: contain;" alt="Logo">';
        }

        ob_start();
?>
<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<title>Payslip - <?= $empName ?> - <?= $monthName ?></title>
<style>
    @page { margin: 24px; }
    body {
        font-family: Helvetica, Arial, sans-serif;
        font-size: 11px;
        color: #0f172a;
        line-height: 1.4;
        margin: 0;
        padding: 10px;
    }
    .header-table {
        width: 100%;
        border-bottom: 2px solid #2563eb;
        padding-bottom: 12px;
        margin-bottom: 14px;
    }
    .company-title {
        font-size: 16px;
        font-weight: bold;
        color: #0f172a;
        margin: 0 0 4px 0;
    }
    .company-addr {
        font-size: 10px;
        color: #64748b;
        line-height: 1.3;
    }
    .slip-badge {
        text-align: right;
    }
    .slip-badge h2 {
        margin: 0;
        font-size: 18px;
        color: #2563eb;
        letter-spacing: 0.5px;
    }
    .slip-badge p {
        margin: 4px 0 0 0;
        font-size: 11px;
        font-weight: bold;
        color: #334155;
    }
    .emp-table {
        width: 100%;
        background: #f8fafc;
        border: 1px solid #e2e8f0;
        border-radius: 4px;
        margin-bottom: 14px;
        border-collapse: collapse;
    }
    .emp-table td {
        padding: 6px 10px;
        font-size: 10.5px;
    }
    .emp-label {
        color: #64748b;
        font-weight: 500;
        width: 15%;
    }
    .emp-val {
        color: #0f172a;
        font-weight: bold;
        width: 35%;
    }
    .breakdown-table {
        width: 100%;
        border-collapse: collapse;
        margin-bottom: 14px;
    }
    .breakdown-table td {
        vertical-align: top;
        padding: 0;
    }
    .sub-table {
        width: 100%;
        border-collapse: collapse;
        border: 1px solid #e2e8f0;
    }
    .sub-table th {
        background: #0f172a;
        color: #ffffff;
        font-size: 10px;
        text-transform: uppercase;
        letter-spacing: 0.5px;
        padding: 6px 8px;
        text-align: left;
    }
    .sub-table th.amt {
        text-align: right;
    }
    .sub-table td {
        padding: 5px 8px;
        border-bottom: 1px solid #f1f5f9;
        font-size: 10.5px;
    }
    .sub-table tr:nth-child(even) td {
        background: #fbfcfe;
    }
    .sub-table .total-row td {
        background: #f1f5f9;
        font-weight: bold;
        border-top: 1px solid #cbd5e1;
        border-bottom: none;
    }
    .net-box {
        width: 100%;
        background: #eff6ff;
        border: 1.5px solid #bfdbfe;
        border-radius: 4px;
        padding: 10px 14px;
        margin-bottom: 14px;
        box-sizing: border-box;
    }
    .net-amount {
        font-size: 18px;
        font-weight: bold;
        color: #1d4ed8;
    }
    .net-words {
        font-size: 10.5px;
        color: #334155;
        margin-top: 4px;
        font-weight: 500;
    }
    .footer {
        margin-top: 20px;
        padding-top: 10px;
        border-top: 1px dashed #cbd5e1;
        font-size: 9.5px;
        color: #64748b;
        text-align: center;
    }
</style>
</head>
<body>

<table class="header-table" cellpadding="0" cellspacing="0">
    <tr>
        <td style="width: 60%; vertical-align: top;">
            <?php if (!empty($logoHtml)): ?>
                <div style="margin-bottom: 6px;"><?= $logoHtml ?></div>
            <?php endif; ?>
            <div class="company-title"><?= $legalName ?></div>
            <div class="company-addr"><?= $address ?></div>
        </td>
        <td class="slip-badge" style="width: 40%; vertical-align: top;">
            <h2>PAYSLIP</h2>
            <p><?= htmlspecialchars($monthName) ?></p>
        </td>
    </tr>
</table>

<table class="emp-table" cellpadding="0" cellspacing="0">
    <tr>
        <td class="emp-label">Employee Name:</td>
        <td class="emp-val"><?= $empName ?></td>
        <td class="emp-label">Employee ID:</td>
        <td class="emp-val"><?= $empId ?></td>
    </tr>
    <tr>
        <td class="emp-label">Designation:</td>
        <td class="emp-val"><?= $jobTitle ?></td>
        <td class="emp-label">Department / Team:</td>
        <td class="emp-val"><?= $teamName ?></td>
    </tr>
    <tr>
        <td class="emp-label">Joining Date:</td>
        <td class="emp-val"><?= $joinedDate ?></td>
        <td class="emp-label">Working / Paid Days:</td>
        <td class="emp-val"><?= $workingDays ?> / <?= $paidDays ?> (LOP: <?= $lopDays ?>)</td>
    </tr>
</table>

<table class="breakdown-table" cellpadding="0" cellspacing="0">
    <tr>
        <td style="width: 49%; padding-right: 1%;">
            <table class="sub-table" cellpadding="0" cellspacing="0">
                <thead>
                    <tr>
                        <th>Earnings</th>
                        <th class="amt">Amount</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($earnings as $item): ?>
                    <tr>
                        <td><?= htmlspecialchars($item['name'] ?? 'Earning') ?></td>
                        <td style="text-align: right;"><?= $currency . ' ' . number_format((float)($item['amount'] ?? 0), 2) ?></td>
                    </tr>
                    <?php endforeach; ?>
                    <?php foreach ($adjEarnings as $adj): ?>
                    <tr style="color: #166534;">
                        <td><?= htmlspecialchars($adj['name'] ?? 'Adjustment') ?> (Adj)</td>
                        <td style="text-align: right;">+ <?= $currency . ' ' . number_format((float)($adj['amount'] ?? 0), 2) ?></td>
                    </tr>
                    <?php endforeach; ?>
                    <tr class="total-row">
                        <td>Gross Earnings</td>
                        <td style="text-align: right;"><?= $currency . ' ' . number_format($gross, 2) ?></td>
                    </tr>
                </tbody>
            </table>
        </td>
        <td style="width: 49%; padding-left: 1%;">
            <table class="sub-table" cellpadding="0" cellspacing="0">
                <thead>
                    <tr>
                        <th>Deductions</th>
                        <th class="amt">Amount</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($deductions as $item): ?>
                    <tr>
                        <td><?= htmlspecialchars($item['name'] ?? 'Deduction') ?></td>
                        <td style="text-align: right;"><?= $currency . ' ' . number_format((float)($item['amount'] ?? 0), 2) ?></td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if ($lopAmount > 0): ?>
                    <tr style="color: #991b1b;">
                        <td>Loss of Pay (LOP <?= $lopDays ?>d)</td>
                        <td style="text-align: right;"><?= $currency . ' ' . number_format($lopAmount, 2) ?></td>
                    </tr>
                    <?php endif; ?>
                    <?php foreach ($adjDeductions as $adj): ?>
                    <tr style="color: #991b1b;">
                        <td><?= htmlspecialchars($adj['name'] ?? 'Adjustment') ?> (Adj)</td>
                        <td style="text-align: right;">+ <?= $currency . ' ' . number_format((float)($adj['amount'] ?? 0), 2) ?></td>
                    </tr>
                    <?php endforeach; ?>
                    <tr class="total-row">
                        <td>Total Deductions</td>
                        <td style="text-align: right;"><?= $currency . ' ' . number_format($totalDeductions + $lopAmount, 2) ?></td>
                    </tr>
                </tbody>
            </table>
        </td>
    </tr>
</table>

<div class="net-box">
    <div style="font-size: 11px; text-transform: uppercase; color: #1e40af; font-weight: bold; letter-spacing: 0.5px;">Net Salary Payable</div>
    <div class="net-amount"><?= $currency . ' ' . number_format($netPay, 2) ?></div>
    <div class="net-words">In Words: <?= htmlspecialchars($netInWords) ?></div>
</div>

<div class="footer">
    <?= $footerText ?>
</div>

</body>
</html>
<?php
        return ob_get_clean();
    }
}

