<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\AppPreviewInvalidationStream;
use App\Services\AppPreviewSessionService;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class AppPreviewInvalidationController extends Controller
{
    public function __invoke(
        Request $request,
        AppPreviewSessionService $sessions,
        AppPreviewInvalidationStream $stream,
    ): StreamedResponse {
        $token = $request->header('X-Foodex-Preview-Token');
        $preferredLocale = strtolower((string) $request->getPreferredLanguage(['ar', 'en']));
        $acceptedLocales = array_map(
            static fn (string $language): string => explode('-', str_replace('_', '-', strtolower($language)), 2)[0],
            $request->getLanguages(),
        );
        $appLocale = strtolower((string) app()->getLocale());
        $fallbackLocale = in_array($appLocale, ['ar', 'en'], true) ? $appLocale : 'en';
        $locale = in_array($preferredLocale, $acceptedLocales, true) ? $preferredLocale : $fallbackLocale;

        abort_unless(
            is_string($token) && trim($token) !== '',
            401,
            (string) __('preview_session.token_required', [], $locale),
        );

        $session = $sessions->resolve($token, $request);
        abort_unless($session->target_type === 'customer', 404);
        abort_unless((string) $session->mode === 'read_only', 403);
        abort_unless($session->store_id !== null, 401);

        return $stream->response(
            (int) $session->store_id,
            (string) $session->channel,
            $stream->lastEventId($request->header('Last-Event-ID')),
        );
    }
}
