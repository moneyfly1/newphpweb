<?php
/**
 * 用户路由 — 需要登录 (RequireLogin)
 */

declare(strict_types=1);

use app\controller\api\UserApiController;
use app\controller\api\UserActionController;
use app\controller\user\DashboardController as UserDashboardController;
use app\controller\user\HelpController as UserHelpController;
use app\controller\user\KnowledgeController as UserKnowledgeController;
use app\controller\user\NodesController as UserNodesController;
use app\controller\user\RechargeController as UserRechargeController;
use app\controller\user\SettingsController as UserSettingsController;
use app\controller\user\ShopController as UserShopController;
use app\controller\user\SubscriptionController as UserSubscriptionController;
use app\controller\user\TicketController as UserTicketController;
use app\controller\user\InviteController as UserInviteController;
use app\controller\user\OrderController as UserOrderController;
use app\controller\user\PurchaseController as UserPurchaseController;
use app\middleware\RequireLogin;
use think\facade\Route;

// 用户 API - 订阅管理
Route::group('/api/user', function (): void {
    Route::get('/subscription', [UserApiController::class, 'getSubscription']);
    Route::post('/subscription/reset', [UserApiController::class, 'resetSubscription']);
    Route::post('/subscription/send-email', [UserApiController::class, 'sendSubscriptionEmail']);
    Route::get('/subscription/stats', [UserApiController::class, 'getSubscriptionStats']);
    Route::get('/subscription/formats', [UserApiController::class, 'getSubscriptionFormats']);
})->middleware('auth');

// 用户页面 & API
Route::group('', function (): void {
    Route::get('/dashboard', [UserDashboardController::class, 'index']);
    Route::get('/shop', [UserShopController::class, 'index']);
    Route::get('/orders', [UserOrderController::class, 'index']);
    Route::get('/subscriptions', [UserSubscriptionController::class, 'index']);
    Route::get('/tickets', [UserTicketController::class, 'index']);
    Route::get('/settings', [UserSettingsController::class, 'index']);
    Route::get('/help', [UserHelpController::class, 'index']);
    Route::get('/knowledge', [UserKnowledgeController::class, 'index']);
    Route::get('/nodes', [UserNodesController::class, 'index']);
    Route::get('/purchase', [UserPurchaseController::class, 'index']);
    Route::get('/purchase/:id', [UserPurchaseController::class, 'details']);
    Route::get('/recharge', [UserRechargeController::class, 'index']);
    Route::get('/invite', [UserInviteController::class, 'index']);

    Route::group('/api', function (): void {
        Route::post('/checkin', [UserActionController::class, 'checkin']);
        Route::post('/coupons/verify', [UserActionController::class, 'verifyCoupon']);
        Route::post('/orders/create', [UserActionController::class, 'createOrder']);
        Route::get('/orders/:no/status', [UserActionController::class, 'paymentStatus']);
        Route::post('/orders/:no/cancel', [UserActionController::class, 'cancelOrder']);
        Route::post('/settings/toggle', [UserActionController::class, 'toggleSetting']);
        Route::post('/tickets/create', [UserActionController::class, 'createTicket']);
        Route::post('/settings/profile', [UserActionController::class, 'saveProfile']);
        Route::post('/settings/password', [UserActionController::class, 'updatePassword']);
        Route::post('/settings/notifications', [UserActionController::class, 'saveNotifications']);
        Route::post('/settings/privacy', [UserActionController::class, 'savePrivacy']);
        Route::post('/settings/preferences', [UserActionController::class, 'savePreferences']);
        Route::post('/settings/export', [UserActionController::class, 'exportData']);
        Route::get('/settings/login-history', [UserActionController::class, 'loginHistory']);
        Route::get('/settings/subscriptions', [UserActionController::class, 'userSubscriptions']);
        Route::post('/account/freeze', [UserActionController::class, 'freezeAccount']);
        Route::post('/account/delete', [UserActionController::class, 'deleteAccount']);
        Route::post('/subscriptions/reset', [UserActionController::class, 'resetSubscription']);
        Route::post('/subscriptions/balance', [UserActionController::class, 'convertSubscriptionBalance']);
        Route::post('/subscriptions/send-email', [UserActionController::class, 'sendSubscriptionEmail']);
        Route::post('/subscriptions/clear-device', [UserActionController::class, 'clearSubscriptionDevices']);
        Route::get('/payment-methods', [UserActionController::class, 'paymentMethods']);
        Route::post('/recharge', [UserActionController::class, 'recharge']);
        Route::get('/recharge/:id/status', [UserActionController::class, 'rechargeStatus']);
        Route::get('/recharge-history', [UserActionController::class, 'rechargeHistory']);
        Route::post('/purchase-package', [UserActionController::class, 'purchasePackage']);
        Route::get('/order/:orderNo/status', [UserActionController::class, 'checkOrderStatus']);

        // 支付网关相关 API
        Route::get('/payment-gateway/enabled-gateways', 'api.PaymentGatewayController@enabledGateways');
        Route::get('/payment-gateway/features/:code', 'api.PaymentGatewayController@getFeatures');
        Route::get('/payment-gateway/info/:code', 'api.PaymentGatewayController@getInfo');

        // 支付处理 API
        Route::post('/payment/initiate', 'api.PaymentController@initiate');
        Route::get('/payment/order/:tradeNo', 'api.PaymentController@queryOrder');
    });
})->middleware(RequireLogin::class);
