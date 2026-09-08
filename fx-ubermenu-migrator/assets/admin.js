/**
 * Admin behaviour for FX UberMenu Migrator.
 *
 * Progressive enhancement only: every control still submits without JS.
 */
(function () {
	'use strict';

	var DATA = window.fxUberMenuData || {};

	function ready( fn ) {
		if ( 'loading' !== document.readyState ) {
			fn();
			return;
		}
		document.addEventListener( 'DOMContentLoaded', fn );
	}

	function debounce( fn, wait ) {
		var timer = null;
		return function () {
			window.clearTimeout( timer );
			timer = window.setTimeout( fn, wait );
		};
	}

	function formatBytes( bytes ) {
		if ( ! bytes ) {
			return '0 KB';
		}
		if ( bytes < 1024 * 1024 ) {
			return Math.max( 1, Math.round( bytes / 1024 ) ) + ' KB';
		}
		return ( bytes / ( 1024 * 1024 ) ).toFixed( 1 ) + ' MB';
	}

	function plural( count, single, many ) {
		return count + ' ' + ( 1 === count ? single : many );
	}

	function el( tag, className, text ) {
		var node = document.createElement( tag );
		if ( className ) {
			node.className = className;
		}
		if ( undefined !== text && null !== text ) {
			node.textContent = text;
		}
		return node;
	}

	function phaseLabel( progress ) {
		var phases = {
			menu: 'Importing menus',
			item: 'Importing menu items',
			option: 'Applying UberMenu settings',
			location: 'Assigning theme locations',
			start: 'Reading the pack',
			finish: 'Finishing up'
		};
		var label = phases[ progress.phase ] || 'Working';

		if ( 'start' === progress.phase || 'finish' === progress.phase ) {
			return label;
		}
		return label + ' (' + progress.done + ' of ' + progress.total + ')';
	}

	/* -------------------------------------------------- Menu picker */

	function initPicker( picker ) {
		var form = picker.closest( 'form' );
		var list = picker.querySelector( '[data-fx-um="list"]' );
		var rows = Array.prototype.slice.call( picker.querySelectorAll( '[data-fx-um="row"]' ) );
		var empty = picker.querySelector( '[data-fx-um="empty"]' );
		var search = picker.querySelector( '[data-fx-um="search"]' );
		var kind = picker.querySelector( '[data-fx-um="kind"]' );
		var sort = picker.querySelector( '[data-fx-um="sort"]' );
		var counter = picker.querySelector( '[data-fx-um="count"]' );
		var shown = picker.querySelector( '[data-fx-um="shown"]' );
		var submit = form ? form.querySelector( '[data-fx-um="submit"]' ) : null;
		var error = form ? form.querySelector( '[data-fx-um="error"]' ) : null;

		if ( ! list || ! rows.length ) {
			return;
		}

		function radioOf( row ) {
			return row.querySelector( 'input[type="radio"]' );
		}

		function selectedRow() {
			for ( var i = 0; i < rows.length; i++ ) {
				var radio = radioOf( rows[ i ] );
				if ( radio && radio.checked ) {
					return rows[ i ];
				}
			}
			return null;
		}

		function matches( row ) {
			var term = search ? search.value.trim().toLowerCase() : '';
			var wanted = kind ? kind.value : '';

			if ( wanted && row.getAttribute( 'data-kind' ) !== wanted ) {
				return false;
			}
			if ( term && -1 === row.getAttribute( 'data-search' ).indexOf( term ) ) {
				return false;
			}
			return true;
		}

		function applyFilters() {
			var visible = 0;

			rows.forEach( function ( row ) {
				var ok = matches( row );
				row.hidden = ! ok;
				if ( ok ) {
					visible++;
				}
			} );

			if ( empty ) {
				empty.hidden = visible > 0;
			}
			if ( shown ) {
				shown.textContent = plural( visible, 'menu shown', 'menus shown' );
			}
		}

		function applySort() {
			var mode = sort ? sort.value : 'name-asc';
			var sorted = rows.slice();

			sorted.sort( function ( a, b ) {
				switch ( mode ) {
					case 'name-desc':
						return b.getAttribute( 'data-name' ).localeCompare( a.getAttribute( 'data-name' ) );
					case 'items-desc':
						return Number( b.getAttribute( 'data-items' ) ) - Number( a.getAttribute( 'data-items' ) );
					case 'items-asc':
						return Number( a.getAttribute( 'data-items' ) ) - Number( b.getAttribute( 'data-items' ) );
					case 'assigned-first':
						return Number( b.getAttribute( 'data-assigned' ) ) - Number( a.getAttribute( 'data-assigned' ) ) ||
							a.getAttribute( 'data-name' ).localeCompare( b.getAttribute( 'data-name' ) );
					default:
						return a.getAttribute( 'data-name' ).localeCompare( b.getAttribute( 'data-name' ) );
				}
			} );

			sorted.forEach( function ( row ) {
				list.appendChild( row );
			} );
			rows = sorted;
		}

		function updateSelection() {
			var selected = selectedRow();
			var name = selected ? selected.getAttribute( 'data-name' ) : '';

			rows.forEach( function ( row ) {
				var radio = radioOf( row );
				row.classList.toggle( 'is-selected', !! ( radio && radio.checked ) );
			} );

			if ( counter ) {
				counter.textContent = selected ? 'Selected: ' + name : 'No menu selected';
			}
			if ( submit ) {
				submit.value = selected ? 'Download "' + name + '" export' : 'Download JSON export';
			}
			if ( error && selected ) {
				error.hidden = true;
			}
		}

		list.addEventListener( 'change', function ( event ) {
			if ( event.target && 'radio' === event.target.type ) {
				updateSelection();
			}
		} );

		if ( search ) {
			search.addEventListener( 'input', debounce( applyFilters, 120 ) );
			search.addEventListener( 'search', applyFilters );
		}
		if ( kind ) {
			kind.addEventListener( 'change', applyFilters );
		}
		if ( sort ) {
			sort.addEventListener( 'change', applySort );
		}

		picker.addEventListener( 'click', function ( event ) {
			var action = event.target.getAttribute ? event.target.getAttribute( 'data-fx-um-action' ) : null;
			if ( 'reset-filters' !== action ) {
				return;
			}
			event.preventDefault();
			if ( search ) {
				search.value = '';
			}
			if ( kind ) {
				kind.value = '';
			}
			applyFilters();
		} );

		if ( form ) {
			form.addEventListener( 'submit', function ( event ) {
				if ( selectedRow() ) {
					return;
				}
				event.preventDefault();
				if ( error ) {
					error.hidden = false;
				}
				if ( search ) {
					search.focus();
				}
			} );
		}

		applySort();
		applyFilters();
		updateSelection();
	}

	/* -------------------------------------------------- Import card */

	function initImport( card ) {
		var drop = card.querySelector( '[data-fx-um="drop"]' );
		var input = card.querySelector( '[data-fx-um="file"]' );
		var info = card.querySelector( '[data-fx-um="file-info"]' );
		var dryRun = card.querySelector( '[data-fx-um="dry-run"]' );
		var mode = card.querySelector( '[data-fx-um="mode"]' );
		var form = card.querySelector( 'form' );
		var submit = form ? form.querySelector( '[data-fx-um="submit"]' ) : null;
		var progress = card.querySelector( '[data-fx-um="progress"]' );
		var progressPhase = card.querySelector( '[data-fx-um="progress-phase"]' );
		var progressPct = card.querySelector( '[data-fx-um="progress-pct"]' );
		var progressTrack = card.querySelector( '[data-fx-um="progress-track"]' );
		var progressBar = card.querySelector( '[data-fx-um="progress-bar"]' );
		var progressLabel = card.querySelector( '[data-fx-um="progress-label"]' );
		var importError = card.querySelector( '[data-fx-um="import-error"]' );
		var reportTarget = document.querySelector( '[data-fx-um="report-target"]' );
		var submitLabel = submit ? submit.value : '';
		var tooLarge = false;

		function canStream() {
			return !! (
				window.FormData &&
				window.XMLHttpRequest &&
				DATA.ajaxUrl &&
				DATA.action &&
				DATA.nonce &&
				input &&
				input.files &&
				input.files.length
			);
		}

		function setProgress( percent, phase, label ) {
			if ( ! progress ) {
				return;
			}
			progress.hidden = false;
			progress.classList.remove( 'is-failed' );

			if ( null === percent ) {
				progress.classList.add( 'is-indeterminate' );
				if ( progressPct ) {
					progressPct.textContent = '';
				}
			} else {
				progress.classList.remove( 'is-indeterminate' );
				if ( progressBar ) {
					progressBar.style.width = percent + '%';
				}
				if ( progressPct ) {
					progressPct.textContent = percent + '%';
				}
				if ( progressTrack ) {
					progressTrack.setAttribute( 'aria-valuenow', String( percent ) );
				}
			}

			if ( progressPhase && phase ) {
				progressPhase.textContent = phase;
			}
			if ( progressLabel ) {
				progressLabel.textContent = label || '';
			}
		}

		function showError( message ) {
			if ( progress ) {
				progress.classList.remove( 'is-indeterminate' );
				progress.classList.add( 'is-failed' );
			}
			if ( importError ) {
				importError.hidden = false;
				importError.textContent = message;
			}
			if ( submit ) {
				submit.classList.remove( 'disabled' );
				submit.disabled = false;
				submit.value = submitLabel;
			}
		}

		function describe( file ) {
			if ( ! info ) {
				return;
			}
			tooLarge = false;
			info.hidden = false;
			info.classList.remove( 'is-invalid' );
			info.innerHTML = '';

			var name = document.createElement( 'strong' );
			name.textContent = file.name;
			info.appendChild( name );

			var meta = document.createElement( 'span' );
			meta.textContent = formatBytes( file.size ) + ' - reading pack...';
			info.appendChild( meta );

			if ( DATA.maxUpload && file.size > DATA.maxUpload ) {
				tooLarge = true;
				info.classList.add( 'is-invalid' );
				meta.textContent = formatBytes( file.size ) + ' - too large for this server, which accepts up to ' +
					( DATA.maxUploadLabel || 'its configured limit' ) +
					'. Raise the PHP upload limits, or import this pack with the WP-CLI command under Advanced below.';
				return;
			}

			if ( file.size > 12 * 1024 * 1024 ) {
				meta.textContent = formatBytes( file.size ) + ' - ready to upload. Large packs are checked during the import itself.';
				return;
			}

			var reader = new FileReader();
			reader.onload = function () {
				var pack;
				try {
					pack = JSON.parse( reader.result );
				} catch ( err ) {
					info.classList.add( 'is-invalid' );
					meta.textContent = formatBytes( file.size ) + ' - this file is not valid JSON.';
					return;
				}

				if ( ! pack || 'fx-ubermenu-pack' !== pack.format ) {
					info.classList.add( 'is-invalid' );
					meta.textContent = formatBytes( file.size ) + ' - not an UberMenu pack. Export one from the other environment first.';
					return;
				}

				var menus = pack.menus || [];
				var itemCount = 0;
				menus.forEach( function ( menu ) {
					itemCount += ( menu.items || [] ).length;
				} );

				var parts = [
					plural( menus.length, 'menu', 'menus' ),
					plural( itemCount, 'item', 'items' )
				];
				if ( pack.theme_locations && Object.keys( pack.theme_locations ).length ) {
					parts.push( plural( Object.keys( pack.theme_locations ).length, 'theme location', 'theme locations' ) );
				}
				var from = pack.source && pack.source.home ? ' - from ' + pack.source.home : '';
				meta.textContent = formatBytes( file.size ) + ' - contains ' + parts.join( ', ' ) + from;
			};
			reader.onerror = function () {
				info.classList.add( 'is-invalid' );
				meta.textContent = formatBytes( file.size ) + ' - could not be read.';
			};
			reader.readAsText( file );
		}

		function updateMode() {
			if ( ! mode || ! dryRun ) {
				return;
			}
			if ( dryRun.checked ) {
				mode.classList.remove( 'is-live' );
				mode.textContent = 'Safe preview: nothing will be written. Review the report, then uncheck Dry run to import for real.';
			} else {
				mode.classList.add( 'is-live' );
				mode.textContent = 'Live import: menus, UberMenu settings, and theme locations will be written to this site.';
			}
			if ( submit ) {
				submit.value = dryRun.checked ? 'Preview import (dry run)' : 'Run live import';
			}
		}

		if ( input ) {
			input.addEventListener( 'change', function () {
				if ( input.files && input.files.length ) {
					describe( input.files[ 0 ] );
				} else if ( info ) {
					info.hidden = true;
				}
			} );
		}

		if ( drop ) {
			[ 'dragenter', 'dragover' ].forEach( function ( name ) {
				drop.addEventListener( name, function ( event ) {
					event.preventDefault();
					drop.classList.add( 'is-dragover' );
				} );
			} );
			[ 'dragleave', 'drop' ].forEach( function ( name ) {
				drop.addEventListener( name, function () {
					drop.classList.remove( 'is-dragover' );
				} );
			} );
			drop.addEventListener( 'drop', function ( event ) {
				if ( ! event.dataTransfer || ! event.dataTransfer.files.length || ! input ) {
					return;
				}
				event.preventDefault();
				input.files = event.dataTransfer.files;
				describe( input.files[ 0 ] );
			} );
		}

		if ( dryRun ) {
			dryRun.addEventListener( 'change', updateMode );
			updateMode();
		}

		function stream() {
			var data = new FormData( form );
			var xhr = new XMLHttpRequest();
			var offset = 0;
			var finished = false;

			data.append( 'action', DATA.action );
			data.append( 'nonce', DATA.nonce );

			function handle( event ) {
				if ( 'progress' === event.t ) {
					setProgress( event.percent, phaseLabel( event ), event.label );
				} else if ( 'done' === event.t ) {
					finished = true;
					setProgress( 100, event.dry_run ? 'Dry run complete' : 'Import complete', plural( ( event.report || [] ).length, 'report row', 'report rows' ) );
					if ( progress ) {
						progress.classList.add( 'is-done' );
					}
					if ( submit ) {
						submit.classList.remove( 'disabled' );
						submit.disabled = false;
						submit.value = submitLabel;
					}
					renderReport( reportTarget, event );
				} else if ( 'error' === event.t ) {
					finished = true;
					showError( event.message || 'The import failed.' );
				}
			}

			function consume() {
				var text = xhr.responseText;
				var nl;
				var line;

				if ( ! text ) {
					return;
				}
				while ( -1 !== ( nl = text.indexOf( '\n', offset ) ) ) {
					line = text.slice( offset, nl ).trim();
					offset = nl + 1;
					if ( ! line ) {
						continue;
					}
					try {
						handle( JSON.parse( line ) );
					} catch ( err ) {
						// Ignore a partial or non-JSON line and keep reading.
					}
				}
			}

			xhr.upload.onprogress = function ( event ) {
				if ( ! event.lengthComputable ) {
					setProgress( null, 'Uploading pack', '' );
					return;
				}
				setProgress(
					Math.round( ( event.loaded / event.total ) * 100 ),
					'Uploading pack',
					formatBytes( event.loaded ) + ' of ' + formatBytes( event.total )
				);
			};

			xhr.upload.onload = function () {
				setProgress( null, 'Reading the pack', 'The server is working through the pack now.' );
			};

			xhr.onprogress = consume;

			xhr.onload = function () {
				consume();
				if ( finished ) {
					return;
				}
				if ( xhr.status < 200 || xhr.status >= 300 ) {
					showError( 'The server returned HTTP ' + xhr.status + '. Nothing was imported. Check the PHP error log.' );
					return;
				}
				showError( 'The import finished but did not report back. Reload the page to check the current state of the site.' );
			};

			xhr.onerror = function () {
				showError( 'The connection dropped during the import. Reload the page to check the current state of the site.' );
			};

			xhr.open( 'POST', DATA.ajaxUrl, true );
			xhr.send( data );
		}

		if ( form ) {
			form.addEventListener( 'submit', function ( event ) {
				if ( importError ) {
					importError.hidden = true;
				}

				if ( tooLarge ) {
					event.preventDefault();
					showError( 'That pack is too large for this server to accept over HTTP. Use the WP-CLI command under Advanced below.' );
					return;
				}

				if ( dryRun && ! dryRun.checked ) {
					if ( ! window.confirm( 'This is a live import. Menus and UberMenu settings will be written to this site. Continue?' ) ) {
						event.preventDefault();
						return;
					}
				}

				if ( submit ) {
					submit.classList.add( 'disabled' );
					submit.value = dryRun && dryRun.checked ? 'Previewing...' : 'Importing...';
				}

				if ( ! canStream() ) {
					// No streaming available: fall through to the plain POST.
					return;
				}

				event.preventDefault();
				if ( submit ) {
					submit.disabled = true;
				}
				if ( progress ) {
					progress.classList.remove( 'is-done' );
				}
				setProgress( 0, 'Uploading pack', '' );
				stream();
			} );
		}
	}

	/* -------------------------------------------------- Report rendering */

	function renderReport( target, payload ) {
		var rows = payload.report || [];
		var existing = document.querySelector( '[data-fx-um="report"]' );
		var counts = {};
		var order = [];
		var wrap;
		var chips;
		var tools;
		var table;
		var tbody;
		var searchInput;
		var toggle;
		var toggleBox;
		var countNode;

		if ( ! target ) {
			return;
		}
		if ( existing && existing.parentNode ) {
			existing.parentNode.removeChild( existing );
		}
		target.innerHTML = '';

		wrap = el( 'div', 'fx-um-report' );
		wrap.setAttribute( 'data-fx-um', 'report' );
		wrap.appendChild( el( 'h2', null, payload.dry_run ? 'Dry run report' : 'Import report' ) );

		rows.forEach( function ( row ) {
			var key = row.status || row.type || 'info';
			if ( ! counts[ key ] ) {
				counts[ key ] = { count: 0, tone: row.tone || 'info' };
				order.push( key );
			}
			counts[ key ].count++;
		} );

		chips = el( 'ul', 'fx-um-report__summary' );
		chips.appendChild( chipNode( 'total', rows.length, '' ) );
		order.forEach( function ( key ) {
			chips.appendChild( chipNode( key, counts[ key ].count, counts[ key ].tone ) );
		} );
		wrap.appendChild( chips );

		tools = el( 'div', 'fx-um-report__tools' );
		searchInput = el( 'input' );
		searchInput.type = 'search';
		searchInput.placeholder = 'Search the report';
		searchInput.autocomplete = 'off';
		searchInput.setAttribute( 'data-fx-um', 'report-search' );
		tools.appendChild( searchInput );

		toggle = el( 'label', 'fx-um-toggle' );
		toggleBox = el( 'input' );
		toggleBox.type = 'checkbox';
		toggleBox.setAttribute( 'data-fx-um', 'report-problems' );
		toggle.appendChild( toggleBox );
		toggle.appendChild( document.createTextNode( ' Show only warnings and errors' ) );
		tools.appendChild( toggle );

		countNode = el( 'span', 'fx-um-actions__hint' );
		countNode.setAttribute( 'data-fx-um', 'report-count' );
		countNode.setAttribute( 'aria-live', 'polite' );
		tools.appendChild( countNode );
		wrap.appendChild( tools );

		table = el( 'table', 'widefat striped' );
		table.innerHTML = '<thead><tr><th style="width:110px;">Type</th><th style="width:140px;">Status</th><th>Details</th></tr></thead>';
		tbody = el( 'tbody' );

		rows.forEach( function ( row ) {
			var tr = el( 'tr' );
			var pill = el( 'span', 'fx-um-pill fx-um-pill--' + ( row.tone || 'info' ), row.status || row.type || '' );
			var statusCell = el( 'td' );

			tr.setAttribute( 'data-tone', row.tone || 'info' );
			tr.appendChild( el( 'td', null, row.type || '' ) );
			statusCell.appendChild( pill );
			tr.appendChild( statusCell );
			tr.appendChild( el( 'td', null, row.message || '' ) );
			tbody.appendChild( tr );
		} );

		table.appendChild( tbody );
		wrap.appendChild( table );
		target.appendChild( wrap );

		initReport( wrap );
		wrap.scrollIntoView( { behavior: 'smooth', block: 'start' } );
	}

	function chipNode( label, count, tone ) {
		var chip = el( 'li', 'fx-um-chip' + ( tone ? ' fx-um-chip--' + tone : '' ) );
		chip.appendChild( el( 'b', null, String( count ) ) );
		chip.appendChild( document.createTextNode( ' ' + label ) );
		return chip;
	}

	/* -------------------------------------------------- Report table */

	function initReport( report ) {
		var search = report.querySelector( '[data-fx-um="report-search"]' );
		var problems = report.querySelector( '[data-fx-um="report-problems"]' );
		var counter = report.querySelector( '[data-fx-um="report-count"]' );
		var rows = Array.prototype.slice.call( report.querySelectorAll( 'tbody tr' ) );

		function apply() {
			var term = search ? search.value.trim().toLowerCase() : '';
			var onlyProblems = problems && problems.checked;
			var visible = 0;

			rows.forEach( function ( row ) {
				var tone = row.getAttribute( 'data-tone' );
				var ok = true;

				if ( onlyProblems && 'warn' !== tone && 'bad' !== tone ) {
					ok = false;
				}
				if ( ok && term && -1 === row.textContent.toLowerCase().indexOf( term ) ) {
					ok = false;
				}

				row.hidden = ! ok;
				if ( ok ) {
					visible++;
				}
			} );

			if ( counter ) {
				counter.textContent = plural( visible, 'row shown', 'rows shown' );
			}
		}

		if ( search ) {
			search.addEventListener( 'input', debounce( apply, 120 ) );
			search.addEventListener( 'search', apply );
		}
		if ( problems ) {
			problems.addEventListener( 'change', apply );
		}

		apply();
	}

	ready( function () {
		var picker = document.querySelector( '[data-fx-um="picker"]' );
		var importCard = document.querySelector( '[data-fx-um="import"]' );
		var report = document.querySelector( '[data-fx-um="report"]' );

		if ( picker ) {
			initPicker( picker );
		}
		if ( importCard ) {
			initImport( importCard );
		}
		if ( report ) {
			initReport( report );
		}
	} );
})();
