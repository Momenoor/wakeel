<?php

namespace App\Filament\Shared\Pages;

use App\Support\Guide\Guide;
use BackedEnum;
use Filament\Facades\Filament;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Pages\Page;
use Filament\Schemas\Components\Callout;
use Filament\Schemas\Components\Flex;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Html;
use Filament\Schemas\Components\Image;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\UnorderedList;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\HtmlString;
use Livewire\Attributes\Url;

/**
 * "How to use" — every screen and action of the system explained step by
 * step with screenshots, who can do it, and tips. Open to every signed-in
 * user: the guide itself shows which roles an action needs.
 */
class UserGuide extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::AcademicCap;

    // First in the menu, above every group, styled apart (theme.css:
    // .fi-sidebar-item a[href$="/user-guide"]).
    protected static ?int $navigationSort = -100;

    protected static ?string $slug = 'user-guide';

    #[Url(as: 'module')]
    public string $module = '';

    /**
     * What is typed in "Search this module…". Filtered in the browser
     * ($wire.search), so typing never waits for the server.
     */
    public string $search = '';

    public static function getNavigationLabel(): string
    {
        return __('User Guide');
    }

    public static function getNavigationGroup(): ?string
    {
        return null;
    }

    public function getTitle(): string
    {
        return __('User Guide');
    }

    public function mount(): void
    {
        $modules = $this->modules();

        if (! collect($modules)->contains('id', $this->module)) {
            $this->module = $modules[0]['id'] ?? '';
        }
    }

    /**
     * Another section of the guide opened (side list or, on a phone, the
     * dropdown): read from its top — it is the same page, so the browser
     * stayed where the last section had been scrolled to.
     */
    public function updatedModule(): void
    {
        $this->js('window.scrollTo({ top: 0, behavior: "instant" })');
    }

    /**
     * @return list<array<string, string>>
     */
    public function modules(): array
    {
        return Guide::modules(Filament::getCurrentPanel()?->getId());
    }

    /**
     * @return array<string, mixed>|null
     */
    public function current(): ?array
    {
        return Guide::module($this->module);
    }

    public function content(Schema $schema): Schema
    {
        $current = $this->current();

        return $schema->components([
            Group::make([
                Grid::make(['default' => 1, 'lg' => 4])->schema([
                    View::make('filament.shared.user-guide-nav')
                        ->viewData(['modules' => $this->modules(), 'module' => $this->module]),

                    Group::make($current
                        ? [$this->moduleIntro($current), ...array_map(fn (array $action): Section => $this->actionCard($action), $current['actions'])]
                        : [Text::make(__('No guide content yet.'))])
                        ->columnSpan(['lg' => 3]),
                ]),

                // A screenshot opened full size; a click or Escape closes it.
                Html::make('<div class="wk-zoom" x-show="zoom" x-cloak x-on:click="zoom = null"><img x-bind:src="zoom" alt="" /></div>'),
            ])->extraAttributes([
                'class' => 'wk-guide',
                'x-data' => "{ zoom: null, match(el) { const q = (\$wire.search || '').trim().toLowerCase(); return q === '' || el.dataset.text.includes(q) } }",
                'x-on:keydown.escape.window' => 'zoom = null',
            ]),
        ]);
    }

    /**
     * @param  array<string, mixed>  $module
     */
    private function moduleIntro(array $module): Section
    {
        return Section::make($module['title'])
            ->schema([
                Text::make($module['intro']),
                TextInput::make('search')
                    ->hiddenLabel()
                    ->type('search')
                    ->placeholder(__('Search this module…')),
            ]);
    }

    /**
     * One thing to do: who can, the steps with their screenshots, and tips.
     * Shown while it matches the search.
     *
     * @param  array<string, mixed>  $action
     */
    private function actionCard(array $action): Section
    {
        $text = mb_strtolower(
            $action['title'].' '.$action['description'].' '.collect($action['steps'])->pluck('text')->implode(' ')
        );
        $permissions = $action['permissions'] ?? [];

        return Section::make($action['title'])
            ->key("{$this->module}-{$action['id']}")
            ->id($action['id'])
            ->collapsible()
            // Escaped here: the section writes its extra attributes as given.
            ->extraAttributes(['data-text' => e($text), 'x-show' => 'match($el)'])
            ->schema([
                Text::make($action['description']),

                TextEntry::make("{$action['id']}_roles")
                    ->label(__('Who can do this'))
                    ->inlineLabel()
                    ->state([...$action['roles'], ...$permissions])
                    ->badge()
                    ->color(fn (string $state): string => in_array($state, $permissions, true) ? 'gray' : 'success'),

                ...array_map(fn (array $step, int $i): Flex => $this->step($step, $i + 1), $action['steps'], array_keys($action['steps'])),

                Callout::make(__('Tips'))
                    ->color('warning')
                    ->footer([UnorderedList::make(array_map(fn (string $tip): Text => Text::make($tip), $action['tips'] ?? []))])
                    ->visible(! empty($action['tips'])),
            ]);
    }

    /**
     * @param  array<string, string>  $step
     */
    private function step(array $step, int $number): Flex
    {
        $src = isset($step['shot']) ? Guide::shotUrl($this->module, $step['shot']) : null;

        return Flex::make([
            Text::make((string) $number)->badge()->grow(false),
            Group::make(array_values(array_filter([
                Text::make($step['text']),
                isset($step['code'])
                    ? Html::make(new HtmlString('<pre class="wk-code font-mono" dir="ltr">'.e($step['code']).'</pre>'))
                    : null,
                $src
                    ? Image::make($src, $step['text'])
                        ->imageWidth('100%')
                        ->imageHeight('auto')
                        ->extraAttributes(['class' => 'wk-shot', 'loading' => 'lazy', 'x-on:click' => 'zoom = '.e(json_encode($src))])
                    : null,
            ]))),
        ]);
    }
}
