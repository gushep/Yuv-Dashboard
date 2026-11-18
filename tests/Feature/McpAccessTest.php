<?php

namespace Tests\Feature;

use App\Models\McpAuditLog;
use App\Models\PassportKey;
use App\Models\User;
use App\Screen;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Laravel\Passport\Passport;
use Laravel\Passport\Token;
use League\OAuth2\Server\ResourceServer;
use phpseclib4\Crypt\RSA;
use Tests\TestCase;

class McpAccessTest extends TestCase
{
    use RefreshDatabase;

    private const REDIRECT_URI = 'https://claude.ai/api/mcp/auth_callback';

    /** @var array{private: string, public: string}|null */
    private static ?array $keys = null;

    protected function setUp(): void
    {
        parent::setUp();
        // Um par de chaves pequeno, gerado uma vez, para assinar os tokens nos testes.
        self::$keys ??= (function (): array {
            $key = RSA::createKey(2048);

            return ['private' => (string) $key, 'public' => (string) $key->getPublicKey()];
        })();
        config(['passport.private_key' => self::$keys['private'], 'passport.public_key' => self::$keys['public']]);
    }

    public function test_discovery_points_ai_tools_to_the_metrics_authorization_server(): void
    {
        $this->getJson('/.well-known/oauth-authorization-server')->assertOk()
            ->assertJsonPath('authorization_endpoint', route('passport.authorizations.authorize'))
            ->assertJsonPath('token_endpoint', route('passport.token'))
            ->assertJsonPath('registration_endpoint', url('/oauth/register'))
            ->assertJsonPath('code_challenge_methods_supported', ['S256']);
        $this->getJson('/.well-known/oauth-protected-resource/mcp')->assertOk()->assertJsonPath('resource', url('/mcp'));
    }

    public function test_mcp_without_a_valid_token_answers_401_with_the_discovery_address(): void
    {
        $this->postJson('/mcp', $this->rpc('tools/list'))->assertUnauthorized()
            ->assertHeader('WWW-Authenticate', 'Bearer realm="mcp", resource_metadata="'.url('/.well-known/oauth-protected-resource/mcp').'"');
        $this->withToken('token-inventado')->postJson('/mcp', $this->rpc('tools/list'))->assertUnauthorized();
        $this->assertDatabaseCount('mcp_audit_logs', 0);
    }

    public function test_an_authorized_ai_tool_reads_the_current_account_and_the_call_is_audited(): void
    {
        $user = User::factory()->mcpUser()->withScreens(Screen::Dashboard, Screen::Planning)->create(['name' => 'Ana']);

        $token = $this->authorize($user);
        $response = $this->mcp($token, $this->rpc('tools/call', ['name' => 'current-account', 'arguments' => []]))->assertOk();

        $account = json_decode($response->json('result.content.0.text'), true);
        $this->assertSame('Ana', $account['nome']);
        $this->assertSame(['dashboard', 'planning'], array_column($account['telas'], 'tela'));
        $this->assertDatabaseHas('mcp_audit_logs', ['user_id' => $user->id, 'client_name' => 'Claude', 'method' => 'tools/call', 'tool' => 'current-account', 'status' => 200]);
    }

    public function test_without_the_mcp_permission_the_consent_is_refused_and_no_code_is_issued(): void
    {
        $user = User::factory()->create(['email' => 'bia@yuv.com.br']);
        $client = $this->registerClient();

        $this->actingAs($user)->get($this->authorizeUrl($client))
            ->assertForbidden()->assertSee('Acesso ao MCP não liberado')->assertDontSee('Autorizar</button>', false);
        $this->assertNull(session('authToken'));

        $this->post(route('passport.authorizations.approve'), ['client_id' => $client, 'auth_token' => 'qualquer', 'state' => ''])
            ->assertForbidden();
        $this->assertDatabaseCount('oauth_auth_codes', 0);
    }

    public function test_a_valid_token_stops_working_when_the_permission_is_removed(): void
    {
        $user = User::factory()->mcpUser()->create();
        $token = $this->authorize($user);

        $user->update(['can_use_mcp' => false]);

        $this->mcp($token, $this->rpc('tools/list'))->assertUnauthorized();
        $this->assertSame(0, Token::query()->where('user_id', $user->id)->where('revoked', false)->count());
    }

    public function test_deleting_the_user_revokes_the_ai_tools(): void
    {
        $user = User::factory()->mcpUser()->create();
        $this->authorize($user);

        $user->delete();

        $this->assertSame(0, Token::query()->where('user_id', $user->id)->where('revoked', false)->count());
    }

    public function test_the_permission_is_checked_on_every_call(): void
    {
        Passport::actingAs(User::factory()->create(), ['mcp:use'], 'api');

        $this->postJson('/mcp', $this->rpc('tools/list'))->assertForbidden()->assertJsonPath('error', 'access_denied');
    }

    public function test_registration_only_accepts_return_addresses_of_the_supported_ai_tools(): void
    {
        $this->postJson('/oauth/register', ['client_name' => 'Golpe', 'redirect_uris' => ['https://claude.ai.golpe.com/callback']])
            ->assertStatus(400)->assertJsonPath('error', 'invalid_redirect_uri');
        $this->postJson('/oauth/register', ['client_name' => 'Gemini CLI', 'redirect_uris' => ['http://localhost:7777/oauth/callback']])->assertCreated();
        $this->postJson('/oauth/register', ['client_name' => 'ChatGPT', 'redirect_uris' => ['https://chatgpt.com/connector_platform_oauth_redirect']])->assertCreated();
    }

    public function test_the_consent_page_escapes_the_name_sent_by_the_ai_tool(): void
    {
        $user = User::factory()->mcpUser()->create();
        $client = $this->registerClient('<script>alert(1)</script>');

        $this->actingAs($user)->get($this->authorizeUrl($client))->assertOk()
            ->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false)->assertDontSee('<script>alert(1)</script>', false);
    }

    public function test_the_person_lists_and_revokes_only_their_own_ai_tools(): void
    {
        $ana = User::factory()->mcpUser()->create();
        $bruno = User::factory()->mcpUser()->create();
        $client = $this->registerClient();
        $this->authorize($ana, $client);
        $this->authorize($bruno, $client);

        $this->actingAs($ana)->get(route('mcp.connections'))->assertOk()->assertSee('Claude')->assertSee(url('/mcp'));
        $this->actingAs($ana)->delete(route('mcp.connections.destroy', $client))->assertRedirect(route('mcp.connections'));

        $this->assertSame(0, Token::query()->where('user_id', $ana->id)->where('revoked', false)->count());
        $this->assertSame(1, Token::query()->where('user_id', $bruno->id)->where('revoked', false)->count());
        $this->actingAs($ana)->get(route('mcp.connections'))->assertSee('Nenhuma ferramenta conectada.');
    }

    public function test_user_administration_grants_the_permission_and_revokes_the_ai_tools_of_a_person(): void
    {
        $admin = User::factory()->create(['name' => 'Admin']);
        $ana = User::factory()->withScreens(Screen::Dashboard)->create(['name' => 'Ana']);

        $this->actingAs($admin)->putJson("/api/users/{$ana->id}", ['name' => $ana->name, 'email' => $ana->email, 'screens' => ['dashboard'], 'can_use_mcp' => true])
            ->assertOk()->assertJsonPath('user.canUseMcp', true);
        $this->authorize($ana->refresh());
        $this->actingAs($admin)->getJson('/api/users')->assertJsonPath('users.1.name', 'Ana')->assertJsonPath('users.1.mcpConnections', 1);

        $this->actingAs($ana)->postJson("/api/users/{$ana->id}/mcp-revoke")->assertForbidden();
        $this->actingAs($admin)->postJson("/api/users/{$ana->id}/mcp-revoke")->assertOk()->assertJsonPath('revoked', 1);
        $this->assertSame(0, Token::query()->where('user_id', $ana->id)->where('revoked', false)->count());
    }

    public function test_signing_keys_are_generated_once_and_stored_encrypted_in_the_database(): void
    {
        config(['passport.private_key' => null, 'passport.public_key' => null]);
        $user = User::factory()->mcpUser()->create();

        $token = $this->authorize($user);
        $this->mcp($token, $this->rpc('tools/list'))->assertOk();

        $this->assertDatabaseCount('passport_keys', 1);
        $stored = DB::table('passport_keys')->value('private_key');
        $this->assertStringNotContainsString('PRIVATE KEY', $stored);
        $this->assertStringContainsString('PRIVATE KEY', PassportKey::current()->private_key);

        $this->artisan('mcp:rotate-keys', ['--force' => true])->assertSuccessful();
        config(['passport.private_key' => null, 'passport.public_key' => null]);
        $this->app->forgetInstance(ResourceServer::class);
        $this->mcp($token, $this->rpc('tools/list'))->assertUnauthorized();
    }

    public function test_the_audit_keeps_ninety_days(): void
    {
        $user = User::factory()->create();
        $old = McpAuditLog::create(['user_id' => $user->id, 'method' => 'tools/list', 'status' => 200]);
        $old->forceFill(['created_at' => now()->subDays(91)])->save();
        $recent = McpAuditLog::create(['user_id' => $user->id, 'method' => 'tools/list', 'status' => 200]);

        $this->artisan('model:prune', ['--model' => [McpAuditLog::class]])->assertSuccessful();

        $this->assertModelMissing($old);
        $this->assertModelExists($recent);
    }

    /**
     * Fluxo OAuth completo, como a ferramenta de IA faz: registro, consentimento, código e troca pelo token (PKCE).
     */
    private function authorize(User $user, ?string $client = null): string
    {
        $client ??= $this->registerClient();
        $verifier = Str::random(64);
        // No teste, o controller do Passport fica guardado na rota com o usuário da autorização anterior.
        foreach (['passport.authorizations.authorize', 'passport.authorizations.approve'] as $route) {
            $this->app['router']->getRoutes()->getByName($route)->flushController();
        }
        $this->actingAs($user)->get($this->authorizeUrl($client, $verifier))->assertOk()->assertSee('Autorizar');
        $redirect = $this->post(route('passport.authorizations.approve'), ['client_id' => $client, 'auth_token' => session('authToken'), 'state' => ''])
            ->assertRedirect()->headers->get('Location');
        $this->assertStringStartsWith(self::REDIRECT_URI, $redirect);
        parse_str((string) parse_url($redirect, PHP_URL_QUERY), $query);

        $token = $this->postJson(route('passport.token'), [
            'grant_type' => 'authorization_code', 'client_id' => $client, 'redirect_uri' => self::REDIRECT_URI,
            'code_verifier' => $verifier, 'code' => $query['code'],
        ])->assertOk()->json('access_token');
        $this->app['auth']->forgetGuards();
        $this->flushSession();

        return $token;
    }

    private function registerClient(string $name = 'Claude'): string
    {
        return $this->postJson('/oauth/register', ['client_name' => $name, 'redirect_uris' => [self::REDIRECT_URI]])->assertCreated()->json('client_id');
    }

    private function authorizeUrl(string $client, string $verifier = 'verificador-com-mais-de-quarenta-e-tres-caracteres-123'): string
    {
        return route('passport.authorizations.authorize', [
            'client_id' => $client, 'redirect_uri' => self::REDIRECT_URI, 'response_type' => 'code', 'scope' => 'mcp:use', 'state' => 'estado',
            'code_challenge' => rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '='), 'code_challenge_method' => 'S256',
        ]);
    }

    private function mcp(string $token, array $payload): TestResponse
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($token)->postJson('/mcp', $payload);
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    private function rpc(string $method, array $params = []): array
    {
        return ['jsonrpc' => '2.0', 'id' => 1, 'method' => $method, 'params' => (object) $params];
    }
}
