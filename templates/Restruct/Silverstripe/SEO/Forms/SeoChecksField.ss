<%-- The SEO checks panel. Server-rendered from the stored result, so it reads without JavaScript; the script
     replaces this element with a fresh copy from the CMS endpoint after a re-check or an ignore. Every value is
     escaped by the template (ArrayData strings are cast as Text). --%>
<div class="seo-checks" data-seo-checks data-seo-stale="<% if $Stale %>1<% else %>0<% end_if %>"
     data-recheck-url="$RecheckURL.ATT" data-ignore-url="$IgnoreURL.ATT">
    <div class="seo-checks__summary">
        <% if $HasResult %>
            <p class="seo-checks__counts">$CountsText</p>
            <p class="seo-checks__meta">
                <%t Restruct\\Silverstripe\\SEO\\Forms\\SeoChecksField.CheckedAt 'Checked {date}, on the saved draft.' date=$CheckedAt.Nice %>
                <% if $Stale %><%t Restruct\\Silverstripe\\SEO\\Forms\\SeoChecksField.ChangedSince 'The page has changed since: re-check to update.' %><% end_if %>
            </p>
        <% else %>
            <p class="seo-checks__counts"><%t Restruct\\Silverstripe\\SEO\\Forms\\SeoChecksField.NotChecked 'Not checked yet.' %></p>
        <% end_if %>
        <button type="button" class="btn btn-outline-secondary btn-sm font-icon-sync seo-checks__recheck">
            <%t Restruct\\Silverstripe\\SEO\\Forms\\SeoChecksField.Recheck 'Re-check' %>
        </button>
        <span class="seo-checks__status" role="status" aria-live="polite"></span>
    </div>

    <% loop $Groups %>
        <div class="seo-checks__group">
            <h4 class="seo-checks__group-title">$Title</h4>
            <ul class="seo-checks__list">
                <% loop $Entries %>
                    <li class="seo-checks__item seo-checks__item--$Severity.ATT" data-check="$ID.ATT">
                        <div class="seo-checks__heading">
                            <span class="seo-checks__badge seo-checks__badge--$Severity.ATT">$SeverityLabel</span>
                            <span class="seo-checks__priority">$PriorityLabel</span>
                            <strong class="seo-checks__title">$Title</strong>
                        </div>
                        <p class="seo-checks__message">$Message</p>
                        <% if $Details.Count %>
                            <ul class="seo-checks__details">
                                <% loop $Details %><li>$Text</li><% end_loop %>
                            </ul>
                        <% end_if %>
                        <% if $HowToFix %>
                            <p class="seo-checks__fix">
                                <strong><%t Restruct\\Silverstripe\\SEO\\Forms\\SeoChecksField.HowToFix 'How to fix:' %></strong> $HowToFix
                                <% if $Docs %><a href="$Docs.ATT" target="_blank" rel="noopener noreferrer"><%t Restruct\\Silverstripe\\SEO\\Forms\\SeoChecksField.MoreAbout 'About this check' %></a><% end_if %>
                            </p>
                        <% end_if %>
                        <% if $CanIgnore %>
                            <button type="button" class="btn btn-link btn-sm seo-checks__ignore" data-check="$ID.ATT" data-ignore="1">
                                <%t Restruct\\Silverstripe\\SEO\\Forms\\SeoChecksField.Ignore 'Ignore for this page' %>
                            </button>
                        <% end_if %>
                    </li>
                <% end_loop %>
            </ul>
        </div>
    <% end_loop %>

    <% if $HasResult && $PassedCount %>
        <p class="seo-checks__passed"><%t Restruct\\Silverstripe\\SEO\\Forms\\SeoChecksField.Passed '{count} checks passed.' count=$PassedCount %></p>
    <% end_if %>

    <% if $Ignored.Count %>
        <div class="seo-checks__ignored">
            <h4 class="seo-checks__group-title"><%t Restruct\\Silverstripe\\SEO\\Forms\\SeoChecksField.IgnoredTitle 'Ignored for this page' %></h4>
            <ul class="seo-checks__list">
                <% loop $Ignored %>
                    <li class="seo-checks__item seo-checks__item--ignored" data-check="$ID.ATT">
                        <strong class="seo-checks__title">$Title</strong>: $Message
                        <em class="seo-checks__ignored-by"><%t Restruct\\Silverstripe\\SEO\\Forms\\SeoChecksField.IgnoredBy 'Ignored by {name} on {date}.' name=$IgnoredByName date=$IgnoredDate %></em>
                        <button type="button" class="btn btn-link btn-sm seo-checks__ignore" data-check="$ID.ATT" data-ignore="0">
                            <%t Restruct\\Silverstripe\\SEO\\Forms\\SeoChecksField.Unignore 'Check again' %>
                        </button>
                    </li>
                <% end_loop %>
            </ul>
        </div>
    <% end_if %>

    <% if $Skipped.Count %>
        <div class="seo-checks__skipped">
            <h4 class="seo-checks__group-title"><%t Restruct\\Silverstripe\\SEO\\Forms\\SeoChecksField.SkippedTitle 'Not checked' %></h4>
            <ul class="seo-checks__list">
                <% loop $Skipped %><li class="seo-checks__item">$Title: $Message</li><% end_loop %>
            </ul>
        </div>
    <% end_if %>
</div>
