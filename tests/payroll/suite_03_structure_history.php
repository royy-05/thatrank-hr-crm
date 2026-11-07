<?php
// tests/payroll/suite_03_structure_history.php

namespace Tests\Payroll;

use App\Config\Database;
use App\Models\SalaryStructure;

class Suite03StructureHistory {
    public static function run(): void {
        echo "\n--- Suite 3: Structure History & Effective Dates ---\n";
        $db = Database::getConnection();
        $db->beginTransaction();

        try {
            $userId = TestHelper::createTestUser('History Tester', 'employee', '2025-01-01');

            // Insert Structure 1: effective 2026-01-01 (Basic 25000)
            SalaryStructure::setStructure($userId, '2026-01-01', ['1' => 25000.00], 1);

            // Insert Structure 2: effective 2026-06-01 (Basic 35000)
            SalaryStructure::setStructure($userId, '2026-06-01', ['1' => 35000.00], 1);

            // Insert Structure 3: effective 2026-10-01 (Basic 45000)
            SalaryStructure::setStructure($userId, '2026-10-01', ['1' => 45000.00], 1);

            // 1. As of March 2026 (2026-03-31) -> Should select Structure 1
            $structMarch = SalaryStructure::getForUser($userId, '2026-03-31');
            TestHelper::assert($structMarch !== null, 'March query returned a structure');
            TestHelper::assertEquals('2026-01-01', $structMarch['effective_from'], 'March resolves to 2026-01-01 structure');
            TestHelper::assertEquals(25000.00, (float)$structMarch['component_values']['1'], 'March basic is 25000.00');

            // 2. As of July 2026 (2026-07-31) -> Should select Structure 2
            $structJuly = SalaryStructure::getForUser($userId, '2026-07-31');
            TestHelper::assert($structJuly !== null, 'July query returned a structure');
            TestHelper::assertEquals('2026-06-01', $structJuly['effective_from'], 'July resolves to 2026-06-01 structure');
            TestHelper::assertEquals(35000.00, (float)$structJuly['component_values']['1'], 'July basic is 35000.00');

            // 3. As of November 2026 (2026-11-30) -> Should select Structure 3
            $structNov = SalaryStructure::getForUser($userId, '2026-11-30');
            TestHelper::assert($structNov !== null, 'November query returned a structure');
            TestHelper::assertEquals('2026-10-01', $structNov['effective_from'], 'November resolves to 2026-10-01 structure');
            TestHelper::assertEquals(45000.00, (float)$structNov['component_values']['1'], 'November basic is 45000.00');

            // 4. Test History ordering
            $history = SalaryStructure::getHistory($userId);
            TestHelper::assertEquals(3, count($history), 'History returns exactly 3 records');
            TestHelper::assertEquals('2026-10-01', $history[0]['effective_from'], 'Most recent structure is first in history');
            TestHelper::assertEquals('2026-06-01', $history[1]['effective_from'], 'Second structure is second in history');
            TestHelper::assertEquals('2026-01-01', $history[2]['effective_from'], 'Oldest structure is last in history');

        } finally {
            $db->rollBack();
        }
    }
}

