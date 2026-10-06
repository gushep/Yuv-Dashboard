<?php

use App\Models\User;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

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
