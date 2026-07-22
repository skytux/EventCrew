/**
 * EventCrew signup block — a thin dynamic block.
 *
 * No build step: it leans on the wp-blocks, wp-element and wp-server-side-render
 * scripts WordPress already ships, and the actual markup comes from the PHP
 * render_callback (SignupController::renderShortcode). The editor just shows a
 * server-rendered preview.
 */
( function ( blocks, element, serverSideRender ) {
	if ( ! blocks || ! serverSideRender ) {
		return;
	}

	blocks.registerBlockType( 'eventcrew/signup', {
		apiVersion: 2,
		title: 'EventCrew signup',
		description: 'The public open-task board with email sign-in.',
		icon: 'groups',
		category: 'widgets',
		edit: function () {
			return element.createElement( serverSideRender, {
				block: 'eventcrew/signup',
			} );
		},
		save: function () {
			return null;
		},
	} );
} )( window.wp.blocks, window.wp.element, window.wp.serverSideRender );
