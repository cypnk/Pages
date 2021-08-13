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


// Default HTML content type (leave as UTF-8 unless there's a good reason to change it)
define( 'HTML_TYPE', 'Content-type: text/html; charset=UTF-8' );

// Common HTML content headers (adjust as needed)
define( 'COMMON_HEADERS', <<<HEADERS
	X-Frame-Options: SAMEORIGIN
	X-XSS-Protection: 1; mode=block
	X-Content-Type-Options: nosniff
	Referrer-Policy: no-referrer, strict-origin-when-cross-origin
	Permissions-Policy: accelerometer=(none), camera=(none), fullscreen=(self), geolocation=(none), gyroscope=(none), interest-cohort=(), payment=(none), usb=(none), microphone=(none), magnetometer=(none)
	Content-Security-Policy: default-src 'none'; base-uri 'none'; frame-ancestors 'none'; form-action 'none'; font-src 'self'; style-src 'self'; script-src 'self'
HEADERS
);

// Static file headers
define( 'STATIC_HEADERS', <<<HEADERS
	X-Frame-Options: SAMEORIGIN
	X-XSS-Protection: 1; mode=block
	X-Content-Type-Options: nosniff
	Referrer-Policy: no-referrer, strict-origin-when-cross-origin
	Permissions-Policy: interest-cohort=()
	Content-Security-Policy: default-src 'self'; base-uri 'none'; frame-ancestors 'none'
HEADERS
);

// Content cache expiration (7200 = 2 hours)
define( 'CONTENT_CACHE_TTL',		7200 );

// Client-side file cache expiration (604800 = 7 days, 31536000 = 365 days)
define( 'FILE_CACHE_TTL',		604800 );

// Path matching pattern (default max 255 characters)
define( 'PATH_PATTERN', '@^/[\pL\pN_\s\.\-\/]{1,255}/?$@i' );

/**
 *  Caution editing below
 **/

/**
 *  Path prefix slash (/) helper
 */
function slashPath( string $path, bool $suffix = false ) : string {
	return $suffix ?
		\rtrim( $path, '/\\' ) . '/' : 
		'/'. \ltrim( $path, '/\\' );
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
 *  Get full request URI
 *  
 *  @return string
 */
function getURI() : string {
	static $uri;
	if ( isset( $uri ) ) {
		return $uri;
	}
	$uri	= \trim( $_SERVER['REQUEST_URI'] ?? '', "/." );
	$uri	= \strtr( $uri, [ '\\' => '/' ] );
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
	if ( \function_exists( 'mime_content_type' ) ) { 
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
function genEtag( string $path ) : string {
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
 *  Set expires header
 */
function setCacheExp( int $ttl ) {
	\header( 'Cache-Control: max-age=' . $ttl, true );
	\header( 'Expires: ' . 
		\gmdate( 'D, d M Y H:i:s', time() + $ttl ) . 
		' GMT', true );
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
	if ( false !== $tags['fsize'] ) {
		\header( "Content-Length: {$fsize}", true );
		if ( !empty( $etag ) ) {
			\header( "ETag: {$etag}", true );
		}
	}
	
	// Send static headers
	$headers = trimmedList( \STATIC_HEADERS, false, "\n" );
	foreach ( $headers as $h ) {
		\header( $h, true );
	}
	
	setCacheExp( \FILE_CACHE_TTL );
	\ob_end_flush();
	
	// Only send file on etag difference
	if ( ifModified( $etag ) ) {
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
		die( $default );
	}
	
	// Send content header
	\header( \HTML_TYPE, true );
	
	// Send common headers
	$headers = trimmedList( \COMMON_HEADERS, false, "\n" );
	foreach ( $headers as $h ) {
		\header( $h, true );
	}
	
	setCacheExp( \CONTENT_CACHE_TTL );
	\ob_end_flush();
	
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
function sendError( int $code, bool $send ) {
	static $meg = [
		400 => 'Bad Request',
		401 => 'Unauthorized',
		403 => 'Forbidden',
		410 => 'Gone'
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
 *  @param string	$file	Full file path
 */
function isStatic( string $file ) : bool {
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
			httpCode( 200 );
			if ( $send ) {
				if ( isStatic( $raw ) ) {
					sendStaticContent( $raw );
				} else {
					sendContent( $raw );
				}
			}
		}
	}
}

/**
 *  Main URI content handler
 *  
 *  @param bool		$send	Send content if found when true
 */
function getContent( bool $send ) {
	$path	= getURI();
	
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
	\ob_clean();
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
		httpCode( 405 );
		die();
}
