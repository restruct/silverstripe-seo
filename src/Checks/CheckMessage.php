<?php

namespace Restruct\Silverstripe\SEO\Checks;

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
     * The text in the current locale. A plain string (a stored value of the earlier format, or data such as a
     * URL) is returned as it is.
     */
    public static function text(mixed $value): string
    {
        if (!static::isMessage($value)) {
            return is_scalar($value) ? (string) $value : '';
        }
        $params = [];
        foreach ((array) ($value['params'] ?? []) as $name => $param) {
            $params[$name] = static::text($param);
        }
        return (string) _t($value['_t'], (string) ($value['default'] ?? ''), $params);
    }
}
