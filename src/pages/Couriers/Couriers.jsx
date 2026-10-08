/**
 * Couriers Page
 * Manage courier connections and credentials.
 */
import { useState, useEffect } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';

const { isPro } = window.orderPilot || {};

export default function Couriers() {
	const [ couriers, setCouriers ]   = useState( [] );
	const [ loading, setLoading ]     = useState( true );
	const [ selected, setSelected ]   = useState( null ); // slug of courier being edited
	const [ credentials, setCreds ]   = useState( {} );
	const [ saving, setSaving ]       = useState( false );
	const [ notice, setNotice ]       = useState( null );

	useEffect( () => {
		loadCouriers();
	}, [] );

	const loadCouriers = () => {
		setLoading( true );
		apiFetch( { path: 'order-pilot/v1/couriers' } )
			.then( setCouriers )
			.catch( () => {} )
			.finally( () => setLoading( false ) );
	};

	/**
	 * Open the credential modal for a courier.
	 * Pre-fills select fields with their defaults.
	 */
	const openModal = ( courier ) => {
		setSelected( courier.slug );
		// Pre-fill select fields with their defaults so they have a value on first save.
		const defaults = {};
		Object.entries( courier.fields || {} ).forEach( ( [ key, field ] ) => {
			if ( field.type === 'select' && field.default ) {
				defaults[ key ] = field.default;
			}
		} );
		setCreds( { ...defaults, ...( courier.credentials || {} ) } );
		setNotice( null );
	};

	/**
	 * Save credentials → the REST endpoint auto-connects the courier.
	 * Called by the "Save & Connect" button inside the modal.
	 */
	const handleSaveCredentials = () => {
		// Validate: at least one field must be non-empty.
		const hasValues = Object.values( credentials ).some( ( v ) => v && v.trim() !== '' );
		if ( ! hasValues ) {
			setNotice( { type: 'error', text: 'Please enter your API credentials before saving.' } );
			return;
		}

		setSaving( true );
		apiFetch( {
			path: `order-pilot/v1/couriers/${ selected }/credentials`,
			method: 'POST',
			data:   credentials,
		} )
			.then( () => {
				setNotice( { type: 'success', text: 'Courier connected successfully.' } );
				setSelected( null );
				setCreds( {} );
				loadCouriers(); // Refresh list so "Connected" badge appears.
			} )
			.catch( ( err ) => {
				setNotice( {
					type: 'error',
					text: err?.message || 'Could not save credentials. Please try again.',
				} );
			} )
			.finally( () => setSaving( false ) );
	};

	/**
	 * Disconnect a courier — removes it from the active list.
	 * Credentials are kept so the user can reconnect easily.
	 */
	const handleDisconnect = ( slug ) => {
		if ( ! window.confirm( 'Disconnect this courier? Your credentials will be kept.' ) ) {
			return;
		}
		apiFetch( {
			path: `order-pilot/v1/couriers/${ slug }/disconnect`,
			method: 'DELETE',
		} )
			.then( () => {
				setNotice( { type: 'success', text: 'Courier disconnected.' } );
				loadCouriers();
			} )
			.catch( ( err ) => {
				setNotice( { type: 'error', text: err?.message || 'Could not disconnect courier.' } );
			} );
	};

	const closeModal = () => {
		setSelected( null );
		setCreds( {} );
	};

	const selectedCourier = couriers.find( ( c ) => c.slug === selected );

	return (
		<div>
			<div className="odrplt-page-header">
				<h1>🚚 Couriers</h1>
				<p>
					Connect and manage your courier services.
					{ ! isPro && (
						<span style={ { marginLeft: 8, color: 'var(--odrplt-warning)' } }>
							Free plan: connect up to 2 couriers.
						</span>
					) }
				</p>
			</div>

			{ notice && (
				<div className={ `odrplt-notice odrplt-notice--${ notice.type }` }>
					{ notice.text }
					<button
						style={ { marginLeft: 'auto', background: 'none', border: 'none', cursor: 'pointer' } }
						onClick={ () => setNotice( null ) }
					>✕</button>
				</div>
			) }

			{ loading ? (
				<div className="odrplt-loading-screen"><span className="odrplt-spinner" /></div>
			) : (
				<div style={ { display: 'grid', gap: '16px', gridTemplateColumns: 'repeat(auto-fill, minmax(280px, 1fr))' } }>
					{ couriers.map( ( courier ) => (
						<div key={ courier.slug } className="odrplt-card">
							<div className="odrplt-card-header">
								<h3 className="odrplt-card-title">{ courier.name }</h3>
								<div style={ { display: 'flex', gap: 6, alignItems: 'center' } }>
									{ /* Environment badge for Pathao */ }
									{ courier.connected && courier.environment && (
										<span
											className={ `odrplt-badge ${ courier.environment === 'sandbox' ? 'odrplt-badge--pending' : 'odrplt-badge--in-transit' }` }
											style={ { fontSize: 10, textTransform: 'uppercase', letterSpacing: 1 } }
										>
											{ courier.environment }
										</span>
									) }
									{ courier.connected
										? <span className="odrplt-badge odrplt-badge--delivered">✓ Connected</span>
										: <span className="odrplt-badge odrplt-badge--none">Not Connected</span>
									}
								</div>
							</div>

							{ /* Show configured (has saved creds) vs not */ }
							{ courier.configured && ! courier.connected && (
								<p style={ { fontSize: 12, color: 'var(--odrplt-text-muted)', marginBottom: 8 } }>
									Credentials saved — click "Connect" to activate.
								</p>
							) }

							<div className="odrplt-flex odrplt-gap-2">
								{ courier.connected ? (
									<>
										{ /* Edit credentials while connected */ }
										<button
											className="odrplt-btn odrplt-btn--secondary odrplt-btn--sm"
											onClick={ () => openModal( courier ) }
										>
											🔑 Edit Credentials
										</button>
										<button
											className="odrplt-btn odrplt-btn--danger odrplt-btn--sm"
											onClick={ () => handleDisconnect( courier.slug ) }
										>
											Disconnect
										</button>
									</>
								) : (
									<>
										{ /* Not connected: open modal to enter credentials & connect */ }
										<button
											className="odrplt-btn odrplt-btn--primary odrplt-btn--sm"
											onClick={ () => openModal( courier ) }
										>
											+ Connect
										</button>
									</>
								) }
							</div>
						</div>
					) ) }
				</div>
			) }

			{ /* Credentials Modal */ }
			{ selected && selectedCourier && (
				<div className="odrplt-modal-overlay" onClick={ closeModal }>
					<div className="odrplt-modal" onClick={ ( e ) => e.stopPropagation() }>
						<div className="odrplt-modal-header">
							<h2 className="odrplt-modal-title">
								{ selectedCourier.name } — { selectedCourier.connected ? 'Edit Credentials' : 'Connect Courier' }
							</h2>
							<button className="odrplt-modal-close" onClick={ closeModal }>✕</button>
						</div>

						<div className="odrplt-modal-body">
							{ ! selectedCourier.connected && (
								<p style={ { marginBottom: 16, color: 'var(--odrplt-text-muted)', fontSize: 13 } }>
									Enter your { selectedCourier.name } API credentials below. Your courier will be connected automatically after saving.
								</p>
							) }

							{ Object.entries( selectedCourier.fields || {} ).map( ( [ key, field ] ) => (
								<div key={ key } className="odrplt-form-group">
									<label>
										{ field.label }
										{ field.required && <span style={ { color: 'var(--odrplt-danger)', marginLeft: 3 } }>*</span> }
									</label>

									{ field.type === 'select' ? (
										<select
											className="odrplt-input"
											value={ credentials[ key ] ?? field.default ?? '' }
											onChange={ ( e ) => setCreds( { ...credentials, [ key ]: e.target.value } ) }
										>
											{ Object.entries( field.options || {} ).map( ( [ val, label ] ) => (
												<option key={ val } value={ val }>{ label }</option>
											) ) }
										</select>
									) : (
										<input
											type={ field.type === 'password' ? 'password' : field.type === 'email' ? 'email' : 'text' }
											className="odrplt-input"
											value={ credentials[ key ] || '' }
											onChange={ ( e ) => setCreds( { ...credentials, [ key ]: e.target.value } ) }
											placeholder={ field.placeholder || field.label }
											autoComplete={ field.type === 'password' ? 'new-password' : 'off' }
										/>
									) }

									{ field.help && (
										<p style={ { margin: '4px 0 0', fontSize: 11, color: 'var(--odrplt-text-muted)' } }>
											{ field.help }
										</p>
									) }
								</div>
							) ) }
						</div>

						<div className="odrplt-modal-footer">
							<button className="odrplt-btn odrplt-btn--secondary" onClick={ closeModal }>
								Cancel
							</button>
							<button
								className="odrplt-btn odrplt-btn--primary"
								onClick={ handleSaveCredentials }
								disabled={ saving }
							>
								{ saving ? 'Saving…' : ( selectedCourier.connected ? '💾 Save Credentials' : '🔗 Save & Connect' ) }
							</button>
						</div>
					</div>
				</div>
			) }
		</div>
	);
}
