// Browser review of the TableRefresh screen on the local dev site (http://localhost:8089, admin/admin).
const puppeteer = require( 'puppeteer-core' );
const path = require( 'path' );

const BASE = 'http://localhost:8089';
const OUT = __dirname;
const [ team, hours ] = process.argv.slice( 2 );

( async () => {
	const browser = await puppeteer.launch( {
		executablePath: 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe',
		headless: 'new',
		args: [ '--no-first-run', '--no-default-browser-check' ],
		userDataDir: path.join( OUT, 'profile' ),
	} );
	const page = await browser.newPage();
	const problems = [];
	page.on( 'console', ( msg ) => { if ( [ 'error', 'warning' ].includes( msg.type() ) ) problems.push( 'console ' + msg.type() + ': ' + msg.text() ); } );
	page.on( 'pageerror', ( err ) => problems.push( 'pageerror: ' + err.message ) );

	await page.setViewport( { width: 1280, height: 900 } );
	await page.goto( BASE + '/wp-login.php', { waitUntil: 'networkidle2' } );
	if ( await page.$( '#user_login' ) ) {
		await page.type( '#user_login', 'admin' );
		await page.type( '#user_pass', 'admin' );
		await Promise.all( [ page.waitForNavigation( { waitUntil: 'networkidle2' } ), page.click( '#wp-submit' ) ] );
	}

	const shot = async ( name, url, opts = {} ) => {
		await page.goto( BASE + url, { waitUntil: 'networkidle2' } );
		await page.screenshot( { path: path.join( OUT, name + '.png' ), fullPage: true, ...opts } );
		console.log( 'saved', name );
	};

	await shot( '1-list', '/wp-admin/admin.php?page=tablerefresh' );
	await shot( '2-edit-conflict', '/wp-admin/admin.php?page=tablerefresh&action=edit&table=' + team );
	await shot( '3-edit-failed', '/wp-admin/admin.php?page=tablerefresh&action=edit&table=' + hours );
	await shot( '4-add', '/wp-admin/admin.php?page=tablerefresh&action=add' );

	// Sync now from the list, through the real button (JS disables it while the request runs).
	await page.goto( BASE + '/wp-admin/admin.php?page=tablerefresh', { waitUntil: 'networkidle2' } );
	await Promise.all( [ page.waitForNavigation( { waitUntil: 'networkidle2' } ), page.click( '.tablerefresh-sync-form button' ) ] );
	await page.screenshot( { path: path.join( OUT, '5-after-sync-now.png' ), fullPage: true } );
	console.log( 'saved 5-after-sync-now' );

	await page.setViewport( { width: 390, height: 844, isMobile: true, deviceScaleFactor: 1 } );
	await shot( '6-list-mobile', '/wp-admin/admin.php?page=tablerefresh' );
	await shot( '7-edit-mobile', '/wp-admin/admin.php?page=tablerefresh&action=edit&table=' + team );

	// Keyboard: the first focusable control in the content area.
	await page.setViewport( { width: 1280, height: 900 } );
	await page.goto( BASE + '/wp-admin/admin.php?page=tablerefresh&action=add', { waitUntil: 'networkidle2' } );
	const unlabeled = await page.$$eval( '.tablerefresh input:not([type=hidden]), .tablerefresh select', ( els ) =>
		els.filter( ( el ) => ! ( el.labels && el.labels.length ) && ! el.getAttribute( 'aria-label' ) ).map( ( el ) => el.name ) );
	console.log( 'controls without label:', JSON.stringify( unlabeled ) );
	console.log( 'problems:', JSON.stringify( problems ) );
	await browser.close();
} )();
