<?php
/**
 * 公开路由 — 无需登录
 */

declare(strict_types=1);

use app\controller\AuthController;
use app\controller\LandingController;
use app\controller\api\UserApiController;
use app\controller\api\PaymentController;
use think\facade\Route;

// 首页 & 认证
Route::get('/', [LandingController::class, 'index']);
Route::get('/login', [AuthController::class, 'showLogin']);
Route::post('/login', [AuthController::class, 'login']);
Route::post('/register', [AuthController::class, 'register']);
Route::post('/forgot-password', [AuthController::class, 'forgotPassword']);
Route::post('/verification-code', [AuthController::class, 'sendVerificationCode']);
Route::post('/logout', [AuthController::class, 'logout']);
Route::post('/captcha/refresh', [AuthController::class, 'refreshCaptcha']);

// 订阅下载（通过 token 验证）
Route::get('sub/:token/:format', [UserApiController::class, 'downloadSubscription']);

// 支付回调（服务器异步通知，不需要登录）
Route::get('pay/notify', [PaymentController::class, 'mazhifuNotify']);
Route::post('pay/notify', [PaymentController::class, 'mazhifuNotify']);
Route::get('pay/return', [PaymentController::class, 'mazhifuReturn']);
Route::post('api/payment/callback/:gateway', [PaymentController::class, 'callback']);
Route::get('api/payment/callback/:gateway', [PaymentController::class, 'callback']);
