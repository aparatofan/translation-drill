# TBT Translation Drill

Polish → English sentence translation drills for live lessons. Replaces the Word documents of "translate these sentences" with a page students open on their phones, or that the teacher screen-shares.

- **WordPress** 6.x, **PHP** 8.0+, Divi theme, self-hosted
- Pure PHP + vanilla JS. No jQuery, no build tools, no CDN, everything self-hosted.

## How it works

1. The teacher writes **English** sentences — the answers — on the teacher page, and highlights the key expressions from the lesson: click a word, or drag across several, and the highlight is added on release (up to four per sentence, each in its own colour). It is the same gesture TBT Drag & Drop uses for gaps. Each highlight appears as a chip with × to remove it.
2. **Translate** sends the sentences to OpenAI in one call. The AI writes a natural Polish sentence for each, marks the Polish words that express each highlighted expression in the same colour, and drafts a one-line language note.
3. The teacher **reviews**: edits the Polish, fixes the note, and adds missing Polish highlights the same way — click or drag the Polish words, and they pair with the first English colour that has no partner yet (or the one chosen, when several are missing). A range touching an existing highlight is refused with a message. Rows whose English changed after translation are flagged.
4. **Approve and publish** gives a link and a QR code. The link never changes, even when the drill is edited later.
5. Students (logged in) see one Polish sentence at a time:
   - **Hint** (`H`) lists each highlighted Polish expression with its English gapped — `p___ your b____` — and a tap reveals it.
   - **Show answer** (`A`) shows the English with the same colours, the student's own attempt if they typed one, and the note.
   - `←` `→` move between sentences.

Nothing is scored or stored about students. The page remembers which sentence a student reached, in their browser only.

## Setup

1. Install and activate. Activation creates `{prefix}tbtd_drills` and `{prefix}tbtd_items`.
2. Create two pages:
   - **Teacher page** with `[tbt_drill_builder]`
   - **Student page** with `[tbt_drill]`
3. Go to **TBT → Translation Drill Settings** and choose both pages.
4. The OpenAI key: if **TBT Swipe** already has one, it is used automatically. Enter a key here only to use a different one. The model works the same way (own setting → Swipe's → `gpt-4o-mini`).
5. **Exclude both pages from page caching** (LiteSpeed, WP Rocket, Cloudflare). The plugin sends no-cache headers and `DONOTCACHEPAGE`, but a cache rule configured to ignore them would serve one visitor's nonce or drill to another.

The plugin lives under the **TBT** hub menu when TBT Hub is active (**TBT → Translation Drill**, **TBT → Translation Drill Settings**), and falls back to its own top-level menu otherwise.

## Shortcodes

| Shortcode | Attribute | Default | Meaning |
| --- | --- | --- | --- |
| `[tbt_drill_builder]` | `hero` | `no` | The teacher page. The TBT pages carry their own header row in Divi; `hero="yes"` brings the built-in one back on a bare page. |
| `[tbt_drill]` | — | — | The student page. The drill comes from `?drill={slug}`. |

The teacher page has two views on one URL: the library (default), and the builder (`?tbtd_new=1`, `?tbtd_edit={id}`). It renders nothing at all for a visitor without the capability.

## Who can see what

- **Teachers**: the capability `tbtd_manage`, granted to the roles ticked under **Settings → Who can build drills**. Administrators always have it. Each teacher sees and edits only their own drills on the teacher page — administrators included. wp-admin (**TBT → Translation Drill**) is where administrators see everyone's.
- **Students**: must be logged in. A logged-out visitor gets a **Log in** button that comes straight back to the drill. The filter `tbtd_user_can_play( $allowed, $drill, $user_id )` can narrow this further, e.g. to active memberships.
- **Drafts** are visible only to their owner (with a "Preview" banner) and administrators. Everyone else gets "This drill isn't available".

## Classes and lessons

If [TBT Notes](https://github.com/aparatofan/tbt_notes) is active, a drill can be attached to one of the teacher's own classes, and optionally a lesson. The library groups drills by class. Attaching never restricts the link. Everything goes through `TBT_Notes_DB`'s public methods; deactivating Notes simply removes the class picker.

## The highlight format

Sentences are stored as text with highlighted fragments wrapped as `[fragment|n]`, `n` = 1–4:

```
EN: I've got [a lot on my plate|1] at the moment.
PL: Mam teraz [dużo na głowie|1].
```

The number is the pairing key and the colour. Numbers are never renumbered — that would silently re-pair the Polish. The characters `[`, `]` and `|` cannot appear as content; typed ones become `(`, `)` and `/`. The paste box accepts this syntax, so a list prepared elsewhere can arrive pre-highlighted.

A Polish highlight can only use a number the English has. An English highlight without a Polish partner is allowed but flagged in review; its hint then shows the English only.

## AI usage limits

**TBT → Translation Drill Settings → AI usage limits**:

- **Sentences per translation** — default 30, hard maximum 60. Longer drills are sent in parts automatically.
- **Translations per teacher per day** — default 40; 0 for no limit. Translating one row again counts as one. Failed calls cost nothing.
- One teacher can be given a different daily limit with the user meta key `tbtd_max_translations_per_day`.

## Security

- The OpenAI key is stored in `wp_options` and never printed to a page or returned by any route.
- Every REST route needs `tbtd_manage` and a `wp_rest` nonce; anything touching a drill also checks ownership.
- There is **no public read route**. Drill content reaches students only inside the server-rendered student page, after the login check.
- Attaching to a class checks the teacher owns it, and a lesson must belong to that class.
- Input is sanitised on save (`TBTD_Markup`), output escaped on render, every query uses `$wpdb->prepare()`.
- Slugs are 12 random characters without look-alikes, so drills cannot be guessed before the lesson.

## File structure

```
translation-drill/
├── translation-drill.php            # bootstrap, constants, activation, hub item
├── includes/
│   ├── class-tbtd-markup.php        # highlight format, notes, slugs, AI answer parsing (no WP)
│   ├── class-tbtd-capabilities.php  # tbtd_manage, role grants
│   ├── class-tbtd-classes.php       # read-only bridge to TBT Notes
│   ├── class-tbtd-db.php            # tables, dbDelta, all queries
│   ├── class-tbtd-api.php           # OpenAI client, prompt, key/model fallback to Swipe
│   ├── class-tbtd-translator.php     # the one translation path + limits
│   ├── class-tbtd-urls.php          # student page, teacher page, every link
│   ├── class-tbtd-rest.php          # authenticated routes for the teacher page
│   ├── class-tbtd-teacher.php       # [tbt_drill_builder]: library + builder
│   ├── class-tbtd-player.php        # [tbt_drill]: login gate + game
│   ├── class-tbtd-admin.php         # wp-admin overview
│   └── class-tbtd-settings.php
├── assets/
│   ├── css/tbt-tokens.css           # shared TBT tokens (copied from Swipe)
│   ├── css/teacher.css
│   ├── css/player.css
│   ├── fonts/                       # Roboto Slab + Roboto Mono, self-hosted
│   ├── js/teacher.js
│   ├── js/player.js
│   └── js/lib/qrcode.min.js         # self-hosted QR library (MIT)
├── tests/test-markup.php
└── .github/workflows/deploy.yml
```

## Tests

```bash
php tests/test-markup.php
```

## Deployment

GitHub Actions over FTPS, the same workflow as the other TBT plugins (`.github/workflows/deploy.yml`). A push to `main` deploys; Markdown-only changes and `tests/` are not uploaded. Repository secrets: `FTP_SERVER`, `FTP_USERNAME`, `FTP_PASSWORD`, `FTP_SERVER_DIR`.

**First install:** the workflow refuses to deploy into a folder that does not already contain `translation-drill.php`. For the very first deploy, either upload the plugin once by hand, or run the workflow manually (**Actions → Deploy to WordPress → Run workflow**) with **allow_create** ticked.
