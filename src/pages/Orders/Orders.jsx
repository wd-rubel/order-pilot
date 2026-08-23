/**
 * Orders Page — placeholder for Phase 2 (full WC order table with courier actions)
 */
export default function Orders() {
	return (
		<div>
			<div className="op-page-header">
				<h1>📦 Orders</h1>
				<p>Manage WooCommerce orders and send to couriers from one place.</p>
			</div>
			<div className="op-card">
				<div className="op-empty">
					<div className="op-empty__icon">📦</div>
					<p className="op-empty__title">Orders view coming in Phase 2</p>
					<p className="op-empty__desc">
						The orders list with courier status, one-click send, and bulk submission will be built in Phase 2.<br/>
						In the meantime, use the <a href="/wp-admin/admin.php?page=wc-orders">WooCommerce orders page</a> — it already shows the courier status column.
					</p>
				</div>
			</div>
		</div>
	);
}
