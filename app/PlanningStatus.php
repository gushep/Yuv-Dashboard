<?php

namespace App;

/**
 * Etapas de uma rodada da planning poker (um card na mesa).
 */
enum PlanningStatus: string
{
    /** Card no centro da mesa para o time ler o escopo; ainda não se vota. */
    case Reading = 'reading';
    /** Cada pessoa joga a carta com as horas, virada para baixo. */
    case Voting = 'voting';
    /** Cartas viradas; o Maestro Scrum escolhe entre a estimativa da IA e a média do time. */
    case Revealed = 'revealed';
    /** Estimativa gravada no card. */
    case Closed = 'closed';
    /** Card tirado da mesa sem gravar nada. */
    case Canceled = 'canceled';

    /**
     * @return list<self>
     */
    public static function open(): array
    {
        return [self::Reading, self::Voting, self::Revealed];
    }

    public function isOpen(): bool
    {
        return in_array($this, self::open(), true);
    }
}
