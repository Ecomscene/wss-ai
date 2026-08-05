/**
 * WS Flow Mailer admin JS — vanilla, no dependencies.
 * Settings (provider toggle, test connection), flows (status toggle,
 * steps builder), template editor (merge tags, preview, test mail).
 */
( function () {
	'use strict';

	function ajax( params ) {
		var body = new URLSearchParams();
		Object.keys( params ).forEach( function ( key ) {
			body.append( key, params[ key ] );
		} );

		return fetch( window.wsfmAdmin.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
			body: body.toString(),
		} ).then( function ( response ) {
			return response.json();
		} );
	}

	function initSettings() {
		var providerSelect = document.getElementById( 'wsfm_provider' );
		var sections = document.querySelectorAll( '.wsfm-provider-fields' );

		function toggleProviderFields() {
			sections.forEach( function ( section ) {
				section.style.display =
					section.getAttribute( 'data-provider' ) === providerSelect.value ? '' : 'none';
			} );
		}

		if ( providerSelect ) {
			providerSelect.addEventListener( 'change', toggleProviderFields );
			toggleProviderFields();
		}

		var testButton = document.getElementById( 'wsfm-test-connection' );
		var testResult = document.getElementById( 'wsfm-test-result' );

		if ( testButton && testResult ) {
			testButton.addEventListener( 'click', function () {
				testButton.disabled = true;
				testResult.className = 'wsfm-test-pending';
				testResult.textContent = window.wsfmAdmin.testing;

				ajax( { action: 'wsfm_test_connection', _ajax_nonce: window.wsfmAdmin.testNonce } )
					.then( function ( json ) {
						testResult.className = json.success ? 'wsfm-test-success' : 'wsfm-test-error';
						testResult.textContent =
							json.data && json.data.message ? json.data.message : 'Onbekende fout.';
					} )
					.catch( function () {
						testResult.className = 'wsfm-test-error';
						testResult.textContent = 'Verzoek mislukt. Probeer het opnieuw.';
					} )
					.finally( function () {
						testButton.disabled = false;
					} );
			} );
		}

		var copyButton = document.getElementById( 'wsfm-copy-webhook' );
		if ( copyButton ) {
			copyButton.addEventListener( 'click', function () {
				var url = document.getElementById( 'wsfm-webhook-url' ).textContent;
				navigator.clipboard.writeText( url ).then( function () {
					copyButton.textContent = '✓';
					setTimeout( function () {
						copyButton.textContent = 'Kopieer';
					}, 1500 );
				} );
			} );
		}
	}

	function initFlowList() {
		document.querySelectorAll( '.wsfm-toggle[data-flow]' ).forEach( function ( toggle ) {
			toggle.addEventListener( 'click', function () {
				toggle.disabled = true;

				ajax( {
					action: 'wsfm_toggle_flow',
					_ajax_nonce: window.wsfmAdmin.adminNonce,
					flow_id: toggle.getAttribute( 'data-flow' ),
				} )
					.then( function ( json ) {
						if ( ! json.success ) {
							window.alert( json.data && json.data.message ? json.data.message : 'Fout.' );
							return;
						}
						var active = json.data.status === 'active';
						toggle.classList.toggle( 'wsfm-toggle-on', active );
						toggle.setAttribute( 'aria-checked', active ? 'true' : 'false' );
						var label = toggle.parentElement.querySelector( '.wsfm-toggle-label' );
						if ( label ) {
							label.textContent = active ? 'Actief' : 'Gepauzeerd';
						}
					} )
					.finally( function () {
						toggle.disabled = false;
					} );
			} );
		} );
	}

	function initFlowEditor() {
		var form = document.getElementById( 'wsfm-flow-form' );
		if ( ! form ) {
			return;
		}

		var stepsContainer = document.getElementById( 'wsfm-steps' );
		var template = document.getElementById( 'wsfm-step-template' );
		var addButton = document.getElementById( 'wsfm-add-step' );
		var triggerSelect = document.getElementById( 'wsfm_trigger_type' );
		var nextIndex = parseInt( stepsContainer.getAttribute( 'data-step-count' ), 10 ) || 0;

		function renumber() {
			stepsContainer.querySelectorAll( '.wsfm-step-number' ).forEach( function ( el, i ) {
				el.textContent = 'Stap ' + ( i + 1 );
			} );
		}

		function bindRemove( scope ) {
			scope.querySelectorAll( '.wsfm-remove-step' ).forEach( function ( btn ) {
				btn.addEventListener( 'click', function () {
					btn.closest( '.wsfm-step' ).remove();
					renumber();
				} );
			} );
		}

		if ( addButton && template ) {
			addButton.addEventListener( 'click', function () {
				var html = template.innerHTML.replace( /__INDEX__/g, String( nextIndex ) );
				nextIndex++;

				var wrapper = document.createElement( 'div' );
				wrapper.innerHTML = html;
				var step = wrapper.firstElementChild;

				// Abandoned-cart flows get the stop-on-order checkbox
				// pre-checked (sensible default per the spec).
				if ( triggerSelect && triggerSelect.value === 'abandoned_cart' ) {
					var stopBox = step.querySelector( '.wsfm-stop-default' );
					if ( stopBox ) {
						stopBox.checked = true;
					}
				}

				stepsContainer.appendChild( step );
				bindRemove( step );
				renumber();
			} );
		}

		bindRemove( stepsContainer );
		renumber();

		// Warn when changing the trigger type while queue items are pending.
		if ( triggerSelect ) {
			var pendingCount = parseInt( form.getAttribute( 'data-pending-count' ), 10 ) || 0;
			var originalTrigger = form.getAttribute( 'data-original-trigger' );
			var warning = document.getElementById( 'wsfm-trigger-warning' );

			triggerSelect.addEventListener( 'change', function () {
				if ( warning && pendingCount > 0 && originalTrigger && triggerSelect.value !== originalTrigger ) {
					warning.style.display = '';
				} else if ( warning ) {
					warning.style.display = 'none';
				}
			} );
		}

		// Start a new flow with one step already in place.
		if ( stepsContainer.children.length === 0 && addButton ) {
			addButton.click();
		}

		var deleteButton = document.getElementById( 'wsfm-delete-flow' );
		if ( deleteButton ) {
			deleteButton.addEventListener( 'click', function ( event ) {
				if ( ! window.confirm( window.wsfmAdmin.confirmDeleteFlow ) ) {
					event.preventDefault();
				}
			} );
		}
	}

	function initTemplateEditor() {
		var form = document.getElementById( 'wsfm-template-form' );
		if ( ! form ) {
			return;
		}

		var subjectField = document.getElementById( 'wsfm_template_subject' );
		var lastFocus = 'body'; // 'subject' or 'body'

		if ( subjectField ) {
			subjectField.addEventListener( 'focus', function () {
				lastFocus = 'subject';
			} );
		}
		document.addEventListener( 'focusin', function ( event ) {
			var target = event.target;
			if ( target.id === 'wsfm_template_body' || ( target.closest && target.closest( '#wp-wsfm_template_body-wrap' ) ) ) {
				lastFocus = 'body';
			}
		} );

		function insertIntoInput( input, text ) {
			var start = input.selectionStart || 0;
			var end = input.selectionEnd || 0;
			input.value = input.value.slice( 0, start ) + text + input.value.slice( end );
			input.selectionStart = input.selectionEnd = start + text.length;
			input.focus();
		}

		function getBodyContent() {
			if ( window.tinymce && window.tinymce.get( 'wsfm_template_body' ) && ! window.tinymce.get( 'wsfm_template_body' ).isHidden() ) {
				return window.tinymce.get( 'wsfm_template_body' ).getContent();
			}
			var textarea = document.getElementById( 'wsfm_template_body' );
			return textarea ? textarea.value : '';
		}

		document.querySelectorAll( '.wsfm-insert-tag' ).forEach( function ( button ) {
			button.addEventListener( 'click', function () {
				var tag = button.getAttribute( 'data-tag' );

				if ( lastFocus === 'subject' && subjectField ) {
					insertIntoInput( subjectField, tag );
					return;
				}

				var editor = window.tinymce ? window.tinymce.get( 'wsfm_template_body' ) : null;
				if ( editor && ! editor.isHidden() ) {
					editor.execCommand( 'mceInsertContent', false, tag );
					editor.focus();
				} else {
					var textarea = document.getElementById( 'wsfm_template_body' );
					if ( textarea ) {
						insertIntoInput( textarea, tag );
					}
				}
			} );
		} );

		var result = document.getElementById( 'wsfm-template-result' );

		var previewButton = document.getElementById( 'wsfm-preview-template' );
		if ( previewButton ) {
			previewButton.addEventListener( 'click', function () {
				previewButton.disabled = true;

				ajax( {
					action: 'wsfm_preview_template',
					_ajax_nonce: window.wsfmAdmin.adminNonce,
					subject: subjectField ? subjectField.value : '',
					body: getBodyContent(),
				} )
					.then( function ( json ) {
						if ( ! json.success ) {
							window.alert( json.data && json.data.message ? json.data.message : 'Fout.' );
							return;
						}
						var win = window.open( '', 'wsfmPreview', 'width=700,height=800' );
						win.document.open();
						win.document.write(
							'<!DOCTYPE html><html><head><meta charset="utf-8"><title>Preview</title></head><body style="margin:0;">' +
								'<div style="background:#23282d;color:#ffffff;padding:10px 16px;font-family:sans-serif;font-size:13px;">Onderwerp: ' +
								json.data.subject.replace( /</g, '&lt;' ) +
								'</div><div style="padding:16px;">' +
								json.data.html +
								'</div></body></html>'
						);
						win.document.close();
					} )
					.finally( function () {
						previewButton.disabled = false;
					} );
			} );
		}

		var testButton = document.getElementById( 'wsfm-send-test' );
		if ( testButton && result ) {
			testButton.addEventListener( 'click', function () {
				testButton.disabled = true;
				result.className = 'wsfm-test-pending';
				result.textContent = window.wsfmAdmin.sending;

				ajax( {
					action: 'wsfm_send_test_template',
					_ajax_nonce: window.wsfmAdmin.adminNonce,
					subject: subjectField ? subjectField.value : '',
					body: getBodyContent(),
				} )
					.then( function ( json ) {
						result.className = json.success ? 'wsfm-test-success' : 'wsfm-test-error';
						result.textContent =
							json.data && json.data.message ? json.data.message : 'Onbekende fout.';
					} )
					.finally( function () {
						testButton.disabled = false;
					} );
			} );
		}

		var deleteButton = document.getElementById( 'wsfm-delete-template-btn' );
		if ( deleteButton ) {
			deleteButton.addEventListener( 'click', function () {
				if ( window.confirm( window.wsfmAdmin.confirmDeleteTemplate ) ) {
					document.getElementById( 'wsfm-delete-template-form' ).submit();
				}
			} );
		}
	}

	document.addEventListener( 'DOMContentLoaded', function () {
		if ( typeof window.wsfmAdmin === 'undefined' ) {
			return;
		}
		initSettings();
		initFlowList();
		initFlowEditor();
		initTemplateEditor();
	} );
} )();
