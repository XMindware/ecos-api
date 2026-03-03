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
        $requestUuid = (string) Str::uuid();

        $this->logPromptRequest($request, 'random', $requestUuid, [
            'fallback_possible' => $request->filled('category'),
        ]);

        $prompt = $this->randomPromptFromQuery($this->filteredPromptQuery($request), $request);

        if ($prompt === null && $request->filled('category')) {
            $prompt = $this->randomPromptFromQuery(
                $this->filteredPromptQuery($request, ignoreCategory: true),
                $request
            );
            $fallbackUsed = $prompt !== null;
        }

        $this->logPromptEvents($request, 'random', $requestUuid, $prompt, [
            'fallback_used' => $fallbackUsed,
        ]);

        return response()
            ->json($prompt)
            ->header('X-Prompt-Request-Id', $requestUuid);
    }

    public function byCategory(Request $request)
    {
        $category = trim((string) $request->get('category', ''));

        if ($category === '') {
            return response()->json([
                'message' => 'The category query parameter is required.',
            ], 422);
        }

        $requestUuid = (string) Str::uuid();
        $this->logPromptRequest($request, 'by-category', $requestUuid);

        $prompts = $this->filteredPromptQuery($request)
            ->with(['category', 'keywords'])
            ->latest('id')
            ->get();

        $this->logPromptEvents($request, 'by-category', $requestUuid, $prompts);

        return response()
            ->json($prompts)
            ->header('X-Prompt-Request-Id', $requestUuid);
    }

    public function categories()
    {
        return Category::where('is_active', true)->get();
    }

    public function search(Request $request)
    {
        $term = trim((string) $request->get('q', ''));
        $limit = min(100, max(1, (int) $request->get('limit', 20)));
        $requestUuid = (string) Str::uuid();

        $this->logPromptRequest($request, 'search', $requestUuid, [
            'limit' => $limit,
            'term' => $term,
        ]);

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

        $this->logPromptEvents($request, 'search', $requestUuid, $prompts, [
            'limit' => $limit,
            'term' => $term,
        ]);

        return response()
            ->json($prompts)
            ->header('X-Prompt-Request-Id', $requestUuid);
    }

    public function registerOutcome(Request $request, Prompt $prompt)
    {
        $validated = $request->validate([
            'event' => ['required', 'in:used,dismissed,discarded'],
            'request_uuid' => ['nullable', 'uuid'],
            'video_id' => ['nullable', 'integer'],
            'context' => ['nullable', 'array'],
        ]);

        $eventName = $validated['event'] === 'discarded'
            ? 'dismissed'
            : $validated['event'];

        $context = array_merge($validated['context'] ?? [], array_filter([
            'video_id' => $validated['video_id'] ?? null,
        ], fn ($value) => $value !== null));

        $event = $this->createPromptEvent(
            $request,
            $validated['request_uuid'] ?? (string) Str::uuid(),
            'feedback',
            $eventName,
            $prompt->id,
            [],
            $context
        );

        return response()->json([
            'message' => 'Prompt outcome registered.',
            'data' => $event,
        ], 201);
    }

    public function analytics(Request $request)
    {
        $validated = $request->validate([
            'category' => ['nullable', 'string'],
            'language' => ['nullable', 'string', 'max:10'],
            'minor' => ['nullable', 'boolean'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $limit = (int) ($validated['limit'] ?? 20);
        $promptBaseQuery = Prompt::query()
            ->with(['category', 'keywords'])
            ->when(! empty($validated['category']), function (Builder $query) use ($validated) {
                $query->whereHas('category', function (Builder $categoryQuery) use ($validated) {
                    $categoryQuery->where('slug', $validated['category']);
                });
            })
            ->when(! empty($validated['language']), fn (Builder $query) => $query->where('language', $validated['language']))
            ->when(
                array_key_exists('minor', $validated) && Schema::hasColumn('prompts', 'is_minor'),
                fn (Builder $query) => $query->where('is_minor', (bool) $validated['minor'])
            );

        $promptEventBaseQuery = $this->applyDateRange(PromptEvent::query(), $validated)
            ->whereIn('prompt_id', (clone $promptBaseQuery)->select('prompts.id'));

        $requestEventBaseQuery = $this->applyAnalyticsRequestFilters(
            $this->applyDateRange(PromptEvent::query(), $validated),
            $validated
        );

        $promptAnalytics = (clone $promptBaseQuery)
            ->select('prompts.*')
            ->selectSub($this->countEventsSubquery('served', $promptEventBaseQuery), 'times_requested')
            ->selectSub($this->countEventsSubquery('used', $promptEventBaseQuery), 'times_used')
            ->selectSub($this->countEventsSubquery(['dismissed', 'discarded'], $promptEventBaseQuery), 'times_dismissed')
            ->orderByDesc('times_used')
            ->orderByDesc('times_requested')
            ->limit($limit)
            ->get()
            ->map(function (Prompt $prompt) {
                $requested = (int) $prompt->times_requested;
                $used = (int) $prompt->times_used;
                $dismissed = (int) $prompt->times_dismissed;

                return [
                    'id' => $prompt->id,
                    'text' => $prompt->text,
                    'language' => $prompt->language,
                    'tone' => $prompt->tone,
                    'difficulty' => $prompt->difficulty,
                    'category' => $prompt->category?->only(['id', 'name', 'slug']),
                    'keywords' => $prompt->keywords->pluck('slug')->values(),
                    'times_requested' => $requested,
                    'times_used' => $used,
                    'times_dismissed' => $dismissed,
                    'times_discarded' => $dismissed,
                    'usage_rate' => $requested > 0 ? round(($used / $requested) * 100, 2) : 0.0,
                    'dismiss_rate' => $requested > 0 ? round(($dismissed / $requested) * 100, 2) : 0.0,
                    'discard_rate' => $requested > 0 ? round(($dismissed / $requested) * 100, 2) : 0.0,
                ];
            })
            ->values();

        $categoryAnalytics = $promptAnalytics
            ->groupBy('category.slug')
            ->map(function ($prompts) {
                $first = $prompts->first();
                $requested = $prompts->sum('times_requested');
                $used = $prompts->sum('times_used');
                $dismissed = $prompts->sum('times_dismissed');

                return [
                    'id' => $first['category']['id'] ?? null,
                    'name' => $first['category']['name'] ?? null,
                    'slug' => $first['category']['slug'] ?? null,
                    'prompt_count' => $prompts->count(),
                    'times_requested' => $requested,
                    'times_used' => $used,
                    'times_dismissed' => $dismissed,
                    'times_discarded' => $dismissed,
                    'usage_rate' => $requested > 0 ? round(($used / $requested) * 100, 2) : 0.0,
                    'dismiss_rate' => $requested > 0 ? round(($dismissed / $requested) * 100, 2) : 0.0,
                    'discard_rate' => $requested > 0 ? round(($dismissed / $requested) * 100, 2) : 0.0,
                ];
            })
            ->sortByDesc('times_used')
            ->values();

        return response()->json([
            'summary' => [
                'request_count' => (clone $requestEventBaseQuery)->where('event', 'requested')->count(),
                'served_count' => (clone $promptEventBaseQuery)->where('event', 'served')->count(),
                'used_count' => (clone $promptEventBaseQuery)->where('event', 'used')->count(),
                'dismissed_count' => $this->countEvents($promptEventBaseQuery, ['dismissed', 'discarded']),
                'discarded_count' => $this->countEvents($promptEventBaseQuery, ['dismissed', 'discarded']),
                'no_result_count' => (clone $requestEventBaseQuery)->where('event', 'no_results')->count(),
            ],
            'top_prompts' => $promptAnalytics,
            'categories' => $categoryAnalytics,
        ]);
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

    protected function logPromptRequest(Request $request, string $endpoint, string $requestUuid, array $context = []): void
    {
        if (! Schema::hasTable('prompt_events')) {
            return;
        }

        try {
            $this->createPromptEvent(
                $request,
                $requestUuid,
                $endpoint,
                'requested',
                null,
                $this->promptEventFilters($request),
                $context
            );
        } catch (Throwable) {
            // Do not fail the API response if event logging fails.
        }
    }

    protected function logPromptEvents(Request $request, string $endpoint, string $requestUuid, Prompt|EloquentCollection|null $result, array $extraContext = []): void
    {
        if (! Schema::hasTable('prompt_events')) {
            return;
        }

        try {
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
    ): PromptEvent {
        return PromptEvent::create([
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

    protected function countEventsSubquery(string|array $events, Builder $eventBaseQuery): \Illuminate\Database\Query\Builder
    {
        $events = (array) $events;

        return (clone $eventBaseQuery)
            ->toBase()
            ->selectRaw('COUNT(*)')
            ->whereColumn('prompt_events.prompt_id', 'prompts.id')
            ->whereIn('prompt_events.event', $events);
    }

    protected function countEvents(Builder $query, array $events): int
    {
        return (clone $query)->whereIn('event', $events)->count();
    }

    protected function applyDateRange(Builder $query, array $validated): Builder
    {
        return $query
            ->when(! empty($validated['from']), fn (Builder $builder) => $builder->where('created_at', '>=', $validated['from']))
            ->when(! empty($validated['to']), fn (Builder $builder) => $builder->where('created_at', '<=', $validated['to']));
    }

    protected function applyAnalyticsRequestFilters(Builder $query, array $validated): Builder
    {
        return $query
            ->when(! empty($validated['category']), fn (Builder $builder) => $builder->where('filters->category', $validated['category']))
            ->when(! empty($validated['language']), fn (Builder $builder) => $builder->where('filters->language', $validated['language']))
            ->when(array_key_exists('minor', $validated), fn (Builder $builder) => $builder->where('filters->minor', (bool) $validated['minor']));
    }

    protected function promptEventFilters(Request $request): array
    {
        $query = $request->query();
        ksort($query);

        return $query;
    }
}
