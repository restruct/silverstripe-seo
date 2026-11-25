# Dropped checks

Checks that other SEO tools run and this module deliberately does not, with the reason. When a check is retired
in a yearly review, it moves here with its reason and date.

Grounded in Google Search Central and in a review of the checks of Yoast, SEOPress, Rank Math, AIOSEO,
Squirrly and Slim SEO (2026-11-25).

| Check | Why it is not here | Since |
| --- | --- | --- |
| Focus keyword (in title, description, H1, URL; used on another page) | Dropped at design time (check 22). A single keyphrase per page is an editorial device, not something search engines use; most "SEO scores" are made of keyphrase checks. Content-quality advice belongs to the optional AI suggestions. | 2026-11-25 |
| Keyword density target | Google has no density target. The keyword-stuffing warning that would have come with the focus keyword went with it; repeated words in alt text are still flagged by `image-alt-quality`. | 2026-11-25 |
| Word-count target | Google: length alone does not matter. `thin-content` only says, as information, that a page has very little text. | 2026-11-25 |
| Keyword in alt text | Yoast removed it in October 2026 (PR #23689): alt text describes the image, not a keyword. | 2026-11-25 |
| Meta keywords | Ignored by Google for well over a decade. The module outputs none. | 2026-11-25 |
| Keywords in the URL as a score | Not a ranking signal worth scoring; `url-segment` only looks at readability (underscores, length, small words). | 2026-11-25 |
| Heading quotas (a minimum number of H2s, keyphrase in subheadings) | No such guidance from Google. `heading-structure` only checks the outline, for accessibility. | 2026-11-25 |
| Flesch reading ease | Yoast removed it in 2022; a poor fit for Dutch. Readability advice belongs to the optional AI suggestions. | 2026-11-25 |
| Title "power words", sentiment, numbers in the title | Click-bait heuristics without a basis in Google's guidance. | 2026-11-25 |
| "Freshness" date bumps | Changing a date without changing the content is not a signal Google rewards. | 2026-11-25 |
| FAQ / HowTo rich-result nudges | FAQ rich results were deprecated (2026-05) and HowTo restricted (2023); several more types went in 2025-26. | 2026-11-25 |
| llms.txt and other AI text files as an SEO lever | Google: not needed for its AI features. (restruct/silverstripe-ai serves llms.txt for other reasons.) | 2026-11-25 |
| A 0-100 score per page | Not a check, but dropped too: a score invites chasing numbers and keeps outdated checks weighted. The panel shows counts per severity instead. | 2026-11-25 |

Sources: developers.google.com/search/docs (seo-starter-guide, creating-helpful-content, title-link, snippet,
google-images, ai-features), the Search Central blog (2023-08 HowTo and FAQ changes, 2025-06 simplifying search
results, 2025-11 update on our efforts), Yoast `packages/yoastseo` and PR #23689.

Last reviewed: 2026-11-25
