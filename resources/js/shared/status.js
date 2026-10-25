/**
 * Spinner in the field while a lookup runs (resources/css/lookup.css).
 *
 * @param {HTMLElement} input The field.
 * @param {boolean}     on    Whether a lookup runs.
 */
export function busy( input, on ) {
	input.parentNode.classList.toggle( 'woolab-ic-dic-loading', on );
	input.setAttribute( 'aria-busy', on ? 'true' : 'false' );
}

/**
 * Run a function once the calls stop for a while.
 *
 * @param {Function} fn   The function.
 * @param {number}   wait Milliseconds.
 * @return {Function} The debounced function, `this` and arguments passed on.
 */
export function debounce( fn, wait ) {
	let timeout;
	return function ( ...args ) {
		clearTimeout( timeout );
		timeout = setTimeout( () => fn.apply( this, args ), wait );
	};
}
