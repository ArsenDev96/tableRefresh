// Accessibility review of Tools > TableRefresh in the RC site: axe-core (WCAG 2.1 A/AA), keyboard, semantics, RTL.
const puppeteer = require( 'puppeteer-core' );
const { execFileSync } = require( 'child_process' );
const path = require( 'path' );
const REPO = path.resolve( __dirname, '../../..' ).replace( /\\/g, '/' );
const fs = require( 'fs' );

const BASE = 'http://localhost:8090';
const OUT = path.join( __dirname, 'rc' );
const AXE = require.resolve( 'axe-core/axe.min.js' );
const bash = ( cmd ) => execFileSync( 'C:/Program Files/Git/bin/bash.exe', [ '-c', 'cd "' + REPO + '" && ' + cmd + ' 2>/dev/null | tail -1' ], { encoding: 'utf8' } ).trim();
const wp = ( code ) => execFileSync( 'C:/Program Files/Git/bin/bash.exe', [ '-c', 'cd "' + REPO + '" && sh dev/rc-env.sh wp eval "$0" 2>/dev/null | tail -1', code ], { encoding: 'utf8' } ).trim();
const out = [];
const log = ( line ) => { out.push( line ); console.log( line ); };

( async () => {
	const browser = await puppeteer.launch( { executablePath: 'C:/Program Files/Google/Chrome/Application/chrome.exe', headless: 'new', userDataDir: path.join( __dirname, 'profile-rc' ) } );
	const page = await browser.newPage();
	await page.setViewport( { width: 1280, height: 900 } );
	const go = async ( url ) => page.goto( BASE + url, { waitUntil: 'networkidle2' } );
	const tr = '/wp-admin/tools.php?page=tablerefresh';
	await go( '/wp-login.php' );
	if ( await page.$( '#user_login' ) ) {
		await page.type( '#user_login', 'admin' );
		await page.type( '#user_pass', 'admin' );
		await Promise.all( [ page.waitForNavigation( { waitUntil: 'networkidle2' } ), page.click( '#wp-submit' ) ] );
	}
	const postId = wp( 'echo (int) key( TableRefresh\\Sync_Config::all() );' );

	// A second table, so that the set-up form has a choice; the configured table in conflict.
	wp( 'wp_set_current_user( 1 ); $t = TablePress::$model_table->get_table_template(); $t["name"] = "Opening hours"; $t["data"] = array( array( "x" ) ); $t["visibility"] = array( "rows" => array( 1 ), "columns" => array( 1 ) ); echo TablePress::$model_table->add( $t );' );
	wp( `wp_set_current_user( 1 ); $id = TableRefresh\\TablePress_Adapter::get_table_id( ${ postId } ); $t = TablePress::$model_table->load( $id ); $t["data"][1][1] = "edited"; TablePress::$model_table->save( $t ); $r = ( new TableRefresh\\Sync_Runner() )->run( ${ postId }, "manual" ); echo $r["outcome"];` );

	const axe = async ( label, url ) => {
		await go( url );
		await page.addScriptTag( { path: AXE } );
		const result = await page.evaluate( async () => axe.run( { include: [ '.tablerefresh' ] }, { runOnly: { type: 'tag', values: [ 'wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'best-practice' ] } } ) );
		log( `axe ${ label }: ${ result.violations.length } violations, ${ result.passes.length } rules passed, ${ result.incomplete.length } to review` );
		for ( const v of result.violations ) {
			log( `  - [${ v.impact }] ${ v.id }: ${ v.help } (${ v.nodes.length }) e.g. ${ v.nodes[ 0 ].html.slice( 0, 120 ) }` );
		}
		for ( const v of result.incomplete ) {
			log( `  ? ${ v.id }: ${ v.help } (${ v.nodes.length })` );
		}
	};
	await axe( 'list', tr );
	await axe( 'set up', tr + '&action=add' );
	await axe( 'settings (conflict)', tr + '&action=edit&table=' + postId );

	// Semantics that a screen reader announces.
	await go( tr + '&action=edit&table=' + postId );
	const semantics = await page.evaluate( () => ( {
		controlsWithoutName: [ ...document.querySelectorAll( '.tablerefresh input:not([type=hidden]), .tablerefresh select, .tablerefresh button' ) ].filter( ( e ) => ! ( e.labels && e.labels.length ) && ! e.getAttribute( 'aria-label' ) && ! ( 'BUTTON' === e.tagName && e.textContent.trim() ) && ! ( 'submit' === e.type && e.value ) ).map( ( e ) => e.outerHTML.slice( 0, 80 ) ),
		radioGroupLegend: !! document.querySelector( '.tablerefresh fieldset legend' ),
		tableCaptions: [ ...document.querySelectorAll( '.tablerefresh table.widefat' ) ].map( ( t ) => ( t.caption ? t.caption.textContent : 'NONE' ) ),
		headings: [ ...document.querySelectorAll( '.tablerefresh h1, .tablerefresh h2, .tablerefresh h3' ) ].map( ( h ) => h.tagName + ' ' + h.textContent.trim().slice( 0, 40 ) ),
		described: [ ...document.querySelectorAll( '.tablerefresh [aria-describedby]' ) ].map( ( e ) => e.name + '→' + ( document.getElementById( e.getAttribute( 'aria-describedby' ) ) ? 'ok' : 'MISSING' ) ),
	} ) );
	log( 'semantics: ' + JSON.stringify( semantics ) );
	await go( tr ); // Notices announced as status / alert.
	wp( `$p = ${ postId }; ( new TableRefresh\\Sync_Runner() )->run( $p, "manual" );` );

	// Keyboard: Tab through the set-up form from the address bar; every control reachable, focus visible.
	await go( tr + '&action=add' );
	await page.focus( 'body' );
	const order = [];
	for ( let i = 0; i < 60; i++ ) {
		await page.keyboard.press( 'Tab' );
		const f = await page.evaluate( () => {
			const e = document.activeElement;
			if ( ! e || ! e.closest( '.tablerefresh' ) ) {
				return null;
			}
			const s = getComputedStyle( e );
			const visible = ( 'none' !== s.outlineStyle && '0px' !== s.outlineWidth ) || ( s.boxShadow && 'none' !== s.boxShadow );
			return ( e.name || e.id || e.textContent.trim().slice( 0, 20 ) || e.tagName ) + ( visible ? '' : ' (NO VISIBLE FOCUS)' );
		} );
		if ( f && order[ order.length - 1 ] !== f ) {
			order.push( f );
		}
	}
	log( 'keyboard order (set up): ' + order.join( ' → ' ) );
	// Keyboard-only set-up: choose the table with the arrow keys, type the URL, Space on checkbox, Enter submits.
	await page.focus( '#tablerefresh-table' );
	await page.keyboard.press( 'ArrowDown' );
	await page.focus( '#tablerefresh-url' );
	await page.keyboard.type( 'https://wordpress.org/tablerefresh-no-such-file.csv' );
	await page.focus( 'input[name="confirm_replace"]' );
	await page.keyboard.press( 'Space' );
	await Promise.all( [ page.waitForNavigation( { waitUntil: 'networkidle2', timeout: 60000 } ), page.keyboard.press( 'Enter' ) ] );
	const notice = await page.$eval( '.tablerefresh-notice', ( e ) => e.getAttribute( 'role' ) + ': ' + e.innerText.trim() ).catch( () => 'no notice' );
	log( 'keyboard-only set-up result: ' + notice );

	// RTL: Arabic admin.
	bash( 'sh dev/rc-env.sh wp language core install ar' );
	bash( 'sh dev/rc-env.sh wp user meta update admin locale ar' );
	await go( tr );
	const rtl = await page.evaluate( () => ( { dir: document.documentElement.dir, overflow: document.documentElement.scrollWidth - window.innerWidth } ) );
	await page.screenshot( { path: path.join( OUT, '10-rtl-list.png' ), fullPage: true } );
	await go( tr + '&action=edit&table=' + postId );
	await page.screenshot( { path: path.join( OUT, '11-rtl-settings.png' ), fullPage: true } );
	await page.setViewport( { width: 390, height: 844, isMobile: true } );
	await go( tr );
	const rtlPhone = await page.evaluate( () => document.documentElement.scrollWidth - window.innerWidth );
	await page.screenshot( { path: path.join( OUT, '12-rtl-phone.png' ), fullPage: true } );
	log( `RTL: dir=${ rtl.dir }, desktop overflow ${ rtl.overflow } px, phone overflow ${ rtlPhone } px` );
	bash( 'sh dev/rc-env.sh wp user meta delete admin locale' );

	fs.writeFileSync( path.join( OUT, 'a11y.txt' ), out.join( '\n' ) + '\n' );
	await browser.close();
} )().catch( ( e ) => { console.error( 'ABORTED:', e.message ); process.exit( 1 ); } );
