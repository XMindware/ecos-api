<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Prompt;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

class PromptController extends Controller
{
    public function random(Request $request)
    {
        $prompt = $this->randomPromptFromQuery($this->filteredPromptQuery($request), $request);

        if ($prompt === null && $request->filled('category')) {
            $prompt = $this->randomPromptFromQuery(
                $this->filteredPromptQuery($request, ignoreCategory: true),
                $request
            );
        }

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

        return $this->filteredPromptQuery($request)
            ->with(['category', 'keywords'])
            ->latest('id')
            ->get();
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

        return $query
            ->latest('id')
            ->limit($limit)
            ->get();
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
}
