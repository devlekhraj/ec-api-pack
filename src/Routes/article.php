<?php

declare(strict_types=1);

use EcApi\Domains\Article\Controllers\ArticleController;
use EcApi\Domains\Article\Controllers\ArticleListController;
use EcApi\Domains\Article\Controllers\ArticleRevisionController;
use EcApi\Domains\Article\Controllers\ArticleSeoController;
use EcApi\Domains\Article\Controllers\EditorialQueueController;
use EcApi\Domains\Article\Controllers\LiveUpdateController;
use EcApi\Domains\LandingPage\Controllers\LandingPageController;
use Illuminate\Support\Facades\Route;

Route::prefix('api/admin')->middleware('auth:sanctum')->group(function () {
    /*
    |--------------------------------------------------------------------------
    | Author Workflow
    |--------------------------------------------------------------------------
    */

    Route::post('articles/drafts', [ArticleController::class, 'createArticle'])->name('articles.drafts.store');
    Route::get('article-versions/{id}/author-draft', [ArticleController::class, 'getAuthorDraftDetail'])->name('article-versions.author-draft.show');
    Route::put('article-versions/{id}/author-draft', [ArticleController::class, 'updateAuthorDraft'])->name('article-versions.author-draft.update');
    Route::delete('article-versions/{id}/author-draft', [ArticleController::class, 'deleteAuthorDraft'])->name('article-versions.author-draft.destroy');
    Route::post('article-versions/bulk-delete-author-drafts', [ArticleController::class, 'bulkDeleteAuthorDrafts'])->name('article-versions.author-drafts.bulk-destroy');
    Route::post('article-versions/{id}/submit-review', [ArticleController::class, 'submitEditorialReview'])->name('article-versions.review.submit');
    Route::get('article-versions/{id}/author-submitted', [ArticleController::class, 'getSubmittedDetail'])->name('article-versions.author-submitted.show');

    /*
    |--------------------------------------------------------------------------
    | Revision Workflow
    |--------------------------------------------------------------------------
    */

    Route::post('article-versions/{id}/revise', [ArticleRevisionController::class, 'reviseArticle'])->name('article-versions.revise');
    Route::get('article-versions/{id}/revision-draft', [ArticleRevisionController::class, 'getRevisionDraftDetail'])->name('article-versions.revision-draft.show');
    Route::put('article-versions/{id}/revision-draft', [ArticleRevisionController::class, 'updateRevisionDraft'])->name('article-versions.revision-draft.update');
    Route::post('article-versions/{id}/revision-publish', [ArticleRevisionController::class, 'publishRevision'])->name('article-versions.revision.publish');

    /*
    |--------------------------------------------------------------------------
    | Editorial Workflow
    |--------------------------------------------------------------------------
    */

    Route::get('editorial-queues', [EditorialQueueController::class, 'index'])->name('editorial-queues.index');
    Route::put('article-versions/{id}/assign', [ArticleController::class, 'assignArticleVersion'])->name('article-versions.assign');
    Route::get('article-versions/{id}/editor-review', [ArticleController::class, 'getEditorDetail'])->name('article-versions.editor-review.show');
    Route::put('article-versions/{id}/editor-review', [ArticleController::class, 'updateEditorReview'])->name('article-versions.editor-review.update');
    Route::get('article-versions/{id}/edit', [ArticleController::class, 'getEditorDetail'])->name('article-versions.edit');
    Route::put('article-versions/{id}/edit', [ArticleController::class, 'updateEditorReview'])->name('article-versions.update');
    Route::put('article-versions/{id}/publish', [ArticleController::class, 'publishArticle'])->name('article-versions.publish');

    /*
    |--------------------------------------------------------------------------
    | SEO Workflow
    |--------------------------------------------------------------------------
    */

    Route::post('article-versions/{id}/seo', [ArticleController::class, 'updateSeo'])->name('article-versions.seo.update');
    Route::post('article-versions/{id}/seo/finalize', [ArticleSeoController::class, 'finalizeSeo'])->name('article-versions.seo.finalize');
    Route::post('article-versions/{id}/seo/generate/open-ai', [ArticleSeoController::class, 'generateSeoOpenAI'])->name('article-versions.seo.generate.open-ai');
    Route::post('article-versions/{id}/seo/generate/gemini', [ArticleSeoController::class, 'generateSeoGemini'])->name('article-versions.seo.generate.gemini');
    Route::post('article-versions/{id}/seo/generate/deepseek', [ArticleSeoController::class, 'generateSeoDeepSeek'])->name('article-versions.seo.generate.deepseek');
    Route::post('article-versions/{id}/seo/lock-generation', [ArticleSeoController::class, 'lockSeoGeneration'])->name('article-versions.seo.lock-generation');

    /*
    |--------------------------------------------------------------------------
    | Article Lists
    |--------------------------------------------------------------------------
    */

    Route::get('articles/my-articles', [ArticleListController::class, 'myArticlesList'])->name('articles.my-articles.index');
    Route::get('articles/drafts', [ArticleListController::class, 'draftList'])->name('articles.drafts.index');
    Route::get('articles/under-review', [ArticleListController::class, 'underReviewList'])->name('articles.under-review.index');
    Route::get('articles/assigned', [ArticleListController::class, 'assignedList'])->name('articles.assigned.index');
    Route::get('articles/published', [ArticleListController::class, 'publishedList'])->name('articles.published.index');
    Route::get('articles/published/{id}', [ArticleListController::class, 'publishedArticleDetail'])->name('articles.published.show');
    Route::get('articles/published/{id}/versions', [ArticleListController::class, 'publishedArticleVersions'])->name('articles.published.versions');

    /*
    |--------------------------------------------------------------------------
    | Live Updates
    |--------------------------------------------------------------------------
    */

    Route::get('article-versions/{versionId}/live-updates', [LiveUpdateController::class, 'index'])->name('live-updates.index');
    Route::post('article-versions/{versionId}/live-updates', [LiveUpdateController::class, 'store'])->name('live-updates.store');
    Route::get('live-updates/{id}', [LiveUpdateController::class, 'show'])->name('live-updates.show');
    Route::put('live-updates/{id}', [LiveUpdateController::class, 'update'])->name('live-updates.update');
    Route::delete('live-updates/{id}', [LiveUpdateController::class, 'destroy'])->name('live-updates.destroy');

    /*
    |--------------------------------------------------------------------------
    | Landing Page Manager
    |--------------------------------------------------------------------------
    */

    Route::get('landing-page/settings', [LandingPageController::class, 'getSettings'])->name('landing-page.settings.show');
    Route::post('landing-page/settings', [LandingPageController::class, 'updateSettings'])->name('landing-page.settings.update');
    Route::get('landing-page/article-search', [LandingPageController::class, 'searchArticles'])->name('landing-page.articles.search');
});
