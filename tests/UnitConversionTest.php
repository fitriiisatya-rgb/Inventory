<?php
declare(strict_types=1);

function test_unit_conversion(): void
{
    T::section('UnitConversion — worked examples from Phase 2 design review');

    // Keju: 1 Karton = 20 Kg, 1 Karton = 20.000 Gr
    $keju = ['buy_unit' => 'Karton', 'buy_content' => 20000, 'mid_unit' => 'Kg', 'mid_content' => 20, 'base_unit' => 'Gr'];
    T::assertEquals(1000.0, UnitConversion::midToBase($keju), 'Keju mid_to_base = 1000 Gr per Kg');
    T::assertEquals(70200.0, UnitConversion::normalize($keju, 2, 30, 200), 'Keju: 2 Karton + 30 Kg + 200 Gr = 70.200 Gr');
    T::assertEquals(
        ['buy', 'mid', 'base'],
        array_column(UnitConversion::levels($keju), 'level'),
        'Keju renders 3 levels (Karton/Kg/Gr), no dropdown'
    );

    // Minyak: 1 Karton = 12 Pcs, base_unit = Pcs, no mid level
    $minyak = ['buy_unit' => 'Karton', 'buy_content' => 12, 'mid_unit' => null, 'mid_content' => null, 'base_unit' => 'Pcs'];
    T::assertEquals(null, UnitConversion::midToBase($minyak), 'Minyak has no mid level');
    T::assertEquals(110.0, UnitConversion::normalize($minyak, 9, 0, 2), 'Minyak: 9 Karton + 2 Pcs = 110 Pcs');
    T::assertEquals(['buy', 'base'], array_column(UnitConversion::levels($minyak), 'level'), 'Minyak renders 2 levels');

    // Telur: buy_unit === base_unit, 1-level item
    $telur = ['buy_unit' => 'Pcs', 'buy_content' => 1, 'mid_unit' => null, 'mid_content' => null, 'base_unit' => 'Pcs'];
    T::assertEquals(120.0, UnitConversion::normalize($telur, 0, 0, 120), 'Telur: 120 Pcs = 120 Pcs');
    T::assertEquals(['base'], array_column(UnitConversion::levels($telur), 'level'), 'Telur renders 1 level only');

    // Sak example from correction letter: 1 Sak = 25 Kg, 1 Sak = 25.000 Gr
    $sak = ['buy_unit' => 'Sak', 'buy_content' => 25000, 'mid_unit' => 'Kg', 'mid_content' => 25, 'base_unit' => 'Gr'];
    T::assertEquals(1000.0, UnitConversion::midToBase($sak), 'Sak mid_to_base = 1000 Gr per Kg');

    T::section('UnitConversion — validation rules');

    $issues = UnitConversion::validateConversion('Karton', 20000, 'Kg', 20, 'Gr');
    T::assertFalse(UnitConversion::hasBlockingErrors($issues), 'Valid 3-level Keju config has no blocking errors');

    $issues = UnitConversion::validateConversion('Karton', 12, null, null, 'Pcs');
    T::assertFalse(UnitConversion::hasBlockingErrors($issues), 'Valid 2-level Minyak config has no blocking errors');

    $issues = UnitConversion::validateConversion('Pcs', 1, null, null, 'Pcs');
    T::assertFalse(UnitConversion::hasBlockingErrors($issues), 'Valid 1-level Telur config has no blocking errors');

    $issues = UnitConversion::validateConversion('Pcs', 2, null, null, 'Pcs');
    T::assertTrue(UnitConversion::hasBlockingErrors($issues), 'buy_unit==base_unit but buy_content!=1 is an error');

    $issues = UnitConversion::validateConversion('Pcs', 1, 'Kg', 5, 'Pcs');
    T::assertTrue(UnitConversion::hasBlockingErrors($issues), '1-level item cannot also have a mid_unit');

    $issues = UnitConversion::validateConversion('Karton', 20000, 'Gr', 20, 'Gr');
    T::assertTrue(UnitConversion::hasBlockingErrors($issues), 'mid_unit cannot equal base_unit');

    // Swapped buy_content/mid_content (classic data-entry mistake): mid_content > buy_content
    $issues = UnitConversion::validateConversion('Karton', 20, 'Kg', 20000, 'Gr');
    T::assertTrue(!UnitConversion::hasBlockingErrors($issues), 'Swapped values produce a warning, not a hard block');
    $hasWarning = false;
    foreach ($issues as $i) {
        if ($i['severity'] === 'warning') { $hasWarning = true; }
    }
    T::assertTrue($hasWarning, 'Swapped buy_content/mid_content triggers a warning');
}
