<?php
/**
 * Server-side client for the OpenAI Chat Completions API.
 *
 * The API key never leaves the server: the teacher page talks to this site's
 * REST API, and only this class talks to api.openai.com.
 *
 * Key and model fall back to TBT Swipe's settings when this plugin's own are
 * empty, so a site that already runs Swipe needs no second key.
 */

defined( 'ABSPATH' ) || exit;

class TBTD_API {

	const ENDPOINT      = 'https://api.openai.com/v1/chat/completions';
	const DEFAULT_MODEL = 'gpt-4o-mini';

	/** Swipe's option names, read only as a fallback. Never written. */
	const SWIPE_KEY_OPTION   = 'tbts_api_key';
	const SWIPE_MODEL_OPTION = 'tbts_model';

	/**
	 * The key in use: this plugin's own, else Swipe's, else ''.
	 *
	 * @return string
	 */
	public static function api_key() {
		$own = (string) get_option( 'tbtd_api_key', '' );
		if ( '' !== $own ) {
			return $own;
		}
		return (string) get_option( self::SWIPE_KEY_OPTION, '' );
	}

	/**
	 * Where the key in use comes from: 'own', 'swipe' or ''.
	 *
	 * @return string
	 */
	public static function key_source() {
		if ( '' !== (string) get_option( 'tbtd_api_key', '' ) ) {
			return 'own';
		}
		return '' !== (string) get_option( self::SWIPE_KEY_OPTION, '' ) ? 'swipe' : '';
	}

	/**
	 * The model in use: this plugin's own setting, else Swipe's, else the default.
	 *
	 * @return string
	 */
	public static function model() {
		$own = trim( (string) get_option( 'tbtd_model', '' ) );
		if ( '' !== $own ) {
			return $own;
		}
		$swipe = trim( (string) get_option( self::SWIPE_MODEL_OPTION, '' ) );
		return '' !== $swipe ? $swipe : self::DEFAULT_MODEL;
	}

	/**
	 * Translate a batch of highlighted English sentences into Polish.
	 *
	 * @param string[] $en      English markup, one per sentence, already sanitised.
	 * @param string   $context The teacher's context line for the drill, or ''.
	 * @return array|WP_Error List of ['pl','note','missing'] in input order.
	 */
	public static function translate( array $en, $context = '' ) {
		$api_key = self::api_key();
		if ( '' === $api_key ) {
			return new WP_Error(
				'tbtd_no_key',
				__( 'No OpenAI API key is set. An administrator can add one under TBT → Translation Drill Settings.', 'translation-drill' )
			);
		}

		$response = wp_remote_post(
			self::ENDPOINT,
			array(
				'timeout' => 90,
				'headers' => array(
					'Authorization' => 'Bearer ' . $api_key,
					'Content-Type'  => 'application/json',
				),
				'body'    => wp_json_encode(
					array(
						'model'                 => self::model(),
						'max_completion_tokens' => 8192,
						'response_format'       => array( 'type' => 'json_object' ),
						'messages'              => array(
							array(
								'role'    => 'system',
								'content' => self::system_prompt(),
							),
							array(
								'role'    => 'user',
								'content' => self::user_prompt( $en, $context ),
							),
						),
					)
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return new WP_Error(
				'tbtd_http_error',
				sprintf(
					/* translators: %s: error message */
					__( 'Could not reach the AI service: %s', 'translation-drill' ),
					$response->get_error_message()
				)
			);
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( 200 !== $code ) {
			$detail = isset( $body['error']['message'] ) ? $body['error']['message'] : __( 'unknown error', 'translation-drill' );
			return new WP_Error(
				'tbtd_api_error',
				sprintf(
					/* translators: 1: HTTP status, 2: API error detail */
					__( 'The AI service returned an error (HTTP %1$d): %2$s', 'translation-drill' ),
					$code,
					sanitize_text_field( $detail )
				)
			);
		}

		$text   = isset( $body['choices'][0]['message']['content'] ) ? (string) $body['choices'][0]['message']['content'] : '';
		$result = TBTD_Markup::parse_ai_items( $text, $en );

		if ( 'count' === $result ) {
			return new WP_Error(
				'tbtd_count_mismatch',
				__( 'The AI returned a different number of sentences than it was sent. Nothing was changed — please try again.', 'translation-drill' )
			);
		}
		if ( ! is_array( $result ) ) {
			return new WP_Error(
				'tbtd_parse_error',
				__( 'The AI answer could not be read. Nothing was changed — please try again.', 'translation-drill' )
			);
		}

		return $result;
	}

	/**
	 * The fixed instructions. Everything that keeps the exercise usable lives
	 * here: the direction of the task, the highlight contract and the note.
	 *
	 * The one idea the model most often gets wrong is the direction. Students
	 * see the Polish and must arrive at the English, so the Polish has to be
	 * what a Polish speaker would naturally say — not a word-for-word
	 * rendering that gives the English structure away.
	 *
	 * @return string
	 */
	private static function system_prompt() {
		return "You help a Polish teacher of English build a translation exercise for adult Polish learners (around B1–B2).\n"
			. "Students see a POLISH sentence and translate it into English. The English sentence you are given is the target answer.\n\n"
			. "For each English sentence:\n"
			. "1. Write ONE natural Polish sentence with the same meaning, tense and register — what a Polish speaker would actually say. "
			. "Do not translate word for word and do not copy English structures: an idiom becomes the natural Polish equivalent "
			. "(\"I've got a lot on my plate\" → \"Mam dużo na głowie\"). Use correct Polish diacritics and punctuation. "
			. "Use the informal \"ty\" form unless the English is clearly formal.\n"
			. "2. Some English fragments are marked like [fragment|n]. In your Polish sentence, mark the Polish words that express "
			. "each fragment with the SAME number, like [polskie słowa|n]. Every number in the English must appear exactly once in the "
			. "Polish. Mark the shortest natural Polish chunk that carries that meaning. Never mark anything else, never nest or overlap "
			. "marks, and use exactly this bracket syntax. If an English sentence has no marks, the Polish has none either.\n"
			. "3. Write a short note in simple English (B1, at most 25 words) for the student about the marked expression(s): "
			. "meaning, the collocation, or the grammar pattern a Polish speaker is likely to get wrong. Wrap English expressions "
			. "in **double asterisks**. Do not repeat the whole sentence. Do not mention the Polish.\n\n"
			. "Return ONLY a JSON object, no markdown: {\"items\":[{\"pl\":\"...\",\"note\":\"...\"}]} — exactly one item per input "
			. "sentence, in the input order.";
	}

	/**
	 * The per-request part: the teacher's context and the numbered sentences.
	 *
	 * @param string[] $en      English markup.
	 * @param string   $context Context line or ''.
	 * @return string
	 */
	private static function user_prompt( array $en, $context ) {
		$lines = array();
		foreach ( array_values( $en ) as $i => $sentence ) {
			$lines[] = ( $i + 1 ) . '. ' . $sentence;
		}

		$context = trim( (string) $context );
		$intro   = '' !== $context
			? "Context from the teacher (use it to choose vocabulary and register): {$context}\n\n"
			: '';

		return $intro . 'English sentences (' . count( $lines ) . "):\n" . implode( "\n", $lines );
	}
}
