<?php

namespace Tests\Feature;

use App\Models\User;
use App\Screen;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class UserManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_management_requires_login(): void
    {
        $this->getJson('/api/users')->assertUnauthorized();
    }

    public function test_user_without_users_screen_cannot_manage_users(): void
    {
        $this->actingAs(User::factory()->withScreens(Screen::Dashboard)->create());

        $this->getJson('/api/users')->assertForbidden();
        $this->postJson('/api/users', ['name' => 'Ana', 'email' => 'ana@yuv.com.br', 'password' => 'segredo123', 'screens' => []])->assertForbidden();
        $this->assertDatabaseMissing('users', ['email' => 'ana@yuv.com.br']);
    }

    public function test_listing_returns_users_with_their_screens_and_the_screen_catalog(): void
    {
        $admin = User::factory()->create(['name' => 'Admin']);
        User::factory()->withScreens(Screen::Release, Screen::Dashboard)->create(['name' => 'Bia']);

        $this->actingAs($admin)->getJson('/api/users')
            ->assertOk()
            ->assertJsonPath('users.1.name', 'Bia')
            ->assertJsonPath('users.1.screens', ['dashboard', 'release'])
            ->assertJsonPath('screens.0', ['value' => 'control-tower', 'label' => 'Torre de controle'])
            ->assertJsonCount(count(Screen::cases()), 'screens');
    }

    public function test_admin_creates_user_with_selected_screens_who_can_then_log_in(): void
    {
        $this->actingAs(User::factory()->create());

        $this->postJson('/api/users', ['name' => 'Ana Souza', 'email' => 'ana@yuv.com.br', 'password' => 'segredo123', 'screens' => ['release', 'capacity']])
            ->assertCreated()
            ->assertJsonPath('user.screens', ['capacity', 'release']);

        $user = User::firstWhere('email', 'ana@yuv.com.br');
        $this->assertTrue(Hash::check('segredo123', $user->password));
        $this->assertTrue($user->canAccessScreen(Screen::Release));
        $this->assertFalse($user->canAccessScreen(Screen::Settings));
    }

    public function test_creation_rejects_missing_fields_short_password_duplicate_email_and_unknown_screen(): void
    {
        $admin = User::factory()->create(['email' => 'admin@yuv.com.br']);
        $this->actingAs($admin);

        $this->postJson('/api/users', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['name' => 'Informe o nome.', 'email', 'password', 'screens']);

        $this->postJson('/api/users', ['name' => 'Ana', 'email' => 'admin@yuv.com.br', 'password' => 'curta', 'screens' => ['financeiro']])
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'email' => 'Já existe um usuário com este e-mail.',
                'password' => 'A senha deve ter pelo menos 8 caracteres.',
                'screens.0' => 'Tela inválida.',
            ]);

        $this->assertSame(1, User::count());
    }

    public function test_updating_without_password_keeps_the_current_one(): void
    {
        $this->actingAs(User::factory()->create());
        $user = User::factory()->withScreens(Screen::Dashboard)->create(['password' => 'senhaAntiga1']);

        $this->putJson("/api/users/{$user->id}", ['name' => 'Novo Nome', 'email' => $user->email, 'password' => '', 'screens' => ['forecast']])
            ->assertOk()
            ->assertJsonPath('user.screens', ['forecast']);

        $user->refresh();
        $this->assertSame('Novo Nome', $user->name);
        $this->assertTrue(Hash::check('senhaAntiga1', $user->password));
        $this->assertFalse($user->canAccessScreen(Screen::Dashboard));
    }

    public function test_admin_grants_and_revokes_permission_to_change_card_status(): void
    {
        $this->actingAs(User::factory()->create());
        $user = User::factory()->withScreens(Screen::RawData)->create();
        $payload = ['name' => $user->name, 'email' => $user->email, 'screens' => ['raw']];

        $this->putJson("/api/users/{$user->id}", [...$payload, 'can_update_items' => true])
            ->assertOk()->assertJsonPath('user.canUpdateItems', true);
        $this->assertTrue($user->refresh()->canUpdateItems());

        $this->putJson("/api/users/{$user->id}", [...$payload, 'can_update_items' => false])
            ->assertOk()->assertJsonPath('user.canUpdateItems', false);
        $this->assertFalse($user->refresh()->canUpdateItems());

        $this->putJson("/api/users/{$user->id}", [...$payload, 'can_create_tickets' => true])
            ->assertOk()->assertJsonPath('user.canCreateTickets', true)->assertJsonPath('user.canUpdateItems', false);
        $this->assertTrue($user->refresh()->canCreateTickets());
    }

    public function test_updating_with_password_replaces_it(): void
    {
        $this->actingAs(User::factory()->create());
        $user = User::factory()->create();

        $this->putJson("/api/users/{$user->id}", ['name' => $user->name, 'email' => $user->email, 'password' => 'novaSenha99', 'screens' => []])->assertOk();

        $this->assertTrue(Hash::check('novaSenha99', $user->refresh()->password));
    }

    public function test_admin_cannot_remove_own_access_to_users_screen(): void
    {
        $admin = User::factory()->create();
        $this->actingAs($admin);

        $this->putJson("/api/users/{$admin->id}", ['name' => $admin->name, 'email' => $admin->email, 'screens' => ['dashboard']])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['screens' => 'Você não pode remover o seu próprio acesso à tela de Usuários.']);

        $this->assertTrue($admin->refresh()->canAccessScreen(Screen::Users));
    }

    public function test_admin_deletes_another_user_but_not_themselves(): void
    {
        $admin = User::factory()->create();
        $other = User::factory()->create();
        $this->actingAs($admin);

        $this->deleteJson("/api/users/{$admin->id}")
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['user' => 'Você não pode excluir o seu próprio usuário.']);
        $this->deleteJson("/api/users/{$other->id}")->assertOk();

        $this->assertModelExists($admin);
        $this->assertModelMissing($other);
    }

    public function test_menu_lists_only_the_screens_released_to_the_user(): void
    {
        $this->actingAs(User::factory()->withScreens(Screen::Release, Screen::Capacity)->create());

        $this->get('/')
            ->assertOk()
            ->assertSee('id="nav-release"', false)
            ->assertSee('id="nav-capacity"', false)
            ->assertDontSee('id="nav-settings"', false)
            ->assertDontSee('id="nav-users"', false)
            ->assertDontSee('id="nav-sprint"', false)
            ->assertSee('const ALLOWED_SCREENS=["capacity","release"]', false);
    }

    public function test_screen_apis_refuse_users_without_that_screen(): void
    {
        $this->actingAs(User::factory()->withScreens(Screen::Dashboard)->create());

        $this->postJson('/api/release', [])->assertForbidden();
        $this->getJson('/api/control-tower')->assertForbidden();
        $this->postJson('/api/metric-settings', ['capacityHours' => 80, 'excludedPeople' => []])->assertForbidden();
        $this->postJson('/api/capacity', [])->assertForbidden();
        $this->postJson('/api/support-reserves', ['sprint' => '10', 'minutes' => 60])->assertForbidden();
        $this->getJson('/api/config')->assertOk();
    }
}
