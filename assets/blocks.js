/**
 * Monero in the Cart and Checkout blocks. The customer picks it, places the
 * order, and is sent to Monokulo's checkout to pay, as with the classic
 * checkout. Settings come from Monokulo_Blocks_Support.
 */
( function () {
	const { registerPaymentMethod } = window.wc.wcBlocksRegistry;
	const { getSetting } = window.wc.wcSettings;
	const { createElement } = window.wp.element;
	const { decodeEntities } = window.wp.htmlEntities;

	const settings = getSetting( 'monokulo_data', {} );
	const title = decodeEntities( settings.title || '' );
	const description = decodeEntities( settings.description || '' );

	const Label = () =>
		createElement(
			'span',
			{ className: 'wc-block-components-payment-method-label' },
			title,
			settings.icon
				? createElement( 'img', { src: settings.icon, alt: '', style: { height: '1.5em', marginInlineStart: '0.5em', verticalAlign: 'middle' } } )
				: null
		);
	const Content = () => createElement( 'p', null, description );

	registerPaymentMethod( {
		name: 'monokulo',
		label: createElement( Label ),
		content: createElement( Content ),
		edit: createElement( Content ),
		canMakePayment: () => true,
		ariaLabel: title,
		supports: { features: settings.supports || [] },
	} );
} )();
