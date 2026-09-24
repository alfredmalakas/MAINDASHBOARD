<?php
/**
 * Payroll calculation helpers.
 * BIR withholding tax table: Annex E, RR No. 11-2018,
 * effective January 1, 2023 and onwards.
 */
function payroll_period_type(string $start, string $end): string
{
    $days = max(1, (int)((new DateTime($end))->diff(new DateTime($start))->days + 1));
    return $days <= 16 ? 'semi-monthly' : 'monthly';
}

function bir_withholding_tax(float $taxableIncome, string $periodType = 'monthly'): float
{
    $x = max(0.0, $taxableIncome);

    if ($periodType === 'semi-monthly') {
        if ($x <= 10417) return 0.0;
        if ($x <= 16666) return ($x - 10417) * 0.15;
        if ($x <= 33332) return 937.50 + (($x - 16667) * 0.20);
        if ($x <= 83332) return 4270.70 + (($x - 33333) * 0.25);
        if ($x <= 333332) return 16770.70 + (($x - 83333) * 0.30);
        return 91770.70 + (($x - 333333) * 0.35);
    }

    if ($x <= 20833) return 0.0;
    if ($x <= 33332) return ($x - 20833) * 0.15;
    if ($x <= 66666) return 1875.00 + (($x - 33333) * 0.20);
    if ($x <= 166666) return 8541.80 + (($x - 66667) * 0.25);
    if ($x <= 666666) return 33541.80 + (($x - 166667) * 0.30);
    return 183541.80 + (($x - 666667) * 0.35);
}

function payroll_statutory_deductions(float $basicSalary, float $grossPay, bool $semiMonthly): array
{
    $divisor = $semiMonthly ? 2 : 1;

    // SSS employee share: 5% of MSC, capped at PHP 35,000 MSC.
    $msc = min(35000, max(5000, $basicSalary));
    $sss = round(($msc * 0.05) / $divisor, 2);

    // PhilHealth: 5% premium, generally shared equally; employee share = 2.5%.
    $philHealthBase = min(100000, max(10000, $basicSalary));
    $philhealth = round(($philHealthBase * 0.05 * 0.50) / $divisor, 2);

    // Pag-IBIG: employee share 2%, capped at PHP 10,000 fund salary.
    $pagibig = round((min(10000, max(0, $basicSalary)) * 0.02) / $divisor, 2);

    return compact('sss', 'philhealth', 'pagibig');
}
