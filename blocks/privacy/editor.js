/**
 * EventCrew privacy block — a thin dynamic block.
 *
 * Same shape as the signup block: no build step, no client-side markup. The
 * notice is assembled in PHP from the organizer's Privacy settings
 * (PrivacyController::renderShortcode), so the editor shows a server-rendered
 * preview and nothing here has to know what the notice says.
 */
( function ( blocks, element, serverSideRender ) {
	if ( ! blocks || ! serverSideRender ) {
		return;
	}

	blocks.registerBlockType( 'eventcrew/privacy', {
		apiVersion: 2,
		title: 'EventCrew privacy notice',
		description: 'How the crew’s personal data is handled, from your Privacy settings.',
		icon: 'privacy',
		category: 'widgets',
		edit: function () {
			return element.createElement( serverSideRender, {
				block: 'eventcrew/privacy',
			} );
		},
		save: function () {
			return null;
		},
	} );
} )( window.wp.blocks, window.wp.element, window.wp.serverSideRender );
