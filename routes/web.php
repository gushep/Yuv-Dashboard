<?php

use App\Http\Controllers\FeatureRequestController;
use App\Http\Controllers\MondayController;
use App\Http\Controllers\SupportTicketController;
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

    Route::middleware('screen:settings')->group(function () {
        Route::post('/api/boards', [MondayController::class, 'boards']);
        Route::post('/api/inspect', [MondayController::class, 'inspect']);
        Route::post('/api/config', [MondayController::class, 'saveConfig']);
        Route::post('/api/refresh', [MondayController::class, 'saveRefresh']);
        Route::post('/api/metric-settings', [MondayController::class, 'saveMetricSettings'])->name('metric-settings.store');
        Route::post('/api/board-tabs', [MondayController::class, 'saveBoardTabs'])->name('board-tabs.store');
    });
    Route::post('/api/capacity', [MondayController::class, 'saveCapacity'])->middleware('screen:capacity');
    Route::post('/api/support-reserves', [MondayController::class, 'saveSupportReserve'])->middleware('screen:consumption')->name('support-reserves.store');
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

    Route::apiResource('/api/users', UserController::class)->except('show')->middleware('screen:users');
});
