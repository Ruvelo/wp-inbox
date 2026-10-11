/**
 * Ruvelo Inbox: the bell's dropdown, live counts over the WordPress
 * Heartbeat, and small conveniences on the inbox page. Without this file,
 * the bell is a link to the inbox and every button is a plain form.
 * Plain JavaScript, no build step.
 */
( function () {
	'use strict';

	var settings = window.ruveloInbox || {};
	var __ = window.wp && wp.i18n ? wp.i18n.__ : function ( s ) { return s; };
	var _n = window.wp && wp.i18n ? wp.i18n._n : function ( s, p, n ) { return n === 1 ? s : p; };
	var sprintf = window.wp && wp.i18n ? wp.i18n.sprintf : function ( s ) { return s; };
	var bells = [];

	function badge( n ) {
		return n > 99 ? '99+' : String( n );
	}

	function speak( message ) {
		if ( window.wp && wp.a11y && wp.a11y.speak ) {
			wp.a11y.speak( message, 'polite' );
		}
	}

	function api( path, method ) {
		if ( ! settings.rest || ! window.fetch ) {
			return Promise.reject();
		}
		return fetch( settings.rest + path, {
			method: method || 'POST',
			credentials: 'same-origin',
			keepalive: true,
			headers: { 'X-WP-Nonce': settings.nonce || '', Accept: 'application/json' },
		} ).then( function ( r ) {
			return r.ok ? r.json() : Promise.reject( r );
		} );
	}

	// Every place that shows the count: bells, the admin menu bubble, the inbox page.
	function setCount( n ) {
		n = Math.max( 0, n );
		bells.forEach( function ( bell ) {
			bell.setCount( n );
		} );
		document.querySelectorAll( '[data-ruvelo-inbox-menu-count]' ).forEach( function ( el ) {
			el.className = el.className.replace( /count-\d+/, 'count-' + n );
			var visible = el.querySelector( '.pending-count' );
			if ( visible ) {
				visible.textContent = badge( n );
			}
			el.hidden = n === 0;
		} );
		document.querySelectorAll( '[data-ruvelo-inbox-count]' ).forEach( function ( el ) {
			el.textContent = badge( n );
			el.hidden = n === 0;
		} );
	}

	function Bell( root ) {
		var button = root.querySelector( '[data-ruvelo-inbox-toggle]' );
		var panel = root.querySelector( '[data-ruvelo-inbox-panel]' );
		var badgeEl = root.querySelector( '[data-ruvelo-inbox-badge]' );
		var list = root.querySelector( '[data-ruvelo-inbox-list]' );
		var readAll = root.querySelector( '[data-ruvelo-inbox-read-all]' );
		var label = root.getAttribute( 'data-label' ) || __( 'Notifications', 'ruvelo-inbox' );
		var self = this;

		this.root = root;
		this.count = parseInt( root.getAttribute( 'data-unread' ), 10 ) || 0;

		button.setAttribute( 'role', 'button' );
		button.setAttribute( 'aria-expanded', 'false' );

		this.setCount = function ( n ) {
			self.count = n;
			badgeEl.textContent = badge( n );
			badgeEl.hidden = n === 0;
			/* translators: 1: "Notifications", 2: number unread */
			button.setAttribute( 'aria-label', n > 0 ? sprintf( _n( '%1$s, %2$d unread', '%1$s, %2$d unread', n, 'ruvelo-inbox' ), label, n ) : label );
			var submit = readAll && readAll.querySelector( 'button' );
			if ( submit ) {
				submit.disabled = n === 0;
			}
		};

		this.setList = function ( html ) {
			list.innerHTML = html;
		};

		function isOpen() {
			return ! panel.hidden;
		}

		function open() {
			panel.hidden = false;
			button.setAttribute( 'aria-expanded', 'true' );
			panel.focus( { preventScroll: true } );
		}

		function close( refocus ) {
			if ( ! isOpen() ) {
				return;
			}
			panel.hidden = true;
			button.setAttribute( 'aria-expanded', 'false' );
			if ( refocus ) {
				button.focus();
			}
		}

		function markItem( item ) {
			if ( ! item.hasAttribute( 'data-unread' ) ) {
				return false;
			}
			item.removeAttribute( 'data-unread' );
			item.classList.remove( 'is-unread' );
			var dot = item.querySelector( '.ruvelo-inbox-dot' );
			if ( dot ) {
				dot.remove();
			}
			return true;
		}

		button.addEventListener( 'click', function ( e ) {
			if ( e.metaKey || e.ctrlKey || e.shiftKey || e.button === 1 ) {
				return;
			}
			e.preventDefault();
			if ( isOpen() ) {
				close( false );
			} else {
				open();
			}
		} );
		button.addEventListener( 'keydown', function ( e ) {
			if ( e.key === ' ' ) {
				e.preventDefault();
				button.click();
			}
			if ( e.key === 'ArrowDown' && ! isOpen() ) {
				e.preventDefault();
				open();
			}
		} );
		root.addEventListener( 'keydown', function ( e ) {
			if ( e.key === 'Escape' && isOpen() ) {
				e.preventDefault();
				e.stopPropagation();
				close( true );
			}
		} );
		document.addEventListener( 'click', function ( e ) {
			if ( ! root.contains( e.target ) ) {
				close( false );
			}
		} );
		root.addEventListener( 'focusout', function ( e ) {
			if ( e.relatedTarget && ! root.contains( e.relatedTarget ) ) {
				close( false );
			}
		} );

		// Opening an item marks it read in the background, then goes straight to it.
		list.addEventListener( 'click', function ( e ) {
			var item = e.target.closest( '[data-ruvelo-inbox-item]' );
			if ( ! item || e.metaKey || e.ctrlKey || e.shiftKey || e.button === 1 ) {
				return;
			}
			var url = item.getAttribute( 'data-url' );
			if ( ! url ) {
				return; // The link marks it read on the server, then shows the inbox.
			}
			e.preventDefault();
			var go = function () {
				window.location.assign( url );
			};
			if ( markItem( item ) ) {
				setCount( self.count - 1 );
				api( 'notifications/' + item.getAttribute( 'data-ruvelo-inbox-item' ) + '/read' ).then( go, go );
			} else {
				go();
			}
		} );

		if ( readAll ) {
			readAll.addEventListener( 'submit', function ( e ) {
				e.preventDefault();
				list.querySelectorAll( '[data-ruvelo-inbox-item][data-unread]' ).forEach( markItem );
				setCount( 0 );
				speak( __( 'All notifications marked as read.', 'ruvelo-inbox' ) );
				api( 'notifications/read-all' ).then( function ( json ) {
					if ( json && typeof json.unread === 'number' ) {
						setCount( json.unread );
					}
				}, function () {
					readAll.submit();
				} );
			} );
		}
	}

	function latest() {
		var max = 0;
		bells.forEach( function ( bell ) {
			max = Math.max( max, parseInt( bell.root.getAttribute( 'data-latest' ), 10 ) || 0 );
		} );
		return max;
	}

	// Live updates over the Heartbeat the admin already runs: no polling of our own.
	function heartbeat() {
		if ( ! window.jQuery || ! bells.length ) {
			return;
		}
		jQuery( document ).on( 'heartbeat-send.ruveloInbox', function ( e, data ) {
			data.ruvelo_inbox = { latest: latest(), base: window.location.href };
		} );
		jQuery( document ).on( 'heartbeat-tick.ruveloInbox', function ( e, data ) {
			var payload = data && data.ruvelo_inbox;
			if ( ! payload ) {
				return;
			}
			if ( typeof payload.html === 'string' ) {
				bells.forEach( function ( bell ) {
					bell.setList( payload.html );
					bell.root.setAttribute( 'data-latest', String( payload.latest ) );
				} );
			}
			setCount( payload.unread );
			if ( payload.fresh > 0 ) {
				/* translators: %d: number of new notifications */
				speak( sprintf( _n( '%d new notification', '%d new notifications', payload.fresh, 'ruvelo-inbox' ), payload.fresh ) );
			}
		} );
	}

	function page( root ) {
		var filter = root.querySelector( '[data-ruvelo-inbox-filter]' );
		if ( filter ) {
			var submit = filter.querySelector( '[data-ruvelo-inbox-filter-submit]' );
			var select = filter.querySelector( 'select' );
			if ( submit && select ) {
				submit.hidden = true;
				select.addEventListener( 'change', function () {
					filter.submit();
				} );
			}
		}

		var bulk = root.querySelector( '[data-ruvelo-inbox-bulk]' );
		var all = root.querySelector( '[data-ruvelo-inbox-select-all]' );
		if ( bulk && all ) {
			var boxes = root.querySelectorAll( '.ruvelo-inbox-select' );
			var allLabel = root.querySelector( '[data-ruvelo-inbox-select-all-label]' );
			all.hidden = false;
			if ( allLabel ) {
				allLabel.hidden = false;
			}
			all.addEventListener( 'change', function () {
				boxes.forEach( function ( box ) {
					box.checked = all.checked;
				} );
			} );
			bulk.addEventListener( 'submit', function ( e ) {
				var action = bulk.querySelector( 'select' ).value;
				var chosen = Array.prototype.filter.call( boxes, function ( box ) {
					return box.checked;
				} ).length;
				if ( ! action || ! chosen ) {
					e.preventDefault();
					speak( __( 'Choose some notifications and an action first.', 'ruvelo-inbox' ) );
					return;
				}
				/* translators: %d: number of notifications */
				if ( action === 'delete' && ! window.confirm( sprintf( _n( 'Delete %d notification?', 'Delete %d notifications?', chosen, 'ruvelo-inbox' ), chosen ) ) ) {
					e.preventDefault();
				}
			} );
		}
	}

	function boot() {
		document.querySelectorAll( '[data-ruvelo-inbox-bell]' ).forEach( function ( root ) {
			if ( ! root.getAttribute( 'data-ready' ) ) {
				root.setAttribute( 'data-ready', '1' );
				bells.push( new Bell( root ) );
			}
		} );
		document.querySelectorAll( '[data-ruvelo-inbox-page]' ).forEach( page );
		heartbeat();
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', boot );
	} else {
		boot();
	}
}() );
