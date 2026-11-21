<?php

namespace App\Services;

use Illuminate\Contracts\Translation\Loader;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

final class DatabaseTranslationLoader implements Loader
{
    public function __construct(private readonly Loader $base) {}

    public function load($locale, $group, $namespace = null): array
    {
        $lines = $this->base->load($locale, $group, $namespace);

        if (in_array($namespace, [null, '*'], true) === false || in_array($locale, ['ar', 'en'], true) === false) {
            return $lines;
        }

        try {
            if (Schema::hasTable('translations') === false) {
                return $lines;
            }

            // Translation groups are literal namespaces, never SQL LIKE patterns.
            // Escape operator characters in the group and leave only the final
            // percentage as the intentional prefix wildcard.
            $groupPrefix = str_replace(['!', '%', '_'], ['!!', '!%', '!_'], (string) $group.'.').'%';
            $rows = DB::table('translations')
                ->whereRaw("key LIKE ? ESCAPE '!'", [$groupPrefix])
                ->pluck($locale, 'key');

            foreach ($rows as $key => $value) {
                $relative = substr((string) $key, strlen($group) + 1);
                Arr::set($lines, $relative, (string) $value);
            }
        } catch (Throwable) {
            return $lines;
        }

        return $lines;
    }

    public function addNamespace($namespace, $hint): void
    {
        $this->base->addNamespace($namespace, $hint);
    }

    public function addJsonPath($path): void
    {
        $this->base->addJsonPath($path);
    }

    public function namespaces(): array
    {
        return $this->base->namespaces();
    }
}
