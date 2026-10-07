import { __ } from '@wordpress/i18n';
import { Component } from '@wordpress/element';

/**
 * Where an add-on's part of a connection form goes.
 *
 * Some ways of connecting need more than fields: a button that sends you off to
 * sign in, a redirect address to copy into somebody else's console first. An
 * add-on that provides such a way registers a connector for it -
 *
 *     window.mmoa.registerConnector( 'microsoft:own_signin', {
 *         before: ( props ) => <RedirectUri … />,   // above the fields
 *         render: ( props ) => <MicrosoftConnect … />, // below them
 *     } );
 *
 * - keyed "provider:mode" for one way into a family, or "provider" for all of
 * them, and this renders it in both places the form appears: the connections
 * screen and the setup wizard.
 *
 * Read at render time rather than once: registration happens before the app
 * mounts, so either would do, but this way nothing has to be kept in step.
 */
export const connectorFor = ( provider, mode ) => {
	const registered = window.mmoa?.connectors || {};

	return registered[ `${ provider }:${ mode }` ] || registered[ provider ] || null;
};

/**
 * A connector that throws takes itself out, not the form around it. The form
 * is how somebody would disconnect a broken connection, so it has to survive.
 */
class Contained extends Component {
	constructor( props ) {
		super( props );
		this.state = { failed: false };
	}

	static getDerivedStateFromError() {
		return { failed: true };
	}

	componentDidCatch( error ) {
		// eslint-disable-next-line no-console
		console.error( '[mme-mail-to-smtp] a registered connector failed to render', error );
	}

	render() {
		if ( this.state.failed ) {
			return (
				<p className="m-0 text-[13px] text-danger">
					{ __( 'Part of this form, provided by an add-on, could not be shown. Updating the add-on usually fixes this.', 'mme-mail-to-smtp' ) }
				</p>
			);
		}

		return this.props.children;
	}
}

/**
 * @param {Object} props
 * @param {'before'|'render'} props.part Which half: above the fields, or below.
 */
const ConnectorSlot = ( { part, provider, mode, ...props } ) => {
	const connector = connectorFor( provider, mode );
	const draw = connector?.[ part ];

	if ( typeof draw !== 'function' ) {
		return null;
	}

	return (
		<Contained>
			<div className={ 'before' === part ? 'mb-5' : 'mt-5' }>
				{ draw( { provider, mode, ...props } ) }
			</div>
		</Contained>
	);
};

export default ConnectorSlot;
