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
		<div className="op-pro-gate">
			<div className="op-pro-gate__icon">🚀</div>
			<h2 className="op-pro-gate__title">{ feature } — Pro Feature</h2>
			<p className="op-pro-gate__desc">
				Upgrade to <strong>Order Pilot Pro</strong> to unlock advanced COD automation,
				fraud prevention, and server-side Meta tracking.
			</p>
			<div className="op-pro-gate__features">
				{ list.map( ( b, i ) => (
					<span key={ i } className="op-pro-gate__feature">{ b }</span>
				) ) }
			</div>
			<a
				href="https://orderpilot.io/pricing"
				target="_blank"
				rel="noopener noreferrer"
				className="op-btn op-btn--primary op-btn--lg"
				style={ { background: 'linear-gradient(135deg, #6c47ff, #a855f7)', border: 'none' } }
			>
				⚡ Upgrade to Pro
			</a>
		</div>
	);
}
