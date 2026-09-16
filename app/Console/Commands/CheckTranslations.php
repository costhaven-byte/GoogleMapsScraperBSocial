<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Arr;

/**
 * Compares the translation files of every configured language against the default
 * one, so a missing or stale key is caught here instead of showing up in the UI as
 * "app.runs.th.score". Run it after editing anything in lang/.
 */
class CheckTranslations extends Command
{
    protected $signature = 'lang:check';

    protected $description = 'Report translation keys missing from, or extra in, a language';

    public function handle(): int
    {
        $base = config('app.locale');
        $problems = 0;

        foreach (array_keys(config('gscraper.locales')) as $locale) {
            if ($locale === $base) {
                continue;
            }

            foreach ($this->groups($base) as $group) {
                $expected = $this->keys($base, $group);
                $actual = $this->keys($locale, $group);

                // Help and data files key their entries by the translated term itself,
                // so only the structure above that level has to match.
                $compare = in_array($group, ['help', 'data'], true)
                    ? fn (array $keys) => array_unique(array_map(fn ($k) => implode('.', array_slice(explode('.', $k), 0, 2)), $keys))
                    : fn (array $keys) => $keys;

                foreach (array_diff($compare($expected), $compare($actual)) as $key) {
                    $this->error("missing in $locale: $group.$key");
                    $problems++;
                }

                foreach (array_diff($compare($actual), $compare($expected)) as $key) {
                    $this->warn("not in $base: $locale/$group.$key");
                    $problems++;
                }
            }
        }

        if ($problems) {
            $this->newLine();
            $this->error("$problems difference(s).");

            return self::FAILURE;
        }

        $this->info('Translations are in step.');

        return self::SUCCESS;
    }

    /** @return string[] */
    private function groups(string $locale): array
    {
        return array_map(
            fn ($path) => pathinfo($path, PATHINFO_FILENAME),
            glob(lang_path($locale.'/*.php')) ?: []
        );
    }

    /** @return string[] */
    private function keys(string $locale, string $group): array
    {
        $file = lang_path("$locale/$group.php");

        return is_file($file) ? array_keys(Arr::dot(require $file)) : [];
    }
}
