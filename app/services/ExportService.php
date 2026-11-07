<?php
// app/services/ExportService.php

namespace App\Services;

use Dompdf\Dompdf;
use Dompdf\Options;

class ExportService {
    /**
     * Generate CSV file content and stream to browser.
     */
    public static function streamCsv(string $filename, array $headers, array $rows): void {
        if (!headers_sent()) {
            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="' . $filename . '"');
            header('Pragma: no-cache');
            header('Expires: 0');
        }

        $output = fopen('php://output', 'w');
        // Add BOM for Excel UTF-8 support
        fputs($output, "\xEF\xBB\xBF");
        fputcsv($output, $headers);

        foreach ($rows as $row) {
            fputcsv($output, $row);
        }

        fclose($output);
        exit;
    }

    /**
     * Generate PDF using Dompdf and stream to browser.
     */
    public static function streamPdf(string $filename, string $html): void {
        $options = new Options();
        $options->set('isHtml5ParserEnabled', true);
        $options->set('isRemoteEnabled', false);
        $options->set('defaultFont', 'Helvetica');

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html);
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();

        $dompdf->stream($filename, ['Attachment' => true]);
        exit;
    }

    /**
     * Build clean HTML report template for PDF generation.
     */
    public static function renderReportHtml(string $title, string $period, array $stats, array $headers, array $rows): string {
        $html = '<!DOCTYPE html><html><head><meta charset="utf-8">';
        $html .= '<style>
            body { font-family: Helvetica, Arial, sans-serif; font-size: 11px; color: #0f172a; line-height: 1.4; margin: 20px; }
            .header { border-bottom: 2px solid #2b56f0; padding-bottom: 12px; margin-bottom: 20px; }
            .title { font-size: 20px; font-weight: bold; color: #0d1b3a; }
            .subtitle { font-size: 12px; color: #64748b; margin-top: 4px; }
            .stats { width: 100%; margin-bottom: 20px; border-collapse: collapse; }
            .stats td { padding: 10px; background: #f6f7fa; border: 1px solid #e4e8ef; text-align: center; }
            .stats .num { font-size: 16px; font-weight: bold; color: #2b56f0; margin-top: 4px; }
            table.tbl { width: 100%; border-collapse: collapse; margin-top: 10px; }
            table.tbl th { background: #0d1b3a; color: #ffffff; font-size: 10px; text-transform: uppercase; padding: 8px 10px; text-align: left; }
            table.tbl td { padding: 8px 10px; border-bottom: 1px solid #e4e8ef; font-size: 10.5px; }
            table.tbl tr:nth-child(even) td { background: #f8fafc; }
            .r { text-align: right; }
            .footer { margin-top: 30px; font-size: 9px; color: #94a3b8; text-align: right; border-top: 1px solid #e4e8ef; padding-top: 8px; }
        </style></head><body>';

        $html .= '<div class="header">';
        $html .= '<div class="title">' . htmlspecialchars($title) . '</div>';
        $html .= '<div class="subtitle">' . htmlspecialchars($period) . ' &middot; ThatRank HR Internal Report</div>';
        $html .= '</div>';

        if (!empty($stats)) {
            $html .= '<table class="stats"><tr>';
            foreach ($stats as $k => $v) {
                $html .= '<td><div style="font-size:10px;color:#64748b;">' . htmlspecialchars($k) . '</div><div class="num">' . htmlspecialchars($v) . '</div></td>';
            }
            $html .= '</tr></table>';
        }

        $html .= '<table class="tbl"><thead><tr>';
        foreach ($headers as $h) {
            $align = (in_array(strtolower($h), ['days', 'worked', 'avg per day', 'breaks', 'late', 'overtime', 'gross', 'deductions', 'net pay'])) ? ' class="r"' : '';
            $html .= '<th' . $align . '>' . htmlspecialchars($h) . '</th>';
        }
        $html .= '</tr></thead><tbody>';

        foreach ($rows as $row) {
            $html .= '<tr>';
            foreach ($row as $idx => $cell) {
                $align = (in_array(strtolower($headers[$idx] ?? ''), ['days', 'worked', 'avg per day', 'breaks', 'late', 'overtime', 'gross', 'deductions', 'net pay'])) ? ' class="r"' : '';
                $html .= '<td' . $align . '>' . htmlspecialchars($cell) . '</td>';
            }
            $html .= '</tr>';
        }

        $html .= '</tbody></table>';
        $html .= '<div class="footer">Generated on ' . date('d M Y, h:i A') . ' IST &middot; Confidential ThatRank Internal Document</div>';
        $html .= '</body></html>';

        return $html;
    }
}

