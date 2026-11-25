<!doctype html>
<html lang="en">
<head>
    $MetaTags
</head>
<body>
    <%-- Outside <main>: the content checks must not see this, but the H1 count reads the whole body --%>
    <header>
        <nav><a href="$BaseHref">Home</a><h4>Menu</h4></nav>
    </header>
    <main>
        <% if not $HideH1 %><h1>$Title</h1><% end_if %>
        $Content
        <% loop $Blocks %>
            <section class="block">
                <h2>$Title</h2>
                $Content
            </section>
        <% end_loop %>
    </main>
    <footer>
        <a href="#top">Read more</a>
        <p>Lorem ipsum in the footer is outside the content and is not reported.</p>
    </footer>
</body>
</html>
