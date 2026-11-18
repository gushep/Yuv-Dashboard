<?php

namespace App\Mcp\Tools;

use App\Metrics\MetricsData;
use App\Services\MondaySnapshotService;

/**
 * Ferramenta de uma tela cujos números são calculados pelos cálculos portados das telas (app/Metrics): carrega os
 * mesmos dados que a tela recebe e devolve os cards só com os campos úteis, para a resposta não ficar grande demais.
 */
abstract class MetricsScreenTool extends ScreenTool
{
    /** Campos do card devolvidos pelas ferramentas. */
    private const CARD_FIELDS = ['Id', 'Nome', 'Status', 'Responsavel', 'Tipo', 'Prioridade', 'EstH', 'RealH', 'Sprint'];

    protected function metrics(): MetricsData
    {
        return MetricsData::load(app(MondaySnapshotService::class), $this->connection());
    }

    /**
     * Sprint aberta por padrão nas telas (defaultSprint do dashboard): a em andamento hoje; senão, a última que já
     * começou; senão, a última sprint dos cards.
     */
    protected function defaultSprint(MetricsData $data): ?int
    {
        $today = $data->today();
        $started = array_values(array_filter($data->sprints, fn (int $sprint): bool => ($data->datesOf($sprint)['start'] ?? null) !== null && $data->datesOf($sprint)['start'] <= $today));
        $running = array_values(array_filter($started, fn (int $sprint): bool => ($data->datesOf($sprint)['end'] ?? '') >= $today));

        return $running !== [] ? end($running) : ($started !== [] ? end($started) : ($data->sprints !== [] ? end($data->sprints) : null));
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    protected function card(array $row): array
    {
        return array_intersect_key($row, array_flip(self::CARD_FIELDS));
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    protected function cards(array $rows): array
    {
        return array_map($this->card(...), $rows);
    }
}
