# Pages
A single file request handler

This is a directory content helper that builds on [Placeholder](https://github.com/cypnk/Placeholder), which allows for more than one page to be served with minimal intervention. Content is added to the /content folder, which also includes the default error files. Other static files such as images and JS are added to the /uploads folder. When a visitor reaches example.com/page, if page.html exists in the /content folder, it will be served. If *style.css* exists in the /uploads folder, it will also be served. Subfolders are supported in a similar manner. Add an *index.html* when creating a subfolder if you like.

The default URL limit is 255 charcters, however this can be extended in *index.php*.

There are no databases, cookies, sessions etc... This is a simple handler for serving HTML content and static files uploaded to a server.
