<?php

namespace App\Metrics;

use App\Support\SprintRules;
use App\Support\TeamRules;
use Collator;

/**
 * Indicadores dos cards das telas Dashboard, Sprints, Épicos e Time (espelho de metricsOf, isArrastado, precStats,
 * devStats e dos números dos gráficos em resources/views/dashboard.blade.php). Mesmas regras e arredondamentos do
 * front: percentuais inteiros arredondados (Math.round), horas com uma casa (toFixed(1)).
 */
final class SprintKpis
{
    private readonly TeamRules $teams;

    public function __construct(private readonly MetricsData $data)
    {
        $this->teams = new TeamRules([
            'capacityHours' => $data->settings['capacityHours'] ?? 80,
            'excludedPeople' => $data->settings['excludedPeople'] ?? [],
            'team' => $data->settings['team'] ?? [],
        ]);
    }

    /**
     * Indicadores de um conjunto de cards. Cancelado, Descontinuado e Despriorizado saem do total e contam como
     * retirados do escopo. `lists` traz os cards de cada indicador (id, nome e sprint).
     *
     * @param  list<array<string, mixed>>  $all
     * @return array<string, mixed>
     */
    public function metricsOf(array $all): array
    {
        $groups = $this->groups($all);
        $d = $groups['tot'];
        $tot = count($d);
        $isDone = fn (array $x): bool => SprintRules::isDone($x['Status'] ?? null);
        $status = fn (array $x): string => SprintRules::normalize($x['Status'] ?? null);
        $inTestItems = $groups['inTest'];
        $plannedItems = $groups['planned'];
        $unplannedItems = $groups['unplanned'];
        $supportItems = $groups['support'];
        $unplan = count($this->filter($d, fn (array $x): bool => ($x['Planejada'] ?? null) === 'Não Planejado'));
        $withEst = $tot - count($groups['noEst']);
        $done = count($groups['done']);
        $rework = count($groups['rework']);
        $drag = count($groups['drag']);
        $cleanScope = count($plannedItems);
        $devFinished = count($groups['devFinished']);
        $unplanned = count($unplannedItems);
        $sprintItems = count($plannedItems) + $unplanned;

        return [
            'lists' => array_map(fn (array $items): array => array_map(self::card(...), $items), $groups),
            'tot' => $tot,
            'done' => $done,
            'waiting' => count($groups['waiting']),
            'inTest' => count($inTestItems),
            'inTestRejected' => count($this->filter($inTestItems, fn (array $x): bool => $status($x) === 'qa - reprovado')),
            'inTestApproved' => count($this->filter($inTestItems, fn (array $x): bool => $status($x) === 'qa - aprovado')),
            'open' => count($groups['open']),
            'removed' => count($all) - $tot,
            'unplan' => $unplan,
            'rework' => $rework,
            'bugs' => count($groups['bugs']),
            'valor' => count($groups['valor']),
            'drag' => $drag,
            'withEst' => $withEst,
            'epicItems' => count($groups['epic']),
            'epicDone' => count($this->filter($d, fn (array $x): bool => ($x['Tipo'] ?? null) === 'Épico' && $isDone($x))),
            'cleanScope' => $cleanScope,
            'unplanCrit' => count($this->filter($d, fn (array $x): bool => ($x['Planejada'] ?? null) === 'Não Planejado' && ($x['Prioridade'] ?? null) === 'Crítica')),
            'unplanBug' => count($this->filter($d, fn (array $x): bool => ($x['Planejada'] ?? null) === 'Não Planejado' && ($x['Tipo'] ?? null) === 'Bug')),
            'support' => count($supportItems),
            'supportDone' => count($this->filter($supportItems, $isDone)),
            'planned' => count($plannedItems),
            'plannedDone' => count($this->filter($plannedItems, $isDone)),
            'unplanned' => $unplanned,
            'unplannedDone' => count($this->filter($unplannedItems, $isDone)),
            'unplannedCrit' => count($this->filter($unplannedItems, fn (array $x): bool => ($x['Prioridade'] ?? null) === 'Crítica')),
            'unplannedBug' => count($this->filter($unplannedItems, fn (array $x): bool => ($x['Tipo'] ?? null) === 'Bug')),
            'unplannedPct' => self::pct($unplanned, $tot),
            'conclusao' => self::pct($done, $tot),
            'devFinished' => $devFinished,
            'devFinishedPct' => self::pct($devFinished, $tot),
            'reworkPct' => self::pct($rework, $tot),
            'aderencia' => self::pct($withEst, $tot),
            'arrastPct' => self::pct($drag, $tot),
            'cleanPct' => self::pct($cleanScope, $sprintItems),
            'epicPct' => self::pct(count($groups['epic']), $tot),
            'interruptPct' => self::pct($unplan, $tot),
        ];
    }

    /**
     * Cards de cada indicador (as listas do modal do front), sem os retirados do escopo.
     *
     * @param  list<array<string, mixed>>  $all
     * @return array<string, list<array<string, mixed>>>
     */
    private function groups(array $all): array
    {
        $d = $this->filter($all, fn (array $x): bool => ! SprintRules::isRemoved($x['Status'] ?? null));
        $isDone = fn (array $x): bool => SprintRules::isDone($x['Status'] ?? null);
        $support = fn (array $x): bool => SprintRules::normalize($x['Tipo'] ?? null) === 'suporte';
        $planned = fn (array $x): bool => SprintRules::normalize($x['Planejada'] ?? null) === 'planejada';
        $plannedItems = $this->filter($d, fn (array $x): bool => ! $support($x) && $planned($x));

        return [
            'tot' => $d,
            'done' => $this->filter($d, $isDone),
            'devFinished' => $this->filter($d, fn (array $x): bool => $isDone($x) || SprintRules::isDoneNotDelivered($x['Status'] ?? null)
                || (SprintRules::isInTest($x['Status'] ?? null) && SprintRules::normalize($x['Status'] ?? null) !== 'qa - reprovado')),
            'waiting' => $this->filter($d, fn (array $x): bool => SprintRules::isDoneNotDelivered($x['Status'] ?? null)),
            'inTest' => $this->filter($d, fn (array $x): bool => SprintRules::isInTest($x['Status'] ?? null)),
            'open' => $this->filter($d, fn (array $x): bool => SprintRules::isOpen($x['Status'] ?? null)),
            'planned' => $plannedItems,
            'unplanned' => $this->filter($d, fn (array $x): bool => ! $support($x) && ! $planned($x)),
            'support' => $this->filter($d, $support),
            'rework' => $this->filter($d, fn (array $x): bool => self::truthy($x['Retrabalho'] ?? null)),
            'clean' => $plannedItems,
            'drag' => $this->filter($d, fn (array $x): bool => $this->isArrastado($x)),
            'noEst' => $this->filter($d, fn (array $x): bool => ! (self::number($x['EstH'] ?? null) > 0)),
            'bugs' => $this->filter($d, fn (array $x): bool => self::truthy($x['BugProducao'] ?? null)),
            'valor' => $this->filter($d, fn (array $x): bool => self::truthy($x['ValorAgregado'] ?? null)),
            'epic' => $this->filter($d, fn (array $x): bool => self::truthy($x['Epico'] ?? null)),
        ];
    }

    /**
     * Arrastado: o mesmo card (Id) já esteve em sprint anterior, pelo histórico do Monday ou pelas linhas carregadas;
     * cópias "(copy)" sem Id comum são reconhecidas pelo nome.
     *
     * @param  array<string, mixed>  $item
     */
    public function isArrastado(array $item): bool
    {
        if (self::truthy($item['Arrastado'] ?? null)) {
            return true;
        }
        $sprint = self::number($item['Sprint'] ?? null);
        $id = $item['Id'] ?? null;
        if (self::truthy($id)) {
            foreach ($this->data->rows as $row) {
                if (($row['Id'] ?? null) === $id && self::number($row['Sprint'] ?? null) < $sprint) {
                    return true;
                }
            }
        }
        $name = (string) ($item['Nome'] ?? '');
        $base = mb_strtolower(trim((string) preg_replace('/\s*\(copy\)\s*/iu', '', $name)));
        if (mb_strlen($base) < 8 || ! preg_match('/\(copy\)/i', $name)) {
            return false;
        }
        foreach ($this->data->rows as $row) {
            if (self::number($row['Sprint'] ?? null) < $sprint && str_contains(mb_strtolower((string) ($row['Nome'] ?? '')), $base)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Precisão da estimativa: desvio médio (real − estimado) ÷ estimado dos cards com as duas horas.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return array{avg: ?float, label: string}
     */
    public function precStats(array $rows): array
    {
        $both = $this->filter($rows, fn (array $x): bool => self::number($x['EstH'] ?? null) > 0 && self::number($x['RealH'] ?? null) > 0);
        if ($both === []) {
            return ['avg' => null, 'label' => 'sem dados'];
        }
        $avg = array_sum(array_map(fn (array $x): float => (self::number($x['RealH']) - self::number($x['EstH'])) / self::number($x['EstH']) * 100, $both)) / count($both);

        return ['avg' => $avg, 'label' => abs($avg) < 5 ? 'preciso' : ($avg > 0 ? 'subestimou '.self::f1($avg).'%' : 'superestimou '.self::f1(abs($avg)).'%')];
    }

    /**
     * Tela Dashboard: os indicadores de todas as sprints, a série de cada sprint dos gráficos, as horas do Volume por
     * Sprint (reais e estimadas) e a composição por tipo e por qualidade.
     *
     * @return array<string, mixed>
     */
    public function dashboard(): array
    {
        $sprints = $this->data->sprints;
        $all = $this->filter($this->data->rows, fn (array $x): bool => self::truthy($x['Sprint'] ?? null) && ! SprintRules::isRemoved($x['Status'] ?? null));
        $total = $this->metricsOf($this->filter($this->data->rows, fn (array $x): bool => self::truthy($x['Sprint'] ?? null)));
        $perSprint = [];
        foreach ($sprints as $sprint) {
            $rows = $this->data->rowsOf($sprint);
            $m = $this->metricsOf($rows);
            $perSprint[] = [
                'sprint' => $sprint,
                'tot' => $m['tot'], 'done' => $m['done'], 'conclusao' => $m['conclusao'],
                'planned' => $m['planned'], 'unplanned' => $m['unplanned'], 'support' => $m['support'], 'drag' => $m['drag'], 'arrastPct' => $m['arrastPct'],
                'withEst' => $m['withEst'], 'aderencia' => $m['aderencia'], 'rework' => $m['rework'], 'reworkPct' => $m['reworkPct'],
                'bugs' => count($this->filter($rows, fn (array $x): bool => self::truthy($x['BugProducao'] ?? null))),
                'valor' => count($this->filter($rows, fn (array $x): bool => self::truthy($x['ValorAgregado'] ?? null))),
                'cleanScope' => $m['cleanScope'], 'cleanPct' => $m['cleanPct'], 'epicItems' => $m['epicItems'], 'epicPct' => $m['epicPct'],
                'volumeHours' => ['RealH' => $this->volumeHours($rows, 'RealH'), 'EstH' => $this->volumeHours($rows, 'EstH')],
            ];
        }
        $bug = fn (array $x): bool => self::truthy($x['BugProducao'] ?? null);
        $rework = fn (array $x): bool => self::truthy($x['Retrabalho'] ?? null);

        return [
            'sprints' => $sprints,
            'total' => $total,
            'averagePerSprint' => $sprints === [] ? 0 : self::round($total['tot'] / count($sprints)),
            'perSprint' => $perSprint,
            'types' => $this->countBy($all, 'Tipo'),
            'quality' => [
                'clean' => count($this->filter($all, fn (array $x): bool => ! $bug($x) && ! $rework($x))),
                'rework' => count($this->filter($all, fn (array $x): bool => $rework($x) && ! $bug($x))),
                'bug' => count($this->filter($all, fn (array $x): bool => $bug($x) && ! $rework($x))),
                'both' => count($this->filter($all, fn (array $x): bool => $bug($x) && $rework($x))),
            ],
        ];
    }

    /**
     * Tela Sprints: indicadores da sprint e da anterior (tendência), composição por status, tipo, prioridade e
     * planejamento, tasks por épico e cards por pessoa.
     *
     * @return array<string, mixed>
     */
    public function sprint(int $sprint): array
    {
        $rows = $this->data->rowsOf($sprint);
        $index = array_search($sprint, $this->data->sprints, true);
        $previous = $index !== false && $index > 0 ? $this->data->sprints[$index - 1] : null;
        $priorities = $this->countBy($rows, 'Prioridade');
        $byPriority = [];
        foreach (['Crítica', 'Alta', 'Média', 'Baixa'] as $priority) {
            foreach ($priorities as [$label, $count]) {
                if ($label === $priority) {
                    $byPriority[] = [$label, $count];
                }
            }
        }
        $devs = [];
        foreach ($rows as $row) {
            foreach ($this->countedPeople($row) as $person) {
                $devs[$person] ??= ['name' => $person, 'total' => 0, 'done' => 0];
                $devs[$person]['total']++;
                if (SprintRules::isDone($row['Status'] ?? null)) {
                    $devs[$person]['done']++;
                }
            }
        }
        $devs = array_values($devs);
        usort($devs, fn (array $a, array $b): int => $b['total'] <=> $a['total']);

        return [
            'sprint' => $sprint,
            'previousSprint' => $previous,
            'metrics' => $this->metricsOf($rows),
            'previousMetrics' => $previous === null ? null : $this->metricsOf($this->data->rowsOf($previous)),
            'status' => $this->countBy($rows, 'Status'),
            'types' => $this->countBy($rows, 'Tipo'),
            'priorities' => $byPriority,
            'planning' => [
                'planned' => count($this->filter($rows, fn (array $x): bool => ($x['Planejada'] ?? null) === 'Planejada')),
                'unplanned' => count($this->filter($rows, fn (array $x): bool => ($x['Planejada'] ?? null) === 'Não Planejado')),
            ],
            'epics' => $this->countBy($this->filter($rows, fn (array $x): bool => self::truthy($x['Epico'] ?? null)), 'Epico'),
            'devs' => array_map(fn (array $dev): array => [...$dev, 'pct' => self::pct($dev['done'], $dev['total'])], $devs),
        ];
    }

    /**
     * Tela Épicos: tasks, épicos concluídos, horas reais e sprints de cada épico, e a participação de cada épico
     * nos cards de cada sprint (%).
     *
     * @return array{epics: list<array{epic: string, tasks: int, done: int, hours: float, sprints: list<int>}>, perSprint: list<array{sprint: int, shares: array<string, int>}>, items: int}
     */
    public function epics(): array
    {
        $names = array_values(array_unique(array_filter(array_map(fn (array $x): string => (string) ($x['Epico'] ?? ''), $this->data->rows))));
        $this->sortLocale($names);
        $epics = [];
        foreach ($names as $epic) {
            $items = $this->filter($this->data->rows, fn (array $x): bool => ($x['Epico'] ?? null) === $epic);
            if ($items === []) {
                continue;
            }
            $sprints = array_values(array_unique(array_filter(array_map(fn (array $x): mixed => $x['Sprint'] ?? null, $items), self::truthy(...))));
            // O front ordena as sprints com sort() sem comparador: ordem de texto (10 antes de 9).
            sort($sprints, SORT_STRING);
            $epics[] = [
                'epic' => $epic,
                'tasks' => count($items),
                'done' => count($this->filter($items, fn (array $x): bool => ($x['Tipo'] ?? null) === 'Épico' && SprintRules::isDone($x['Status'] ?? null))),
                'hours' => self::f1(array_sum(array_map(fn (array $x): float => self::number($x['RealH'] ?? null), $items))),
                'sprints' => array_map('intval', $sprints),
            ];
        }
        $perSprint = [];
        foreach ($this->data->sprints as $sprint) {
            $rows = $this->data->rowsOf($sprint);
            $shares = [];
            foreach ($epics as $epic) {
                $count = count($this->filter($rows, fn (array $x): bool => ($x['Epico'] ?? null) === $epic['epic']));
                $shares[$epic['epic']] = $rows === [] ? 0 : self::round($count / count($rows) * 100);
            }
            $perSprint[] = ['sprint' => $sprint, 'shares' => $shares];
        }

        return ['epics' => $epics, 'perSprint' => $perSprint, 'items' => count($this->filter($this->data->rows, fn (array $x): bool => self::truthy($x['Epico'] ?? null)))];
    }

    /**
     * Tela Time: sem pessoa, os números de cada pessoa e as horas por sprint; com pessoa, os indicadores dela, a
     * capacidade (salva no Capacity, herdada ou padrão), a ocupação e a evolução por sprint.
     *
     * @return array<string, mixed>
     */
    public function team(?string $dev = null, ?int $sprint = null): array
    {
        $rows = $this->filter($this->data->rows, fn (array $x): bool => self::truthy($x['Sprint'] ?? null) && $this->countedPeople($x) !== []);
        if ($sprint !== null) {
            $rows = $this->filter($rows, fn (array $x): bool => (int) $x['Sprint'] === $sprint);
        }
        if ($dev === null) {
            $stats = [];
            foreach ($this->devs() as $person) {
                $mine = $this->filter($rows, fn (array $x): bool => in_array($person, $this->countedPeople($x), true));
                if ($mine === []) {
                    continue;
                }
                $stats[] = ['name' => $person, ...$this->devStats($person, $rows), 'types' => $this->countBy($mine, 'Tipo', false)];
            }
            $hours = [];
            foreach ($stats as $stat) {
                $hours[$stat['name']] = array_map(fn (int $s): float => $this->devStats($stat['name'], $this->data->rowsOf($s))['allocH'], $this->data->sprints);
            }

            return ['sprint' => $sprint, 'devs' => $stats, 'hoursPerSprint' => ['sprints' => $this->data->sprints, 'devs' => $hours]];
        }
        $stats = $this->devStats($dev, $rows);
        $capacityOf = fn (int $s): float => $this->teams->capacityMinutes($this->data->capacityOverrides, $s, $dev) / 60;
        $capacity = $sprint === null ? array_sum(array_map($capacityOf, $this->data->sprints)) : $capacityOf($sprint);

        return [
            'dev' => $dev,
            'sprint' => $sprint,
            'stats' => $stats,
            'pctOfTeam' => self::pct($stats['tot'], count($rows)),
            'capacityHours' => $capacity,
            'occupancy' => self::pct($stats['allocH'], $capacity),
            'gap' => self::f1($capacity - $stats['allocH']),
            'perSprint' => array_map(fn (int $s): array => ['sprint' => $s, 'capacityHours' => $capacityOf($s), ...$this->devStats($dev, $this->data->rowsOf($s))], $this->data->sprints),
        ];
    }

    /**
     * Números de uma pessoa nos cards informados. As horas reais de um card com várias pessoas são divididas entre elas.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return array{tot: int, done: int, rework: int, bugs: int, valor: int, open: int, withEst: int, epicItems: int, epicDone: int, allocH: float, conclusao: int, reworkPct: int, aderencia: int, netPrec: ?float, precLabel: string}
     */
    public function devStats(string $dev, array $rows): array
    {
        $items = $this->filter($rows, fn (array $x): bool => in_array($dev, $this->countedPeople($x), true));
        $tot = count($items);
        $done = count($this->filter($items, fn (array $x): bool => SprintRules::isDone($x['Status'] ?? null)));
        $rework = count($this->filter($items, fn (array $x): bool => self::truthy($x['Retrabalho'] ?? null)));
        $withEst = count($this->filter($items, fn (array $x): bool => self::number($x['EstH'] ?? null) > 0));
        $allocH = 0.0;
        foreach ($items as $x) {
            if (! self::truthy($x['RealH'] ?? null)) {
                continue;
            }
            $devs = $this->countedPeople($x);
            $allocH += self::number($x['RealH']) / count($devs);
        }
        $precision = $this->precStats($items);

        return [
            'tot' => $tot,
            'done' => $done,
            'rework' => $rework,
            'bugs' => count($this->filter($items, fn (array $x): bool => self::truthy($x['BugProducao'] ?? null))),
            'valor' => count($this->filter($items, fn (array $x): bool => self::truthy($x['ValorAgregado'] ?? null))),
            'open' => count($this->filter($items, fn (array $x): bool => SprintRules::isOpen($x['Status'] ?? null))),
            'withEst' => $withEst,
            'epicItems' => count($this->filter($items, fn (array $x): bool => self::truthy($x['Epico'] ?? null))),
            'epicDone' => count($this->filter($items, fn (array $x): bool => ($x['Tipo'] ?? null) === 'Épico' && SprintRules::isDone($x['Status'] ?? null))),
            'allocH' => self::f1($allocH),
            'conclusao' => self::pct($done, $tot),
            'reworkPct' => self::pct($rework, $tot),
            'aderencia' => self::pct($withEst, $tot),
            'netPrec' => $precision['avg'],
            'precLabel' => $precision['label'],
        ];
    }

    /**
     * Pessoas do time nos cards (DEVS do front): fora as que estão fora das métricas, em ordem alfabética.
     *
     * @return list<string>
     */
    public function devs(): array
    {
        $names = [];
        foreach ($this->data->rows as $row) {
            foreach ($this->countedPeople($row) as $person) {
                $names[$person] = true;
            }
        }
        $names = array_keys($names);
        $this->sortLocale($names);

        return $names;
    }

    /**
     * Pessoas do card que contam nas métricas por pessoa.
     *
     * @param  array<string, mixed>  $row
     * @return list<string>
     */
    private function countedPeople(array $row): array
    {
        $people = array_values(array_filter(array_map('trim', explode(',', trim((string) ($row['Responsavel'] ?? '')))), 'strlen'));

        return array_values(array_filter($people, fn (string $person): bool => $this->teams->counts($person)));
    }

    /**
     * Horas do Volume por Sprint: planejados, não planejados, suporte e arrastados; total e cards sem horas.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return array{planned: float, unplanned: float, support: float, drag: float, total: float, missing: int, tot: int}
     */
    private function volumeHours(array $rows, string $field): array
    {
        $groups = $this->groups($rows);
        $hours = fn (array $list): float => self::f1(array_sum(array_map(fn (array $x): float => self::number($x[$field] ?? null), $list)));

        return [
            'planned' => $hours($groups['planned']), 'unplanned' => $hours($groups['unplanned']), 'support' => $hours($groups['support']),
            'drag' => $hours($groups['drag']), 'total' => $hours($groups['tot']),
            'missing' => count($this->filter($groups['tot'], fn (array $x): bool => ! (self::number($x[$field] ?? null) > 0))), 'tot' => count($groups['tot']),
        ];
    }

    /**
     * Contagem por campo, do maior para o menor (empates na ordem de aparição), como os gráficos de rosca.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return list<array{0: string, 1: int}>
     */
    private function countBy(array $rows, string $field, bool $sorted = true): array
    {
        $counts = [];
        foreach ($rows as $row) {
            $key = (string) ($row[$field] ?? '');
            $counts[$key] = ($counts[$key] ?? 0) + 1;
        }
        $entries = array_map(fn (string|int $key, int $count): array => [(string) $key, $count], array_keys($counts), $counts);
        if ($sorted) {
            usort($entries, fn (array $a, array $b): int => $b[1] <=> $a[1]);
        }

        return $entries;
    }

    /**
     * @param  list<string>  $names
     */
    private function sortLocale(array &$names): void
    {
        $collator = class_exists(Collator::class) ? new Collator('pt_BR') : null;
        usort($names, fn (string $a, string $b): int => $collator ? (int) $collator->compare($a, $b) : strcasecmp($a, $b));
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @param  callable(array<string, mixed>): bool  $keep
     * @return list<array<string, mixed>>
     */
    private function filter(array $rows, callable $keep): array
    {
        return array_values(array_filter($rows, $keep));
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array{id: string, nome: string, sprint: int}
     */
    private static function card(array $row): array
    {
        return ['id' => (string) ($row['Id'] ?? ''), 'nome' => (string) ($row['Nome'] ?? ''), 'sprint' => (int) ($row['Sprint'] ?? 0)];
    }

    /** pct do front: 0 sem base; senão o percentual inteiro (Math.round). */
    public static function pct(float|int $part, float|int $whole): int
    {
        return $whole == 0 ? 0 : self::round($part / $whole * 100);
    }

    /** Math.round do JavaScript: meio arredonda para cima, inclusive nos negativos. */
    public static function round(float|int $value): int
    {
        return (int) floor($value + 0.5);
    }

    /** f1 do front: uma casa decimal. */
    public static function f1(float|int $value): float
    {
        return round((float) $value, 1);
    }

    /** Verdadeiro como no JavaScript: vazio, 0, false e null são falsos; o texto "0" é verdadeiro. */
    public static function truthy(mixed $value): bool
    {
        return ! ($value === null || $value === false || $value === '' || $value === 0 || $value === 0.0 || (is_float($value) && is_nan($value)));
    }

    /** Número como o JavaScript compara (x > 0): texto numérico vira número; o resto vira 0. */
    public static function number(mixed $value): float
    {
        if (is_bool($value)) {
            return $value ? 1.0 : 0.0;
        }

        return is_numeric($value) ? (float) $value : 0.0;
    }
}
