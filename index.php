<?php declare( strict_types = 1 );
/**
 *  Pages: A single file request handler
 */

// HTML Content page folder location (default relative to this file)
define( 'CONTENT', \realpath( \dirname( __FILE__ ) ) . 'content/' );
// Use this instead if you keep content outside the web root
// define( 'CONTENT',	\realpath( \dirname( __FILE__, 2 ) ) . '/htdocs/' );

// Uploaded media folder (everything not HTML E.G. images, JS, CSS etc...)
define( 'UPLOADS', \realpath( \dirname( __FILE__ ) ) . 'uploads/' );
// Use this instead if you keep uploaded files outside the web root
// define( 'UPLOADS',	\realpath( \dirname( __FILE__, 2 ) ) . '/uploads/' );

// Maximum folder depth
define( 'MAX_DEPTH',			50 );

// Media types which will be served (comma separated list)
define( 'MEDIA_TYPES',			<<<TYPES
css, js, txt, html, vtt,
ico, jpg, jpeg, gif, bmp, png, tif, tiff, svg, webp,
ttf, otf, woff, woff2,
doc, docx, ppt, pptx, pdf, epub,
ogg, oga, mpa, mp3, m4a, wav, wma, flac,
avi, mp4, mkv, mov, ogg, ogv,
zip, rar, gz, tar

TYPES
);

// Default HTML content type (leave as UTF-8 unless there's a good reason to change it)
define( 'HTML_TYPE', 'Content-type: text/html; charset=UTF-8' );

// Content cache expiration (7200 = 2 hours)
define( 'CONTENT_CACHE_TTL',		7200 );

// Client-side file cache expiration (604800 = 7 days, 31536000 = 365 days)
define( 'FILE_CACHE_TTL',		604800 );

// Path matching pattern (default max 255 characters)
define( 'PATH_PATTERN', '@[\pL_\-\d\.\s\\/]{1,255}/?@i' );

// Whitelist of approved frame sources for frame-ancestors policy (one per line)
define( 'FRAME_WHITELIST',	<<<LINES

LINES
);

// Content Security and Permissions Policy headers
define( 'SECPOLICY',	<<<JSON
{
	"content-security-policy": {
		"default-src"			: "'none'",
		"img-src"			: "*",
		"base-uri"			: "'self'",
		"style-src"			: "'self'",
		"script-src"			: "'self'",
		"font-src"			: "'self'",
		"form-action"			: "'self'",
		"frame-ancestors"		: "'self'",
		"frame-src"			: "*",
		"media-src"			: "'self'",
		"connect-src"			: "'self'",
		"worker-src"			: "'self'",
		"child-src"			: "'self'",
		"require-trusted-types-for"	: "'script'"
	},
	"permissions-policy": {
		"accelerometer"			: [ "none" ],
		"camera"			: [ "none" ],
		"fullscreen"			: [ "self" ],
		"geolocation"			: [ "none" ],
		"gyroscope"			: [ "none" ],
		"interest-cohort"		: [],
		"payment"			: [ "none" ],
		"usb"				: [ "none" ],
		"microphone"			: [ "none" ],
		"magnetometer"			: [ "none" ]
	}, 
	"common-policy": [
		"X-XSS-Protection: 1; mode=block",
		"X-Content-Type-Options: nosniff",
		"X-Frame-Options: SAMEORIGIN",
		"Referrer-Policy: no-referrer, strict-origin-when-cross-origin"
	]
}
JSON
);

// Streaming file chunks
define( 'STREAM_CHUNK_SIZE',	4096 );

// Maximum file size before streaming in chunks
define( 'STREAM_CHUNK_LIMIT',	50000 );


/***********************************************************
 *  Caution editing below
 ***********************************************************/



/**
 *  Helpers
 **/

/**
 *  Filter number within min and max range, inclusive
 *  
 *  @param mixed	$val		Given default value
 *  @param int		$min		Minimum, returned if less than this
 *  @param int		$max		Maximum, returned if greater than this
 *  @return int
 */
function intRange( $val, int $min, int $max ) : int {
	$out = ( int ) $val;
	
	return 
	( $out > $max ) ? $max : ( ( $out < $min ) ? $min : $out );
}

/**
 *  Safely decode JSON to array
 *  
 *  @return array
 */
function decode( string $data = '', int $depth = 10 ) : array {
	if ( empty( $data ) ) {
		return [];
	}
	$depth	= intRange( $depth, 1, 50 );
	$out	= 
	\json_decode( 
		\utf8_encode( $data ), true, $depth, 
		\JSON_BIGINT_AS_STRING
	);
	
	if ( empty( $out ) || false === $out ) {
		return [];
	}
	
	return $out;
}

/**
 *  Path prefix slash (/) helper
 */
function slashPath( string $path, bool $suffix = false ) : string {
	return $suffix ?
		\rtrim( $path, '/\\' ) . '/' : 
		'/'. \ltrim( $path, '/\\' );
}

/**
 *  Split a block of text into an array of lines
 *  
 *  @param string	$text	Raw text to split into lines
 *  @param int		$lim	Max line limit, defaults to unlimited
 *  @param bool		$tr	Also trim lines if true
 *  @return array
 */
function lines( string $text, int $lim = -1, bool $tr = true ) : array {
	return $tr ?
	\preg_split( 
		'/\s*\R\s*/', 
		trim( $text ), 
		$lim, 
		\PREG_SPLIT_NO_EMPTY 
	) : 
	\preg_split( '/\R/', $text, $lim, \PREG_SPLIT_NO_EMPTY );
}

/**
 *  Helper to turn items (one per line) into a unique value array
 *  
 *  @param string	$text	Lined settings (one per line)
 *  @param int		$lim	Maximum number of items
 *  @param string	$filter	Optional filter name to apply
 *  @return array
 */
function lineSettings( string $text, int $lim, string $filter = '' ) : array {
	$ln = \array_unique( lines( $text ) );
	
	$rt = ( ( count( $ln ) > $lim ) && $lim > -1 ) ? 
		\array_slice( $ln, 0, $lim ) : $ln;
	
	return 
	( !empty( $filter ) && \is_callable( $filter ) ) ? 
		\array_map( $filter, $rt ) : $rt;
}

/**
 *  Format configuration setting as a set of lines or an array and returns filtered
 *  
 *  @param mixed	$def	Default value
 *  @param string	$map	Filter function
 */
function linedConfig( $def, string $filter ) {
	$raw = 
	\is_array( $def ) ? 
		\array_map( $filter, $def ) : 
		lineSettings( $def, -1, $filter );
	
	return \array_unique( \array_filter( $raw ) );
}

/**
 *  String to list helper
 *  
 *  @param string	$text	Input text to break into items
 *  @param bool		$lower	Convert Mixed/Uppercase text to lowercase if true
 *  @param string	$sep	String delimiter, defaults to comma
 *  @return array
 */
function trimmedList( string $text, bool $lower = false, string $sep = ',' ) : array {
	$map = \array_map( 'trim', \explode( $sep, $text ) );
	return $lower ? \array_map( 'strtolower', $map ) : $map;
}

/**
 *  Suhosin aware checking for function availability
 *  
 *  @param string	$func	Function name
 *  @return bool		True If the function isn't available 
 */
function missing( $func ) : bool {
	static $exts;
	static $blocked;
	static $fn	= [];
	if ( isset( $fn[$func] ) ) {
		return $fn[$func];
	}
	
	if ( \extension_loaded( 'suhosin' ) ) {
		if ( !isset( $exts ) ) {
			$exts = \ini_get( 'suhosin.executor.func.blacklist' );
		}
		if ( !empty( $exts ) ) {
			if ( !isset( $blocked ) ) {
				$blocked = trimmedList( $exts, true );
			}
			
			$search		= \strtolower( $func );
			
			$fn[$func]	= (
				false	== \function_exists( $func ) && 
				true	== \array_search( $search, $blocked ) 
			);
		}
	} else {
		$fn[$func] = !\function_exists( $func );
	}
	
	return $fn[$func];
}

/**
 *  Filtering and formatting
 */

/**
 *  Apply uniform encoding of given text to UTF-8
 *  
 *  @param string	$text	Raw input
 *  @param bool		$ignore Discard unconvertable characters (default)
 *  @return string
 */
function utf( string $text, bool $ignore = true ) : string {
	$out = $ignore ? 
		\iconv( 'UTF-8', 'UTF-8//IGNORE', $text ) : 
		\iconv( 'UTF-8', 'UTF-8', $text );
	
	return ( false === $out ) ? '' : $out;
}

/**
 *  Strip unusable characters from raw text/html and conform to UTF-8
 *  
 *  @param string	$html	Raw content body to be cleaned
 *  @param bool		$entities Convert to HTML entities
 *  @return string
 */
function pacify( 
	string		$html, 
	bool		$entities	= false 
) : string {
	$html		= utf( \trim( $html ) );
	
	// Remove control chars except linebreaks/tabs etc...
	$html		= 
	\preg_replace(
		'/[\x00-\x08\x0B\x0C\x0E-\x1F\x80-\x9F]/u', '', $html
	);
	
	// Non-characters
	$html		= 
	\preg_replace(
		'/[\x{fdd0}-\x{fdef}]/u', '', $html
	);
	
	// UTF unassigned, formatting, and half surrogate pairs
	$html		= 
	\preg_replace(
		'/[\p{Cs}\p{Cf}\p{Cn}]/u', '', $html
	);
		
	// Convert Unicode character entities?
	if ( $entities && !missing( 'mb_convert_encoding' ) ) {
		$html	= 
		\mb_convert_encoding( 
			$html, 'HTML-ENTITIES', 'UTF-8' 
		);
	}
	
	return \trim( $html );
}


/**
 *  HTML safe character entities in UTF-8
 *  
 *  @param string	$v	Raw text to turn to HTML entities
 *  @param bool		$quotes	Convert quotes (defaults to true)
 *  @param bool		$spaces	Convert spaces to "&nbsp;*" (defaults to true)
 *  @return string
 */
function entities( 
	string		$v, 
	bool		$quotes	= true,
	bool		$spaces	= true
) : string {
	if ( $quotes ) {
		$v	=
		\htmlentities( 
			utf( $v, false ), 
			\ENT_QUOTES | \ENT_SUBSTITUTE, 
			'UTF-8'
		);
	} else {
		$v =  \htmlentities( 
			utf( $v, false ), 
			\ENT_NOQUOTES | \ENT_SUBSTITUTE, 
			'UTF-8'
		);
	}
	if ( $spaces ) {
		return 
		\strtr( $v, [ 
			' ' => '&nbsp;',
			'	' => '&nbsp;&nbsp;&nbsp;&nbsp;'
		] );
	}
	return $v;
}

/**
 *  Filter URL
 *  This is not a 100% foolproof method, but it's better than nothing
 *  
 *  @param string	$txt	Raw URL attribute value
 *  @param bool		$xss	Filter XSS possibilities
 *  @return string
 */
function cleanUrl( 
	string		$txt, 
	bool		$xss		= true
) : string {
	// Nothing to clean
	if ( empty( $txt ) ) {
		return '';
	}
	
	// Default filter
	if ( \filter_var( $txt, \FILTER_VALIDATE_URL ) ) {
		// XSS filter
		if ( $xss ) {
			if ( !\preg_match( 
				'~^(http|ftp)(s)?\:\/\/((([\pL\pN\-]{1,25})(\.)?){2,9})($|\/.*$){4,255}$~i', 
				$txt 
			) ){
				return '';
			}	
		}
		
		if ( 
			\preg_match( '/(<(s(?:cript|tyle)).*?)/ism', $txt ) || 
			\preg_match( '/(document\.|window\.|eval\(|\(\))/ism', $txt ) || 
			\preg_match( '/(\\~\/|\.\.|\\\\|\-\-)/sm', $txt ) 
		) {
			return '';
		}
		
		// Return as/is
		return  $txt;
	}
	
	return entities( $txt, false, false );
}

/**
 *  Convert all spaces to single character
 *  
 *  @param string	$text		Raw text containting mixed space types
 *  @param string	$rpl		Replacement space, defaults to ' '
 *  @param string	$br		Preserve line breaks
 *  @return string
 */
function unifySpaces( string $text, string $rpl = ' ', bool $br = false ) : string {
	return $br ?
		\preg_replace( 
			'/[ \t\v\f]+/', $rpl, pacify( $text ) 
		) : 
		\preg_replace( '/[[:space:]]+/', $rpl, pacify( $text ) );
}

/**
 *  Make text completely bland by stripping punctuation, 
 *  spaces and diacritics (for further processing)
 *  
 *  @param string	$text		Raw input text
 *  @param bool		$nospecial	Remove special characters if true
 *  @return string
 */
function bland( string $text, bool $nospecial = false ) : string {
	$text = \strip_tags( unifySpaces( $text ) );
	
	if ( $nospecial ) {
		return \preg_replace( 
			'/[^\p{L}\p{N}\-\s_]+/', '', \trim( $text ) 
		);
	}
	return \trim( $text );
}

/**
 *  Configuration handling
 */

/**
 *  Quoted security policy attribute helper
 *   
 *  @param string	$atr	Security policy parameter
 *  @return string
 */
function quoteSecAttr( string $atr ) : string {
	// Safe allow list
	static $allow	= [ 'self', 'src', 'none' ];
	$atr		= \trim( unifySpaces( $atr ) );
	
	return 
	\in_array( $atr, $allow ) ? 
		$atr : '"' . cleanUrl( $atr ) . '"'; 
}

/**
 *  Parse security policy attribute value
 *  
 *  @param string	$key	Permisisons policy identifier
 *  @param mixed	$policy	Policy value(s)
 *  @return string
 */
function parsePermPolicy( string $key, $policy = null ) : string {
	// No value? Send empty set E.G. "interest-cohort=()"
	if ( empty( $policy ) ) {
		return bland( $key, true ) . '=()';
	}
	
	// Send specific value(s) E.G. "fullscreen=(self)"
	return 
	bland( $key, true ) . '=(' . 
	( \is_array( $policy ) ? 
		\implode( ' ', \array_map( 'quoteSecAttr', $policy ) ) : 
		quoteSecAttr( ( string ) $policy ) ) . 
	')';
}

/**
 *  Content Security and Permissions Policy settings
 *  
 *  @param string	$policy		Security policy header
 *  @return string
 */
function securityPolicy( string $policy ) : string {
	static $p;
	static $r	= [];
	
	// Load defaults
	if ( !isset( $p ) ) {
		$p = decode( \SECPOLICY );
	}
	
	switch ( $policy ) {
		case 'common':
		case 'common-policy':
			if ( isset( $r['common'] ) ) {
				return $r['common'];
			}
			
			// Common header override
			$cfj = 
			linedConfig( 
				$p['common-policy'] ?? [], 
				'bland' 
			);
			$r['common'] = \implode( "\n", $cfj );
			
			return $r['common'];
			
		case 'permissions':
		case 'permissions-policy':
			if ( isset( $r['permissions'] ) ) {
				return $r['permissions'];
			}
			
			$prm = [];
			$def = $p['permissions-policy'] ?? [];
			foreach ( $def as $k => $v ) {
				$prm[]	= parsePermPolicy( $k, $v );
			}
			
			$r['permissions'] = \implode( ', ', $prm );
			return $r['permissions'];
			
		case 'content-security':
		case 'content-security-policy':
			if ( isset( $r['content'] ) ) {
				return $r['content'];
			}
			$csp = '';
			$cjp = $p['content-security-policy'] ?? [];
			
			// Approved frame ancestors ( for embedding media )
			$frm = 
			\implode( ' ', 
				linedConfig( 
					\FRAME_WHITELIST, 
					'cleanUrl' 
				) 
			);
			
			// Append sources to frame-ancestors policy setting
			foreach ( $cjp as $k => $v ) {
				$csp .= 
				( 0 == \strcmp( $k, 'frame-ancestors' ) ) ? 
					"$k $v $frm;" : "$k $v;";
			}
			$r['content'] = \rtrim( $csp, ';' );
			return $r['content'];
	}
}


/**
 *  Request handling
 */

/**
 *  Get full request URI
 *  
 *  @return string
 */
function getURI() : string {
	static $uri;
	if ( isset( $uri ) ) {
		return $uri;
	}
	$uri	= \strtr( $_SERVER['REQUEST_URI'] ?? '', [ '\\' => '/' ] );
	$uri	= pacify( \trim( $uri, "/." ) );
	return $uri;
}

/**
 *  Current client request method
 *  
 *  @return string
 */
function getMethod() : string {
	static $method;
	if ( isset( $method ) ) {
		return $method;
	}
	$method = 
	\strtolower( trim( $_SERVER['REQUEST_METHOD'] ?? '' ) );
	return $method;
}

/**
 *  Get or guess current server protocol
 *  
 *  @param string	$assume		Default protocol to assume if not given
 *  @return string
 */
function getProtocol( string $assume = 'HTTP/1.1' ) : string {
	static $pr;
	if ( isset( $pr ) ) {
		return $pr;
	}
	$pr = $_SERVER['SERVER_PROTOCOL'] ?? $assume;
	return $pr;
}

/**
 *  Get requested file range, return [-1] if range was invalid
 *  
 *  @return array
 */
function getFileRange() : array {
	static $ranges;
	if ( isset( $ranges ) ) {
		return $ranges;
	}
	
	$fr = $_SERVER['HTTP_RANGE'] ?? '';
	if ( empty( $fr ) ) {
		return [];
	}
	
	// Range(s) too long 
	if ( strlen( $fr ) > 180 ) {
		return [-1];
	}
	
	// Check multiple ranges, if given
	$rg = \preg_match_all( 
		'/bytes=(^$)|(?<start>\d+)?(\s+)?-(\s+)?(?<end>\d+)?/is',
		$fr,
		$m
	);
	
	// Invalid range syntax?
	if ( false === $rg ) {
		return [-1];
	}
	
	$starting	= $m['start'] ?? [];
	$ending		= $m['end'] ?? [];
	$sc		= count( $starting );
	
	// Too many or too few ranges or starting / ending mismatch
	if ( $sc > 10 || $sc == 0 || $sc != count( $ending ) ) {
		return [-1];
	}
	
	\asort( $starting );
	\asort( $ending );
	$rx = [];
	
	// Format ranges
	foreach ( $ending as $k => $v ) {
		
		// Specify 0 for starting if empty and -1 if end of file
		$rx[$k] = [ 
			empty( $starting[$k] ) ? 0 : \intval( $starting[$k] ), 
			empty( $ending[$k] ) ? -1 : \intval( $ending[$k] )
		];
		
		// If start is larger or same as ending and not EOF...
		if ( $rx[$k][0] >= $rx[$k][1] && $rx[$k][1] != -1 ) {
			return [-1];
		}
	}
	
	// Sort by lowest starting value
	usort( $rx, function( $a, $b ) {
		return $a[0] <=> $b[0];
	} );
	
	// End of file range found if true
	$eof = 0;
	
	// Check for overlapping/redundant ranges (preserves bandwidth)
	foreach ( $rx as $k => $v ) {
		// Nothing to check yet
		if ( !isset( $rx[$k-1] ) ) {
			continue;
		}
		// Starting range is lower than or equal previous start
		if ( $rx[$k][0] <= $rx[$k-1][0] ) {
			return [-1];
		}
		
		// Ending range lower than previous ending range
		if ( $rx[$k][1] <= $rx[$k-1][1] ) {
			// Special case EOF and it hasn't been found yet
			if ( $rx[$k][1] == -1 && $eof == 0) {
				$eof = 1;
				continue;
			}
			return [-1];
		}
		
		// Duplicate EOF ranges
		if ( $rx[$k][1] == -1 && $eof == 1 ) {
			return [-1];
		}
	}
	
	$ranges = $rx;
	return $rx;
}

/**
 *  Check if sent path is safe and not excluded from restricted pages
 *  
 *  @param string	$path	Raw user sent path
 *  @return bool
 */
function filterPath( string $path ) : bool {
	return \preg_match( \PATH_PATTERN, $path ) ? true : false;
}

/**
 *  Verify if given directory path is a subfolder of root
 *  
 *  @param string	$path	Folder path to check
 *  @param string	$root	Full parent folder path
 *  @return string Empty if directory traversal or other issue found
 */
function filterDir( $path, string $root ) {
	if ( \strpos( $path, '..' ) ) {
		return '';
	}
	
	$lp	= \strlen( $root );
	if ( \strlen( $path ) < $lp ) { 
		return ''; 
	}
	$pos	= \strpos( $path, $root );
	if ( false === $pos ) {
		return '';
	}
	$path	= \substr( $path, $pos + $lp );
	return \trim( $path ?? '' );
}

/**
 *  Adjust text mime-type based on path extension
 *  
 *  @param mixed	$mime		Discovered mime-type
 *  @param string	$path		File name or path name
 *  @param mixed	$ext		Given extension (optional)
 *  @return string Adjusted mime type
 */
function adjustMime( $mime, $path, $ext = null ) : string {
	if ( false === $mime ) {
		return 'application/octet-stream';
	}
	
	// Override text types with special extensions
	// Required on some OSes like OpenBSD
	if ( 0 === \strcasecmp( $mime, 'text/plain' ) ) {
		$e	= 
		$ext ?? \pathinfo( $path, \PATHINFO_EXTENSION ) ?? '';
		
		switch( \strtolower( $e ) ) {
			case 'css':
				return 'text/css';
				
			case 'js':
				return 'text/javascript';
				
			case 'svg':
				return 'image/svg+xml';
				
			case 'vtt':
				return 'text/vtt';
		}
	}
	
	return \strtolower( $mime );
}

/**
 *  File mime-type detection helper
 *  
 *  @param string	$path	Fixed file path
 *  @return string
 */
function detectMime( string $path ) : string {
	if ( !missing( 'mime_content_type' ) ) { 
		return adjustMime( \mime_content_type( $path ), $path );
	}
	
	$info	= \finfo_open( \FILEINFO_MIME_TYPE );
	$mime	= adjustMime( \finfo_open( $info, $path ), $path );
	
	\finfo_close( $info );
	return $mime;
}

/**
 *  Generate ETag from file path
 *  
 *  @param string	$path	Raw file location
 *  @return string
 */
function genEtag( string $path ) : array {
	static $tags		= [];
	
	if ( isset( $tags[$path] ) ) {
		return $tags[$path];
	}
	
	$tags[$path]		= [];
	
	// Find file size header
	$tags[$path]['fsize']	= \filesize( $path );
	
	// Send empty on failure
	if ( false === $tags[$path]['fsize'] ) {
		$tags[$path]['fmod'] = 0;
		$tags[$path]['etag'] = '';
		return $tags;
	}
	
	// Similar to Nginx ETag algo: 
	// Lowercase hex of last modified date and filesize
	$tags[$path]['fmod']	= \filemtime( $path );
	if ( false !== $tags[$path]['fmod'] ) {
		$tags[$path]['etag']	= 
		\sprintf( '%x-%x', 
			$tags[$path]['fmod'], 
			$tags[$path]['fsize']
		);
	} else {
		$tags[$path]['etag'] = '';
	}
	
	return $tags[$path];
}

/**
 *  Check If-None-Match header against given ETag
 *  
 *  @return true if header not set or if ETag doesn't match
 */
function ifModified( string $etag ) : bool {
	$mod = $_SERVER['HTTP_IF_NONE_MATCH'] ?? '';
	
	if ( empty( $mod ) ) {
		return true;
	}
	
	return ( 0 !== \strcmp( $etag, $mod ) );
}

/**
 *  Stream content in chunks within starting and ending limits
 *  
 *  @param resource	$stream		Open file stream
 *  @param int		$int		Starting offset
 *  @param int		$end		Ending offset or end of file
 */
function streamChunks( &$stream, int $start, int $end ) {
	// Default chunk size
	$csize	= \STREAM_CHUNK_SIZE;
	$sent	= 0;
	
	fseek( $stream, $start );
	
	while ( !feof( $stream ) ) {
		
		// Check for aborted connection between flushes
		if ( \connection_aborted() ) {
			fclose( $stream );
			$stream = false;
			visitorAbort();
		}
		
		// End reached
		if ( $sent >= $end ) {
			flushOutput();
			break;
		}
		
		// Change chunk size when approaching the end of range
		if ( $sent + $csize > $end ) {
			$csize = ( $end + 1 ) - $sent;
		}
		
		// Reset limit while streaming
		\set_time_limit( 30 );
		
		$buf = fread( $stream, $csize );
		echo $buf;
		
		$sent += strlen( $buf );
		flushOutput();
	}
}

/**
 *  Set expires header
 */
function setCacheExp( int $ttl ) {
	\header( 'Cache-Control: max-age=' . $ttl, true );
	\header( 'Expires: ' . 
		\gmdate( 'D, d M Y H:i:s', time() + $ttl ) . 
		' GMT', true );
}

/**
 *  Safety headers
 *  
 *  @param string	$chk	Content checksum
 *  @param bool		$send	CSP Send Content Security Policy header
 *  @param bool		$type	Send content type (html)
 */
function preamble(
	string	$chk		= '', 
	bool	$send_csp	= true,
	bool	$send_type	= true
) {
	if ( $send_type ) {
		\header( HTML_TYPE, true );
	}
	
	// Set common policy headers
	$chead	= explode( "\n", securityPolicy( 'common-policy' ) );
	foreach ( $chead as $h ) {
		\header( $h, true );
	}
	
	// Set default permissions policy header
	$perms = securityPolicy( 'permissions-policy' );
	if ( !empty( $perms ) ) {
		\header( 'Permissions-Policy: ' . $perms , true );
	}
	
	// If sending CSP and content checksum isn't used
	if ( $send_csp ) {
		$csp = securityPolicy( 'content-security-policy' );
		if ( !empty( $csp ) ) {
			\header( 'Content-Security-Policy: ' . $csp, true );
		}
	
	// Content checksum used
	} elseif ( !empty( $chk ) ) {
		\header( 
			"Content-Security-Policy: default-src " .
			"'self' '{$chk}'", 
			true
		);
	}
}

/**
 *  Send list of allowed methods in "Allow:" header
 */
function sendAllowHeader() {
	\header( 'Allow: GET, HEAD, OPTIONS', true );
}

/**
 *  Create HTTP status code message
 *  
 *  @param int		$code		HTTP Status code
 */
function httpCode( int $code ) {
	$green	= [
		200, 201, 202, 204, 205, 206, 
		300, 301, 302, 303, 304,
		400, 401, 403, 404, 405, 406, 407, 409, 410, 411, 412, 
		413, 414, 415,
		500, 501
	];
	
	if ( \in_array( $code, $green ) ) {
		\http_response_code( $code );
		
		// Some codes need additional headers
		switch( $code ) {
			case 204:
			case 405:
				sendAllowHeader();
				break;
				
			case 410:
				// Gone status set to long expiration
				setCacheExp( 31536000 );
				break;
		}
		
		return;
	}
	
	$prot = getProtocol();
	
	// Special cases
	switch( $code ) {
		case 425:
			\header( "$prot $code " . 'Too Early' );
			return;
			
		case 429:
			\header( "$prot $code " . 
				'Too Many Requests' );
			return;
			
		case 431:
			\header( "$prot $code " . 
				'Request Header Fields Too Large' );
			return;
			
		case 503:
			\header( "$prot $code " . 
				'Service Unavailable' );
			return;
	}
}

/**
 *  Invalid file range error page helper
 */
function sendRangeError() {
	httpCode( 416 );
	die();
}

/**
 *  Clean the output buffer without flushing
 *  
 *  @param bool		$ebuf		End buffers
 */
function cleanOutput( bool $ebuf = false ) {
	if ( $ebuf ) {
		while ( \ob_get_level() > 0 ) {
			\ob_end_clean();
		}
		return;	
	}
	
	while ( \ob_get_level() && \ob_get_length() > 0 ) {
		\ob_clean();
	}
}

/**
 *  Flush and optionally end output buffers
 *  
 *  @param bool		$ebuf		End buffers
 */
function flushOutput( bool $ebuf = false ) {
	if ( $ebuf ) {
		while ( \ob_get_level() > 0 ) {
			\ob_end_flush();
		}
	} else {
		while ( \ob_get_level() > 0 ) {
			\ob_flush();	
		}
	}
	flush();
}

/**
 *  Visitor disconnect event helper
 */
function visitorAbort() {
	cleanOutput( true );
	if ( !\headers_sent() ) {
		httpCode( 205 );
	}
	die();
}

/**
 *  Handle ranged file request
 *  
 *  @param string	$path		Absolute file path
 *  @param bool		$dosend		Send file ranges if true
 *  @return bool
 */
function sendFileRange( string $path, bool $dosend ) : bool {
	$frange	= getFileRange();
	$fsize	= filesize( $path );
	$fend	= $fsize - 1;
	$totals	= 0;
	
	// Check if any ranges are outside file limits
	foreach ( $frange as $r ) {
		if ( $r[0] >= $fend || $r[1] > $fend ) {
			sendRangeError();
		}
		$totals += ( $r[1] > -1 ) ? 
			( $r[1] - $r[0] ) + 1 : ( $fend - $r[0] ) + 1;
	}
	
	if ( !$dosend ) {
		return true;
	}
	
	$stream	= fopen( $path, 'rb' );
	if ( false === $stream ) {
		// Error opening path
		die();
	}
	\stream_set_blocking( $stream, false );
	
	// Prepare partial content
	// Send static headers
	preamble( '', true, false );
	
	$mime	= detectMime( $path );
	
	// Generate boundary
	$bound	= \base64_encode( \hash( 'sha1', $path . $fsize, true ) );
	\header(
		"Content-Type: multipart/byteranges; boundary={$bound}",
		true
	);
	
	\header( "Content-Length: {$totals}", true );
	
	// Send any headers and end buffering
	flushOutput( true );
	
	// Start fresh buffer
	\ob_start();
	
	$limit = 0;
	
	foreach ( $frange as $r ) {
		echo "\n--{$bound}";
		echo "Content-Type: {$mime}";
		if ( $r[1] == -1 ) {
			echo "Content-Range: bytes {$r[0]}-{$fend}/{$fsize}\n";
		} else {
			echo "Content-Range: bytes {$r[0]}-{$r[1]}/{$fsize}\n";
		}
		
		$limit = ( $r[1] > -1 ) ? $r[1] + 1 : $fsize;
		streamChunks( $stream, $r[0], $limit );
	}
	fclose( $stream );
	flushOutput( true );
	return true;
}

/**
 *  Send specific file including file type MIME
 *  
 *  @param string	$name		Exact filename to send
 */
function sendStaticContent( string $name ) {
	// Content type header
	$mime	= detectMime( $name );
	\header( "Content-Type: {$mime}", true );
	
	// Prepare content length and etag headers
	$tags	= genEtag( $name );
	$fsize	= $tags['fsize'];
	$etag	= $tags['etag'];
	$stream = false;
	
	if ( false !== $fsize ) {
		// Prepare resource if this is a large file
		if ( $fsize > \STREAM_CHUNK_LIMIT ) {
			$stream = fopen( $name, 'rb' );
			if ( false === $stream ) {
				die();
			}
			\stream_set_blocking( $stream, false );
		}
		\header( "Content-Length: {$fsize}", true );
		if ( !empty( $etag ) ) {
			\header( "ETag: {$etag}", true );
		}
	}
	
	// Send static headers
	preamble( '', true, false );
	
	setCacheExp( \FILE_CACHE_TTL );
	flushOutput( true );
	
	// Only send file on etag difference
	if ( ifModified( $etag ) ) {
		if ( $stream !== false ) {
			streamChunks( $stream, 0, $fsize );
			fclose( $stream );
			die();
		}
		\readfile( $name );
	}
	
	// End execution
	die();
}

/**
 *  Send specific file including file type MIME
 *  
 *  @param string	$name		Exact filename to send
 *  @param string	$default	Fallback if file isn't found
 */
function sendContent( string $name, string $default = '' ) {
	if ( !\file_exists( $name ) ) {
		// Nothing to send
		preamble( '', true, false );
		die( $default );
	}
	
	// Send common headers
	preamble();
	
	setCacheExp( \CONTENT_CACHE_TTL );
	flushOutput( true );
	
	\readfile( $name );
	
	// End execution
	die();
}

/**
 *  Send supported error condition content
 *  
 *  @param int		$code	Error code, defaults to 404
 *  @param bool		$send	Send content, if true
 */
function sendError( int $code, bool $send = true ) {
	static $msg = [
		400 => 'Bad Request',
		401 => 'Unauthorized',
		403 => 'Forbidden',
		405 => 'Method Not Allowed',
		410 => 'Gone',
		414 => 'URI Too Long',
		415 => 'Unsupported Media Type'
	];
	
	if ( \array_key_exists( $code, $msg ) ) {
		httpCode( $code );
		if ( $send ) {
			sendContent( 
				\CONTENT . $code . '.html', $msg[$code]
			);
		}
		die();
	}
	
	// Default
	httpCode( 404 );
	if ( $send ) {
		sendContent( 
			\CONTENT . '404.html', 
			'Not Found' 
		);
	}
	die();
}

/**
 *  Get all files in relative path
 *  
 *  @param string	$root	Relative content root folder
 *  @return array
 */
function contentFolder( string $root ) : array {
	static $st =	[];
	
	$root = slashPath( $root, true );
	if ( isset( $st[$root] ) ) {
		return $st[$root];
	}
	
	try {
		$dir	= 
		new \RecursiveDirectoryIterator( 
			$root, 
			\FilesystemIterator::FOLLOW_SYMLINKS | 
			\FilesystemIterator::KEY_AS_FILENAME
		);
		
		$it		= 
		new \RecursiveIteratorIterator( 
			$dir, 
			\RecursiveIteratorIterator::LEAVES_ONLY,
			\RecursiveIteratorIterator::CATCH_GET_CHILD 
		);
		
		$it->rewind();
		
		// Temp array for sorting
		$tmp	= \iterator_to_array( $it, true );
		\rsort( $tmp, \SORT_NATURAL );
		
		$st[$root]	= $tmp;
		return $tmp;
		
	} catch( \Exception $e ) {
		return [];
	}
}

/**
 *  Check if this is a whitelisted file extension
 *  
 *  @param SplFileInfo	$file	File info object
 */
function isStatic( $file ) : bool {
	if ( $ext = $file->getExtension() ) {
		return 
		(
			0 == strcasecmp( $ext, 'html' ) || 
			0 == strcasecmp( $ext, 'htm' )
		) ? false : true;
	}
	return true;
}

/** 
 *  Content HTML page finder
 *  
 *  @param string	$dir	Root directory
 *  @param string	$page	Relative sub page path
 *  @param bool		$send	Send content if true
 */
function contentPage( $dir, $page, $send ) {
	$it = contentFolder( $dir );
	if ( empty( $it ) ) {
		return;
	}
	
	foreach ( $it as $file ) {
		// Skip directories
		if ( $file->isDir() ) {
			continue;
		}
		
		$raw	= $file->getRealPath();
		
		// Location not within specified directory?
		$path	= filterDir( $raw, $dir );
		if ( empty( $path ) ) {
			continue;
		}
		
		// Matches requested path?
		if ( 0 == strcasecmp( $path, $page ) ) {
			if ( isStatic( $file ) ) {
				// Check if ranged request
				$frange	= getFileRange();
				if ( empty( $frange ) ) {
					// Full content
					httpCode( 200 );
					if ( $send ) {
						sendStaticContent( $raw );
					}
				} else {
					// Range wasn't satisfiable
					if ( \in_array( -1, $frange ) ) {
						sendRangeError();
					}
					
					// Partial content
					httpCode( 206 );
					sendFileRange( $raw, $send );
				}
			} else {
				httpCode( 200 );
				if ( $send ) {
					sendContent( $raw );
				}
			}
			die();
		}
	}
}

/**
 *  Restrict handling to given path constraints
 *  
 *  @param string	$path	Visitor requested URI
 *  @param bool		$send	Send content if found when true
 */
function pathLimits( string $path, bool $send ) {
	if ( empty( $path ) ) {
		return;
	}
	
	// Limit to maximum folder depth
	$depth	= intRange( \MAX_DEPTH, 1, 500 );
	if ( count( \explode( '/', $path ) ) > $depth ) {
		sendError( 414, $send );
	}
	
	$ext	= 
	\pathinfo( $path, \PATHINFO_EXTENSION ) ?? '';
	
	// No type to check?
	if ( empty( $ext ) ) {
		return;
	}
	
	$wext	= trimmedList( \MEDIA_TYPES, true );
	if ( \in_array( \strtolower( $ext ), $wext ) ) {
		return;
	}
	
	// Not an allowed media type
	sendError( 415, $send );
}

/**
 *  Main URI content handler
 *  
 *  @param bool		$send	Send content if found when true
 */
function getContent( bool $send ) {
	$path	= getURI();
	pathLimits( $path, $send );
	
	// Send homepage if no path specified
	if ( empty( $path ) ) {
		httpCode( 200 );
		if ( $send ) {
			sendContent( 
				slashPath( \CONTENT, true ) . 
					'index.html', 
				'Home' 
			);
		}
		
		die();
	}
	
	// Filter path
	if ( false === filterPath( $path ) ) {
		sendError( 400, $send );
	}
	
	// Try uploaded file, content page, or subfolder index
	contentPage( \UPLOADS, $path, $send );
	contentPage( \CONTENT, $path . '.html', $send );
	contentPage( \CONTENT, slashPath( $path, true ) . 'index.html', $send );
	
	// If none succeeded, send Not Found
	sendError( 404, $send );
}

/**
 *  Handle options output
 */
function getOptions() {
	httpCode( 204 );
	setCacheExp( 604800 );
	die();
}

/**
 *  Remove previously set headers, output
 */
function scrubOutput() {
	// Scrub output buffer
	cleanOutput();
	\header_remove( 'Pragma' );
	
	// This is best done in php.ini : expose_php = Off
	\header( 'X-Powered-By: nil', true );
	\header_remove( 'X-Powered-By' );
	
	// This isn't succssful sometimes, but try anyway
	\header_remove( 'Server' );
}

/**
 *  Begin
 **/
scrubOutput();

// Send appropriate content and end execution
switch ( getMethod() ) {
	// Send content	
	case 'get':
		getContent( true );
	
	// Only send response headers, but nothing else
	case 'head':
		getContent( false );
	
	// Send allowed methods
	case 'options':
		getOptions();
		
	// Send allowed methods header for everything else
	default:
		sendError( 405 );
}

