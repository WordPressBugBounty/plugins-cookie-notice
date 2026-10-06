( function( $ ) {

	// ready event
	$( function() {
		var btClient = false;
		var btCreditCardsInitialized = false;
		var btPayPalInitialized = false;

		var btInit = function() {
			var result = btInitToken();

			if ( result !== false && btCreditCardsInitialized === false ) {
				// ajax was successful
				result.done( function( response ) {
					// token received
					try {
						// parse response
						data = JSON.parse( response );

						// first step, init braintree client
						btClient = braintree.client.create( {
							authorization: data.token
						} );

						btInitPaymentMethod( 'credit_card' );
					// token failed
					} catch ( e ) {
						btGatewayFail( 'btInit catch' );
					}
					// ajax failed
				} ).fail( function() {
					btGatewayFail( 'btInit AJAX failed' );
				} );
			}
		}

		var btInitToken = function() {
			// payment screen?
			var paymentEl = $( '.cn-sidebar form[data-action="payment"]' );

			// init braintree
			if ( paymentEl.length ) {
				paymentEl.addClass( 'cn-form-disabled' );

				if ( typeof braintree !== 'undefined' ) {
					var ajaxArgs = {
						action: 'cn_api_request',
						request: 'get_bt_init_token',
						nonce: cnWelcomeArgs.nonce
					};

					// network area?
					if ( cnWelcomeArgs.network === '1' )
						ajaxArgs.cn_network = 1;

					return $.ajax( {
						url: cnWelcomeArgs.ajaxURL,
						type: 'POST',
						dataType: 'html',
						data: ajaxArgs
					} );
				} else
					return false;
			} else
				return false;
		}

		var btInitPaymentMethod = function( type ) {
			if ( btClient !== false ) {
				if ( type === 'credit_card' && btCreditCardsInitialized === false ) {
					$( 'form.cn-form[data-action="payment"]' ).addClass( 'cn-form-disabled' );

					btClient.then( btCreditCardsInit ).then( btHostedFieldsInstance ).catch( btGatewayFail );
				} else if ( type === 'paypal' && btPayPalInitialized === false ) {
					$( 'form.cn-form[data-action="payment"]' ).addClass( 'cn-form-disabled' );

					btClient.then( btPaypalCheckoutInit ).then( btPaypalCheckoutSDK ).then( btPaypalCheckoutInstance ).then( btPaypalCheckoutButton ).catch( btGatewayFail );
				}
			} else
				btGatewayFail( 'btInitPaymentMethod btClient is false' );
		}

		var btCreditCardsInit = function( clientInstance ) {
			return braintree.hostedFields.create( {
				client: clientInstance,
				styles: {
					'input': {
						'font-size': '14px',
						'font-family': '-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Oxygen-Sans,Ubuntu,Cantarell,"Helvetica Neue",sans-serif',
						'color': '#fff'
					},
					':focus': {
						'color': '#fff'
					},
					"::placeholder": {
						'color': '#aaa'
					}
				},
				fields: {
					number: {
						'selector': '#cn_card_number',
						'placeholder': '0000 0000 0000 0000'
					},
					expirationDate: {
						'selector': '#cn_expiration_date',
						'placeholder': 'MM / YY'
					},
					cvv: {
						'selector': '#cn_cvv',
						'placeholder': '123'
					}
				}
			} );
		}

		var btHostedFieldsInstance = function( hostedFieldsInstance ) {
			btCreditCardsInitialized = true;

			var form = $( 'form.cn-form[data-action="payment"]' );

			form.removeClass( 'cn-form-disabled' );

			form.on( 'submit', function() {
				if ( form.hasClass( 'cn-payment-in-progress' ) )
					return false;

				form.find( '.cn-form-feedback' ).addClass( 'cn-hidden' );

				// spin the spinner, if exists
				if ( form.find( '.cn-spinner' ).length )
					form.find( '.cn-spinner' ).addClass( 'spin' );

				var invalidForm = false;
				var state = hostedFieldsInstance.getState();

				// check hosted fields
				Object.keys( state.fields ).forEach( function( field ) {
					if ( ! state.fields[field].isValid ) {
						$( state.fields[field].container ).addClass( 'braintree-hosted-fields-invalid' );

						invalidForm = true;
					}
				} );

				if ( invalidForm ) {
					setTimeout( function() {
						cnDisplayError( cnWelcomeArgs.invalidFields, form );

						// spin the spinner, if exists
						if ( form.find( '.cn-spinner' ).length )
							form.find( '.cn-spinner' ).removeClass( 'spin' );
					}, 500 );

					return false;
				}

				hostedFieldsInstance.tokenize( function( err, payload ) {
					if ( err ) {
						cnDisplayError( cnWelcomeArgs.error );

						return false;
					} else {
						form.addClass( 'cn-payment-in-progress' );
						form.find( 'input[name="payment_nonce"]' ).val( payload.nonce );
						form.find( 'input[name="cn_payment_identifier"]' ).val( payload.details.lastFour );

						$( '#cn_submit_pro' ).find( '.cn-screen-button[data-screen="4"]' ).trigger( 'click' );
					}
				} );

				return false;
			} );
		}

		var btPaypalCheckoutInit = function( clientInstance ) {
			return braintree.paypalCheckout.create( {
				client: clientInstance
			} );
		}

		var btPaypalCheckoutSDK = function( paypalCheckoutInstance ) {
			return paypalCheckoutInstance.loadPayPalSDK( {
				vault: true,
				intent: 'tokenize'
			} );
		}

		var btPaypalCheckoutInstance = function( paypalCheckoutInstance ) {
			var form = $( 'form.cn-form[data-action="payment"]' );

			return paypal.Buttons( {
				fundingSource: paypal.FUNDING.PAYPAL,
				createBillingAgreement: function() {
					form.addClass( 'cn-form-disabled' );

					return paypalCheckoutInstance.createPayment( {
						flow: 'vault',
						intent: 'tokenize',
						currency: 'EUR'
					} );
				},
				onApprove: function( data, actions ) {
					return paypalCheckoutInstance.tokenizePayment( data ).then( function( payload ) {
						form.addClass( 'cn-payment-in-progress' );
						form.find( 'input[name="payment_nonce"]' ).val( payload.nonce );
						form.find( 'input[name="cn_payment_identifier"]' ).val( payload.details.email );

						$( '#cn_submit_pro' ).find( '.cn-screen-button[data-screen="4"]' ).trigger( 'click' );
					} );
				},
				onCancel: function( data ) {
					form.removeClass( 'cn-form-disabled' );
				},
				onError: function( err ) {
					form.removeClass( 'cn-form-disabled' );
				}
			} ).render( '#cn_paypal_button' );
		}

		var btPaypalCheckoutButton = function() {
			btPayPalInitialized = true;

			$( 'form.cn-form[data-action="payment"]' ).removeClass( 'cn-form-disabled' );
		}

		var btGatewayFail = function( error ) {
			if ( typeof error !== 'undefined' )
				console.log( error );

			cnDisplayError( cnWelcomeArgs.error );
		}

		var cnDisplayError = function( message, form ) {
			if ( typeof form === 'undefined' )
				form = $( 'form.cn-form[data-action="payment"]' );

			form.find( '.cn-form-feedback' ).html( '<p class="cn-error">' + message + '</p>' ).removeClass( 'cn-hidden' );
		}

		var cnWelcomeScreen = function( target ) {
			var screen = $( target ).data( 'screen' );
			var steps = [1, 2, 3, 4];
			var sidebars = ['login', 'register', 'configure', 'payment'];

			// continue with screen loading
			var requestData = {
				action: 'cn_welcome_screen',
				nonce: cnWelcomeArgs.nonce
			};

			if ( $.inArray( screen, steps ) !== -1 ) {
				var container = $( '.cn-welcome-wrap' );

				requestData.screen = screen;
			} else if ( $.inArray( screen, sidebars ) !== -1 ) {
				var container = $( '.cn-sidebar' );

				requestData.screen = screen;
			} else
				return false;

			// network area?
			if ( cnWelcomeArgs.network === '1' )
				requestData.cn_network = 1;

			// add loading overlay
			$( container ).addClass( 'cn-loading' );

			$.ajax( {
				url: cnWelcomeArgs.ajaxURL,
				type: 'POST',
				dataType: 'html',
				data: requestData
			} ).done( function( response ) {
				$( container ).replaceWith( response );
			} ).fail( function( jqXHR, textStatus, errorThrown ) {
				//
			} ).always( function( response ) {
				// remove spinner
				$( container ).removeClass( 'cn-loading' );

				// trigger event
				var event = $.Event( 'screen-loaded' );

				$( document ).trigger( event );
			} );

			return this;
		};

		var cnWelcomeForm = function( form ) {
			var formAction = $( form[0] ).data( 'action' );
			var formResult = null;
			var formData = {
				action: 'cn_api_request',
				nonce: cnWelcomeArgs.nonce
			};

			// clear feedback
			$( form[0] ).find( '.cn-form-feedback' ).addClass( 'cn-hidden' );

			// build request data
			formData.request = formAction;

			// convert form data to object
			$( form[0] ).serializeArray().map( function( x ) {
				// exception for checkboxes
				if ( x.name === 'cn_laws' ) {
					var arrayVal = typeof formData[x.name] !== 'undefined' ? formData[x.name] : [];

					arrayVal.push( x.value );

					formData[x.name] = arrayVal;
				} else
					formData[x.name] = x.value;
			} );

			// network area?
			if ( cnWelcomeArgs.network === '1' )
				formData.cn_network = 1;

			formResult = $.ajax( {
				url: cnWelcomeArgs.ajaxURL,
				type: 'POST',
				dataType: 'json',
				data: formData
			} );

			return formResult;
		};

		// Two-step verification: the code step of the sign-in. The server keeps the partial token;
		// all this holds is what to ask for.
		var cnLoginCode = { methods: [], emailSent: false, timer: null };

		var cnLoginCodeForm = function() {
			return $( 'form.cn-form[data-action="login_code"]' );
		};

		// show one method: its hint, its field type, and the links to the others
		var cnLoginCodeSetMethod = function( method ) {
			var form = cnLoginCodeForm();

			form.find( 'input[name="method"]' ).val( method );
			form.find( 'input[name="code"]' ).val( '' ).attr( 'inputmode', method === 'backup' ? 'text' : 'numeric' );
			form.find( '.cn-login-code-hint' ).hide().filter( '[data-method="' + method + '"]' ).show();
			form.find( '.cn-login-code-resend' ).toggle( method === 'email' );

			form.find( '.cn-login-code-method' ).each( function() {
				var other = $( this ).data( 'method' );

				$( this ).toggle( other !== method && $.inArray( other, cnLoginCode.methods ) !== -1 );
			} );
		};

		// back to the password form, with the reason if there is one
		var cnLoginCodeBack = function( message ) {
			var loginForm = $( 'form.cn-form[data-action="login"]' );

			clearInterval( cnLoginCode.timer );

			cnLoginCodeForm().hide().find( 'input[name="code"]' ).val( '' );
			loginForm.show();

			if ( message )
				cnDisplayError( message, loginForm );
		};

		// the resend link counts down the seconds before another code may be asked for
		var cnLoginCodeCountdown = function( form, seconds ) {
			var link = form.find( '.cn-login-code-resend' );
			var label = link.data( 'label' );

			clearInterval( cnLoginCode.timer );
			link.data( 'wait', seconds );

			var tick = function() {
				var left = link.data( 'wait' );

				if ( left <= 0 ) {
					clearInterval( cnLoginCode.timer );
					link.text( label );

					return;
				}

				link.text( label + ' (' + left + ')' ).data( 'wait', left - 1 );
			};

			cnLoginCode.timer = setInterval( tick, 1000 );
			tick();
		};

		// ask for an emailed code ( the server allows one a minute )
		var cnLoginCodeSend = function( form ) {
			var ajaxArgs = {
				action: 'cn_api_request',
				request: 'login_code_resend',
				nonce: cnWelcomeArgs.nonce
			};

			if ( cnWelcomeArgs.network === '1' )
				ajaxArgs.cn_network = 1;

			form.find( '.cn-form-feedback' ).addClass( 'cn-hidden' );
			form.find( '.cn-login-code-sent' ).hide();

			return $.ajax( {
				url: cnWelcomeArgs.ajaxURL,
				type: 'POST',
				dataType: 'json',
				data: ajaxArgs
			} ).done( function( response ) {
				if ( response && response.hasOwnProperty( 'error' ) ) {
					if ( response.expired )
						cnLoginCodeBack( response.error );
					else
						cnDisplayError( response.error, form );
				} else if ( response && response.code_sent ) {
					cnLoginCode.emailSent = true;

					form.find( '.cn-login-code-sent' ).show();
					cnLoginCodeCountdown( form, response.retry_after || 60 );
				}
			} ).fail( function() {
				cnDisplayError( cnWelcomeArgs.error, form );
			} );
		};

		// the password was right and the account has a second step: turn the form into the code step
		var cnLoginCodeShow = function( loginForm, reply ) {
			var form = cnLoginCodeForm();

			cnLoginCode.methods = $.isArray( reply.methods ) ? reply.methods : [];
			cnLoginCode.emailSent = !! reply.code_sent;

			form.find( '.cn-code-email' ).text( reply.email || '' );
			form.find( '.cn-form-feedback' ).addClass( 'cn-hidden' );
			form.find( '.cn-login-code-sent' ).hide();

			loginForm.find( 'input[name="pass"]' ).val( '' );
			loginForm.hide();
			form.show();

			cnLoginCodeSetMethod( cnLoginCode.methods[0] || 'email' );

			if ( ( cnLoginCode.methods[0] || 'email' ) === 'email' && ! cnLoginCode.emailSent )
				cnLoginCodeSend( form );
		};

		$( document ).on( 'click', '.cn-login-code-resend', function( e ) {
			e.preventDefault();

			if ( $( this ).data( 'wait' ) > 0 )
				return;

			cnLoginCodeSend( cnLoginCodeForm() );
		} );

		$( document ).on( 'click', '.cn-login-code-method', function( e ) {
			var method = $( this ).data( 'method' );

			e.preventDefault();

			cnLoginCodeSetMethod( method );
			cnLoginCodeForm().find( '.cn-form-feedback' ).addClass( 'cn-hidden' );

			if ( method === 'email' && ! cnLoginCode.emailSent )
				cnLoginCodeSend( cnLoginCodeForm() );
		} );

		$( document ).on( 'click', '.cn-login-code-back', function( e ) {
			e.preventDefault();

			cnLoginCodeBack();
		} );

		// Which app to use: the account has apps and none is this site's. Nothing is chosen for the
		// visitor ( no option starts selected ). Names and domains are the account's text: they are set
		// with .text() and .val(), never as markup.
		var cnLoginAppForm = function() {
			return $( 'form.cn-form[data-action="login_app"]' );
		};

		// back to the password form, with the reason if there is one
		var cnLoginAppBack = function( message ) {
			var loginForm = $( 'form.cn-form[data-action="login"]' );

			cnLoginAppForm().hide().find( '.cn-login-app-list' ).empty();
			loginForm.show();

			if ( message )
				cnDisplayError( message, loginForm );
		};

		// the server asked: turn the sign-in into the question
		var cnLoginAppShow = function( reply ) {
			var form = cnLoginAppForm();
			var list = form.find( '.cn-login-app-list' ).empty();
			var addOption = function( id, name, domain, current ) {
				var label = $( '<label class="cn-login-app-option"></label>' );

				label.append( $( '<input type="radio" name="app_id">' ).val( id ) );
				label.append( $( '<span class="cn-login-app-name"></span>' ).text( name ) );

				if ( domain )
					label.append( $( '<span class="cn-login-app-domain"></span>' ).text( domain ) );

				if ( current )
					label.append( $( '<span class="cn-login-app-badge"></span>' ).text( form.attr( 'data-current-label' ) ) );

				list.append( label );
			};

			$.each( $.isArray( reply.apps ) ? reply.apps : [], function( i, app ) {
				var name = app.name || app.domain || app.id;

				addOption( app.id, name, app.domain && app.domain !== app.name ? app.domain : '', app.id === reply.current_app_id );
			} );

			addOption( '__new__', form.attr( 'data-new-label' ), '', false );

			form.find( '.cn-app-site' ).text( reply.site_domain || '' );
			form.find( '.cn-login-app-note' ).show();
			form.find( '.cn-form-feedback' ).addClass( 'cn-hidden' );

			clearInterval( cnLoginCode.timer );

			$( 'form.cn-form[data-action="login"]' ).hide().find( 'input[name="pass"]' ).val( '' );
			cnLoginCodeForm().hide().find( 'input[name="code"]' ).val( '' );
			form.show();
		};

		// the note is about an existing app's settings: not shown for a new one
		$( document ).on( 'change', 'form.cn-login-app-form input[name="app_id"]', function() {
			$( this ).closest( 'form' ).find( '.cn-login-app-note' ).toggle( $( this ).val() !== '__new__' );
		} );

		$( document ).on( 'click', '.cn-login-app-back', function( e ) {
			e.preventDefault();

			cnLoginAppBack();
		} );

		// handle screen loading
		$( document ).on( 'click', '.cn-screen-button', function( e ) {
			var form = $( e.target ).closest( 'form' );
			var result = false;

			// spin the spinner, if exists
			if ( $( e.target ).find( '.cn-spinner' ).length )
				$( e.target ).find( '.cn-spinner' ).addClass( 'spin' );

			// no form?
			if ( form.length === 0 )
				return cnWelcomeScreen( e.target );

			var formData = {};
			var formDataset = $( form[0] ).data();
			var formAction = formDataset.hasOwnProperty( 'action' ) ? formDataset.action : '';

			// get form data
			$( form[0] ).serializeArray().map( function( x ) {
				// exception for checkboxes
				if ( x.name === 'cn_laws' ) {
					var arrayVal = typeof formData[x.name] !== 'undefined' ? formData[x.name] : [];

					arrayVal.push( x.value );

					formData[x.name] = arrayVal;
				} else
					formData[x.name] = x.value;
			} );

			// payment?
			if ( formAction === 'payment' ) {
				// free
				if ( formData.plan === 'free' ) {
					// load screen
					cnWelcomeScreen( e.target );

					return false;
				// licesne
				} else if ( formData.plan === 'license' ) {
					// payment screen?
					var paymentEl = $( '.cn-sidebar form[data-action="payment"]' );

					// disable form
					if ( paymentEl.length )
						paymentEl.addClass( 'cn-form-disabled' );

					// get subscription ID
					var subscriptionID = formData.hasOwnProperty( 'cn_subscription_id' ) ? parseInt( formData.cn_subscription_id ) : 0;

					var ajaxArgs = {
						action: 'cn_api_request',
						request: 'use_license',
						subscriptionID: subscriptionID,
						nonce: cnWelcomeArgs.nonce
					};

					// network area?
					if ( cnWelcomeArgs.network === '1' )
						ajaxArgs.cn_network = 1;

					// assign license request
					result = $.ajax( {
						url: cnWelcomeArgs.ajaxURL,
						type: 'POST',
						dataType: 'json',
						data: ajaxArgs
					} );

					// process license
					result.done( function( response ) {
						// error
						if ( response.hasOwnProperty( 'error' ) ) {
							cnDisplayError( response.error, $( form[0] ) );

							return false;
						// message
						} else {
							var targetEl = $( '#cn_submit_license' ).find( '.cn-screen-button[data-screen="4"]' );

							// open next screen
							cnWelcomeScreen( targetEl );

							return result;
						}
					} );

					// remove spinner
					result.always( function( response ) {
						if ( $( e.target ).find( '.cn-spinner' ).length )
							$( e.target ).find( '.cn-spinner' ).removeClass( 'spin' );

						// enable form
						if ( paymentEl.length )
							paymentEl.removeClass( 'cn-form-disabled' );
					} );
				// pro
				} else {
					// only credit cards
					if ( $( form[0] ).find( 'input[name="payment_nonce"]' ).val() === '' ) {
						form.trigger( 'submit' );

						return false;
					}
				}
			// other forms
			} else
				e.preventDefault();

			// break here on license payment
			if ( formAction === 'payment' && formData.plan === 'license' )
				return result;

			// the question about apps needs an answer: nothing is chosen for the visitor
			if ( formAction === 'login_app' && ! formData.hasOwnProperty( 'app_id' ) ) {
				cnDisplayError( $( form[0] ).attr( 'data-choose-message' ), $( form[0] ) );

				if ( $( e.target ).find( '.cn-spinner' ).length )
					$( e.target ).find( '.cn-spinner' ).removeClass( 'spin' );

				return false;
			}

			// one answer at a time: a second Continue while the first is in flight would show the
			// second reply ( the choice is used up: 'expired' ) over the first one's success
			if ( formAction === 'login_app' ) {
				if ( $( form[0] ).data( 'cnAnswering' ) )
					return false;

				$( form[0] ).data( 'cnAnswering', true );
			}

			// get form and process it
			result = cnWelcomeForm( form );

			result.done( function( response ) {
				// error
				if ( response.hasOwnProperty( 'error' ) ) {
					// the sign-in waiting for its code is gone: start again from the password
					if ( formAction === 'login_code' && response.expired )
						cnLoginCodeBack( response.error );
					// the choice is used up whatever the answer was: start again from the password
					else if ( formAction === 'login_app' )
						cnLoginAppBack( response.error );
					else
						cnDisplayError( response.error, $( form[0] ) );

					return false;
				// message
				} else if ( response.hasOwnProperty( 'message' ) ) {
					cnDisplayError( response.message, $( form[0] ) );

					return false;
				// two-step verification: not signed in yet, the code comes first ( before the success branch )
				} else if ( response.hasOwnProperty( 'needs_code' ) ) {
					cnLoginCodeShow( $( form[0] ), response );

					return false;
				// which app to use: not connected yet, and not a sign-in either ( before the success branch )
				} else if ( response.hasOwnProperty( 'choose_app' ) ) {
					cnLoginAppShow( response );

					return false;
				// all good
				} else {
					switch ( formAction ) {
						// logged in with the code, or with the chosen app, same as logged in
						case 'login_code':
						case 'login_app':
						// logged in, go to success or billing
						case 'login':
						// register complete, go to success or billing
						case 'register':
							// if there are any subscriptions
							if ( response.hasOwnProperty( 'subscriptions' ) ) {
								var subscriptions = response.subscriptions;

								if ( subscriptions.length > 0 ) {
									var available = 0;

									for ( i = 0; i < subscriptions.length; i ++ ) {
										var subscriptionID = subscriptions[i].subscriptionid;
										var licensesAvailable = parseInt( subscriptions[i].availablelicense );
										var subscriptionText = subscriptions[i].VendorSubscriptionID + ' - ' + licensesAvailable + ' ' + cnWelcomeArgs.licensesAvailable;

										var subscriptionOption = $( '<option value="' + subscriptionID + '">' + subscriptionText + '</option>' );

										if ( licensesAvailable == 0 ) {
											$( subscriptionOption ).attr( 'disabled', 'true');
										}

										$( '#cn-subscription-select' ).append( subscriptionOption );

										available += licensesAvailable;
									}

									if ( available > 0 ) {
										$( '.cn-pricing-plan-license' ).removeClass( 'cn-disabled' );
										$( '.cn-pricing-plan-license' ).find( '.cn-plan-amount' ).text( available );
									}
								}
							}

							var accountPlan = formData.hasOwnProperty( 'plan' ) ? formData.plan : 'free';

							// trigger payment
							var accordionItem = $( form[0] ).closest( '.cn-accordion-item' );

							// collapse account
							$( accordionItem ).addClass( 'cn-collapsed cn-disabled' );

							// show billing
							$( accordionItem ).next().removeClass( 'cn-disabled' ).removeClass( 'cn-collapsed' );
							$( accordionItem ).find( 'form' ).removeClass( 'cn-form-disabled' );

							// init braintree after payment screen is loaded via AJAX
							btInit();
							break;

						case 'configure':
						default:
							// load screen
							cnWelcomeScreen( e.target );
							break;
					}
				}
			} );

			result.always( function( response ) {
				if ( $( e.target ).find( '.cn-spinner' ).length )
					$( e.target ).find( '.cn-spinner' ).removeClass( 'spin' );

				if ( formAction === 'login_app' )
					$( form[0] ).data( 'cnAnswering', false );

				// after invalid payment?
				if ( formAction === 'payment' ) {
					$( form[0] ).removeClass( 'cn-payment-in-progress' );
					$( form[0] ).find( 'input[name="payment_nonce"]' ).val( '' );
				}
			} );

			return result;
		} );

		//
		$( document ).on( 'screen-loaded', function() {
			var configureFields = $( '#cn-form-configure' ).serializeArray() || [];
			var frame = window.frames[ 'cn_iframe_id' ];

			if ( configureFields.length > 0 ) {
				$( configureFields ).each( function( index, field ) {
				} );
			}
		} );

		// change payment method
		$( document ).on( 'change', 'input[name="method"]', function() {
			var input = $( this );

			$( '#cn_payment_method_credit_card, #cn_payment_method_paypal' ).toggle();

			input.closest( 'form' ).find( '.cn-form-feedback' ).addClass( 'cn-hidden' );

			// init payment method if needed
			btInitPaymentMethod( input.val() );
		} );

		//
		$( document ).on( 'click', '.cn-accordion > .cn-accordion-item .cn-accordion-button', function() {
			var accordionItem = $( this ).closest( '.cn-accordion-item' );
			var activeItem = $( this ).closest( '.cn-accordion' ).find( '.cn-accordion-item:not(.cn-collapsed)' );

			if ( $( accordionItem ).hasClass( 'cn-collapsed' ) ) {
				$( activeItem ).addClass( 'cn-collapsed' );
				$( accordionItem ).removeClass( 'cn-collapsed' );
			}

			return false;
		} );

		// live preview
		$( document ).on( 'change', 'input[name="cn_position"]', function() {
			var val = $( this ).val();
			var frame = window.frames['cn_iframe_id'];

			frame.contentWindow.postMessage( {call: 'position', value: val} );
		} );

		$( document ).on( 'change', 'input[name="cn_laws"]', function() {
			var val = [];

			$( 'input[name="cn_laws"]:checked' ).each( function() {
				val.push( $( this ).val() );
			} );

			var frame = window.frames['cn_iframe_id'];

			frame.contentWindow.postMessage( {call: 'laws', value: val} );
		} );

		$( document ).on( 'change', 'input[name="cn_naming"]', function() {
			var val = [];

			$( 'input[name="cn_naming"]:checked' ).each( function() {
				val.push( $( this ).val() );
			} );

			var frame = window.frames['cn_iframe_id'];

			frame.contentWindow.postMessage( {call: 'naming', value: val} );
		} );

		$( document ).on( 'change', 'input[name="cn_on_scroll"]', function() {
			var val = $( this ).prop( 'checked' );
			var frame = window.frames['cn_iframe_id'];

			frame.contentWindow.postMessage( {call: 'on_scroll', value: val} );
		} );

		$( document ).on( 'change', 'input[name="cn_on_click"]', function() {
			var val = $( this ).prop( 'checked' );
			var frame = window.frames['cn_iframe_id'];

			frame.contentWindow.postMessage( {call: 'on_click', value: val} );
		} );
		
		$( document ).on( 'change', 'input[name="cn_ui_blocking"]', function() {
			var val = $( this ).prop( 'checked' );
			var frame = window.frames['cn_iframe_id'];

			frame.contentWindow.postMessage( {call: 'ui_blocking', value: val} );
		} );
		
		$( document ).on( 'change', 'input[name="cn_revoke_consent"]', function() {
			var val = $( this ).prop( 'checked' );
			var frame = window.frames['cn_iframe_id'];

			frame.contentWindow.postMessage( {call: 'revoke_consent', value: val} );
		} );

		$( document ).on( 'change', 'input[name="cn_color_primary"]', function() {
			var val = $( this ).val();
			var frame = window.frames['cn_iframe_id'];

			frame.contentWindow.postMessage( {call: 'color_primary', value: val} );
		} );

		$( document ).on( 'change', 'input[name="cn_color_background"]', function() {
			var val = $( this ).val();
			var frame = window.frames['cn_iframe_id'];

			frame.contentWindow.postMessage( {call: 'color_background', value: val} );
		} );

		$( document ).on( 'change', 'input[name="cn_color_border"]', function() {
			var val = $( this ).val();
			var frame = window.frames['cn_iframe_id'];

			frame.contentWindow.postMessage( {call: 'color_border', value: val} );
		} );

		$( document ).on( 'change', 'input[name="cn_color_text"]', function() {
			var val = $( this ).val();
			var frame = window.frames['cn_iframe_id'];

			frame.contentWindow.postMessage( {call: 'color_text', value: val} );
		} );

		$( document ).on( 'change', 'input[name="cn_color_heading"]', function() {
			var val = $( this ).val();
			var frame = window.frames['cn_iframe_id'];

			frame.contentWindow.postMessage( {call: 'color_heading', value: val} );
		} );

		$( document ).on( 'change', 'input[name="cn_color_button_text"]', function() {
			var val = $( this ).val();
			var frame = window.frames['cn_iframe_id'];

			frame.contentWindow.postMessage( {call: 'color_button_text', value: val} );
		} );

		// handle monthly / yearly payment plan
		$( document ).on( 'change', 'input[name="cn_pricing_type"]', function() {
			// pricing plans
			var plansMonthly = cnWelcomeArgs.pricingMonthly;
			var plansYearly = cnWelcomeArgs.pricingYearly;

			var pricingOptions = $( '#cn-pricing-plans option' );
			var checked = $( 'input[name="cn_pricing_type"]:checked' ).val();

			var names = Object.keys( checked === 'yearly' ? plansYearly : plansMonthly );
			var pricing = Object.values( checked === 'yearly' ? plansYearly : plansMonthly );

			if ( checked === 'yearly' )
				$( '.cn-plan-period' ).text( cnWelcomeArgs.paidYear );
			else
				$( '.cn-plan-period' ).text( cnWelcomeArgs.paidMonth );

			// replace options
			var i = 0;

			for ( var property in pricing ) {
				var option = pricingOptions[i];

				$( option ).val( names[i] );
				$( option ).attr( 'data-price', pricing[i] );
				i++;
			}

			// trigger plan selection
			$( 'select[name="cn_pricing_plan"]' ).trigger( 'change' );
		} );

		// handle pro plan selection
		$( document ).on( 'change', 'select[name="cn_pricing_plan"]', function() {
			var el = $( '#cn-pricing-plans' );
			var selected = $( el ).find( 'option:selected' );

			// update price
			$( '.cn-pricing-plan-pro .cn-plan-amount' ).text( $( selected ).attr( 'data-price' ) );

			var availablePlans = ['free'];

			// merge with pro plans
			availablePlans = availablePlans.concat( Object.keys( cnWelcomeArgs.pricingMonthly ) );
			availablePlans = availablePlans.concat( Object.keys( cnWelcomeArgs.pricingYearly ) );

			var input = $( this );
			var inputVal = input.val();

			inputVal = availablePlans.indexOf( inputVal ) !== -1 ? inputVal : 'free';

			if ( inputVal === 'free' ) {
				$( '#cn_submit_free' ).removeClass( 'cn-hidden' );
				$( '#cn_submit_pro' ).addClass( 'cn-hidden' );

				$( document ).find( '#cn-field-plan-free' ).prop( 'checked', true );
				$( document ).find( '#cn-pricing-plan-free' ).prop( 'checked', true );
			} else {
				$( '#cn_submit_free' ).addClass( 'cn-hidden' );
				$( '#cn_submit_pro' ).removeClass( 'cn-hidden' );

				$( document ).find( '#cn-field-plan-pro' ).val( inputVal ).prop( 'checked', true );
				$( document ).find( '#cn-pricing-plan-pro' ).prop( 'checked', true );
			}
		} );

		// handle free / pro / license selection
		$( document ).on( 'change', 'input[name="plan"]', function() {
			var input = $( this ),
				inputVal = input.val();

			if ( inputVal === 'free' ) {
				$( '#cn_submit_free' ).removeClass( 'cn-hidden' );
				$( '#cn_submit_pro' ).addClass( 'cn-hidden' );
				$( '#cn_submit_license' ).addClass( 'cn-hidden' );

				$( document ).find( '#cn-pricing-plan-free' ).prop( 'checked', true );
			} else if ( inputVal === 'license' ) {
				$( '#cn_submit_free' ).addClass( 'cn-hidden' );
				$( '#cn_submit_pro' ).addClass( 'cn-hidden' );
				$( '#cn_submit_license' ).removeClass( 'cn-hidden' );

				$( document ).find( '#cn-pricing-plan-free' ).prop( 'checked', false );
				$( document ).find( '#cn-pricing-plan-pro' ).prop( 'checked', false );
			} else {
				$( '#cn_submit_free' ).addClass( 'cn-hidden' );
				$( '#cn_submit_pro' ).removeClass( 'cn-hidden' );
				$( '#cn_submit_license' ).addClass( 'cn-hidden' );

				$( document ).find( '#cn-pricing-plan-pro' ).prop( 'checked', true );
			}
		} );

		// highlight form
		$( document ).on( 'click', 'input[name="cn_pricing"]', function() {
			$( '.cn-accordion .cn-accordion-item:first-child:not(.cn-collapsed)' ).focus();
		} );

		// select plan payment
		$( document ).on( 'change', 'input[name="cn_pricing"]', function() {
			var input = $( this ),
				inputVal = input.val();

			if ( inputVal === 'free' ) {
				$( '#cn_submit_free' ).removeClass( 'cn-hidden' );
				$( '#cn_submit_pro' ).addClass( 'cn-hidden' );

				$( document ).find( '#cn-field-plan-free' ).prop( 'checked', true );
			} else {
				$( '#cn_submit_free' ).addClass( 'cn-hidden' );
				$( '#cn_submit_pro' ).removeClass( 'cn-hidden' );

				$( document ).find( '#cn-field-plan-pro' ).prop( 'checked', true );
			}

		} );

		// color picker
		initSpectrum();

		// init welcome modal
		if ( cnWelcomeArgs.initModal == true )
			initModal();

	} );

	$( document ).on( 'ajaxComplete', function() {
		// color picker
		initSpectrum();
	} );

	function initSpectrum() {
		$( '.cn-color-picker' ).spectrum( {
			showInput: true,
			showInitial: true,
			allowEmpty: false,
			showAlpha: false
		} );
	}

	function initModal() {
		var progressbar,
			timerId,
			modal = $( "#cn-modal-trigger" );

		if ( modal ) {
			$( "#cn-modal-trigger" ).modaal( {
				content_source: cnWelcomeArgs.ajaxURL + '?action=cn_welcome_screen' + '&nonce=' + cnWelcomeArgs.nonce + '&screen=1',
				type: 'ajax',
				width: 1600,
				custom_class: 'cn-modal',
				// is_locked: true
				ajax_success: function() {
					progressbar = $( document ).find( '.cn-progressbar' );

					if ( progressbar ) {
						timerId = initProgressBar( progressbar );
					}
				},
				before_close: function() {
					clearInterval( timerId );

					var currentStep = $( '.cn-welcome-wrap' );

					// reload on success screen; set dismissed flag on early close
					if ( currentStep.length > 0 ) {
						if ( $( currentStep[0] ).hasClass( 'cn-welcome-step-4' ) === true ) {
							window.location.reload( true );
						} else {
							// user dismissed without completing — suppress future auto-open via ?welcome=1
							$.post( cnWelcomeArgs.ajaxURL, {
								action: 'cn_dismiss_welcome',
								nonce:  cnWelcomeArgs.nonce
							} );
						}
					}
				},
				after_close: function() {
					progressbar = $( document ).find( '.cn-progressbar' );

					$( progressbar ).progressbar( "destroy" );
				}
			} );

			$( modal ).trigger( 'click' );

			$( document ).on( 'click', '.cn-skip-button', function( e ) {
				$( '#modaal-close' ).trigger( 'click' );
			} );
		}
	}

	function initProgressBar( progressbar ) {
		var progressbarObj,
			progressLabel = $( document ).find( '.cn-progress-label' ),
			complianceResults = $( document ).find( '.cn-compliance-results' ),
			currentProgress = 0,
			timerId;

		if ( progressbar ) {
			$( document ).on( 'click', '.cn-screen-button', function( e ) {
				e.preventDefault();

				clearInterval( timerId );
			} );

			$( progressbar ).progressbar( {
				value: 5,
				max: 100,
				create: function( event, ui ) {
					timerId = setInterval( function() {
						// increment progress bar
						currentProgress += 5;

						// update progressbar
						progressbar.progressbar( 'value', currentProgress );

						var lastItem = $( complianceResults ).find( 'div:visible' ).last(),
							lastItemText = $( lastItem ).find( '.cn-compliance-status' ).text();

						$( lastItem ).find( '.cn-compliance-status' ).text( lastItemText + ' .' );

						switch ( currentProgress ) {
							case 15:
								$( lastItem ).find( '.cn-compliance-status' ).addClass( 'cn-passed' ).text( cnWelcomeArgs.statusPassed );

								$( lastItem ).next().slideDown( 200 );
								break;
							case 35:
							case 55:
							case 75:
							case 95:
								if ( cnWelcomeArgs.complianceStatus === 'active' ) {
									$( lastItem ).find( '.cn-compliance-status' ).addClass( 'cn-passed' ).text( cnWelcomeArgs.statusPassed );
								} else {
									$( lastItem ).find( '.cn-compliance-status' ).addClass( 'cn-failed' ).text( cnWelcomeArgs.statusFailed );
								}

								$( lastItem ).next().slideDown( 200 );
								break;
							case 100:
								if ( cnWelcomeArgs.complianceStatus === 'active' ) {
									$( lastItem ).find( '.cn-compliance-status' ).addClass( 'cn-passed' ).text( cnWelcomeArgs.statusPassed );
								} else {
									$( lastItem ).find( '.cn-compliance-status' ).addClass( 'cn-failed' ).text( cnWelcomeArgs.statusFailed );
								}
								break;
						}

						// complete
						if ( currentProgress >= 100 )
							clearInterval( timerId );
					}, 300 );
				},
				change: function( event, ui ) {
					progressLabel.text( progressbar.progressbar( 'value' ) + '%' );
				},
				complete: function( event, ui ) {
					setTimeout( function() {
						if ( cnWelcomeArgs.complianceStatus )
							$( '.cn-compliance-check' ).find( '.cn-compliance-feedback' ).html( '<p class="cn-message">' + cnWelcomeArgs.compliancePassed + '</p>' ).removeClass( 'cn-hidden' );
						else
							$( '.cn-compliance-check' ).find( '.cn-compliance-feedback' ).html( '<p class="cn-error">' + cnWelcomeArgs.complianceFailed + '</p>' ).removeClass( 'cn-hidden' );
					}, 500 );
				}
			} );

			progressbarObj = $( progressbar ).progressbar( "instance" );

			return timerId;
		}
	}

	$( document ).on( 'click', '.cn-run-upgrade, .cn-run-welcome', function( e ) {
		e.preventDefault();

		// modal
		initModal();
	} );

	$( document ).ready( function() {
		var welcome = false;

		welcome = cnGetUrlParam( 'welcome' );

		if ( welcome && ! cnWelcomeArgs.dismissed ) {
			// modal — suppressed if user previously dismissed without completing
			initModal();
		}
	} );

	$( document ).on( 'click', '.cn-sign-up', function( e ) {
		e.preventDefault();

		$( '.cn-screen-button' ).trigger( 'click' );
	} );

	var cnGetUrlParam = function cnGetUrlParam( parameter ) {
		var pageURL = window.location.search.substring( 1 ),
			urlVars = pageURL.split( '&' ),
			parameterName,
			i;

		for ( i = 0; i < urlVars.length; i ++ ) {
			parameterName = urlVars[i].split( '=' );

			if ( parameterName[0] === parameter )
				return typeof parameterName[1] === undefined ? true : decodeURIComponent( parameterName[1] );
		}

		return false;
	};

} )( jQuery );