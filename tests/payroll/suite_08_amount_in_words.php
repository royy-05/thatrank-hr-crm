<?php
// tests/payroll/suite_08_amount_in_words.php

namespace Tests\Payroll;

use App\Services\PayrollService;

class Suite08AmountInWords {
    public static function run(): void {
        echo "\n--- Suite 8: Amount in Words (Indian Format) ---\n";

        // 1. Zero
        $res0 = PayrollService::numberToWordsIndian(0);
        TestHelper::assertEquals("Zero Rupees Only", $res0, "0 converts to 'Zero Rupees Only'");

        // 2. One
        $res1 = PayrollService::numberToWordsIndian(1);
        TestHelper::assertEquals("One Rupee Only", $res1, "1 converts to 'One Rupee Only'");

        // 3. 99
        $res99 = PayrollService::numberToWordsIndian(99);
        TestHelper::assertEquals("Ninety-Nine Rupees Only", $res99, "99 converts to 'Ninety-Nine Rupees Only'");

        // 4. 1,000
        $res1000 = PayrollService::numberToWordsIndian(1000);
        TestHelper::assertEquals("One Thousand Rupees Only", $res1000, "1000 converts to 'One Thousand Rupees Only'");

        // 5. 100,000 (1 Lakh)
        $res100000 = PayrollService::numberToWordsIndian(100000);
        TestHelper::assertEquals("One Lakh Rupees Only", $res100000, "100000 converts to 'One Lakh Rupees Only'");

        // 6. 12,345,678 (1 Crore 23 Lakh 45 Thousand 6 Hundred 78)
        $resBig = PayrollService::numberToWordsIndian(12345678);
        $expectedBig = "One Crore Twenty-Three Lakh Forty-Five Thousand Six Hundred Seventy-Eight Rupees Only";
        TestHelper::assertEquals($expectedBig, $resBig, "12345678 converts to '{$expectedBig}'");

        // 7. Rupees with paise (1000.50)
        $resPaise = PayrollService::numberToWordsIndian(1000.50);
        TestHelper::assertEquals("One Thousand Rupees and Fifty Paise Only", $resPaise, "1000.50 converts to 'One Thousand Rupees and Fifty Paise Only'");

        // 8. Paise only (0.50)
        $resPaiseOnly = PayrollService::numberToWordsIndian(0.50);
        TestHelper::assertEquals("Fifty Paise Only", $resPaiseOnly, "0.50 converts to 'Fifty Paise Only'");

        // 9. Single rupee with paise (1.25)
        $resOnePaise = PayrollService::numberToWordsIndian(1.25);
        TestHelper::assertEquals("One Rupee and Twenty-Five Paise Only", $resOnePaise, "1.25 converts to 'One Rupee and Twenty-Five Paise Only'");
    }
}

