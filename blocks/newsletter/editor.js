/**
 * EventCrew next events announcement block — a thin dynamic block.
 *
 * No build step: it leans on the wp-blocks, wp-element and wp-server-side-render
 * scripts WordPress already ships, and the actual markup comes from the PHP
 * render_callback (NewsletterController::renderShortcode). The editor just shows
 * a server-rendered preview.
 */
( function ( blocks, element, serverSideRender ) {
	if ( ! blocks || ! serverSideRender ) {
		return;
	}

	blocks.registerBlockType( 'eventcrew/newsletter', {
		apiVersion: 2,
		title: 'Next events announcement',
		description: 'The form to get the coming events by email.',
		icon: 'email',
		category: 'widgets',
		edit: function () {
			return element.createElement( serverSideRender, {
				block: 'eventcrew/newsletter',
			} );
		},
		save: function () {
			return null;
		},
	} );
} )( window.wp.blocks, window.wp.element, window.wp.serverSideRender );
