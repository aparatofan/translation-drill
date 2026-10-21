/**
 * TBT Translation Drill — student page.
 *
 * One Polish sentence at a time. Highlighted Polish expressions carry the
 * colour of their English partner; Hint lists them with the English gapped
 * (first letters only) and a tap reveals each one; Show answer gives the
 * full English with the same colours and the teacher's note.
 *
 * Nothing is scored. When TBT Notes is present, one completion per page load
 * is reported to its Class Progress panel, with a heartbeat while the student
 * works. Typed attempts still live in memory only, and the last sentence
 * reached is remembered per drill in this browser.
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
			return { hint: false, open: {}, answer: false, draft: '' };
		} );
		var i = 0;

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

		function renderYours() {
			var d = state[ i ].draft.trim();
			var yours = $( 'yours' );
			yours.hidden = ! d;
			if ( d ) {
				yours.innerHTML = esc( t.yours ) + ' <span>' + esc( d ) + '</span>';
			}
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
			$( 'attempt' ).value = st.draft;

			// Hints: every English highlight, in colour order, with its Polish
			// partner where there is one.
			var pl = chunks( item.pl );
			var en = chunks( item.en );
			$( 'hints' ).innerHTML = Object.keys( en ).sort().map( function ( n ) {
				var open = !! st.open[ n ];
				var enHtml = open
					? esc( en[ n ] )
					: '<span class="tbtd-p-gap">' + esc( gap( en[ n ] ) ) + '</span><span class="tbtd-p-tap">' + esc( t.tapReveal ) + '</span>';
				return '<li><button type="button" class="tbtd-p-hint' + ( open ? ' is-open' : '' ) + '" data-n="' + n + '" aria-expanded="' + open + '">'
					+ '<span class="tbtd-p-hint-pl"><span class="tbtd-p-dot tbtd-p-dot' + n + '"></span>' + ( pl[ n ] ? esc( pl[ n ] ) : '' ) + '</span>'
					+ '<span class="tbtd-p-hint-arrow" aria-hidden="true">→</span>'
					+ '<span class="tbtd-p-hint-en tbtd-p-en' + n + '">' + enHtml + '</span>'
					+ '</button></li>';
			} ).join( '' );
			var hasHints = Object.keys( en ).length > 0;
			$( 'hint-btn' ).hidden = ! hasHints;
			$( 'hint-panel' ).hidden = ! ( st.hint && hasHints );
			$( 'hint-btn' ).setAttribute( 'aria-pressed', st.hint ? 'true' : 'false' );

			$( 'en' ).innerHTML = marked( item.en );
			var note = $( 'note' );
			note.innerHTML = item.note || '';
			note.hidden = ! item.note;
			renderYours();
			$( 'answer-panel' ).hidden = ! st.answer;
			$( 'answer-btn' ).firstChild.nodeValue = ( st.answer ? t.hideAnswer : t.showAnswer ) + ' ';

			if ( animate === 'answer' && st.answer ) {
				restart( $( 'answer-panel' ) );
			}
			if ( animate === 'hint' && st.hint ) {
				restart( $( 'hint-panel' ) );
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
			i = k % items.length;
			try {
				window.localStorage.setItem( storeKey, String( i ) );
			} catch ( e ) {}
			render( 'card' );
		}

		function toggleHint() {
			state[ i ].hint = ! state[ i ].hint;
			render( 'hint' );
		}

		function toggleAnswer() {
			state[ i ].answer = ! state[ i ].answer;
			render( 'answer' );
		}

		$( 'hint-btn' ).addEventListener( 'click', function () { noteInteraction(); toggleHint(); } );
		$( 'answer-btn' ).addEventListener( 'click', function () { noteInteraction(); toggleAnswer(); } );
		$( 'prev' ).addEventListener( 'click', function () { noteInteraction(); go( i - 1 ); } );
		$( 'next' ).addEventListener( 'click', function () { noteInteraction(); go( i + 1 ); } );
		$( 'attempt' ).addEventListener( 'input', function ( e ) {
			noteInteraction();
			state[ i ].draft = e.target.value;
			renderYours();
		} );
		$( 'hints' ).addEventListener( 'click', function ( e ) {
			var b = e.target.closest( '.tbtd-p-hint' );
			if ( ! b ) {
				return;
			}
			noteInteraction();
			var n = b.getAttribute( 'data-n' );
			state[ i ].open[ n ] = ! state[ i ].open[ n ];
			render();
		} );

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
			} else if ( k === 'h' ) {
				noteInteraction();
				toggleHint();
			} else if ( k === 'a' ) {
				noteInteraction();
				toggleAnswer();
			}
		} );

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
