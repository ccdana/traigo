<?php

// Reparto del bono, escalones y semanas de la campaña.
// Generado desde los Excel de Traigo; si cambia el Excel, vuelve a generarlo
// o edita este archivo con cuidado.

return ['members' => [
    ['member' => 'Ailén', 'bonus_share_pct' => 20, 'min_kpi_pct' => 90],
    ['member' => 'Isaí', 'bonus_share_pct' => 20, 'min_kpi_pct' => 90],
    ['member' => 'Kim', 'bonus_share_pct' => 20, 'min_kpi_pct' => 90],
    ['member' => 'Alison', 'bonus_share_pct' => 20, 'min_kpi_pct' => 80],
    ['member' => 'Dana', 'bonus_share_pct' => 10, 'min_kpi_pct' => 90],
    ['member' => 'Brandon', 'bonus_share_pct' => 10, 'min_kpi_pct' => 90],
], 'tiers' => [
    ['tier' => 'partial', 'threshold_pct' => 90, 'release_factor' => 0.5],
    ['tier' => 'complete', 'threshold_pct' => 100, 'release_factor' => 1],
    ['tier' => 'superior', 'threshold_pct' => 115, 'release_factor' => 1.25],
], 'weeks' => [
    ['number' => 1, 'label' => '1–6 dic', 'starts_on' => '2026-12-01', 'ends_on' => '2026-12-06', 'sales_target_bs' => 40000, 'base_prize_bs' => 1500, 'count_target_factor' => 0.9, 'requires_system_deliverables' => true],
    ['number' => 2, 'label' => '7–13 dic', 'starts_on' => '2026-12-07', 'ends_on' => '2026-12-13', 'sales_target_bs' => 60000, 'base_prize_bs' => 2500, 'count_target_factor' => 1, 'requires_system_deliverables' => false],
    ['number' => 3, 'label' => '14–20 dic', 'starts_on' => '2026-12-14', 'ends_on' => '2026-12-20', 'sales_target_bs' => 85000, 'base_prize_bs' => 3500, 'count_target_factor' => 1, 'requires_system_deliverables' => false],
    ['number' => 4, 'label' => '21–27 dic', 'starts_on' => '2026-12-21', 'ends_on' => '2026-12-27', 'sales_target_bs' => 100000, 'base_prize_bs' => 4500, 'count_target_factor' => 1, 'requires_system_deliverables' => false],
    ['number' => 5, 'label' => '28 dic–3 ene', 'starts_on' => '2026-12-28', 'ends_on' => '2027-01-03', 'sales_target_bs' => 55000, 'base_prize_bs' => 2500, 'count_target_factor' => 1, 'requires_system_deliverables' => false],
    ['number' => 6, 'label' => '4–10 ene', 'starts_on' => '2027-01-04', 'ends_on' => '2027-01-10', 'sales_target_bs' => 70000, 'base_prize_bs' => 3500, 'count_target_factor' => 1, 'requires_system_deliverables' => false],
    ['number' => 7, 'label' => '11–14 ene', 'starts_on' => '2027-01-11', 'ends_on' => '2027-01-14', 'sales_target_bs' => 40000, 'base_prize_bs' => 2000, 'count_target_factor' => 0.7, 'requires_system_deliverables' => false],
]];
