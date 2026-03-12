<?php
class HealthScoring{
    private $weights = [
        'sales'      => 0.30,
        'net_income' => 0.25,
        'inventory'  => 0.20,
        'expenses'   => 0.15,
        'activity'   => 0.10
    ];

    // SCORING LOGIC
    private function salesScore($b)
    {
        if ($b['previous_sales'] == 0) return ['name'=>'Sales Performance','raw_basis'=>'No previous data','score'=>50];

        $growth = ($b['current_sales'] - $b['previous_sales']) / $b['previous_sales'];

        $score = $growth >= 0.10 ? 100 :
                 ($growth >= 0.05 ? 70 :
                 ($growth >= 0 ? 50 : 30));

        return [
            'name' => 'Sales Performance',
            'raw_basis' =>
                'PHP ' . number_format($b['current_sales']) .
                ' vs PHP ' . number_format($b['previous_sales']) .
                ' (' . round($growth * 100, 1) . '% growth)',
            'score' => $score
        ];
    }

    private function netIncomeScore($b)
    {
        if ($b['current_sales'] == 0) return ['name'=>'Net Income','raw_basis'=>'No sales data','score'=>40];

        $net = $b['current_sales'] - $b['expenses'] - $b['cogs'];
        $margin = $net / $b['current_sales'];

        $score = $margin >= 0.20 ? 100 :
                 ($margin >= 0.15 ? 80 :
                 ($margin >= 0.10 ? 60 : 40));

        return [
            'name' => 'Net Income',
            'raw_basis' =>
                'PHP ' . number_format($net) .
                ' (' . round($margin * 100, 1) . '% margin)',
            'score' => $score
        ];
    }

    private function inventoryScore($b)
    {
        if ($b['avg_inventory'] == 0) return ['name'=>'Inventory Health','raw_basis'=>'No inventory data','score'=>40];

        $deadPct = $b['dead_stock'] / $b['avg_inventory'];

        $score = $deadPct < 0.10 ? 100 :
                 ($deadPct <= 0.20 ? 80 :
                 ($deadPct <= 0.30 ? 60 : 40));

        return [
            'name' => 'Inventory Health',
            'raw_basis' =>
                'Dead stock PHP ' . number_format($b['dead_stock']) .
                ' (' . round($deadPct * 100, 1) . '%)',
            'score' => $score
        ];
    }

    private function expenseScore($b)
    {
        if ($b['current_sales'] == 0) return ['name'=>'Expense Control','raw_basis'=>'No sales data','score'=>30];

        $ratio = $b['expenses'] / $b['current_sales'];

        $score = $ratio <= 0.50 ? 100 :
                 ($ratio <= 0.65 ? 70 :
                 ($ratio <= 0.75 ? 50 : 30));

        return [
            'name' => 'Expense Control',
            'raw_basis' =>
                'PHP ' . number_format($b['expenses']) .
                ' (' . round($ratio * 100, 1) . '% of sales)',
            'score' => $score
        ];
    }

    private function activityScore($b)
    {
        if ($b['expected_pos_days'] == 0) return ['name'=>'Activity / Compliance','raw_basis'=>'No POS data','score'=>30];

        $rate = $b['actual_pos_days'] / $b['expected_pos_days'];

        $score = $rate >= 0.95 ? 100 :
                 ($rate >= 0.85 ? 70 :
                 ($rate >= 0.70 ? 50 : 30));

        return [
            'name' => 'Activity / Compliance',
            'raw_basis' =>
                "{$b['actual_pos_days']} / {$b['expected_pos_days']} active days",
            'score' => $score
        ];
    }
    public function scoreBranch(array $b): array
    {
        $sales = $this->salesScore($b);
        $net   = $this->netIncomeScore($b);
        $inv   = $this->inventoryScore($b);
        $exp   = $this->expenseScore($b);
        $act   = $this->activityScore($b);

        $totalScore = round(
            $sales['score'] * $this->weights['sales'] +
            $net['score']   * $this->weights['net_income'] +
            $inv['score']   * $this->weights['inventory'] +
            $exp['score']   * $this->weights['expenses'] +
            $act['score']   * $this->weights['activity']
        );

        return [
            'overall_score' => $totalScore,
            'factors' => [
                $sales + ['weight' => 30],
                $net   + ['weight' => 25],
                $inv   + ['weight' => 20],
                $exp   + ['weight' => 15],
                $act   + ['weight' => 10]
            ]
        ];
    }
}
?>
