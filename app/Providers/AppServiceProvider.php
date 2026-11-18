<?php

namespace App\Providers;

use App\Models\PassportKey;
use App\Models\User;
use App\Screen;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use Laravel\Passport\Events\AccessTokenCreated;
use Laravel\Passport\Passport;
use League\OAuth2\Server\AuthorizationServer;
use League\OAuth2\Server\ResourceServer;

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
        Gate::define('conduct-planning', fn (User $user): bool => $user->canConductPlanning());
        Gate::define('manage-capacity', fn (User $user): bool => $user->canManageCapacity());

        $this->configureOAuth();

        // O CDN da hospedagem guarda .js e .css por 7 dias; a versão no endereço faz o navegador buscar o arquivo novo após cada deploy.
        URL::macro('versioned', function (string $path): string {
            $file = public_path($path);

            return asset($path).(is_file($file) ? '?v='.filemtime($file) : '');
        });
    }

    /**
     * OAuth do MCP (Passport): tela de consentimento em português, validade dos tokens, chaves de assinatura guardadas
     * no banco e limite de chamadas por pessoa.
     */
    private function configureOAuth(): void
    {
        Passport::tokensExpireIn(now()->addMinutes((int) config('mcp.access_token_minutes', 60)));
        Passport::refreshTokensExpireIn(now()->addDays((int) config('mcp.refresh_token_days', 30)));
        Event::listen(AccessTokenCreated::class, function (AccessTokenCreated $event): void {
            Log::info('Autorização OAuth emitida', ['user_id' => $event->userId, 'client_id' => $event->clientId]);
        });

        // Sem a permissão "Acesso ao MCP", a pessoa vê o aviso no lugar do consentimento e o pedido é descartado.
        Passport::authorizationView(function (array $parameters) {
            if (! $parameters['user']->canUseMcp()) {
                $parameters['request']->session()->forget(['authToken', 'authRequest']);

                return response()->view('oauth.forbidden', $parameters, 403);
            }

            return response()->view('oauth.authorize', $parameters);
        });

        // As chaves só são carregadas (ou geradas, na primeira vez) quando o OAuth é usado de fato.
        foreach ([AuthorizationServer::class, ResourceServer::class] as $server) {
            $this->app->beforeResolving($server, function (): void {
                if (config('passport.private_key') && config('passport.public_key')) {
                    return;
                }
                $key = PassportKey::current();
                config(['passport.private_key' => $key->private_key, 'passport.public_key' => $key->public_key]);
            });
        }

        RateLimiter::for('mcp', fn (Request $request) => Limit::perMinute(120)->by($request->user()?->getAuthIdentifier() ?: $request->ip()));
    }
}
