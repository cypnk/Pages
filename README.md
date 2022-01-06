# Pages
A single file request handler

This is a directory content helper that builds on [Placeholder](https://github.com/cypnk/Placeholder), which allows for more than one page to be served with minimal intervention. Content is added to the /content folder, which also includes the default error files. Other static files such as images and JS are added to the /uploads folder. When a visitor reaches example.com/page, if page.html exists in the /content folder, it will be served. If *style.css* exists in the /uploads folder, and it's referenced in page.html, it will also be served. Subfolders are supported in a similar manner. Add an *index.html* when creating a subfolder if you like.

The default URL limit is 255 charcters, however this can be extended in *index.php*.

There are no databases, cookies, sessions etc... This is a simple handler for serving HTML content and static files uploaded to a server.

## Table of contents
* [Requirements](#requirements)
* [Installation](#installation)
  * [Nginx](#nginx)
  * [OpenBSD's httpd(8) web server](#openbsds-httpd8-web-server)

## Requirements
* Webserver capable of handling URL rewrites (Apache, Nginx etc...)
* PHP Version 7.3+, 8.0+ recommended (may work on 7.2 and older, but not tested on these)
* fileinfo Extension installed or enabled in **php.ini**

## Installation
Upload the following to your webroot:
* .htaccess - Required if using the Apache web server
* *index.php* - Your homepage
* /content - Location of all your HTML pages including any error pages
* /uploads - Location of everything else you wish to serve

Configure your webserver to send all requests to *index.php*. This is very similar to the way [Bare](https://github.com/cypnk/Bare#installation) is installed.

### Nginx

The Nginx web server supports URL rewriting and file filtering. The following is a simple configuration for a site named example.com tested on Arch linux.  

Note: The pound sign(#) denotes comments. The location of **nginx.config** will depend on your platform.
```
server {
	server_name example.com;
	
	# Change this to your web root, if it's different
	root /usr/share/nginx/example.com/html;
	
	# Prevent access to special files (recommended)
	location ~\.(hta|htp|md|conf|db|sql|json|sh)\$ {
		deny all;
	}
	
	# Prevent direct access to uploads and content folders
	# Change these if they're set differently in index.php
	location /uploads {
		deny all;
	}
	
	location /content {
		deny all;
	}
	
	# Remember to put static files (I.E. .css, .js etc...)
	# in the same directory you set in UPLOADS
	
	# Send all requests (that aren't static files) to index.php
	location / {
		try_files $uri @pagehandler;
		index index.php;
	}
	
	location @pagehandler {
                rewrite ^(.*)$ /index.php;
        }
	
	# Handle php
	location ~ \.php$ {
		fastcgi_pass	unix:/run/php-fpm/php-fpm.sock;
		fastcgi_index	index.php;
		include		fastcgi.conf;
        }
}
```

### OpenBSD's httpd(8) web server

The following configuration can be used if Pages is installed as the "example.com" website (tested on OpenBSD 7.0).

Edit **/etc/httpd.conf** to add a custom server setting file:
```
include "/etc/httpd.conf.local"
```

Create **/etc/httpd.conf.local** and add the following:
```
# A site called "example.com" 
server "www.example.com" {
	alias "example.com"
  
	# listening on external addresses
	listen on egress port 80
	
	# Default directory
	directory index "index.html"
  
	# Change this to your web root, if it's different
	root "/htdocs"
  
	# Prevent access to special files (recommended)
	location "/*.hta*"		{ block }
	location "/*.htp*"              { block }
	location "/*.md*"		{ block }
	location "/*.conf*"		{ block }
	location "/*.db*"		{ block }
	location "/*.sql*"		{ block }
	location "/*.json*"		{ block }
	location "/*.sh*"		{ block }
	
	# Prevent access to uploads and content folders
	# Change these if they're set differently in index.php
	location "/uploads/*"		{ block }
	location "/content/*"		{ block }
	
	# Remember to put static files (I.E. .css, .js etc...)
	# In the same directory you set in UPLOADS
	
	# Let index.php handle all other requests
	location "/*" {
		directory index "index.php"
		
		# Change this to your web root, if it's different
		root { "/htdocs/index.php" }
		
		# Enable FastCGI handling of PHP
		fastcgi socket "/run/php-fpm.sock"
	}
}
``` 
