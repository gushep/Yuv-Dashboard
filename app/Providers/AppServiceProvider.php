<?php

namespace App\Providers;

use App\Models\User;
use App\Screen;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Gate::define('access-screen', fn (User $user, string $screen): bool => $user->canAccessScreen(Screen::from($screen)));
        Gate::define('update-items', fn (User $user): bool => $user->canUpdateItems());
        Gate::define('create-tickets', fn (User $user): bool => $user->canCreateTickets());

        // O CDN da hospedagem guarda .js e .css por 7 dias; a versão no endereço faz o navegador buscar o arquivo novo após cada deploy.
        URL::macro('versioned', function (string $path): string {
            $file = public_path($path);

            return asset($path).(is_file($file) ? '?v='.filemtime($file) : '');
        });
    }
}
