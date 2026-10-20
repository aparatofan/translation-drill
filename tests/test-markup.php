<?php
/**
 * Standalone logic tests for TBTD_Markup.
 *
 * The highlight markup is the contract between the teacher page, the AI, the
 * database and the student page. These are the rules a teacher would
 * otherwise have to catch by eye: highlights survive a round trip, a stray
 * bracket never breaks a sentence, a Polish highlight can only point at an
 * English one that exists, and a bad AI answer changes nothing.
 *
 * WordPress is not loaded; TBTD_Markup does not use it.
 *
 * Run: php tests/test-markup.php
 */
error_reporting( E_ALL );
define( 'ABSPATH', __DIR__ . '/' );
require __DIR__ . '/../includes/class-tbtd-markup.php';

$pass = 0;
$fail = 0;
function ok( $cond, $label ) {
	global $pass, $fail;
	if ( $cond ) {
		$pass++;
	} else {
		$fail++;
		echo "FAIL: {$label}\n";
	}
}
function same( $actual, $expected, $label ) {
	ok( $actual === $expected, $label . ( $actual === $expected ? '' : "\n  expected: " . var_export( $expected, true ) . "\n  actual:   " . var_export( $actual, true ) ) );
}

/* ---------- parse / serialize round trip ---------- */

$en = "I've got [a lot on my plate|2] [at the moment|1], but [I'd be happy to talk|3] tomorrow.";
$p  = TBTD_Markup::parse( $en );
same( $p['text'], "I've got a lot on my plate at the moment, but I'd be happy to talk tomorrow.", 'parse: plain text' );
same( count( $p['marks'] ), 3, 'parse: three marks' );
same( $p['marks'][0]['n'], 2, 'parse: marks sorted by position, numbers kept' );
same( mb_substr( $p['text'], $p['marks'][0]['start'], $p['marks'][0]['end'] - $p['marks'][0]['start'] ), 'a lot on my plate', 'parse: offsets point at the fragment' );
same( TBTD_Markup::serialize( $p['text'], $p['marks'] ), $en, 'serialize: round trip is exact' );

$pl = 'Mam [teraz|1] [dużo na głowie|2], ale [chętnie porozmawiam|3] z tobą jutro.';
$pp = TBTD_Markup::parse( $pl );
same( mb_substr( $pp['text'], $pp['marks'][1]['start'], $pp['marks'][1]['end'] - $pp['marks'][1]['start'] ), 'dużo na głowie', 'parse: multibyte offsets are character offsets' );
same( TBTD_Markup::serialize( $pp['text'], $pp['marks'] ), $pl, 'serialize: Polish round trip is exact' );

/* ---------- tolerance ---------- */

same( TBTD_Markup::sanitize( 'A [ stray bracket and a | pipe' ), 'A ( stray bracket and a / pipe', 'sanitize: stray syntax becomes readable text' );
same( TBTD_Markup::sanitize( 'Two [same|1] numbers [here|1].' ), 'Two [same|1] numbers here.', 'sanitize: a repeated number is unwrapped, words kept' );
same( TBTD_Markup::sanitize( 'Out of [range|7] and [zero|0].' ), 'Out of range and zero.', 'sanitize: numbers outside 1–4 are unwrapped' );
same( TBTD_Markup::sanitize( 'Edge [ spaces |1] here' ), 'Edge [spaces|1] here', 'sanitize: whitespace moves outside the fragment' );
same( TBTD_Markup::sanitize( "  Many   \n spaces  [here|2]  " ), 'Many spaces [here|2]', 'sanitize: whitespace collapsed and trimmed' );
same( TBTD_Markup::sanitize( '<b>Bold</b> [tag|1]' ), 'Bold [tag|1]', 'sanitize: tags stripped' );
same( TBTD_Markup::sanitize( '' ), '', 'sanitize: empty stays empty' );
same( TBTD_Markup::sanitize( '[  |1] nothing' ), 'nothing', 'sanitize: an empty fragment disappears' );

/* ---------- allowed numbers (Polish against English) ---------- */

same(
	TBTD_Markup::sanitize( 'Czy mogę cię o coś [podpytać|1]? [Extra|3] mark.', array( 1, 2 ) ),
	'Czy mogę cię o coś [podpytać|1]? Extra mark.',
	'sanitize: a Polish highlight with no English partner is unwrapped'
);
same( TBTD_Markup::numbers( $en ), array( 1, 2, 3 ), 'numbers: ascending' );
same( TBTD_Markup::missing_numbers( $en, 'Mam [teraz|1] dużo na głowie.' ), array( 2, 3 ), 'missing_numbers: unpaired English highlights' );
same( TBTD_Markup::missing_numbers( $en, $pl ), array(), 'missing_numbers: fully paired' );
same( TBTD_Markup::plain( $en ), $p['text'], 'plain: unwraps everything' );

/* ---------- length limit ---------- */

$long = str_repeat( 'word ', 100 ) . '[end|1]';
$cut  = TBTD_Markup::sanitize( $long );
ok( mb_strlen( TBTD_Markup::plain( $cut ) ) <= TBTD_Markup::MAX_TEXT, 'sanitize: text capped at MAX_TEXT' );
same( TBTD_Markup::numbers( $cut ), array(), 'sanitize: a mark beyond the cap is dropped, not cut in half' );

/* ---------- notes ---------- */

same( TBTD_Markup::sanitize_note( "  <i>Use</i> **pick someone's brain**\n  to ask advice. " ), "Use **pick someone's brain** to ask advice.", 'sanitize_note: one clean line, bold markers kept' );
same( TBTD_Markup::note_html( 'Use **liaise with** & <script>' ), 'Use <b>liaise with</b> &amp; &lt;script&gt;', 'note_html: escaped, then bold' );

/* ---------- slugs ---------- */

$slug = TBTD_Markup::generate_slug();
ok( 1 === preg_match( '/^[a-z2-9]{12}$/', $slug ), 'generate_slug: 12 lowercase characters' );
ok( 0 === preg_match( '/[01ilo]/', $slug ), 'generate_slug: no look-alike characters' );

/* ---------- AI answers ---------- */

$batch = array(
	'Can I [pick your brain|1] about something?',
	'The school psychologist [liaises with|1] teachers to [help prevent bullying|2].',
);

$good = json_encode( array( 'items' => array(
	array( 'pl' => 'Czy mogę cię o coś [podpytać|1]?', 'note' => '**pick someone\'s brain** = ask for advice.' ),
	array( 'pl' => 'Psycholog szkolny [współpracuje z|1] nauczycielami, aby [zapobiegać przemocy wśród uczniów|2].', 'note' => '**liaise with** = work with.' ),
) ), JSON_UNESCAPED_UNICODE );
$res = TBTD_Markup::parse_ai_items( $good, $batch );
ok( is_array( $res ) && 2 === count( $res ), 'parse_ai_items: clean JSON object' );
same( $res[1]['missing'], array(), 'parse_ai_items: paired highlights report nothing missing' );

$fenced = "Here you go:\n```json\n" . $good . "\n```";
ok( is_array( TBTD_Markup::parse_ai_items( $fenced, $batch ) ), 'parse_ai_items: fences and prose around the JSON are tolerated' );

$bare = json_encode( array(
	array( 'pl' => 'Czy mogę cię o coś podpytać?', 'note' => '' ),
	array( 'pl' => 'Psycholog [współpracuje|1] z nauczycielami. [Coś|4] jeszcze.', 'note' => '' ),
), JSON_UNESCAPED_UNICODE );
$res = TBTD_Markup::parse_ai_items( $bare, $batch );
ok( is_array( $res ), 'parse_ai_items: a bare array is accepted' );
same( $res[0]['missing'], array( 1 ), 'parse_ai_items: a missing Polish highlight is reported' );
same( $res[1]['pl'], 'Psycholog [współpracuje|1] z nauczycielami. Coś jeszcze.', 'parse_ai_items: an invented highlight is unwrapped' );
same( $res[1]['missing'], array( 2 ), 'parse_ai_items: the unpaired English number is reported' );

$short = json_encode( array( 'items' => array( array( 'pl' => 'Tylko jedno.', 'note' => '' ) ) ) );
same( TBTD_Markup::parse_ai_items( $short, $batch ), 'count', 'parse_ai_items: a count mismatch is a hard error' );
same( TBTD_Markup::parse_ai_items( 'not json at all', $batch ), 'parse', 'parse_ai_items: garbage is a parse error' );
same( TBTD_Markup::parse_ai_items( '{"items":{"a":1}}', $batch ), 'parse', 'parse_ai_items: an object where a list belongs is a parse error' );
$empty_pl = json_encode( array( 'items' => array( array( 'pl' => '', 'note' => '' ), array( 'pl' => 'x', 'note' => '' ) ) ) );
same( TBTD_Markup::parse_ai_items( $empty_pl, $batch ), 'parse', 'parse_ai_items: an empty Polish sentence is a parse error' );

echo "\n{$pass} passed, {$fail} failed\n";
exit( $fail ? 1 : 0 );
