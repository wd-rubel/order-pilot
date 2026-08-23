/**
 * Layout Component
 */
import './Layout.css';

export default function Layout( { children, currentPage } ) {
	return (
		<div className="op-layout">
			<main className="op-content">
				{ children }
			</main>
		</div>
	);
}
