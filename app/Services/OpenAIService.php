<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use RuntimeException;

class OpenAIService
{
    /**
     * @return string|array<string, mixed>
     */
    public function generateStructuredPrompts(string $systemPrompt): string|array
    {
        $apiKey = (string) config('services.openai.api_key', env('OPENAI_API_KEY'));

        if ($apiKey === '') {
            throw new RuntimeException('OPENAI_API_KEY is not configured.');
        }

        $baseUrl = rtrim((string) config('services.openai.base_url', env('OPENAI_BASE_URL', 'https://api.openai.com/v1')), '/');
        $model = (string) config('services.openai.model', env('OPENAI_MODEL', 'gpt-4.1-mini'));

        $response = Http::withToken($apiKey)
            ->acceptJson()
            ->post("{$baseUrl}/chat/completions", [
                'model' => $model,
                'temperature' => 0.8,
                'messages' => [
                    [
                        'role' => 'system',
                        'content' => $systemPrompt,
                    ],
                    [
                        'role' => 'user',
                        'content' => 'Return only valid JSON that matches the requested schema.',
                    ],
                ],
            ])
            ->throw();

        $payload = $response->json();
        $content = data_get($payload, 'choices.0.message.content');

        if (is_string($content) && trim($content) !== '') {
            return $content;
        }

        if (is_array($content)) {
            $text = collect($content)
                ->map(fn ($part) => is_array($part) ? ($part['text'] ?? '') : '')
                ->implode('');

            if (trim($text) !== '') {
                return $text;
            }
        }

        throw new RuntimeException('OpenAI response did not contain assistant message content.');
    }
}
