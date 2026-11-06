# Search Ranking

How event search should match and rank words, researched when a two-word search returned a dozen loose matches above the event titled with those words (2026-11-06). It is built; what was built, and how it differs from the sketch below, is under [Built](#built). The sections before it are the research as it stood.

## Before

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

## Built

The owner's answers to the open questions: best tier first inside Upcoming and Past, then date (soonest upcoming, newest past); tags and people rank with the title; no spread-across-fields tier; no OR anywhere; the address is not searched.

**Words.** `Search::words()` takes runs of letters and digits, lowercased, each once, at most eight. Anything else separates words, so `+`, `-`, `*`, quotes and brackets never reach MATCH. `Search::phrase()` is the same words in order with an apostrophe inside a word kept, since that is how the index read the stored text.

**Tiers.** Each field gets three conditions (`Search::fieldMatch()`), and `Search::rank()` turns them into one CASE:

| Tier | Title (1 to 3), description (4 to 6) | Tag or person name |
|---|---|---|
| 1 / 4 | `MATCH(f) AGAINST('"labor day"')` or `f LIKE '%labor day%'`, and the tier 2 condition | the words in order |
| 2 / 5 | `MATCH(f) AGAINST('+labor +day')` | every word whole |
| 3 / 6 | `MATCH(f) AGAINST('+labor* +day*')` | every word starts a word |

All MATCH calls are `IN BOOLEAN MODE`. The LIKE half of tier 1 also catches a phrase whose last word is still being typed ("labor da"). One word has no phrase tier. A word the index doesn't hold (shorter than `@@innodb_ft_min_token_size`, read from the server, or one of InnoDB's stopwords when `@@innodb_ft_enable_stopword` is on) leaves MATCH and is required with `f LIKE '%word%'` on the same field, in every tier. When no word is indexable ("the who", "5k"), every field condition is LIKE alone: each word in the field, and the words together for the phrase tier. So the fallback keeps the tiers, AND and one field, over title, description, tags and people.

```sql
SELECT ..., CASE
    WHEN <title phrase> THEN 1  WHEN id IN (<label tier 1>) THEN 1
    WHEN <title whole>  THEN 2  WHEN id IN (<label tier 2>) THEN 2
    WHEN <title prefix> THEN 3  WHEN id IN (<label tier 3>) THEN 3
    WHEN <description phrase> THEN 4
    WHEN <description whole>  THEN 5
    WHEN <description prefix> THEN 6
  END AS search_tier
FROM events
WHERE <owner, deleted, when, calendar>
  AND (<title prefix> OR <description prefix> OR id IN (<labels>))
ORDER BY search_tier, start_utc  -- ascending for Upcoming, else descending
LIMIT ?
```

The limit keeps the best tiers. PHP then puts upcoming before past and, within a tier, orders by the date shown (a running series' next date is only known there). The web app keeps that order inside each group. InnoDB's relevance score is not used: within a tier the owner chose date order, and a score would decide almost every pair before the date did.

**Tags and people.** `Labels::eventTiersForWords()` reads the account's tag and people names and matches them in PHP with `Search::nameTier()`, the same three kinds as the title. `#` searches tag names only and `@` people only, by the same rule. `GET /events?q=` keeps its own substring match.

**Indexes.** Migration 065 adds `FULLTEXT(title)` and `FULLTEXT(description)` and drops `ft_events`, which nothing else used. Building both over 20,000 events took about a quarter second in the database suite; InnoDB holds writes to the table while it builds.

**Published search feeds** match the way the search does: every word in one field, the address not searched. A feed saved before this keeps its query, and may now hold fewer events.

**Two behaviours pinned** in `server/tests/db/search.test.php`, on MariaDB 10.11:

- A required stopword empties the result: `+the +tribute` finds nothing where `+tribute` finds "The Who Tribute Night". Hence stopwords never go to MATCH; they are required by LIKE.
- An apostrophe splits a word in the stored text and in the query alike. "O'Brien" is stored as "o" (under the minimum length, dropped) and "brien", so "brien", "o'brien" and the phrase "pat o'brien" find it and "obrien" does not. Search splits words the same way, so "o'brien" is the indexed word "brien" plus "o" by LIKE. A phrase with the apostrophe kept matches; the same phrase with the apostrophe as a space ("pat o brien") did not, which is why the phrase keeps it.

The suite also checks that the built-in stopword list matches the server's.

**Time.** Over 20,000 invented events with about a thousand distinct words, a whole `search()` call (labels, query, the PHP after) took about 20 ms for a two-word query, 18 ms for one word, 34 ms for a stopword-only LIKE query and 46 ms when every row matched, measured on a database server shared with another run.
