<?php

use App\Models\MondayConnection;
use App\Models\User;
use App\Services\MondayActivityImporter;
use Illuminate\Foundation\Inspiring;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('app:create-admin {email} {name}', function (string $email, string $name) {
    if (User::where('email', $email)->exists()) {
        $this->error('Já existe um usuário com esse e-mail.');

        return 1;
    }
    $password = $this->secret('Senha (mínimo 10 caracteres)');
    $confirmation = $this->secret('Repita a senha');
    if (! $password || strlen($password) < 10 || $password !== $confirmation) {
        $this->error('A senha precisa ter ao menos 10 caracteres e coincidir com a confirmação.');

        return 1;
    }
    User::create(['name' => $name, 'email' => $email, 'password' => $password]);
    $this->info('Usuário criado.');

    return 0;
})->purpose('Cria um usuário inicial do dashboard');

Artisan::command('monday:import-activity', function (MondayActivityImporter $importer) {
    $connection = MondayConnection::current();
    if ($connection->board_id === '' || ! $connection->token) {
        $this->warn('Monday não configurado: nada a copiar.');

        return 0;
    }
    try {
        $result = $importer->import($connection);
    } catch (ConnectionException|RuntimeException $exception) {
        report($exception);
        $this->error('Falha ao copiar o log de atividades: '.$exception->getMessage());

        return 1;
    }
    $this->info("{$result['imported']} evento(s) novo(s) copiado(s) do Monday.");

    return 0;
})->purpose('Copia o log de atividades do Monday para preservar o histórico das sprints');

Schedule::command('monday:import-activity')->everyFifteenMinutes()->withoutOverlapping(30);
