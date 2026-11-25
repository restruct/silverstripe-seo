<?php

namespace Restruct\Silverstripe\SEO\Checks;

use DOMElement;
use DOMNode;
use DOMXPath;
use Restruct\Silverstripe\PageRender\HtmlHead;
use Restruct\Silverstripe\PageRender\NarrowedContent;
use Restruct\Silverstripe\PageRender\RenderResult;
use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\Control\Director;
use SilverStripe\Core\Config\Config;
use SilverStripe\Core\Injector\Injectable;
use SilverStripe\i18n\i18n;

/**
 * What every check reads: the page record (draft), the rendered draft (parsed once, shared by all checks), the
 * values the page's fields produce, and site-level lookups.
 *
 * Values that the rendered head carries (title, description) come from the render when there is one, since that
 * is what search engines get, and from the page's SEO methods otherwise.
 */
class PageAnalysis
{
    use Injectable;

    protected ?NarrowedContent $content = null;

    protected ?HtmlHead $head = null;

    protected ?LinkResolver $links = null;

    public function __construct(
        protected readonly SiteTree $page,
        protected readonly ?RenderResult $render,
        protected readonly SiteData $site
    ) {
    }

    public function getPage(): SiteTree
    {
        return $this->page;
    }

    public function getRender(): ?RenderResult
    {
        return $this->render;
    }

    /**
     * Whether there is a usable render: the page answered with a 2xx status and some HTML.
     */
    public function isRendered(): bool
    {
        return $this->render !== null && $this->render->isSuccessful() && trim($this->render->getHtml()) !== '';
    }

    public function site(): SiteData
    {
        return $this->site;
    }

    /**
     * One resolver per analysis, so its cache never outlives the run.
     */
    public function links(): LinkResolver
    {
        return $this->links ??= LinkResolver::create();
    }

    /**
     * The content area of the rendered page (`<main>`, else `<body>`, with the ignored elements removed).
     */
    public function content(): ?NarrowedContent
    {
        if (!$this->isRendered()) {
            return null;
        }
        return $this->content ??= $this->render->content();
    }

    public function head(): ?HtmlHead
    {
        if (!$this->isRendered()) {
            return null;
        }
        return $this->head ??= $this->render->head();
    }

    public function contentRoot(): ?DOMElement
    {
        return $this->content()?->getRoot();
    }

    /**
     * Elements matching an XPath expression, relative to the content root by default. An expression starting
     * with `//` searches the whole (narrowed) document, the body outside `<main>` included.
     *
     * @return DOMElement[]
     */
    public function query(string $expression, ?DOMNode $context = null): array
    {
        $content = $this->content();
        if (!$content || !$content->getRoot()) {
            return [];
        }
        $nodes = $content->xpath()->query($expression, $context ?? $content->getRoot());
        $elements = [];
        foreach ($nodes ?: [] as $node) {
            if ($node instanceof DOMElement) {
                $elements[] = $node;
            }
        }
        return $elements;
    }

    public function xpath(): ?DOMXPath
    {
        return $this->content()?->xpath();
    }

    /**
     * The text of the content area, words separated at block boundaries.
     */
    public function contentText(): string
    {
        return (string) $this->content()?->getText();
    }

    /**
     * The complete `<title>`: null when the rendered page has no title element.
     */
    public function title(): ?string
    {
        if ($this->isRendered()) {
            return $this->head()->title();
        }
        return (string) $this->page->SeoTitle();
    }

    /**
     * The page's own part of the title (meta title, else page title), without the site name.
     */
    public function pageTitle(): string
    {
        return (string) $this->page->SeoPageTitle();
    }

    /**
     * The description as typed by the editor; empty when none was typed.
     */
    public function typedDescription(): string
    {
        return trim((string) $this->page->MetaDescription);
    }

    /**
     * The description search engines get: the rendered meta description, typed or derived.
     */
    public function description(): string
    {
        if ($this->isRendered()) {
            return trim((string) $this->head()->meta('description'));
        }
        return trim((string) $this->page->SeoDescription());
    }

    /**
     * Whether the page itself asks not to be indexed. The site-wide `seo_noindex_non_live` (every page noindex
     * on a test environment) is the environment's choice, not the page's, so it is left out here.
     */
    public function isNoindex(): bool
    {
        $page = $this->page;
        return Config::withConfig(function ($config) use ($page) {
            $config->set(get_class($page), 'seo_noindex_non_live', false);
            return in_array('noindex', (array) $page->SeoRobotsDirectives(), true);
        });
    }

    /**
     * The two-letter language of the page: the rendered `<html lang>`, else the site's default locale.
     */
    public function language(): string
    {
        $lang = (string) ($this->head()?->lang() ?: i18n::config()->get('default_locale'));
        return strtolower(substr(str_replace('_', '-', $lang), 0, 2));
    }

    /**
     * The page's own public URL, without a query string: what a self-referencing canonical is.
     */
    public function ownURL(): string
    {
        return (string) strtok((string) $this->page->AbsoluteLink(), '?');
    }

    public function isHttpsSite(): bool
    {
        return str_starts_with(strtolower(Director::absoluteBaseURL()), 'https://');
    }
}
