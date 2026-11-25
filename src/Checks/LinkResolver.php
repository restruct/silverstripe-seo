<?php

namespace Restruct\Silverstripe\SEO\Checks;

use SilverStripe\Assets\File;
use SilverStripe\Assets\FilenameParsing\HashFileIDHelper;
use SilverStripe\Assets\FilenameParsing\NaturalFileIDHelper;
use SilverStripe\Assets\Flysystem\Filesystem;
use SilverStripe\Assets\Flysystem\FlysystemAssetStore;
use SilverStripe\Assets\Flysystem\PublicAdapter;
use SilverStripe\Assets\Storage\AssetStore;
use SilverStripe\CMS\Controllers\OldPageRedirector;
use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\Control\Director;
use SilverStripe\Core\Extensible;
use SilverStripe\Core\Injector\Injectable;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\ORM\DataObject;
use SilverStripe\Versioned\Versioned;
use Throwable;

/**
 * Works out what an href or src in the rendered page points at, from the database alone: no HTTP request is
 * made, so nothing is fetched from the network and no SSRF guard is needed.
 *
 * - a page: the URL is resolved with SiteTree::get_by_link(), segment by segment; when the full path is not a
 *   page, the longest leading part that is one counts (the rest is an action on that page, e.g. /news/tag/x),
 *   since only an HTTP request could tell whether the action exists;
 * - a file under the assets URL: by its file name, parsed with core's file ID helpers (the hash folder of a
 *   protected URL, a variant suffix, and a variant that changed the extension, such as Convert('webp'));
 * - a static file in the public folder (`_resources/...`, favicon.ico, robots.txt): found on disk; a path with
 *   `.` or `..` segments is never looked up, so nothing outside the public folder is;
 * - the old URL of a renamed or moved page: found as core's OldPageRedirector finds it, and reported as a
 *   redirect, not as broken;
 * - a URL handled by a Director rule other than the page catch-all (Security/, admin/, an API route): not checked;
 * - anything on another host, fragments, mailto:, tel: and the like: not checked.
 *
 * Translation modules: a URL with a locale prefix is not resolved by the module itself (Fluent is not supported
 * yet). An extension can resolve it in `updateResolveInternalLink(array &$result, string $url, string $relative)`,
 * called for every URL on this site with the result so far.
 *
 * A target is "published" it exists in the live stage; one that exists only as a draft is reported as such,
 * because the link breaks for visitors once this page is published.
 */
class LinkResolver
{
    use Extensible;
    use Injectable;

    public const EMPTY = 'empty';
    public const ANCHOR = 'anchor';
    public const EXTERNAL = 'external';
    public const OTHER_SCHEME = 'other';
    public const ROUTE = 'route';
    public const PAGE = 'page';
    public const DRAFT_PAGE = 'draft-page';
    public const MISSING_PAGE = 'missing-page';
    public const FILE = 'file';
    public const DRAFT_FILE = 'draft-file';
    public const MISSING_FILE = 'missing-file';
    public const OLD_URL = 'old-url';

    /**
     * @var array<string, array>
     */
    protected array $cache = [];

    /**
     * @return array{type:string, record:?DataObject, path:string}
     */
    public function resolve(string $url): array
    {
        $url = trim($url);
        if (!array_key_exists($url, $this->cache)) {
            $result = $this->doResolve($url);
            if (!in_array($result['type'], [self::EMPTY, self::ANCHOR, self::EXTERNAL, self::OTHER_SCHEME], true)) {
                $this->extend('updateResolveInternalLink', $result, $url, $result['path']);
            }
            $this->cache[$url] = $result;
        }
        return $this->cache[$url];
    }

    /**
     * Whether a resolved type means the link does not work for a visitor of the published site.
     */
    public static function isBroken(string $type): bool
    {
        return in_array($type, [self::DRAFT_PAGE, self::MISSING_PAGE, self::DRAFT_FILE, self::MISSING_FILE], true);
    }

    /**
     * Whether the URL points at this site (a relative URL, or an absolute one on the site's own host).
     */
    public function isInternal(string $url): bool
    {
        return !in_array($this->resolve($url)['type'], [self::EXTERNAL, self::OTHER_SCHEME, self::ANCHOR, self::EMPTY], true);
    }

    protected function doResolve(string $url): array
    {
        $result = fn (string $type, ?DataObject $record = null, string $path = '') => [
            'type' => $type,
            'record' => $record,
            'path' => $path,
        ];

        if ($url === '') {
            # A link shortcode to a page that no longer exists renders as an empty href
            return $result(self::EMPTY);
        }
        if (str_starts_with($url, '#')) {
            return $result(self::ANCHOR);
        }
        if (preg_match('#^([a-z][a-z0-9+.\-]*):#i', $url, $m) && !in_array(strtolower($m[1]), ['http', 'https'], true)) {
            return $result(self::OTHER_SCHEME);
        }

        $parts = parse_url(str_starts_with($url, '//') ? 'http:' . $url : $url);
        if ($parts === false) {
            return $result(self::MISSING_PAGE, null, $url);
        }
        if (!empty($parts['host']) && strcasecmp($parts['host'], $this->siteHost()) !== 0) {
            return $result(self::EXTERNAL);
        }

        $path = rawurldecode((string) ($parts['path'] ?? ''));
        if (!str_starts_with($path, '/')) {
            # A relative path without a leading slash is resolved against the site root, as the <base> tag
            # Silverstripe themes print makes the browser do
            $path = '/' . $path;
        }
        # Without the site's base path (a site installed in a subfolder)
        $base = rtrim(Director::baseURL(), '/');
        if ($base !== '' && str_starts_with($path, $base . '/')) {
            $path = substr($path, strlen($base));
        }
        $relative = trim($path, '/');

        $assetsDir = defined('ASSETS_DIR') ? ASSETS_DIR : 'assets';
        if ($relative === $assetsDir || str_starts_with($relative, $assetsDir . '/')) {
            return $this->resolveFile($relative, $result);
        }
        $static = $this->resolveStaticFile($relative);
        if ($static !== null) {
            return $result($static, null, $relative);
        }
        if ($relative !== '' && $this->isRoute($relative)) {
            return $result(self::ROUTE, null, $relative);
        }
        return $this->resolvePage($relative, $result);
    }

    protected function resolvePage(string $relative, callable $result): array
    {
        $segments = $relative === '' ? [''] : explode('/', $relative);
        foreach ([Versioned::LIVE => self::PAGE, Versioned::DRAFT => self::DRAFT_PAGE] as $stage => $type) {
            $page = Versioned::withVersionedMode(function () use ($stage, $segments) {
                Versioned::set_stage($stage);
                # The full path first, then ever shorter leading parts: /courses/detail/5 is an action on /courses
                for ($n = count($segments); $n >= 1; $n--) {
                    $page = SiteTree::get_by_link(implode('/', array_slice($segments, 0, $n)), false);
                    if ($page) {
                        return $page;
                    }
                }
                return null;
            });
            if ($page instanceof SiteTree) {
                return $result($type, $page, $relative);
            }
        }
        # A page that was renamed or moved: its old URL redirects (OldPageRedirector), so the link still works
        $newURL = Versioned::withVersionedMode(function () use ($segments) {
            Versioned::set_stage(Versioned::LIVE);
            return OldPageRedirector::find_old_page($segments);
        });
        if (is_string($newURL) && $newURL !== '') {
            $page = Versioned::withVersionedMode(function () use ($newURL) {
                Versioned::set_stage(Versioned::LIVE);
                return SiteTree::get_by_link($newURL, false);
            });
            return $result(self::OLD_URL, $page instanceof SiteTree ? $page : null, $relative);
        }
        return $result(self::MISSING_PAGE, null, $relative);
    }

    /**
     * FILE for a file that exists in the public folder, MISSING_FILE for a missing one under the resources
     * folder, null for a path that is neither.
     *
     * Containment is checked on the path itself, not on its real path: a path with a `.` or `..` segment (or a
     * NUL byte) is never a file, so nothing outside the public folder is looked at. Symbolic links inside the
     * public folder are followed, since that is how vendor-expose publishes `_resources`.
     */
    protected function resolveStaticFile(string $relative): ?string
    {
        if ($relative === '' || str_contains($relative, "\0")) {
            return null;
        }
        foreach (explode('/', $relative) as $segment) {
            if ($segment === '.' || $segment === '..') {
                return null;
            }
        }
        $public = rtrim((string) Director::publicFolder(), DIRECTORY_SEPARATOR);
        if ($public !== '' && is_file($public . DIRECTORY_SEPARATOR . $relative)) {
            return self::FILE;
        }
        $resources = defined('RESOURCES_DIR') ? RESOURCES_DIR : '_resources';
        if (str_starts_with($relative, $resources . '/')) {
            return self::MISSING_FILE;
        }
        return null;
    }

    protected function resolveFile(string $relative, callable $result): array
    {
        $filename = $this->assetFilename($relative);
        if ($filename === '') {
            return $result(self::MISSING_FILE, null, $relative);
        }
        foreach ([Versioned::LIVE => self::FILE, Versioned::DRAFT => self::DRAFT_FILE] as $stage => $type) {
            $file = Versioned::withVersionedMode(function () use ($stage, $filename) {
                Versioned::set_stage($stage);
                # By the stored file name, not File::find(): that walks the folders, and a folder that was never
                # published itself hides a published file in it
                return File::get()->filter('FileFilename', $filename)->first();
            });
            if ($file instanceof File) {
                return $result($type, $file, $relative);
            }
        }
        return $result(self::MISSING_FILE, null, $relative);
    }

    /**
     * The file name as stored on the File record (Folder/name.jpg) for an asset URL path (assets/Folder/name.jpg,
     * assets/Folder/abcdef1234/name__FitWzEwMF0.jpg).
     */
    protected function assetFilename(string $relative): string
    {
        $assetsDir = defined('ASSETS_DIR') ? ASSETS_DIR : 'assets';
        # The public store may sit in a subfolder of the assets URL (a test store does)
        $publicPrefix = $this->publicUrlPrefix();
        if ($publicPrefix !== '' && str_starts_with($relative, $publicPrefix . '/')) {
            $path = substr($relative, strlen($publicPrefix) + 1);
        } else {
            $path = (string) substr($relative, strlen($assetsDir) + 1);
        }
        $path = trim($path, '/');
        if ($path === '') {
            return '';
        }
        # Core's own parsers: a protected URL has a hash folder (HashFileIDHelper), a public one not
        # (NaturalFileIDHelper); both take off a variant and undo the extension change of a converted variant
        foreach ([HashFileIDHelper::create(), NaturalFileIDHelper::create()] as $helper) {
            $parsed = $helper->parseFileID($path);
            if ($parsed && ($helper instanceof NaturalFileIDHelper || $parsed->getHash() !== '')) {
                return (string) $parsed->getFilename();
            }
        }
        return '';
    }

    /**
     * The URL path of the public asset store relative to the site root, without slashes: usually "assets".
     */
    protected function publicUrlPrefix(): string
    {
        try {
            $store = Injector::inst()->get(AssetStore::class);
            if ($store instanceof FlysystemAssetStore) {
                $filesystem = $store->getPublicFilesystem();
                $adapter = $filesystem instanceof Filesystem ? $filesystem->getAdapter() : null;
                if ($adapter instanceof PublicAdapter) {
                    $url = (string) $adapter->getPublicUrl('');
                    $base = rtrim(Director::baseURL(), '/');
                    if ($base !== '' && str_starts_with($url, $base)) {
                        $url = substr($url, strlen($base));
                    }
                    return trim($url, '/');
                }
            }
        } catch (Throwable) {
            # A store of another kind (S3, a CDN): its URLs are on another host and are not checked
        }
        return '';
    }

    /**
     * Whether the first segment of the path is handled by a Director rule of its own rather than by pages.
     */
    protected function isRoute(string $relative): bool
    {
        $first = strtolower(explode('/', $relative)[0]);
        foreach (array_keys((array) Director::config()->get('rules')) as $pattern) {
            $literal = strtolower((string) preg_split('#/|\$#', ltrim((string) $pattern, '/'))[0]);
            if ($literal !== '' && $literal === $first) {
                return true;
            }
        }
        return false;
    }

    protected function siteHost(): string
    {
        return (string) parse_url(Director::absoluteBaseURL(), PHP_URL_HOST);
    }
}
