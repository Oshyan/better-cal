# Search Ranking

How event search should match and rank words, researched when a two-word search returned a dozen loose matches above the event titled with those words (2026-11-06). Nothing here is built yet.

## Today

`Search::plan()` runs `MATCH(title, description, location) AGAINST (? IN NATURAL LANGUAGE MODE)` on one combined index, plus events whose tag or linked person contains the whole query. Natural-language mode ORs the words and scores them across all three columns, so "Labor Day" matches anything with "day" anywhere, addresses included. With no results, or a query under three characters, `LIKE '%query%'` over the same columns takes over.

The web app then re-sorts each group (Upcoming, Past) by date, so ranking only decides which 50 rows come back. Tiers need the client to keep the server's order.

## What the Documentation Says

From the MySQL 8.0 manual ("Boolean Full-Text Searches", "Full-Text Restrictions", "Full-Text Stopwords", "Fine-Tuning MySQL Full-Text Search") and MariaDB's "Full-Text Index Overview" and "Full-Text Index Stopwords":

- A leading `+` makes a word required (InnoDB takes only leading operators). A quoted phrase needs the same words in the same order; punctuation between them is ignored.
- Boolean mode does not sort by relevance, and InnoDB has no 50% threshold there. Its score is a TF-IDF variant based on BM25.
- For InnoDB the MATCH column list must equal some FULLTEXT index's columns. Per-field matching needs one index per field.
- Words under `innodb_ft_min_token_size` (3 by default) and stopwords aren't indexed; a phrase of only such words returns nothing. A word with the `*` operator is never stripped.
- The InnoDB stopword list is 35 short words (the, of, in, when, what, who and so on; "and" is not one). "day" is indexed.
- Token size and stopwords are server settings needing a restart and rebuild. Shared hosts won't change them, so the code reads `@@innodb_ft_min_token_size` instead of assuming 3.

All of this exists since MySQL 5.6 and MariaDB 10.0, so MySQL 8 and MariaDB 10.6+ are safe.

## Usual Practice

Lucene, SQLite FTS5 and Meilisearch AND multiple words by default, boost phrase and exact matches, and weight fields (BM25F, or FTS5's per-column `bm25()` weights). Most weight rather than tier, so a strong description match can beat a weak title one. With short titles and one person's events, strict tiers are more predictable and lose little; BM25's length normalization matters mostly for long documents.

## The Proposal, Mapped

- (a) Phrase first, (b) AND not OR, (e) address out: all standard practice.
- (c) All words in one field: stricter than usual; engines accept spread matches and rank them lower. Start strict and add a bottom tier only if real searches miss.
- (d) Title always above description: stricter than weighting but fits short titles. The likely exception is tags and people, which could rank with the title.

## Recommendation

Build the tiers as our own ranking on boolean-mode full text: one database, no dependency, any LAMP host. The alternatives cost more: a PHP scorer loads candidates and re-implements tokenizing; SQLite FTS5 is a second copy of every event to keep in step with undo and sync, and `pdo_sqlite` isn't guaranteed on shared hosts; Meilisearch or Typesense can't run on shared hosting; libraries like TNTSearch keep their own index and fail the dependency bar.

## Query Shape

PHP splits the query on anything not a letter or digit, so typed operators never reach MATCH. For "labor day", `:all` is `+labor +day*` and `:phrase` is `"labor day"`:

```sql
SELECT ...,
  CASE
    WHEN title = :q THEN 0
    WHEN MATCH(title) AGAINST(:phrase IN BOOLEAN MODE) THEN 1
    WHEN MATCH(title) AGAINST(:all IN BOOLEAN MODE) THEN 2
    WHEN id IN (:tagOrPersonIds) THEN 3
    WHEN MATCH(description) AGAINST(:phrase IN BOOLEAN MODE) THEN 4
    ELSE 5
  END AS tier
FROM events
WHERE <owner, deleted, when, calendar>
  AND (MATCH(title) AGAINST(:all IN BOOLEAN MODE)
    OR MATCH(description) AGAINST(:all IN BOOLEAN MODE)
    OR id IN (:tagOrPersonIds))
ORDER BY tier, MATCH(title) AGAINST(:all IN BOOLEAN MODE) DESC, start_utc DESC
```

The `*` on the last word serves search as you type ("labor da"). Tag and person matching stays as is.

Indexes: a migration adds `FULLTEXT(title)` and `FULLTEXT(description)`, one per ALTER, and drops `ft_events`. At tens of thousands of rows that takes seconds, and queries stay in the low milliseconds even if the OR makes MySQL scan the owner's rows; a UNION per field is the fix if measured slow.

## Edge Cases

- One word: tiers 1 and 2 coincide.
- Short words or stopwords ("5k", "the") leave `:all` and are required with `LIKE` on the same field.
- Only such words ("the who"): per-field AND of `LIKE` per word, title first.
- Apostrophes and punctuation: split in PHP; phrases ignore punctuation. How InnoDB tokenizes "o'brien" needs a database-suite check.
- `#` and `@`: unchanged.
- The LIKE fallback becomes per-field AND of words, so it never brings back OR or the address.

## Open Questions

- Show the best tiers first, or keep date order inside Upcoming and Past?
- Tags and people with the title (as sketched) or below descriptions?
- A spread-across-fields tier, or none?
- Does `+stopword` drop silently or empty the result? The pages don't say; the database suite should pin it.
