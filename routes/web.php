<?php

use App\Http\Controllers\MondayController;
use App\Http\Controllers\UserController;
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
    Route::get('/', fn () => view('dashboard'));
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
    });
    Route::post('/api/capacity', [MondayController::class, 'saveCapacity'])->middleware('screen:capacity');
    Route::post('/api/support-reserves', [MondayController::class, 'saveSupportReserve'])->middleware('screen:consumption')->name('support-reserves.store');
    Route::post('/api/release', [MondayController::class, 'release'])->middleware('screen:release')->name('release.data');
    Route::get('/api/control-tower', [MondayController::class, 'controlTower'])->middleware('screen:control-tower')->name('control-tower.show');

    Route::apiResource('/api/users', UserController::class)->except('show')->middleware('screen:users');
});
