<?php

namespace App\Services\Translation;

use Anthropic\Client;
use App\Support\Integrations;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Names in Arabic — of people, companies, government bodies — put into English by Claude, as they are written
 * in the UAE (محمد → Mohammed, شركة ... ذ.م.م → ... L.L.C, دائرة ... → ... Department): a person's name spelt in
 * English letters, a company's or body's words translated. A hundred names a request, each asked once.
 * Off without a key (Settings → Integrations → Claude).
 */
class NameTranslator
{
    /** The models that may be chosen in the settings: the most capable first, the cheapest last. */
    public const MODELS = [
        'claude-opus-5-5' => 'Claude Opus 5.5',
        'claude-sonnet-5-5' => 'Claude Sonnet 5.5',
        'claude-haiku-5-5' => 'Claude Haiku 5.5',
    ];

    /** Names a request: long enough lists to be cheap, short enough for the answer to come back quickly. */
    private const BATCH = 100;

    private const PROMPT = <<<'TXT'
        You put the names of a UAE law firm's clients, opponents, experts and employees from Arabic into English, as they would be written in an English letter or on an official UAE document.
        - A person's name is spelt in English letters as such names are usually written in the UAE and the Gulf: محمد → Mohammed, عبد الله → Abdullah, عبد الرحمن → Abdulrahman, آل / ال → Al, بن → Bin. Never translate the meaning of a person's name (سعيد is Saeed, not Happy).
        - A company's, shop's or body's name has its descriptive words translated and its own name spelt: شركة النجوم للتجارة العامة ذ.م.م → Al Nujoom General Trading Co. L.L.C; مطعم ومقهى الواحة → Al Waha Restaurant & Cafe; ش.ذ.م.م → L.L.C; م.م.ح → FZE; ش.م.ع → PJSC. A foreign name written in Arabic letters goes back to its usual spelling (جون سميث → John Smith).
        - Keep any part already in English or in numbers as it is. Capitalise as a name is capitalised.
        - When a name is not a name at all (stray letters, a test entry), give it spelt in English letters as best you can.
        Answer with every id you were given, each with its name in English only.
        TXT;

    public static function on(): bool
    {
        return Integrations::claudeOn();
    }

    /** Whether there is Arabic in it: what is all in English letters has nothing to translate. */
    public static function arabic(?string $text): bool
    {
        return preg_match('/\p{Arabic}/u', (string) $text) === 1;
    }

    /**
     * The names in English, keyed by the name as given (trimmed); those without Arabic, and any Claude gave
     * back nothing for, left out.
     *
     * @param  iterable<string|null>  $names
     * @return array<string, string>
     */
    public function translate(iterable $names): array
    {
        $names = collect($names)
            ->map(fn (?string $name): string => trim((string) $name))
            ->filter(fn (string $name): bool => self::arabic($name))
            ->unique()
            ->values();

        $english = [];

        foreach ($names->chunk(self::BATCH) as $chunk) {
            $english += $this->ask($chunk->values()->all());
        }

        return $english;
    }

    public function one(?string $name): ?string
    {
        $name = trim((string) $name);

        return $this->translate([$name])[$name] ?? null;
    }

    /**
     * One request: the names numbered, each answered by its number — so a name is never matched to another's.
     *
     * @param  list<string>  $names
     * @return array<string, string>
     */
    protected function ask(array $names): array
    {
        $message = $this->client()->beta->messages->create(
            maxTokens: 16000,
            messages: [['role' => 'user', 'content' => json_encode(
                array_map(fn (int $id, string $name): array => ['id' => $id, 'arabic' => $name], array_keys($names), $names),
                JSON_UNESCAPED_UNICODE,
            )]],
            model: (string) config('services.anthropic.model'),
            // Another model answers when this one declines (it should not for names, but a list is never lost to it).
            fallbacks: 'default',
            // Names need no long thought.
            outputConfig: [
                'effort' => 'low',
                'format' => [
                    'type' => 'json_schema',
                    'schema' => [
                        'type' => 'object',
                        'properties' => [
                            'names' => [
                                'type' => 'array',
                                'items' => [
                                    'type' => 'object',
                                    'properties' => ['id' => ['type' => 'integer'], 'english' => ['type' => 'string']],
                                    'required' => ['id', 'english'],
                                    'additionalProperties' => false,
                                ],
                            ],
                        ],
                        'required' => ['names'],
                        'additionalProperties' => false,
                    ],
                ],
            ],
            system: self::PROMPT,
            betas: ['server-side-fallback-2026-07-01'],
        );

        if ($message->stopReason === 'refusal') {
            throw new RuntimeException(__('Claude declined to translate these names.'));
        }

        $text = collect($message->content)->first(fn ($block): bool => $block->type === 'text')?->text;
        $answer = json_decode((string) $text, true);

        $english = [];

        foreach ((array) ($answer['names'] ?? []) as $row) {
            $name = $names[(int) ($row['id'] ?? -1)] ?? null;
            $translated = Str::limit(trim((string) ($row['english'] ?? '')), 255, '');

            // An answer still in Arabic, or empty, is no English name.
            if ($name !== null && $translated !== '' && ! self::arabic($translated)) {
                $english[$name] = $translated;
            }
        }

        return $english;
    }

    protected function client(): Client
    {
        return new Client(apiKey: (string) config('services.anthropic.key'));
    }
}
