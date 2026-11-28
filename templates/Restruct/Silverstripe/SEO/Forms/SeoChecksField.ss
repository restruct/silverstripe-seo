<%-- The SEO checks panel. Server-rendered from the stored result, so it reads without JavaScript; the script
     replaces this element with a fresh copy from the CMS endpoint after a re-check or an ignore. Every value is
     escaped by the template (ArrayData strings are cast as Text). --%>
<div class="seo-checks" data-seo-checks data-seo-stale="<% if $Stale %>1<% else %>0<% end_if %>"
     data-recheck-url="$RecheckURL.ATT" data-ignore-url="$IgnoreURL.ATT"
     data-msg-checking="$MsgChecking.ATT" data-msg-not-started="$MsgNotStarted.ATT" data-msg-failed="$MsgFailed.ATT"
     data-msg-loading="$MsgLoading.ATT" data-msg-doc-failed="$MsgDocFailed.ATT" data-msg-doc-link="$MsgDocLink.ATT"
     data-msg-close="$MsgClose.ATT">
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
                        <%-- First in the finding, floated to its top right; the label is in the tooltip and the
                             aria-label, the title is what the tooltip reads (and the fallback without script) --%>
                        <% if $CanIgnore %>
                            <button type="button" class="btn btn-link btn-sm btn--no-text btn--icon-md font-icon-eye-with-line seo-checks__ignore seo-checks__ignore--icon seo-checks__tip"
                                    data-check="$ID.ATT" data-ignore="1" data-bs-toggle="tooltip" data-bs-placement="left"
                                    title="$IgnoreLabel.ATT"
                                    aria-label="$IgnoreLabel.ATT"></button>
                        <% end_if %>
                        <%-- No "Not met" marker on a failure: the fail title and the severity badge say it --%>
                        <div class="seo-checks__heading">
                            <span class="seo-checks__badge seo-checks__badge--$Severity.ATT">$SeverityLabel</span>
                            <span class="seo-checks__priority">$PriorityLabel</span>
                            <strong class="seo-checks__title">$Title</strong>
                            <%-- A link to the published spec, so it works without the script; with it, a module
                                 check's spec opens in a dialog from data-doc-url --%>
                            <% if $Docs %><a href="$Docs.ATT" target="_blank" rel="noopener noreferrer"
                               class="font-icon-info-circled seo-checks__about seo-checks__tip"<% if $DocURL %> data-doc-url="$DocURL.ATT"<% end_if %>
                               data-bs-toggle="tooltip"
                               title="$AboutLabel.ATT"
                               aria-label="$AboutLabel.ATT"></a><% end_if %>
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
                            </p>
                        <% end_if %>
                    </li>
                <% end_loop %>
            </ul>
        </div>
    <% end_loop %>

    <%-- Passed checks fold away under their count: each under its pass title, so the list reads as what is right.
         Ignored checks are listed in the same fold, after the passed ones, each with its icon button to check it
         again (product owner's call, 2026-11-28: no block of their own) --%>
    <% if $HasPassedList %>
        <details class="seo-checks__passed">
            <summary>$PassedSummary</summary>
            <ul class="seo-checks__list">
                <% loop $Passed %>
                    <li class="seo-checks__item seo-checks__item--pass">
                        <span class="seo-checks__state seo-checks__state--$Status.ATT">$StatusLabel</span>
                        <span class="seo-checks__title">$Title</span>
                    </li>
                <% end_loop %>
                <% loop $Ignored %>
                    <li class="seo-checks__item seo-checks__item--ignored" data-check="$ID.ATT">
                        <%-- First in the item, floated to its top right like the ignore button of a finding --%>
                        <button type="button" class="btn btn-link btn-sm btn--no-text btn--icon-md font-icon-eye seo-checks__ignore seo-checks__ignore--icon seo-checks__tip"
                                data-check="$ID.ATT" data-ignore="0" data-bs-toggle="tooltip" data-bs-placement="left"
                                title="$UnignoreLabel.ATT"
                                aria-label="$UnignoreLabel.ATT"></button>
                        <span class="seo-checks__state seo-checks__state--ignored">$IgnoredStateLabel</span>
                        <span class="seo-checks__title">$Title</span>
                        <em class="seo-checks__ignored-by"><%t Restruct\\Silverstripe\\SEO\\Forms\\SeoChecksField.IgnoredBy 'Ignored by {name} on {date}.' name=$IgnoredByName date=$IgnoredDate %></em>
                    </li>
                <% end_loop %>
            </ul>
        </details>
    <% end_if %>

    <% if $Skipped.Count %>
        <div class="seo-checks__skipped">
            <h4 class="seo-checks__group-title"><%t Restruct\\Silverstripe\\SEO\\Forms\\SeoChecksField.SkippedTitle 'Not checked' %></h4>
            <ul class="seo-checks__list">
                <% loop $Skipped %>
                    <li class="seo-checks__item">
                        <span class="seo-checks__state seo-checks__state--$Status.ATT">$StatusLabel</span>
                        $Title: $Message
                    </li>
                <% end_loop %>
            </ul>
        </div>
    <% end_if %>
</div>
