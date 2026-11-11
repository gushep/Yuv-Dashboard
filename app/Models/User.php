<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Screen;
use App\Theme;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Casts\AsEnumCollection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Collection;

/**
 * @property Collection<int, Screen>|null $screens
 * @property Theme $theme
 */
#[Fillable(['name', 'email', 'password', 'screens', 'can_update_items', 'can_create_tickets', 'can_conduct_planning', 'can_estimate_planning'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * Usuário novo começa no tema claro, como a coluna do banco.
     *
     * @var array<string, string>
     */
    protected $attributes = ['theme' => 'light'];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'screens' => AsEnumCollection::of(Screen::class),
            'can_update_items' => 'boolean',
            'can_create_tickets' => 'boolean',
            'can_conduct_planning' => 'boolean',
            'can_estimate_planning' => 'boolean',
            'theme' => Theme::class,
        ];
    }

    /**
     * Atualizar cards no Monday (status, comentários e anexos) exige a tela Tarefas e a permissão específica.
     */
    public function canUpdateItems(): bool
    {
        return $this->can_update_items && $this->canAccessScreen(Screen::RawData);
    }

    /**
     * Abrir tickets exige a permissão específica e uma das telas que têm o botão Novo Ticket.
     */
    public function canCreateTickets(): bool
    {
        return $this->can_create_tickets && ($this->canAccessScreen(Screen::RawData) || $this->canAccessScreen(Screen::Firefighter));
    }

    /**
     * Maestro Scrum: escolhe o card, revela os votos e grava a estimativa escolhida no Monday. Exige a tela Planning.
     */
    public function canConductPlanning(): bool
    {
        return $this->can_conduct_planning && $this->canAccessScreen(Screen::Planning);
    }

    /**
     * Maestro Scrum também é quem ajusta as horas de cada pessoa no Capacity e a reserva de suporte no Consumo de
     * horas. Não depende da tela Planning; a tela de cada ajuste é conferida na rota.
     */
    public function canManageCapacity(): bool
    {
        return (bool) $this->can_conduct_planning;
    }

    /**
     * Joga a carta com as horas na planning: é o time de desenvolvimento. Quem só tem a tela assiste à mesa.
     */
    public function canEstimatePlanning(): bool
    {
        return $this->can_estimate_planning && $this->canAccessScreen(Screen::Planning);
    }

    public function canAccessScreen(Screen $screen): bool
    {
        return (bool) $this->screens?->contains($screen);
    }

    /**
     * Telas liberadas, na ordem do menu lateral.
     *
     * @return list<string>
     */
    public function screenValues(): array
    {
        return array_values(array_filter(Screen::values(), fn (string $value): bool => $this->canAccessScreen(Screen::from($value))));
    }
}
