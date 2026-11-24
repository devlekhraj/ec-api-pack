<?php

declare(strict_types=1);

use EcApi\Domains\Auth\Controllers\AdminAuthController;
use EcApi\Domains\Auth\Controllers\PasswordResetController;
use EcApi\Domains\Category\Controllers\CategoryApiController;
use EcApi\Domains\Gallery\Controllers\GalleryController;
use EcApi\Domains\Gallery\Controllers\GalleryUsageController;
use EcApi\Domains\Gallery\Controllers\ImageController;
use EcApi\Domains\Lookup\Controllers\LookupController;
use EcApi\Domains\Menu\Controllers\AdminMenuController;
use EcApi\Domains\Page\Controllers\PageController;
use EcApi\Domains\Post\Controllers\PostController;
use EcApi\Domains\Role\Controllers\PermissionApiController;
use EcApi\Domains\Role\Controllers\RoleApiController;
use EcApi\Domains\Role\Controllers\RolePermissionApiController;
use EcApi\Domains\Shared\Controllers\OverviewController;
use EcApi\Domains\Short\Controllers\ShortApiController;
use EcApi\Domains\User\Controllers\UserApiController;
use Illuminate\Support\Facades\Route;


Route::prefix('api/admin')->group(function () {

    // -------------------------------------------------------------------------
    // Auth (public)
    // -------------------------------------------------------------------------

    Route::post('register', [AdminAuthController::class, 'register']);
    Route::post('login', [AdminAuthController::class, 'login']);

    // OTP-based password reset (all public — no auth required)
    Route::post('forgot-password', [PasswordResetController::class, 'sendOtp']);
    Route::post('verify-otp', [PasswordResetController::class, 'verifyOtp']);
    Route::post('reset-password', [PasswordResetController::class, 'resetPassword']);

    Route::middleware('auth:sanctum')->group(function () {
        Route::get('profile', [AdminAuthController::class, 'getProfile']);
        Route::put('profile', [AdminAuthController::class, 'updateProfile']);
        Route::post('logout', [AdminAuthController::class, 'logout']);
    });

    // -------------------------------------------------------------------------
    // Images — public file serving (no auth required)
    // -------------------------------------------------------------------------

    Route::get('image/{filename}', [ImageController::class, 'getImage'])->name('image.view');

    // -------------------------------------------------------------------------
    // Users
    // -------------------------------------------------------------------------

    Route::middleware('auth:sanctum')->prefix('users')->group(function () {
        Route::get('/', [UserApiController::class, 'index'])->name('users.index');
        Route::get('/authors', [UserApiController::class, 'authors'])->name('users.authors');
        Route::get('/assignable', [UserApiController::class, 'assignableUsers'])->name('users.assignable');
        Route::get('/lookups', [UserApiController::class, 'lookups'])->name('users.lookups');
        Route::get('/{id}', [UserApiController::class, 'show'])->name('users.show');
        Route::get('/{id}/posts', [UserApiController::class, 'posts'])->name('users.posts');
        Route::post('/', [UserApiController::class, 'store'])->name('users.store');
        Route::get('/{id}/edit', [UserApiController::class, 'edit'])->name('users.edit');
        Route::put('/{id}', [UserApiController::class, 'update'])->name('users.update');
        Route::delete('/{id}', [UserApiController::class, 'destroy'])->name('users.destroy');
        Route::post('/{id}/activate', [UserApiController::class, 'activate'])->name('users.activate');
        Route::post('/{id}/deactivate', [UserApiController::class, 'deactivate'])->name('users.deactivate');
        Route::put('/{id}/password', [UserApiController::class, 'updatePassword'])->name('users.updatePassword');
        Route::post('upload', [UserApiController::class, 'uploadImage'])->name('users.profile.image');
    });

    // -------------------------------------------------------------------------
    // Roles & Permissions
    // -------------------------------------------------------------------------

    Route::prefix('roles')->group(function () {
        Route::get('/', [RoleApiController::class, 'index']);
        Route::get('/{id}', [RoleApiController::class, 'show']);
        Route::post('/', [RoleApiController::class, 'store']);
        Route::put('/{id}', [RoleApiController::class, 'update']);
        Route::delete('/{id}', [RoleApiController::class, 'destroy']);
        Route::post('/{id}/permissions', [RolePermissionApiController::class, 'assignPermissions']);
    });

    Route::get('/permissions', [PermissionApiController::class, 'index']);
    Route::get('/roles-permissions', [RolePermissionApiController::class, 'index']);

    // -------------------------------------------------------------------------
    // Overview & Menus
    // -------------------------------------------------------------------------

    Route::middleware('auth:sanctum')->group(function () {
        Route::get('overview', [OverviewController::class, 'index'])->name('admin.overview.data');
        Route::get('recent-articles', [OverviewController::class, 'recentArticles'])->name('admin.recent-articles');
        Route::get('admin-menus', [AdminMenuController::class, 'index']);
        Route::get('lookups', [LookupController::class, 'index']);
        Route::get('lookups/{code}', [LookupController::class, 'getLookup']);
    });

    // -------------------------------------------------------------------------
    // Posts, Articles & Categories
    // -------------------------------------------------------------------------

    Route::middleware('auth:sanctum')->group(function () {
        Route::get('posts', [PostController::class, 'index'])->name('article.list');
        Route::get('posts/{id}', [PostController::class, 'show'])->name('article.detail');
        Route::get('published-articles/{id}/details', [PostController::class, 'fetchPublishedArticle'])->name('article.published.detail');
        Route::post('posts', [PostController::class, 'store']);
        Route::put('posts/{id}/update', [PostController::class, 'update']);
        Route::post('posts/{id}/update-seo', [PostController::class, 'updateSeo']);
        Route::put('posts/{id}/publish', [PostController::class, 'publishPost']);
        Route::put('posts/{id}/status', [PostController::class, 'updatePostStatus']);

        Route::post('posts/{id}/banner', [PostController::class, 'banner']);
        
        Route::delete('posts/{id}', [PostController::class, 'destroy']);
        Route::put('posts/{id}/toggle-publish', [PostController::class, 'togglePublish']);
        Route::post('posts-thumbnail-upload', [PostController::class, 'uploadImage']);

        Route::get('categories/lookups', [CategoryApiController::class, 'lookups']);
        Route::get('categories', [CategoryApiController::class, 'index']);
        Route::get('categories/{id}', [CategoryApiController::class, 'show']);
        Route::get('post-categories', [PostController::class, 'categories']);
        Route::get('post-authors', [PostController::class, 'authors']);
        Route::get('category-options', [CategoryApiController::class, 'options']);
        Route::post('categories', [CategoryApiController::class, 'store']);
        Route::put('categories/{id}/status', [CategoryApiController::class, 'updateCategoryStatus']);
        Route::delete('categories/{id}', [CategoryApiController::class, 'destroy']);
    });

    // -------------------------------------------------------------------------
    // Pages
    // -------------------------------------------------------------------------

    Route::middleware('auth:sanctum')->group(function () {
        Route::get('pages', [PageController::class, 'index']);
        Route::get('pages/{code}', [PageController::class, 'show']);
        Route::post('pages', [PageController::class, 'storeOrUpdate']);
        Route::delete('pages/{id}', [PageController::class, 'destroy']);
    });

    // -------------------------------------------------------------------------
    // Shorts
    // -------------------------------------------------------------------------

    Route::middleware('auth:sanctum')->prefix('shorts')->group(function () {
        Route::get('/', [ShortApiController::class, 'index'])->name('admin.shorts.index');
        Route::post('/', [ShortApiController::class, 'store'])->name('admin.shorts.store');
        Route::get('/{id}', [ShortApiController::class, 'show'])->name('admin.shorts.show');
        Route::put('/{id}', [ShortApiController::class, 'update'])->name('admin.shorts.update');
        Route::delete('/{id}', [ShortApiController::class, 'destroy'])->name('admin.shorts.destroy');
        Route::patch('/{id}/toggle-status', [ShortApiController::class, 'toggleStatus'])->name('admin.shorts.toggle-status');
    });

    // -------------------------------------------------------------------------
    // Gallery — physical image files
    // -------------------------------------------------------------------------

    Route::middleware('auth:sanctum')->group(function () {
        Route::get('galleries', [GalleryController::class, 'index']);
        Route::get('galleries/{id}', [GalleryController::class, 'show']);

        // Upload with usage context (source=upload|existing, creates GalleryUsage).
        Route::post('gallery-upload', [GalleryController::class, 'upload']);

        // Simple upload — stores the file, returns gallery record, no usage created.
        Route::post('upload-image', [GalleryController::class, 'store']);

        // Image listing endpoints.
        Route::get('image-list', [ImageController::class, 'imageList'])->name('admin.image.list');
        Route::get('image-list-with-usage', [ImageController::class, 'imageListWithUsage'])->name('admin.image.list.usage');
    });

    // -------------------------------------------------------------------------
    // Gallery Usages — contextual metadata linking galleries to entities
    // -------------------------------------------------------------------------

    Route::middleware('auth:sanctum')->group(function () {
        Route::post('images/{gallery_id}/usage-save', [GalleryUsageController::class, 'save'])->name('admin.image.usage.save');
        Route::post('image-usages/{id}/make-primary', [GalleryUsageController::class, 'makePrimary'])->name('admin.image.usage.make.primary');
        Route::post('image-usages/{id}/remove', [GalleryUsageController::class, 'remove'])->name('admin.image.usage.remove');
        Route::post('image-usages/{id}/update', [GalleryUsageController::class, 'update'])->name('admin.image.usage.update');
    });
});
