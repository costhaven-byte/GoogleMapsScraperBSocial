<?php

/*
 * Builds a SiteGround-ready deployment zip:
 *
 *   dist/gscraper-deploy-<timestamp>.zip
 *     gscraper/      the application: upload OUTSIDE public_html
 *     public_html/   the contents of public/, with index.php pointing at ../gscraper
 *
 * Run on your development machine after `npm run build`:
 *   composer package          (or: php scripts/package.php)
 *
 * Composer is called as `composer` unless COMPOSER_BIN is set,
 * e.g. COMPOSER_BIN="php C:/tools/composer.phar".
 */

$root = str_replace('\\', '/', dirname(__DIR__));
$stamp = date('Ymd-His');
$dist = "{$root}/dist";
$stage = "{$dist}/stage-{$stamp}";
$app = "{$stage}/gscraper";
$web = "{$stage}/public_html";

function fail(string $message): never
{
    fwrite(STDERR, "\n  ERROR: {$message}\n\n");
    exit(1);
}

function step(string $message): void
{
    echo "==> {$message}\n";
}

if (! class_exists(ZipArchive::class)) {
    fail('The PHP zip extension is required (enable extension=zip in php.ini).');
}
if (! is_file("{$root}/public/build/manifest.json")) {
    fail('public/build/manifest.json is missing. Run `npm ci && npm run build` first.');
}
if (! is_file("{$root}/composer.lock")) {
    fail('composer.lock is missing. Run `composer install` first.');
}

// Paths (relative to the project root) that never go to the server.
$exclude = [
    '.git', '.github', '.tools', '.idea', '.vscode', '.cursor', '.codex', '.zed', '.claude',
    'node_modules', 'vendor', 'worker', 'tests', 'dist', 'docs', 'scripts',
    '.env', '.env.backup', '.env.production', '.editorconfig', '.gitattributes', '.npmrc',
    '.phpunit.result.cache', '.phpunit.cache', 'phpunit.xml',
    'package.json', 'package-lock.json', 'vite.config.js', 'README.md',
    'resources/css', 'resources/js',
    'public/hot', 'public/storage',
    'storage/logs', 'storage/framework/cache/data', 'storage/framework/sessions',
    'storage/framework/views', 'storage/framework/testing', 'storage/pail',
];
$excludeFilePatterns = ['#^database/.*\.sqlite$#', '#^bootstrap/cache/.*\.php$#', '#\.log$#'];

// Writable directories that must exist on the server (kept empty).
$keepDirs = [
    'storage/logs', 'storage/framework/cache/data', 'storage/framework/sessions',
    'storage/framework/views', 'bootstrap/cache', 'storage/app/private',
];

step("Copying application files to {$stage}");
@mkdir($app, 0775, true);
$iterator = new RecursiveIteratorIterator(
    new RecursiveCallbackFilterIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        function (SplFileInfo $file) use ($root, $exclude, $excludeFilePatterns) {
            $rel = ltrim(substr(str_replace('\\', '/', $file->getPathname()), strlen($root)), '/');
            if (in_array($rel, $exclude, true)) {
                return false;
            }
            if ($file->isFile()) {
                foreach ($excludeFilePatterns as $pattern) {
                    if (preg_match($pattern, $rel)) {
                        return false;
                    }
                }
            }

            return true;
        }
    ),
    RecursiveIteratorIterator::SELF_FIRST
);
$files = 0;
foreach ($iterator as $file) {
    $rel = ltrim(substr(str_replace('\\', '/', $file->getPathname()), strlen($root)), '/');
    $target = "{$app}/{$rel}";
    if ($file->isDir()) {
        @mkdir($target, 0775, true);
    } else {
        @mkdir(dirname($target), 0775, true);
        copy($file->getPathname(), $target) || fail("Could not copy {$rel}");
        $files++;
    }
}
foreach ($keepDirs as $dir) {
    @mkdir("{$app}/{$dir}", 0775, true);
    file_put_contents("{$app}/{$dir}/.gitignore", "*\n!.gitignore\n");
}
echo "    {$files} files\n";

step('Installing production PHP dependencies (composer install --no-dev)');
$composer = getenv('COMPOSER_BIN') ?: 'composer';
passthru("{$composer} install --no-dev --optimize-autoloader --classmap-authoritative --no-interaction --no-progress --no-scripts --working-dir=".escapeshellarg($app), $code);
$code === 0 || fail('composer install failed.');
passthru(escapeshellarg(PHP_BINARY).' '.escapeshellarg("{$app}/artisan").' package:discover --ansi', $code);
$code === 0 || fail('package:discover failed.');
// Cached config/routes contain absolute paths from this machine: they must be rebuilt on the server.
foreach (glob("{$app}/bootstrap/cache/{config,routes-*,events}.php", GLOB_BRACE) ?: [] as $cached) {
    unlink($cached);
}

step('Splitting public/ into public_html/');
rename("{$app}/public", $web) || fail('Could not move public/ to public_html/.');
$index = file_get_contents("{$web}/index.php");
$index = str_replace("__DIR__.'/../", "__DIR__.'/../gscraper/", $index, $count);
$count === 3 || fail('Unexpected public/index.php layout; update scripts/package.php.');
$index = str_replace('$app->handleRequest(', "// public_html is the web root on SiteGround.\n\$app->usePublicPath(__DIR__);\n\n\$app->handleRequest(", $index);
file_put_contents("{$web}/index.php", $index);

// If gscraper/ is ever uploaded inside the web root by mistake, Apache still refuses to serve it.
file_put_contents("{$app}/.htaccess", "# Application code: never served over HTTP.\nRequire all denied\n");

step('Creating zip');
$zipPath = "{$dist}/gscraper-deploy-{$stamp}.zip";
$zip = new ZipArchive;
$zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true || fail("Cannot write {$zipPath}");
$all = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($stage, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
foreach ($all as $file) {
    $rel = ltrim(substr(str_replace('\\', '/', $file->getPathname()), strlen($stage)), '/');
    $file->isDir() ? $zip->addEmptyDir($rel) : $zip->addFile($file->getPathname(), $rel);
}
$zip->close();

$mb = number_format(filesize($zipPath) / 1048576, 1);
echo "\n  Done: {$zipPath} ({$mb} MB)\n";
echo "  Staged copy kept in {$stage} (safe to delete).\n";
echo "  Next: follow docs/DEPLOYMENT.md, section 4 (Upload).\n\n";
