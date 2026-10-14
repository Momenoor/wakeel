<?php

namespace App\Filament\Support;

use Filament\Actions\Action;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\RichEditor\EditorCommand;
use Filament\Forms\Components\RichEditor\Plugins\Contracts\RichContentPlugin;
use Filament\Forms\Components\RichEditor\RichEditorTool;
use Filament\Forms\Components\Textarea;
use Filament\Support\Enums\Width;
use Tiptap\Core\Extension;

/**
 * The text's HTML in the rich editors: a toolbar button opens it in a box to
 * read and edit, and Apply puts it back.
 *
 * What comes back goes through the same reader the form uses on save — the
 * editor's own schema, merge tags and blocks included — so whatever it cannot
 * hold (a script, an unknown tag) is dropped as it would be on save, and an
 * image kept inline in the text (data:) stays. Read on the server, not parsed
 * again in the browser, which would drop those images.
 */
class EditorSourceCode implements RichContentPlugin
{
    public static function make(): static
    {
        return app(static::class);
    }

    /**
     * @return array<Extension>
     */
    public function getTipTapPhpExtensions(): array
    {
        return [];
    }

    /**
     * @return array<string>
     */
    public function getTipTapJsExtensions(): array
    {
        return [];
    }

    /**
     * @return array<RichEditorTool>
     */
    public function getEditorTools(): array
    {
        return [
            RichEditorTool::make('sourceCode')
                ->label(__('HTML source'))
                ->icon('heroicon-o-code-bracket-square')
                ->action(arguments: '{ html: $getEditor().getHTML() }'),
        ];
    }

    /**
     * @return array<Action>
     */
    public function getEditorActions(): array
    {
        return [
            Action::make('sourceCode')
                ->label(__('HTML source'))
                ->modalHeading(__('HTML source'))
                ->modalDescription(__('Edit the HTML of this text. What the editor cannot hold is dropped when you apply.'))
                ->modalWidth(Width::SevenExtraLarge)
                ->modalSubmitActionLabel(__('Apply'))
                ->fillForm(fn (array $arguments): array => ['html' => self::format((string) ($arguments['html'] ?? ''))])
                ->schema([
                    Textarea::make('html')
                        ->hiddenLabel()
                        ->rows(24)
                        ->extraInputAttributes(['dir' => 'ltr', 'spellcheck' => 'false', 'style' => 'font-family: ui-monospace, Menlo, Consolas, monospace; font-size: .8rem; line-height: 1.5; white-space: pre; overflow-wrap: normal;']),
                ])
                ->action(function (array $arguments, array $data, RichEditor $component): void {
                    $document = $component->getTipTapEditor()
                        ->setContent((string) ($data['html'] ?? ''))
                        ->getDocument();

                    $component->runCommands([
                        EditorCommand::make('setContent', arguments: [$document, ['emitUpdate' => true]]),
                    ]);
                }),
        ];
    }

    /**
     * The editor's HTML on one line, broken after block tags so it can be read.
     * Only whitespace between block tags is added, which the editor ignores.
     */
    public static function format(string $html): string
    {
        $blocks = 'p|h[1-6]|ul|ol|li|table|thead|tbody|tfoot|tr|td|th|blockquote|pre|div|details|summary|hr';

        $html = preg_replace('~(</(?:'.$blocks.')>|<hr\b[^>]*>)(?!\s*\n)~i', "$1\n", $html) ?? $html;
        $html = preg_replace('~(?<!\n)(<(?:table|thead|tbody|tfoot|tr|ul|ol|blockquote|details)\b[^>]*>)~i', "\n$1", $html) ?? $html;

        return trim(preg_replace("~\n{2,}~", "\n", $html) ?? $html);
    }
}
