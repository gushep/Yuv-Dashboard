<?php

namespace App;

/**
 * Telas do dashboard que podem ser liberadas por usuário.
 * O valor é o mesmo identificador usado por navigate() no front-end.
 */
enum Screen: string
{
    case ControlTower = 'control-tower';
    case Dashboard = 'dashboard';
    case Sprints = 'sprint';
    case Epics = 'epicos';
    case Team = 'time';
    case RawData = 'raw';
    case Firefighter = 'bombeiro';
    case SprintPanel = 'sprint-panel';
    case Carousel = 'carousel';
    case Capacity = 'capacity';
    case Forecast = 'forecast';
    case Consumption = 'consumption';
    case Release = 'release';
    case Settings = 'settings';
    case Users = 'users';

    public function label(): string
    {
        return match ($this) {
            self::ControlTower => 'Torre de controle',
            self::Dashboard => 'Dashboard',
            self::Sprints => 'Sprints',
            self::Epics => 'Épicos',
            self::Team => 'Time',
            self::RawData => 'Dado Bruto',
            self::Firefighter => 'Painel do Bombeiro',
            self::SprintPanel => 'Painel da Sprint',
            self::Carousel => 'Apresentação dos Painéis',
            self::Capacity => 'Capacity',
            self::Forecast => 'Previsibilidade',
            self::Consumption => 'Consumo de horas',
            self::Release => 'Release da sprint',
            self::Settings => 'Configurações',
            self::Users => 'Usuários',
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(fn (self $screen): array => ['value' => $screen->value, 'label' => $screen->label()], self::cases());
    }
}
