<%-- Search result preview as of the last save; the script updates it while the editor types, using the
     thresholds in data-seo-config (the checks' own config). --%>
<div class="seo-snippet" data-seo-config="$ConfigJSON.ATT">
    <div class="seo-snippet__result">
        <div class="seo-snippet__url"><span class="seo-snippet__host">$Host</span><% loop $Path %> <span class="seo-snippet__crumb">&rsaquo; $Segment</span><% end_loop %></div>
        <div class="seo-snippet__title" data-seo-preview="title">$PreviewTitle</div>
        <div class="seo-snippet__description" data-seo-preview="description">$PreviewDescription</div>
    </div>
    <%-- The numbers sit in their own spans: the script updates those and the state class, not the text --%>
    <p class="seo-snippet__meter" data-seo-meter="title">
        <%t Restruct\\Silverstripe\\SEO\\Forms\\SeoSnippetPreviewField.TitleWidth 'Title width, approximately:' %>
        <span data-seo-value="pixels">$TitlePixels</span> / $MaxPixels px
    </p>
    <p class="seo-snippet__meter" data-seo-meter="description">
        <%t Restruct\\Silverstripe\\SEO\\Forms\\SeoSnippetPreviewField.DescriptionLength 'Description length:' %>
        <span data-seo-value="length">$DescriptionLength</span>
        <%t Restruct\\Silverstripe\\SEO\\Forms\\SeoSnippetPreviewField.DescriptionRange 'characters (about {min} to {max} reads well)' min=$MinLength max=$MaxLength %>
    </p>
</div>
