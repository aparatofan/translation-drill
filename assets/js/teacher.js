/**
 * TBT Translation Drill — teacher page.
 *
 * Two independent parts, each starting only if its markup is on the page:
 *   - the library: search and class filter, delete, copy link, QR code
 *   - the builder: the English sentence list (typed, pasted or written by
 *     the AI), sentence rows with a select-to-highlight editor for the
 *     English and the Polish, AI translation, save / publish / share
 *
 * The highlight format is the contract with the server (TBTD_Markup):
 *     plain text with fragments wrapped as [fragment|n], n = 1–4.
 * parseMarkup() and serialize() below are the JS twins of the PHP ones.
 *
 * Vanilla JS, no dependencies beyond the self-hosted QR library.
 */
( function () {
	'use strict';

	var cfg = window.tbtdTeacher || {};
	var t = cfg.i18n || {};
	var MAX_MARKS = 4;

	/* ------------------------------------------------------------------ *
	 * Helpers
	 * ------------------------------------------------------------------ */

	/** sprintf-lite: %d / %s in order, or %1$d / %2$s by position. */
	function fmt( str ) {
		var args = Array.prototype.slice.call( arguments, 1 );
		var i = 0;
		return String( str || '' ).replace( /%(?:(\d+)\$)?[ds]/g, function ( _, pos ) {
			var v = pos ? args[ parseInt( pos, 10 ) - 1 ] : args[ i++ ];
			return v === undefined ? '' : String( v );
		} );
	}

	function el( tag, attrs, children ) {
		var node = document.createElement( tag );
		Object.keys( attrs || {} ).forEach( function ( key ) {
			var val = attrs[ key ];
			if ( val === null || val === undefined || val === false ) {
				return;
			}
			if ( key === 'text' ) {
				node.textContent = val;
			} else if ( key === 'className' ) {
				node.className = val;
			} else if ( key.indexOf( 'on' ) === 0 && typeof val === 'function' ) {
				node.addEventListener( key.slice( 2 ), val );
			} else {
				node.setAttribute( key, val === true ? '' : val );
			}
		} );
		( children || [] ).forEach( function ( child ) {
			if ( child === null || child === undefined ) {
				return;
			}
			node.appendChild( typeof child === 'string' ? document.createTextNode( child ) : child );
		} );
		return node;
	}

	/** The three markup characters cannot be content; same swap as the server. */
	function cleanChars( s ) {
		return String( s || '' ).replace( /\[/g, '(' ).replace( /\]/g, ')' ).replace( /\|/g, '/' );
	}

	function parseMarkup( markup ) {
		var src = String( markup || '' ).replace( /\s+/g, ' ' );
		var re = /\[([^\[\]|]+)\|(\d+)\]/g;
		var text = '';
		var marks = [];
		var used = {};
		var pos = 0;
		var m;
		while ( ( m = re.exec( src ) ) !== null ) {
			text += cleanChars( src.slice( pos, m.index ) );
			var inner = m[ 1 ];
			var n = parseInt( m[ 2 ], 10 );
			var lead = inner.length - inner.replace( /^\s+/, '' ).length;
			var core = inner.trim();
			text += inner.slice( 0, lead );
			if ( core && n >= 1 && n <= MAX_MARKS && ! used[ n ] ) {
				marks.push( { n: n, start: text.length, end: text.length + core.length } );
				used[ n ] = true;
			}
			text += core + inner.slice( lead + core.length );
			pos = m.index + m[ 0 ].length;
		}
		text += cleanChars( src.slice( pos ) );

		var trimmedLead = text.length - text.replace( /^\s+/, '' ).length;
		text = text.trim();
		marks.forEach( function ( mk ) {
			mk.start -= trimmedLead;
			mk.end -= trimmedLead;
		} );
		marks.sort( function ( a, b ) { return a.start - b.start; } );
		return { text: text, marks: marks };
	}

	function serialize( side ) {
		var text = side.text;
		var marks = side.marks.slice().sort( function ( a, b ) { return a.start - b.start; } );
		var out = '';
		var pos = 0;
		marks.forEach( function ( mk ) {
			if ( mk.start < pos || mk.end <= mk.start || mk.end > text.length ) {
				return;
			}
			var inner = text.slice( mk.start, mk.end );
			if ( ! inner.trim() ) {
				return;
			}
			out += text.slice( pos, mk.start ) + '[' + inner + '|' + mk.n + ']';
			pos = mk.end;
		} );
		return ( out + text.slice( pos ) ).replace( /\s+/g, ' ' ).trim();
	}

	function numbersOf( side ) {
		return side.marks.map( function ( mk ) { return mk.n; } ).sort();
	}

	function fragment( side, n ) {
		for ( var i = 0; i < side.marks.length; i++ ) {
			if ( side.marks[ i ].n === n ) {
				return side.text.slice( side.marks[ i ].start, side.marks[ i ].end );
			}
		}
		return '';
	}

	function dropNumber( side, n ) {
		side.marks = side.marks.filter( function ( mk ) { return mk.n !== n; } );
	}

	/**
	 * Move highlights along with an edit of the plain text.
	 *
	 * The edit is located as the span between the common prefix and the
	 * common suffix of old and new text. Marks wholly before it stay, marks
	 * wholly after it shift, and a mark the edit touches stretches or shrinks
	 * with it — so fixing a typo inside a highlighted phrase keeps the
	 * highlight instead of losing it.
	 */
	function adjustMarks( side, oldText, newText ) {
		var max = Math.min( oldText.length, newText.length );
		var p = 0;
		while ( p < max && oldText[ p ] === newText[ p ] ) {
			p++;
		}
		var s = 0;
		while ( s < max - p && oldText[ oldText.length - 1 - s ] === newText[ newText.length - 1 - s ] ) {
			s++;
		}
		var oldEnd = oldText.length - s;
		var newEnd = newText.length - s;
		var delta = newText.length - oldText.length;

		side.marks = side.marks.map( function ( mk ) {
			var a = mk.start < p ? mk.start : ( mk.start >= oldEnd ? mk.start + delta : p );
			var b = mk.end <= p ? mk.end : ( mk.end >= oldEnd ? mk.end + delta : newEnd );
			while ( a < b && /\s/.test( newText[ a ] ) ) {
				a++;
			}
			while ( b > a && /\s/.test( newText[ b - 1 ] ) ) {
				b--;
			}
			return { n: mk.n, start: a, end: b };
		} ).filter( function ( mk ) {
			return mk.end > mk.start;
		} );
		side.text = newText;
	}

	var WORD_RE = /[\p{L}\p{N}][\p{L}\p{N}'’\-]*/gu;

	/**
	 * Split a sentence into word and separator segments for the picker.
	 *
	 * Boundaries come from the words and from every highlight edge, so a
	 * highlight the AI placed mid-word still renders exactly where it is.
	 * Only word segments can be clicked; separators ride along inside a range.
	 */
	function segments( side ) {
		var text = side.text;
		var cuts = {};
		var words = [];
		var m;
		cuts[ 0 ] = true;
		cuts[ text.length ] = true;
		WORD_RE.lastIndex = 0;
		while ( ( m = WORD_RE.exec( text ) ) !== null ) {
			cuts[ m.index ] = true;
			cuts[ m.index + m[ 0 ].length ] = true;
			words.push( [ m.index, m.index + m[ 0 ].length ] );
		}
		side.marks.forEach( function ( mk ) {
			cuts[ mk.start ] = true;
			cuts[ mk.end ] = true;
		} );
		var points = Object.keys( cuts ).map( Number ).sort( function ( a, b ) { return a - b; } );
		var out = [];
		for ( var i = 0; i < points.length - 1; i++ ) {
			var s = points[ i ];
			var e = points[ i + 1 ];
			if ( e <= s ) {
				continue;
			}
			out.push( {
				start: s,
				end: e,
				word: words.some( function ( w ) { return s >= w[ 0 ] && e <= w[ 1 ]; } )
			} );
		}
		return out;
	}

	function api( method, path, body ) {
		return fetch( cfg.restBase + path, {
			method: method,
			credentials: 'same-origin',
			headers: {
				'X-WP-Nonce': cfg.nonce,
				'Content-Type': 'application/json'
			},
			body: body ? JSON.stringify( body ) : undefined
		} ).then( function ( res ) {
			return res.json().catch( function () { return {}; } ).then( function ( data ) {
				if ( res.ok ) {
					return data;
				}
				var msg = ( data && data.message ) || t.networkError;
				if ( data && data.code === 'rest_cookie_invalid_nonce' ) {
					msg = t.staleNonce;
				}
				var err = new Error( msg );
				err.code = data && data.code;
				throw err;
			} );
		}, function () {
			throw new Error( t.networkError );
		} );
	}

	function copyText( text, button ) {
		var label = button.textContent;
		function done() {
			button.textContent = t.copied;
			setTimeout( function () { button.textContent = label; }, 1600 );
		}
		function fallback() {
			var area = el( 'textarea', { readonly: true, style: 'position:fixed;opacity:0' } );
			area.value = text;
			document.body.appendChild( area );
			area.select();
			try {
				document.execCommand( 'copy' );
				done();
			} catch ( e ) {}
			document.body.removeChild( area );
		}
		if ( navigator.clipboard && navigator.clipboard.writeText ) {
			navigator.clipboard.writeText( text ).then( done, fallback );
		} else {
			fallback();
		}
	}

	function drawQr( target, url ) {
		target.innerHTML = '';
		if ( ! url || typeof window.QRCode === 'undefined' ) {
			return;
		}
		/* eslint-disable no-new */
		new window.QRCode( target, {
			text: url,
			width: 176,
			height: 176,
			colorDark: '#0E2A4F',
			colorLight: '#FFFFFF',
			correctLevel: window.QRCode.CorrectLevel.M
		} );
	}

	function showError( box, message ) {
		if ( ! box ) {
			return;
		}
		box.textContent = message || '';
		box.hidden = ! message;
		if ( message ) {
			box.scrollIntoView( { behavior: 'smooth', block: 'center' } );
		}
	}

	/* ------------------------------------------------------------------ *
	 * Library
	 * ------------------------------------------------------------------ */

	function initLibrary( root ) {
		var errorBox = root.querySelector( '[data-role="error"]' );
		var modal = document.querySelector( '[data-role="qr-modal"]' );
		var filter = initFilter( root, modal );

		/* Rows and groups are found by their behavioural attributes, never by
		   the class names the design owns. */
		root.addEventListener( 'click', function ( e ) {
			var btn = e.target.closest( 'button[data-role]' );
			if ( ! btn ) {
				return;
			}
			var role = btn.getAttribute( 'data-role' );
			var row = btn.closest( '[data-id]' );

			if ( role === 'copy' ) {
				copyText( btn.getAttribute( 'data-url' ), btn );
			} else if ( role === 'qr' && modal ) {
				modal.querySelector( '[data-role="qr-title"]' ).textContent = btn.getAttribute( 'data-title' );
				drawQr( modal.querySelector( '[data-role="qr-target"]' ), btn.getAttribute( 'data-url' ) );
				modal.hidden = false;
				modal.querySelector( '[data-role="qr-close"]' ).focus();
			} else if ( role === 'filter-clear' && filter ) {
				filter.clearSearch();
			} else if ( role === 'filter-reset' && filter ) {
				filter.reset();
			} else if ( role === 'delete' && row ) {
				if ( ! window.confirm( t.confirmDelete ) ) {
					return;
				}
				btn.disabled = true;
				api( 'DELETE', 'drills/' + row.getAttribute( 'data-id' ) ).then( function () {
					var group = row.closest( '[data-role="group"]' );
					row.remove();
					if ( group && ! group.querySelector( '[data-id]' ) ) {
						group.remove();
					}
					// Counts, the summary and the class dropdown are all
					// counting rows that no longer exist.
					if ( filter ) {
						filter.refresh();
					}
				} ).catch( function ( err ) {
					btn.disabled = false;
					showError( errorBox, err.message );
				} );
			}
		} );

		if ( modal ) {
			var close = function () { modal.hidden = true; };
			modal.addEventListener( 'click', function ( e ) {
				if ( e.target === modal || e.target.closest( '[data-role="qr-close"]' ) ) {
					close();
				}
			} );
			document.addEventListener( 'keydown', function ( e ) {
				if ( e.key === 'Escape' && ! modal.hidden ) {
					close();
				}
			} );
		}
	}

	/* ------------------------------------------------------------------ *
	 * Library filter — ported from TBT Swipe's Admin Bar, plus a search
	 * through the sentences themselves.
	 * ------------------------------------------------------------------ */

	var FILTER_Q_PARAM = 'tbtd_q';
	var FILTER_CLASS_PARAM = 'tbtd_class';
	var HIT_MAX = 90;

	/**
	 * Case- and diacritic-insensitive: "malas" finds "Małas", "sroda" finds
	 * "Środa". ł does not decompose under NFD, so it is mapped by hand.
	 */
	function fold( value ) {
		return String( value || '' ).replace( /[łŁ]/g, 'l' ).normalize( 'NFD' ).replace( /[̀-ͯ]/g, '' ).toLowerCase();
	}

	function roleIn( scope, name ) {
		return scope.querySelector( '[data-role="' + name + '"]' );
	}

	function plural( n ) {
		return 1 === n ? t.itemOne : t.itemMany;
	}

	/**
	 * Show the match inside a piece of text, and nothing else. Built out of
	 * nodes, never innerHTML: the text is something the teacher typed.
	 *
	 * @param {Element} node Element to rewrite.
	 * @param {string}  text Source text from a data attribute — the element's
	 *                       own text may already hold a mark.
	 * @param {string}  q    Folded query, or '' to strip the marks.
	 */
	function highlight( node, text, q ) {
		if ( ! node ) {
			return;
		}
		var folded = fold( text );
		var at = q ? folded.indexOf( q ) : -1;

		/* fold() keeps length for the Polish letters. If some other character
		   changed it the indices no longer line up, so skip the mark rather
		   than highlight the wrong letters. */
		if ( -1 === at || folded.length !== text.length ) {
			if ( node.firstElementChild || node.textContent !== text ) {
				node.textContent = text;
			}
			return;
		}

		node.textContent = '';
		node.appendChild( document.createTextNode( text.slice( 0, at ) ) );
		node.appendChild( el( 'mark', { className: 'tbtd-hit', text: text.slice( at, at + q.length ) } ) );
		node.appendChild( document.createTextNode( text.slice( at + q.length ) ) );
	}

	/**
	 * The line under a row that matched only through its sentences: the first
	 * matching sentence in quotes, trimmed around the match, "+N more".
	 *
	 * @param {Element}       line The row's [data-role=item-hit].
	 * @param {Object}        data Cached row data.
	 * @param {number[]|null} hits Indexes of matching sentences, or null to clear.
	 * @param {string}        q    Folded query.
	 */
	function fillHitLine( line, data, hits, q ) {
		if ( ! line ) {
			return;
		}
		if ( ! hits || ! hits.length ) {
			if ( ! line.hidden || line.firstChild ) {
				line.textContent = '';
				line.hidden = true;
			}
			return;
		}

		var i = hits[ 0 ];
		var side = -1 !== data.folded[ i ][ 0 ].indexOf( q ) ? 0 : 1;
		var text = data.pairs[ i ][ side ];
		var folded = data.folded[ i ][ side ];
		var at = folded.indexOf( q );
		var aligned = folded.length === text.length;
		var start = 0;
		var end = text.length;

		if ( text.length > HIT_MAX ) {
			// Centre the match in the window, then pull the window back
			// inside the sentence.
			start = Math.max( 0, at - Math.floor( Math.max( 0, HIT_MAX - q.length ) / 2 ) );
			end = Math.min( text.length, start + HIT_MAX );
			start = Math.max( 0, Math.min( start, end - HIT_MAX ) );
			end = Math.min( text.length, Math.max( end, at + q.length ) );
		}

		var lead = start > 0 ? '…' : '';
		var tail = end < text.length ? '…' : '';
		var quote = String( t.hitQuote || '“%1$s”' ).split( '%1$s' );

		line.textContent = '';
		if ( aligned ) {
			line.appendChild( document.createTextNode( quote[ 0 ] + lead + text.slice( start, at ).replace( /^\s+/, lead ? '' : '$&' ) ) );
			line.appendChild( el( 'mark', { className: 'tbtd-hit', text: text.slice( at, at + q.length ) } ) );
			line.appendChild( document.createTextNode( text.slice( at + q.length, end ).replace( /\s+$/, tail ? '' : '$&' ) + tail + ( quote[ 1 ] || '' ) ) );
		} else {
			line.appendChild( document.createTextNode( quote[ 0 ] + lead + text.slice( start, end ).trim() + tail + ( quote[ 1 ] || '' ) ) );
		}
		if ( hits.length > 1 ) {
			line.appendChild( el( 'span', { className: 'tbtd-hit-more', text: fmt( t.hitMore, hits.length - 1 ) } ) );
		}
		line.hidden = false;
	}

	/**
	 * @return {Object|null} { refresh, clearSearch, reset }, or null when the
	 *                       library has no filter (no drills on load).
	 */
	function initFilter( root, modal ) {
		var input = roleIn( root, 'filter-q' );
		if ( ! input ) {
			return null;
		}
		var head = roleIn( root, 'library-head' );
		var bar = roleIn( root, 'filter' );
		var endLine = roleIn( root, 'filter-end' );
		var classSelect = roleIn( root, 'filter-class' );
		var clearBtn = roleIn( root, 'filter-clear' );
		var summary = roleIn( root, 'filter-summary' );
		var summaryText = roleIn( root, 'filter-summary-text' );
		var filterEmpty = roleIn( root, 'filter-empty' );
		var libraryEmpty = roleIn( root, 'library-empty' );
		var cache = new WeakMap();
		var typing = null;

		/* Parsed and folded once per row, never per keystroke. A deleted row
		   takes its entry with it. */
		function rowData( row ) {
			var data = cache.get( row );
			if ( data ) {
				return data;
			}
			var pairs = [];
			try {
				pairs = JSON.parse( row.getAttribute( 'data-search-items' ) || '[]' );
			} catch ( e ) {}
			if ( ! Array.isArray( pairs ) ) {
				pairs = [];
			}
			pairs = pairs.map( function ( p ) {
				return [ String( ( p && p[ 0 ] ) || '' ), String( ( p && p[ 1 ] ) || '' ) ];
			} );
			data = {
				title: row.getAttribute( 'data-search-title' ) || '',
				label: row.getAttribute( 'data-search-label' ) || '',
				chip: row.getAttribute( 'data-search-chip' ) || '',
				pairs: pairs,
				folded: pairs.map( function ( p ) { return [ fold( p[ 0 ] ), fold( p[ 1 ] ) ]; } )
			};
			data.foldedName = fold( data.title ) + '\n' + fold( data.label ) + '\n' + fold( data.chip );
			cache.set( row, data );
			return data;
		}

		function applyFilter() {
			var q = fold( input.value.trim() );
			var cls = classSelect ? classSelect.value : '';
			var active = !! ( q || cls );
			var sections = root.querySelectorAll( '[data-filter-group]' );
			var total = 0;
			var shown = 0;

			Array.prototype.forEach.call( sections, function ( section ) {
				var inClass = ! cls || cls === section.getAttribute( 'data-filter-group' );
				/* Only a real class has a searchable name. "Drafts" and "No
				   class" are the plugin's own words. */
				var groupName = section.getAttribute( 'data-filter-name' ) || '';
				var foldedGroup = fold( groupName );
				var rows = section.querySelectorAll( '[data-id]' );
				var visible = 0;

				Array.prototype.forEach.call( rows, function ( row ) {
					var data = rowData( row );
					var nameHit = false;
					var hits = [];
					var match = inClass;

					if ( match && q ) {
						nameHit = -1 !== ( data.foldedName + '\n' + foldedGroup ).indexOf( q );
						if ( ! nameHit ) {
							data.folded.forEach( function ( f, i ) {
								if ( -1 !== f[ 0 ].indexOf( q ) || -1 !== f[ 1 ].indexOf( q ) ) {
									hits.push( i );
								}
							} );
						}
						match = nameHit || hits.length > 0;
					}

					row.hidden = ! match;
					if ( match ) {
						visible++;
					}

					highlight( roleIn( row, 'item-title' ), data.title, match ? q : '' );
					if ( '' !== data.label ) {
						highlight( roleIn( row, 'item-label' ), data.label, match ? q : '' );
					}
					if ( '' !== data.chip ) {
						highlight( roleIn( row, 'item-chip' ), data.chip, match ? q : '' );
					}
					fillHitLine( roleIn( row, 'item-hit' ), data, match && q && ! nameHit ? hits : null, q );
				} );

				if ( '' !== groupName ) {
					highlight( roleIn( section, 'group-name' ), groupName, visible ? q : '' );
				}

				section.hidden = 0 === visible;
				shown += visible;
				total += rows.length;

				var count = roleIn( section, 'group-count' );
				if ( count ) {
					count.textContent = active && visible !== rows.length
						? fmt( t.filterOf, visible, rows.length, plural( rows.length ) )
						: fmt( t.filterAll, rows.length, plural( rows.length ) );
				}
			} );

			if ( clearBtn ) {
				clearBtn.hidden = ! input.value;
			}
			if ( classSelect ) {
				classSelect.classList.toggle( 'is-set', !! cls );
			}
			if ( summary ) {
				summary.hidden = ! ( active && shown > 0 );
				if ( ! summary.hidden && summaryText ) {
					summaryText.textContent = fmt( t.filterShowing, shown, total, plural( total ) );
				}
			}
			if ( filterEmpty ) {
				filterEmpty.hidden = ! ( active && 0 === shown );
			}

			writeUrl();
		}

		/* A class whose last drill was deleted keeps an option that can only
		   ever show nothing, so the option goes with the group. */
		function refreshOptions() {
			if ( ! classSelect ) {
				return;
			}
			var options = classSelect.options;
			for ( var i = options.length - 1; i >= 0; i-- ) {
				var option = options[ i ];
				var value = option.value;
				if ( '' === value ) {
					continue;
				}
				var n = root.querySelectorAll( '[data-filter-group="' + value + '"] [data-id]' ).length;
				if ( 0 === n ) {
					if ( classSelect.value === value ) {
						classSelect.value = '';
					}
					option.remove();
					continue;
				}
				option.textContent = fmt( t.optionLabel, option.getAttribute( 'data-name' ), n );
			}
		}

		function refresh() {
			refreshOptions();
			applyFilter();

			// The last drill is gone: back to the empty library, no reload.
			if ( ! root.querySelector( '[data-id]' ) ) {
				if ( head ) {
					head.classList.remove( 'tbtd-library-head--filter' );
				}
				[ bar, endLine, summary, filterEmpty ].forEach( function ( node ) {
					if ( node ) {
						node.hidden = true;
					}
				} );
				if ( libraryEmpty ) {
					libraryEmpty.hidden = false;
				}
			}
		}

		function clearSearch() {
			input.value = '';
			applyFilter();
			input.focus();
		}

		function reset() {
			input.value = '';
			if ( classSelect ) {
				classSelect.value = '';
			}
			applyFilter();
			input.focus();
		}

		function hasOption( value ) {
			return Array.prototype.some.call( classSelect.options, function ( o ) { return o.value === value; } );
		}

		function readUrl() {
			try {
				var params = new URLSearchParams( window.location.search );
				var q = params.get( FILTER_Q_PARAM );
				var cls = params.get( FILTER_CLASS_PARAM );
				if ( q ) {
					input.value = q;
				}
				// Only a class still on the page: a value no option carries
				// would silently select the first.
				if ( cls && classSelect && hasOption( cls ) ) {
					classSelect.value = cls;
				}
			} catch ( e ) {}
		}

		/* replaceState, never pushState: typing must not leave one history
		   entry per keystroke. Edit → Back lands on the same filtered view. */
		function writeUrl() {
			if ( ! window.history || ! window.history.replaceState ) {
				return;
			}
			try {
				var url = new URL( window.location.href );
				var q = input.value.trim();
				var cls = classSelect ? classSelect.value : '';
				if ( q ) {
					url.searchParams.set( FILTER_Q_PARAM, q );
				} else {
					url.searchParams.delete( FILTER_Q_PARAM );
				}
				if ( cls ) {
					url.searchParams.set( FILTER_CLASS_PARAM, cls );
				} else {
					url.searchParams.delete( FILTER_CLASS_PARAM );
				}
				if ( url.toString() !== window.location.href ) {
					window.history.replaceState( window.history.state, '', url.toString() );
				}
			} catch ( e ) {}
		}

		input.addEventListener( 'input', function () {
			// Client-side, so short: a fast typist filters once, not per key.
			window.clearTimeout( typing );
			typing = window.setTimeout( applyFilter, 80 );
		} );

		input.addEventListener( 'keydown', function ( e ) {
			// An empty field has nothing to clear, so Escape passes through.
			if ( 'Escape' === e.key && input.value ) {
				e.preventDefault();
				window.clearTimeout( typing );
				input.value = '';
				applyFilter();
			}
		} );

		if ( classSelect ) {
			classSelect.addEventListener( 'change', applyFilter );
		}

		document.addEventListener( 'keydown', function ( e ) {
			if ( '/' !== e.key || e.ctrlKey || e.metaKey || e.altKey ) {
				return;
			}
			if ( ( modal && ! modal.hidden ) || ( bar && bar.hidden ) ) {
				return;
			}
			var active = document.activeElement;
			var tag = active ? active.tagName : '';
			if ( 'INPUT' === tag || 'TEXTAREA' === tag || 'SELECT' === tag || ( active && active.isContentEditable ) ) {
				return;
			}
			e.preventDefault();
			input.focus();
		} );

		readUrl();
		applyFilter();

		return { refresh: refresh, clearSearch: clearSearch, reset: reset };
	}

	/* ------------------------------------------------------------------ *
	 * Builder
	 * ------------------------------------------------------------------ */

	function initBuilder( root ) {
		var $ = function ( role ) { return root.querySelector( '[data-role="' + role + '"]' ); };
		var dataNode = $( 'drill-data' );
		var initial = null;
		try {
			initial = JSON.parse( dataNode ? dataNode.textContent : 'null' );
		} catch ( e ) {
			initial = null;
		}

		var errorBox = $( 'error' );
		var list = $( 'items' );
		var srows = $( 'srows' );
		var titleInput = $( 'title' );
		var classInput = $( 'class' );
		var classIdField = $( 'class-id' );
		var classHint = $( 'class-hint' );
		var classHelp = $( 'class-help' );
		var classList = root.querySelector( '#tbtd-class-list' );
		var lessonSelect = $( 'lesson' );
		var lessonWrap = $( 'lesson-wrap' );
		var translateBtn = $( 'translate' );
		var translateStatus = $( 'translate-status' );
		var saveBtn = $( 'save' );
		var publishBtn = $( 'publish' );
		var unpublishBtn = $( 'unpublish' );
		var previewLink = $( 'preview' );
		var stateLine = $( 'state-line' );
		var savedMsg = $( 'saved-msg' );
		var warnings = $( 'warnings' );
		var share = $( 'share' );
		var anotherRow = $( 'another-row' );
		var defaultStatus = translateStatus.textContent.trim();
		var stage3 = $( 'stage-highlight' );
		var generateBtn = $( 'generate' );
		var genStatus = $( 'gen-status' );
		var genDefault = genStatus.textContent.trim();
		var continueBtn = $( 'continue' );
		var continueHelp = $( 'continue-help' );
		var removeAllBtn = $( 'remove-all' );
		var reduceMotion = window.matchMedia && window.matchMedia( '(prefers-reduced-motion: reduce)' ).matches;

		var state = {
			id: initial ? initial.id : 0,
			slug: initial ? initial.slug : '',
			status: initial ? initial.status : 'draft',
			url: initial ? initial.url : '',
			items: [],
			busy: false,
			dirty: false,
			remaining: cfg.remaining,
			// Stage 3 waits for "Continue" on a new drill; a saved drill with
			// sentences opens with it. Once open it stays open.
			stage3Open: !! ( initial && initial.items && initial.items.length )
		};
		var keySeq = 0;

		function newItem( enMarkup, plMarkup, note, fromServer ) {
			var en = parseMarkup( enMarkup || '' );
			var pl = parseMarkup( plMarkup || '' );
			// A Polish mark can only point at an English one that exists.
			var allowed = numbersOf( en );
			pl.marks = pl.marks.filter( function ( mk ) { return allowed.indexOf( mk.n ) !== -1; } );
			return {
				key: 'i' + ( ++keySeq ),
				en: en,
				pl: pl,
				note: note || '',
				// The English the current Polish was made from. Saved drills
				// count as up to date; a change to the English flags the row.
				src: fromServer && pl.text ? serialize( en ) : null,
				// A one-line message under each picker ("overlaps…", "all four
				// colours used…"), cleared by the next successful action.
				notice: { en: '', pl: '' },
				// The English colour the next Polish selection pairs with, when
				// the teacher picked one; otherwise the first unpaired colour.
				target: null,
				// Written by the AI in this session. Shown as a tag, never saved.
				ai: false,
				dom: {}
			};
		}

		function markDirty() {
			state.dirty = true;
			savedMsg.textContent = '';
			refreshTranslateBar();
			refreshApprove();
		}

		window.addEventListener( 'beforeunload', function ( e ) {
			if ( state.dirty ) {
				e.preventDefault();
				e.returnValue = t.confirmLeave;
				return t.confirmLeave;
			}
		} );

		/* ---------- status of one row ---------- */

		function rowStatus( item ) {
			if ( ! hasText( item ) ) {
				return '';
			}
			if ( ! item.pl.text ) {
				return 'new';
			}
			if ( item.src !== null && item.src !== serialize( item.en ) ) {
				return 'changed';
			}
			if ( missingNumbers( item ).length ) {
				return 'missing';
			}
			return 'done';
		}

		function missingNumbers( item ) {
			var pl = numbersOf( item.pl );
			return numbersOf( item.en ).filter( function ( n ) { return pl.indexOf( n ) === -1; } );
		}

		function needsTranslation( item ) {
			var s = rowStatus( item );
			return s === 'new' || s === 'changed';
		}

		/* ---------- row construction ---------- */

		/**
		 * Each item has two views: the Stage 2 row (number, English input,
		 * "AI" tag, ×) and the Stage 3 row (highlighting, Polish, note). The
		 * English input lives only in Stage 2; Stage 3 shows the sentence as
		 * the picker.
		 */
		function buildRows( item ) {
			buildSrow( item );
			buildRow( item );
		}

		function buildSrow( item ) {
			var d = item.dom;

			d.sn = el( 'span', { className: 'tbtd-item-n' } );
			d.enInput = el( 'input', {
				type: 'text',
				className: 'tbtd-input tbtd-input--sentence',
				placeholder: t.enPlaceholder,
				'aria-label': t.english,
				maxlength: '400'
			} );
			d.enInput.value = item.en.text;
			d.enInput.addEventListener( 'input', function () { onTextInput( item, 'en' ); } );
			d.enInput.addEventListener( 'keydown', function ( e ) {
				if ( e.key !== 'Enter' || e.isComposing ) {
					return;
				}
				e.preventDefault();
				var at = state.items.indexOf( item ) + 1;
				if ( liveCount() < cfg.maxItems ) {
					addItem( newItem(), true, at );
					markDirty();
				}
			} );
			d.src = el( 'span', { className: 'tbtd-src', text: t.aiTag, title: t.aiTagTitle } );
			d.src.hidden = ! item.ai;

			d.srow = el( 'li', { className: 'tbtd-srow', 'data-key': item.key }, [
				d.sn,
				d.enInput,
				d.src,
				el( 'button', {
					type: 'button',
					className: 'tbtd-item-x',
					'aria-label': t.removeSentence,
					title: t.removeSentence,
					text: '×',
					onclick: function () { removeItem( item ); }
				} )
			] );
			return d.srow;
		}

		function buildRow( item ) {
			var d = item.dom;

			d.n = el( 'span', { className: 'tbtd-item-n' } );
			d.status = el( 'span', { className: 'tbtd-item-status' } );
			var removeBtn = el( 'button', {
				type: 'button',
				className: 'tbtd-item-x',
				'aria-label': t.removeSentence,
				title: t.removeSentence,
				text: '×',
				onclick: function () { removeItem( item ); }
			} );

			d.enPreview = el( 'div', { className: 'tbtd-preview', 'data-key': item.key, 'data-side': 'en' } );
			d.enTools = el( 'div', { className: 'tbtd-tools' } );

			d.plInput = el( 'input', {
				type: 'text',
				className: 'tbtd-input tbtd-input--sentence',
				placeholder: t.plPlaceholder,
				'aria-label': t.polish,
				maxlength: '400',
				lang: 'pl'
			} );
			d.plInput.value = item.pl.text;
			d.plInput.addEventListener( 'input', function () { onTextInput( item, 'pl' ); } );

			d.plPreview = el( 'div', { className: 'tbtd-preview', 'data-key': item.key, 'data-side': 'pl', lang: 'pl' } );
			d.plTools = el( 'div', { className: 'tbtd-tools' } );

			d.note = el( 'textarea', {
				className: 'tbtd-input tbtd-note',
				rows: '2',
				maxlength: '400',
				placeholder: t.notePlaceholder,
				'aria-label': t.note
			} );
			d.note.value = item.note;
			d.note.addEventListener( 'input', function () {
				item.note = d.note.value;
				markDirty();
			} );

			d.translateBtn = el( 'button', {
				type: 'button',
				className: 'tbtd-linkbtn',
				onclick: function () { translate( [ item ] ); }
			} );

			d.row = el( 'li', { className: 'tbtd-item', 'data-key': item.key }, [
				el( 'div', { className: 'tbtd-item-head' }, [ d.n, d.status, removeBtn ] ),
				el( 'div', { className: 'tbtd-lang' }, [
					el( 'span', { className: 'tbtd-lang-label', text: t.english } ),
					d.enPreview,
					d.enTools
				] ),
				el( 'div', { className: 'tbtd-lang tbtd-lang--pl' }, [
					el( 'span', { className: 'tbtd-lang-label', text: t.polish } ),
					d.plInput,
					d.plPreview,
					d.plTools,
					el( 'span', { className: 'tbtd-lang-label tbtd-lang-label--note', text: t.note } ),
					d.note
				] ),
				el( 'div', { className: 'tbtd-item-foot' }, [ d.translateBtn ] )
			] );

			refreshRow( item );
			return d.row;
		}

		/**
		 * The picker: the sentence as clickable words, highlights painted in.
		 * Clicking a word highlights it; dragging across words highlights the
		 * range on release — the gesture TBT Drag & Drop uses for gaps.
		 */
		function renderPreview( item, sideName ) {
			var side = item[ sideName ];
			var box = sideName === 'en' ? item.dom.enPreview : item.dom.plPreview;
			box.innerHTML = '';
			box.classList.toggle( 'is-empty', ! side.text );
			if ( ! side.text ) {
				box.appendChild( el( 'span', { className: 'tbtd-preview-empty', text: sideName === 'en' ? t.enEmpty : t.plEmpty } ) );
				return;
			}
			var marks = side.marks.slice().sort( function ( a, b ) { return a.start - b.start; } );
			var holder = null;
			var current = null;
			segments( side ).forEach( function ( seg ) {
				var mk = null;
				for ( var i = 0; i < marks.length; i++ ) {
					if ( seg.start >= marks[ i ].start && seg.end <= marks[ i ].end ) {
						mk = marks[ i ];
						break;
					}
				}
				if ( mk !== current ) {
					current = mk;
					holder = mk ? el( 'mark', { className: 'tbtd-hl tbtd-hl' + mk.n, 'data-n': String( mk.n ) } ) : null;
					if ( holder ) {
						box.appendChild( holder );
					}
				}
				var node = seg.word
					? el( 'span', {
						className: 'tbtd-w',
						'data-s': String( seg.start ),
						'data-e': String( seg.end ),
						tabindex: mk ? null : '0',
						role: mk ? null : 'button',
						text: side.text.slice( seg.start, seg.end )
					} )
					: document.createTextNode( side.text.slice( seg.start, seg.end ) );
				( holder || box ).appendChild( node );
			} );
		}

		function dot( n ) {
			return el( 'span', { className: 'tbtd-dot tbtd-dot' + n, 'aria-hidden': 'true' } );
		}

		/** A highlight as a chip with its colour, its words and a remove button. */
		function chip( item, sideName, n ) {
			var words = fragment( item[ sideName ], n );
			return el( 'span', { className: 'tbtd-chip-hl tbtd-chip-hl' + n }, [
				dot( n ),
				el( 'span', { text: words } ),
				el( 'button', {
					type: 'button',
					className: 'tbtd-chip-x',
					'aria-label': fmt( t.removeNamed, words ),
					title: t.removeHighlight,
					text: '×',
					onclick: function () { removeMark( item, sideName, n ); }
				} )
			] );
		}

		/** The Polish colour the next selection pairs with, or 0 when all are paired. */
		function plTarget( item ) {
			var missing = missingNumbers( item );
			if ( item.target && missing.indexOf( item.target ) !== -1 ) {
				return item.target;
			}
			return missing.length ? missing[ 0 ] : 0;
		}

		function renderTools( item, sideName ) {
			var box = sideName === 'en' ? item.dom.enTools : item.dom.plTools;
			var side = item[ sideName ];
			box.innerHTML = '';
			if ( ! side.text ) {
				return;
			}

			var chips = el( 'div', { className: 'tbtd-chips' } );
			side.marks.slice().sort( function ( a, b ) { return a.n - b.n; } ).forEach( function ( mk ) {
				chips.appendChild( chip( item, sideName, mk.n ) );
			} );
			if ( chips.childNodes.length ) {
				box.appendChild( chips );
			}

			if ( item.notice[ sideName ] ) {
				box.appendChild( el( 'p', { className: 'tbtd-tools-hint tbtd-tools-hint--warn', role: 'status', text: item.notice[ sideName ] } ) );
			}

			if ( sideName === 'en' ) {
				box.appendChild( el( 'p', {
					className: 'tbtd-tools-hint',
					text: side.marks.length >= MAX_MARKS ? t.allColoursUsed : t.enHint
				} ) );
				return;
			}

			// Polish side.
			if ( ! item.en.marks.length ) {
				box.appendChild( el( 'p', { className: 'tbtd-tools-hint', text: t.plNoMarks } ) );
				return;
			}
			var missing = missingNumbers( item );
			if ( ! missing.length ) {
				box.appendChild( el( 'p', { className: 'tbtd-tools-hint tbtd-tools-hint--ok', text: t.plAllPaired } ) );
				return;
			}
			var target = plTarget( item );
			var line = el( 'div', { className: 'tbtd-target' }, [ el( 'span', { className: 'tbtd-tools-hint', text: t.plSelectFor } ) ] );
			missing.forEach( function ( n ) {
				// With one colour missing there is nothing to choose; with
				// several, each is a button that makes it the next pairing.
				var attrs = {
					className: 'tbtd-target-opt' + ( n === target ? ' is-target' : '' ),
					'aria-pressed': missing.length > 1 ? ( n === target ? 'true' : 'false' ) : null
				};
				var children = [ dot( n ), el( 'span', { text: fragment( item.en, n ) } ) ];
				if ( missing.length > 1 ) {
					attrs.type = 'button';
					attrs.onclick = function () {
						item.target = n;
						renderTools( item, 'pl' );
					};
					line.appendChild( el( 'button', attrs, children ) );
				} else {
					line.appendChild( el( 'span', attrs, children ) );
				}
			} );
			box.appendChild( line );
		}

		function refreshRow( item ) {
			var d = item.dom;
			renderPreview( item, 'en' );
			renderPreview( item, 'pl' );
			renderTools( item, 'en' );
			renderTools( item, 'pl' );

			var status = rowStatus( item );
			var labels = { 'new': t.statusNew, changed: t.statusChanged, done: t.statusDone, missing: t.statusMissing };
			d.status.textContent = labels[ status ] || '';
			d.status.className = 'tbtd-item-status' + ( status ? ' tbtd-item-status--' + status : '' );
			d.row && d.row.classList.toggle( 'is-pending', status === 'new' || status === 'changed' );

			d.translateBtn.textContent = item.pl.text ? t.translateAgain : t.translateOne;
			d.translateBtn.hidden = ! item.en.text;
			d.translateBtn.disabled = state.busy;
		}

		function hasText( item ) {
			return !! item.en.text.trim();
		}

		function liveCount() {
			return state.items.filter( hasText ).length;
		}

		/**
		 * Bring both lists in line with state.items. Stage 2 numbers every
		 * row; Stage 3 holds only sentences with English, numbered in list
		 * order — the numbers the server and the warnings use. Rows already
		 * in place are not touched, so typing never loses focus.
		 */
		function renumber() {
			state.items.forEach( function ( item, i ) {
				item.dom.sn.textContent = String( i + 1 );
				item.dom.enInput.setAttribute( 'aria-label', fmt( t.sentenceLabel, i + 1 ) );
			} );

			var wanted = state.stage3Open ? state.items.filter( hasText ) : [];
			var ref = list.firstChild;
			wanted.forEach( function ( item, i ) {
				item.dom.n.textContent = String( i + 1 );
				if ( item.dom.row === ref ) {
					ref = ref.nextSibling;
				} else {
					list.insertBefore( item.dom.row, ref );
				}
			} );
			while ( ref ) {
				var next = ref.nextSibling;
				list.removeChild( ref );
				ref = next;
			}

			refreshContent();
		}

		/** Stage 2's count, empty state, tools and Continue bar; Stage 3's waiting state. */
		function refreshContent() {
			var live = liveCount();
			$( 'list-count' ).textContent = live === 1 ? t.sentence1 : fmt( t.sentenceN, live );
			$( 'list-empty' ).hidden = state.items.length > 0;
			removeAllBtn.hidden = state.items.length === 0;

			continueBtn.hidden = state.stage3Open;
			continueBtn.disabled = live === 0;
			continueHelp.textContent = state.stage3Open ? t.continueOpen : ( live ? t.continueReady : t.continueEmpty );

			stage3.setAttribute( 'data-state', state.stage3Open ? 'active' : 'waiting' );
			$( 'stage-wait' ).hidden = state.stage3Open;
			$( 'stage-body' ).hidden = ! state.stage3Open;
		}

		/* ---------- editing ---------- */

		function onTextInput( item, sideName ) {
			var input = sideName === 'en' ? item.dom.enInput : item.dom.plInput;
			var cleaned = cleanChars( input.value );
			if ( cleaned !== input.value ) {
				var caret = input.selectionStart;
				input.value = cleaned;
				input.setSelectionRange( caret, caret );
			}
			var side = item[ sideName ];
			var before = numbersOf( side );
			adjustMarks( side, side.text, cleaned );
			item.notice[ sideName ] = '';

			if ( sideName === 'en' ) {
				// An English highlight that vanished takes its Polish partner with it.
				var after = numbersOf( side );
				before.forEach( function ( n ) {
					if ( after.indexOf( n ) === -1 ) {
						dropNumber( item.pl, n );
					}
				} );
			} else if ( item.src === null ) {
				// Polish written by hand is "made from" the English as it is now.
				item.src = serialize( item.en );
			}
			refreshRow( item );
			if ( sideName === 'en' ) {
				// A sentence may have just gained or lost its text: Stage 3
				// shows it or drops it straight away.
				renumber();
			}
			markDirty();
		}

		function freeNumber( item ) {
			var taken = numbersOf( item.en );
			for ( var n = 1; n <= MAX_MARKS; n++ ) {
				if ( taken.indexOf( n ) === -1 ) {
					return n;
				}
			}
			return 0;
		}

		/**
		 * Turn a word range into a highlight at once, or say why it cannot be one.
		 *
		 * As in Drag & Drop, a range touching an existing highlight is refused
		 * rather than merged: silently swallowing a highlight the teacher made
		 * is a worse surprise than being told to remove it first.
		 */
		function commit( item, sideName, start, end ) {
			var side = item[ sideName ];
			var clash = side.marks.some( function ( mk ) { return start < mk.end && mk.start < end; } );
			var n = 0;

			if ( clash ) {
				item.notice[ sideName ] = t.overlap;
			} else if ( sideName === 'en' ) {
				n = freeNumber( item );
				item.notice.en = n ? '' : t.allColoursUsed;
			} else if ( ! item.en.marks.length ) {
				item.notice.pl = t.plNoMarks;
			} else {
				n = plTarget( item );
				item.notice.pl = n ? '' : t.plAllPairedRemove;
			}

			if ( n && ! clash ) {
				side.marks.push( { n: n, start: start, end: end } );
				if ( sideName === 'pl' ) {
					item.target = null;
				}
				refreshRow( item );
				markDirty();
				return;
			}
			renderTools( item, sideName );
		}

		function removeMark( item, sideName, n ) {
			dropNumber( item[ sideName ], n );
			if ( sideName === 'en' ) {
				// The Polish partner of a removed English highlight has nothing
				// left to pair with.
				dropNumber( item.pl, n );
			} else {
				item.target = n;
			}
			// Either side's warning described the highlights as they were.
			item.notice = { en: '', pl: '' };
			refreshRow( item );
			markDirty();
		}

		function findItem( key ) {
			for ( var i = 0; i < state.items.length; i++ ) {
				if ( state.items[ i ].key === key ) {
					return state.items[ i ];
				}
			}
			return null;
		}

		/* ---------- the picker gesture ---------- */

		var drag = null;

		function pickerOf( node ) {
			var preview = node && node.closest ? node.closest( '.tbtd-preview' ) : null;
			if ( ! preview || ! list.contains( preview ) ) {
				return null;
			}
			var item = findItem( preview.getAttribute( 'data-key' ) );
			return item ? { item: item, side: preview.getAttribute( 'data-side' ), preview: preview } : null;
		}

		function wordAt( x, y, preview ) {
			var node = document.elementFromPoint( x, y );
			var word = node && node.closest ? node.closest( '.tbtd-w' ) : null;
			return word && preview.contains( word ) ? word : null;
		}

		function paint( preview, from, to ) {
			var low = Math.min( from, to );
			var high = Math.max( from, to );
			preview.querySelectorAll( '.tbtd-w' ).forEach( function ( w ) {
				var s = parseInt( w.getAttribute( 'data-s' ), 10 );
				w.classList.toggle( 'is-marking', s >= low && s <= high );
			} );
		}

		// Pointer events cover the mouse and a finger alike. The browser's own
		// text selection is suppressed: here a drag means "highlight these
		// words", and a blue selection on top would read as something else.
		list.addEventListener( 'pointerdown', function ( e ) {
			if ( e.button !== 0 ) {
				return;
			}
			var word = e.target.closest ? e.target.closest( '.tbtd-w' ) : null;
			var picker = word ? pickerOf( word ) : null;
			if ( ! picker || word.closest( 'mark' ) ) {
				return;
			}
			e.preventDefault();
			var s = parseInt( word.getAttribute( 'data-s' ), 10 );
			drag = { picker: picker, anchorStart: s, anchorEnd: parseInt( word.getAttribute( 'data-e' ), 10 ), last: word };
			paint( picker.preview, s, s );
		} );

		document.addEventListener( 'pointermove', function ( e ) {
			if ( ! drag ) {
				return;
			}
			var word = wordAt( e.clientX, e.clientY, drag.picker.preview );
			if ( word ) {
				drag.last = word;
				paint( drag.picker.preview, drag.anchorStart, parseInt( word.getAttribute( 'data-s' ), 10 ) );
			}
		} );

		// On document, not on the picker: a drag that ends outside the sentence
		// still has to finish, or the picker would stay stuck mid-gesture.
		document.addEventListener( 'pointerup', function () {
			if ( ! drag ) {
				return;
			}
			var d = drag;
			drag = null;
			var ls = parseInt( d.last.getAttribute( 'data-s' ), 10 );
			var le = parseInt( d.last.getAttribute( 'data-e' ), 10 );
			commit( d.picker.item, d.picker.side, Math.min( d.anchorStart, ls ), Math.max( d.anchorEnd, le ) );
		} );

		document.addEventListener( 'pointercancel', function () {
			if ( drag ) {
				drag.picker.preview.querySelectorAll( '.is-marking' ).forEach( function ( w ) { w.classList.remove( 'is-marking' ); } );
				drag = null;
			}
		} );

		// Keyboard: Enter or Space on a word highlights that word.
		list.addEventListener( 'keydown', function ( e ) {
			if ( e.key !== 'Enter' && e.key !== ' ' ) {
				return;
			}
			var word = e.target.closest ? e.target.closest( '.tbtd-w' ) : null;
			var picker = word ? pickerOf( word ) : null;
			if ( ! picker ) {
				return;
			}
			e.preventDefault();
			commit( picker.item, picker.side, parseInt( word.getAttribute( 'data-s' ), 10 ), parseInt( word.getAttribute( 'data-e' ), 10 ) );
		} );

		/* ---------- adding and removing rows ---------- */

		/**
		 * @param {Object}  item  New item.
		 * @param {boolean} focus Focus its English input.
		 * @param {number}  [at]  Position in the list; the end by default.
		 */
		function addItem( item, focus, at ) {
			buildRows( item );
			if ( at === undefined || at >= state.items.length ) {
				state.items.push( item );
				srows.appendChild( item.dom.srow );
			} else {
				srows.insertBefore( item.dom.srow, state.items[ at ].dom.srow );
				state.items.splice( at, 0, item );
			}
			renumber();
			refreshTranslateBar();
			if ( focus ) {
				item.dom.enInput.focus();
			}
		}

		function dropItem( item ) {
			state.items = state.items.filter( function ( it ) { return it !== item; } );
			item.dom.srow.remove();
			item.dom.row.remove();
		}

		/** The lone empty row a teacher may have added before pasting or generating. */
		function dropEmptyStarter() {
			if ( state.items.length === 1 && ! state.items[ 0 ].en.text && ! state.items[ 0 ].pl.text ) {
				dropItem( state.items[ 0 ] );
			}
		}

		// A new drill may have no rows at all; nothing forces one back.
		function removeItem( item ) {
			var hasContent = item.en.text || item.pl.text || item.note;
			if ( hasContent && ! window.confirm( t.confirmRemove ) ) {
				return;
			}
			dropItem( item );
			renumber();
			refreshTranslateBar();
			markDirty();
		}

		$( 'add' ).addEventListener( 'click', function () {
			if ( liveCount() >= cfg.maxItems ) {
				showError( errorBox, fmt( t.tooManyItems, cfg.maxItems ) );
				return;
			}
			addItem( newItem(), true );
			markDirty();
		} );

		removeAllBtn.addEventListener( 'click', function () {
			var live = liveCount();
			if ( live && ! window.confirm( fmt( t.confirmRemoveAll, live ) ) ) {
				return;
			}
			state.items.slice().forEach( dropItem );
			renumber();
			refreshTranslateBar();
			markDirty();
		} );

		$( 'paste-add' ).addEventListener( 'click', function () {
			var area = $( 'paste' );
			var lines = area.value.split( /\r?\n/ ).map( function ( l ) { return l.trim(); } ).filter( Boolean );
			if ( ! lines.length ) {
				return;
			}
			// Replace a lone empty row rather than leave it above the paste.
			// Marks typed as [expression|n] are kept and show in Stage 3.
			dropEmptyStarter();
			lines.forEach( function ( line ) {
				if ( liveCount() < cfg.maxItems ) {
					addItem( newItem( line ), false );
				}
			} );
			area.value = '';
			$( 'paste-box' ).open = false;
			markDirty();
		} );

		/* ---------- content stage: modes and generation ---------- */

		function chosen( name ) {
			var input = root.querySelector( 'input[name="' + name + '"]:checked' );
			return input ? input.value : '';
		}

		function refreshMode() {
			var ai = $( 'mode-ai' ).checked;
			$( 'compose-ai' ).hidden = ! ai;
			$( 'compose-own' ).hidden = ai;
		}
		root.querySelectorAll( 'input[name="tbtd-mode"]' ).forEach( function ( radio ) {
			radio.addEventListener( 'change', refreshMode );
		} );

		/** Briefly tint rows that just arrived, so the teacher sees where they went. */
		function flagFresh( items ) {
			if ( reduceMotion || ! items.length ) {
				return;
			}
			items.forEach( function ( item ) { item.dom.srow.classList.add( 'is-fresh' ); } );
			window.requestAnimationFrame( function () {
				window.requestAnimationFrame( function () {
					items.forEach( function ( item ) { item.dom.srow.classList.remove( 'is-fresh' ); } );
				} );
			} );
		}

		function generate() {
			showError( errorBox, '' );
			var count = parseInt( chosen( 'tbtd-count' ), 10 ) || 6;
			var level = chosen( 'tbtd-level' ) || 'B1';
			var room = cfg.maxItems - liveCount();
			if ( room <= 0 ) {
				showError( errorBox, fmt( t.tooManyItems, cfg.maxItems ) );
				return;
			}

			root.classList.add( 'is-generating' );
			setBusy( true );
			genStatus.textContent = fmt( t.generating, count, level );

			api( 'POST', 'generate', {
				count: count,
				level: level,
				vocabulary: $( 'vocabulary' ).value,
				grammar: $( 'grammar' ).value,
				avoid: state.items.filter( hasText ).map( function ( item ) { return item.en.text; } )
			} ).then( function ( res ) {
				state.remaining = res.remaining;
				var sentences = res.sentences || [];
				var fit = sentences.slice( 0, Math.max( 0, cfg.maxItems - liveCount() ) );
				var added = [];
				dropEmptyStarter();
				fit.forEach( function ( sentence ) {
					var item = newItem( sentence );
					item.ai = true;
					addItem( item, false );
					added.push( item );
				} );
				flagFresh( added );
				if ( fit.length < sentences.length ) {
					genStatus.textContent = fmt( t.generatedPart, fit.length, sentences.length, cfg.maxItems );
				} else {
					genStatus.textContent = added.length === 1 ? t.generated1 : fmt( t.generatedN, added.length );
				}
				if ( added.length ) {
					markDirty();
				}
			} ).catch( function ( err ) {
				genStatus.textContent = genDefault;
				showError( errorBox, err.code === 'tbtd_quota' ? t.quota : err.message );
			} ).then( function () {
				root.classList.remove( 'is-generating' );
				setBusy( false );
			} );
		}

		generateBtn.addEventListener( 'click', generate );

		continueBtn.addEventListener( 'click', function () {
			if ( ! liveCount() ) {
				return;
			}
			state.stage3Open = true;
			renumber();
			refreshTranslateBar();
			stage3.scrollIntoView( { behavior: reduceMotion ? 'auto' : 'smooth', block: 'start' } );
		} );

		/** Show a sentence where it can be fixed: its Stage 3 row, or its Stage 2 input. */
		function showItem( item ) {
			if ( ! hasText( item ) ) {
				item.dom.srow.scrollIntoView( { behavior: reduceMotion ? 'auto' : 'smooth', block: 'center' } );
				item.dom.enInput.focus( { preventScroll: true } );
				return;
			}
			if ( ! state.stage3Open ) {
				state.stage3Open = true;
				renumber();
				refreshTranslateBar();
			}
			item.dom.row.scrollIntoView( { behavior: reduceMotion ? 'auto' : 'smooth', block: 'center' } );
		}

		/* ---------- translation ---------- */

		function refreshTranslateBar() {
			var pending = state.items.filter( needsTranslation ).length;
			if ( state.busy ) {
				translateBtn.disabled = true;
			} else {
				translateBtn.disabled = pending === 0;
				translateBtn.textContent = pending === 0 ? t.allTranslated : ( pending === 1 ? t.translate1 : fmt( t.translateN, pending ) );
			}
			state.items.forEach( function ( item ) {
				item.dom.translateBtn.disabled = state.busy;
			} );
			generateBtn.disabled = state.busy;
		}

		function setBusy( busy, message ) {
			state.busy = busy;
			root.classList.toggle( 'is-busy', busy );
			saveBtn.disabled = busy;
			publishBtn.disabled = busy;
			translateStatus.textContent = message || remainingLine();
			refreshTranslateBar();
		}

		function remainingLine() {
			return state.remaining === null || state.remaining === undefined ? defaultStatus : fmt( t.remaining, state.remaining );
		}

		function translate( items ) {
			showError( errorBox, '' );
			var todo = items.filter( hasText );
			if ( ! todo.length ) {
				showError( errorBox, t.noSentences );
				return;
			}

			var chunks = [];
			for ( var i = 0; i < todo.length; i += cfg.maxSentences ) {
				chunks.push( todo.slice( i, i + cfg.maxSentences ) );
			}

			setBusy( true, t.translating );
			todo.forEach( function ( item ) { item.dom.row.classList.add( 'is-working' ); } );

			var run = chunks.reduce( function ( chain, chunk ) {
				return chain.then( function () {
					return api( 'POST', 'translate', {
						sentences: chunk.map( function ( item ) { return serialize( item.en ); } )
					} ).then( function ( res ) {
						state.remaining = res.remaining;
						( res.items || [] ).forEach( function ( out, idx ) {
							var item = chunk[ idx ];
							if ( ! item ) {
								return;
							}
							var pl = parseMarkup( out.pl );
							var allowed = numbersOf( item.en );
							pl.marks = pl.marks.filter( function ( mk ) { return allowed.indexOf( mk.n ) !== -1; } );
							item.pl = pl;
							item.note = out.note || '';
							item.src = serialize( item.en );
							item.notice = { en: '', pl: '' };
							item.target = null;
							item.dom.plInput.value = pl.text;
							item.dom.note.value = item.note;
							item.dom.row.classList.remove( 'is-working' );
							refreshRow( item );
						} );
					} );
				} );
			}, Promise.resolve() );

			run.then( function () {
				markDirty();
			} ).catch( function ( err ) {
				showError( errorBox, err.code === 'tbtd_quota' ? t.quota : err.message );
			} ).then( function () {
				todo.forEach( function ( item ) { item.dom.row.classList.remove( 'is-working' ); } );
				setBusy( false );
				state.items.forEach( refreshRow );
			} );
		}

		translateBtn.addEventListener( 'click', function () {
			translate( state.items.filter( needsTranslation ) );
		} );

		/* ---------- class and lesson ---------- */

		/**
		 * The class the typed text stands for.
		 *
		 * The datalist hands back a name, so the id it means is looked up here.
		 * A name that matches nothing resolves to no class — deliberately, so a
		 * half-typed name can never attach a drill to the wrong class.
		 *
		 * @param {string} text What the teacher typed.
		 * @return {HTMLOptionElement|null} The matching entry, if there is one.
		 */
		function classOptionFor( text ) {
			var wanted = ( text || '' ).trim().toLowerCase();
			var found = null;

			if ( ! wanted || ! classList ) {
				return null;
			}

			classList.querySelectorAll( 'option' ).forEach( function ( option ) {
				if ( ! found && option.value.trim().toLowerCase() === wanted ) {
					found = option;
				}
			} );

			return found;
		}

		function setClass( id ) {
			if ( classIdField ) {
				classIdField.value = id || '';
			}
		}

		/**
		 * Load a class's lessons: newest first, "Any lesson" last, the newest
		 * selected. The lesson stays optional, so a class with no lessons keeps
		 * only "Any lesson" rather than being taken out of play as in Swipe.
		 *
		 * @param {string} classId Class ID.
		 */
		function attachClass( classId ) {
			setClass( classId );

			api( 'GET', 'classes/' + encodeURIComponent( classId ) + '/lessons' ).then( function ( lessons ) {
				// The teacher picked another class while this was in flight.
				if ( ! classIdField || classIdField.value !== String( classId ) ) {
					return;
				}
				// The route returns lessons newest first, so option 0 is the
				// most recent and no sorting happens here.
				lessonSelect.innerHTML = '';
				( lessons || [] ).forEach( function ( lesson ) {
					lessonSelect.appendChild( el( 'option', { value: String( lesson.id ), text: lesson.title } ) );
				} );
				lessonSelect.appendChild( el( 'option', { value: '0', text: t.noLesson } ) );
				lessonSelect.selectedIndex = 0;
				lessonWrap.hidden = false;
			} ).catch( function ( err ) {
				if ( ! classIdField || classIdField.value !== String( classId ) ) {
					return;
				}
				lessonWrap.hidden = true;
				setClass( '' );
				showError( errorBox, err.message );
			} );
		}

		if ( classInput && lessonSelect && lessonWrap ) {
			// 'change' rather than 'input': it fires both when a suggestion is
			// picked and when the field is left, so the lessons load once
			// instead of on every keystroke.
			classInput.addEventListener( 'change', function () {
				var typed = classInput.value.trim();
				var option = classOptionFor( typed );

				setClass( '' );
				if ( classHint ) {
					classHint.hidden = true;
				}
				lessonWrap.hidden = true;
				markDirty();

				if ( ! typed ) {
					return;
				}

				if ( ! option ) {
					// Typed, but not one of this teacher's classes. Say so; the
					// drill saves unattached unless a class is picked, as in Swipe.
					if ( classHint ) {
						classHint.hidden = false;
					}
					return;
				}

				attachClass( option.getAttribute( 'data-id' ) );
			} );
			lessonSelect.addEventListener( 'change', markDirty );
		}
		titleInput.addEventListener( 'input', markDirty );

		/** The help line under the class field says what a class does or does not change. */
		function refreshClassHelp() {
			if ( classHelp && classInput ) {
				classHelp.textContent = classOptionFor( classInput.value ) ? t.classSet : t.classNone;
			}
		}
		if ( classInput ) {
			classInput.addEventListener( 'input', refreshClassHelp );
			classInput.addEventListener( 'change', refreshClassHelp );
		}

		/* ---------- approve, save, share ---------- */

		function collectWarnings() {
			var out = [];
			// A sentence whose English was cleared but whose Polish is still
			// there cannot be saved; it is fixed in Stage 2.
			state.items.forEach( function ( item, i ) {
				if ( ! hasText( item ) && item.pl.text.trim() ) {
					out.push( { item: item, text: fmt( t.missingEn, i + 1 ), blocking: true } );
				}
			} );
			// Numbered as in Stage 3: sentences with English, in list order.
			state.items.filter( hasText ).forEach( function ( item, i ) {
				if ( ! item.pl.text ) {
					out.push( { item: item, text: fmt( t.missingPl, i + 1 ), blocking: true } );
					return;
				}
				missingNumbers( item ).forEach( function ( n ) {
					out.push( { item: item, text: fmt( t.unpairedLine, i + 1, fragment( item.en, n ) ), blocking: false } );
				} );
			} );
			return out;
		}

		function refreshApprove() {
			var live = state.status === 'published';
			var list3 = collectWarnings();

			warnings.innerHTML = '';
			list3.forEach( function ( w ) {
				warnings.appendChild( el( 'li', { className: w.blocking ? 'is-blocking' : '' }, [
					el( 'button', {
						type: 'button',
						className: 'tbtd-linkbtn',
						text: w.text,
						onclick: function () { showItem( w.item ); }
					} )
				] ) );
			} );
			warnings.hidden = ! list3.length;

			stateLine.textContent = ! state.id ? t.stateNew : ( live ? t.stateLive : t.stateDraft );
			saveBtn.textContent = live ? t.saveChanges : t.saveDraft;
			saveBtn.hidden = live;
			publishBtn.textContent = live ? t.saveChanges : t.publish;
			unpublishBtn.hidden = ! live;

			previewLink.hidden = ! state.url;
			if ( state.url ) {
				previewLink.href = state.url;
			}

			share.hidden = ! ( live && state.url );
			if ( anotherRow ) {
				anotherRow.hidden = share.hidden;
			}
			if ( live && state.url ) {
				var urlInput = $( 'share-url' );
				if ( urlInput.value !== state.url ) {
					urlInput.value = state.url;
					$( 'share-open' ).href = state.url;
					drawQr( $( 'share-qr' ), state.url );
				}
			}
		}

		function payload( status ) {
			return {
				id: state.id || 0,
				title: titleInput.value,
				class_id: classIdField ? parseInt( classIdField.value, 10 ) || 0 : 0,
				lesson_id: ( lessonSelect && lessonWrap && ! lessonWrap.hidden ) ? parseInt( lessonSelect.value, 10 ) || 0 : 0,
				status: status,
				items: state.items.filter( function ( item ) {
					return hasText( item ) || item.pl.text;
				} ).map( function ( item ) {
					return { en: serialize( item.en ), pl: serialize( item.pl ), note: item.note };
				} )
			};
		}

		function save( status ) {
			showError( errorBox, '' );
			if ( ! titleInput.value.trim() ) {
				showError( errorBox, t.needTitle );
				titleInput.focus();
				return;
			}
			var body = payload( status );
			if ( body.items.length > cfg.maxItems ) {
				showError( errorBox, fmt( t.tooManyItems, cfg.maxItems ) );
				return;
			}

			if ( status === 'published' ) {
				if ( ! body.items.length ) {
					showError( errorBox, t.noSentences );
					return;
				}
				var list3 = collectWarnings();
				var blocking = list3.filter( function ( w ) { return w.blocking; } );
				if ( blocking.length ) {
					showError( errorBox, blocking[ 0 ].text );
					showItem( blocking[ 0 ].item );
					return;
				}
				if ( list3.length && ! window.confirm( t.unpairedConfirm ) ) {
					return;
				}
			}

			setBusy( true, '' );
			savedMsg.textContent = t.saving;
			var wasLive = state.status === 'published';

			api( 'POST', 'drills', body ).then( function ( drill ) {
				var wasNew = ! state.id;
				state.id = drill.id;
				state.slug = drill.slug;
				state.status = drill.status;
				state.url = drill.url || '';
				state.dirty = false;

				if ( wasNew && window.history && window.history.replaceState ) {
					var url = new URL( window.location.href );
					url.searchParams.delete( 'tbtd_new' );
					url.searchParams.set( cfg.editParam, String( drill.id ) );
					window.history.replaceState( null, '', url.toString() );
				}

				if ( ! state.url && ! cfg.playerReady ) {
					savedMsg.textContent = t.noPlayerPage;
				} else if ( status === 'published' ) {
					savedMsg.textContent = wasLive ? t.savedLive : t.published;
				} else {
					savedMsg.textContent = t.savedDraft;
				}
				refreshApprove();
				if ( status === 'published' && ! share.hidden ) {
					share.scrollIntoView( { behavior: 'smooth', block: 'center' } );
				}
			} ).catch( function ( err ) {
				savedMsg.textContent = '';
				showError( errorBox, err.message );
			} ).then( function () {
				setBusy( false );
			} );
		}

		saveBtn.addEventListener( 'click', function () { save( 'draft' ); } );
		publishBtn.addEventListener( 'click', function () { save( 'published' ); } );
		unpublishBtn.addEventListener( 'click', function () {
			if ( window.confirm( t.unpublishConfirm ) ) {
				save( 'draft' );
			}
		} );
		$( 'share-copy' ).addEventListener( 'click', function ( e ) {
			copyText( state.url, e.currentTarget );
		} );

		/* ---------- start ---------- */

		if ( initial && initial.items && initial.items.length ) {
			initial.items.forEach( function ( it ) {
				addItem( newItem( it.en, it.pl, it.note, true ), false );
			} );
		}
		// Defaults: a new drill on "Generate with AI", a saved one on "Write
		// my own". The markup says the same; this keeps a restored form honest.
		$( initial ? 'mode-own' : 'mode-ai' ).checked = true;
		refreshMode();
		refreshClassHelp();
		renumber();
		setBusy( false );
		refreshApprove();
		state.dirty = false;
	}

	/* ------------------------------------------------------------------ */

	function start() {
		var library = document.querySelector( '.tbtd-teacher [data-role="library"]' );
		var builder = document.querySelector( '.tbtd-teacher [data-role="builder"]' );
		if ( library ) {
			initLibrary( library );
		}
		if ( builder ) {
			initBuilder( builder );
		}
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', start );
	} else {
		start();
	}
}() );
