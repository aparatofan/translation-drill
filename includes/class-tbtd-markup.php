<?php
/**
 * The highlight markup, and everything else that is pure string logic.
 *
 * A sentence is stored as plain text with its highlighted fragments wrapped
 * like this:
 *
 *     I've got [a lot on my plate|1] at the moment.
 *
 * The number (1–4) is the fragment's colour and its pairing key: the Polish
 * fragment that translates "a lot on my plate" carries the same number, and
 * the player paints both in the same colour. Numbers are identities, not
 * positions — they are never renumbered, because renumbering the English
 * after a new highlight would silently re-pair the Polish.
 *
 * Nothing in this class touches WordPress, so tests/ can exercise it with no
 * WordPress loaded. The JS twin of parse()/serialize() lives in
 * assets/js/teacher.js and assets/js/player.js; the format is the contract.
 */

defined( 'ABSPATH' ) || exit;

class TBTD_Markup {

	/** Highlights allowed per sentence. The player has one colour for each. */
	const MAX_MARKS = 4;

	/** Longest sentence accepted, in characters, before markup. */
	const MAX_TEXT = 400;

	/** Longest language note accepted, in characters. */
	const MAX_NOTE = 400;

	/** Matches one well-formed highlight: [text|n]. */
	const MARK_RE = '/\[([^\[\]|]+)\|(\d+)\]/u';

	/**
	 * Clean free text a teacher typed or a model returned.
	 *
	 * The three markup characters cannot appear as content — there is no
	 * escape syntax, deliberately, because a teacher should never have to know
	 * one. Square brackets become round ones and a pipe becomes a slash, which
	 * keeps the sentence readable rather than deleting characters from it.
	 *
	 * @param string $text Raw text.
	 * @return string
	 */
	public static function clean_text( $text ) {
		$text = strip_tags( (string) $text );
		$text = str_replace( array( '[', ']', '|' ), array( '(', ')', '/' ), $text );
		$text = preg_replace( '/\s+/u', ' ', $text );
		return trim( (string) $text );
	}

	/**
	 * Split markup into plain text plus highlight ranges.
	 *
	 * Tolerant by design: it reads whatever a model or an old client sent and
	 * returns something valid. A number outside 1–MAX_MARKS, or a number
	 * already used earlier in the sentence, is unwrapped to plain text rather
	 * than rejected — the words stay, only the highlight goes.
	 *
	 * Ranges are character offsets (mb_*) into the returned text, end
	 * exclusive. Whitespace at the edge of a fragment is moved outside it.
	 *
	 * @param string $markup Stored or submitted markup.
	 * @return array{text:string, marks:array<int,array{n:int,start:int,end:int}>}
	 */
	public static function parse( $markup ) {
		$markup = strip_tags( (string) $markup );
		$markup = preg_replace( '/\s+/u', ' ', $markup );

		$text  = '';
		$marks = array();
		$used  = array();
		$pos   = 0;

		if ( preg_match_all( self::MARK_RE, $markup, $m, PREG_OFFSET_CAPTURE | PREG_SET_ORDER ) ) {
			foreach ( $m as $match ) {
				$whole_off = $match[0][1];
				$before    = substr( $markup, $pos, $whole_off - $pos );
				$text     .= self::strip_syntax( $before );

				$inner = self::strip_syntax( $match[1][0] );
				$n     = (int) $match[2][0];

				// Edge whitespace belongs to the sentence, not the fragment.
				$lead  = strlen( $inner ) - strlen( ltrim( $inner ) );
				$core  = trim( $inner );
				$trail = strlen( $inner ) - $lead - strlen( $core );
				$text .= substr( $inner, 0, $lead );

				if ( '' !== $core && $n >= 1 && $n <= self::MAX_MARKS && ! isset( $used[ $n ] ) ) {
					$start   = mb_strlen( $text );
					$text   .= $core;
					$marks[] = array(
						'n'     => $n,
						'start' => $start,
						'end'   => mb_strlen( $text ),
					);
					$used[ $n ] = true;
				} else {
					$text .= $core;
				}
				$text .= substr( $inner, strlen( $inner ) - $trail );

				$pos = $whole_off + strlen( $match[0][0] );
			}
		}
		$text .= self::strip_syntax( substr( $markup, $pos ) );

		// Moving edge spaces out of fragments can leave two spaces side by
		// side ("Edge [ x |1] here" → "Edge  x  here"). Collapse them again,
		// carrying every mark offset through the same mapping.
		$chars = mb_str_split( $text );
		$map   = array();
		$out   = '';
		$j     = 0;
		$prev  = false;
		foreach ( $chars as $i => $c ) {
			$map[ $i ] = $j;
			$is_space  = ( ' ' === $c );
			if ( $is_space && $prev ) {
				continue;
			}
			$out .= $c;
			++$j;
			$prev = $is_space;
		}
		$map[ count( $chars ) ] = $j;
		foreach ( $marks as &$mark ) {
			$mark['start'] = $map[ $mark['start'] ];
			$mark['end']   = $map[ $mark['end'] ];
		}
		unset( $mark );
		$text = $out;

		// Only the very ends can still carry whitespace. Trim and shift.
		$lead = mb_strlen( $text ) - mb_strlen( ltrim( $text ) );
		$text = trim( $text );
		if ( $lead > 0 ) {
			foreach ( $marks as &$mark ) {
				$mark['start'] -= $lead;
				$mark['end']   -= $lead;
			}
			unset( $mark );
		}

		usort( $marks, function ( $a, $b ) {
			return $a['start'] <=> $b['start'];
		} );

		return array(
			'text'  => $text,
			'marks' => $marks,
		);
	}

	/**
	 * Join plain text and ranges back into markup.
	 *
	 * Overlapping or out-of-range marks are dropped, first one wins, so the
	 * output is always well formed whatever the input claimed.
	 *
	 * @param string $text  Plain text, already free of markup characters.
	 * @param array  $marks Ranges as returned by parse().
	 * @return string
	 */
	public static function serialize( $text, array $marks ) {
		$text = (string) $text;
		$len  = mb_strlen( $text );

		usort( $marks, function ( $a, $b ) {
			return (int) $a['start'] <=> (int) $b['start'];
		} );

		$out  = '';
		$pos  = 0;
		$used = array();
		foreach ( $marks as $mark ) {
			$n     = (int) $mark['n'];
			$start = (int) $mark['start'];
			$end   = (int) $mark['end'];
			if ( $start < $pos || $end <= $start || $end > $len || $n < 1 || $n > self::MAX_MARKS || isset( $used[ $n ] ) ) {
				continue;
			}
			$inner = mb_substr( $text, $start, $end - $start );
			if ( '' === trim( $inner ) ) {
				continue;
			}
			$out       .= mb_substr( $text, $pos, $start - $pos );
			$out       .= '[' . $inner . '|' . $n . ']';
			$pos        = $end;
			$used[ $n ] = true;
		}
		$out .= mb_substr( $text, $pos );

		return $out;
	}

	/**
	 * Normalise submitted markup into its canonical stored form.
	 *
	 * @param string     $markup  Raw markup.
	 * @param int[]|null $allowed When given, only these numbers may stay
	 *                            highlighted. The Polish side passes the
	 *                            English numbers, so a Polish fragment can
	 *                            never point at an English one that is not there.
	 * @return string
	 */
	public static function sanitize( $markup, $allowed = null ) {
		$parsed = self::parse( $markup );
		$text   = $parsed['text'];
		$marks  = $parsed['marks'];

		if ( mb_strlen( $text ) > self::MAX_TEXT ) {
			$text  = rtrim( mb_substr( $text, 0, self::MAX_TEXT ) );
			$limit = mb_strlen( $text );
			$marks = array_values( array_filter( $marks, function ( $mark ) use ( $limit ) {
				return $mark['end'] <= $limit;
			} ) );
		}

		if ( is_array( $allowed ) ) {
			$allowed = array_map( 'intval', $allowed );
			$marks   = array_values( array_filter( $marks, function ( $mark ) use ( $allowed ) {
				return in_array( (int) $mark['n'], $allowed, true );
			} ) );
		}

		return self::serialize( $text, $marks );
	}

	/**
	 * The highlight numbers present in a sentence, ascending.
	 *
	 * @param string $markup Markup.
	 * @return int[]
	 */
	public static function numbers( $markup ) {
		$numbers = array_map( function ( $mark ) {
			return (int) $mark['n'];
		}, self::parse( $markup )['marks'] );
		sort( $numbers );
		return $numbers;
	}

	/**
	 * The sentence with every highlight unwrapped.
	 *
	 * @param string $markup Markup.
	 * @return string
	 */
	public static function plain( $markup ) {
		return self::parse( $markup )['text'];
	}

	/**
	 * English highlights that have no Polish partner.
	 *
	 * Advisory: a sentence with a missing partner still plays, the hint for
	 * that colour simply has no Polish chunk to point at. The teacher sees the
	 * gap in review and decides.
	 *
	 * @param string $en English markup.
	 * @param string $pl Polish markup.
	 * @return int[]
	 */
	public static function missing_numbers( $en, $pl ) {
		return array_values( array_diff( self::numbers( $en ), self::numbers( $pl ) ) );
	}

	/**
	 * Clean a language note. One line, plain text, **bold** kept as typed.
	 *
	 * @param string $note Raw note.
	 * @return string
	 */
	public static function sanitize_note( $note ) {
		$note = strip_tags( (string) $note );
		$note = preg_replace( '/\s+/u', ' ', $note );
		$note = trim( (string) $note );
		if ( mb_strlen( $note ) > self::MAX_NOTE ) {
			$note = rtrim( mb_substr( $note, 0, self::MAX_NOTE ) );
		}
		return $note;
	}

	/**
	 * Render a note for display: escaped, with **bold** as <b>.
	 *
	 * @param string $note Stored note.
	 * @return string Safe HTML.
	 */
	public static function note_html( $note ) {
		$safe = htmlspecialchars( (string) $note, ENT_QUOTES, 'UTF-8' );
		return (string) preg_replace( '/\*\*(.+?)\*\*/u', '<b>$1</b>', $safe );
	}

	/**
	 * A fresh 12-character unguessable slug.
	 *
	 * Lowercase letters and digits with the look-alikes (0/o, 1/l/i) removed,
	 * so a teacher reading a link aloud cannot be misheard.
	 *
	 * @return string
	 */
	public static function generate_slug() {
		$alphabet = 'abcdefghjkmnpqrstuvwxyz23456789';
		$max      = strlen( $alphabet ) - 1;
		$slug     = '';
		for ( $i = 0; $i < 12; $i++ ) {
			$slug .= $alphabet[ random_int( 0, $max ) ];
		}
		return $slug;
	}

	/**
	 * Read the model's answer for a batch of sentences.
	 *
	 * The contract is a JSON object {"items":[{"pl":"…","note":"…"}, …]} in
	 * input order. A bare array is accepted too, and stray prose or markdown
	 * fences around the JSON are stripped. A count mismatch is a hard error,
	 * never a partial result: half a translated drill is worse than none.
	 *
	 * Each Polish sentence is sanitised against its own English numbers here,
	 * so a highlight the model invented never reaches the teacher.
	 *
	 * @param string   $text Raw completion text.
	 * @param string[] $en   English markup, one per item, in request order.
	 * @return array|string List of ['pl','note','missing'] or an error code:
	 *                      'parse' or 'count'.
	 */
	public static function parse_ai_items( $text, array $en ) {
		$text = trim( (string) $text );
		$text = preg_replace( '/^```(?:json)?\s*/i', '', $text );
		$text = preg_replace( '/\s*```$/', '', (string) $text );

		$data = json_decode( (string) $text, true );
		if ( ! is_array( $data ) ) {
			// Be tolerant of prose around the JSON: take the outermost object.
			$start = strpos( (string) $text, '{' );
			$end   = strrpos( (string) $text, '}' );
			if ( false !== $start && false !== $end && $end > $start ) {
				$data = json_decode( substr( (string) $text, $start, $end - $start + 1 ), true );
			}
		}
		if ( ! is_array( $data ) ) {
			return 'parse';
		}

		$items = isset( $data['items'] ) && is_array( $data['items'] ) ? $data['items'] : $data;
		if ( array() !== $items && array_keys( $items ) !== range( 0, count( $items ) - 1 ) ) {
			return 'parse';
		}

		$en = array_values( $en );
		if ( count( $items ) !== count( $en ) ) {
			return 'count';
		}

		$out = array();
		foreach ( $items as $i => $item ) {
			if ( ! is_array( $item ) ) {
				return 'parse';
			}
			$allowed = self::numbers( $en[ $i ] );
			$pl      = self::sanitize( (string) ( $item['pl'] ?? '' ), $allowed );
			if ( '' === self::plain( $pl ) ) {
				return 'parse';
			}
			$out[] = array(
				'pl'      => $pl,
				'note'    => self::sanitize_note( (string) ( $item['note'] ?? '' ) ),
				'missing' => self::missing_numbers( $en[ $i ], $pl ),
			);
		}

		return $out;
	}

	/**
	 * Strip markup characters left outside a well-formed highlight.
	 *
	 * @param string $s Fragment.
	 * @return string
	 */
	private static function strip_syntax( $s ) {
		return str_replace( array( '[', ']', '|' ), array( '(', ')', '/' ), (string) $s );
	}
}
