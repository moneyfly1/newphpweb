<?php
/**
 * 管理员路由 — 需要管理员权限 (RequireAdmin)
 */

declare(strict_types=1);

use app\controller\admin\CouponController as AdminCouponController;
use app\controller\admin\DashboardController as AdminDashboardController;
use app\controller\admin\OrderController as AdminOrderController;
use app\controller\admin\PackageController as AdminPackageController;
use app\controller\admin\SettingsController as AdminSettingsController;
use app\controller\admin\SubscriptionController as AdminSubscriptionController;
use app\controller\admin\TicketController as AdminTicketController;
use app\controller\admin\UserController as AdminUserController;
use app\controller\api\AdminActionController;
use app\controller\api\AdminApiController;
use app\middleware\RequireAdmin;
use think\facade\Route;

// 管理员 API - 采集源管理
Route::group('/admin/api/sources', function (): void {
    Route::get('', [AdminApiController::class, 'listSources']);
    Route::post('', [AdminApiController::class, 'createSource']);
    Route::post('/:id', [AdminApiController::class, 'updateSource']);
    Route::delete('/:id', [AdminApiController::class, 'deleteSource']);
    Route::post('/:id/collect', [AdminApiController::class, 'collectOne']);
    Route::post('/collect-all', [AdminApiController::class, 'collectAll']);
    Route::get('/:id/logs', [AdminApiController::class, 'getSourceLogs']);
    Route::get('/stats', [AdminApiController::class, 'getSourceStats']);
})->middleware(RequireAdmin::class);

// 管理员 API - 节点管理
Route::group('/admin/api/nodes', function (): void {
    Route::get('/all', [AdminApiController::class, 'listAllNodes']);
    Route::get('/subscription/:id', [AdminApiController::class, 'getSubscriptionNodes']);
    Route::delete('/:id', [AdminApiController::class, 'deleteNode']);
    Route::post('/batch-delete', [AdminApiController::class, 'batchDeleteNodes']);
    Route::post('/batch-import', [AdminApiController::class, 'batchImportNodes']);
    Route::post('/:id/status', [AdminApiController::class, 'updateNodeStatus']);
    Route::get('/stats/:id', [AdminApiController::class, 'getNodeStats']);
})->middleware(RequireAdmin::class);

// 管理员 API - 仪表板统计
Route::group('/admin/api/stats', function (): void {
    Route::get('/dashboard', [AdminApiController::class, 'getDashboardStats']);
})->middleware(RequireAdmin::class);

// 管理员页面 & API
Route::group('/admin', function (): void {
    Route::get('/', [AdminDashboardController::class, 'index']);
    Route::get('/dashboard', [AdminDashboardController::class, 'index']);
    Route::get('/analysis', [AdminDashboardController::class, 'analysis']);
    Route::get('/statistics', [AdminDashboardController::class, 'statistics']);
    Route::get('/users', [AdminUserController::class, 'index']);
    Route::get('/packages', [AdminPackageController::class, 'index']);
    Route::get('/orders', [AdminOrderController::class, 'index']);
    Route::get('/subscriptions', [AdminSubscriptionController::class, 'index']);
    Route::get('/coupons', [AdminCouponController::class, 'index']);
    Route::get('/tickets', [AdminTicketController::class, 'index']);
    Route::get('/email-queue', [AdminSettingsController::class, 'emailQueue']);
    Route::get('/export', [AdminSettingsController::class, 'dataExport']);
    Route::get('/logins', [AdminSettingsController::class, 'loginSecurity']);
    Route::get('/batch-export', [AdminSettingsController::class, 'batchExport']);
    Route::get('/payment-gateway-config', [AdminSettingsController::class, 'paymentGatewayConfig']);
    Route::get('/payment-config', [AdminSettingsController::class, 'paymentGatewayConfig']);
    Route::get('/nodes/manage', [AdminSettingsController::class, 'nodeManagement']);
    Route::get('/sources/manage', [AdminSettingsController::class, 'sourceManagement']);
    Route::get('/settings', [AdminSettingsController::class, 'index']);
    Route::get('/settings/advanced', [AdminSettingsController::class, 'advanced']);
    Route::get('/logs/audit', [AdminSettingsController::class, 'auditLogs']);
    Route::post('/settings', [AdminSettingsController::class, 'save']);

    Route::group('/api', function (): void {
        Route::post('/packages/save', [AdminActionController::class, 'savePackage']);
        Route::post('/coupons/save', [AdminActionController::class, 'saveCoupon']);
        Route::post('/orders/batch', [AdminActionController::class, 'batchOrders']);
        Route::post('/tickets/batch', [AdminActionController::class, 'batchTickets']);
        Route::post('/packages/batch', [AdminActionController::class, 'batchPackages']);
        Route::post('/coupons/batch', [AdminActionController::class, 'batchCoupons']);
        Route::post('/users/:id/reset-password', [AdminActionController::class, 'resetUserPassword']);
        Route::post('/orders/:no/action', [AdminActionController::class, 'updateOrderStatus']);
        Route::post('/tickets/:no/reply', [AdminActionController::class, 'replyTicket']);
        Route::post('/settings/site', [AdminActionController::class, 'saveSiteSettings']);
        Route::post('/settings/advanced', [AdminActionController::class, 'saveAdvancedSettings']);
        Route::post('/settings/email', [AdminActionController::class, 'saveEmailSettings']);
        Route::post('/settings/email/test', [AdminActionController::class, 'testEmail']);
        Route::post('/settings/notification', [AdminActionController::class, 'saveNotificationSettings']);
        Route::post('/settings/theme', [AdminActionController::class, 'saveThemeSettings']);
        Route::post('/email-queue/:id/retry', [AdminActionController::class, 'retryEmail']);
        Route::post('/email-queue/:id/delete', [AdminActionController::class, 'deleteEmail']);
        Route::post('/email-queue/cleanup', [AdminActionController::class, 'cleanupEmails']);
        Route::post('/payment-methods/:id/toggle', [AdminActionController::class, 'togglePaymentMethod']);
        Route::post('/payment-methods/:id/update', [AdminActionController::class, 'updatePaymentMethod']);
        Route::post('/payment/test-alipay', [AdminActionController::class, 'testAlipay']);
        Route::post('/user/:id/note', [AdminActionController::class, 'updateUserNote']);
        Route::post('/user/:id/status', [AdminActionController::class, 'updateUserStatus']);
        Route::post('/users/batch', [AdminActionController::class, 'batchUsers']);
        Route::post('/subscriptions/:id/device-limit', [AdminActionController::class, 'updateSubscriptionDeviceLimit']);
        Route::post('/subscriptions/:id/expire-at', [AdminActionController::class, 'updateSubscriptionExpireAt']);
        Route::post('/subscriptions/:id/extend', [AdminActionController::class, 'extendSubscription']);
        Route::post('/subscriptions/:id/reset', [AdminActionController::class, 'resetSubscription']);
        Route::post('/subscriptions/:id/clear-devices', [AdminActionController::class, 'clearSubscriptionDevices']);
        Route::post('/subscriptions/batch', [AdminActionController::class, 'batchSubscriptions']);
        Route::get('/subscriptions/export', [AdminActionController::class, 'exportData']);

        // 支付网关管理 API
        Route::get('/payment-gateway/list', 'admin.api.PaymentGatewayController@list');
        Route::get('/payment-gateway/enabled-list', 'admin.api.PaymentGatewayController@enabledList');
        Route::get('/payment-gateway/config', 'admin.api.PaymentGatewayController@getConfig');
        Route::post('/payment-gateway/config', 'admin.api.PaymentGatewayController@saveConfig');
        Route::post('/payment-gateway/test', 'admin.api.PaymentGatewayController@test');
        Route::post('/payment-gateway/set-enabled', 'admin.api.PaymentGatewayController@setEnabled');
        Route::get('/payment-gateway/config-template', 'admin.api.PaymentGatewayController@configTemplate');
    });
})->middleware(RequireAdmin::class);
