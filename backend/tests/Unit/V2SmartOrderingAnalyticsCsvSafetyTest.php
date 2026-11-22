<?php

namespace Tests\Unit;

use App\Http\Controllers\Admin\SmartOrderingAnalyticsController;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class V2SmartOrderingAnalyticsCsvSafetyTest extends TestCase
{
    public function test_formula_like_text_is_neutralized_without_changing_numeric_metrics(): void
    {
        $method = new ReflectionMethod(SmartOrderingAnalyticsController::class, 'csvSafeCell');

        foreach ([
            '=HYPERLINK("https://example.invalid")',
            '+SUM(1,1)',
            '-1+2',
            '@SUM(1,1)',
            " \t=1+1",
            "\r\n=1+1",
            "\u{200B}=1+1",
        ] as $unsafe) {
            $actual = $method->invoke(null, $unsafe);
            $this->assertSame("'".$unsafe, $actual);
        }

        foreach (['campaign', 'source-1', 'QR-2026', '', 'ordinary = text'] as $safe) {
            $this->assertSame($safe, $method->invoke(null, $safe));
        }

        foreach ([0, 12, -3, 1.25, null] as $metric) {
            $this->assertSame($metric, $method->invoke(null, $metric));
        }
    }

    public function test_spreadsheet_export_keeps_formula_payload_as_quoted_text_cell(): void
    {
        $method = new ReflectionMethod(SmartOrderingAnalyticsController::class, 'csvSafeCell');
        $buffer = fopen('php://temp', 'w+b');
        $this->assertNotFalse($buffer);

        fputcsv($buffer, [
            $method->invoke(null, '=1+1'),
            $method->invoke(null, 'normal-source'),
            $method->invoke(null, 42),
        ]);
        rewind($buffer);
        $cells = fgetcsv($buffer);
        fclose($buffer);

        $this->assertSame(["'=1+1", 'normal-source', '42'], $cells);
    }
}
