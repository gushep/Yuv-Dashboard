<?php

use App\Http\Controllers\DailyController;
use App\Http\Controllers\FeatureRequestController;
use App\Http\Controllers\MondayController;
use App\Http\Controllers\PlanningController;
use App\Http\Controllers\RetrospectiveController;
use App\Http\Controllers\SupportTicketController;
use App\Http\Controllers\ThemeController;
use App\Http\Controllers\UserController;
use App\Screen;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

Route::middleware('guest')->group(function () {
    Route::get('/login', fn () => view('login'))->name('login');
    Route::post('/login', function (Request $request) {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);
        if (! Auth::attempt($credentials)) {
            return back()->withErrors(['email' => 'E-mail ou senha inválidos.'])->onlyInput('email');
        }
        $request->session()->regenerate();

        return redirect()->intended('/');
    })->middleware('throttle:5,1');
});

Route::middleware('auth')->group(function () {
    // Cada tela tem seu endereço, mas todas são servidas pela mesma página; quem decide o acesso é a tela no front e a API.
    Route::get('/', fn () => view('dashboard'))->name('home');
    // A tela Dado Bruto passou a se chamar Tarefas; links antigos continuam funcionando.
    Route::get('/dados/{sprint?}', function (Request $request, ?string $sprint = null) {
        $query = http_build_query(array_filter(['sprint' => $sprint, ...$request->query()]));

        return redirect('/tarefas'.($query === '' ? '' : "?{$query}"), 301);
    })->where('sprint', '[0-9]{1,6}');
    Route::get('/{screen}/{sprint?}', fn () => view('dashboard'))
        ->where(['screen' => implode('|', Screen::slugs()), 'sprint' => '[0-9]{1,6}'])
        ->name('screen');
    Route::post('/logout', function (Request $request) {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/login');
    });

    // Leituras compartilhadas: todas as telas dependem da configuração e dos dados sincronizados.
    Route::get('/api/config', [MondayController::class, 'config']);
    Route::get('/api/capacity', [MondayController::class, 'capacity']);
    Route::get('/api/support-reserves', [MondayController::class, 'supportReserves'])->name('support-reserves.index');
    Route::post('/api/sync', [MondayController::class, 'sync']);
    Route::post('/api/theme', ThemeController::class)->name('theme.update');

    Route::middleware('screen:settings')->group(function () {
        Route::post('/api/boards', [MondayController::class, 'boards']);
        Route::post('/api/inspect', [MondayController::class, 'inspect']);
        Route::post('/api/config', [MondayController::class, 'saveConfig']);
        Route::post('/api/refresh', [MondayController::class, 'saveRefresh']);
        Route::post('/api/metric-settings', [MondayController::class, 'saveMetricSettings'])->name('metric-settings.store');
        Route::get('/api/monday-users', [MondayController::class, 'mondayUsers'])->name('monday-users.index');
        Route::post('/api/board-tabs', [MondayController::class, 'saveBoardTabs'])->name('board-tabs.store');
    });
    // Horas por pessoa e reserva de suporte: só o Maestro Scrum altera.
    Route::post('/api/capacity', [MondayController::class, 'saveCapacity'])->middleware(['screen:capacity', 'can:manage-capacity'])->name('capacity.store');
    Route::post('/api/support-reserves', [MondayController::class, 'saveSupportReserve'])->middleware(['screen:consumption', 'can:manage-capacity'])->name('support-reserves.store');
    Route::post('/api/release', [MondayController::class, 'release'])->middleware('screen:release')->name('release.data');
    Route::get('/api/control-tower', [MondayController::class, 'controlTower'])->middleware('screen:control-tower')->name('control-tower.show');
    Route::get('/api/burndown', [MondayController::class, 'burndown'])->middleware('screen:burndown')->name('burndown.show');
    Route::get('/api/board-tabs/{tab}', [MondayController::class, 'boardTab'])->whereIn('tab', ['roadmap', 'backlog'])->middleware('screen:raw')->name('board-tabs.show');
    Route::post('/api/items/bulk', [MondayController::class, 'changeItems'])->middleware(['screen:raw', 'can:update-items'])->name('items.bulk');
    Route::middleware('screen:raw')->whereNumber('item')->group(function () {
        Route::get('/api/items/{item}', [MondayController::class, 'item'])->name('items.show');
        Route::get('/api/items/{item}/activity', [MondayController::class, 'itemActivity'])->name('items.activity');
        Route::post('/api/items/{item}/status', [MondayController::class, 'changeItemStatus'])->middleware('can:update-items')->name('items.status');
        Route::post('/api/items/{item}/columns', [MondayController::class, 'changeItemColumn'])->middleware('can:update-items')->name('items.columns.update');
        Route::get('/api/items/{item}/columns/{column}/options', [MondayController::class, 'itemColumnOptions'])
            ->where('column', '[A-Za-z0-9_]{1,100}')->middleware('can:update-items')->name('items.columns.options');
        Route::post('/api/items/{item}/comments', [MondayController::class, 'postItemComment'])->middleware('can:update-items')->name('items.comments.store');
        Route::post('/api/items/{item}/comments/{update}/files', [MondayController::class, 'attachItemCommentFile'])
            ->whereNumber('update')->middleware('can:update-items')->name('items.comments.files.store');
    });

    Route::middleware('can:create-tickets')->group(function () {
        Route::get('/api/tickets/options', [SupportTicketController::class, 'options'])->name('tickets.options');
        Route::post('/api/tickets', [SupportTicketController::class, 'store'])->name('tickets.store');
        Route::post('/api/tickets/{item}/comments/{comment}/files', [SupportTicketController::class, 'attachFile'])
            ->whereNumber(['item', 'comment'])->name('tickets.files.store');
        Route::get('/api/feature-requests/options', [FeatureRequestController::class, 'options'])->name('feature-requests.options');
        Route::post('/api/feature-requests', [FeatureRequestController::class, 'store'])->name('feature-requests.store');
        Route::post('/api/feature-requests/{item}/files', [FeatureRequestController::class, 'attachFile'])
            ->whereNumber('item')->name('feature-requests.files.store');
    });

    // Daily Scrum (Daily Hub): ações da Daily para quem tem a tela; publicar no card exige "Atualizar cards" (conferido no serviço).
    Route::middleware('screen:sprint-retrospective')->prefix('/api/retro')->name('retro.')->group(function () {
        Route::get('/', [RetrospectiveController::class, 'show'])->name('show');
        Route::get('/room', [RetrospectiveController::class, 'room'])->name('room');
        Route::get('/sprints/{sprint}', [RetrospectiveController::class, 'review'])->whereNumber('sprint')->name('review');
        Route::post('/retros', [RetrospectiveController::class, 'start'])->name('start');
        Route::whereNumber(['retro', 'card', 'topic', 'action'])->group(function () {
            Route::post('/retros/{retro}/step', [RetrospectiveController::class, 'step'])->name('step');
            Route::post('/retros/{retro}/setup', [RetrospectiveController::class, 'setup'])->name('setup');
            Route::post('/retros/{retro}/reveal', [RetrospectiveController::class, 'reveal'])->name('reveal');
            Route::post('/retros/{retro}/cards', [RetrospectiveController::class, 'addCard'])->name('cards.store');
            Route::post('/retros/{retro}/goal-vote', [RetrospectiveController::class, 'goalVote'])->name('goal-vote');
            Route::post('/retros/{retro}/topics', [RetrospectiveController::class, 'addTopic'])->name('topics.store');
            Route::post('/retros/{retro}/actions', [RetrospectiveController::class, 'addAction'])->name('actions.store');
            Route::post('/retros/{retro}/close', [RetrospectiveController::class, 'close'])->name('close');
            Route::post('/cards/{card}/vote', [RetrospectiveController::class, 'vote'])->name('cards.vote');
            Route::post('/cards/{card}/merge', [RetrospectiveController::class, 'merge'])->name('cards.merge');
            Route::post('/cards/{card}/delete', [RetrospectiveController::class, 'deleteCard'])->name('cards.delete');
            Route::post('/topics/{topic}', [RetrospectiveController::class, 'updateTopic'])->name('topics.update');
            Route::post('/actions/{action}/delete', [RetrospectiveController::class, 'deleteAction'])->name('actions.delete');
            Route::post('/actions/{action}/complete', [RetrospectiveController::class, 'complete'])->name('actions.complete');
            Route::post('/actions/{action}/drop', [RetrospectiveController::class, 'drop'])->name('actions.drop');
            Route::post('/actions/{action}/keep', [RetrospectiveController::class, 'keep'])->name('actions.keep');
            Route::post('/actions/{action}/reopen', [RetrospectiveController::class, 'reopen'])->name('actions.reopen');
        });
    });

    Route::middleware('screen:daily-scrum')->prefix('/api/daily')->name('daily.')->group(function () {
        Route::get('/', [DailyController::class, 'show'])->name('show');
        Route::post('/actions', [DailyController::class, 'store'])->name('actions.store');
        Route::whereNumber('action')->group(function () {
            Route::post('/actions/{action}/complete', [DailyController::class, 'complete'])->name('actions.complete');
            Route::post('/actions/{action}/reopen', [DailyController::class, 'reopen'])->name('actions.reopen');
            Route::post('/actions/{action}/publish', [DailyController::class, 'publish'])->name('actions.publish');
            Route::post('/actions/{action}/notice', [DailyController::class, 'notice'])->name('actions.notice');
            Route::post('/retro-actions/{action}/complete', [DailyController::class, 'completeRetroAction'])->name('retro-actions.complete');
            Route::post('/actions/{action}/preview', [DailyController::class, 'preview'])->name('actions.preview');
        });
    });

    Route::middleware('screen:planning')->prefix('/api/planning')->name('planning.')->group(function () {
        Route::get('/', [PlanningController::class, 'show'])->name('show');
        Route::get('/sprints', [PlanningController::class, 'history'])->name('history');
        Route::get('/sprints/{sprint}', [PlanningController::class, 'review'])->whereNumber('sprint')->name('review');
        Route::whereNumber(['session', 'round'])->group(function () {
            Route::get('/rounds/{round}/scope', [PlanningController::class, 'scope'])->name('rounds.scope');
            Route::post('/rounds/{round}/votes', [PlanningController::class, 'vote'])->name('votes.store');
            Route::middleware('can:conduct-planning')->group(function () {
                Route::post('/sessions', [PlanningController::class, 'openTable'])->name('sessions.store');
                Route::post('/sessions/{session}/close', [PlanningController::class, 'closeTable'])->name('sessions.close');
                Route::post('/sessions/{session}/rounds', [PlanningController::class, 'playCard'])->name('rounds.store');
                Route::post('/rounds/{round}/estimate', [PlanningController::class, 'startEstimate'])->name('rounds.estimate');
                Route::post('/rounds/{round}/reveal', [PlanningController::class, 'reveal'])->name('rounds.reveal');
                Route::post('/rounds/{round}/decision', [PlanningController::class, 'decide'])->name('rounds.decide');
                Route::post('/rounds/{round}/cancel', [PlanningController::class, 'cancel'])->name('rounds.cancel');
            });
        });
    });

    Route::apiResource('/api/users', UserController::class)->except('show')->middleware('screen:users');
});
