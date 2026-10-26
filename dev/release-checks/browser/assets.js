// Renders the WordPress.org icon and banners (original artwork; no TablePress or Google marks).
const puppeteer = require( 'puppeteer-core' );
const fs = require( 'fs' );
const path = require( 'path' );
const OUT = process.argv[ 2 ];
fs.mkdirSync( OUT, { recursive: true } );

// Table grid with a sync arrow pair, on a teal rounded square.
const icon = `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 256 256" role="img" aria-label="TableRefresh">
  <defs><linearGradient id="g" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="#0f766e"/><stop offset="1" stop-color="#14b8a6"/></linearGradient></defs>
  <rect width="256" height="256" rx="48" fill="url(#g)"/>
  <g fill="none" stroke="#fff" stroke-width="10" stroke-linejoin="round">
    <rect x="44" y="52" width="128" height="112" rx="10"/>
    <path d="M44 86h128M44 124h128M92 52v112M132 52v112"/>
  </g>
  <rect x="49" y="57" width="118" height="24" fill="#fff" opacity=".35"/>
  <g transform="translate(170 170)">
    <circle r="54" fill="#fff"/>
    <g fill="none" stroke="#0f766e" stroke-width="10" stroke-linecap="round">
      <path d="M-28 -6a30 30 0 0 1 50 -18"/>
      <path d="M28 6a30 30 0 0 1 -50 18"/>
    </g>
    <path d="M30 -36l-4 26 -24 -10z" fill="#0f766e"/>
    <path d="M-30 36l4 -26 24 10z" fill="#0f766e"/>
  </g>
</svg>`;

const banner = ( w, h ) => `<!doctype html><html><head><meta charset="utf-8"><style>
  html, body { margin: 0; width: ${ w }px; height: ${ h }px; overflow: hidden; }
  body { background: linear-gradient(120deg, #0b5f59 0%, #0f766e 45%, #14b8a6 100%); font-family: "Segoe UI", system-ui, sans-serif; color: #fff; display: flex; align-items: center; }
  .wrap { display: flex; align-items: center; gap: ${ h * 0.12 }px; padding-left: ${ h * 0.2 }px; }
  .icon { width: ${ h * 0.56 }px; height: ${ h * 0.56 }px; filter: drop-shadow(0 ${ h * 0.02 }px ${ h * 0.04 }px rgba(0,0,0,.25)); }
  h1 { margin: 0; font-size: ${ h * 0.2 }px; font-weight: 700; letter-spacing: -0.01em; }
  p { margin: ${ h * 0.03 }px 0 0; font-size: ${ h * 0.085 }px; opacity: .95; }
  .grid { position: absolute; right: -${ h * 0.1 }px; top: -${ h * 0.1 }px; width: ${ h * 1.3 }px; height: ${ h * 1.3 }px; opacity: .08;
    background-image: linear-gradient(#fff 2px, transparent 2px), linear-gradient(90deg, #fff 2px, transparent 2px); background-size: ${ h * 0.18 }px ${ h * 0.14 }px; }
</style></head><body><div class="grid"></div><div class="wrap"><div class="icon">${ icon }</div>
<div><h1>TableRefresh</h1><p>Auto sync for TablePress tables</p><p>from CSV files and public Google Sheets</p></div></div></body></html>`;

( async () => {
	const browser = await puppeteer.launch( { executablePath: 'C:/Program Files/Google/Chrome/Application/chrome.exe', headless: 'new' } );
	const page = await browser.newPage();
	fs.writeFileSync( path.join( OUT, 'icon.svg' ), icon + '\n' );
	for ( const size of [ 128, 256 ] ) {
		await page.setViewport( { width: size, height: size } );
		await page.setContent( `<!doctype html><html><body style="margin:0">${ icon.replace( '<svg ', `<svg width="${ size }" height="${ size }" ` ) }</body></html>` );
		await page.screenshot( { path: path.join( OUT, `icon-${ size }x${ size }.png` ), omitBackground: true } );
	}
	for ( const [ w, h ] of [ [ 772, 250 ], [ 1544, 500 ] ] ) {
		await page.setViewport( { width: w, height: h } );
		await page.setContent( banner( w, h ) );
		await page.screenshot( { path: path.join( OUT, `banner-${ w }x${ h }.png` ) } );
	}
	await browser.close();
	console.log( fs.readdirSync( OUT ).join( ' ' ) );
} )();
