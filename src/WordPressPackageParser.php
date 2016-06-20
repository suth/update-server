<?php

namepace Suth\UpdateServer;

use Michelf\Markdown;

class WordPressPackageParser
{
	/**
	 * Extract headers and readme.txt data from a ZIP archive that contains a plugin or theme.
	 *
	 * Returns an associative array with these keys:
	 *  'type'   - Detected package type. This can be either "plugin" or "theme".
	 * 	'header' - An array of plugin or theme headers. See get_plugin_data() or WP_Theme for details.
	 *  'readme' - An array of metadata extracted from readme.txt. @see self::parseReadme()
	 * 	'pluginFile' - The name of the PHP file where the plugin headers were found relative to the root directory of the ZIP archive.
	 * 	'stylesheet' - The relative path to the style.css file that contains theme headers, if any.
	 *
	 * The 'readme' key will only be present if the input archive contains a readme.txt file
	 * formatted according to WordPress.org readme standards. Similarly, 'pluginFile' and
	 * 'stylesheet' will only be present if the archive contains a plugin or a theme, respectively.
	 *
	 * @param string $packageFilename The path to the ZIP package.
	 * @param bool $applyMarkdown Whether to transform markup used in readme.txt to HTML. Defaults to false.
	 * @return array Either an associative array or FALSE if the input file is not a valid ZIP archive or doesn't contain a WP plugin or theme.
	 */
	public static function parsePackage($packageFilename, $applyMarkdown = false){
		if ( !file_exists($packageFilename) || !is_readable($packageFilename) ){
			return false;
		}

		//Open the .zip
		$zip = new ZipArchive();
		if ( $zip->open($packageFilename) !== true ){
			return false;
		}

		//Find and parse the plugin or theme file and (optionally) readme.txt.
		$header = null;
		$readme = null;
		$pluginFile = null;
		$stylesheet = null;
		$type = null;

		for ( $fileIndex = 0; ($fileIndex < $zip->numFiles) && (empty($readme) || empty($header)); $fileIndex++ ){
			$info = $zip->statIndex($fileIndex);

			//Normalize filename: convert backslashes to slashes, remove leading slashes.
			$fileName = trim(str_replace('\\', '/', $info['name']), '/');
			$fileName = ltrim($fileName, '/');

			$fileNameParts = explode('.', $fileName);
			$extension = strtolower(end($fileNameParts));
			$depth = substr_count($fileName, '/');

			//Skip empty files, directories and everything that's more than 1 sub-directory deep.
			if ( ($depth > 1) || ($info['size'] == 0) ) {
				continue;
			}

			//readme.txt (for plugins)?
			if ( empty($readme) && (strtolower(basename($fileName)) == 'readme.txt') ){
				//Try to parse the readme.
				$readme = self::parseReadme($zip->getFromIndex($fileIndex), $applyMarkdown);
			}

			//Theme stylesheet?
			if ( empty($header) && (strtolower(basename($fileName)) == 'style.css') ) {
				$fileContents = substr($zip->getFromIndex($fileIndex), 0, 8*1024);
				$header = self::getThemeHeaders($fileContents);
				if ( !empty($header) ){
					$stylesheet = $fileName;
					$type = 'theme';
				}
			}

			//Main plugin file?
			if ( empty($header) && ($extension === 'php') ){
				$fileContents = substr($zip->getFromIndex($fileIndex), 0, 8*1024);
				$header = self::getPluginHeaders($fileContents);
				if ( !empty($header) ){
					$pluginFile = $fileName;
					$type = 'plugin';
				}
			}
		}

		if ( empty($type) ){
			return false;
		} else {
			return compact('header', 'readme', 'pluginFile', 'stylesheet', 'type');
		}
	}

	/**
	 * Parse a plugin's readme.txt to extract various plugin metadata.
	 *
	 * Returns an array with the following fields:
	 * 	'name' - Name of the plugin.
	 * 	'contributors' - An array of wordpress.org usernames.
	 * 	'donate' - The plugin's donation link.
	 * 	'tags' - An array of the plugin's tags.
	 * 	'requires' - The minimum version of WordPress that the plugin will run on.
	 * 	'tested' - The latest version of WordPress that the plugin has been tested on.
	 * 	'stable' - The SVN tag of the latest stable release, or 'trunk'.
	 * 	'short_description' - The plugin's "short description".
	 * 	'sections' - An associative array of sections present in the readme.txt.
	 *               Case and formatting of section headers will be preserved.
	 *
	 * Be warned that this function does *not* perfectly emulate the way that WordPress.org
	 * parses plugin readme's. In particular, it may mangle certain HTML markup that wp.org
	 * handles correctly.
	 *
	 * @see http://wordpress.org/extend/plugins/about/readme.txt
	 *
	 * @param string $readmeTxtContents The contents of a plugin's readme.txt file.
	 * @param bool $applyMarkdown Whether to transform Markdown used in readme.txt sections to HTML. Defaults to false.
	 * @return array|null Associative array, or NULL if the input isn't a valid readme.txt file.
	 */
	public static function parseReadme($readmeTxtContents, $applyMarkdown = false){
		$readmeTxtContents = trim($readmeTxtContents, " \t\n\r");
		$readme = array(
			'name' => '',
			'contributors' => array(),
			'donate' => '',
			'tags' => array(),
			'requires' => '',
			'tested' => '',
			'stable' => '',
			'short_description' => '',
			'sections' => array(),
		);

		//The readme.txt header has a fairly fixed structure, so we can parse it line-by-line
		$lines = explode("\n", $readmeTxtContents);
		//Plugin name is at the very top, e.g. === My Plugin ===
		if ( preg_match('@===\s*(.+?)\s*===@', array_shift($lines), $matches) ){
			$readme['name'] = $matches[1];
		} else {
			return null;
		}

		//Then there's a bunch of meta fields formatted as "Field: value"
		$headers = array();
		$headerMap = array(
			'Contributors' => 'contributors',
			'Donate link' => 'donate',
			'Tags' => 'tags',
			'Requires at least' => 'requires',
			'Tested up to' => 'tested',
			'Stable tag' => 'stable',
		);
		do { //Parse each readme.txt header
			$pieces = explode(':', array_shift($lines), 2);
			if ( array_key_exists($pieces[0], $headerMap) ){
				if ( isset($pieces[1]) ){
					$headers[ $headerMap[$pieces[0]] ] = trim($pieces[1]);
				} else {
					$headers[ $headerMap[$pieces[0]] ] = '';
				}
			}
		} while ( trim($pieces[0]) != '' ); //Until an empty line is encountered

		//"Contributors" is a comma-separated list. Convert it to an array.
		if ( !empty($headers['contributors']) ){
			$headers['contributors'] = array_map('trim', explode(',', $headers['contributors']));
		}

		//Likewise for "Tags"
		if ( !empty($headers['tags']) ){
			$headers['tags'] = array_map('trim', explode(',', $headers['tags']));
		}

		$readme = array_merge($readme, $headers);

		//After the headers comes the short description
		$readme['short_description'] = array_shift($lines);

		//Finally, a valid readme.txt also contains one or more "sections" identified by "== Section Name =="
		$sections = array();
		$contentBuffer = array();
		$currentSection = '';
		foreach($lines as $line){
			//Is this a section header?
			if ( preg_match('@^\s*==\s+(.+?)\s+==\s*$@m', $line, $matches) ){
				//Flush the content buffer for the previous section, if any
				if ( !empty($currentSection) ){
					$sectionContent = trim(implode("\n", $contentBuffer));
					$sections[$currentSection] = $sectionContent;
				}
				//Start reading a new section
				$currentSection = $matches[1];
				$contentBuffer = array();
			} else {
				//Buffer all section content
				$contentBuffer[] = $line;
			}
		}
		//Flush the buffer for the last section
		if ( !empty($currentSection) ){
			$sections[$currentSection] = trim(implode("\n", $contentBuffer));
		}

		//Apply Markdown to sections
		if ( $applyMarkdown ){
			$sections = array_map(__CLASS__ . '::applyMarkdown', $sections);
		}

		$readme['sections'] = $sections;

		return $readme;
	}

	/**
	 * Transform Markdown markup to HTML.
	 *
	 * Tries (in vain) to emulate the transformation that WordPress.org applies to readme.txt files.
	 *
	 * @param string $text
	 * @return string
	 */
	private static function applyMarkdown($text){
		//The WP standard for readme files uses some custom markup, like "= H4 headers ="
		$text = preg_replace('@^\s*=\s*(.+?)\s*=\s*$@m', "<h4>$1</h4>\n", $text);
		return Markdown::defaultTransform($text);
	}

	/**
	 * Parse the plugin contents to retrieve plugin's metadata headers.
	 *
	 * Adapted from the get_plugin_data() function used by WordPress.
	 * Returns an array that contains the following:
	 *		'Name' - Name of the plugin.
	 *		'Title' - Title of the plugin and the link to the plugin's web site.
	 *		'Description' - Description of what the plugin does and/or notes from the author.
	 *		'Author' - The author's name.
	 *		'AuthorURI' - The author's web site address.
	 *		'Version' - The plugin version number.
	 *		'PluginURI' - Plugin web site address.
	 *		'TextDomain' - Plugin's text domain for localization.
	 *		'DomainPath' - Plugin's relative directory path to .mo files.
	 *		'Network' - Boolean. Whether the plugin can only be activated network wide.
	 *
	 * If the input string doesn't appear to contain a valid plugin header, the function
	 * will return NULL.
	 *
	 * @param string $fileContents Contents of the plugin file
	 * @return array|null See above for description.
	 */
	public static function getPluginHeaders($fileContents) {
		//[Internal name => Name used in the plugin file]
		$pluginHeaderNames = array(
			'Name' => 'Plugin Name',
			'PluginURI' => 'Plugin URI',
			'Version' => 'Version',
			'Description' => 'Description',
			'Author' => 'Author',
			'AuthorURI' => 'Author URI',
			'TextDomain' => 'Text Domain',
			'DomainPath' => 'Domain Path',
			'Network' => 'Network',
			'Depends' => 'Depends',
			'Provides' => 'Provides',

			//Site Wide Only is deprecated in favor of Network.
			'_sitewide' => 'Site Wide Only',
		);

		$headers = self::getFileHeaders($fileContents, $pluginHeaderNames);

		//Site Wide Only is the old header for Network.
		if ( empty($headers['Network']) && !empty($headers['_sitewide']) ) {
			$headers['Network'] = $headers['_sitewide'];
		}
		unset($headers['_sitewide']);
		$headers['Network'] = (strtolower($headers['Network']) === 'true');

		//For backward compatibility by default Title is the same as Name.
		$headers['Title'] = $headers['Name'];
		
		//"Depends" is a comma-separated list. Convert it to an array.
		if ( !empty($headers['Depends']) ){
			$headers['Depends'] = array_map('trim', explode(',', $headers['Depends']));
		}

		//Same for "Provides"
		if ( !empty($headers['Provides']) ){
			$headers['Provides'] = array_map('trim', explode(',', $headers['Provides']));
		}

		//If it doesn't have a name, it's probably not a plugin.
		if ( empty($headers['Name']) ){
			return null;
		} else {
			return $headers;
		}
	}

	/**
	 * Parse the theme stylesheet to retrieve its metadata headers.
	 *
	 * Adapted from the get_theme_data() function and the WP_Theme class in WordPress.
	 * Returns an array that contains the following:
	 *		'Name' - Name of the theme.
	 *		'Description' - Theme description.
	 *		'Author' - The author's name
	 *		'AuthorURI' - The authors web site address.
	 *		'Version' - The theme version number.
	 *		'ThemeURI' - Theme web site address.
	 *		'Template' - The slug of the parent theme. Only applies to child themes.
	 *		'Status' - Unknown. Included for completeness.
	 *		'Tags' - An array of tags.
	 *		'TextDomain' - Theme's text domain for localization.
	 *		'DomainPath' - Theme's relative directory path to .mo files.
	 *
	 * If the input string doesn't appear to contain a valid theme header, the function
	 * will return NULL.
	 *
	 * @param string $fileContents Contents of the theme stylesheet.
	 * @return array|null See above for description.
	 */
	public static function getThemeHeaders($fileContents) {
		$themeHeaderNames = array(
			'Name'        => 'Theme Name',
			'ThemeURI'    => 'Theme URI',
			'Description' => 'Description',
			'Author'      => 'Author',
			'AuthorURI'   => 'Author URI',
			'Version'     => 'Version',
			'Template'    => 'Template',
			'Status'      => 'Status',
			'Tags'        => 'Tags',
			'TextDomain'  => 'Text Domain',
			'DomainPath'  => 'Domain Path',
			'DetailsURI'   => 'Details URI',
		);
		$headers = self::getFileHeaders($fileContents, $themeHeaderNames);

		$headers['Tags'] = array_filter(array_map('trim', explode(',', strip_tags( $headers['Tags']))));

		//If it doesn't have a name, it's probably not a valid theme.
		if ( empty($headers['Name']) ){
			return null;
		} else {
			return $headers;
		}
	}

	/**
	 * Parse the file contents to retrieve its metadata.
	 *
	 * Searches for metadata for a file, such as a plugin or theme.  Each piece of
	 * metadata must be on its own line. For a field spanning multiple lines, it
	 * must not have any newlines or only parts of it will be displayed.
	 *
	 * @param string $fileContents File contents. Can be safely truncated to 8kiB as that's all WP itself scans.
	 * @param array $headerMap The list of headers to search for in the file.
	 * @return array
	 */
	public static function getFileHeaders($fileContents, $headerMap ) {
		$headers = array();

		//Support systems that use CR as a line ending.
		$fileContents = str_replace("\r", "\n", $fileContents);

		foreach ($headerMap as $field => $prettyName) {
			$found = preg_match('/^[ \t\/*#@]*' . preg_quote($prettyName, '/') . ':(.*)$/mi', $fileContents, $matches);
			if ( ($found > 0) && !empty($matches[1]) ) {
				//Strip comment markers and closing PHP tags.
				$value = trim(preg_replace("/\s*(?:\*\/|\?>).*/", '', $matches[1]));
				$headers[$field] = $value;
			} else {
				$headers[$field] = '';
			}
		}

		return $headers;
	}
}
