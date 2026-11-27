<%-- The body of the "About this check" dialog, sent by SeoCheckController::doc() and set as the modal's body by
     the panel script. First what the check looks at and how to fix it, for editors, in their language; then the
     check's spec, for developers, folded away and only there for admins (Spec is empty for everyone else). About
     and HowToFix are plain strings, escaped here; Spec is the escaped output of DocMarkdown. --%>
<div class="seo-checks-doc__editor">
    <% if $About %><p>$About</p><% end_if %>
    <% if $HowToFix %>
        <h3><%t Restruct\\Silverstripe\\SEO\\Control\\SeoCheckController.DocHowToFix 'How to fix' %></h3>
        <p>$HowToFix</p>
    <% end_if %>
</div>
<% if $Spec %>
    <details class="seo-checks-doc__developer">
        <summary><%t Restruct\\Silverstripe\\SEO\\Control\\SeoCheckController.DocDevelopers 'For developers' %></summary>
        $Spec
    </details>
<% end_if %>
