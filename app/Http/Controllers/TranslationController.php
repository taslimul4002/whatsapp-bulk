<?php

namespace App\Http\Controllers;

use App\Http\Requests\SaveTranslationRequest;
use App\Models\Translation;
use App\Support\Locales;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class TranslationController extends Controller
{
    public function index(): Response
    {
        $translations = Translation::query()
            ->orderBy('key')
            ->get(['key', 'locale', 'value'])
            ->groupBy('key')
            ->map(fn ($items): array => [
                'key' => $items->first()->key,
                'en' => $items->firstWhere('locale', Locales::English)?->value ?? '',
                'ar' => $items->firstWhere('locale', Locales::Arabic)?->value ?? '',
            ])
            ->values();

        return Inertia::render('Translations', ['translationEntries' => $translations]);
    }

    public function store(SaveTranslationRequest $request): RedirectResponse
    {
        $data = $request->validated();

        if (Translation::query()->where('key', $data['key'])->exists()) {
            throw ValidationException::withMessages([
                'key' => 'A translation with this key already exists.',
            ]);
        }

        $this->saveTranslations($data['key'], [
            Locales::English => $data[Locales::English],
            Locales::Arabic => $data[Locales::Arabic],
        ]);

        return back()->with('success', 'flash.translations_created');
    }

    public function update(SaveTranslationRequest $request, string $key): RedirectResponse
    {
        abort_unless(Translation::query()->where('key', $key)->exists(), 404);

        $data = $request->validated();
        $this->saveTranslations($key, [
            Locales::English => $data[Locales::English],
            Locales::Arabic => $data[Locales::Arabic],
        ]);

        return back()->with('success', 'flash.translations_updated');
    }

    public function export(Request $request): StreamedResponse
    {
        $locale = $request->query('locale', app()->getLocale());
        abort_unless(is_string($locale) && in_array($locale, Locales::Supported, true), 404);

        $translations = Translation::query()
            ->where('locale', $locale)
            ->orderBy('key')
            ->pluck('value', 'key')
            ->all();

        return response()->streamDownload(
            static function () use ($translations): void {
                echo json_encode(
                    $translations,
                    JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
                );
            },
            "translations-{$locale}.json",
            ['Content-Type' => 'application/json; charset=utf-8'],
        );
    }

    /**
     * @param  array{en: string, ar: string}  $values
     */
    private function saveTranslations(string $key, array $values): void
    {
        DB::transaction(function () use ($key, $values): void {
            foreach (Locales::Supported as $locale) {
                Translation::query()->updateOrCreate(
                    ['locale' => $locale, 'key' => $key],
                    ['value' => $values[$locale]],
                );
            }
        });
    }
}
