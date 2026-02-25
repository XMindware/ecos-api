<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Prompt;
use App\Models\PromptEvent;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Throwable;

class PromptController extends Controller
{
    public function random(Request $request)
    {
        $fallbackUsed = false;
        $prompt = $this->randomPromptFromQuery($this->filteredPromptQuery($request), $request);

        if ($prompt === null && $request->filled('category')) {
            $prompt = $this->randomPromptFromQuery(
                $this->filteredPromptQuery($request, ignoreCategory: true),
                $request
            );
            $fallbackUsed = $prompt !== null;
        }

        $this->logPromptEvents($request, 'random', $prompt, [
            'fallback_used' => $fallbackUsed,
        ]);

        return $prompt;
    }

    public function byCategory(Request $request)
    {
        $category = trim((string) $request->get('category', ''));

        if ($category === '') {
            return response()->json([
                'message' => 'The category query parameter is required.',
            ], 422);
        }

        $prompts = $this->filteredPromptQuery($request)
            ->with(['category', 'keywords'])
            ->latest('id')
            ->get();

        $this->logPromptEvents($request, 'by-category', $prompts);

        return $prompts;
    }

    public function categories()
    {
        return Category::where('is_active', true)->get();
    }

    public function search(Request $request)
    {
        $term = trim((string) $request->get('q', ''));
        $limit = min(100, max(1, (int) $request->get('limit', 20)));

        $query = $this->filteredPromptQuery($request)
            ->with(['category', 'keywords']);

        if ($term !== '') {
            $query->where('text', 'like', '%'.$term.'%');
        }

        if ($request->filled('exclude_id')) {
            $query->where('id', '!=', (int) $request->input('exclude_id'));
        }

        $prompts = $query
            ->latest('id')
            ->limit($limit)
            ->get();

        $this->logPromptEvents($request, 'search', $prompts, [
            'limit' => $limit,
            'term' => $term,
        ]);

        return $prompts;
    }

    protected function filteredPromptQuery(Request $request, bool $ignoreCategory = false): Builder
    {
        $language = trim((string) $request->get('language', 'en'));
        $category = trim((string) $request->get('category', ''));
        $keyword = trim((string) $request->get('keyword', ''));
        $minor = $request->query('minor');

        return Prompt::query()
            ->where('is_active', true)
            ->where('language', $language)
            ->when(
                $minor !== null && Schema::hasColumn('prompts', 'is_minor'),
                fn (Builder $query) => $query->where('is_minor', $request->boolean('minor'))
            )
            ->when(! $ignoreCategory && $category !== '', function (Builder $query) use ($category) {
                $query->whereHas('category', function (Builder $q) use ($category) {
                    $q->where('slug', $category)
                        ->where('is_active', true);
                });
            })
            ->when($keyword !== '', function (Builder $query) use ($keyword) {
                $query->whereHas('keywords', function (Builder $q) use ($keyword) {
                    $q->where('slug', $keyword)
                        ->where('is_active', true);
                });
            });
    }

    protected function randomPromptFromQuery(Builder $query, Request $request): ?Prompt
    {
        $query->with(['category', 'keywords']);

        if ($request->filled('exclude_id')) {
            $query->where('id', '!=', (int) $request->input('exclude_id'));
        }

        /** @var ?Prompt $prompt */
        $prompt = $query->inRandomOrder()->first();

        return $prompt;
    }

    protected function logPromptEvents(Request $request, string $endpoint, Prompt|EloquentCollection|null $result, array $extraContext = []): void
    {
        if (! Schema::hasTable('prompt_events')) {
            return;
        }

        try {
            $requestUuid = (string) Str::uuid();
            $filters = $this->promptEventFilters($request);

            if ($result instanceof Prompt) {
                $this->createPromptEvent($request, $requestUuid, $endpoint, 'served', $result->id, $filters, array_merge($extraContext, [
                    'result_count' => 1,
                ]));

                return;
            }

            if ($result instanceof EloquentCollection && $result->isNotEmpty()) {
                $count = $result->count();

                foreach ($result as $prompt) {
                    if (! $prompt instanceof Prompt) {
                        continue;
                    }

                    $this->createPromptEvent($request, $requestUuid, $endpoint, 'served', $prompt->id, $filters, array_merge($extraContext, [
                        'result_count' => $count,
                    ]));
                }

                return;
            }

            $this->createPromptEvent($request, $requestUuid, $endpoint, 'no_results', null, $filters, array_merge($extraContext, [
                'result_count' => 0,
            ]));
        } catch (Throwable) {
            // Do not fail the API response if event logging fails.
        }
    }

    protected function createPromptEvent(
        Request $request,
        string $requestUuid,
        string $endpoint,
        string $event,
        ?int $promptId,
        array $filters,
        array $context
    ): void {
        PromptEvent::create([
            'request_uuid' => $requestUuid,
            'endpoint' => $endpoint,
            'event' => $event,
            'prompt_id' => $promptId,
            'filters' => $filters,
            'context' => $context,
            'ip_address' => $request->ip(),
            'user_agent' => Str::limit((string) $request->userAgent(), 1024, ''),
        ]);
    }

    protected function promptEventFilters(Request $request): array
    {
        $query = $request->query();
        ksort($query);

        return $query;
    }
}
