<?php

use App\Http\Controllers\Auth\LogoutController;
use App\Http\Controllers\Webhook\WhatsAppFlowController;
use App\Http\Controllers\Webhook\WhatsAppWebhookController;
use Illuminate\Support\Facades\Route;

Route::view('/', 'welcome');

Route::get('/dashboard', function () {
    $user = auth()->user();

    return $user->hasRole(['super_admin', 'admin_staff'])
        ? redirect()->route('admin.dashboard')
        : redirect()->route('vendor.dashboard');
})->middleware('auth')->name('dashboard');

// ---------------------------------------------------------------------------
// Guest auth
// ---------------------------------------------------------------------------
Route::middleware('guest')->group(function () {
    Route::livewire('/login', 'auth.login')->name('login');
    Route::livewire('/register', 'auth.vendor-register')->name('vendor.register');
});

Route::post('/logout', LogoutController::class)->middleware('auth')->name('logout');

// ---------------------------------------------------------------------------
// WhatsApp webhook — public, no session/CSRF. Signature-verified on POST.
// ---------------------------------------------------------------------------
Route::prefix('webhook')->name('webhook.')->group(function () {
    Route::get('/whatsapp', [WhatsAppWebhookController::class, 'verify'])->name('whatsapp.verify');
    Route::post('/whatsapp', [WhatsAppWebhookController::class, 'receive'])
        ->middleware(['whatsapp.signature', 'throttle:60,1'])
        ->name('whatsapp.receive');

    // Encrypted data-exchange endpoint for the multi-select product Flow.
    Route::post('/whatsapp-flow', [WhatsAppFlowController::class, 'handle'])
        ->middleware(['whatsapp.signature', 'throttle:60,1'])
        ->name('whatsapp.flow');
});

// ---------------------------------------------------------------------------
// Admin panel
// ---------------------------------------------------------------------------
Route::middleware(['auth', 'role:super_admin|admin_staff'])
    ->prefix('admin')
    ->name('admin.')
    ->group(function () {
        Route::livewire('/', 'admin.dashboard')->name('dashboard');
        Route::livewire('/packages', 'admin.package-manager')->name('packages');
        Route::livewire('/vendors', 'admin.vendor-list')->name('vendors');
        Route::livewire('/webhook-logs', 'admin.webhook-log-viewer')->name('webhook-logs');
        Route::livewire('/whatsapp-provisioning', 'admin.whatsapp-provisioning-log')->name('whatsapp-provisioning');
        Route::livewire('/ai-routing-logs', 'admin.ai-routing-log-viewer')->name('ai-routing-logs');
        Route::livewire('/payment-settings', 'admin.payment-settings')->name('payment-settings');
    });

// ---------------------------------------------------------------------------
// Vendor panel
// ---------------------------------------------------------------------------
Route::middleware(['auth', 'role:vendor_owner|vendor_staff'])
    ->prefix('vendor')
    ->name('vendor.')
    ->group(function () {
        Route::livewire('/', 'vendor.dashboard')->name('dashboard');
        Route::livewire('/stores', 'vendor.store-manager')->name('stores');
        Route::livewire('/stores/create', 'vendor.store-form')
            ->middleware('vendor.approved')
            ->name('stores.create');
        Route::livewire('/stores/{store}/edit', 'vendor.store-form')->name('stores.edit');
        Route::livewire('/categories', 'vendor.category-manager')->name('categories');
        Route::livewire('/products', 'vendor.product-manager')->name('products');
        Route::livewire('/whatsapp', 'vendor.whatsapp-connect')->name('whatsapp');
        Route::livewire('/agents', 'vendor.agent-manager')->name('agents');
        Route::livewire('/knowledge-base', 'vendor.knowledge-base')->name('knowledge-base');
        Route::livewire('/conversations', 'vendor.conversation-list')->name('conversations');
        Route::livewire('/conversations/{conversation}', 'vendor.conversation-thread')->name('conversations.show');
        Route::livewire('/orders', 'vendor.order-list')->name('orders');
        Route::livewire('/orders/{order}', 'vendor.order-detail')->name('orders.show');
        Route::livewire('/analytics', 'vendor.store-analytics')->name('analytics');
        Route::livewire('/settings', 'vendor.settings')->name('settings');
    });
