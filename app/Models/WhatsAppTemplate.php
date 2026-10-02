<?php

namespace App\Models;

use App\Services\MMS\BulkMailPlaceholders;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * A WhatsApp message template as approved in Meta's WhatsApp Manager: its
 * name and language there, whether a file goes in its header, and the
 * value of each of its {{parameters}} — which may hold the system's own
 * {{placeholders}}. Its text here is for the preview; Meta sends its own.
 */
#[Fillable('name', 'purpose', 'meta_name', 'language', 'header', 'body', 'parameters', 'acknowledgement', 'is_active', 'is_default')]
class WhatsAppTemplate extends Model
{
    /** Minutes sent to the attendees to sign and send back. */
    public const MINUTES_SIGNATURE = 'minutes_signature';

    protected $table = 'whatsapp_templates';

    public function casts(): array
    {
        return [
            'parameters' => 'array',
            'is_active' => 'boolean',
            'is_default' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        // One default for each purpose.
        static::saved(function (WhatsAppTemplate $template): void {
            if ($template->is_default) {
                static::query()->whereKeyNot($template->getKey())->where('purpose', $template->purpose)->where('is_default', true)->update(['is_default' => false]);
            }
        });
    }

    /**
     * @return array<string, string>
     */
    public static function purposes(): array
    {
        return [self::MINUTES_SIGNATURE => __('Minutes for signature')];
    }

    public static function default(string $purpose): ?self
    {
        return static::query()->where('purpose', $purpose)->where('is_active', true)->orderByDesc('is_default')->oldest('id')->first();
    }

    /**
     * Each parameter's value, its placeholders filled.
     *
     * @param  array<string, string>  $values
     * @return array<string, string> parameter name => text
     */
    public function parameterValues(array $values): array
    {
        return collect($this->parameters ?? [])
            ->filter(fn ($p) => is_array($p) && filled($p['name'] ?? null))
            ->mapWithKeys(fn (array $p) => [trim($p['name']) => trim(BulkMailPlaceholders::apply((string) ($p['value'] ?? ''), array_map('strip_tags', $values))) ?: '—'])
            ->all();
    }

    /**
     * The message as the recipient reads it: the text with its parameters.
     *
     * @param  array<string, string>  $parameters
     */
    public function preview(array $parameters): string
    {
        return preg_replace_callback('/\{\{\s*([a-z0-9_]+)\s*\}\}/u', fn (array $m) => $parameters[$m[1]] ?? $m[0], (string) $this->body) ?? '';
    }
}
