<?php

use App\Models\MondayConnection;
use App\Models\ReleaseSummary;
use App\Models\User;
use App\Services\BombeiroNotifier;
use App\Services\MondayActivityImporter;
use App\Services\MondaySnapshotService;
use App\Services\SprintReleaseService;
use Illuminate\Foundation\Inspiring;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;

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

Artisan::command('monday:refresh', function (MondaySnapshotService $snapshots, MondayActivityImporter $importer, BombeiroNotifier $notifier) {
    $connection = MondayConnection::current();
    if ($connection->board_id === '' || ! $connection->token) {
        $this->warn('Monday não configurado: nada a atualizar.');

        return 0;
    }
    try {
        $snapshot = $snapshots->current($connection, 0, true);
        $imported = $importer->import($connection);
        $snapshots->withFrozenSprints($snapshot, $connection);
    } catch (ConnectionException|RuntimeException $exception) {
        report($exception);
        $this->error('Falha ao atualizar os dados do Monday: '.$exception->getMessage());

        return 1;
    }
    $this->info(count($snapshot['rows'])." registro(s) atualizados e {$imported['imported']} evento(s) novo(s) copiado(s).");
    // Com o log em dia, as mudanças de status dos cards de Suporte vão para o canal do Bombeiro.
    $notified = $notifier->statusChanges($connection, $snapshot);
    if ($notified > 0) {
        $this->info("{$notified} mudança(s) de status avisada(s) no canal do Bombeiro.");
    }
    // Os quadros das abas Roadmap e Backlog (e da Visão 360°) também ficam prontos, para as telas não esperarem o Monday.
    foreach (array_keys(MondaySnapshotService::BOARD_TABS) as $tab) {
        try {
            $snapshots->boardTab($connection, $tab, 0, true);
        } catch (ConnectionException|RuntimeException $exception) {
            report($exception);
            $this->error("Falha ao atualizar o quadro da aba {$tab}: ".$exception->getMessage());
        }
    }

    return 0;
})->purpose('Atualiza os cards e os quadros das abas do Monday, copia o log de atividades e avisa o canal do Bombeiro, sem depender do dashboard aberto');

Schedule::command('monday:refresh')->everyFifteenMinutes()->withoutOverlapping(30);

Artisan::command('release:summaries-export {sprint : Número da sprint} {--all : Inclui também os cards que já têm descrição}', function (MondaySnapshotService $snapshots, SprintReleaseService $release, int $sprint) {
    $connection = MondayConnection::current();
    try {
        $snapshot = $snapshots->withFrozenSprints($snapshots->current($connection, 300), $connection);
        $pending = $release->pendingSummaries($snapshot, $sprint, (string) $connection->token, (string) $connection->board_id, (bool) $this->option('all'));
    } catch (ConnectionException|RuntimeException $exception) {
        $this->error('Falha ao consultar o Monday: '.$exception->getMessage());

        return 1;
    }
    $path = "release-summaries/sprint-{$sprint}.json";
    Storage::disk('local')->put($path, json_encode(['sprint' => $sprint, 'items' => $pending], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    $this->info(count($pending).' card(s) para resumir em '.Storage::disk('local')->path($path));

    return 0;
})->purpose('Exporta os cards implementados da sprint que precisam de resumo de release');

Artisan::command('release:summaries-import {file : Arquivo JSON com {"items": [{"id", "hash", "summary"}]}}', function (string $file) {
    $path = is_file($file) ? $file : Storage::disk('local')->path($file);
    $data = is_file($path) ? json_decode((string) file_get_contents($path), true) : null;
    if (! is_array($data)) {
        $this->error("Arquivo inválido ou não encontrado: {$file}");

        return 1;
    }
    $validator = Validator::make($data, [
        'items' => ['required', 'array', 'min:1'],
        'items.*.id' => ['required', 'string', 'regex:/^\d{1,32}$/'],
        'items.*.hash' => ['required', 'string', 'regex:/^[a-f0-9]{64}$/'],
        'items.*.summary' => ['required', 'string', 'min:20', 'max:400', 'not_regex:/https?:|www\.|@\S+\.\S+/i'],
    ]);
    if ($validator->fails()) {
        foreach ($validator->errors()->all() as $message) {
            $this->error($message);
        }

        return 1;
    }
    foreach ($validator->validated()['items'] as $item) {
        ReleaseSummary::query()->updateOrCreate(['item_id' => $item['id']], ['summary' => trim($item['summary']), 'source_hash' => $item['hash'], 'generated_by' => 'claude-code']);
    }
    $this->info(count($data['items']).' resumo(s) gravado(s).');

    return 0;
})->purpose('Grava os resumos de release escritos para os cards exportados');
