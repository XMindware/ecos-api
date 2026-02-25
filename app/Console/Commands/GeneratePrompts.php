<?php

namespace App\Console\Commands;

use App\Models\Category;
use App\Models\Keyword;
use App\Models\Prompt;
use App\Services\OpenAIService;
use App\Services\PromptGeneratorService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Throwable;

class GeneratePrompts extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'ecos:generate-prompts
                            {category}
                            {language=en}
                            {--count=10}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Generate prompts with OpenAI, store them, sync keywords, and print a summary';

    /**
     * Execute the console command.
     */
    public function handle(PromptGeneratorService $promptGenerator, OpenAIService $openAIService): int
    {
        $missingSchema = $this->missingSchemaRequirements();

        if ($missingSchema !== []) {
            $this->error('Missing required tables/columns for prompt generation:');

            foreach ($missingSchema as $item) {
                $this->line(" - {$item}");
            }

            return self::FAILURE;
        }

        $categoryInput = (string) $this->argument('category');
        $language = (string) $this->argument('language');
        $count = max(1, (int) $this->option('count'));

        $this->info("Generating {$count} prompts for category '{$categoryInput}' ({$language})...");

        try {
            $systemPrompt = $promptGenerator->buildSystemPrompt($categoryInput, $language, $count);
            $rawResponse = $openAIService->generateStructuredPrompts($systemPrompt);
            $generated = $promptGenerator->generateForCategory($categoryInput, $rawResponse, $count);

            $summary = DB::transaction(function () use ($categoryInput, $language, $generated) {
                $category = $this->upsertCategory($categoryInput);

                $promptCreated = 0;
                $promptUpdated = 0;
                $keywordCreated = 0;
                $keywordReused = 0;
                $keywordLinks = 0;

                foreach ($generated['prompts'] as $payload) {
                    [$prompt, $wasCreated] = $this->upsertPrompt($category, $language, $payload);
                    $wasCreated ? $promptCreated++ : $promptUpdated++;

                    [$keywordIds, $createdCount, $reusedCount] = $this->resolveKeywordIds($payload['keywords'] ?? []);
                    $keywordCreated += $createdCount;
                    $keywordReused += $reusedCount;

                    $prompt->keywords()->sync($keywordIds);
                    $keywordLinks += count($keywordIds);
                }

                return [
                    'category' => $category,
                    'prompts_created' => $promptCreated,
                    'prompts_updated' => $promptUpdated,
                    'keywords_created' => $keywordCreated,
                    'keywords_reused' => $keywordReused,
                    'keyword_links' => $keywordLinks,
                    'generated_count' => count($generated['prompts']),
                ];
            });
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->newLine();
        $this->info('Prompt generation complete.');
        $this->line('Summary:');
        $this->line(' - Category: '.$summary['category']->name.' (slug: '.$summary['category']->slug.')');
        $this->line(' - Generated: '.$summary['generated_count']);
        $this->line(' - Prompts created: '.$summary['prompts_created']);
        $this->line(' - Prompts updated: '.$summary['prompts_updated']);
        $this->line(' - Keywords created: '.$summary['keywords_created']);
        $this->line(' - Keywords reused: '.$summary['keywords_reused']);
        $this->line(' - Keyword links synced: '.$summary['keyword_links']);

        return self::SUCCESS;
    }

    /**
     * @return list<string>
     */
    protected function missingSchemaRequirements(): array
    {
        $requirements = [
            'categories' => ['name', 'slug'],
            'prompts' => ['category_id', 'text', 'language'],
            'keywords' => ['name', 'slug'],
            'keyword_prompt' => ['prompt_id', 'keyword_id'],
        ];

        $missing = [];

        foreach ($requirements as $table => $columns) {
            if (! Schema::hasTable($table)) {
                $missing[] = "table: {$table}";
                continue;
            }

            foreach ($columns as $column) {
                if (! Schema::hasColumn($table, $column)) {
                    $missing[] = "{$table}.{$column}";
                }
            }
        }

        return $missing;
    }

    protected function upsertCategory(string $categoryInput): Category
    {
        $slug = Str::slug($categoryInput);
        $name = Str::of($categoryInput)->replace('-', ' ')->title()->toString();

        $category = Category::query()->where('slug', $slug)->first() ?? new Category();
        $category->slug = $slug;
        $category->name = $category->name ?: $name;

        if (Schema::hasColumn('categories', 'is_active')) {
            $category->is_active = true;
        }

        $category->save();

        return $category;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{0: Prompt, 1: bool}
     */
    protected function upsertPrompt(Category $category, string $language, array $payload): array
    {
        $text = (string) ($payload['text'] ?? '');

        $prompt = Prompt::query()
            ->where('category_id', $category->id)
            ->where('language', $language)
            ->where('text', $text)
            ->first() ?? new Prompt();

        $wasCreated = ! $prompt->exists;
        $prompt->category_id = $category->id;
        $prompt->language = $language;
        $prompt->text = $text;

        if (Schema::hasColumn('prompts', 'tone')) {
            $prompt->tone = (string) ($payload['tone'] ?? 'simple');
        }

        if (Schema::hasColumn('prompts', 'difficulty')) {
            $prompt->difficulty = (string) ($payload['difficulty'] ?? 'medium');
        }

        if (Schema::hasColumn('prompts', 'is_active')) {
            $prompt->is_active = true;
        }

        $prompt->save();

        return [$prompt, $wasCreated];
    }

    /**
     * @param  array<int, string>  $keywords
     * @return array{0: list<int>, 1: int, 2: int}
     */
    protected function resolveKeywordIds(array $keywords): array
    {
        $ids = [];
        $created = 0;
        $reused = 0;

        foreach ($keywords as $keywordValue) {
            $name = trim((string) $keywordValue);

            if ($name === '') {
                continue;
            }

            $slug = Str::slug($name);

            if ($slug === '') {
                continue;
            }

            $keyword = Keyword::query()->where('slug', $slug)->first() ?? new Keyword();
            $wasCreated = ! $keyword->exists;
            $keyword->slug = $slug;
            $keyword->name = $keyword->name ?: $name;

            if (Schema::hasColumn('keywords', 'is_active')) {
                $keyword->is_active = true;
            }

            $keyword->save();

            $wasCreated ? $created++ : $reused++;
            $ids[] = (int) $keyword->id;
        }

        return [array_values(array_unique($ids)), $created, $reused];
    }
}
