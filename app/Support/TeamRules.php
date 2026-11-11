<?php

namespace App\Support;

/**
 * Times da capacidade, espelho das funções de time de public/sprint-rules.js.
 * Full stack atende os itens da sprint e a sustentação (reserva do Bombeiro); Projetos (IA) atende demandas próprias.
 * Quem não está em nenhum dos dois fica fora das métricas por pessoa e da capacidade. Enquanto ninguém foi
 * classificado, vale a regra anterior: todos contam como full stack, menos as pessoas fora das métricas.
 */
class TeamRules
{
    public const FULLSTACK = 'fullstack';

    public const PROJECTS = 'projects';

    public const GROUPS = [self::FULLSTACK, self::PROJECTS];

    /** @var array<string, string> nome normalizado => time */
    private array $teams = [];

    /** @var array<string, string> nome normalizado => nome como foi classificado */
    private array $names = [];

    /** @var list<string> */
    private array $excluded;

    /**
     * @param  array{capacityHours: float|int, excludedPeople: list<string>, team: list<array{id: string, name: string, group: string}>}  $settings
     */
    public function __construct(private array $settings)
    {
        foreach ($settings['team'] as $member) {
            $this->teams[SprintRules::normalize($member['name'])] = $member['group'];
            $this->names[SprintRules::normalize($member['name'])] = $member['name'];
        }
        $this->excluded = array_map([SprintRules::class, 'normalize'], $settings['excludedPeople']);
    }

    /** Alguém já foi classificado nas Configurações: a regra dos times passa a valer. */
    public function configured(): bool
    {
        return $this->teams !== [];
    }

    /**
     * @return 'fullstack'|'projects'|null null: fora das métricas por pessoa e da capacidade
     */
    public function teamOf(string $name): ?string
    {
        $key = SprintRules::normalize($name);
        if ($this->configured()) {
            return $this->teams[$key] ?? null;
        }

        return in_array($key, $this->excluded, true) ? null : self::FULLSTACK;
    }

    /** Nome da pessoa como foi classificado, para o mesmo dev não aparecer duas vezes com grafias diferentes. */
    public function canonicalName(string $name): string
    {
        return $this->names[SprintRules::normalize($name)] ?? trim($name);
    }

    public function counts(string $name): bool
    {
        return $this->teamOf($name) !== null;
    }

    /**
     * Pessoas classificadas, para a capacidade contar também quem não tem card na sprint.
     *
     * @return list<array{name: string, group: string}>
     */
    public function members(): array
    {
        return array_map(fn (array $member): array => ['name' => $member['name'], 'group' => $member['group']], $this->settings['team']);
    }

    /**
     * Capacidade da pessoa na sprint, em minutos: o valor salvo nesta sprint; sem ele, o último salvo numa sprint
     * anterior (herança); sem nenhum, o padrão das Configurações.
     *
     * @param  array<string, int>  $overrides  chave "sprint|nome"
     */
    public function capacityMinutes(array $overrides, int $sprint, string $name): int
    {
        $target = SprintRules::normalize($name);
        $bestSprint = null;
        $value = null;
        foreach ($overrides as $key => $minutes) {
            [$keySprint, $keyName] = array_pad(explode('|', (string) $key, 2), 2, '');
            if ((int) $keySprint > $sprint || SprintRules::normalize($keyName) !== $target) {
                continue;
            }
            if ($bestSprint === null || (int) $keySprint > $bestSprint) {
                $bestSprint = (int) $keySprint;
                $value = (int) $minutes;
            }
        }

        return $value ?? (int) round((float) $this->settings['capacityHours'] * 60);
    }
}
