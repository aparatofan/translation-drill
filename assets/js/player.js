/**
 * TBT Translation Drill — student page.
 *
 * One Polish sentence at a time, in three steps that follow how students
 * actually work: they say the sentence out loud, use the phrases, then check.
 *   1. Polish sentence — highlighted expressions carry the colour of their
 *      English partner.
 *   2. Translate phrases — always open: each Polish expression with its
 *      English gapped (first letters only); a tap reveals that expression.
 *      Hidden when there are no marks, and the model answer becomes step 2.
 *   3. Model answer — the full English in the same colours, the teacher's
 *      note and, when TBT Flashcards is set up, Listen.
 *
 * Listen borrows Flashcards' ElevenLabs proxy (admin-ajax, same request as
 * Flashcards makes). Each sentence is fetched once per page load and kept as
 * a blob URL; on any failure the browser's own speech takes over, so the
 * button never does nothing.
 *
 * Nothing is scored. When TBT Notes is present, one completion per page load
 * is reported to its Class Progress panel, with a heartbeat while the student
 * works. The last sentence reached is remembered per drill in this browser.
 */
( function () {
	'use strict';

	var cfg = window.tbtdPlayer || {};
	var t = cfg.i18n || {};

	/* Reporting to TBT Notes, as in Drag & Drop. The endpoint and nonce are
	   localised only when Notes is active and the viewer is logged in; the
	   per-drill `activity` block is printed only for a student on a published
	   drill. Missing either one, nothing is sent. */
	var PRESENCE_EVERY = 20000;
	var presenceTimer = null;
	var activity = null;

	function postActivity( path, body ) {
		if ( ! cfg.activityBase || ! cfg.activityNonce || ! activity ) {
			return;
		}

		// A side effect of practising, never a gate on it: failures are swallowed.
		fetch( cfg.activityBase + path, {
			method: 'POST',
			credentials: 'same-origin',
			cache: 'no-store',
			headers: {
				'Content-Type': 'application/json',
				'X-WP-Nonce': cfg.activityNonce
			},
			body: JSON.stringify( body || {} ),
			// A student who closes the tab right after finishing is still recorded.
			keepalive: true
		} ).catch( function () {} );
	}

	function startPresence() {
		if ( presenceTimer || ! cfg.activityBase || ! activity ) {
			return;
		}
		postActivity( '/presence', {} );
		presenceTimer = window.setInterval( function () {
			postActivity( '/presence', {} );
		}, PRESENCE_EVERY );
	}

	function stopPresence() {
		if ( presenceTimer ) {
			window.clearInterval( presenceTimer );
			presenceTimer = null;
		}
	}

	function fmt( str ) {
		var args = Array.prototype.slice.call( arguments, 1 );
		var i = 0;
		return String( str || '' ).replace( /%(?:(\d+)\$)?[ds]/g, function ( _, pos ) {
			var v = pos ? args[ parseInt( pos, 10 ) - 1 ] : args[ i++ ];
			return v === undefined ? '' : String( v );
		} );
	}

	function esc( s ) {
		return String( s ).replace( /[&<>"]/g, function ( c ) {
			return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[ c ];
		} );
	}

	var MARK = /\[([^\[\]|]+)\|(\d)\]/g;

	function marked( s ) {
		return esc( s ).replace( MARK, function ( _, text, n ) {
			return '<mark class="tbtd-hl tbtd-hl' + n + '">' + text + '</mark>';
		} );
	}

	function chunks( s ) {
		var out = {};
		String( s ).replace( MARK, function ( _, text, n ) {
			out[ n ] = text;
			return _;
		} );
		return out;
	}

	/** "pick your brain" → "p___ your b____": first letter, length kept, short words shown. */
	function gap( s ) {
		return String( s ).replace( /[A-Za-z’']+/g, function ( w ) {
			if ( w.replace( /['’]/g, '' ).length <= 2 ) {
				return w;
			}
			var first = true;
			return w.replace( /[A-Za-z]/g, function ( c ) {
				if ( first ) {
					first = false;
					return c;
				}
				return '_';
			} );
		} );
	}

	/* ---------- Audio ---------- */

	var ICONS = {
		idle: '<svg aria-hidden="true" viewBox="0 0 16 16"><path d="M4 2.5v11l9-5.5z" fill="currentColor"/></svg>',
		playing: '<svg aria-hidden="true" viewBox="0 0 16 16"><rect x="3.5" y="3.5" width="9" height="9" rx="1.5" fill="currentColor"/></svg>',
		loading: '<svg aria-hidden="true" viewBox="0 0 16 16"><circle cx="8" cy="8" r="6" fill="none" stroke="currentColor" stroke-width="2" stroke-dasharray="26 12" stroke-linecap="round"/></svg>'
	};

	// Text → blob URL, for the page load. One shared Audio element.
	var audioUrls = new Map();
	var audioEl = null;

	function fetchAudio( text ) {
		var body = new URLSearchParams();
		body.append( 'action', cfg.ttsAction );
		body.append( 'nonce', cfg.ttsNonce );
		body.append( 'text', text );
		return fetch( cfg.ttsUrl, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
			body: body.toString()
		} ).then( function ( res ) {
			var type = res.headers.get( 'Content-Type' ) || '';
			if ( ! res.ok || type.indexOf( 'audio/' ) !== 0 ) {
				throw new Error( 'TTS proxy returned ' + res.status + ' ' + type );
			}
			return res.blob();
		} ).then( function ( blob ) {
			var url = URL.createObjectURL( blob );
			audioUrls.set( text, url );
			return url;
		} );
	}

	/** Web Speech fallback, as in TBT Flashcards. Returns false when unavailable. */
	function speak( text, done ) {
		if ( typeof speechSynthesis === 'undefined' || typeof SpeechSynthesisUtterance === 'undefined' ) {
			return false;
		}
		try {
			var u = new SpeechSynthesisUtterance( text );
			u.lang = 'en-GB';
			u.rate = 0.9;
			var voices = speechSynthesis.getVoices();
			var voice = null;
			for ( var a = 0; a < voices.length && ! voice; a++ ) {
				if ( voices[ a ].lang === 'en-GB' ) { voice = voices[ a ]; }
			}
			for ( var b = 0; b < voices.length && ! voice; b++ ) {
				if ( voices[ b ].lang.indexOf( 'en' ) === 0 ) { voice = voices[ b ]; }
			}
			if ( voice ) {
				u.voice = voice;
			}
			u.onend = done;
			u.onerror = done;
			speechSynthesis.cancel();
			speechSynthesis.speak( u );
			return true;
		} catch ( err ) {
			console.warn( 'TBT Translation Drill speech fallback:', err );
			return false;
		}
	}

	/** A phrase's English: in full once tapped, otherwise gapped with the tap prompt. */
	function phraseEn( phrase, open ) {
		if ( open ) {
			return esc( phrase );
		}
		var label = fmt( t.startsWith, phrase.charAt( 0 ), phrase.replace( /\s+/g, '' ).length );
		return '<span class="tbtd-p-gap" aria-label="' + esc( label ) + '">' + esc( gap( phrase ) ) + '</span>'
			+ '<span class="tbtd-p-tap">' + esc( t.tapReveal ) + '</span>';
	}

	function start( root ) {
		var data;
		try {
			data = JSON.parse( root.querySelector( '[data-role="drill-data"]' ).textContent );
		} catch ( e ) {
			return;
		}
		if ( ! data || ! data.items || ! data.items.length ) {
			return;
		}

		var $ = function ( role ) { return root.querySelector( '[data-role="' + role + '"]' ); };
		var items = data.items;
		var storeKey = 'tbtd-pos-' + data.slug;
		var state = items.map( function () {
			return { answer: false, open: {} };
		} );
		var i = 0;
		var listenBtn = $( 'listen' );
		var hasAudio = !! ( cfg.ttsUrl && cfg.ttsAction && cfg.ttsNonce && window.fetch && window.URL && URL.createObjectURL );
		var listenSeq = 0;

		activity = data.activity || null;
		// startedAt is set on the first student action, not on page load, so a
		// student who only opens the link is not shown as working. completionSent
		// holds for the page load: going round again does not report twice.
		var startedAt = null;
		var completionSent = false;

		function noteInteraction() {
			if ( completionSent ) {
				return;
			}
			if ( ! startedAt ) {
				startedAt = Date.now();
			}
			startPresence();
		}

		function reportCompletion() {
			if ( completionSent || ! activity ) {
				return;
			}
			completionSent = true;
			stopPresence();
			postActivity( '', {
				tool: 'drill',
				object_ref: activity.objectRef,
				object_title: activity.objectTitle,
				duration_seconds: startedAt ? Math.max( 0, Math.round( ( Date.now() - startedAt ) / 1000 ) ) : null
			} );
		}

		try {
			var saved = parseInt( window.localStorage.getItem( storeKey ), 10 );
			if ( saved >= 0 && saved < items.length ) {
				i = saved;
			}
		} catch ( e ) {}

		var track = $( 'track' );
		track.innerHTML = items.map( function ( _, k ) {
			return '<button type="button" data-k="' + k + '" aria-label="' + esc( fmt( t.goTo, k + 1 ) ) + '">' + ( k + 1 ) + '</button>';
		} ).join( '' );
		track.addEventListener( 'click', function ( e ) {
			var b = e.target.closest( 'button' );
			if ( b ) {
				noteInteraction();
				go( parseInt( b.getAttribute( 'data-k' ), 10 ) );
			}
		} );

		function restart( el ) {
			el.classList.remove( 'is-in' );
			void el.offsetWidth;
			el.classList.add( 'is-in' );
		}

		function render( animate ) {
			var item = items[ i ];
			var st = state[ i ];

			$( 'count' ).textContent = fmt( t.count, i + 1, items.length );
			Array.prototype.forEach.call( track.children, function ( b, k ) {
				b.setAttribute( 'aria-current', k === i ? 'true' : 'false' );
				b.classList.toggle( 'is-done', state[ k ].answer && k !== i );
			} );

			$( 'pl' ).innerHTML = marked( item.pl );

			// Phrases: every English highlight, in colour order, with its Polish
			// partner where there is one. The English is gapped until tapped.
			var pl = chunks( item.pl );
			var en = chunks( item.en );
			var keys = Object.keys( en ).sort();
			$( 'phrases' ).innerHTML = keys.map( function ( n ) {
				var open = !! st.open[ n ];
				return '<li><button type="button" class="tbtd-p-phrase' + ( open ? ' is-open' : '' ) + '" data-n="' + n + '" aria-expanded="' + open + '">'
					+ '<span class="tbtd-p-phrase-pl" lang="pl"><span class="tbtd-p-dot tbtd-p-dot' + n + '"></span>' + ( pl[ n ] ? esc( pl[ n ] ) : '' ) + '</span>'
					+ '<span class="tbtd-p-arrow" aria-hidden="true">→</span>'
					+ '<span class="tbtd-p-phrase-en tbtd-p-en' + n + '" lang="en">' + phraseEn( en[ n ], open ) + '</span>'
					+ '</button></li>';
			} ).join( '' );
			$( 'step-phrases' ).hidden = ! keys.length;
			$( 'answer-num' ).textContent = keys.length ? '3' : '2';

			$( 'en' ).innerHTML = marked( item.en );
			var note = $( 'note' );
			note.innerHTML = item.note || '';
			note.hidden = ! item.note;
			$( 'step-answer' ).classList.toggle( 'tbtd-p-step--waiting', ! st.answer );
			$( 'answer-btn' ).hidden = st.answer;
			$( 'answer-panel' ).hidden = ! st.answer;
			listenBtn.hidden = ! hasAudio;

			if ( animate === 'answer' && st.answer ) {
				restart( $( 'answer-panel' ) );
			}
			if ( animate === 'card' ) {
				restart( $( 'card' ) );
			}

			$( 'prev' ).disabled = i === 0;
			$( 'next' ).textContent = i === items.length - 1 ? t.restart : t.next;
		}

		function go( k ) {
			if ( k < 0 ) {
				return;
			}
			// Next (or →) on the last sentence finishes the drill.
			if ( i === items.length - 1 && k === items.length ) {
				reportCompletion();
			}
			stopAudio();
			i = k % items.length;
			try {
				window.localStorage.setItem( storeKey, String( i ) );
			} catch ( e ) {}
			render( 'card' );
		}

		function toggleAnswer() {
			var focusInside = $( 'answer-panel' ).contains( document.activeElement );
			state[ i ].answer = ! state[ i ].answer;
			if ( ! state[ i ].answer ) {
				stopAudio();
			}
			render( 'answer' );
			if ( state[ i ].answer ) {
				( listenBtn.hidden ? $( 'hide-btn' ) : listenBtn ).focus( { preventScroll: true } );
			} else if ( focusInside ) {
				// The focused control just disappeared; keep keyboard users in place.
				$( 'answer-btn' ).focus( { preventScroll: true } );
			}
		}

		/* Listen. listenSeq invalidates a pending fetch or play when the student
		   stops, hides the answer or moves on. */
		function setListen( stateName ) {
			listenBtn.setAttribute( 'data-state', stateName );
			var text = { idle: t.listen, loading: t.loading, playing: t.stop }[ stateName ];
			listenBtn.innerHTML = ICONS[ stateName ] + '<span>' + esc( text ) + '</span> <kbd>P</kbd>';
			if ( stateName === 'idle' ) {
				listenBtn.setAttribute( 'aria-label', t.listenLabel );
			} else {
				listenBtn.removeAttribute( 'aria-label' );
			}
		}

		function stopAudio() {
			listenSeq++;
			if ( audioEl ) {
				audioEl.pause();
			}
			if ( typeof speechSynthesis !== 'undefined' ) {
				speechSynthesis.cancel();
			}
			if ( hasAudio ) {
				setListen( 'idle' );
			}
		}

		function fallback( text, seq, err ) {
			console.warn( 'TBT Translation Drill audio:', err );
			if ( seq !== listenSeq ) {
				return;
			}
			var spoke = speak( text, function () {
				if ( seq === listenSeq ) {
					setListen( 'idle' );
				}
			} );
			setListen( spoke ? 'playing' : 'idle' );
		}

		function playUrl( url, text, seq ) {
			if ( ! audioEl ) {
				audioEl = new Audio();
			}
			audioEl.onended = function () {
				if ( seq === listenSeq ) {
					setListen( 'idle' );
				}
			};
			audioEl.src = url;
			setListen( 'playing' );
			var p = audioEl.play();
			if ( p && p.catch ) {
				p.catch( function ( err ) { fallback( text, seq, err ); } );
			}
		}

		function toggleListen() {
			if ( ! hasAudio || ! state[ i ].answer ) {
				return;
			}
			noteInteraction();
			if ( listenBtn.getAttribute( 'data-state' ) !== 'idle' ) {
				stopAudio();
				return;
			}
			var text = items[ i ].en.replace( MARK, '$1' ).trim();
			var seq = ++listenSeq;
			if ( audioUrls.has( text ) ) {
				playUrl( audioUrls.get( text ), text, seq );
				return;
			}
			setListen( 'loading' );
			fetchAudio( text ).then( function ( url ) {
				if ( seq === listenSeq ) {
					playUrl( url, text, seq );
				}
			} ).catch( function ( err ) { fallback( text, seq, err ); } );
		}

		// Toggled in place rather than re-rendered, so the tapped row keeps focus.
		$( 'phrases' ).addEventListener( 'click', function ( e ) {
			var b = e.target.closest( '.tbtd-p-phrase' );
			if ( ! b ) {
				return;
			}
			noteInteraction();
			var n = b.getAttribute( 'data-n' );
			var open = ! state[ i ].open[ n ];
			state[ i ].open[ n ] = open;
			b.classList.toggle( 'is-open', open );
			b.setAttribute( 'aria-expanded', open ? 'true' : 'false' );
			b.querySelector( '.tbtd-p-phrase-en' ).innerHTML = phraseEn( chunks( items[ i ].en )[ n ], open );
		} );
		$( 'answer-btn' ).addEventListener( 'click', function () { noteInteraction(); toggleAnswer(); } );
		$( 'hide-btn' ).addEventListener( 'click', function () { noteInteraction(); toggleAnswer(); } );
		listenBtn.addEventListener( 'click', toggleListen );
		$( 'prev' ).addEventListener( 'click', function () { noteInteraction(); go( i - 1 ); } );
		$( 'next' ).addEventListener( 'click', function () { noteInteraction(); go( i + 1 ); } );

		document.addEventListener( 'keydown', function ( e ) {
			var tag = e.target && e.target.tagName;
			if ( tag === 'TEXTAREA' || tag === 'INPUT' || tag === 'SELECT' || e.target.isContentEditable || e.metaKey || e.ctrlKey || e.altKey ) {
				return;
			}
			var k = e.key.toLowerCase();
			if ( k === 'arrowright' ) {
				e.preventDefault();
				noteInteraction();
				go( i + 1 );
			} else if ( k === 'arrowleft' ) {
				e.preventDefault();
				noteInteraction();
				go( i - 1 );
			} else if ( k === 'a' ) {
				noteInteraction();
				toggleAnswer();
			} else if ( k === 'p' ) {
				toggleListen();
			}
		} );

		if ( hasAudio ) {
			setListen( 'idle' );
		}
		render();
	}

	function boot() {
		var root = document.querySelector( '.tbtd-player[data-role="player"]' );
		if ( root ) {
			start( root );
		}
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', boot );
	} else {
		boot();
	}
}() );
