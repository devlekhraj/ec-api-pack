<?php

declare(strict_types=1);

namespace EcApi\Domains\Page\Controllers;

use EcApi\Models\Page;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;

final class PageController extends Controller
{
    public function index(Request $request)
    {
        $perPage = max(1, min((int) $request->integer('per_page', 10), 100));
        $search = trim((string) $request->input('search', ''));

        $query = Page::query()
            ->where('is_deleted', 0)
            ->orderByDesc('updated_at');

        if ($search !== '') {
            $query->where(function ($builder) use ($search): void {
                $builder->where('code', 'like', "%{$search}%")
                    ->orWhere('content', 'like', "%{$search}%");
            });
        }

        /** @var LengthAwarePaginator<Page> $pages */
        $pages = $query->paginate($perPage);

        return response()->json([
            'data' => $pages->getCollection()->map(fn (Page $page) => $this->transformPage($page))->values(),
            'meta' => [
                'current_page' => $pages->currentPage(),
                'per_page' => $pages->perPage(),
                'total' => $pages->total(),
                'last_page' => $pages->lastPage(),
                'from' => $pages->firstItem(),
                'to' => $pages->lastItem(),
            ],
        ]);
    }

    public function show(Request $request, $code)
    {
        $page = Page::query()
            ->where('id', $code)
            ->orWhere('code', $code)
            ->first();

        if ($page) {
            return response()->json(['data' => $this->transformPage($page)], 200);
        }

        return response()->json(['message' => 'Page not found'], 404);
    }

    public function storeOrUpdate(Request $request)
    {
        $request->validate([
            'code' => 'required|string',
            'content' => 'nullable',
        ]);

        $payload = $this->buildContentPayload($request);
        $page = Page::updateOrCreate(
            ['code' => $request->string('code')],
            [
                'content' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'code' => $request->string('code'),
                'is_published' => $request->boolean('is_published', $request->boolean('status', false)),
            ]
        );

        return response()->json(['data' => $this->transformPage($page), 'message' => 'Page saved successfully.'], 200);
    }

    public function destroy(Request $request, $id)
    {
        try {
            $post = Page::findOrFail($id);
            $post->is_deleted = 1;
            $post->deleted_at = now();
            $post->save();

            return response()->json(['message' => 'Page deleted successfully.', 'data' => $post], 200);
        } catch (\Throwable $th) {
            return response()->json([
                'error' => $th->getMessage(),
                'message' => 'Failed to Delete',
            ], 422);
        }
    }

    private function buildContentPayload(Request $request): array
    {
        $content = $request->input('content');

        if (is_string($content)) {
            $decoded = json_decode($content, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                return $decoded;
            }
        }

        if (is_array($content)) {
            return $content;
        }

        return array_filter([
            'title' => $request->input('title'),
            'slug' => $request->input('slug'),
            'excerpt' => $request->input('excerpt'),
            'content' => $request->input('body', $request->input('html', $request->input('raw_content'))),
            'meta_title' => $request->input('meta_title'),
            'meta_keywords' => $request->input('meta_keywords'),
            'meta_description' => $request->input('meta_description'),
        ], static fn ($value) => $value !== null && $value !== '');
    }

    private function transformPage(Page $page): array
    {
        $decodedContent = $this->decodeContent($page->content);
        $title = (string) ($decodedContent['title'] ?? $decodedContent['name'] ?? $page->code);
        $slug = (string) ($decodedContent['slug'] ?? $page->code);

        return array_merge($decodedContent, [
            'id' => $page->id,
            'code' => $page->code,
            'title' => $title,
            'slug' => $slug,
            'status' => (bool) $page->is_published,
            'is_published' => (bool) $page->is_published,
            'updated_at' => $page->updated_at?->toISOString(),
            'created_at' => $page->created_at?->toISOString(),
        ]);
    }

    private function decodeContent(mixed $content): array
    {
        if (is_array($content)) {
            return $content;
        }

        if (! is_string($content) || $content === '') {
            return [];
        }

        $decoded = json_decode($content, true);
        if (json_last_error() !== JSON_ERROR_NONE || ! is_array($decoded)) {
            return ['content' => $content];
        }

        return $decoded;
    }
}
