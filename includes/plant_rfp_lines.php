<?php

if (!function_exists('plantRfpBaseModeName')) {
    function plantRfpBaseModeName(): string
    {
        return 'Standard Operation';
    }
}

if (!function_exists('plantRfpNormalizeTripQty')) {
    function plantRfpNormalizeTripQty($value, int $default = 1): int
    {
        $qty = (int)$value;
        return max(1, $qty > 0 ? $qty : $default);
    }
}

if (!function_exists('plantRfpModeBreakdown')) {
    function plantRfpModeBreakdown(array $sessions): array
    {
        $breakdown = [];
        foreach ($sessions as $session) {
            $modeName = !empty($session['mode_name']) ? (string)$session['mode_name'] : plantRfpBaseModeName();
            if (!isset($breakdown[$modeName])) {
                $breakdown[$modeName] = 0.0;
            }
            $breakdown[$modeName] += (float)($session['hours'] ?? 0);
        }

        return $breakdown;
    }
}

if (!function_exists('plantRfpFlatAddons')) {
    function plantRfpFlatAddons(array $sessions): array
    {
        $addons = [];
        foreach ($sessions as $session) {
            if (empty($session['addons_used'])) {
                continue;
            }
            $decoded = json_decode((string)$session['addons_used'], true);
            if (!is_array($decoded)) {
                continue;
            }
            foreach ($decoded as $addonRow) {
                if (!is_array($addonRow)) {
                    continue;
                }
                $name = trim((string)($addonRow['name'] ?? ''));
                $qty = (int)($addonRow['qty'] ?? 0);
                if ($name === '' || $qty <= 0) {
                    continue;
                }
                if (!isset($addons[$name])) {
                    $addons[$name] = 0;
                }
                $addons[$name] += $qty;
            }
        }

        return $addons;
    }
}

if (!function_exists('plantRfpResolveConfigRate')) {
    function plantRfpResolveConfigRate(?array $cfgs, string $name, string $type, float $fallbackRate): array
    {
        if (!is_array($cfgs)) {
            return ['code' => '', 'rate' => $fallbackRate];
        }

        foreach ($cfgs as $cfg) {
            if (($cfg['name'] ?? null) !== $name || ($cfg['type'] ?? null) !== $type) {
                continue;
            }
            $code = trim((string)($cfg['nom_code'] ?? ''));
            $rate = (float)($cfg['price'] ?? 0);

            return [
                'code' => $code,
                'rate' => $rate > 0 ? $rate : $fallbackRate,
            ];
        }

        return ['code' => '', 'rate' => $fallbackRate];
    }
}

if (!function_exists('plantRfpOptionalModeCharges')) {
    function plantRfpOptionalModeCharges(?array $cfgs, array $sessions, float $fallbackRate): array
    {
        $charges = [];
        if (!is_array($cfgs) || empty($sessions)) {
            return $charges;
        }

        $baseMode = plantRfpBaseModeName();
        foreach (plantRfpModeBreakdown($sessions) as $modeName => $modeHours) {
            if ($modeName === $baseMode) {
                continue;
            }
            $hours = round((float)$modeHours, 2);
            if ($hours <= 0) {
                continue;
            }
            $resolved = plantRfpResolveConfigRate($cfgs, $modeName, 'mode', $fallbackRate);
            if ($resolved['code'] === '') {
                continue;
            }
            $rate = round((float)$resolved['rate'], 4);
            $charges[] = [
                'name' => $modeName,
                'code' => $resolved['code'],
                'rate' => $rate,
                'hours' => $hours,
                'total' => round($hours * $rate, 2),
            ];
        }

        return $charges;
    }
}

if (!function_exists('plantRfpAppendLine')) {
    function plantRfpAppendLine(string $code, string $description, string $qtyLabel, float $rate, float $total): string
    {
        $safeCode = htmlspecialchars($code, ENT_QUOTES, 'UTF-8');
        $safeDesc = htmlspecialchars($description, ENT_QUOTES, 'UTF-8');

        return "<tr>
            <td><b>{$safeCode}</b></td>
            <td>{$safeDesc}</td>
            <td style='text-align: right;'>{$qtyLabel}</td>
            <td style='text-align: right;'>" . number_format($rate, 4) . "</td>
            <td style='text-align: right;'><b>" . number_format($total, 2) . "</b></td>
        </tr>";
    }
}

/**
 * Build invoice table rows and gross subtotal for PDF / email delivery notes.
 *
 * @return array{rows: string, grossSubtotal: float}
 */
if (!function_exists('buildPlantRfpInvoiceTable')) {
    function buildPlantRfpInvoiceTable(array $job, array $sessions, string $jobRef): array
    {
        $pricingType = (string)($job['pricing_type'] ?? 'hourly');
        $minHours = (float)($job['min_hours'] ?? 0);
        $rateFixed = (float)($job['final_rate_fixed'] ?? 0);
        $rateVar = (float)($job['final_rate_var'] ?? 0);
        $rateSetup = (float)($job['final_setup_fee'] ?? 0);
        $hasSetupFee = ((isset($job['apply_setup_fee']) && (int)$job['apply_setup_fee'] === 1) || $rateSetup > 0);

        $cfgs = (($job['has_configurations'] ?? 0) == 1 && !empty($job['configurations']))
            ? json_decode((string)$job['configurations'], true)
            : null;

        $tableRows = '';
        $grossSubtotal = 0.0;
        $refSuffix = "<br><i style='font-size:11px; color:#64748b;'>(Job Ref: " . htmlspecialchars($jobRef, ENT_QUOTES, 'UTF-8') . ")</i>";

        if ($hasSetupFee) {
            $setupCode = htmlspecialchars((string)($job['nom_code_setup'] ?? '0000'), ENT_QUOTES, 'UTF-8');
            $grossSubtotal += $rateSetup;
            $tableRows .= plantRfpAppendLine($setupCode, 'Setup / Mobilisation Fee' . $refSuffix, '1.00', $rateSetup, $rateSetup);
        }

        if ($pricingType === 'fixed_then_hourly') {
            $fixedCode = htmlspecialchars((string)($job['nom_code_fixed'] ?? 'MISSING'), ENT_QUOTES, 'UTF-8');
            $grossSubtotal += $rateFixed;
            $tableRows .= plantRfpAppendLine($fixedCode, 'Fixed Callout Charge' . $refSuffix, '1.00', $rateFixed, $rateFixed);

            $baseHours = (float)($job['final_hours'] ?? 0);
            $extraHours = max(0, round($baseHours - $minHours, 2));
            if ($extraHours > 0) {
                $varCode = htmlspecialchars((string)($job['nom_code_variable'] ?? 'MISSING'), ENT_QUOTES, 'UTF-8');
                $vTotal = round($extraHours * $rateVar, 2);
                $grossSubtotal += $vTotal;
                $tableRows .= plantRfpAppendLine(
                    $varCode,
                    'Additional Hourly Rate' . "<br><i style='font-size:11px; color:#64748b;'>(Extra Hours > {$minHours})</i>",
                    number_format($extraHours, 2),
                    $rateVar,
                    $vTotal
                );
            }

            foreach (plantRfpOptionalModeCharges($cfgs, $sessions, $rateVar) as $modeCharge) {
                $grossSubtotal += $modeCharge['total'];
                $tableRows .= plantRfpAppendLine(
                    $modeCharge['code'],
                    'Primary Mode: ' . $modeCharge['name'] . $refSuffix,
                    number_format($modeCharge['hours'], 2) . ' Hrs',
                    $modeCharge['rate'],
                    $modeCharge['total']
                );
            }
        } elseif ($pricingType === 'per_trip') {
            $tripQty = plantRfpNormalizeTripQty($job['qty_trips'] ?? 1);
            $tripCode = htmlspecialchars((string)($job['nom_code_fixed'] ?? 'MISSING'), ENT_QUOTES, 'UTF-8');
            $tTotal = round($tripQty * $rateFixed, 2);
            $grossSubtotal += $tTotal;
            $tableRows .= plantRfpAppendLine($tripCode, 'Trip Execution Charge' . $refSuffix, $tripQty . ' Trips', $rateFixed, $tTotal);
        } elseif ($pricingType === 'daily') {
            $dayQty = round((float)($job['final_hours'] ?? 0) > 0 ? (float)$job['final_hours'] : 1, 2);
            $dayCode = htmlspecialchars((string)($job['nom_code_fixed'] ?? 'MISSING'), ENT_QUOTES, 'UTF-8');
            $dTotal = round($dayQty * $rateFixed, 2);
            $grossSubtotal += $dTotal;
            $tableRows .= plantRfpAppendLine($dayCode, 'Daily Flat Rate' . $refSuffix, $dayQty . ' Days', $rateFixed, $dTotal);
        } elseif (is_array($cfgs) && count($sessions) > 0) {
            foreach (plantRfpModeBreakdown($sessions) as $modeName => $modeHours) {
                $hours = round((float)$modeHours, 2);
                if ($hours <= 0) {
                    continue;
                }
                $resolved = plantRfpResolveConfigRate($cfgs, $modeName, 'mode', $rateVar);
                $modeCode = $resolved['code'] !== ''
                    ? $resolved['code']
                    : (string)($job['nom_code_variable'] ?? 'MISSING');
                $modeRate = round((float)$resolved['rate'], 4);
                $mTotal = round($hours * $modeRate, 2);
                $grossSubtotal += $mTotal;
                $tableRows .= plantRfpAppendLine(
                    $modeCode,
                    'Primary Mode: ' . $modeName . $refSuffix,
                    number_format($hours, 2) . ' Hrs',
                    $modeRate,
                    $mTotal
                );
            }
        } else {
            $hourQty = round((float)($job['final_hours'] ?? 0), 2);
            $hourCode = htmlspecialchars((string)($job['nom_code_variable'] ?? 'MISSING'), ENT_QUOTES, 'UTF-8');
            $hTotal = round($hourQty * $rateVar, 2);
            $grossSubtotal += $hTotal;
            $tableRows .= plantRfpAppendLine($hourCode, 'Standard Hourly Operation' . $refSuffix, $hourQty . ' Hrs', $rateVar, $hTotal);
        }

        if (is_array($cfgs) && count($sessions) > 0) {
            foreach (plantRfpFlatAddons($sessions) as $addonName => $addonQty) {
                $resolved = plantRfpResolveConfigRate($cfgs, $addonName, 'addon', 0);
                if ($resolved['code'] === '' || $addonQty <= 0) {
                    continue;
                }
                $aTotal = round($addonQty * $resolved['rate'], 2);
                $grossSubtotal += $aTotal;
                $tableRows .= plantRfpAppendLine(
                    $resolved['code'],
                    'Extra Add-on: ' . $addonName . $refSuffix,
                    number_format($addonQty, 2),
                    $resolved['rate'],
                    $aTotal
                );
            }
        }

        return [
            'rows' => $tableRows,
            'grossSubtotal' => round($grossSubtotal, 2),
        ];
    }
}
