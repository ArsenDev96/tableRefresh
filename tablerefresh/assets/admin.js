/* TableRefresh admin screen: show that a sync is running and prevent a second click. */
( function () {
	document.querySelectorAll( '.tablerefresh-sync-form' ).forEach( function ( form ) {
		form.addEventListener( 'submit', function () {
			var button = form.querySelector( 'button[type="submit"]' );
			if ( button ) {
				button.disabled = true;
				button.setAttribute( 'aria-busy', 'true' );
				button.textContent = window.tablerefreshAdmin ? window.tablerefreshAdmin.syncing : button.textContent;
			}
		} );
	} );
}() );
