<?php

namespace App\Services;

use Illuminate\Support\Str;
use InvalidArgumentException;

class PromptGeneratorService
{
    public function buildSystemPrompt(string $category, string $language = 'en', int $count = 10, bool $minor = false): string
    {
        $category = trim($category);
        $language = trim($language);

        if ($category === '') {
            throw new InvalidArgumentException('Category is required.');
        }

        if ($language === '') {
            throw new InvalidArgumentException('Language is required.');
        }

        $languageInstruction = $this->buildLanguageInstruction($language);
        $audienceInstruction = $this->buildAudienceInstruction($minor);

        return <<<PROMPT
Generate {$count} reflective memory prompts about {$category}.
{$languageInstruction}
{$audienceInstruction}
Each prompt should:
- use simple, clear language
- focus on one concrete anecdote or moment
- encourage storytelling through a personal memory
- avoid broad, abstract, or overly poetic phrasing
- be exactly one question
- contain only one question mark
Return JSON:
{
  "prompts": [
    {
      "text": "...",
      "keywords": ["nostalgia","parents","school"],
      "tone": "simple",
      "difficulty": "medium"
    }
  ]
}
PROMPT;
    }

    protected function buildAudienceInstruction(bool $minor): string
    {
        if (! $minor) {
            return 'Target adults and general audiences. Avoid child-specific framing unless naturally relevant.';
        }

        return 'Target kids/minors. Use kid-friendly language and safe topics (for example: cartoons they like, games, school moments, friends, family fun, hobbies, and things they want to do). Avoid adult themes, romance, violence, fear-heavy topics, alcohol/drugs, and trauma-focused questions.';
    }

    protected function buildLanguageInstruction(string $language): string
    {
        $normalized = Str::lower(trim($language));

        $aliases = [
            'en' => 'English',
            'english' => 'English',
            'es' => 'Spanish',
            'spa' => 'Spanish',
            'spanish' => 'Spanish',
            'espanol' => 'Spanish',
            'español' => 'Spanish',
            'pt' => 'Portuguese',
            'portuguese' => 'Portuguese',
            'fr' => 'French',
            'french' => 'French',
        ];

        $label = $aliases[$normalized] ?? Str::title($normalized);

        return "Write all prompt text in {$label}.";
    }

    /**
     * @param  string|array<string, mixed>  $rawResponse
     * @return array<string, mixed>
     */
    public function generateForCategory(string $category, string|array $rawResponse, int $count = 10): array
    {
        $decoded = is_array($rawResponse)
            ? $rawResponse
            : $this->decodeJson($rawResponse);

        $prompts = $decoded['prompts'] ?? null;

        if (! is_array($prompts)) {
            throw new InvalidArgumentException('Invalid response format: missing prompts array.');
        }

        $normalized = collect($prompts)
            ->filter(fn ($item) => is_array($item))
            ->map(fn (array $item) => $this->normalizePrompt($item))
            ->filter(fn (array $item) => $item['text'] !== '')
            ->take($count)
            ->values()
            ->all();

        return [
            'category' => $category,
            'count' => count($normalized),
            'prompts' => $normalized,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function decodeJson(string $json): array
    {
        $decoded = json_decode($json, true);

        if (! is_array($decoded) || json_last_error() !== JSON_ERROR_NONE) {
            throw new InvalidArgumentException('Prompt generator returned invalid JSON.');
        }

        return $decoded;
    }

    /**
     * @param  array<string, mixed>  $prompt
     * @return array<string, mixed>
     */
    protected function normalizePrompt(array $prompt): array
    {
        $text = $this->normalizePromptText((string) ($prompt['text'] ?? ''));
        $keywords = $this->normalizeKeywords($prompt['keywords'] ?? null, $text);

        return [
            'text' => $text,
            'keywords' => $keywords,
            'tone' => trim((string) ($prompt['tone'] ?? 'simple')) ?: 'simple',
            'difficulty' => trim((string) ($prompt['difficulty'] ?? 'medium')) ?: 'medium',
        ];
    }

    protected function normalizePromptText(string $text): string
    {
        $text = trim($text);

        if ($text === '') {
            return '';
        }

        $firstQuestionPos = strpos($text, '?');

        if ($firstQuestionPos !== false) {
            $text = substr($text, 0, $firstQuestionPos + 1);
        }

        return trim($text);
    }

    /**
     * @param  mixed  $keywords
     * @return list<string>
     */
    protected function normalizeKeywords(mixed $keywords, string $fallbackText): array
    {
        if (is_array($keywords)) {
            $normalized = collect($keywords)
                ->map(fn ($keyword) => Str::lower(trim((string) $keyword)))
                ->filter()
                ->unique()
                ->take(6)
                ->values()
                ->all();

            if ($normalized !== []) {
                return $normalized;
            }
        }

        return $this->generateKeywords($fallbackText);
    }

    /**
     * Lightweight fallback keyword extraction when the model omits metadata.
     *
     * @return list<string>
     */
    protected function generateKeywords(string $text): array
    {
        $stopWords = [
            'the', 'and', 'for', 'with', 'from', 'that', 'this', 'your', 'about', 'into',
            'what', 'when', 'where', 'which', 'while', 'have', 'been', 'were', 'they',
            'their', 'them', 'would', 'could', 'should', 'after', 'before', 'during',
            'through', 'memory', 'prompt', 'story', 'telling',
        ];

        $words = preg_split('/[^a-z0-9]+/i', Str::lower($text)) ?: [];

        return collect($words)
            ->filter(fn (string $word) => $word !== '' && strlen($word) > 2)
            ->reject(fn (string $word) => in_array($word, $stopWords, true))
            ->unique()
            ->take(6)
            ->values()
            ->all();
    }
}
