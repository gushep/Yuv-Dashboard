<?php

namespace Tests\Unit;

use App\Support\SprintRules;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;

class SprintRulesTest extends TestCase
{
    #[TestWith(['Melhoria', 'Planejada', 'planned'])]
    #[TestWith(['Bug', ' planejada ', 'planned'])]
    #[TestWith(['Melhoria', 'Não Planejado', 'unplanned'])]
    #[TestWith(['Melhoria', '', 'unplanned'])]
    #[TestWith(['Suporte', 'Não Planejado', 'support'])]
    #[TestWith(['suporte', 'Planejada', 'support'])]
    public function test_card_origin_puts_support_ahead_of_the_planned_column(string $type, string $planned, string $expected): void
    {
        $this->assertSame($expected, SprintRules::category($type, $planned));
    }
}
