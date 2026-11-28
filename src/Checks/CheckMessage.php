<?php

namespace Restruct\Silverstripe\SEO\Checks;

use SilverStripe\Assets\File;
use SilverStripe\Core\Convert;

/**
 * A translatable message as stored with a result: the i18n key, the English default and the parameters, so it
 * is translated when shown (in the language of whoever reads it) rather than when the check ran.
 *
 * The factory is called `_t` on purpose: the i18n text collector picks up every `_t(` call, so the keys and
 * defaults written as `$this->_t(__CLASS__ . '.Key', 'Default', [...])` land in lang/en.yml like any other.
 */
final class CheckMessage
{
    /**
     * @param array<string, mixed> $params Plain values, or messages themselves (translated with the outer one)
     * @return array{_t: string, default: string, params: array<string, mixed>}
     */
    public static function _t(string $key, string $default, array $params = []): array
    {
        return ['_t' => $key, 'default' => $default, 'params' => $params];
    }

    public static function isMessage(mixed $value): bool
    {
        return is_array($value) && isset($value['_t']) && is_string($value['_t']);
    }

    /**
     * A reference to a file by the URL the page shows (an image src, often a variant), stored as it was
     * rendered and resolved to its File when shown ({@see self::fileFor()}): by its title, linked to its edit
     * form in the Files admin, or as the URL when it is no file of this site.
     *
     * @return array{_file: string}
     */
    public static function file(string $url): array
    {
        return ['_file' => $url];
    }

    public static function isFile(mixed $value): bool
    {
        return is_array($value) && isset($value['_file']) && is_string($value['_file']);
    }

    /**
     * The text in the current locale. A plain string (a stored value of the earlier format, or data such as a
     * URL) is returned as it is.
     */
    public static function text(mixed $value): string
    {
        if (static::isFile($value)) {
            $file = static::fileFor($value['_file']);
            return $file ? static::fileLabel($file) : static::shortURL($value['_file']);
        }
        if (!static::isMessage($value)) {
            return is_scalar($value) ? (string) $value : '';
        }
        $params = [];
        foreach ((array) ($value['params'] ?? []) as $name => $param) {
            $params[$name] = static::text($param);
        }
        return (string) _t($value['_t'], (string) ($value['default'] ?? ''), $params);
    }

    /**
     * The same text as escaped HTML, with file references as links to their edit form in the Files admin.
     *
     * The translated text is escaped as a whole; a parameter that is a file or a message of its own is put in
     * as a placeholder first and replaced by its own (escaped) HTML after, so no unescaped text gets through.
     * Plain parameters stay plain, so a plural still sees its number.
     */
    public static function html(mixed $value): string
    {
        if (static::isFile($value)) {
            $file = static::fileFor($value['_file']);
            if (!$file) {
                return Convert::raw2xml(static::shortURL($value['_file']));
            }
            $label = Convert::raw2xml(static::fileLabel($file));
            $link = (string) $file->getCMSEditLink();
            if ($link === '') {
                return $label;
            }
            # A new tab: the page being edited stays open
            return '<a href="' . Convert::raw2att($link) . '" target="_blank" rel="noopener noreferrer">' . $label . '</a>';
        }
        if (!static::isMessage($value)) {
            return Convert::raw2xml(is_scalar($value) ? (string) $value : '');
        }
        $params = [];
        $replacements = [];
        # A token no translation or parameter contains, and that escaping leaves as it is
        $token = 'seoparam' . bin2hex(random_bytes(6));
        foreach ((array) ($value['params'] ?? []) as $name => $param) {
            if (static::isFile($param) || static::isMessage($param)) {
                $placeholder = $token . count($replacements) . 'x';
                $params[$name] = $placeholder;
                $replacements[$placeholder] = static::html($param);
            } else {
                $params[$name] = static::text($param);
            }
        }
        $html = Convert::raw2xml((string) _t($value['_t'], (string) ($value['default'] ?? ''), $params));
        return strtr($html, $replacements);
    }

    /**
     * The File record a URL on this site points at, variants included (resized, or converted to another
     * format), parsed by core's file ID helpers through {@see LinkResolver}; null for another host, a path
     * outside the assets or a file that does not exist.
     */
    public static function fileFor(string $url): ?File
    {
        $resolved = LinkResolver::singleton()->resolve($url);
        $record = $resolved['record'] ?? null;
        return in_array($resolved['type'] ?? '', [LinkResolver::FILE, LinkResolver::DRAFT_FILE], true) && $record instanceof File
            ? $record
            : null;
    }

    protected static function fileLabel(File $file): string
    {
        $title = trim((string) $file->Title);
        return $title !== '' ? $title : (string) $file->Name;
    }

    /**
     * A long URL cut for display, as the checks did when they stored it.
     */
    protected static function shortURL(string $url, int $max = 120): string
    {
        $url = trim((string) preg_replace('/\s+/u', ' ', $url));
        return mb_strlen($url) > $max ? rtrim(mb_substr($url, 0, $max - 3)) . '...' : $url;
    }
}
