/**
 * ProGate — Upgrade screen shown for Pro-only pages when not licensed.
 */
export default function ProGate( { feature = 'This feature', benefits = [] } ) {
	const defaultBenefits = [
		'✅ Advanced Fraud Detection',
		'✅ Unlimited Courier Connections',
		'✅ Bulk Order Submission',
		'✅ Courier Status Sync',
		'✅ Meta Conversions API',
		'✅ Event Deduplication',
		'✅ COD-Aware Purchase Tracking',
		'✅ Advanced Analytics',
	];

	const list = benefits.length ? benefits : defaultBenefits;

	return (
		<div className="odrplt-pro-gate">
			<div className="odrplt-pro-gate__icon">🚀</div>
			<h2 className="odrplt-pro-gate__title">{ feature } — Pro Feature</h2>
			<p className="odrplt-pro-gate__desc">
				Upgrade to <strong>Order Pilot Pro</strong> to unlock advanced COD automation,
				fraud prevention, and server-side Meta tracking.
			</p>
			<div className="odrplt-pro-gate__features">
				{ list.map( ( b, i ) => (
					<span key={ i } className="odrplt-pro-gate__feature">{ b }</span>
				) ) }
			</div>
			<a
				href="https://arsyntax.com/asxc-product/order-pilot/"
				target="_blank"
				rel="noopener noreferrer"
				className="odrplt-btn odrplt-btn--primary odrplt-btn--lg"
				style={ { background: 'linear-gradient(135deg, #6c47ff, #a855f7)', border: 'none' } }
			>
				⚡ Upgrade to Pro
			</a>
		</div>
	);
}
