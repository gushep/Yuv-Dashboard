<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Screen;
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
 */
#[Fillable(['name', 'email', 'password', 'screens', 'can_update_items', 'can_create_tickets'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

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
