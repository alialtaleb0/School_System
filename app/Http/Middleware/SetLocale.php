<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Symfony\Component\HttpFoundation\Response;

class SetLocale
{
    /**
     * تحديد لغة الاستجابة بناءً على ترويسة Accept-Language
     * أو معامل X-Locale (مع دعم فاصلة q-weights مثل: ar,en;q=0.8).
     *
     * اللغات المدعومة: ar / en — أي لغة غير مدعومة تعود للـ fallback locale.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $supported = config('app.supported_locales', ['ar', 'en']);

        $locale = $request->header('X-Locale')
            ?? $request->header('Accept-Language')
            ?? $request->query('lang')
            ?? config('app.locale');

        if (is_string($locale) && str_contains($locale, ',')) {
            $parts = array_map(
                fn ($part) => trim(explode(';', $part)[0]),
                explode(',', $locale),
            );
            $locale = $parts[0] ?? $locale;
        }

        $locale = strtolower(trim((string) $locale));

        if (! in_array($locale, $supported, true)) {
            $locale = config('app.fallback_locale');
        }

        App::setLocale($locale);

        return $next($request);
    }
}
