<?php

namespace App\Http\Controllers;

use App\Support\Favorites;
use Filament\Facades\Filament;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * A page starred, or no longer — from the side menu or the page itself (filament.partials.favorites-script): its
 * system's Favorites group drawn again, to be put in place of the one shown.
 */
class FavoriteController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $data = $request->validate([
            'panel' => ['required', 'string', 'max:40'],
            'url' => ['required', 'string', 'max:2000'],
            'label' => ['nullable', 'string', 'max:500'],
            'current' => ['nullable', 'string', 'max:2000'],
        ]);

        $panel = collect(Filament::getPanels())->first(fn ($panel): bool => $panel->getId() === $data['panel']);
        abort_if($panel === null, 404);

        $added = Favorites::toggle($request->user(), $panel->getId(), $data['url'], (string) ($data['label'] ?? ''));
        if ($added === null) {
            return response()->json(['message' => __('Up to :max favorites; remove one first.', ['max' => Favorites::MAX])], 422);
        }

        Filament::setCurrentPanel($panel);

        return response()->json([
            'favorite' => $added,
            'urls' => Favorites::urls($request->user(), $panel->getId()),
            'html' => view('filament.partials.favorites', ['current' => $data['current'] ?? null])->render(),
        ]);
    }
}
