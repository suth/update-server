WP Update Server
================

Custom update API package for WordPress plugins and themes.

Based on [WP Update Server by Yahnis Elsts](https://github.com/YahnisElsts/wp-update-server)

Features
--------
**Provide updates for plugins and themes.**    

From the users perspective, the updates work just like they do with plugins and themes listed in the official WordPress.org directory.

**Easy to integrate** with existing plugins and themes.

All it takes is about 5 lines of code. See the [plugin update checker](http://w-shadow.com/blog/2010/09/02/automatic-updates-for-any-plugin/) and [theme update checker](http://w-shadow.com/blog/2011/06/02/automatic-updates-for-commercial-themes/) docs for details, or just scroll down to the "Getting Started" section for the short version.

**Designed for extensibility.**

Want to secure your upgrade download links? Or use a custom logger or cache? Maybe your plugin doesn't have a standard `readme.txt` and you'd prefer to load the changelog and other update meta from the database instead? Create your own customized server by extending the `Wpup_UpdateServer` class. See examples below.
  	
Getting Started
---------------

### Setting Up the Server
This part of the setup process is identical for both plugins and themes. For the sake of brevity, I'll describe it from the plugin perspective.

1. Upload the `wp-update-server` directory to your site. You can rename it to something else (e.g. `updates`) if you want. 
2. Make the `cache` and `logs` subdirectories writable by PHP.
3. Create a Zip archive of your plugin's directory. The name of the archive must be the same as the name of the directory + ".zip".
4. Copy the Zip file to the `packages` subdirectory.
5. Verify that the API is working by visiting `/wp-update-server/?action=get_metadata&slug=plugin-directory-name` in your browser. You should see a JSON document containing various information about your plugin (name, version, description and so on).

**Tip:** Use the JSONView extension ([Firefox](https://addons.mozilla.org/en-US/firefox/addon/10869/),  [Chrome](https://chrome.google.com/webstore/detail/jsonview/chklaanhfefbnpoihckbnefhakgolnmc)) to pretty-print JSON in the browser.

When creating the Zip file, make sure the plugin files are inside a directory and not at the archive root. For example, lets say you have a plugin called "My Cool Plugin" and it lives inside `/wp-content/plugins/my-cool-plugin`. The ZIP file should be named `my-cool-plugin.zip` and it should contain the following:

```
/my-cool-plugin
    /css
    /js
    /another-directory
    my-cool-plugin.php
    readme.txt
    ...
```

If you put everything at the root, update notifications may show up just fine, but you will run into inexplicable problems when you try to install an update because WordPress expects plugin files to be inside a subdirectory.

### Integrating with Plugins

See the [update checker docs](http://w-shadow.com/blog/2010/09/02/automatic-updates-for-any-plugin/) for detailed usage instructions and and examples.

### Integrating with Themes

See the [theme update checker docs](http://w-shadow.com/blog/2011/06/02/automatic-updates-for-commercial-themes/) for information and examples.
	
## Advanced Topics

### Logging

The server logs all API requests to the `/logs/request.log` file. Each line represents one request and is formatted like this:

```
[timestamp] IP_address	action	slug	installed_version	wordpress_version	site_url	query_string
```

Missing or inapplicable fields are replaced with a dash "-". The logger extracts the WordPress version and site URL from the "User-Agent" header that WordPress adds to all requests sent via its HTTP API. These fields will not be present if you make an API request via the browser or if the header is removed or overriden by a plugin (some security plugins do that).

### Extending the server

To customize the way the update server works, create your own server class that extends [Wpup_UpdateServer](includes/Wpup/UpdateServer.php) and edit the init script (that's `index.php` if you're running the server as a standalone app) to load and use the new class.

For example, lets make a simple modification that disables downloads and removes the download URL from the plugin details returned by the update API. This could serve as a foundation for a custom server that requires authorization to download an update.

Add a new file `MyCustomServer.php` to `wp-update-server`:

```php
class MyCustomServer extends Wpup_UpdateServer {
	protected function filterMetadata($meta, $request) {
		$meta = parent::filterMetadata($meta, $request);
		unset($meta['download_url']);
		return $meta;
	}
	
	protected function actionDownload(Wpup_Request $request) {
		$this->exitWithError('Downloads are disabled.', 403);
	}
}
```

Edit `index.php` to use the new class:

```php
require __DIR__ . '/loader.php';
require __DIR__ . '/MyCustomServer.php';
$server = new MyCustomServer();
$server->handleRequest();
```

### Running the server from another script

While the easiest way to use the update server is to run it as a standalone application, that's not the only way to do it. If you need to, you can also load it as a third-party library and create your own server instance. This lets you  filter and modify query arguments before passing them to the server, run it from a WordPress plugin, use your own server class, and so on.

To run the server from your own application you need to do three things:

1. Include `/wp-update-server/loader.php`.
2. Create an instance of `Wpup_UpdateServer` or a class that extends it.
3. Call the `handleRequest($queryParams)` method.

Here's a basic example plugin that runs the update server from inside WordPress:
```php
<?php
/*
Plugin Name: Plugin Update Server
Description: An example plugin that runs the update API.
Version: 1.0
Author: Yahnis Elsts
Author URI: http://w-shadow.com/
*/

class ExamplePlugin {
	protected $updateServer;

	public function __construct() {
		require_once __DIR__ . '/path/to/wp-update-server/loader.php';
		$this->updateServer = new Wpup_UpdateServer(home_url('/'));
		
		add_filter('query_vars', array($this, 'addQueryVariables'));
		add_action('template_redirect', array($this, 'handleUpdateApiRequest'));
	}
	
	public function addQueryVariables($queryVariables) {
		$queryVariables = array_merge($queryVariables, array(
			'update_action',
			'update_slug',
		));
		return $queryVariables;
	}
	
	public function handleUpdateApiRequest() {
		if ( get_query_var('update_action') ) {
			$this->updateServer->handleRequest(array(
				'action' => get_query_var('update_action'),
				'slug'   => get_query_var('update_slug'),
			));
		}
	}
}

$examplePlugin = new ExamplePlugin();
```

**Note:** If you intend to use something like the above in practice, you'll probably want to override `Wpup_UpdateServer::generateDownloadUrl()` to use your own URL structure or query variable names.

### Securing download links

See [this blog post](http://w-shadow.com/blog/2013/03/19/plugin-updates-securing-download-links/) for a high-level overview and some brief examples.
