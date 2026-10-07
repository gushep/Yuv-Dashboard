<?php

use App\Http\Controllers\MondayController;
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

    Route::get('/api/config', [MondayController::class, 'config']);
    Route::get('/api/capacity', [MondayController::class, 'capacity']);
    Route::get('/api/support-reserves', [MondayController::class, 'supportReserves'])->name('support-reserves.index');
    Route::post('/api/support-reserves', [MondayController::class, 'saveSupportReserve'])->name('support-reserves.store');
    Route::post('/api/boards', [MondayController::class, 'boards']);
    Route::post('/api/inspect', [MondayController::class, 'inspect']);
    Route::post('/api/config', [MondayController::class, 'saveConfig']);
    Route::post('/api/refresh', [MondayController::class, 'saveRefresh']);
    Route::post('/api/capacity', [MondayController::class, 'saveCapacity']);
    Route::post('/api/metric-settings', [MondayController::class, 'saveMetricSettings'])->name('metric-settings.store');
    Route::post('/api/sync', [MondayController::class, 'sync']);
    Route::post('/api/release', [MondayController::class, 'release'])->name('release.data');
});
