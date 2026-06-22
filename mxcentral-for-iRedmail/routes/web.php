<?php

use App\Http\Controllers\Admin\AdminController;
use App\Http\Controllers\Admin\ApiController;
use App\Http\Controllers\Admin\MailController;
use App\Http\Controllers\Admin\PolicyController;
use App\Http\Controllers\Admin\SystemSettingsController;
use App\Http\Controllers\Auth\LoginController;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => redirect()->route('dashboard'));
Route::get('/login', [LoginController::class, 'create'])->name('login');
Route::post('/login', [LoginController::class, 'store'])->name('login.store');
Route::post('/logout', [LoginController::class, 'destroy'])->name('logout');

Route::middleware('iredmail.auth')->group(function () {
    Route::get('/preferences', [AdminController::class, 'users'])->name('preferences');
    Route::get('/activities/quarantined/raw/{mailId}', [MailController::class, 'raw'])->name('quarantine.raw');
    Route::get('/activities/quarantined/{type?}', [MailController::class, 'quarantine'])->name('quarantine');
    Route::delete('/activities/quarantined', [MailController::class, 'deleteQuarantine'])->name('quarantine.delete');
    Route::post('/activities/quarantined/{mailId}/release', [MailController::class, 'release'])->name('quarantine.release');
    Route::post('/users/{email}/forwarding', [AdminController::class, 'updateUserForwarding'])->where('email', '.*')->name('users.forwarding');
});

Route::middleware('iredmail.auth:admin')->group(function () {
    Route::get('/dashboard', [AdminController::class, 'dashboard'])->name('dashboard');
    Route::get('/domains', [AdminController::class, 'domains'])->name('domains');
    Route::post('/domains', [AdminController::class, 'createDomain'])->name('domains.create');
    Route::patch('/domains/{domain}', [AdminController::class, 'updateDomain'])->where('domain', '.*')->name('domains.update');
    Route::post('/domains/{domain}/alias-domains', [AdminController::class, 'createAliasDomain'])->where('domain', '.*')->name('domains.alias-domains.create');
    Route::delete('/alias-domains/{aliasDomain}', [AdminController::class, 'deleteAliasDomain'])->where('aliasDomain', '.*')->name('domains.alias-domains.delete');
    Route::post('/domains/{domain}/catch-all', [AdminController::class, 'createCatchAll'])->where('domain', '.*')->name('domains.catch-all.create');
    Route::delete('/domains/{domain}/catch-all/{destination}', [AdminController::class, 'deleteCatchAll'])->where('domain', '[^/]+')->where('destination', '.*')->name('domains.catch-all.delete');
    Route::post('/domains/{domain}/dkim/generate', [AdminController::class, 'generateDomainDkim'])->where('domain', '.*')->middleware('iredmail.auth:global')->name('domains.dkim.generate');
    Route::post('/domains/{domain}/dkim/check', [AdminController::class, 'checkDomainDkim'])->where('domain', '.*')->name('domains.dkim.check');
    Route::post('/domains/{domain}/dns/check', [AdminController::class, 'checkDomainDns'])->where('domain', '.*')->name('domains.dns.check');
    Route::delete('/domains/{domain}', [AdminController::class, 'deleteDomain'])->where('domain', '.*')->name('domains.delete');
    Route::get('/users', [AdminController::class, 'users'])->name('users');
    Route::post('/users', [AdminController::class, 'createUser'])->name('users.create');
    Route::patch('/users/{email}', [AdminController::class, 'updateUser'])->where('email', '.*')->name('users.update');
    Route::post('/users/{email}/services', [AdminController::class, 'updateServices'])->where('email', '.*')->name('users.services');
    Route::delete('/users/{email}', [AdminController::class, 'deleteUser'])->where('email', '.*')->name('users.delete');
    Route::get('/aliases', [AdminController::class, 'aliases'])->name('aliases');
    Route::post('/aliases', [AdminController::class, 'createAlias'])->name('aliases.create');
    Route::patch('/aliases/{address}', [AdminController::class, 'updateAlias'])->where('address', '.*')->name('aliases.update');
    Route::delete('/aliases/{address}', [AdminController::class, 'deleteAlias'])->where('address', '.*')->name('aliases.delete');
    Route::get('/mls', [AdminController::class, 'lists'])->name('lists');
    Route::post('/mls', [AdminController::class, 'createList'])->name('lists.create');
    Route::patch('/mls/{address}', [AdminController::class, 'updateList'])->where('address', '.*')->name('lists.update');
    Route::delete('/mls/{address}', [AdminController::class, 'deleteList'])->where('address', '.*')->name('lists.delete');
    Route::get('/admins', [AdminController::class, 'admins'])->name('admins');
    Route::post('/admins', [AdminController::class, 'assignAdmin'])->name('admins.assign');
    Route::delete('/admins/{email}/{domain}', [AdminController::class, 'deleteAdminAssignment'])->where('email', '[^/]+')->where('domain', '.*')->name('admins.delete');
    Route::get('/search', [AdminController::class, 'search'])->name('search');
    Route::get('/export/managed-accounts', [AdminController::class, 'exportAccounts'])->name('export.accounts');
    Route::get('/export/admin-statistics', [AdminController::class, 'exportAdminStats'])->name('export.admins');

    Route::get('/activities/{direction}', [MailController::class, 'logs'])->name('mail.logs');

    Route::get('/system/throttle', [PolicyController::class, 'throttle'])->name('throttle');
    Route::post('/system/throttle', [PolicyController::class, 'saveThrottle'])->name('throttle.save');
    Route::get('/system/wblist', [PolicyController::class, 'wblist'])->name('wblist');
    Route::post('/system/wblist', [PolicyController::class, 'addWblist'])->name('wblist.add');
    Route::get('/system/settings', [SystemSettingsController::class, 'edit'])->middleware('iredmail.auth:global')->name('system.settings');
    Route::post('/system/settings', [SystemSettingsController::class, 'update'])->middleware('iredmail.auth:global')->name('system.settings.update');
    Route::post('/system/settings/unauthenticated-senders', [SystemSettingsController::class, 'updateUnauthenticatedSenders'])->middleware('iredmail.auth:global')->name('system.settings.unauthenticated.update');
    Route::post('/system/settings/discard-recipients', [SystemSettingsController::class, 'updateDiscardRecipients'])->middleware('iredmail.auth:global')->name('system.settings.discard.update');
    Route::post('/system/settings/sogo-logo', [SystemSettingsController::class, 'updateSogoLogo'])->middleware('iredmail.auth:global')->name('system.settings.sogo.update');
    Route::get('/activities/fail2ban/banned', [PolicyController::class, 'fail2ban'])->name('fail2ban');
    Route::post('/activities/fail2ban/unban/{ip}', [PolicyController::class, 'unban'])->name('fail2ban.unban');
});

Route::prefix('api')->middleware('iredmail.auth')->group(function () {
    Route::get('/setup', [ApiController::class, 'setup'])->middleware('iredmail.auth:admin');
    Route::get('/dashboard', [ApiController::class, 'dashboard']);
    Route::get('/domains', [ApiController::class, 'domains'])->middleware('iredmail.auth:admin');
    Route::post('/domains', [ApiController::class, 'createDomain'])->middleware('iredmail.auth:admin');
    Route::patch('/domains/{domain}', [ApiController::class, 'updateDomain'])->where('domain', '.*')->middleware('iredmail.auth:admin');
    Route::delete('/domains/{domain}', [ApiController::class, 'deleteDomain'])->where('domain', '.*')->middleware('iredmail.auth:admin');
    Route::get('/users', [ApiController::class, 'users']);
    Route::post('/users', [ApiController::class, 'createUser'])->middleware('iredmail.auth:admin');
    Route::patch('/users/{email}', [ApiController::class, 'updateUser'])->where('email', '.*')->middleware('iredmail.auth:admin');
    Route::delete('/users/{email}', [ApiController::class, 'deleteUser'])->where('email', '.*')->middleware('iredmail.auth:admin');
    Route::get('/aliases', [ApiController::class, 'aliases'])->middleware('iredmail.auth:admin');
    Route::post('/aliases', [ApiController::class, 'createAlias'])->middleware('iredmail.auth:admin');
    Route::patch('/aliases/{address}', [ApiController::class, 'updateAlias'])->where('address', '.*')->middleware('iredmail.auth:admin');
    Route::delete('/aliases/{address}', [ApiController::class, 'deleteAlias'])->where('address', '.*')->middleware('iredmail.auth:admin');
    Route::get('/mls', [ApiController::class, 'lists']);
    Route::post('/mls', [ApiController::class, 'createList'])->middleware('iredmail.auth:admin');
    Route::patch('/mls/{address}', [ApiController::class, 'updateList'])->where('address', '.*')->middleware('iredmail.auth:admin');
    Route::delete('/mls/{address}', [ApiController::class, 'deleteList'])->where('address', '.*')->middleware('iredmail.auth:admin');
    Route::post('/admins', [ApiController::class, 'assignAdmin'])->middleware('iredmail.auth:global');
    Route::delete('/admins/{email}/{domain}', [ApiController::class, 'deleteAdminAssignment'])->where('email', '[^/]+')->where('domain', '.*')->middleware('iredmail.auth:global');
    Route::get('/activities/quarantined/{type?}', [ApiController::class, 'quarantine']);
    Route::get('/activities/{direction}', [ApiController::class, 'mail']);
    Route::get('/system/throttle', [ApiController::class, 'throttle'])->middleware('iredmail.auth:admin');
});
