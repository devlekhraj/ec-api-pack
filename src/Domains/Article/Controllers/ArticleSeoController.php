<?php

declare(strict_types=1);

namespace EcApi\Domains\Article\Controllers;

use EcApi\Models\AiRequest;
use EcApi\Models\ArticleVersion;
use EcApi\Models\ArticleVersionSeo;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

final class ArticleSeoController extends Controller
{
    public function lockSeoGeneration(Request $request, string $id): JsonResponse
    {
        return $this->updateSeoState($request, $id, [
            'locked' => true,
            'locked_at' => now(),
            'locked_by' => $request->user()?->id,
        ], 'SEO generation locked successfully.', 'Failed to lock SEO generation.');
    }

    public function finalizeSeo(Request $request, string $id): JsonResponse
    {
        return $this->updateSeoState($request, $id, [
            'finalized' => true,
            'finalized_at' => now(),
            'finalized_by' => $request->user()?->id,
        ], 'SEO finalized successfully.', 'Failed to finalize SEO.');
    }

    public function generateSeoOpenAI(Request $request, string $id): JsonResponse
    {
        $user = $request->user();

        if (! $user) {
            return response()->json([
                'message' => 'Unauthenticated.',
            ], 401);
        }

        try {
            $articleVersion = ArticleVersion::query()
                ->with(['article', 'authors', 'categories', 'createdBy', 'bannerImage.gallery'])
                ->where('id', $id)
                ->firstOrFail();

            $articleContext = $this->buildSeoContext($articleVersion);
            $openAiResponse = $this->requestSeoFromOpenAi($articleContext);
            $seoPayload = $this->normalizeSeoPayload($openAiResponse['seo'] ?? []);
            $slug = $this->generateSlug($seoPayload['slug'] ?? null, $articleVersion, $seoPayload['slug_options'] ?? []);

            $articleVersionSeo = DB::transaction(function () use ($articleVersion, $seoPayload, $slug, $user, $articleContext, $openAiResponse): ArticleVersionSeo {
                $articleVersion->forceFill([
                    'slug' => $slug,
                ])->save();

                $seo = ArticleVersionSeo::query()->firstOrNew([
                    'article_version_id' => $articleVersion->id,
                ]);

                $seo->fill([
                    'canonical_url' => $seoPayload['canonical_url'] ?? null,
                    'meta_title' => $seoPayload['meta_title'] ?? null,
                    'meta_descriptions' => $seoPayload['meta_description'] ?? null,
                    'og_title' => $seoPayload['og_title'] ?? null,
                    'og_description' => $seoPayload['og_description'] ?? null,
                    'og_image_url' => $seoPayload['og_image'] ?? null,
                    'twitter_title' => $seoPayload['twitter_title'] ?? null,
                    'twitter_description' => $seoPayload['twitter_description'] ?? null,
                    'twitter_image_url' => $seoPayload['twitter_image'] ?? null,
                    'focus_keywords' => $this->formatFocusKeywords($seoPayload['focus_keywords'] ?? []),
                    'seo_extra' => [
                        'summary' => $seoPayload['summary'] ?? null,
                        'findings' => $seoPayload['findings'] ?? [],
                        'suggestions' => $seoPayload['suggestions'] ?? [],
                        'slug_options' => $seoPayload['slug_options'] ?? [],
                        'checks' => $seoPayload['checks'] ?? [],
                        'article_context' => $articleContext,
                    ],
                    'ai_optimized' => true,
                    'ai_request_count' => (int) ($seo->ai_request_count ?? 0) + 1,
                ]);
                $seo->save();

                AiRequest::query()->create([
                    'requestable_type' => ArticleVersionSeo::class,
                    'requestable_id' => $seo->id,
                    'requested_by' => $user->id,
                    'provider' => 'openai',
                    'model' => (string) config('services.openai.model', 'gpt-4.1-mini'),
                    'feature' => 'seo',
                    'action' => 'optimize_article_seo',
                    'prompt_version' => 'v1',
                    'input_snapshot' => $articleContext,
                    'output' => $openAiResponse,
                    'input_tokens' => data_get($openAiResponse, 'usage.prompt_tokens'),
                    'output_tokens' => data_get($openAiResponse, 'usage.completion_tokens'),
                    'total_tokens' => data_get($openAiResponse, 'usage.total_tokens'),
                    'estimated_cost_usd' => null,
                    'accepted' => false,
                    'accepted_at' => null,
                    'accepted_by' => null,
                    'meta' => [
                        'article_version_id' => $articleVersion->id,
                    ],
                ]);

                return $seo->refresh();
            });

            return response()->json([
                'success' => true,
                'message' => 'SEO generated successfully.',
                'data' => [
                    'id' => $articleVersion->id,
                    'slug' => $articleVersion->slug,
                    'seo' => [
                        'canonical_url' => $articleVersionSeo->canonical_url,
                        'meta_title' => $articleVersionSeo->meta_title,
                        'meta_description' => $articleVersionSeo->meta_descriptions,
                        'og_title' => $articleVersionSeo->og_title,
                        'og_description' => $articleVersionSeo->og_description,
                        'og_image' => $articleVersionSeo->og_image_url,
                        'twitter_title' => $articleVersionSeo->twitter_title,
                        'twitter_description' => $articleVersionSeo->twitter_description,
                        'twitter_image' => $articleVersionSeo->twitter_image_url,
                        'focus_keywords' => $this->parseFocusKeywords($articleVersionSeo->focus_keywords),
                        'slug_options' => $seoPayload['slug_options'] ?? [],
                        'seo_extra' => $articleVersionSeo->seo_extra,
                        'ai_optimized' => $articleVersionSeo->ai_optimized,
                        'ai_request_count' => $articleVersionSeo->ai_request_count,
                    ],
                ],
            ]);
        } catch (Throwable $th) {
            Log::error('Failed to generate SEO: ', [
                'error' => $th->getMessage(),
                'file' => $th->getFile(),
                'line' => $th->getLine(),
                'exception' => $th,
            ]);

            return response()->json([
                'message' => 'Failed to generate SEO.',
                'error' => $th->getMessage(),
            ], 422);
        }
    }

    public function generateSeoGemini(Request $request, string $id): JsonResponse
    {
        $user = $request->user();

        if (! $user) {
            return response()->json([
                'message' => 'Unauthenticated.',
            ], 401);
        }

        try {
            $articleVersion = ArticleVersion::query()
                ->with(['article', 'authors', 'categories', 'createdBy', 'bannerImage.gallery'])
                ->where('id', $id)
                ->firstOrFail();

            $articleContext = $this->buildSeoContext($articleVersion);
            $geminiResponse = $this->requestSeoFromGemini($articleContext);
            $seoPayload = $this->normalizeSeoPayload($geminiResponse['seo'] ?? []);
            $slug = $this->generateSlug($seoPayload['slug'] ?? null, $articleVersion, $seoPayload['slug_options'] ?? []);

            $articleVersionSeo = DB::transaction(function () use ($articleVersion, $seoPayload, $slug, $user, $articleContext, $geminiResponse): ArticleVersionSeo {
                $articleVersion->forceFill([
                    'slug' => $slug,
                ])->save();

                $seo = ArticleVersionSeo::query()->firstOrNew([
                    'article_version_id' => $articleVersion->id,
                ]);

                $seo->fill([
                    'canonical_url' => $seoPayload['canonical_url'] ?? null,
                    'meta_title' => $seoPayload['meta_title'] ?? null,
                    'meta_descriptions' => $seoPayload['meta_description'] ?? null,
                    'og_title' => $seoPayload['og_title'] ?? null,
                    'og_description' => $seoPayload['og_description'] ?? null,
                    'og_image_url' => $seoPayload['og_image'] ?? null,
                    'twitter_title' => $seoPayload['twitter_title'] ?? null,
                    'twitter_description' => $seoPayload['twitter_description'] ?? null,
                    'twitter_image_url' => $seoPayload['twitter_image'] ?? null,
                    'focus_keywords' => $this->formatFocusKeywords($seoPayload['focus_keywords'] ?? []),
                    'seo_extra' => [
                        'summary' => $seoPayload['summary'] ?? null,
                        'findings' => $seoPayload['findings'] ?? [],
                        'suggestions' => $seoPayload['suggestions'] ?? [],
                        'slug_options' => $seoPayload['slug_options'] ?? [],
                        'checks' => $seoPayload['checks'] ?? [],
                        'article_context' => $articleContext,
                    ],
                    'ai_optimized' => true,
                    'ai_request_count' => (int) ($seo->ai_request_count ?? 0) + 1,
                ]);
                $seo->save();

                AiRequest::query()->create([
                    'requestable_type' => ArticleVersionSeo::class,
                    'requestable_id' => $seo->id,
                    'requested_by' => $user->id,
                    'provider' => 'gemini',
                    'model' => (string) config('services.gemini.model', 'gemini-flash-latest'),
                    'feature' => 'seo',
                    'action' => 'optimize_article_seo',
                    'prompt_version' => 'v1',
                    'input_snapshot' => $articleContext,
                    'output' => $geminiResponse,
                    'input_tokens' => data_get($geminiResponse, 'usage.prompt_token_count'),
                    'output_tokens' => data_get($geminiResponse, 'usage.candidates_token_count'),
                    'total_tokens' => data_get($geminiResponse, 'usage.total_token_count'),
                    'estimated_cost_usd' => null,
                    'accepted' => false,
                    'accepted_at' => null,
                    'accepted_by' => null,
                    'meta' => [
                        'article_version_id' => $articleVersion->id,
                    ],
                ]);

                return $seo->refresh();
            });

            return response()->json([
                'success' => true,
                'message' => 'SEO generated successfully.',
                'data' => [
                    'id' => $articleVersion->id,
                    'slug' => $articleVersion->slug,
                    'seo' => [
                        'canonical_url' => $articleVersionSeo->canonical_url,
                        'meta_title' => $articleVersionSeo->meta_title,
                        'meta_description' => $articleVersionSeo->meta_descriptions,
                        'og_title' => $articleVersionSeo->og_title,
                        'og_description' => $articleVersionSeo->og_description,
                        'og_image' => $articleVersionSeo->og_image_url,
                        'twitter_title' => $articleVersionSeo->twitter_title,
                        'twitter_description' => $articleVersionSeo->twitter_description,
                        'twitter_image' => $articleVersionSeo->twitter_image_url,
                        'focus_keywords' => $this->parseFocusKeywords($articleVersionSeo->focus_keywords),
                        'slug_options' => $seoPayload['slug_options'] ?? [],
                        'seo_extra' => $articleVersionSeo->seo_extra,
                        'ai_optimized' => $articleVersionSeo->ai_optimized,
                        'ai_request_count' => $articleVersionSeo->ai_request_count,
                    ],
                ],
            ]);
        } catch (Throwable $th) {
            Log::error('Failed to generate SEO using Gemini: ', [
                'error' => $th->getMessage(),
                'file' => $th->getFile(),
                'line' => $th->getLine(),
                'exception' => $th,
            ]);

            return response()->json([
                'message' => 'Failed to generate SEO.',
                'error' => $th->getMessage(),
            ], 422);
        }
    }

    public function generateSeoDeepSeek(Request $request, string $id): JsonResponse
    {
        $user = $request->user();

        if (! $user) {
            return response()->json([
                'message' => 'Unauthenticated.',
            ], 401);
        }

        try {
            $articleVersion = ArticleVersion::query()
                ->with(['article', 'authors', 'categories', 'createdBy', 'bannerImage.gallery'])
                ->where('id', $id)
                ->firstOrFail();

            $articleContext = $this->buildSeoContext($articleVersion);
            $deepseekResponse = $this->requestSeoFromDeepSeek($articleContext);
            $seoPayload = $this->normalizeSeoPayload($deepseekResponse['seo'] ?? []);
            $slug = $this->generateSlug($seoPayload['slug'] ?? null, $articleVersion, $seoPayload['slug_options'] ?? []);

            $articleVersionSeo = DB::transaction(function () use ($articleVersion, $seoPayload, $slug, $user, $articleContext, $deepseekResponse): ArticleVersionSeo {
                $articleVersion->forceFill([
                    'slug' => $slug,
                ])->save();

                $seo = ArticleVersionSeo::query()->firstOrNew([
                    'article_version_id' => $articleVersion->id,
                ]);

                $seo->fill([
                    'canonical_url' => $seoPayload['canonical_url'] ?? null,
                    'meta_title' => $seoPayload['meta_title'] ?? null,
                    'meta_descriptions' => $seoPayload['meta_description'] ?? null,
                    'og_title' => $seoPayload['og_title'] ?? null,
                    'og_description' => $seoPayload['og_description'] ?? null,
                    'og_image_url' => $seoPayload['og_image'] ?? null,
                    'twitter_title' => $seoPayload['twitter_title'] ?? null,
                    'twitter_description' => $seoPayload['twitter_description'] ?? null,
                    'twitter_image_url' => $seoPayload['twitter_image'] ?? null,
                    'focus_keywords' => $this->formatFocusKeywords($seoPayload['focus_keywords'] ?? []),
                    'seo_extra' => [
                        'summary' => $seoPayload['summary'] ?? null,
                        'findings' => $seoPayload['findings'] ?? [],
                        'suggestions' => $seoPayload['suggestions'] ?? [],
                        'slug_options' => $seoPayload['slug_options'] ?? [],
                        'checks' => $seoPayload['checks'] ?? [],
                        'article_context' => $articleContext,
                    ],
                    'ai_optimized' => true,
                    'ai_request_count' => (int) ($seo->ai_request_count ?? 0) + 1,
                ]);
                $seo->save();

                AiRequest::query()->create([
                    'requestable_type' => ArticleVersionSeo::class,
                    'requestable_id' => $seo->id,
                    'requested_by' => $user->id,
                    'provider' => 'deepseek',
                    'model' => (string) config('services.deepseek.model', 'deepseek-chat'),
                    'feature' => 'seo',
                    'action' => 'optimize_article_seo',
                    'prompt_version' => 'v1',
                    'input_snapshot' => $articleContext,
                    'output' => $deepseekResponse,
                    'input_tokens' => data_get($deepseekResponse, 'usage.prompt_tokens'),
                    'output_tokens' => data_get($deepseekResponse, 'usage.completion_tokens'),
                    'total_tokens' => data_get($deepseekResponse, 'usage.total_tokens'),
                    'estimated_cost_usd' => null,
                    'accepted' => false,
                    'accepted_at' => null,
                    'accepted_by' => null,
                    'meta' => [
                        'article_version_id' => $articleVersion->id,
                    ],
                ]);

                return $seo->refresh();
            });

            return response()->json([
                'success' => true,
                'message' => 'SEO generated successfully.',
                'data' => [
                    'id' => $articleVersion->id,
                    'slug' => $articleVersion->slug,
                    'seo' => [
                        'canonical_url' => $articleVersionSeo->canonical_url,
                        'meta_title' => $articleVersionSeo->meta_title,
                        'meta_description' => $articleVersionSeo->meta_descriptions,
                        'og_title' => $articleVersionSeo->og_title,
                        'og_description' => $articleVersionSeo->og_description,
                        'og_image' => $articleVersionSeo->og_image_url,
                        'twitter_title' => $articleVersionSeo->twitter_title,
                        'twitter_description' => $articleVersionSeo->twitter_description,
                        'twitter_image' => $articleVersionSeo->twitter_image_url,
                        'focus_keywords' => $this->parseFocusKeywords($articleVersionSeo->focus_keywords),
                        'slug_options' => $seoPayload['slug_options'] ?? [],
                        'seo_extra' => $articleVersionSeo->seo_extra,
                        'ai_optimized' => $articleVersionSeo->ai_optimized,
                        'ai_request_count' => $articleVersionSeo->ai_request_count,
                    ],
                ],
            ]);
        } catch (Throwable $th) {
            Log::error('Failed to generate SEO using DeepSeek: ', [
                'error' => $th->getMessage(),
                'file' => $th->getFile(),
                'line' => $th->getLine(),
                'exception' => $th,
            ]);

            return response()->json([
                'message' => 'Failed to generate SEO.',
                'error' => $th->getMessage(),
            ], 422);
        }
    }

    private function buildSeoContext(ArticleVersion $articleVersion): array
    {
        return [
            'title' => $articleVersion->title,
            'sub_title' => $articleVersion->sub_title,
            'slug' => $articleVersion->slug,
        ];
    }

    private function updateSeoState(Request $request, string $id, array $attributes, string $successMessage, string $errorMessage): JsonResponse
    {
        $user = $request->user();

        if (! $user) {
            return response()->json([
                'message' => 'Unauthenticated.',
            ], 401);
        }

        try {
            $articleVersionSeo = DB::transaction(function () use ($id, $attributes): ArticleVersionSeo {
                $articleVersion = ArticleVersion::query()
                    ->where('id', $id)
                    ->firstOrFail();

                $seo = ArticleVersionSeo::query()->firstOrNew([
                    'article_version_id' => $articleVersion->id,
                ]);

                $seo->fill($attributes);
                $seo->save();

                return $seo->refresh();
            });

            return response()->json([
                'success' => true,
                'message' => $successMessage,
                'data' => [
                    'id' => $articleVersionSeo->article_version_id,
                    'finalized' => $articleVersionSeo->finalized,
                    'locked' => $articleVersionSeo->locked,
                    'finalized_at' => $articleVersionSeo->finalized_at?->toISOString(),
                    'locked_at' => $articleVersionSeo->locked_at?->toISOString(),
                ],
            ]);
        } catch (Throwable $th) {
            Log::error($errorMessage, [
                'error' => $th->getMessage(),
                'file' => $th->getFile(),
                'line' => $th->getLine(),
                'exception' => $th,
            ]);

            return response()->json([
                'message' => $errorMessage,
                'error' => $th->getMessage(),
            ], 422);
        }
    }

    private function requestSeoFromOpenAi(array $articleContext): array
    {
        $key = (string) config('services.openai.key', '');

        if ($key === '') {
            throw new \RuntimeException('OpenAI API key is not configured.');
        }

        $prompt = $this->buildSeoPrompt($articleContext);

        $response = Http::withToken($key)
            ->acceptJson()
            ->connectTimeout((int) config('services.openai.connect_timeout', 5))
            ->timeout((int) config('services.openai.timeout', 20))
            ->post('https://api.openai.com/v1/chat/completions', [
                'model' => (string) config('services.openai.model', 'gpt-4.1-mini'),
                'temperature' => 0.2,
                'response_format' => [
                    'type' => 'json_object',
                ],
                'messages' => [
                    [
                        'role' => 'system',
                        'content' => 'You are a senior SEO assistant that returns strict JSON.',
                    ],
                    [
                        'role' => 'user',
                        'content' => $prompt,
                    ],
                ],
            ]);

        if (! $response->successful()) {
            $errorMessage = (string) data_get($response->json(), 'error.message', '');
            $errorCode = (string) data_get($response->json(), 'error.code', '');

            if ($errorCode === 'insufficient_quota') {
                throw new \RuntimeException('OpenAI quota is exhausted. Please check billing or switch to a valid API key.');
            }

            throw new \RuntimeException($errorMessage !== '' ? $errorMessage : 'OpenAI request failed.');
        }

        $content = (string) data_get($response->json(), 'choices.0.message.content', '');
        $cleaned = preg_replace('/^```(?:json)?\s*/i', '', trim($content));
        $cleaned = preg_replace('/\s*```$/', '', trim((string) $cleaned));
        $decoded = json_decode((string) $cleaned, true);

        if (! is_array($decoded)) {
            throw new \RuntimeException('Invalid OpenAI JSON response.');
        }

        return $decoded;
    }

    private function requestSeoFromGemini(array $articleContext): array
    {
        $key = (string) config('services.gemini.key', '');

        if ($key === '') {
            throw new \RuntimeException('Gemini API key is not configured.');
        }

        $prompt = $this->buildSeoPrompt($articleContext);

        $response = Http::withHeaders([
            'Content-Type' => 'application/json',
            'X-goog-api-key' => $key,
        ])
            ->connectTimeout((int) config('services.gemini.connect_timeout', 5))
            ->timeout((int) config('services.gemini.timeout', 20))
            ->post('https://generativelanguage.googleapis.com/v1beta/models/' . config('services.gemini.model', 'gemini-flash-latest') . ':generateContent', [
                'contents' => [
                    [
                        'parts' => [
                            [
                                'text' => $prompt,
                            ],
                        ],
                    ],
                ],
                'generationConfig' => [
                    'response_mime_type' => 'application/json',
                ],
            ]);

        if (! $response->successful()) {
            $errorMessage = (string) data_get($response->json(), 'error.message', '');
            throw new \RuntimeException($errorMessage !== '' ? $errorMessage : 'Gemini request failed.');
        }

        $content = (string) data_get($response->json(), 'candidates.0.content.parts.0.text', '');
        $cleaned = preg_replace('/^```(?:json)?\s*/i', '', trim($content));
        $cleaned = preg_replace('/\s*```$/', '', trim((string) $cleaned));
        $decoded = json_decode((string) $cleaned, true);

        if (! is_array($decoded)) {
            throw new \RuntimeException('Invalid Gemini JSON response.');
        }

        return $decoded;
    }

    private function requestSeoFromDeepSeek(array $articleContext): array
    {
        $key = (string) config('services.deepseek.key', '');

        if ($key === '') {
            throw new \RuntimeException('DeepSeek API key is not configured.');
        }

        $prompt = $this->buildSeoPrompt($articleContext);
        $baseUrl = rtrim((string) config('services.deepseek.base_url', 'https://api.deepseek.com'), '/');

        $response = Http::withToken($key)
            ->acceptJson()
            ->connectTimeout((int) config('services.deepseek.connect_timeout', 5))
            ->timeout((int) config('services.deepseek.timeout', 20))
            ->post($baseUrl . '/chat/completions', [
                'model' => (string) config('services.deepseek.model', 'deepseek-chat'),
                'temperature' => 0.2,
                'response_format' => [
                    'type' => 'json_object',
                ],
                'messages' => [
                    [
                        'role' => 'system',
                        'content' => 'You are a senior SEO assistant that returns strict JSON.',
                    ],
                    [
                        'role' => 'user',
                        'content' => $prompt,
                    ],
                ],
            ]);

        if (! $response->successful()) {
            $errorMessage = (string) data_get($response->json(), 'error.message', '');
            throw new \RuntimeException($errorMessage !== '' ? $errorMessage : 'DeepSeek request failed.');
        }

        $content = (string) data_get($response->json(), 'choices.0.message.content', '');
        $cleaned = preg_replace('/^```(?:json)?\s*/i', '', trim($content));
        $cleaned = preg_replace('/\s*```$/', '', trim((string) $cleaned));
        $decoded = json_decode((string) $cleaned, true);

        if (! is_array($decoded)) {
            throw new \RuntimeException('Invalid DeepSeek JSON response.');
        }

        return $decoded;
    }

    private function buildSeoPrompt(array $articleContext): string
    {
        $articleContextJson = $this->encodePromptData($articleContext);

        return <<<PROMPT
You are an expert SEO editor for a professional news, blog, and article CMS.

Generate SEO metadata for the given article.

Return valid JSON only.
Do not include markdown.
Do not include explanation outside JSON.
Do not include comments.
Do not wrap JSON in triple backticks.

Required JSON structure:
{
  "seo": {
    "slug": "string",
    "slug_options": [
      {
        "slug": "string",
        "type": "seo_search_intent|title_matching|short_clean|editorial|news_style",
        "reason": "string",
        "best_for": "string"
      }
    ],
    "canonical_url": "string or null",
    "meta_title": "string",
    "meta_description": "string",
    "og_title": "string",
    "og_description": "string",
    "og_image": "string or null",
    "twitter_title": "string or null",
    "twitter_description": "string or null",
    "twitter_image": "string or null",
    "focus_keywords": ["string"]
  },
  "summary": "string",
  "findings": [],
  "suggestions": [],
  "checks": {
    "slug_is_url_safe": true,
    "meta_title_length": 0,
    "meta_description_length": 0,
    "has_focus_keyword": true
  }
}

Article context:
{$articleContextJson}

General SEO rules:
- Base all output only on the provided article context.
- Do not invent facts, names, dates, places, prices, statistics, quotes, or claims.
- Write for humans first, then search engines.
- Avoid clickbait.
- Avoid keyword stuffing.
- Avoid vague wording.
- Avoid duplicate-sounding titles and descriptions.
- Prefer clarity over creativity.

Slug rules:
- Generate 3 to 4 slug options.
- The main "seo.slug" must be the best overall recommended slug.
- The main "seo.slug" must also appear inside "seo.slug_options".
- Each slug must be lowercase, short, meaningful, and URL-safe.
- Each slug must use only lowercase letters, numbers, and hyphens.
- Do not use underscores, special characters, dates, random words, or stop words unless required for meaning.
- Each slug should ideally be 3 to 7 words.
- Do not make the article sound like a guide unless the article actually contains guide-style content.
- Do not change the article angle.
- If the article is an evergreen guide, tutorial, travel guide, trekking guide, how-to, review, itinerary, cost guide, or tips article, prefer a search-intent slug.
- If the article is news, report, announcement, opinion, editorial, interview, or storytelling content, prefer a title-matching or news-style slug.
- Each slug option must include:
  - slug
  - type
  - reason
  - best_for

Slug option type rules:
- Use "seo_search_intent" for slugs targeting common search queries.
- Use "title_matching" for slugs that closely follow the article title.
- Use "short_clean" for short and simple URLs.
- Use "editorial" for storytelling, opinion, magazine-style, or feature-style articles.
- Use "news_style" for timely news/reporting articles.

Meta title rules:
- Meta title should be 45 to 60 characters when possible.
- Include the main topic naturally.
- Do not force keywords.
- Do not exceed 65 characters unless necessary for clarity.

Meta description rules:
- Meta description should be 140 to 160 characters when possible.
- Clearly summarize the article.
- Make it useful and natural for search results.
- Do not use fake urgency or clickbait.
- Do not exceed 170 characters unless necessary.

Open Graph rules:
- OG title may be slightly more engaging than meta title.
- OG description may be slightly more social-friendly than meta description.
- OG title and description must still be factually accurate.
- If a banner image, cover image, featured image, or social image URL is provided in article context, use the best available image URL as "og_image".
- If no image URL is available, return null for "og_image".

Twitter rules:
- Twitter title may reuse OG title if no better shorter version is needed.
- Twitter description may reuse OG description if no better shorter version is needed.
- If an image URL is available, use the same image as "twitter_image".
- If no image URL is available, return null for "twitter_image".

Canonical URL rules:
- canonical_url should be null unless a canonical URL is provided in the article context.
- If a canonical URL is provided, return it exactly.
- Do not create or guess a canonical URL.

Focus keyword rules:
- Return 3 to 8 focus keywords.
- Keywords should be realistic search phrases.
- Prefer phrases over single generic words.
- Do not include unrelated keywords.
- Do not include keywords that are not supported by the article context.

Summary rules:
- Summary should be 1 to 3 sentences.
- It should summarize the article, not the SEO metadata.

Findings and suggestions rules:
- "findings" should contain important observations about SEO quality, missing context, or content limitations.
- "suggestions" should contain practical improvement suggestions for the editor.
- If there are no findings or suggestions, return empty arrays.

Checks rules:
- "slug_is_url_safe" must be true only if the recommended slug uses only lowercase letters, numbers, and hyphens.
- "meta_title_length" must be the character count of meta_title.
- "meta_description_length" must be the character count of meta_description.
- "has_focus_keyword" must be true if at least one focus keyword is included naturally in the meta title or meta description.

Output rules:
- Return JSON only.
- Ensure the JSON is valid and parseable by json_decode.
- Do not include trailing commas.
PROMPT;
    }

    private function normalizeSeoPayload(array $seo): array
    {
        return [
            'slug' => isset($seo['slug']) ? Str::slug((string) $seo['slug']) : null,
            'slug_options' => $this->normalizeSlugOptions($seo['slug_options'] ?? []),
            'canonical_url' => isset($seo['canonical_url']) ? trim((string) $seo['canonical_url']) : null,
            'meta_title' => isset($seo['meta_title']) ? trim((string) $seo['meta_title']) : null,
            'meta_description' => isset($seo['meta_description']) ? trim((string) $seo['meta_description']) : null,
            'og_title' => isset($seo['og_title']) ? trim((string) $seo['og_title']) : null,
            'og_description' => isset($seo['og_description']) ? trim((string) $seo['og_description']) : null,
            'og_image' => isset($seo['og_image']) ? trim((string) $seo['og_image']) : null,
            'twitter_title' => isset($seo['twitter_title']) ? trim((string) $seo['twitter_title']) : null,
            'twitter_description' => isset($seo['twitter_description']) ? trim((string) $seo['twitter_description']) : null,
            'twitter_image' => isset($seo['twitter_image']) ? trim((string) $seo['twitter_image']) : null,
            'focus_keywords' => $seo['focus_keywords'] ?? [],
            'summary' => $seo['summary'] ?? null,
            'findings' => $seo['findings'] ?? [],
            'suggestions' => $seo['suggestions'] ?? [],
            'checks' => $seo['checks'] ?? [],
        ];
    }

    private function normalizeSlugOptions(mixed $slugOptions): array
    {
        if (! is_array($slugOptions)) {
            return [];
        }

        return array_values(array_filter(array_map(function (mixed $option): array|null {
            if (! is_array($option)) {
                return null;
            }

            $slug = Str::slug((string) ($option['slug'] ?? ''));

            if ($slug === '') {
                return null;
            }

            return [
                'slug' => $slug,
                'type' => isset($option['type']) ? trim((string) $option['type']) : null,
                'reason' => isset($option['reason']) ? trim((string) $option['reason']) : null,
                'best_for' => isset($option['best_for']) ? trim((string) $option['best_for']) : '',
            ];
        }, $slugOptions)));
    }

    private function generateSlug(?string $slug, ArticleVersion $articleVersion, array $slugOptions = []): string
    {
        $firstSlugOption = (string) data_get($slugOptions, '0.slug', '');
        $baseSlug = Str::slug((string) ($firstSlugOption ?: $slug ?: $articleVersion->title));

        if ($baseSlug === '') {
            $baseSlug = 'article-' . $articleVersion->id;
        }

        return $baseSlug;
    }

    private function formatFocusKeywords(mixed $keywords): ?string
    {
        if (! is_array($keywords)) {
            return null;
        }

        $items = array_values(array_filter(array_map(static fn ($item): string => trim((string) $item), $keywords)));

        return $items === [] ? null : implode(', ', $items);
    }

    private function parseFocusKeywords(?string $keywords): array
    {
        if (! $keywords) {
            return [];
        }

        return array_values(array_filter(array_map('trim', explode(',', $keywords))));
    }

    private function encodePromptData(array $payload): string
    {
        return json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}';
    }
}
