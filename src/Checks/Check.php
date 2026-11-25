<?php

namespace Restruct\Silverstripe\SEO\Checks;

/**
 * One SEO check: a small, single-purpose test of a page, run on a {@see PageAnalysis} (the rendered draft plus
 * the page's fields). Checks are registered in YAML on {@see CheckRunner}.checks, keyed by id.
 *
 * Two axes describe a failing check, and there is deliberately no score:
 * - severity: problem (something is broken), warning (likely to cost visibility), tip (could be better);
 * - priority: high, medium, low (how much it matters relative to the others).
 *
 * Each check has a spec next to the code, in docs/checks/<id>.md: what it checks, why, its thresholds, the
 * source and when it was last reviewed.
 */
interface Check
{
    public const PROBLEM = 'problem';
    public const WARNING = 'warning';
    public const TIP = 'tip';

    public const HIGH = 'high';
    public const MEDIUM = 'medium';
    public const LOW = 'low';

    public const GROUP_TITLE = 'title';
    public const GROUP_DESCRIPTION = 'description';
    public const GROUP_CONTENT = 'content';
    public const GROUP_IMAGES = 'images';
    public const GROUP_LINKS = 'links';
    public const GROUP_INDEXING = 'indexing';
    public const GROUP_STRUCTURED_DATA = 'structured-data';
    public const GROUP_SOCIAL = 'social';
    public const GROUP_URL = 'url';

    /**
     * In the order the panel lists them.
     */
    public const GROUPS = [
        self::GROUP_INDEXING,
        self::GROUP_TITLE,
        self::GROUP_DESCRIPTION,
        self::GROUP_CONTENT,
        self::GROUP_IMAGES,
        self::GROUP_LINKS,
        self::GROUP_STRUCTURED_DATA,
        self::GROUP_SOCIAL,
        self::GROUP_URL,
    ];

    /**
     * Stable identifier, also the YAML key and the name of the spec file (docs/checks/<id>.md).
     */
    public function id(): string;

    /**
     * One of the GROUP_* constants.
     */
    public function group(): string;

    /**
     * problem, warning or tip.
     */
    public function severity(): string;

    /**
     * high, medium or low.
     */
    public function priority(): string;

    /**
     * Short name of the check, shown in the panel.
     */
    public function title(): string;

    /**
     * General advice on fixing a failure; a result can give more specific advice.
     */
    public function howToFix(): string;

    /**
     * Whether the check reads the rendered page. Such a check is skipped (not failed) when the page could not be
     * rendered; a check that does not need the render still runs then.
     */
    public function needsRender(): bool;

    public function run(PageAnalysis $analysis): CheckResult;
}
